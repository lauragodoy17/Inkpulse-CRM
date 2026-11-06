<?php
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

ini_set('memory_limit', '1024M');
set_time_limit(120);
// Ver mismo comentario en php/backorders_tabla.php: libera el lock de sesión para no bloquear
// otras pestañas del usuario mientras esta consulta corre.
session_write_close();

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/periodos_fechas.php");
header('Content-Type: application/json');

asegurar_fechas_periodos($bdd);
$infoPeriodos = obtener_periodos_backorders($bdd);
$idPeriodo = isset($_GET['id_periodo']) && $_GET['id_periodo'] !== ''
    ? (int)$_GET['id_periodo']
    : $infoPeriodos['periodoActivo'];

$datos = obtener_cumplimiento_por_cliente($bdd, $idPeriodo);
echo json_encode(['success' => true, 'idPeriodo' => $idPeriodo] + $datos);
