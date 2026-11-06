<?php
/**
 * Buscador (select2) de pedidos sin adopción (pedidos2) para "Backorders sin adopción": por número
 * de pedido, colegio o cliente — ver bsa_buscar_pedidos2(). Reemplaza al buscador de terceros de
 * World Office (ajax/bsa_buscar_terceros.php) desde 2026-10-20: el cliente sale del pedido.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

header('Content-Type: application/json; charset=utf-8');
if (!bsa_tiene_acceso()) { echo json_encode(['results' => []]); exit; }
session_write_close();

$out = [];
foreach (bsa_buscar_pedidos2($bdd, $_GET['q'] ?? '') as $p) {
    $out[] = [
        'id' => (int)$p['id'],
        'text' => 'Pedido #' . $p['id'] . ' — ' . $p['cliente'] . ($p['documento'] ? ' (' . $p['documento'] . ')' : '')
                . ' — ' . $p['colegio'] . ' — ' . bsa_tipo_pedido2($p['tipo']) . ' — ' . $p['fecha'] . ' — ' . $p['estado'],
        'cliente' => $p['cliente'],
        'documento' => (string)$p['documento'],
    ];
}
echo json_encode(['results' => $out]);
