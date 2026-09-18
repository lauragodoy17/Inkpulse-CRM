<?php
/**
 * /php/planilla_procesamiento_pdf.php
 * Vuelve a descargar el PDF de UNA planilla ya generada (?tipo=venta|muestreo|sa&id=N), a partir
 * de los pedidos que quedaron asociados a ella (ver includes/planillas_procesamiento_datos.php) —
 * para reporte_planillas_procesamiento.php, cuando alguien necesita el PDF original otra vez sin
 * tener que ir hasta el módulo de Aprobados a re-generarlo (ni poder, ya que esos pedidos ya no
 * están en estado Aprobado). Reutiliza EXACTAMENTE la misma función de armado de PDF que
 * php/generar_planilla_procesamiento*.php (includes/planilla_pdf.php), para que el documento
 * salga igual. Pedido por el usuario 2026-09-18.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

require_once("../conexion/bdd.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/planilla_pdf.php");
require_once("../includes/planillas_procesamiento_datos.php");

function ppp_error($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif;color:#b91c1c;padding:40px">' . htmlspecialchars($msg) .
         '</p><p><a href="javascript:history.back()">Volver</a></p>';
    exit;
}

$tipo = trim($_GET['tipo'] ?? '');
$idPlanilla = (int)($_GET['id'] ?? 0);
$tiposInfo = tipos_planillas_procesamiento();
if (!isset($tiposInfo[$tipo]) || $idPlanilla <= 0) ppp_error('Planilla inválida.');

$stmt = $bdd->prepare("SELECT id, fecha_generacion FROM {$tiposInfo[$tipo]['tabla_planilla']} WHERE id = ?");
$stmt->execute([$idPlanilla]);
$planilla = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$planilla) ppp_error('Esa planilla ya no existe.');

$procesados = obtener_detalle_planilla_procesamiento($bdd, $tipo, $idPlanilla);
if (empty($procesados)) ppp_error('Esta planilla no tiene pedidos asociados (puede que se hayan eliminado).');

// La etiqueta "Tipo:" dentro del PDF usa el mismo texto que ya escribían
// php/generar_planilla_procesamiento*.php originalmente ("Con adopción" en vez de "Pedidos de
// venta"), para que una planilla re-descargada se vea idéntica a como salió la primera vez.
$tipoLabelPdf = ['venta' => 'Con adopción', 'muestreo' => 'Muestreo', 'sa' => 'Sin adopción'][$tipo];

$cols = planilla_pdf_columnas();
// Fecha y hora ORIGINALES (cuando se generó/descargó la primera vez, guardadas en
// fecha_generacion) — no la de este re-descargo. Pedido por el usuario 2026-09-18: "que quede la
// hora y la fecha con la que se registró o se descargó por primera vez".
$fecha_descarga = date('d/m/Y H:i:s', strtotime($planilla['fecha_generacion']));
$pdf_contenido = planilla_pdf_generar($idPlanilla, $fecha_descarga, $procesados, $cols, $tipoLabelPdf);

$sufijo = $tipo === 'venta' ? '' : ('_' . $tipo);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="planilla_procesamiento' . $sufijo . '_' . $idPlanilla . '.pdf"');
header('Content-Length: ' . strlen($pdf_contenido));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf_contenido;
