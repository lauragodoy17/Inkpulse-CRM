<?php
/**
 * Ítems (libros) de un documento de World Office guardado en el historial
 * de op_pendiente.php, para el botón "Ver" (ojo) de cada registro.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/api_wo_documentos_op.php");

header('Content-Type: application/json; charset=utf-8');

$id_wo = intval($_GET['id_wo'] ?? 0);
if ($id_wo <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Documento inválido.', 'items' => [], 'total' => 0]);
    exit;
}

$resultado = wo_obtener_items_documento($id_wo);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
