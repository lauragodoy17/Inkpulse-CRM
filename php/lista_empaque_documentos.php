<?php
/**
 * /php/lista_empaque_documentos.php?clientes=12,84&claves=REM:1052,FV:33
 * Documentos de World Office que el usuario marcó de los clientes elegidos, validados y con sus
 * productos (ver le_datos_documentos()).
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");
set_time_limit(120);

le_crear_tablas($bdd);
$claves = array_filter(explode(',', (string)($_GET['claves'] ?? '')), fn($c) => preg_match('/^(REM|FV):\d+$/', $c));
echo json_encode(le_datos_documentos($bdd, explode(',', (string)($_GET['clientes'] ?? '')), $claves), JSON_UNESCAPED_UNICODE);
