<?php
/**
 * /php/lista_empaque_anular.php (POST id, motivo)
 * Anula una lista de empaque (solo Administrador): queda en el historial como anulada y sus
 * documentos de World Office quedan libres para otra lista.
 */
require_once("aut.php");
header('Content-Type: application/json');

if (intval($_SESSION["tipo"] ?? 0) !== 1 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un Administrador puede anular listas de empaque.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");

le_crear_tablas($bdd);
echo json_encode(le_anular($bdd, intval($_POST['id'] ?? 0), $_POST['motivo'] ?? '', intval($_SESSION['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
