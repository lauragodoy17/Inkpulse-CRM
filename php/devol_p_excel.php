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
$objSpreadsheet->getProperties()->setTitle("Devoluciones de muestras");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Devoluciones de muestras");
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


	
	$objSpreadsheet->getActiveSheet()->SetCellValue("E1", "Reporte devoluciones de proveedores");
	$objSpreadsheet->getActiveSheet()->getStyle('E1')->applyFromArray($estilo_negrita);
	$objSpreadsheet->getActiveSheet()->getStyle('E1')->applyFromArray($estilo_centrar);

	$fecha=date("Y-m-d");
	//~ Ingreo de datos en la hojda de excel

	//if ($_POST["usuario"]==0) {

		/*$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Ascesor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");*/
		$objSpreadsheet->getActiveSheet()->SetCellValue("F1", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F2", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "# Devolución");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Usuario");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Estado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Proveedor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Cantidad de Libros");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Observaciones");


	//}
	/*else{

		$sql = "SELECT CONCAT(nombres, ' ', apellidos) as nombre_c FROM usuarios WHERE id='".$_POST["usuario"]."'";
		$req = $bdd->prepare($sql);
		$req->execute();
		$usuario = $req->fetch();

		$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Ascesor");
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
	}*/

	

	$objSpreadsheet->getActiveSheet()->getStyle('A4:G4')->applyFromArray([
	    'fill' => [
	        'fillType' => Fill::FILL_SOLID,
	        'startColor' => [
	            'rgb' => '00FF84'
	        ]
	    ]
	]);  

	$desde=$_POST['desde']." 00:00:00";
    $hasta=$_POST['hasta']." 23:59:59";

    //if ($_POST["usuario"]==0) {

    	$sql="SELECT d.id, d.fecha, d.observaciones,d.codigo, c.proveedor, e.estado, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM devoluciones_prov d JOIN proveedores c ON c.id=d.persona JOIN estados_pedidos e ON d.estado=e.id JOIN usuarios u ON d.id_usuario=u.id WHERE d.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY d.id;";


    //}
    /*else{
    	$sql = "SELECT pe.id, pe.fecha, pe.colegio, pe.estado, l.id as libroid, l.id_grado, l.libro, l.precio, m.materia, l.isbn, lp.cantidad, lp.cantidad_aprob, lp.descuento_aprob, lp.descuento, lp.id as lpid FROM pedidos2 pe JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id WHERE pe.id_usuario='".$_POST["usuario"]."' AND pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id";

    }*/

	

	$req = $bdd->prepare($sql);
	$req->execute();
	$devoluciones= $req->fetchAll();

	$devol_codes_p = array_column($devoluciones, 'codigo');
	$cant_map_dvp = [];
	if (!empty($devol_codes_p)) {
	    $ph_dp = implode(',', array_fill(0, count($devol_codes_p), '?'));
	    $req_cant_dvp = $bdd->prepare("SELECT cod_pedido, SUM(cantidad) as cant FROM libros_devol WHERE cod_pedido IN ($ph_dp) GROUP BY cod_pedido");
	    $req_cant_dvp->execute($devol_codes_p);
	    foreach ($req_cant_dvp->fetchAll(PDO::FETCH_ASSOC) as $row)
	        $cant_map_dvp[$row['cod_pedido']] = $row['cant'];
	}

	$conta=5;
	foreach($devoluciones as $devolucion) {

		$cantidades = ['cant' => $cant_map_dvp[$devolucion["codigo"]] ?? 0];

		//if ($_POST["usuario"]==0) {

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$devolucion[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$devolucion[promotor]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$devolucion[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$devolucion[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$devolucion[proveedor]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cantidades[cant]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$devolucion[observaciones]");


		//}

		/*else{

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

		}*/
		
		


		$conta++;
	}

	/*$total_v=array_sum($total_venta);
    $total_c=array_sum($total_cantidad);
    $total_c_aprob=array_sum($total_cantidad_aprob);*/

    //$objSpreadsheet->getActiveSheet()->getStyle('I'.$conta.':N'.$conta)->applyFromArray($estilo_negrita);

    //if ($_POST["usuario"]==0) {
    	/*$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "Total");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$total_c");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$total_c_aprob");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$total_v");*/

    //}

    /*else{
    	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "Total");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$total_c");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$total_c_aprob");
	    $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$total_v");
    }*/

    



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="devoluciones_proveedores.xlsx"');

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>