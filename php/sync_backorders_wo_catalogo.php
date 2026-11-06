<?php
/**
 * /php/sync_backorders_wo_catalogo.php
 * A diferencia de php/sync_backorders_wo.php (que solo resuelve los documentos cuyo número YA está
 * escrito como "Núm. de documento" en una OP Atendida), este trae TODO el catálogo de REM/FV de
 * World Office — necesario porque el problema que resuelve es justo el opuesto: pedidos cuya OP
 * nunca quedó con documento asociado (reportado por el usuario 2026-09-11). Por cada documento se
 * verifica a qué pedido pertenece cruzando dos números que el concepto trae de forma independiente
 * ("OP <año>-<id>" y "PED <numero>") contra el propio CRM (ver includes/matching_pedidos_wo.php) —
 * si se verifica, el documento pasa a contar como despachado para ESE pedido igual que si su OP
 * hubiera tenido el n_doc bien puesto. Si no se puede verificar, queda en `backorder_wo_catalogo`
 * para asignación manual a un pedido específico (panel "Documentos sin verificar" en
 * reporte_backorders.php, ver php/backorders_wo_asignar_pedido.php) — esa asignación manual también
 * escribe `id_pedido_verificado`, así que un re-sync no debe pisarla con el resultado (probablemente
 * NULL de nuevo) de la verificación automática.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

set_time_limit(120);
session_write_close();

require_once("../includes/prueba_post2.php");
require_once("../includes/prueba_get2.php");
require_once("../includes/matching_pedidos_wo.php");
require_once("../includes/matching_colegios.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/backorders_wo_anulados.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

try {
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_catalogo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        fecha DATE NULL,
        concepto TEXT NULL,
        colegio_extraido VARCHAR(255) NULL,
        id_pedido_verificado INT NULL,
        asignado_manual TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento),
        KEY idx_pedido_verificado (id_pedido_verificado)
    )");
    // La primera versión de este módulo asignaba manualmente un COLEGIO (informativo, no alimentaba
    // el backorder) en vez de un pedido específico — reemplazado a pedido del usuario 2026-09-11
    // porque no había forma de saber a cuál pedido de ese colegio aplicarle el despacho. Se quita la
    // columna en instalaciones que ya la tenían de esa primera versión.
    try { $bdd->exec("ALTER TABLE backorder_wo_catalogo DROP COLUMN id_colegio_manual"); } catch (Exception $e) {}
    // Mismas tablas que ya usa php/sync_backorders_wo.php — un documento verificado acá se guarda
    // ahí para que obtener_datos_backorders() lo lea sin ningún cambio en esa consulta.
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo_documento VARCHAR(10) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        id_wo INT NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        fecha DATE NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_tipo_numero (tipo_documento, numero)
    )");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_renglones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        id_libro INT NULL,
        isbn VARCHAR(100) NULL,
        descripcion VARCHAR(255) NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        KEY idx_doc (id_wo, tipo_documento),
        KEY idx_libro (id_libro)
    )");
} catch (Exception $e) {}
backorders_asegurar_columna_anulado($bdd);

$tipoDocumento = ($_GET['tipoDocumento'] ?? '') === 'FV' ? 'FV' : 'REM';
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 20;

$filtros = [
    crear_filtro_api('documentoTipo.codigoDocumento', $tipoDocumento, 0, 0),
    crear_filtro_api('moneda.id', '31', 4, 0),
];
if ($tipoDocumento === 'REM') {
    $filtros[] = crear_filtro_api('prefijo.nombre', 'CEUR', 0, 0);
}

$pedidosPorOp = cargar_pedidos_por_op($bdd);

$inicio = microtime(true);
$respuestaLista = listar_documentos_salida_almacen($filtros, $pagina, $porPagina);
if (($respuestaLista['status'] ?? 'error') !== 'OK') {
    echo json_encode(['success' => false, 'message' => 'Error consultando World Office: ' . ($respuestaLista['userMessage'] ?? 'desconocido')]);
    exit;
}
$pag = $respuestaLista['data'] ?? [];
$documentos = $pag['content'] ?? [];
$totalPaginas = (int)($pag['totalPages'] ?? 0);
$totalDocumentos = (int)($pag['totalElements'] ?? 0);

$stmtCatalogo = $bdd->prepare("
    INSERT INTO backorder_wo_catalogo
        (id_wo, tipo_documento, numero, prefijo, fecha, concepto, colegio_extraido, id_pedido_verificado, created_at, updated_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        numero = VALUES(numero), prefijo = VALUES(prefijo), fecha = VALUES(fecha), concepto = VALUES(concepto),
        colegio_extraido = VALUES(colegio_extraido),
        id_pedido_verificado = IF(asignado_manual = 1, id_pedido_verificado, VALUES(id_pedido_verificado)),
        updated_at = NOW()
");
$stmtDoc = $bdd->prepare("
    INSERT INTO backorder_wo_docs (tipo_documento, numero, id_wo, prefijo, fecha, anulado, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
    ON DUPLICATE KEY UPDATE id_wo = VALUES(id_wo), prefijo = VALUES(prefijo), fecha = VALUES(fecha), anulado = 0, eliminado = 0, updated_at = NOW()
");
// Documento anulado en World Office: entra al catálogo marcado (no sale en "sin verificar") y,
// si ya estaba guardado como despacho, deja de contar (ver includes/backorders_wo_anulados.php).
$stmtCatalogoAnulado = $bdd->prepare("
    INSERT INTO backorder_wo_catalogo (id_wo, tipo_documento, numero, prefijo, fecha, concepto, anulado, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE anulado = 1, updated_at = NOW()
");
$stmtYaGuardado = $bdd->prepare("SELECT 1 FROM backorder_wo_docs WHERE tipo_documento = ? AND id_wo = ? AND anulado = 0");
$stmtDelRenglones = $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE id_wo = ? AND tipo_documento = ?");
$stmtRenglon = $bdd->prepare("
    INSERT INTO backorder_wo_renglones (id_wo, tipo_documento, id_libro, isbn, descripcion, cantidad, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");

$procesados = [];
$errores = [];
$verificados = 0;
$sinVerificar = 0;
$anulados = 0;

foreach ($documentos as $doc) {
    $idWo = $doc['id'] ?? null;
    if (!$idWo) continue;

    if (!empty($doc['senAnulado'])) {
        // Los datos de la fila del listado alcanzan: no hace falta pedir detalle ni renglones.
        $numeroAn = trim((string)($doc['numero'] ?? ''));
        $prefijoAn = $doc['prefijo']['nombre'] ?? ($doc['prefijo'] ?? '');
        $prefijoAn = is_array($prefijoAn) ? '' : $prefijoAn;
        $fechaAn = $doc['fecha'] ?? null;
        $stmtCatalogoAnulado->execute([$idWo, $tipoDocumento, $numeroAn, $prefijoAn, $fechaAn, $doc['concepto'] ?? '']);
        $stmtYaGuardado->execute([$tipoDocumento, $idWo]);
        if ($stmtYaGuardado->fetchColumn()) {
            backorders_marcar_doc_anulado($bdd, $tipoDocumento, $numeroAn, $idWo, $prefijoAn, $fechaAn);
        }
        $anulados++;
        $procesados[] = ['id_wo' => (int)$idWo, 'numero' => $numeroAn, 'anulado' => true];
        continue;
    }

    $detalle = obtener_documento_salida_por_id($idWo);
    if (($detalle['status'] ?? 'error') !== 'OK' && ($detalle['status'] ?? '') !== 'ACCEPTED') {
        $errores[] = ['id_wo' => $idWo, 'motivo' => 'detalle'];
        continue;
    }
    $d = $detalle['data'] ?? [];
    $concepto = $d['concepto'] ?? '';
    $fecha = $d['fecha'] ?? date('Y-m-d');
    $numero = (string)($d['numero'] ?? '');
    $prefijo = $d['prefijo']['nombre'] ?? '';

    $colegioExtraido = extraer_colegio_de_concepto($concepto);
    // Documentos de antes de la migración a World Office no tienen traza confiable (ver
    // BACKORDERS_FECHA_MIGRACION_WO en includes/backorders_datos.php) — se guardan en el catálogo
    // igual (para que no aparezcan una y otra vez como "sin verificar" en cada sync) pero nunca se
    // verifican contra un pedido, para no descontarle despacho a un pedido con un documento de esa
    // época poco confiable.
    $idPedidoVerificado = ($fecha >= BACKORDERS_FECHA_MIGRACION_WO)
        ? verificar_pedido_por_op_concepto($concepto, $pedidosPorOp)
        : null;

    if ($idPedidoVerificado) {
        $verificados++;
        $renglones = obtener_renglones_documento($idWo, 0, 100);
        $filas = $renglones['data']['content'] ?? [];

        $stmtDoc->execute([$tipoDocumento, $numero, $idWo, is_array($prefijo) ? '' : $prefijo, $fecha]);
        $stmtDelRenglones->execute([$idWo, $tipoDocumento]);
        foreach ($filas as $r) {
            $inv = $r['inventario'] ?? [];
            // OJO: $inv['id'] es el id de INVENTARIO de World Office, no `libros.id` del CRM — ver
            // el mismo comentario en php/sync_backorders_wo.php. obtener_datos_backorders() cruza
            // esta columna contra `libros.id_wo`, no contra `libros.id`.
            $stmtRenglon->execute([
                $idWo, $tipoDocumento, $inv['id'] ?? null, $inv['codigo'] ?? null, $inv['descripcion'] ?? null,
                (float)($r['cantidad'] ?? 0),
            ]);
        }
    } else {
        $sinVerificar++;
    }

    $stmtCatalogo->execute([
        $idWo, $tipoDocumento, $numero, is_array($prefijo) ? '' : $prefijo, $fecha, $concepto,
        $colegioExtraido, $idPedidoVerificado,
    ]);

    $procesados[] = ['id_wo' => (int)$idWo, 'numero' => $numero, 'id_pedido_verificado' => $idPedidoVerificado];
}

echo json_encode([
    'success' => true,
    'tipoDocumento' => $tipoDocumento,
    'pagina' => $pagina,
    'totalPaginas' => $totalPaginas,
    'totalDocumentos' => $totalDocumentos,
    'revisadosEnPagina' => count($documentos),
    'procesados' => $procesados,
    'resumen' => ['verificados' => $verificados, 'sinVerificar' => $sinVerificar, 'anulados' => $anulados],
    'errores' => $errores,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
