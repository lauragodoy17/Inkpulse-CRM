<?php
/**
 * /php/planillas_procesamiento_tabla.php
 * Listado (AJAX) de planillas de procesamiento generadas, con filtro de fecha y tipo — para
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

$fechaDesde = trim($_GET['desde'] ?? '') ?: null;
$fechaHasta = trim($_GET['hasta'] ?? '') ?: null;
$tipoFiltro = trim($_GET['tipo'] ?? '') ?: null;
if ($tipoFiltro && !isset(tipos_planillas_procesamiento()[$tipoFiltro])) $tipoFiltro = null;

$filas = obtener_planillas_procesamiento($bdd, $fechaDesde, $fechaHasta, $tipoFiltro);
echo json_encode(['success' => true, 'filas' => $filas]);
