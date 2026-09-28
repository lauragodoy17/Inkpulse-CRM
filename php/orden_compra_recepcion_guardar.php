<?php
/**
 * /php/orden_compra_recepcion_guardar.php (POST, cuerpo JSON: id, token, version, cantidades, observaciones)
 * Registra una recepción de una orden de compra y actualiza acumulados, backorders y estado de la
 * orden en una sola transacción (ver oc_guardar_recepcion()). No toca World Office.
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/ordenes_compra_datos.php");
set_time_limit(90);

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada) || intval($entrada['id'] ?? 0) <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);
    exit;
}
echo json_encode(oc_guardar_recepcion($bdd, intval($entrada['id']), $entrada, intval($_SESSION['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
