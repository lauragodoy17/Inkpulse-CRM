<?php
/**
 * /php/mercado_excel.php
 * Exporta la pestaña "Mercado editorial" de colegio.php: un título por fila con alumnos del grado,
 * tasa de compra, PVP, descuento, precio neto, ejemplares y venta potencial (ver mercado_filas()).
 */
require_once("aut.php");

session_write_close();

include("../lib/autoload-phpspreadsheet.php");
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

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
require_once("../includes/mercado_editorial_datos.php");

$colegio = mercado_colegio($bdd, (int)($_GET['cole'] ?? 0));
$periodo = mercado_periodo($bdd, (int)($_GET['periodo'] ?? 0));
if (!$colegio || !$periodo) {
    header("Location: ../index.php");
    exit;
}
$filas = mercado_filas($bdd, $colegio['id'], $periodo);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Mercado editorial");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("MERCADO EDITORIAL");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

excel_encabezado($hoja, $bdd, 'MERCADO EDITORIAL — ' . $colegio['colegio'], 'Periodo: ' . $periodo['periodo'], 'E');

$fila = EXCEL_FILA_ENCABEZADOS;
$hoja->fromArray(['EDITORIAL', 'TIPO', 'TÍTULO', 'MATERIA', 'GRADO', 'VIGENCIA', 'ALUMNOS', 'TASA DE COMPRA', 'PVP',
    'DESCUENTO', 'PRECIO NETO', 'EJEMPLARES', 'VENTA POTENCIAL'], null, 'A' . $fila);
excel_estilo_encabezados($hoja, 'A' . $fila . ':M' . $fila);
$hoja->getStyle('A' . $fila . ':M' . $fila)->getFont()->setBold(true);
$fila++;

$inicioDatos = $fila;
foreach ($filas as $f) {
    $hoja->fromArray([$f['editorial'], $f['tipo'], $f['libro'], $f['materia'], $f['grado'], $f['vigencia'], $f['alumnos'],
        $f['tasa'] / 100, $f['precio'], $f['desc'] / 100, $f['neto'], $f['ejemplares'], $f['venta']], null, 'A' . $fila, true);
    $fila++;
}

if ($filas) {
    $finDatos = $fila - 1;
    $hoja->setCellValue('A' . $fila, 'TOTAL');
    $hoja->setCellValue('L' . $fila, '=SUM(L' . $inicioDatos . ':L' . $finDatos . ')');
    $hoja->setCellValue('M' . $fila, '=SUM(M' . $inicioDatos . ':M' . $finDatos . ')');
    $hoja->getStyle('A' . $fila . ':M' . $fila)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    ]);
} else {
    $hoja->setCellValue('A' . $fila, 'No hay títulos registrados en el mercado editorial de este colegio para el periodo.');
}

$hoja->getStyle('G' . $inicioDatos . ':G' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('H' . $inicioDatos . ':H' . $fila)->getNumberFormat()->setFormatCode('0%');
$hoja->getStyle('I' . $inicioDatos . ':I' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('J' . $inicioDatos . ':J' . $fila)->getNumberFormat()->setFormatCode('0%');
$hoja->getStyle('K' . $inicioDatos . ':K' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('L' . $inicioDatos . ':L' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('M' . $inicioDatos . ':M' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('G' . EXCEL_FILA_ENCABEZADOS . ':L' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
foreach (range('A', 'M') as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->freezePane('A' . $inicioDatos);

$nombre = 'mercado_editorial_' . preg_replace('/[^A-Za-z0-9]+/', '_', $colegio['codigo']) . '_' . date('Y-m-d') . '.xlsx';
$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
