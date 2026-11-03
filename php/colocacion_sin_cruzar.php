<?php
/**
 * /php/colocacion_sin_cruzar.php
 * JSON del panel "Documentos sin cruzar" de reporte_colocacion.php. Va aparte de
 * php/colocacion_tabla.php porque no depende del período elegido: un documento sin colegio todavía
 * no tiene calendario, así que se muestran todos los pendientes.
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

echo json_encode(['success' => true, 'documentos' => obtener_documentos_sin_cruzar($bdd)]);
