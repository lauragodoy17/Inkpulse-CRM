<?php
/**
 * /php/planilla_devolucion.php?id=N — PDF de una planilla de devolución ya generada (planillas_devolucion), igual a
 * como se generó (foto guardada). Se generan en el módulo planillas_devolucion.php (menú Venta directa).
 * ?id_pedido=N (enlace desde el pedido) lleva al módulo con ese pedido elegido.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/planillas_devolucion_datos.php");

dm_validar_acceso(false, '../');

if (!isset($_GET['id']) && isset($_GET['id_pedido'])) {
    header('Location: ../planillas_devolucion.php?id_pedido=' . (int)$_GET['id_pedido']);
    exit;
}
$f = pdv_obtener($bdd, (int)($_GET['id'] ?? 0));
if (!$f) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:Arial;padding:30px">La planilla no existe o no está en tu alcance.</p>';
    exit;
}
$pdf = pdv_pdf($f);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="planilla_devolucion_' . pdv_numero($f['id']) . '_pedido_' . (int)$f['id_pedido'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
