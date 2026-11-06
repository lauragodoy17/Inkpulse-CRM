<?php
/**
 * /php/backorders_marcar_completado.php  (POST id_colegio, id_periodo, motivo)
 * Botón "Marcar como completado" de reporte_backorders_pedido.php: el colegio + período deja de
 * aparecer en la búsqueda y de contar en todo el módulo de backorders, aunque se deban unidades —
 * ver backorders_marcar_colegio_completado().
 */
require_once("aut.php");

header('Content-Type: application/json');
$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
$idUsuario = intval($_SESSION['id'] ?? 0);
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");

$idColegio = intval($_POST['id_colegio'] ?? 0);
$idPeriodo = intval($_POST['id_periodo'] ?? 0);
$motivo = mb_substr(trim($_POST['motivo'] ?? ''), 0, 500);
if ($idColegio <= 0 || $idPeriodo <= 0) {
    echo json_encode(['success' => false, 'message' => 'No se indicó el colegio.']);
    exit;
}

backorders_marcar_colegio_completado($bdd, $idColegio, $idPeriodo, $idUsuario, $motivo);
echo json_encode(['success' => true]);
