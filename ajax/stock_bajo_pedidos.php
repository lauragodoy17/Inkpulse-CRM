<?php
/**
 * /ajax/stock_bajo_pedidos.php
 * Recibe un lote de ids de pedidos (con o sin adopción) y devuelve, para
 * cada uno que tenga al menos un libro con existencia real en la bodega
 * General de World Office por debajo del umbral, el detalle de esos libros.
 * Lógica compartida en includes/stock_bajo.php (también usada al crear un
 * pedido, para avisar por correo si queda con stock bajo).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/stock_bajo.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['tipo'] ?? null) != 1) {
    echo json_encode([]);
    exit;
}

$origen = ($_POST['origen'] ?? '') === 'pedidos2' ? 'pedidos2' : 'pedidos';
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [];

echo json_encode(libros_bajo_stock_pedidos($bdd, $ids, $origen));
