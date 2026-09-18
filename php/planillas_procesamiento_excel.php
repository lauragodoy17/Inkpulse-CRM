<?php
/**
 * /php/planillas_procesamiento_excel.php
 * Exporta a Excel el listado de reporte_planillas_procesamiento.php: una fila por planilla
 * generada (Consecutivo/Tipo/Fecha/Usuario/# Pedidos), con el detalle de cada una (colegio,
 * responsable, fecha del pedido) justo debajo, indentado — misma información que se ve al
 * expandir una fila en la página, para que el Excel no se quede corto frente a lo que ya se ve en
 * pantalla. Respeta los mismos filtros (desde/hasta/tipo) que la vista web. Pedido por el usuario
 * 2026-09-18.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

require_once("../lib/autoload-phpspreadsheet.php");
require_once("../lib/ZipStream/src/Option/Archive.php");
require_once("../lib/MyCLabs/Enum/Enum.php");
require_once("../lib/ZipStream/src/Option/Method.php");
require_once("../lib/ZipStream/src/ZipStream.php");
require_once("../lib/ZipStream/src/Bigint.php");
require_once("../lib/ZipStream/src/Option/File.php");
require_once("../lib/ZipStream/src/File.php");
require_once("../lib/ZipStream/src/Option/Version.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../conexion/bdd.php");
require_once("../includes/planillas_procesamiento_datos.php");

ini_set('memory_limit', '512M');
set_time_limit(120);

$fechaDesde = trim($_GET['desde'] ?? '') ?: null;
$fechaHasta = trim($_GET['hasta'] ?? '') ?: null;
$tipoFiltro = trim($_GET['tipo'] ?? '') ?: null;
$tiposInfo = tipos_planillas_procesamiento();
if ($tipoFiltro && !isset($tiposInfo[$tipoFiltro])) $tipoFiltro = null;

$filas = obtener_planillas_procesamiento($bdd, $fechaDesde, $fechaHasta, $tipoFiltro);

$filtroLabel = 'Todos';
if ($tipoFiltro) $filtroLabel = $tiposInfo[$tipoFiltro]['label'];
$rangoLabel = ($fechaDesde || $fechaHasta)
    ? ($fechaDesde ?: '(sin definir)') . ' a ' . ($fechaHasta ?: '(sin definir)')
    : 'Todas las fechas';

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Planillas generadas");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("PLANILLAS GENERADAS");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

// ── Encabezado: logo, título, filtros y fecha — mismo patrón ya usado en los demás reportes
// Excel del CRM (ver php/backorders_excel.php, php/informe_editorial_excel.php) ─────────────
$drawing = new Drawing();
$drawing->setName('logo');
$drawing->setDescription('logo');
$drawing->setPath('../vendors/images/logo_eureka.png');
$drawing->setHeight(70);
$drawing->setCoordinates('A1');
$drawing->setWorksheet($hoja);

$estiloTitulo   = ['font' => ['bold' => true, 'size' => 14]];
$estiloEtiqueta = ['font' => ['bold' => true]];

$hoja->mergeCells('D1:H1');
$hoja->getStyle('D1')->applyFromArray($estiloTitulo);
$hoja->setCellValue('D1', 'PLANILLAS GENERADAS');

$hoja->getStyle('D2')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D2', 'Rango de fechas:');
$hoja->setCellValue('E2', $rangoLabel);
$hoja->getStyle('D3')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D3', 'Tipo:');
$hoja->setCellValue('E3', $filtroLabel);
$hoja->getStyle('D4')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D4', 'Fecha del reporte:');
$hoja->setCellValue('E4', date('Y-m-d H:i'));

// ── Tabla ────────────────────────────────────────────────────────────────────────────────────
$filaInicioTabla = 7;
$fila = $filaInicioTabla;

$estiloCol = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
];
$hoja->fromArray(['Consecutivo', 'Tipo', 'Fecha de generación', 'Generada por', '# Pedidos', 'Colegio (detalle)', 'Responsable (detalle)', 'Fecha del pedido (detalle)'], null, 'A' . $fila);
$hoja->getStyle('A' . $fila . ':H' . $fila)->applyFromArray($estiloCol);
$fila++;

$estiloPlanilla = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$estiloDetalle  = ['font' => ['color' => ['rgb' => '475569']]];

foreach ($filas as $f) {
    $hoja->setCellValue('A' . $fila, '#' . str_pad($f['id'], 6, '0', STR_PAD_LEFT));
    $hoja->setCellValue('B' . $fila, $f['tipo_label']);
    $hoja->setCellValue('C' . $fila, date('d/m/Y H:i', strtotime($f['fecha_generacion'])));
    $hoja->setCellValue('D' . $fila, $f['usuario']);
    $hoja->setCellValue('E' . $fila, $f['cantidad_pedidos']);
    $hoja->getStyle('A' . $fila . ':E' . $fila)->applyFromArray($estiloPlanilla);
    $fila++;

    $detalle = obtener_detalle_planilla_procesamiento($bdd, $f['tipo'], $f['id']);
    foreach ($detalle as $d) {
        $hoja->setCellValue('A' . $fila, '  #' . $d['id']);
        $hoja->setCellValue('F' . $fila, $d['colegio'] ?? '');
        $hoja->setCellValue('G' . $fila, $d['responsable'] ?? '');
        $hoja->setCellValue('H' . $fila, !empty($d['fecha']) ? date('d/m/Y', strtotime($d['fecha'])) : '');
        $hoja->getStyle('A' . $fila . ':H' . $fila)->applyFromArray($estiloDetalle);
        $fila++;
    }
}
$finTabla = $fila - 1;

if (empty($filas)) {
    $hoja->setCellValue('A' . $fila, 'No hay planillas generadas para este filtro.');
}

foreach (range('A', 'H') as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
if ($finTabla >= $filaInicioTabla) $hoja->freezePane('A' . ($filaInicioTabla + 1));

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="planillas_generadas_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
