<?php
/**
 * /php/reporte_stock_excel.php
 * Exporta a Excel el "Reporte de stock" (reporte_stock.php): por cada libro
 * con pedidos pendientes/aprobados en el período — con adopción (origen
 * "pedidos") o sin adopción (origen "pedidos2") — cantidad pedida vs.
 * existencia real en bodega General de World Office. Datos calculados en
 * includes/reporte_stock_datos.php (mismo umbral que includes/stock_bajo.php).
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/reporte_stock_datos.php");

if (!in_array($_SESSION['tipo'] ?? null, [1, 2], true)) {
    http_response_code(403);
    exit('No autorizado');
}

$origen  = ($_POST['origen'] ?? '') === 'pedidos2' ? 'pedidos2' : 'pedidos';
$usuario = intval($_POST['usuario'] ?? 0);
$periodo = intval($_POST['periodo'] ?? 0);
// Detallado (por colegio, con Empresa/Zona/Asesor) solo existe para "pedidos"
// (con adopción) — a pedido explícito del usuario 2026-09-08, "sin adopción"
// siempre usa la variante generall.
$detallado = $origen === 'pedidos' && ($_POST['detalle'] ?? '0') === '1';

if (!$periodo) {
    http_response_code(400);
    exit('Selecciona un período');
}

$usuarioNombre = 'Todos';
if ($usuario > 0) {
    $req = $bdd->prepare("SELECT CONCAT(nombres, ' ', apellidos) as nombre FROM usuarios WHERE id = ?");
    $req->execute([$usuario]);
    $nombre = $req->fetchColumn();
    $usuarioNombre = $nombre !== false ? $nombre : 'Desconocido';
}

$req = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
$req->execute([$periodo]);
$periodoNombre = $req->fetchColumn();
$periodoNombre = $periodoNombre !== false ? $periodoNombre : '';

$filas = $detallado
    ? reporte_stock_pedidos_detallado($bdd, $usuario, $periodo)
    : reporte_stock_pedidos($bdd, $origen, $usuario, $periodo);

$tituloHoja = $origen === 'pedidos2' ? 'Stock - Sin adopción' : ($detallado ? 'Stock - Pedidos de venta detallado' : 'Stock - Pedidos de venta');
// El título de la pestaña de Excel tiene un límite de 31 caracteres (no aplica
// al título del documento ni al encabezado A1, que sí pueden ser largos).
$tituloPestana = $origen === 'pedidos2' ? 'Stock sin adopción' : ($detallado ? 'Stock pedidos venta detalle' : 'Stock pedidos venta');

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle($tituloHoja);
$objSpreadsheet->setActiveSheetIndex(0);
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle($tituloPestana);
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

$estiloNegrita = ['font' => ['bold' => true]];

$hoja->setCellValue('A1', 'Reporte de stock — ' . ($origen === 'pedidos2' ? 'Pedidos sin adopción' : 'Pedidos de venta') . ($detallado ? ' (detallado por colegio)' : ''));
$hoja->getStyle('A1')->applyFromArray($estiloNegrita);
$hoja->setCellValue('A2', 'Usuario: ' . $usuarioNombre);
$hoja->setCellValue('A3', 'Periodo: ' . $periodoNombre);
$hoja->setCellValue('A4', 'Fecha: ' . date('Y-m-d'));

if ($detallado) {
    $encabezados = ['Empresa', 'Zona', 'Asesor', 'Colegio', 'Libro', 'Cantidad pedida', 'Existencias', 'Stock bajo'];
} else {
    $encabezados = ['Libro', 'Cantidad pedida', 'Existencias', 'Stock bajo'];
}
$ultimaCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
foreach ($encabezados as $i => $titulo) {
    $hoja->setCellValueByColumnAndRow($i + 1, 6, $titulo);
}
$hoja->getStyle("A6:{$ultimaCol}6")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']],
]);

$fila = 7;
foreach ($filas as $f) {
    $col = 1;
    if ($detallado) {
        $hoja->setCellValueByColumnAndRow($col++, $fila, $f['empresa']);
        $hoja->setCellValueByColumnAndRow($col++, $fila, $f['zona']);
        $hoja->setCellValueByColumnAndRow($col++, $fila, $f['asesor']);
        $hoja->setCellValueByColumnAndRow($col++, $fila, $f['colegio']);
    }
    $hoja->setCellValueByColumnAndRow($col++, $fila, $f['libro']);
    $hoja->setCellValueExplicitByColumnAndRow($col++, $fila, $f['cantidad_pedida'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    if ($f['existencia'] === null) {
        $hoja->setCellValueByColumnAndRow($col++, $fila, '—');
    } else {
        $hoja->setCellValueExplicitByColumnAndRow($col++, $fila, $f['existencia'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    }
    $hoja->setCellValueByColumnAndRow($col++, $fila, $f['stock_bajo'] === null ? '—' : ($f['stock_bajo'] ? 'Sí' : 'No'));
    $fila++;
}

if (!$filas) {
    $hoja->setCellValue('A7', 'Sin resultados para este usuario/período.');
}

foreach (range('A', $ultimaCol) as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}

$nombreArchivo = $origen === 'pedidos2'
    ? 'Reporte_stock_sin_adopcion.xlsx'
    : ($detallado ? 'Reporte_stock_pedidos_venta_detallado.xlsx' : 'Reporte_stock_pedidos_venta.xlsx');

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
