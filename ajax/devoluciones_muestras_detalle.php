<?php
/**
 * /ajax/devoluciones_muestras_detalle.php
 * Paso 2 de devoluciones_muestras.php: OP del pedido de muestras, documentos de World Office y
 * títulos despachados con lo ya devuelto y lo disponible (ver includes/devoluciones_muestras_datos.php).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/devoluciones_muestras_datos.php");

dm_validar_acceso(true);
dm_asegurar_columnas($bdd);

// modo=legalizacion lo usa legalizaciones_muestras.php: saldo despachado − legalizado.
$modo = ($_GET['modo'] ?? '') === 'legalizacion' ? 'legalizacion' : 'devolucion';
$detalle = dm_detalle_devolucion($bdd, (int)($_GET['id_muestreo'] ?? 0), $modo);
if (!empty($detalle['muestreo']['fecha'])) {
    $detalle['muestreo']['fecha'] = date('d/m/Y', strtotime($detalle['muestreo']['fecha']));
}
// Los renglones crudos no hacen falta en la pantalla (ya van sumados en "titulos").
foreach ($detalle['documentos'] ?? [] as $i => $doc) unset($detalle['documentos'][$i]['renglones']);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
