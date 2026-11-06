<?php
/**
 * /php/backorders_wo_asignar_pedido.php
 * Asigna manualmente un PEDIDO específico (no un colegio) a un documento REM/FV que la
 * verificación automática por OP+PED (ver includes/matching_pedidos_wo.php) no pudo resolver.
 * A diferencia de la primera versión de este módulo (que solo asignaba un colegio, informativo),
 * esto SÍ alimenta el backorder: escribe `id_pedido_verificado` en `backorder_wo_catalogo` igual
 * que una verificación automática, así que includes/backorders_datos.php lo descuenta sin ningún
 * cambio adicional. Reemplazado a pedido explícito del usuario 2026-09-11 ("cámbialo a pedido
 * específico") tras confirmar que la asignación por colegio no servía para nada más que consulta.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/prueba_get2.php");
require_once("../includes/backorders_wo_anulados.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');
backorders_asegurar_columna_anulado($bdd);

$id_wo = isset($_POST['id_wo']) ? (int)$_POST['id_wo'] : 0;
$tipo_documento = trim($_POST['tipo_documento'] ?? '');
$id_pedido = isset($_POST['id_pedido']) ? (int)$_POST['id_pedido'] : 0;

if ($id_wo <= 0 || $tipo_documento === '' || $id_pedido <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$stmt = $bdd->prepare("SELECT id FROM pedidos WHERE id = ? AND estado != 3");
$stmt->execute([$id_pedido]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'El pedido no existe o está anulado']);
    exit;
}

$stmt = $bdd->prepare("SELECT numero, prefijo, fecha, anulado FROM backorder_wo_catalogo WHERE id_wo = ? AND tipo_documento = ?");
$stmt->execute([$id_wo, $tipo_documento]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'El documento no existe en el catálogo']);
    exit;
}
if ((int)$doc['anulado'] === 1) {
    echo json_encode(['success' => false, 'message' => 'Este documento está anulado en World Office: no despachó nada, no se puede asignar a un pedido.']);
    exit;
}

try {
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

// El sync (php/sync_backorders_wo_catalogo.php) se salta a propósito la llamada a renglones para
// los documentos que NO se verifican automáticamente (para no gastar llamadas a la API en
// documentos que no se iban a usar) — como este SÍ se va a usar ahora, hay que traerlos acá si
// todavía no están cacheados.
$stmt = $bdd->prepare("SELECT 1 FROM backorder_wo_docs WHERE tipo_documento = ? AND numero = ?");
$stmt->execute([$tipo_documento, $doc['numero']]);
if (!$stmt->fetchColumn()) {
    $renglones = obtener_renglones_documento($id_wo, 0, 100);
    $filas = $renglones['data']['content'] ?? [];

    $bdd->prepare("INSERT INTO backorder_wo_docs (tipo_documento, numero, id_wo, prefijo, fecha, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE id_wo = VALUES(id_wo), prefijo = VALUES(prefijo), fecha = VALUES(fecha), updated_at = NOW()")
        ->execute([$tipo_documento, $doc['numero'], $id_wo, $doc['prefijo'], $doc['fecha']]);

    $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE id_wo = ? AND tipo_documento = ?")->execute([$id_wo, $tipo_documento]);
    $stmtRenglon = $bdd->prepare("INSERT INTO backorder_wo_renglones (id_wo, tipo_documento, id_libro, isbn, descripcion, cantidad, created_at)
                                   VALUES (?, ?, ?, ?, ?, ?, NOW())");
    foreach ($filas as $r) {
        // OJO: $inv['id'] es el id de INVENTARIO de World Office, no `libros.id` del CRM — ver
        // includes/backorders_datos.php (cruza esta columna contra `libros.id_wo`).
        $inv = $r['inventario'] ?? [];
        $stmtRenglon->execute([$id_wo, $tipo_documento, $inv['id'] ?? null, $inv['codigo'] ?? null, $inv['descripcion'] ?? null, (float)($r['cantidad'] ?? 0)]);
    }
}

$stmt = $bdd->prepare("UPDATE backorder_wo_catalogo SET id_pedido_verificado = ?, asignado_manual = 1, updated_at = NOW() WHERE id_wo = ? AND tipo_documento = ?");
$stmt->execute([$id_pedido, $id_wo, $tipo_documento]);
$ok = $stmt->rowCount() > 0;

// Con este documento el pedido (o su pedido original, si es uno nuevo) puede pasar a tener
// documento confirmado: se le asigna su consecutivo BK si le corresponde.
if ($ok) {
    require_once("../includes/backorders_datos.php");
    $familias = backorders_familias($bdd, [$id_pedido]);
    $idOriginal = $familias['hijos'][$id_pedido] ?? $id_pedido;
    backorders_asignar_consecutivos($bdd, calcular_lineas_backorder($bdd, null, null, $idOriginal)['lineas']);
}

echo json_encode(['success' => $ok]);
