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
$objSpreadsheet->getProperties()->setTitle("Devoluciones");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Devoluciones");
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


	$fecha=date("Y-m-d");
	//~ Ingreo de datos en la hojda de excel

	

	/*$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Distribuidor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");*/
	$objSpreadsheet->getActiveSheet()->SetCellValue("D1", "Fecha");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D2", "$fecha");
	$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "# Devolución");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Usuario");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Estado");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Colegio");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Isbn");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Libro");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "PVP");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Descuento");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Precio Facturación");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Cantidad");
	$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Valor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Observaciones");


	$objSpreadsheet->getActiveSheet()->getStyle('A4:M4')->applyFromArray([
	    'fill' => [
	        'fillType' => Fill::FILL_SOLID,
	        'startColor' => [
	            'rgb' => '00FF84'
	        ]
	    ]
	]);;

	$desde=$_POST['desde']." 00:00:00";
    $hasta=$_POST['hasta']." 23:59:59";

  

    $sql = "SELECT pe.id, pe.fecha, c.colegio,pe.estado,pe.observaciones, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn, m.materia, lp.cantidad, p.cod_area, p.descuento_d,lp.id as lpid, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM 	devoluciones_v pe JOIN libros_devol_v lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id LEFT JOIN presupuestos p ON p.id_colegio=pe.id_colegio AND p.id_libro=lp.id_libro AND pe.id_periodo=p.id_periodo LEFT JOIN colegios c ON c.id=p.id_colegio JOIN usuarios u ON pe.id_usuario=u.id WHERE pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id;";

    

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros= $req->fetchAll();

	$estados_map = [];
	foreach ($bdd->query("SELECT id, estado FROM estados_pedidos")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $estados_map[$row['id']] = $row['estado'];

	$conta=5;
	foreach($libros as $libro) {

		$descuento=$libro["descuento_d"] * 100;

	    $precio_fact=$libro["precio"] -($libro["precio"] * $libro["descuento_d"]);
	    $libro["descuento_aprob"]=$descuento;



	    $v_venta=$precio_fact * $libro["cantidad"];
	    $libro["cantidad_aprob"]=$libro["cantidad"];


        $total_venta[]=$v_venta;
        $total_cantidad[]=$libro["cantidad"];

		$estado = ['estado' => $estados_map[$libro["estado"]] ?? ''];

		
		$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[promotor]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$libro[fecha]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$estado[estado]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$libro[colegio]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$libro[isbn]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$libro[libro]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$libro[precio]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$descuento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$precio_fact");
		$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$libro[cantidad]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$v_venta");
		$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$libro[observaciones]");


		$conta++;
	}

	$total_v=array_sum($total_venta);
    $total_c=array_sum($total_cantidad);

    $objSpreadsheet->getActiveSheet()->getStyle('I'.$conta.':L'.$conta)->applyFromArray($estilo_negrita);

    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "Total");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$total_c");
	$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$total_v");

	$objSpreadsheet->getActiveSheet()->getStyle("J5:J$conta")
        ->getNumberFormat()
        ->setFormatCode(
        '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
    );

    $objSpreadsheet->getActiveSheet()->getStyle("H5:H$conta")
        ->getNumberFormat()
        ->setFormatCode(
        '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
    );

    $objSpreadsheet->getActiveSheet()->getStyle("L5:L$conta")
        ->getNumberFormat()
        ->setFormatCode(
        '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
    );
       



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="devoluciones_libro_libro.xlsx"');

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>