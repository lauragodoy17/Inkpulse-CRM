<?php
/**
 * /ajax/devoluciones_muestras_detalle.php
 * Paso 2 de devoluciones_muestras.php: OP del pedido de muestras, documentos de World Office y
 * títulos despachados con lo ya devuelto y lo disponible (ver includes/devoluciones_muestras_datos.php).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/legalizaciones_muestras_datos.php"); // incluye devoluciones_muestras_datos.php

dm_validar_acceso(true);
dm_asegurar_columnas($bdd);

// modo=legalizacion lo usa legalizaciones_muestras.php: saldo despachado − legalizado y solo remisiones
// RE22; fuente=crm es su botón "Traer información del pedido" (lo solicitado en el CRM) (ver lm_detalle_legalizacion()).
$modo = ($_GET['modo'] ?? '') === 'legalizacion' ? 'legalizacion' : 'devolucion';
$idMuestreo = (int)($_GET['id_muestreo'] ?? 0);
$detalle = $modo === 'legalizacion'
    ? lm_detalle_legalizacion($bdd, $idMuestreo, ($_GET['fuente'] ?? '') === 'crm' ? 'crm' : 'auto')
    : dm_detalle_devolucion($bdd, $idMuestreo, $modo);
if (!empty($detalle['muestreo']['fecha'])) {
    $detalle['muestreo']['fecha'] = date('d/m/Y', strtotime($detalle['muestreo']['fecha']));
}
// Los renglones crudos no hacen falta en la pantalla (ya van sumados en "titulos").
foreach ($detalle['documentos'] ?? [] as $i => $doc) unset($detalle['documentos'][$i]['renglones']);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
