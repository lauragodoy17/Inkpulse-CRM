<?php
/**
 * /php/oc_backorders_listar.php            → todos los backorders de órdenes de compra (JSON)
 * /php/oc_backorders_listar.php?id=<id>    → historial de recepciones de un backorder
 * Datos del CRM (tablas oc_*), sin consultar World Office.
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.', 'data' => []]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/ordenes_compra_datos.php");

oc_crear_tablas($bdd);
if (isset($_GET['id'])) {
    echo json_encode(oc_historial_backorder($bdd, intval($_GET['id'])), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => true, 'error' => null, 'data' => oc_listar_backorders($bdd)], JSON_UNESCAPED_UNICODE);
}
