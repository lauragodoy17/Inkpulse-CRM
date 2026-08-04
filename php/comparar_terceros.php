<?php
/**
 * /php/comparar_terceros.php
 * Trae los terceros de World Office con id mayor al "piso efectivo" (ver
 * includes/wo_clientes_sync.php) y, de esos, descarta los que ya existen
 * localmente por identificación/documento. Devuelve el resto para que
 * clientes_op.php los liste con checkbox y permita cruzarlos selectivamente.
 * Solo lectura: no crea ni modifica nada.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/wo_clientes_sync.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$pagina    = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina = isset($_GET['porPagina']) ? max(1, min(200, (int)$_GET['porPagina'])) : 100;
// El piso efectivo se calcula una sola vez, al arrancar una búsqueda (pagina=0),
// y el frontend lo reenvía en las páginas siguientes de la misma búsqueda para
// no recalcularlo en cada request.
$piso = ($pagina === 0 || !isset($_GET['piso']))
    ? calcular_piso_efectivo($bdd)
    : max(ID_MINIMO_WO, (int)$_GET['piso']);

$inicio = microtime(true);

$filtroMayorQue = crear_filtro_api('id', $piso, 2, 3); // id > piso, del lado de World Office
$resultado = listar_terceros([$filtroMayorQue], $pagina, $porPagina);
$status = $resultado['status'] ?? 'error';
if (!in_array($status, ['OK', 'ACCEPTED'], true)) {
    echo json_encode(['success' => false, 'message' => 'La API de World Office respondió con error', 'detalle' => $resultado]);
    exit;
}

$pag = $resultado['data'] ?? [];
$filas = $pag['content'] ?? [];

// Un solo query local para toda la página (evita N consultas): compara por
// identificación/documento, con trim en ambos lados por si hay espacios sueltos.
$identificaciones = array_values(array_filter(array_map(function ($f) {
    return trim((string)($f['identificacion'] ?? ''));
}, $filas), function ($v) { return $v !== ''; }));

$existentesLocal = [];
if ($identificaciones) {
    $marcadores = implode(',', array_fill(0, count($identificaciones), '?'));
    $stmt = $bdd->prepare("SELECT TRIM(documento) as documento FROM clientes WHERE TRIM(documento) IN ($marcadores)");
    $stmt->execute($identificaciones);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $doc) {
        $existentesLocal[$doc] = true;
    }
}

// Por cada tercero que NO está en la tabla local, se pide el detalle completo
// (GET /terceros/consultar/{id}) para mostrarlo con ciudad/departamento/país y
// nombres separados en la tabla del panel.
$nuevos = [];
foreach ($filas as $f) {
    $identificacion = trim((string)($f['identificacion'] ?? ''));
    if ($identificacion !== '' && isset($existentesLocal[$identificacion])) {
        continue; // ya existe en clientes -> no es nuevo
    }

    $detalleResp = consultar_tercero($f['id'] ?? 0);
    $d = (($detalleResp['status'] ?? '') === 'error') ? null : ($detalleResp['data'] ?? $detalleResp);

    $nuevos[] = [
        'id' => $f['id'] ?? null,
        'identificacion' => $identificacion,
        'nombreCompleto' => $d['nombreCompleto'] ?? ($f['nombreCompleto'] ?? ''),
        'ciudad' => $d['ciudad']['nombre'] ?? ($f['ubicacionCiudad'] ?? ''),
        'departamento' => $d['ciudad']['ubicacionDepartamento']['nombre'] ?? '',
        'pais' => $d['ciudad']['ubicacionDepartamento']['ubicacionPais']['nombre'] ?? '',
        'terceroTipos' => $d ? implode(', ', array_column($d['terceroTipos'] ?? [], 'nombre')) : ($f['terceroTipos'] ?? ''),
        'codigo' => $d['codigo'] ?? ($f['codigo'] ?? ''),
    ];
}

echo json_encode([
    'success' => true,
    'piso' => $piso,
    'pagina' => $pagina,
    'totalPaginas' => $pag['totalPages'] ?? null,
    'totalElementos' => $pag['totalElements'] ?? null,
    'revisadosEnPagina' => count($filas),
    'nuevos' => $nuevos,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
