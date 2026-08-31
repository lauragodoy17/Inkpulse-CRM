<?php
/**
 * /php/sync_colocacion_wo.php
 * Sincroniza por lotes las remisiones (REM, prefijo CEUR) y facturas de
 * venta (FV) de World Office hacia la tabla local `wo_documentos_colocacion`,
 * cruzando cada documento contra un colegio de Calendario B mediante el
 * campo `concepto` (ver includes/matching_colegios.php). Se llama en lotes
 * (pagina/porPagina) desde reporte_colocacion.php porque cada documento
 * requiere 2 llamadas adicionales a World Office (detalle + renglones).
 * Los "Abonos" (Recibo de Caja) NO se sincronizan acá: World Office no
 * expone ningún valor monetario para ese tipo de documento (confirmado en
 * vivo: 0 renglones siempre, sin campo de total en el detalle).
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/prueba_post2.php");
require_once("../includes/prueba_get2.php");
require_once("../includes/matching_colegios.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$tipoDocumento = ($_GET['tipoDocumento'] ?? '') === 'FV' ? 'FV' : 'REM';
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 20;

try {
    $bdd->exec("CREATE TABLE IF NOT EXISTS wo_documentos_colocacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        fecha DATE NOT NULL,
        concepto TEXT NULL,
        tercero_externo_id INT NULL,
        tercero_externo_nombre VARCHAR(255) NULL,
        id_colegio INT NULL,
        asignado_manual TINYINT(1) NOT NULL DEFAULT 0,
        colegio_extraido VARCHAR(255) NULL,
        valor_neto DECIMAL(14,2) NOT NULL DEFAULT 0,
        id_periodo INT NOT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento),
        KEY idx_colegio_periodo (id_colegio, id_periodo)
    )");
} catch (Exception $e) {}
// La tabla ya existía antes de agregar asignado_manual (ver php/colocacion_asignar_colegio.php)
// en instalaciones donde se corrió el sync antes de esta columna existir.
try {
    $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD COLUMN asignado_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER id_colegio");
} catch (Exception $e) {}
// La tabla ya existía antes de agregar DREM (devoluciones) y POS (ver
// php/sync_colocacion_wo_ventas.php) con la clave única solo en id_wo — los
// ids de World Office son por módulo (ventas/puntodeventa/salida de almacén),
// NO globales, y ya se confirmó solapamiento real de rangos entre FV y
// DREM/POS. Migra la clave a compuesta (id_wo, tipo_documento) en
// instalaciones que corrieron el sync antes de este cambio.
try {
    $bdd->exec("ALTER TABLE wo_documentos_colocacion DROP INDEX uniq_id_wo");
    $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento)");
} catch (Exception $e) {}

$id_periodo = (int)$bdd->query("SELECT id FROM periodos WHERE id_calendario = 2 ORDER BY id DESC LIMIT 1")->fetchColumn();

$inicio = microtime(true);

$filtros = [
    crear_filtro_api('documentoTipo.codigoDocumento', $tipoDocumento, 0, 0),
    crear_filtro_api('moneda.id', '31', 4, 0),
];
if ($tipoDocumento === 'REM') {
    $filtros[] = crear_filtro_api('prefijo.nombre', 'CEUR', 0, 0);
}

$colegiosPrecargados = cargar_colegios_calendario_b($bdd);

$respuestaLista = listar_documentos_salida_almacen($filtros, $pagina, $porPagina);
if (($respuestaLista['status'] ?? 'error') !== 'OK') {
    echo json_encode(['success' => false, 'message' => 'Error consultando World Office: ' . ($respuestaLista['userMessage'] ?? 'desconocido')]);
    exit;
}

$pag = $respuestaLista['data'] ?? [];
$documentos = $pag['content'] ?? [];
$totalPaginas = (int)($pag['totalPages'] ?? 0);
$totalDocumentos = (int)($pag['totalElements'] ?? 0);

$stmtUpsert = $bdd->prepare("
    INSERT INTO wo_documentos_colocacion
        (id_wo, tipo_documento, prefijo, numero, fecha, concepto, tercero_externo_id, tercero_externo_nombre, id_colegio, colegio_extraido, valor_neto, id_periodo, created_at, updated_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        prefijo = VALUES(prefijo), numero = VALUES(numero), fecha = VALUES(fecha), concepto = VALUES(concepto),
        tercero_externo_id = VALUES(tercero_externo_id), tercero_externo_nombre = VALUES(tercero_externo_nombre),
        id_colegio = IF(asignado_manual = 1, id_colegio, VALUES(id_colegio)),
        colegio_extraido = VALUES(colegio_extraido), valor_neto = VALUES(valor_neto),
        id_periodo = VALUES(id_periodo), updated_at = NOW()
");

$procesados = [];
$errores = [];
$emparejados = 0;
$sinCruzar = 0;

foreach ($documentos as $doc) {
    $idWo = $doc['id'] ?? null;
    if (!$idWo) continue;

    $detalle = obtener_documento_salida_por_id($idWo);
    if (($detalle['status'] ?? 'error') !== 'OK' && ($detalle['status'] ?? '') !== 'ACCEPTED') {
        $errores[] = ['id_wo' => $idWo, 'motivo' => 'detalle'];
        continue;
    }
    $d = $detalle['data'] ?? [];

    $concepto = $d['concepto'] ?? '';
    $colegioExtraido = extraer_colegio_de_concepto($concepto);
    $idColegio = emparejar_colegio($colegioExtraido, $colegiosPrecargados);
    if ($idColegio) $emparejados++; else $sinCruzar++;

    $renglones = obtener_renglones_documento($idWo, 0, 100);
    $filasRenglones = ($renglones['data']['content'] ?? []);
    $valorNeto = 0.0;
    foreach ($filasRenglones as $r) {
        $cantidad = (float)($r['cantidad'] ?? 0);
        $valorUnitario = (float)($r['valorUnitario'] ?? 0);
        $pctDescuento = (float)($r['porcentajeDescuento'] ?? 0);
        $bruto = $cantidad * $valorUnitario;
        $valorNeto += $bruto - ($bruto * $pctDescuento);
    }

    $stmtUpsert->execute([
        $idWo,
        $tipoDocumento,
        $d['prefijo']['nombre'] ?? '',
        (string)($d['numero'] ?? ''),
        $d['fecha'] ?? date('Y-m-d'),
        $concepto,
        $d['terceroExterno']['id'] ?? null,
        $d['terceroExterno']['nombreCompleto'] ?? null,
        $idColegio,
        $colegioExtraido,
        $valorNeto,
        $id_periodo,
    ]);

    $procesados[] = [
        'id_wo' => (int)$idWo,
        'numero' => $d['numero'] ?? null,
        'colegio_extraido' => $colegioExtraido,
        'id_colegio' => $idColegio,
        'valor_neto' => $valorNeto,
    ];
}

echo json_encode([
    'success' => true,
    'tipoDocumento' => $tipoDocumento,
    'pagina' => $pagina,
    'totalPaginas' => $totalPaginas,
    'totalDocumentos' => $totalDocumentos,
    'revisadosEnPagina' => count($documentos),
    'procesados' => $procesados,
    'resumen' => ['emparejados' => $emparejados, 'sinCruzar' => $sinCruzar],
    'errores' => $errores,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
