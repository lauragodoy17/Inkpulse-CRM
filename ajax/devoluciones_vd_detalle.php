<?php
/**
 * /ajax/devoluciones_vd_detalle.php?claves[]=v210&claves[]=v215
 * Paso 2 de devoluciones_venta_directa.php: OP, documentos de World Office y títulos sumados de los
 * pedidos de venta elegidos, con lo ya devuelto, lo disponible y su valor (dvd_detalle_varios).
 * Incluye un token de un solo uso para el formulario, que evita registrar dos veces la misma devolución.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/devoluciones_vd_datos.php");

dm_validar_acceso(true);
dm_asegurar_columnas($bdd);
dvd_asegurar_columnas($bdd);

$detalle = dvd_detalle_varios($bdd, (array)($_GET['claves'] ?? []));
// De cada parte solo lo que necesita la vista previa del saldo (mismo reparto que dvd_guardar_devolucion).
foreach ($detalle['titulos'] ?? [] as $i => $t) {
    $partes = [];
    foreach ($t['partes'] as $pa) {
        if ($pa['cerrado']) continue;
        $pr = $pa['precio'] ?: ['precio' => 0, 'descuento' => 0, 'precio_final' => 0, 'fuente' => $pa['fuente'] === 'crm' ? 'crm' : 'wo'];
        $partes[] = ['id_pedido' => $pa['id_pedido'], 'disponible' => $pa['disponible'], 'fuente_precio' => $pr['fuente'],
                     'precio_final' => $pr['precio_final']];
    }
    $detalle['titulos'][$i]['partes'] = $partes;
    unset($detalle['titulos'][$i]['precio_wo']);
}
$detalle['token'] = bin2hex(random_bytes(16));
// Número que tendría el saldo si se registra ya (el definitivo se asigna al guardar).
$detalle['siguiente_saldo'] = dvd_numero_saldo((int)$bdd->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM saldos_devolucion")->fetchColumn());

header('Content-Type: application/json; charset=utf-8');
echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
