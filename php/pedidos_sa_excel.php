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
$objSpreadsheet->getProperties()->setTitle("Pedidos sin adopción");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Pedidos sin adopción");
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

	if ($_POST["usuario"]==0) {

		/*$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Ascesor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");*/
		$objSpreadsheet->getActiveSheet()->SetCellValue("D1", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D2", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "# Pedido");
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
		$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Descuento Aprobado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Cantidad Aprobada");
		$objSpreadsheet->getActiveSheet()->SetCellValue("N4", "Valor Venta");

	}else{

		$sql = "SELECT CONCAT(nombres, ' ', apellidos) as nombre_c FROM usuarios WHERE id='".$_POST["usuario"]."'";
		$req = $bdd->prepare($sql);
		$req->execute();
		$usuario = $req->fetch();

		$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Asesor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D1", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D2", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "# Pedido");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Estado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Colegio");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Isbn");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Libro");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "PVP");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Descuento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Precio Facturación");
		$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Cantidad");
		$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Descuento Aprobado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Cantidad Aprobada");
		$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Valor Venta");
	}


	$objSpreadsheet->getActiveSheet()->getStyle('A4:N4')->applyFromArray([
	    'fill' => [
	        'fillType' => Fill::FILL_SOLID,
	        'startColor' => [
	            'rgb' => '00FF84'
	        ]
	    ]
	]);

	$desde=$_POST['desde']." 00:00:00";
    $hasta=$_POST['hasta']." 23:59:59";

    if ($_POST["usuario"]==0) {

    	$sql = "SELECT pe.id, pe.fecha, pe.colegio, pe.estado, l.id as libroid, l.id_grado, l.libro, l.precio, m.materia, l.isbn, lp.cantidad, lp.cantidad_aprob, lp.descuento_aprob, lp.descuento, lp.id as lpid, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM pedidos2 pe JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN usuarios u ON pe.id_usuario=u.id WHERE pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id";

    }else{
    	$sql = "SELECT pe.id, pe.fecha, pe.colegio, pe.estado, l.id as libroid, l.id_grado, l.libro, l.precio, m.materia, l.isbn, lp.cantidad, lp.cantidad_aprob, lp.descuento_aprob, lp.descuento, lp.id as lpid FROM pedidos2 pe JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id WHERE pe.id_usuario='".$_POST["usuario"]."' AND pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id";

    }

	

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros= $req->fetchAll();

	$conta=5;
	foreach($libros as $libro) {

		$descuento=$libro["descuento"];
      
        if ($libro["descuento_aprob"]!="") {
	        $precio_fact=$libro["precio"] -($libro["precio"] * ($libro["descuento_aprob"] / 100) );
	    }else {
	        $precio_fact=$libro["precio"] -($libro["precio"] * ($libro["descuento"] / 100) );
	        $libro["descuento_aprob"]=$descuento;
	    }

	 

        if ($libro["cantidad_aprob"]!="") {
            $v_venta=$precio_fact * $libro["cantidad_aprob"];
	    }else{
	        $v_venta=$precio_fact * $libro["cantidad"];
	        $libro["cantidad_aprob"]=$libro["cantidad"];

	    }

	    $total_venta[]=$v_venta;


        $total_cantidad[]=$libro["cantidad"];
        $total_cantidad_aprob[]=$libro["cantidad_aprob"];


        $sql = "SELECT estado FROM estados_pedidos WHERE id='".$libro["estado"]."'";
		$req = $bdd->prepare($sql);
		$req->execute();
		$estado = $req->fetch();

		if ($_POST["usuario"]==0) {

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
			$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$libro[descuento_aprob]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$libro[cantidad_aprob]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$v_venta");

		}else{

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$estado[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$libro[colegio]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$libro[isbn]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$libro[libro]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$libro[precio]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$descuento");
			$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$precio_fact");
			$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$libro[cantidad]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$libro[descuento_aprob]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$libro[cantidad_aprob]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$v_venta");

		}
		
		


		$conta++;
	}

	$total_v = array_sum($total_venta ?? []);
    $total_c=array_sum($total_cantidad ?? []);
    $total_c_aprob=array_sum($total_cantidad_aprob ?? []);

    $objSpreadsheet->getActiveSheet()->getStyle('I'.$conta.':N'.$conta)->applyFromArray($estilo_negrita);

    if ($_POST["usuario"]==0) {
    	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "Total");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$total_c");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$total_c_aprob");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$total_v");

    }else{
    	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "Total");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$total_c");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$total_c_aprob");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$total_v");
    }

    




foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
if ($_POST["usuario"]==0) {
header('Content-Disposition: attachment; filename="pedidos_general.xlsx"');
}else{
	header('Content-Disposition: attachment; filename="pedidos_'.$usuario["nombre_c"].'.xlsx"');
}

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');

?>