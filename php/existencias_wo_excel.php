<?php
/**
 * /php/existencias_wo_excel.php
 * Excel del informe de existencias de World Office (reporte_existencias_wo.php). Recibe las filas
 * ya consultadas y filtradas en la pantalla (POST filas = JSON), para no volver a pedirle a World
 * Office las existencias de miles de títulos.
 */
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;

require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");

if (!in_array((int)($_SESSION['tipo'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('No autorizado');
}

$filas = json_decode((string)($_POST['filas'] ?? ''), true);
if (!is_array($filas)) {
    http_response_code(400);
    exit('Primero consulta World Office.');
}
$filtro = trim((string)($_POST['filtro'] ?? ''));

$libro = new Spreadsheet();
$libro->getProperties()->setCreator("Inkpulse")->setTitle('Existencias World Office');
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Existencias WO');
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

excel_encabezado($hoja, $bdd, 'Existencias World Office — Bodega General y Muestras General', $filtro !== '' ? 'Filtro: ' . $filtro : null, 'B');

$encabezados = ['Código', 'Título', 'Editorial', 'Tipo', 'Estado', 'Bodega General', 'Muestras General', 'Total'];
foreach ($encabezados as $i => $t) $hoja->setCellValueByColumnAndRow($i + 1, 6, $t);
excel_estilo_encabezados($hoja, 'A6:H6');

$fila = 7;
foreach ($filas as $f) {
    $hoja->setCellValueExplicit("A$fila", (string)($f['codigo'] ?? ''), DataType::TYPE_STRING); // ISBN: sin notación científica
    $hoja->setCellValue("B$fila", (string)($f['titulo'] ?? ''));
    $hoja->setCellValue("C$fila", (string)($f['editorial'] ?? ''));
    $hoja->setCellValue("D$fila", !empty($f['paquete']) ? 'Paquete' : 'Título');
    $hoja->setCellValue("E$fila", !empty($f['activo']) ? 'Activo' : 'Inactivo');
    if (($f['general'] ?? null) === null) {
        $hoja->setCellValue("F$fila", 'Sin respuesta');
        $hoja->setCellValue("G$fila", 'Sin respuesta');
    } else {
        $hoja->setCellValue("F$fila", (float)$f['general']);
        $hoja->setCellValue("G$fila", (float)$f['muestras']);
        $hoja->setCellValue("H$fila", "=F$fila+G$fila");
    }
    $fila++;
}
if (!$filas) {
    $hoja->setCellValue('A7', 'Sin títulos con este filtro.');
} else {
    $ultima = $fila - 1;
    $hoja->setCellValue("E$fila", 'TOTAL');
    foreach (['F', 'G', 'H'] as $c) $hoja->setCellValue("$c$fila", "=SUM({$c}7:{$c}{$ultima})");
    $hoja->getStyle("A$fila:H$fila")->getFont()->setBold(true);
    $hoja->getStyle("F7:H$fila")->getNumberFormat()->setFormatCode('#,##0');
    $hoja->setAutoFilter("A6:H$ultima");
    $hoja->freezePane('A7');
}
foreach (['A' => 18, 'B' => 55, 'C' => 38, 'D' => 10, 'E' => 10, 'F' => 15, 'G' => 17, 'H' => 10] as $c => $w) {
    $hoja->getColumnDimension($c)->setWidth($w);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Existencias_World_Office_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($libro))->save('php://output');
