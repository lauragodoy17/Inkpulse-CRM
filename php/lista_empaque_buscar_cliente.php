<?php
/**
 * /php/lista_empaque_buscar_cliente.php
 * Buscador de clientes (con pedidos) para el primer select2 de lista_empaque.php.
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    echo json_encode([]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");

echo json_encode(le_buscar_clientes($bdd, $_GET['q'] ?? ''), JSON_UNESCAPED_UNICODE);
