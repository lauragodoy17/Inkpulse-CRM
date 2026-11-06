<?php
/**
 * /php/backorders_titulos_excel.php
 * Exporta la sección "Backorder general de libros" de reporte_backorders.php:
 * una fila por título con su ISBN, existencias y totales (Solicitado/Despachado/Pendiente/%
 * Cumplimiento), seguida de una fila indentada por cada usuario que lo pidió.
 * Mismo orden de prioridad que la vista web: menor % de cumplimiento primero.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

ini_set('memory_limit', '1024M');
set_time_limit(120);
// Ver mismo comentario en php/backorders_excel.php: libera el lock de sesión para no bloquear
// otras pestañas del usuario mientras se genera el archivo.
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
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/periodos_fechas.php");

asegurar_fechas_periodos($bdd);
$idPeriodo = isset($_GET['id_periodo']) && $_GET['id_periodo'] !== ''
    ? (int)$_GET['id_periodo']
    : obtener_periodos_backorders($bdd)['periodoActivo'];

$datos = obtener_datos_backorders_por_titulo($bdd, $idPeriodo);
$titulos = $datos['titulos'];

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Backorders por titulo");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("PRIORIDAD POR TITULO");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

// Etiqueta legible del filtro de período — para que quien reciba el Excel sepa con qué se generó
// (pedido por el usuario 2026-09-18).
$periodoLabelExcel = 'Todos';
if ($idPeriodo) {
    $reqPer = $bdd->prepare("SELECT p.periodo, c.calendario FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE p.id = ?");
    $reqPer->execute([$idPeriodo]);
    $rowPer = $reqPer->fetch(PDO::FETCH_ASSOC);
    if ($rowPer) $periodoLabelExcel = $rowPer['periodo'] . ' (Calendario ' . $rowPer['calendario'] . ')';
}

// Encabezado: logo (mismo que el resto de reportes del CRM), título, filtro aplicado y fecha —
// formato estándar de includes/excel_formato.php, la tabla de datos arranca en la fila 6.
excel_encabezado($hoja, $bdd, 'BACKORDER POR TÍTULO — PRIORIDAD', 'Periodo: ' . $periodoLabelExcel, 'G');

$estiloEncabezado = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']]];
$estiloTitulo = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$estiloUsuario = ['font' => ['color' => ['rgb' => '475569']]];

// Mismos umbrales de color que las etiquetas de la vista web (rojo/amarillo/verde), para que la
// prioridad se note a simple vista sin tener que leer el número.
function estilo_cumplimiento($pct) {
    if ($pct < 50)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['color' => ['rgb' => '991B1B']]];
    if ($pct < 80)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['color' => ['rgb' => '854D0E']]];
    return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']], 'font' => ['color' => ['rgb' => '166534']]];
}

$filaInicioTabla = EXCEL_FILA_ENCABEZADOS;
$fila = $filaInicioTabla;
$hoja->fromArray(['ISBN', 'TÍTULO / USUARIO', 'EXISTENCIAS', 'SOLICITADO', 'REMISIONADO / FACTURADO', 'PENDIENTE', '% CUMPLIMIENTO'], null, 'A' . $fila);
$hoja->getStyle('A' . $fila . ':G' . $fila)->applyFromArray($estiloEncabezado);
$fila++;

$inicioDatos = $fila;
foreach ($titulos as $t) {
    // ISBN como texto: si se guarda como número, Excel lo muestra en notación científica.
    $hoja->setCellValueExplicit('A' . $fila, $t['isbn'] !== '' ? $t['isbn'] : '—', DataType::TYPE_STRING);
    $hoja->setCellValue('B' . $fila, $t['titulo']);
    // Existencia en bodega General de World Office; rojo si está por debajo del umbral de stock bajo.
    $hoja->setCellValue('C' . $fila, $t['existencia'] === null ? '—' : $t['existencia']);
    $hoja->setCellValue('D' . $fila, $t['solicitada']);
    $hoja->setCellValue('E' . $fila, $t['remisionada']);
    $hoja->setCellValue('F' . $fila, $t['pendiente']);
    $hoja->setCellValue('G' . $fila, $t['cumplimiento'] / 100);
    $hoja->getStyle('A' . $fila . ':F' . $fila)->applyFromArray($estiloTitulo);
    if ($t['existencia'] !== null) {
        $hoja->getStyle('C' . $fila)->getFont()->getColor()->setRGB($t['stock_bajo'] ? 'DC2626' : '15803D');
    }
    // No usar array_merge_recursive: al haber 'fillType' repetido como string en ambos arrays,
    // termina convirtiéndolo en un array (['solid','solid']) en vez de sobrescribirlo, lo que
    // rompe applyFromArray ("Array to string conversion" en Fill.php). Se agrega "bold" aparte.
    $estiloCumpTitulo = estilo_cumplimiento($t['cumplimiento']);
    $estiloCumpTitulo['font']['bold'] = true;
    $hoja->getStyle('G' . $fila)->applyFromArray($estiloCumpTitulo);
    $fila++;

    foreach ($t['usuarios'] as $u) {
        $hoja->setCellValue('B' . $fila, '    ' . ($u['usuario'] ?: '—'));
        $hoja->setCellValue('D' . $fila, $u['solicitada']);
        $hoja->setCellValue('E' . $fila, $u['remisionada']);
        $hoja->setCellValue('F' . $fila, $u['pendiente']);
        $hoja->setCellValue('G' . $fila, $u['cumplimiento'] / 100);
        $hoja->getStyle('B' . $fila . ':F' . $fila)->applyFromArray($estiloUsuario);
        $hoja->getStyle('G' . $fila)->applyFromArray(estilo_cumplimiento($u['cumplimiento']));
        $fila++;
    }
}
$finDatos = $fila - 1;

if (empty($titulos)) {
    $hoja->setCellValue('A' . $fila, 'No hay backorders pendientes.');
}

$hoja->getStyle('C' . $filaInicioTabla . ':G' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('G' . $inicioDatos . ':G' . max($inicioDatos, $finDatos))->getNumberFormat()->setFormatCode('0.0%');
$hoja->getStyle('C' . $filaInicioTabla . ':G' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle('A' . $filaInicioTabla . ':B' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->freezePane('C' . $inicioDatos);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="backorders_por_titulo_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
