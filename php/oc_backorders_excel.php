<?php
/**
 * /php/oc_backorders_excel.php?estado=&orden=&proveedor=&producto=
 * Excel de los backorders de órdenes de compra (tablas oc_*), con los mismos filtros de
 * oc_backorders.php. Hoja 1: un backorder por fila con totales; hoja 2: cada recepción de esos
 * productos (historial). No consulta World Office.
 */
require_once("aut.php");

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}
// Un notice en la salida corrompe el .xlsx.
ini_set('display_errors', 0);
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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_once("../conexion/bdd.php");
require_once("../includes/ordenes_compra_datos.php");
oc_crear_tablas($bdd);

// ── Filtros (mismo criterio que la vista) ──────────────────────────
$fEstado = (string)($_GET['estado'] ?? 'abiertos');
$fOrden = mb_strtolower(trim((string)($_GET['orden'] ?? '')));
$fProveedor = mb_strtolower(trim((string)($_GET['proveedor'] ?? '')));
$fProducto = mb_strtolower(trim((string)($_GET['producto'] ?? '')));
$contiene = fn($texto, $q) => $q === '' || mb_strpos(mb_strtolower((string)$texto), $q) !== false;

$backorders = array_values(array_filter(oc_listar_backorders($bdd), function ($b) use ($fEstado, $fOrden, $fProveedor, $fProducto, $contiene) {
    if ($fEstado === 'abiertos' && !in_array($b['estado'], ['pendiente', 'parcial'], true)) return false;
    if ($fEstado !== '' && $fEstado !== 'abiertos' && $b['estado'] !== $fEstado) return false;
    return $contiene($b['orden'], $fOrden) && $contiene($b['proveedor'], $fProveedor)
        && $contiene($b['producto'] . ' ' . $b['codigo'] . ' ' . $b['isbn'], $fProducto);
}));

$nombresEstado = ['abiertos' => 'Abiertos (pendiente y parcial)', '' => 'Todos'] + OC_ESTADOS_PRODUCTO;
$filtrosTxt = ['Estado: ' . ($nombresEstado[$fEstado] ?? $fEstado)];
if ($fOrden !== '') $filtrosTxt[] = 'Orden: ' . $_GET['orden'];
if ($fProveedor !== '') $filtrosTxt[] = 'Proveedor: ' . $_GET['proveedor'];
if ($fProducto !== '') $filtrosTxt[] = 'Producto: ' . $_GET['producto'];

function oc_fecha_excel($f, $conHora = false) {
    if (!$f) return '';
    $t = strtotime($f);
    return $t ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $t) : $f;
}

$estiloEncabezado = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
];
$coloresEstado = ['pendiente' => 'FEF3C7', 'parcial' => 'DBEAFE', 'completado' => 'DCFCE7', 'excedente' => 'FEE2E2'];

$libro = new Spreadsheet();
$libro->getProperties()->setCreator("Inkpulse CRM")->setTitle("Backorders de órdenes de compra");

// ── Hoja 1: backorders ─────────────────────────────────────────────
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Backorders');
$hoja->setCellValue('A1', 'Backorders de órdenes de compra');
$hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$hoja->setCellValue('A2', 'Generado: ' . date('d/m/Y H:i') . '   ·   ' . implode('   ·   ', $filtrosTxt));
$hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

// Una sola columna de código: el de World Office (en los libros es el mismo ISBN).
$columnas = ['Orden de compra', 'Fecha orden', 'Proveedor', 'Código', 'Producto', 'Unidad',
             'Cantidad solicitada', 'Cantidad recibida', 'Cantidad pendiente', 'Estado',
             'Fecha de generación', 'Última actualización', 'Generado por'];
$filaEnc = 4;
foreach ($columnas as $i => $c) $hoja->setCellValueByColumnAndRow($i + 1, $filaEnc, $c);
$hoja->getStyle("A$filaEnc:M$filaEnc")->applyFromArray($estiloEncabezado);
$hoja->getRowDimension($filaEnc)->setRowHeight(30);

