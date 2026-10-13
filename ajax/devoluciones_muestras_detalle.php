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
// RE22; las devoluciones buscan en remisiones RE22 y CEUR. En ambos, fuente=crm es el botón "Traer
// información del pedido" (lo solicitado en el CRM, ver dm_detalle_crm()).
$modo = ($_GET['modo'] ?? '') === 'legalizacion' ? 'legalizacion' : 'devolucion';
$idMuestreo = (int)($_GET['id_muestreo'] ?? 0);
$fuente = ($_GET['fuente'] ?? '') === 'crm' ? 'crm' : 'auto';
$detalle = $modo === 'legalizacion'
    ? lm_detalle_legalizacion($bdd, $idMuestreo, $fuente)
    : dm_detalle_por_fuente($bdd, $idMuestreo, 'devolucion', $fuente, DM_PREFIJOS_DEVOLUCION);
if (!empty($detalle['muestreo']['fecha'])) {
    $detalle['muestreo']['fecha'] = date('d/m/Y', strtotime($detalle['muestreo']['fecha']));
}
// Los renglones crudos no hacen falta en la pantalla (ya van sumados en "titulos").
foreach ($detalle['documentos'] ?? [] as $i => $doc) unset($detalle['documentos'][$i]['renglones']);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
