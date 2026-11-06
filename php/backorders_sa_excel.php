<?php
/**
 * Excel del informe de "Backorders sin adopción" (mismos filtros que backorders_sa_informe.php):
 * hoja 1 los backorders con totales, hoja 2 cada atención, hoja 3 el
 * historial completo. Formato estándar de informes (includes/excel_formato.php).
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

if (!bsa_tiene_acceso()) { header('Location: ../index.php'); exit; }
// Un aviso impreso en la salida corrompe el .xlsx.
ini_set('display_errors', 0);

include("../lib/autoload-phpspreadsheet.php");
require_once("../lib/ZipStream/src/Option/Archive.php");
require_once("../lib/MyCLabs/Enum/Enum.php");
require_once("../lib/ZipStream/src/Option/Method.php");
require_once("../lib/ZipStream/src/ZipStream.php");
require_once("../lib/ZipStream/src/Bigint.php");
require_once("../lib/ZipStream/src/Option/File.php");
require_once("../lib/ZipStream/src/File.php");
require_once("../lib/ZipStream/src/Option/Version.php");
require_once("../includes/excel_formato.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$filtros = bsa_filtros_desde_request($_GET);
$datos = bsa_listar($bdd, $filtros);
$filas = $datos['filas'];
$tot = $datos['totales'];

$rango = null;
if ($filtros['desde'] !== '' || $filtros['hasta'] !== '') {
    $rango = 'Rango: ' . ($filtros['desde'] ?: '...') . ' - ' . ($filtros['hasta'] ?: '...');
}
$otrosFiltros = [];
if ($filtros['libro'] !== '') $otrosFiltros[] = 'Libro: ' . $filtros['libro'];
if ($filtros['tercero'] !== '') $otrosFiltros[] = 'Pedido / Cliente: ' . $filtros['tercero'];
if ($filtros['estado'] !== '') $otrosFiltros[] = 'Estado: ' . BSA_ESTADOS[$filtros['estado']];

$libro = new Spreadsheet();
$libro->getProperties()->setCreator('Inkpulse CRM')->setTitle('Backorders sin adopción');

// ── Hoja 1: backorders ─────────────────────────────────────────────────────
$h = $libro->getActiveSheet();
$h->setTitle('Backorders');
excel_encabezado($h, $bdd, 'Backorders sin adopción', $rango);
if ($otrosFiltros) $h->setCellValue('D4', implode('   ·   ', $otrosFiltros));

$cols = ['#', 'Fecha de registro', 'Libro', 'ISBN', 'Pedido / Cliente', 'Identificación', 'Solicitada', 'Atendida', 'Pendiente',
         'Estado', 'Registrado por', 'Observaciones', 'Fecha anulación', 'Anulado por', 'Motivo anulación'];
foreach ($cols as $i => $c) $h->setCellValueByColumnAndRow($i + 1, EXCEL_FILA_ENCABEZADOS, $c);
excel_estilo_encabezados($h, 'A' . EXCEL_FILA_ENCABEZADOS . ':O' . EXCEL_FILA_ENCABEZADOS);
$h->getStyle('A' . EXCEL_FILA_ENCABEZADOS . ':O' . EXCEL_FILA_ENCABEZADOS)->getFont()->setBold(true);

$r = EXCEL_FILA_ENCABEZADOS + 1;
foreach ($filas as $b) {
    $h->setCellValue("A$r", $b['id']);
    $h->setCellValue("B$r", $b['fecha_registro']);
    $h->setCellValue("C$r", $b['libro']);
    $h->setCellValueExplicit("D$r", $b['isbn'], DataType::TYPE_STRING); // si no, Excel lo muestra en notación científica
    // Pedido sin adopción + cliente (los registrados antes de 2026-10-20 no tienen pedido).
    $h->setCellValue("E$r", ($b['id_pedido2'] ? 'Pedido #' . $b['id_pedido2'] . ' — ' : '') . $b['tercero']);
    $h->setCellValueExplicit("F$r", $b['tercero_identificacion'], DataType::TYPE_STRING);
    $h->setCellValue("G$r", $b['cantidad_solicitada']);
    $h->setCellValue("H$r", $b['cantidad_atendida']);
    $h->setCellValue("I$r", $b['cantidad_pendiente']);
    $h->setCellValue("J$r", $b['estado_label']);
    $h->setCellValue("K$r", $b['usuario_registro']);
    $h->setCellValue("L$r", $b['observaciones']);
    $h->setCellValue("M$r", $b['fecha_anulacion'] ?? '');
    $h->setCellValue("N$r", $b['usuario_anulacion']);
    $h->setCellValue("O$r", $b['motivo_anulacion']);
    $r++;
}
if ($filas) {
    $h->setCellValue("F$r", 'TOTAL');
    $h->setCellValue("G$r", $tot['solicitada']);
    $h->setCellValue("H$r", $tot['atendida']);
    $h->setCellValue("I$r", $tot['pendiente']);
    $h->getStyle("A$r:O$r")->getFont()->setBold(true);
} else {
    $h->setCellValue("A$r", 'No hay backorders con los filtros seleccionados.');
}
foreach (range('A', 'O') as $c) $h->getColumnDimension($c)->setAutoSize(true);
$h->getColumnDimension('L')->setAutoSize(false)->setWidth(40);
$h->getColumnDimension('O')->setAutoSize(false)->setWidth(40);

// ── Hoja 2: atenciones ─────────────────────────────────────────────────────
$h2 = $libro->createSheet();
$h2->setTitle('Atenciones');
excel_encabezado($h2, $bdd, 'Backorders sin adopción — atenciones', $rango);
// Las dos columnas de pedido solo aplican a atenciones registradas cuando atender generaba un pedido.
$cols2 = ['Backorder #', 'Libro', 'Pedido / Cliente', 'Fecha de atención', 'Cantidad atendida', 'Pendiente antes',
          'Pedido (atenciones antiguas)', 'Estado del pedido', 'Atendido por', 'Observaciones'];
foreach ($cols2 as $i => $c) $h2->setCellValueByColumnAndRow($i + 1, EXCEL_FILA_ENCABEZADOS, $c);
excel_estilo_encabezados($h2, 'A' . EXCEL_FILA_ENCABEZADOS . ':J' . EXCEL_FILA_ENCABEZADOS);
$h2->getStyle('A' . EXCEL_FILA_ENCABEZADOS . ':J' . EXCEL_FILA_ENCABEZADOS)->getFont()->setBold(true);
$r = EXCEL_FILA_ENCABEZADOS + 1;
foreach ($filas as $b) {
    foreach ($b['atenciones'] as $a) {
        $h2->setCellValue("A$r", $b['id']);
        $h2->setCellValue("B$r", $b['libro']);
        $h2->setCellValue("C$r", ($b['id_pedido2'] ? 'Pedido #' . $b['id_pedido2'] . ' — ' : '') . $b['tercero']);
        $h2->setCellValue("D$r", $a['fecha']);
        $h2->setCellValue("E$r", $a['cantidad']);
        $h2->setCellValue("F$r", $a['pendiente_antes']);
        $h2->setCellValue("G$r", $a['id_pedido2'] ?? '');
        $h2->setCellValue("H$r", $a['id_pedido2'] ? $a['estado_pedido'] . ($a['pedido_anulado'] ? ' (no cuenta como atendido)' : '') : '');
        $h2->setCellValue("I$r", $a['usuario']);
        $h2->setCellValue("J$r", $a['observaciones']);
        $r++;
    }
}
if ($r === EXCEL_FILA_ENCABEZADOS + 1) $h2->setCellValue("A$r", 'Sin atenciones registradas.');
foreach (range('A', 'J') as $c) $h2->getColumnDimension($c)->setAutoSize(true);

// ── Hoja 3: historial ──────────────────────────────────────────────────────
$h3 = $libro->createSheet();
$h3->setTitle('Historial');
excel_encabezado($h3, $bdd, 'Backorders sin adopción — historial', $rango);
foreach (['Backorder #', 'Fecha', 'Acción', 'Detalle', 'Usuario'] as $i => $c) $h3->setCellValueByColumnAndRow($i + 1, EXCEL_FILA_ENCABEZADOS, $c);
excel_estilo_encabezados($h3, 'A' . EXCEL_FILA_ENCABEZADOS . ':E' . EXCEL_FILA_ENCABEZADOS);
$h3->getStyle('A' . EXCEL_FILA_ENCABEZADOS . ':E' . EXCEL_FILA_ENCABEZADOS)->getFont()->setBold(true);
$acciones = ['creado' => 'Registrado', 'atencion' => 'Atención', 'anulado' => 'Anulado'];
$r = EXCEL_FILA_ENCABEZADOS + 1;
foreach ($filas as $b) {
    foreach ($b['historial'] as $ev) {
        $h3->setCellValue("A$r", $b['id']);
        $h3->setCellValue("B$r", $ev['fecha']);
        $h3->setCellValue("C$r", $acciones[$ev['accion']] ?? $ev['accion']);
        $h3->setCellValue("D$r", $ev['detalle']);
        $h3->setCellValue("E$r", $ev['usuario']);
        $r++;
    }
}
foreach (['A', 'B', 'C', 'E'] as $c) $h3->getColumnDimension($c)->setAutoSize(true);
$h3->getColumnDimension('D')->setWidth(90);

$libro->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="backorders_sin_adopcion_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($libro))->save('php://output');
