<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

require_once("../php/aut.php");
include("../conexion/bdd.php");
require_once(__DIR__ . "/../includes/paquetes_colegio.php");

if (!puede_usar_tipo_adopcion()) {
	header("Location: ../index.php");
	exit;
}

$id_periodo = intval($_POST['periodo'] ?? 0);
$id_usuario = intval($_POST['usuario'] ?? -1);

if ($id_periodo <= 0 || $id_usuario < 0) {
	header("Location: ../reporte_paquetes.php");
	exit;
}

$req_periodo = $bdd->prepare("SELECT periodo FROM periodos WHERE id=?");
$req_periodo->execute([$id_periodo]);
$periodo_label = $req_periodo->fetchColumn() ?: $id_periodo;

$usuario_label = 'Todos';
if ($id_usuario > 0) {
	$req_usr = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) FROM usuarios WHERE id=?");
	$req_usr->execute([$id_usuario]);
	$usuario_label = $req_usr->fetchColumn() ?: $id_usuario;
}

$datos = obtener_datos_reporte_paquetes($bdd, $id_periodo, $id_usuario);

$estilo_centrar = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
$estilo_negrita = ['font' => ['bold' => true]];
$estilo_fuente  = ['font' => ['size' => 8.5]];
$estilo_borde   = [
	'borders' => [
		'top'    => ['style' => Border::BORDER_THIN],
		'right'  => ['style' => Border::BORDER_THIN],
		'bottom' => ['style' => Border::BORDER_THIN],
		'left'   => ['style' => Border::BORDER_THIN],
	],
];

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Reporte Paquetes");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("Reporte Paquetes");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

$hoja->mergeCells('A1:K1');
$hoja->getStyle('A1')->applyFromArray($estilo_negrita);
$hoja->getStyle('A1')->applyFromArray($estilo_centrar);
$hoja->SetCellValue('A1', 'REPORTE PAQUETES');

$hoja->getStyle('A2')->applyFromArray($estilo_negrita);
$hoja->SetCellValue('A2', 'Usuario:');
$hoja->SetCellValue('B2', $usuario_label);
$hoja->getStyle('D2')->applyFromArray($estilo_negrita);
$hoja->SetCellValue('D2', 'Periodo:');
$hoja->SetCellValue('E2', $periodo_label);

$encabezados = [
	'Zona', 'Responsable', 'DANE', 'Colegio', 'Grado', 'Código del paquete',
	'Precio (sumatoria)', 'Precio redondeado', 'Precio del paquete', 'ISBN', 'Título',
];
$col = 'A';
foreach ($encabezados as $enc) {
	$hoja->SetCellValue($col . '4', $enc);
	$hoja->getStyle($col . '4')->applyFromArray($estilo_negrita);
	$hoja->getStyle($col . '4')->applyFromArray($estilo_centrar);
	$hoja->getStyle($col . '4')->applyFromArray($estilo_borde);
	$col++;
}

$fila = 5;
foreach ($datos as $d) {
	$hoja->SetCellValue('A' . $fila, $d['zona']);
	$hoja->SetCellValue('B' . $fila, $d['responsable']);
	// DANE y código de paquete son enteros largos (12 y 18 dígitos): se fuerzan como
	// texto explícito para que Excel nunca los reinterprete como número y pierda
	// dígitos por precisión de punto flotante (ver memory del proyecto).
	$hoja->setCellValueExplicit('C' . $fila, $d['dane'], DataType::TYPE_STRING);
	$hoja->getStyle('C' . $fila)->getNumberFormat()->setFormatCode('@');
	$hoja->SetCellValue('D' . $fila, $d['colegio']);
	$hoja->SetCellValue('E' . $fila, $d['grado']);
	$hoja->setCellValueExplicit('F' . $fila, $d['codigo_paquete'], DataType::TYPE_STRING);
	$hoja->getStyle('F' . $fila)->getNumberFormat()->setFormatCode('@');
	$hoja->SetCellValue('G' . $fila, $d['precio_neto_sumado']);
	$hoja->SetCellValue('H' . $fila, $d['precio_redondeado'] !== null ? $d['precio_redondeado'] : '');
	$hoja->SetCellValue('I' . $fila, $d['precio_final']);
	$hoja->setCellValueExplicit('J' . $fila, $d['isbn'], DataType::TYPE_STRING);
	$hoja->SetCellValue('K' . $fila, $d['libro']);

	foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $c) {
		$hoja->getStyle($c . $fila)->applyFromArray($estilo_borde);
	}

	$fila++;
}

// Mismo formato de moneda (separador de miles + símbolo $) que el resto del módulo.
if ($fila > 5) {
	$hoja->getStyle('G5:I' . ($fila - 1))
		->getNumberFormat()
		->setFormatCode('_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)');
}

$hoja->getStyle('A1:K' . max($fila - 1, 4))->applyFromArray($estilo_fuente);
foreach (range('A', 'K') as $columnID) {
	$hoja->getColumnDimension($columnID)->setAutoSize(true);
}

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Reporte_Paquetes_' . $periodo_label . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>
