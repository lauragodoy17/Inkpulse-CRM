<?php
/**
 * /php/backorders_clientes_excel.php
 * Exporta la sección "Cumplimiento de despachos por cliente" de reporte_backorders.php: una fila
 * por cliente (pedidos pendientes, solicitadas, despachadas, pendientes, excedente, % cumplimiento) y una
 * fila de totales consolidada. Solo clientes con pendiente; respeta los filtros de la vista (período, cliente, orden).
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
$buscar = trim($_GET['buscar'] ?? '');
$orden = in_array($_GET['orden'] ?? '', ['pct_asc', 'pct_desc', 'pendiente_desc', 'cliente'], true) ? $_GET['orden'] : 'pct_asc';

$datos = obtener_cumplimiento_por_cliente($bdd, $idPeriodo);
$clientes = filtrar_cumplimiento_por_cliente($datos['clientes'], $buscar, $orden);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Cumplimiento por cliente");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("CUMPLIMIENTO POR CLIENTE");
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
$filtroLabel = 'Periodo: ' . $periodoLabelExcel
    . ($buscar !== '' ? ' — Cliente: ' . $buscar : '');

excel_encabezado($hoja, $bdd, 'CUMPLIMIENTO DE DESPACHOS POR CLIENTE', $filtroLabel, 'G');

$estiloEncabezado = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']]];
$estiloTotal = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];

// Mismos umbrales de color que la vista web (rojo/amarillo/verde).
function estilo_cumplimiento($pct) {
    if ($pct === null) return ['font' => ['color' => ['rgb' => '64748B']]];
    if ($pct < 50)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['color' => ['rgb' => '991B1B']]];
    if ($pct < 80)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['color' => ['rgb' => '854D0E']]];
    return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']], 'font' => ['color' => ['rgb' => '166534']]];
}

$filaInicioTabla = EXCEL_FILA_ENCABEZADOS;
$fila = $filaInicioTabla;
$hoja->fromArray(['CLIENTE', 'PEDIDOS PENDIENTES', 'SOLICITADAS', 'REMISIONADO / FACTURADO', 'PENDIENTES', 'EXCEDENTE', '% CUMPLIMIENTO'], null, 'A' . $fila);
$hoja->getStyle('A' . $fila . ':G' . $fila)->applyFromArray($estiloEncabezado);
$fila++;

$inicioDatos = $fila;
$tot = ['pedidos_con_pendiente' => 0, 'solicitada' => 0.0, 'remisionada' => 0.0, 'pendiente' => 0.0, 'excedente' => 0.0];
$totDespachada = 0.0; // para el % global (despachado válido, no se muestra)
foreach ($clientes as $c) {
    $hoja->fromArray([
        $c['cliente'] . ($c['sin_cliente_wo'] ? ' (colegio, sin cliente WO)' : ''),
        $c['pedidos_con_pendiente'], $c['solicitada'], $c['remisionada'], $c['pendiente'], $c['excedente'],
        $c['cumplimiento'] === null ? 'N/A' : $c['cumplimiento'] / 100,
    ], null, 'A' . $fila, true); // true: sin esto fromArray deja vacíos los 0
    $hoja->getStyle('G' . $fila)->applyFromArray(estilo_cumplimiento($c['cumplimiento']));
    foreach ($tot as $k => $v) $tot[$k] += $c[$k];
    $totDespachada += $c['despachada'];
    $fila++;
}

if (empty($clientes)) {
    $hoja->setCellValue('A' . $fila, 'No hay clientes para mostrar.');
    $fila++;
} else {
    // Totales consolidados: % global = despachadas ÷ solicitadas de todos los clientes listados.
    $pctGlobal = $tot['solicitada'] > 0 ? round($totDespachada / $tot['solicitada'] * 100, 1) : null;
    $hoja->fromArray(array_merge(['TOTAL'], array_values($tot), [$pctGlobal === null ? 'N/A' : $pctGlobal / 100]), null, 'A' . $fila, true);
    $hoja->getStyle('A' . $fila . ':F' . $fila)->applyFromArray($estiloTotal);
    $estiloCumpTotal = estilo_cumplimiento($pctGlobal);
    $estiloCumpTotal['font']['bold'] = true;
    $hoja->getStyle('G' . $fila)->applyFromArray($estiloCumpTotal);
}

$hoja->getStyle('B' . $inicioDatos . ':F' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('G' . $inicioDatos . ':G' . $fila)->getNumberFormat()->setFormatCode('0.0%');
$hoja->getStyle('B' . $filaInicioTabla . ':G' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle('A' . $filaInicioTabla . ':A' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->freezePane('A' . $inicioDatos);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="cumplimiento_por_cliente_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
