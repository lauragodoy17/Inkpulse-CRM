<?php
/**
 * /php/backorders_colegio_detalle.php?id_colegio=&id_periodo=  (o ?id_pedido=)
 * Detalle de backorder de un colegio en un período para reporte_backorders_pedido.php — ver
 * obtener_backorder_colegio(). Con id_pedido se abre el colegio + período de ese pedido (o de su
 * pedido original, si es un pedido nuevo generado para atender un backorder).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
session_write_close();

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
header('Content-Type: application/json');

$idColegio = intval($_GET['id_colegio'] ?? 0);
$idPeriodo = intval($_GET['id_periodo'] ?? 0);
$idPedido = intval($_GET['id_pedido'] ?? 0);
if ($idPedido > 0) {
    backorders_asegurar_tablas($bdd);
    $stmt = $bdd->prepare("SELECT id_pedido_origen FROM backorder_pedidos_generados WHERE id_pedido_nuevo = ? LIMIT 1");
    $stmt->execute([$idPedido]);
    $idPedido = (int)($stmt->fetchColumn() ?: $idPedido);
    $stmt = $bdd->prepare("SELECT id_colegio, id_periodo FROM pedidos WHERE id = ? AND estado != 3");
    $stmt->execute([$idPedido]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $idColegio = (int)$row['id_colegio']; $idPeriodo = (int)$row['id_periodo']; }
}
if ($idColegio <= 0 || $idPeriodo <= 0) {
    echo json_encode(['success' => false, 'message' => 'No se encontró el colegio.']);
    exit;
}
if (!periodo_permitido_backorders_pedido($bdd, $idPeriodo)) {
    echo json_encode(['success' => false, 'message' => 'Este colegio no tiene pedidos de los periodos ' . implode(', ', BACKORDERS_PERIODOS_PEDIDO) . '.']);
    exit;
}

$detalle = obtener_backorder_colegio($bdd, $idColegio, $idPeriodo);
if ($detalle === null) {
    echo json_encode(['success' => false, 'message' => 'Este colegio no tiene pedidos con backorder en ese período.']);
    exit;
}
echo json_encode(['success' => true, 'pedido' => $detalle]);
