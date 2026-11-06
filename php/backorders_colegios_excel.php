<?php
/**
 * /php/backorders_colegios_excel.php
 * Exporta la sección "Backorders por colegio" de reporte_backorders.php: una fila por colegio (sus
 * pedidos y totales) seguida de una fila indentada por cada libro con algo pendiente. Lo devuelto
 * se resta al total del colegio (ver obtener_backorders_por_colegio()) pero no se muestra.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

ini_set('memory_limit', '1024M');
set_time_limit(120);
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/periodos_fechas.php");

asegurar_fechas_periodos($bdd);
$idPeriodo = isset($_GET['id_periodo']) && $_GET['id_periodo'] !== ''
    ? (int)$_GET['id_periodo']
    : obtener_periodos_backorders($bdd)['periodoActivo'];
$buscar = mb_strtolower(trim($_GET['buscar'] ?? ''), 'UTF-8');

$colegios = obtener_backorders_por_colegio($bdd, $idPeriodo)['colegios'];
if ($buscar !== '') {
    $colegios = array_values(array_filter($colegios, fn($c) => mb_strpos(mb_strtolower($c['colegio'] . ' ' . $c['cliente'], 'UTF-8'), $buscar) !== false));
}

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Backorders por colegio");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("BACKORDERS POR COLEGIO");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

$periodoLabelExcel = 'Todos';
if ($idPeriodo) {
    $reqPer = $bdd->prepare("SELECT p.periodo, c.calendario FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE p.id = ?");
    $reqPer->execute([$idPeriodo]);
    $rowPer = $reqPer->fetch(PDO::FETCH_ASSOC);
    if ($rowPer) $periodoLabelExcel = $rowPer['periodo'] . ' (Calendario ' . $rowPer['calendario'] . ')';
}
excel_encabezado($hoja, $bdd, 'BACKORDERS POR COLEGIO', 'Periodo: ' . $periodoLabelExcel . ($buscar !== '' ? ' — Buscar: ' . $buscar : ''), 'G');

$estiloEncabezado = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']]];
$estiloColegio = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$estiloLibro = ['font' => ['color' => ['rgb' => '475569']]];

function estilo_cumplimiento($pct) {
    if ($pct === null) return ['font' => ['color' => ['rgb' => '64748B']]];
    if ($pct < 50)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['color' => ['rgb' => '991B1B']]];
    if ($pct < 80)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['color' => ['rgb' => '854D0E']]];
    return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']], 'font' => ['color' => ['rgb' => '166534']]];
}

$filaInicioTabla = EXCEL_FILA_ENCABEZADOS;
$fila = $filaInicioTabla;
// Sin las columnas de devoluciones / notas crédito ni de lo devuelto (pedido por la usuaria
// 2026-11-06); lo devuelto se sigue restando en el pendiente y el % del colegio.
$hoja->fromArray(['ISBN', 'COLEGIO / LIBRO', 'PEDIDOS', 'SOLICITADO', 'REMISIONADO / FACTURADO', 'PENDIENTE', '% CUMPLIMIENTO'], null, 'A' . $fila);
$hoja->getStyle('A' . $fila . ':G' . $fila)->applyFromArray($estiloEncabezado);
$fila++;

$inicioDatos = $fila;
foreach ($colegios as $c) {
    $hoja->setCellValue('B' . $fila, $c['colegio'] . ($c['cliente'] !== '' ? ' — ' . $c['cliente'] : ''));
    $hoja->setCellValue('C' . $fila, implode(', ', array_map(fn($p) => '#' . $p, $c['pedidos'])));
    $hoja->fromArray([$c['solicitada'], $c['remisionada'], $c['pendiente'],
        $c['cumplimiento'] === null ? 'N/A' : $c['cumplimiento'] / 100], null, 'D' . $fila, true);
    $hoja->getStyle('A' . $fila . ':F' . $fila)->applyFromArray($estiloColegio);
    $estilo = estilo_cumplimiento($c['cumplimiento']);
    $estilo['font']['bold'] = true;
    $hoja->getStyle('G' . $fila)->applyFromArray($estilo);
    $fila++;
    foreach ($c['libros'] as $l) {
        $hoja->setCellValueExplicit('A' . $fila, $l['isbn'] !== '' ? $l['isbn'] : '—', DataType::TYPE_STRING);
        $hoja->setCellValue('B' . $fila, '    ' . $l['libro']);
        $hoja->fromArray([$l['solicitada'], $l['remisionada'], $l['pendiente'], $l['cumplimiento'] / 100], null, 'D' . $fila, true);
        $hoja->getStyle('A' . $fila . ':F' . $fila)->applyFromArray($estiloLibro);
        $hoja->getStyle('G' . $fila)->applyFromArray(estilo_cumplimiento($l['cumplimiento']));
        $fila++;
    }
}
if (empty($colegios)) {
    $hoja->setCellValue('A' . $fila, 'No hay backorders pendientes.');
}

$hoja->getStyle('D' . $inicioDatos . ':F' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('G' . $inicioDatos . ':G' . $fila)->getNumberFormat()->setFormatCode('0.0%');
$hoja->getStyle('D' . $filaInicioTabla . ':G' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle('A' . $filaInicioTabla . ':C' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->freezePane('C' . $inicioDatos);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="backorders_por_colegio_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