$fila = $filaEnc + 1;
if (!$backorders) {
    $hoja->setCellValue("A$fila", 'No hay backorders con los filtros seleccionados.');
    $hoja->mergeCells("A$fila:M$fila");
    $fila++;
}
foreach ($backorders as $b) {
    $hoja->setCellValueExplicit("A$fila", $b['orden'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $hoja->setCellValue("B$fila", oc_fecha_excel($b['fecha_orden']));
    $hoja->setCellValue("C$fila", $b['proveedor']);
    // Como texto: si no, Excel muestra el ISBN en notación científica.
    $hoja->setCellValueExplicit("D$fila", $b['codigo'] !== '' ? $b['codigo'] : $b['isbn'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $hoja->setCellValue("E$fila", $b['producto']);
    $hoja->setCellValue("F$fila", $b['unidad']);
    $hoja->setCellValue("G$fila", $b['solicitada']);
    $hoja->setCellValue("H$fila", $b['recibida']);
    $hoja->setCellValue("I$fila", $b['pendiente']);
    $hoja->setCellValue("J$fila", $b['estado_label']);
    $hoja->setCellValue("K$fila", oc_fecha_excel($b['fecha_generacion'], true));
    $hoja->setCellValue("L$fila", oc_fecha_excel($b['fecha_actualizacion'], true));
    $hoja->setCellValue("M$fila", $b['usuario_genero']);
    $hoja->getStyle("J$fila")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($coloresEstado[$b['estado']] ?? 'FFFFFF');
    $fila++;
}
if ($backorders) {
    $ultima = $fila - 1;
    $hoja->setCellValue("F$fila", 'TOTAL');
    foreach (['G', 'H', 'I'] as $col) $hoja->setCellValue("$col$fila", "=SUM({$col}" . ($filaEnc + 1) . ":{$col}{$ultima})");
    $hoja->getStyle("A$fila:M$fila")->getFont()->setBold(true);
    $hoja->getStyle("A$fila:M$fila")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
    $hoja->getStyle("G" . ($filaEnc + 1) . ":I$fila")->getNumberFormat()->setFormatCode('#,##0.##');
    $hoja->setAutoFilter("A$filaEnc:M$ultima");
}
foreach (range('A', 'M') as $col) $hoja->getColumnDimension($col)->setAutoSize(true);
$hoja->getColumnDimension('E')->setAutoSize(false)->setWidth(45);
$hoja->freezePane('A' . ($filaEnc + 1));

// ── Hoja 2: historial de recepciones de esos productos ─────────────
$hist = $libro->createSheet();
$hist->setTitle('Historial de recepciones');
$colsHist = ['Orden de compra', 'Proveedor', 'Código', 'Producto', 'Fecha de recepción', 'Registrada por',
             'Recibida en la entrega', 'Acumulado', 'Pendiente después', 'Observaciones'];
foreach ($colsHist as $i => $c) $hist->setCellValueByColumnAndRow($i + 1, 1, $c);
$hist->getStyle('A1:J1')->applyFromArray($estiloEncabezado);
$hist->getRowDimension(1)->setRowHeight(30);
$f = 2;
foreach ($backorders as $b) {
    $h = oc_historial_backorder($bdd, $b['id']);
    foreach (($h['ok'] ? $h['movimientos'] : []) as $m) {
        $hist->setCellValueExplicit("A$f", $b['orden'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hist->setCellValue("B$f", $b['proveedor']);
        $hist->setCellValueExplicit("C$f", $b['codigo'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hist->setCellValue("D$f", $b['producto']);
        $hist->setCellValue("E$f", oc_fecha_excel($m['fecha'], true));
        $hist->setCellValue("F$f", $m['usuario']);
        $hist->setCellValue("G$f", $m['cantidad']);
        $hist->setCellValue("H$f", $m['acumulado']);
        $hist->setCellValue("I$f", $m['pendiente']);
        $hist->setCellValue("J$f", $m['observaciones']);
        $f++;
    }
}
if ($f === 2) $hist->setCellValue('A2', 'Sin recepciones para los backorders seleccionados.');
else {
    $hist->getStyle("G2:I" . ($f - 1))->getNumberFormat()->setFormatCode('#,##0.##');
    $hist->setAutoFilter('A1:J' . ($f - 1));
}
foreach (range('A', 'J') as $col) $hist->getColumnDimension($col)->setAutoSize(true);
$hist->getColumnDimension('D')->setAutoSize(false)->setWidth(45);
$hist->freezePane('A2');

$libro->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="backorders_ordenes_compra_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
(new Xlsx($libro))->save('php://output');
exit;
