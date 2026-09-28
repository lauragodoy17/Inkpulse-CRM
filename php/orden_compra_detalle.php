<?php
/**
 * /php/orden_compra_detalle.php?id=<id del documento en WO>
 * Una orden de compra para orden_compra.php: encabezado y productos de World Office, con lo
 * recibido en el CRM, el resumen y el historial de recepciones (ver oc_datos_orden()).
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/ordenes_compra_datos.php");
set_time_limit(90);

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Orden de compra no válida.']);
    exit;
}
oc_crear_tablas($bdd);
echo json_encode(oc_datos_orden($bdd, $id), JSON_UNESCAPED_UNICODE);
