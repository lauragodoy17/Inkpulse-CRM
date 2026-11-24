<?php
/**
 * /ajax/devoluciones_ventas_detalle.php?claves[]=v210&claves[]=v215
 * Paso 2 de devoluciones_ventas.php: documentos CEUR/FVE de World Office de las OP de los pedidos de venta
 * elegidos y títulos sumados con lo ya devuelto y lo disponible (ver includes/devoluciones_ventas_datos.php).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/devoluciones_ventas_datos.php");

dm_validar_acceso(true);
dvv_asegurar_columnas($bdd);
session_write_close(); // la consulta a WO puede tardar: no bloquear las demás pestañas

// tipo=corte: remisionado − todas las devoluciones del colegio (ver dvv_detalle_varios()).
// sel=1 (venta directa): solo los documentos de WO elegidos docs[] (ids, o 'crm:v210' para un pedido sin documentos).
$docs_sel = !empty($_GET['sel']) ? array_map('strval', (array)($_GET['docs'] ?? [])) : null;
$detalle = dvv_detalle_varios($bdd, (array)($_GET['claves'] ?? []), dvv_tipo_devolucion((string)($_GET['tipo'] ?? '')), false, $docs_sel);
foreach ($detalle['titulos'] ?? [] as $i => $t) unset($detalle['titulos'][$i]['partes']);
// Venta directa (2026-11-24): a qué venta (consecutivo reservado o el formato donde ya están los pedidos) va la devolución.
if (!empty($detalle['ok']) && !empty($detalle['es_vd'])) {
    require_once("../includes/solicitudes_vd_datos.php");
    $detalle['ventas'] = svd_ventas_para_devolucion($bdd, $detalle['id_colegio'], $detalle['id_periodo'], $detalle['incluidos']);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
