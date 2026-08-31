<?php
/**
 * /php/sync_colocacion_wo_ventas.php
 * Sincroniza por lotes las devoluciones de venta (DREM) y las facturas de
 * punto de venta (POS) de World Office hacia la misma caché local
 * `wo_documentos_colocacion` que usa php/sync_colocacion_wo.php para REM/FV,
 * cruzando cada documento contra un colegio de Calendario B mediante el
 * campo `concepto` (ver includes/matching_colegios.php).
 *
 * A diferencia de sync_colocacion_wo.php, acá el valor neto (valorTotal) ya
 * viene en la propia lista paginada de /ventas/filtrarPaginado o
 * /puntodeventa/filtrarPaginado (ver includes/api_wo_ventas.php) — no hace
 * falta pedir detalle+renglones por documento, así que este sync es de una
 * sola llamada por página en vez de dos por documento.
 *
 * DREM se resta del total colocado (reemplaza la fuente anterior, que era
 * `devoluciones_v`/`libros_devol_v` del propio CRM — decisión del usuario
 * 2026-08-26: World Office es ahora la única fuente de devoluciones para
 * este reporte). POS se suma, igual que REM/FV. La mayoría de los
 * documentos POS son cierres de caja genéricos sin colegio asociado
 * ("CORTE EDUCADORES", tercero "Consumidor Final") — quedan sin cruzar y
 * simplemente no aportan a ningún colegio, igual que cualquier REM/FV sin
 * cruzar; no se descartan del sync, sí de la agregación por colegio.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/api_wo_ventas.php");
require_once("../includes/matching_colegios.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$tipoDocumento = ($_GET['tipoDocumento'] ?? '') === 'POS' ? 'POS' : 'DREM';
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 20;

// Migración defensiva: ver comentario equivalente en sync_colocacion_wo.php.
try {
    $bdd->exec("ALTER TABLE wo_documentos_colocacion DROP INDEX uniq_id_wo");
    $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento)");
} catch (Exception $e) {}

$id_periodo = (int)$bdd->query("SELECT id FROM periodos WHERE id_calendario = 2 ORDER BY id DESC LIMIT 1")->fetchColumn();

$inicio = microtime(true);
$colegiosPrecargados = cargar_colegios_calendario_b($bdd);

$respuestaLista = $tipoDocumento === 'POS'
    ? listar_facturas_pos_wo($pagina, $porPagina)
    : listar_devoluciones_venta_wo($pagina, $porPagina);

if (($respuestaLista['status'] ?? 'error') !== 'OK') {
    echo json_encode(['success' => false, 'message' => 'Error consultando World Office: ' . ($respuestaLista['mensaje_interno'] ?? 'desconocido')]);
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
$anuladas = 0;

foreach ($documentos as $d) {
    $idWo = $d['id'] ?? null;
    if (!$idWo) continue;

    // Anuladas no se guardan: no deben restar (DREM) ni sumar (POS) al total colocado, y si
    // una devolución/factura se anula después de sincronizada, el próximo sync la debe quitar.
    if (!empty($d['senAnulado'])) {
        $bdd->prepare("DELETE FROM wo_documentos_colocacion WHERE id_wo = ? AND tipo_documento = ? AND asignado_manual = 0")
            ->execute([$idWo, $tipoDocumento]);
        $anuladas++;
        continue;
    }

    $concepto = $d['concepto'] ?? '';
    $colegioExtraido = extraer_colegio_de_concepto($concepto);
    $idColegio = emparejar_colegio($colegioExtraido, $colegiosPrecargados);
    if ($idColegio) $emparejados++; else $sinCruzar++;

    // Este microservicio trae la fecha como "dd/mm/yyyy", a diferencia de las llamadas por
    // api.worldoffice.cloud usadas en el resto de este proyecto (yyyy-mm-dd).
    $fechaWo = DateTime::createFromFormat('d/m/Y', (string)($d['fecha'] ?? ''));
    $fechaSql = $fechaWo ? $fechaWo->format('Y-m-d') : date('Y-m-d');

    $valorNeto = (float)($d['valorTotal'] ?? 0);

    $stmtUpsert->execute([
        $idWo,
        $tipoDocumento,
        $d['prefijo'] ?? '',
        (string)($d['numero'] ?? ''),
        $fechaSql,
        $concepto,
        $d['idTerceroExterno'] ?? null,
        $d['terceroExterno'] ?? null,
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
    'resumen' => ['emparejados' => $emparejados, 'sinCruzar' => $sinCruzar, 'anuladas' => $anuladas],
    'errores' => $errores,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
