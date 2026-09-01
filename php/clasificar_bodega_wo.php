<?php
/**
 * /php/clasificar_bodega_wo.php
 * Dado un listado de ids de inventario de World Office, devuelve para cada
 * uno la clasificación de bodega (mismo dominio que libros.tipo, ver
 * php/clasificar_libros_bodega.php): 0 Ninguna, 1 General, 4 Muestras
 * General, 3 Ambas. Se usa desde libros.php para precargar la "Bodega" de
 * un libro nuevo (traído desde "Buscar libros nuevos en World Office"),
 * igual que ya se hace con la editorial.
 */
require_once("aut.php");

header('Content-Type: application/json');

if (($_SESSION["tipo"] ?? null) != 1) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/api_wo_inventario.php");

$body = json_decode(file_get_contents('php://input'), true);
$ids = isset($body['ids']) && is_array($body['ids']) ? array_map('strval', $body['ids']) : [];
$ids = array_values(array_filter($ids, fn($v) => $v !== '' && $v !== '0'));

if (!$ids) {
    echo json_encode(['success' => true, 'tipos' => []]);
    exit;
}

echo json_encode(['success' => true, 'tipos' => clasificar_bodega_bulk($ids)]);
