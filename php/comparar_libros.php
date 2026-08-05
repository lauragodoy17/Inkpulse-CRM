<?php
/**
 * /php/comparar_libros.php
 * Trae los libros/productos de World Office (listarInventarios) con id mayor
 * al piso PISO_ID_WO (ver más abajo), del más reciente al más antiguo, y por
 * cada uno que NO exista todavía en la tabla local `libros` (comparando por
 * ISBN) lo devuelve para que libros.php lo muestre en una lista de "libros
 * nuevos". Solo lectura: no crea ni modifica nada, ni en World Office ni en
 * la base de datos local.
 *
 * A diferencia de clientes/terceros, el piso acá NO se calcula solo (no hay
 * un "calcular_piso_efectivo" para libros): en WO hay ~5,000 productos de
 * inventario pero solo ~2,474 libros locales tienen id_wo, y esa diferencia
 * no es necesariamente "pendiente por crear" (WO puede tener productos que
 * nunca deban existir como libro en el catálogo). El piso de 5501 es fijo,
 * a pedido, porque todo lo de id_wo <= 5501 ya se revisó a mano.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/api_wo_inventario.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

const PISO_ID_WO_LIBROS = 5501;

$pagina    = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina = isset($_GET['porPagina']) ? max(1, min(100, (int)$_GET['porPagina'])) : 50;

$inicio = microtime(true);

$filtroMayorQue = crear_filtro_api('id', PISO_ID_WO_LIBROS, 2, 3); // id > 5501, del lado de World Office
$resultado = listar_inventarios([$filtroMayorQue], $pagina, $porPagina);
$status = $resultado['status'] ?? 'error';
if (!in_array($status, ['OK', 'ACCEPTED'], true)) {
    echo json_encode(['success' => false, 'message' => 'La API de World Office respondió con error', 'detalle' => $resultado]);
    exit;
}

$pag = $resultado['data'] ?? [];
$filas = $pag['content'] ?? [];

// Un solo query local para toda la página (evita N consultas): compara por
// ISBN, con trim en ambos lados por si hay espacios sueltos.
$isbns = array_values(array_filter(array_map(function ($f) {
    return trim((string)($f['codigo'] ?? ''));
}, $filas), function ($v) { return $v !== ''; }));

$existentesLocal = [];
if ($isbns) {
    $marcadores = implode(',', array_fill(0, count($isbns), '?'));
    $stmt = $bdd->prepare("SELECT TRIM(isbn) as isbn FROM libros WHERE TRIM(isbn) IN ($marcadores)");
    $stmt->execute($isbns);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $isbn) {
        $existentesLocal[$isbn] = true;
    }
}

$nuevos = [];
foreach ($filas as $f) {
    $isbn = trim((string)($f['codigo'] ?? ''));
    if ($isbn === '' || isset($existentesLocal[$isbn])) {
        continue; // ya existe en libros -> no es nuevo
    }

    $nuevos[] = [
        'id' => $f['id'] ?? null,
        'isbn' => $isbn,
        'descripcion' => $f['descripcion'] ?? '',
        'editorial' => $f['grupo'][0]['nombreGrupo'] ?? '',
        'activo' => !empty($f['senActivo']),
    ];
}

echo json_encode([
    'success' => true,
    'piso' => PISO_ID_WO_LIBROS,
    'pagina' => $pagina,
    'totalPaginas' => $pag['totalPages'] ?? null,
    'totalElementos' => $pag['totalElements'] ?? null,
    'revisadosEnPagina' => count($filas),
    'nuevos' => $nuevos,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
