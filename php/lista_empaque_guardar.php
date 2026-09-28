<?php
/**
 * /php/lista_empaque_guardar.php (POST, cuerpo JSON)
 * Guarda una lista de empaque con su consecutivo. Toda la validación (documentos de WO del cliente,
 * no anulados ni usados en otra lista, cajas que sumen exactamente lo despachado) está en le_guardar().
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");
set_time_limit(120);

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);
    exit;
}
echo json_encode(le_guardar($bdd, $entrada, intval($_SESSION['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
