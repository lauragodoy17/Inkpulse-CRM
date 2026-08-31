<?php
/**
 * /php/colocacion_tabla.php
 * JSON para la tabla en pantalla de reporte_colocacion.php. Ver
 * includes/colocacion_datos.php para la consulta real (compartida con la exportación a Excel).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/colocacion_datos.php");
header('Content-Type: application/json');

$periodos = obtener_periodos_colocacion($bdd);
$idsPermitidos = array_column($periodos, 'id');

$id_periodo = null;
if (isset($_GET['id_periodo']) && in_array((int)$_GET['id_periodo'], $idsPermitidos, true)) {
    $id_periodo = (int)$_GET['id_periodo'];
}

$datos = obtener_datos_colocacion($bdd, $id_periodo);
echo json_encode(array_merge(['success' => true, 'periodos' => $periodos], $datos));
