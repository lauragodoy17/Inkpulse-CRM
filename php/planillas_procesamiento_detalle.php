<?php
/**
 * /php/planillas_procesamiento_detalle.php
 * Detalle (colegio, responsable, fecha) de una planilla puntual — para expandir una fila en
 * reporte_planillas_procesamiento.php. Ver includes/planillas_procesamiento_datos.php.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/planillas_procesamiento_datos.php");
header('Content-Type: application/json');

$tipo = trim($_GET['tipo'] ?? '');
$idPlanilla = (int)($_GET['id'] ?? 0);
if (!isset(tipos_planillas_procesamiento()[$tipo]) || $idPlanilla <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
    exit;
}

$detalle = obtener_detalle_planilla_procesamiento($bdd, $tipo, $idPlanilla);
echo json_encode(['success' => true, 'detalle' => $detalle]);
