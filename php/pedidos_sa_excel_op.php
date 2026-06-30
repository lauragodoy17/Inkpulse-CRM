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
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "OP");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Atendida");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Tipo de documento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Num. de documento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Observaciones");

	}else{

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
		$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "OP");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Atendida");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Tipo de documento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Num. de documento");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Observaciones");
	}

	

	

	$objSpreadsheet->getActiveSheet()->getStyle('A4:J4')->applyFromArray([
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

    	$sql = "SELECT pe.id, pe.fecha, pe.colegio, pe.estado, pe.observaciones, l.id as libroid, l.id_grado, l.libro, l.precio, m.materia, l.isbn, lp.cantidad, lp.cantidad_aprob, lp.descuento_aprob, lp.descuento, lp.id as lpid, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM pedidos2 pe JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN usuarios u ON pe.id_usuario=u.id WHERE pe.fecha BETWEEN '".$desde."' AND '".$hasta."' GROUP BY pe.id ORDER BY pe.id";

    }else{
    	$sql = "SELECT pe.id, pe.fecha, pe.colegio, pe.estado, pe.observaciones, l.id as libroid, l.id_grado, l.libro, l.precio, m.materia, l.isbn, lp.cantidad, lp.cantidad_aprob, lp.descuento_aprob, lp.descuento, lp.id as lpid FROM pedidos2 pe JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id WHERE pe.id_usuario='".$_POST["usuario"]."' AND pe.fecha BETWEEN '".$desde."' AND '".$hasta."' GROUP BY pe.id ORDER BY pe.id";

    }

	

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros= $req->fetchAll();

	$estados_map = [];
	foreach ($bdd->query("SELECT id, estado FROM estados_pedidos")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $estados_map[$row['id']] = $row['estado'];

	$dist_ids = array_column($libros, 'id');
	$op_map_sa = [];
	if (!empty($dist_ids)) {
	    $ph_did = implode(',', array_fill(0, count($dist_ids), '?'));
	    $req_op_sa = $bdd->prepare("SELECT o.id_pedido_dist, o.id, o.estado, o.n_doc, t.tipo FROM ordenes_pedidos o JOIN tipo_doc t ON t.id=o.tipo_doc WHERE o.id_pedido_dist IN ($ph_did)");
	    $req_op_sa->execute($dist_ids);
	    foreach ($req_op_sa->fetchAll(PDO::FETCH_ASSOC) as $row)
	        $op_map_sa[$row['id_pedido_dist']] = $row;
	}

	$conta=5;
	foreach($libros as $libro) {

		$estado = ['estado' => $estados_map[$libro["estado"]] ?? ''];
		$op     = $op_map_sa[$libro["id"]] ?? [];

		if ($_POST["usuario"]==0) {

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[promotor]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$estado[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$libro[colegio]");
			if (empty($op["id"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$op[id]");
			}
			

			if (!empty($op["id"])) {

				if ($op["estado"]==2) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "Si");
				}else{
					$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "No");
				}
			}

			if (!empty($op["id"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op[tipo]");
				$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$op[n_doc]");
			}
			$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$libro[observaciones]");

		}else{

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$estado[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$libro[colegio]");
			if (empty($op["id"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$op[id]");
			}
			
			if (!empty($op["id"])) {

				if ($op["estado"]==2) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "Si");
				}else{
					$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "No");
				}
			}

			if (!empty($op["id"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$op[tipo]");
				$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op[n_doc]");
			}
			
			$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$libro[observaciones]");

		}
		
		


		$conta++;
	}

	
    $objSpreadsheet->getActiveSheet()->getStyle('I'.$conta.':G'.$conta)->applyFromArray($estilo_negrita);  



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
if ($_POST["usuario"]==0) {
	header('Content-Disposition: attachment; filename="pedidos_op.xlsx"');
}else{
	header('Content-Disposition: attachment; filename="pedidos_op_'.$usuario["nombre_c"].'.xlsx"');
}

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>