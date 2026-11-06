<?php
/**
 * Libros cargados en un pedido sin adopción, para precargar las líneas de "Nuevo backorder" en
 * backorders_sa.php al seleccionar el pedido — ver bsa_libros_pedido2(). La cantidad es también el
 * máximo permitido para ese libro. Si el pedido no tiene libros devuelve [] y se cargan a mano.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

header('Content-Type: application/json; charset=utf-8');
if (!bsa_tiene_acceso()) { echo json_encode([]); exit; }
session_write_close();

$out = [];
foreach (bsa_libros_pedido2($bdd, (int)($_GET['id_pedido2'] ?? 0)) as $id => $l) {
    $out[] = [
        'id' => $id,
        'text' => $l['libro'] . ($l['isbn'] !== '' ? ' — ' . $l['isbn'] : ''),
        'cantidad' => $l['cantidad'],
    ];
}
echo json_encode($out);
