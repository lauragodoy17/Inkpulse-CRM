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
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../php/aut.php");
include("../conexion/bdd.php");

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("OP pendientes");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("OP pendientes");
$objSpreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToPage(true);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);

$estilo_centrar = [
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]
];

$estilo_negrita = array(
    'font' => array(
        'bold' => true
    )
);

$fecha_hoy=date("Y-m-d");

//poner imagen
$drawing = new Drawing();
$drawing->setName('test_img');
$drawing->setDescription('test_img');
$drawing->setPath('../vendors/images/logo_eureka.png'); // Ruta relativa o absoluta a la imagen
$drawing->setHeight(100); // Puedes ajustar el tamaño si deseas
$drawing->setCoordinates('A1'); // Posición en la hoja
$drawing->setWorksheet($objSpreadsheet->getActiveSheet());


$objSpreadsheet->getActiveSheet()->mergeCells('A1:B2');



$objSpreadsheet->getActiveSheet()->mergeCells('E1:F1');



$objSpreadsheet->getActiveSheet()->getStyle('E1')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E1')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->SetCellValue("E1", "OPs Pendientes");
$objSpreadsheet->getActiveSheet()->mergeCells('B3:D3');
$objSpreadsheet->getActiveSheet()->mergeCells('B4:D4');
$objSpreadsheet->getActiveSheet()->getStyle('B3')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('B3')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('B4')->applyFromArray($estilo_centrar);

$objSpreadsheet->getActiveSheet()->getStyle('E3')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E3')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('E4')->applyFromArray($estilo_centrar);

//~ Ingreo de datos en la hojda de excel
$objSpreadsheet->getActiveSheet()->SetCellValue("B3", "Estado");
$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Pendiente");
$objSpreadsheet->getActiveSheet()->SetCellValue("E3", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "$fecha_hoy");
$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "# OP");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("C6", "Usuario");
$objSpreadsheet->getActiveSheet()->SetCellValue("D6", "Tipo de documento");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "Cliente");
$objSpreadsheet->getActiveSheet()->SetCellValue("F6", "Contacto");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Ciudad destino");
$objSpreadsheet->getActiveSheet()->SetCellValue("H6", "Observaciones");



$objSpreadsheet->getActiveSheet()->getStyle('A6:H6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

	$sql = "SELECT o.id as opid, o.op_per,o.fecha, o.n_doc, o.solicitante, o.valor, o.guia, o.fecha_entrega, o.archivo, o.observaciones, o.estado, o.transportista, o.obs_envio, o.guia, o.adjunto_envio, o.ciudad_destino, o.año, t.tipo, c.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario, e.estado AS n_estado FROM ordenes_pedidos o JOIN tipo_doc t ON o.tipo_doc=t.id JOIN clientes c ON c.id=o.cliente JOIN usuarios u ON u.id=o.usuario JOIN estados_pedidos e ON e.id=o.estado WHERE o.estado='1' ORDER BY o.id DESC";


	$req = $bdd->prepare($sql);
	$req->execute();
	$ops = $req->fetchAll();

	$conta=7;

foreach($ops as $op) {
    $opid=$op["año"]." - ".$op["opid"];
	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$opid");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$op[fecha]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$op[usuario]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$op[tipo]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$op[cliente]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$op[solicitante]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$op[ciudad_destino]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op[observaciones]");
	


	$conta++;
}



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="OP_pendientes.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>