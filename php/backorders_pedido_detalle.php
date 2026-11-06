<?php
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
header('Content-Type: application/json');

$idPedido = intval($_GET['id_pedido'] ?? 0);
$detalle = obtener_backorder_pedido($bdd, $idPedido);

if ($detalle === null) {
    echo json_encode(['success' => false, 'message' => 'No se encontró ese pedido (o está anulado).']);
    exit;
}
// Este módulo solo maneja pedidos de los periodos de BACKORDERS_PERIODOS_PEDIDO.
if (!$detalle['periodo_permitido']) {
    echo json_encode(['success' => false, 'message' => 'Este pedido no pertenece a los periodos ' . implode(', ', BACKORDERS_PERIODOS_PEDIDO) . '.']);
    exit;
}

echo json_encode(['success' => true, 'pedido' => $detalle]);
