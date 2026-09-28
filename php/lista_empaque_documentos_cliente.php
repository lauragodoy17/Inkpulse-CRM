<?php
/**
 * /php/lista_empaque_documentos_cliente.php?clientes=12,84
 * Remisiones y facturas de World Office de los clientes elegidos en lista_empaque.php (ver
 * le_documentos_cliente()).
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.', 'documentos' => []]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");

// Varios clientes con muchos documentos son varias páginas de WO (50 por página).
set_time_limit(120);
echo json_encode(le_documentos_cliente($bdd, explode(',', (string)($_GET['clientes'] ?? ''))), JSON_UNESCAPED_UNICODE);
