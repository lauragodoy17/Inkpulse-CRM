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

		/*$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Distribuidor");
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

		$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Distribuidor");
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

    	$sql = "SELECT pe.id, pe.fecha, c.colegio,pe.estado, pe.observaciones, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn, m.materia, lp.cantidad, p.cod_area, p.descuento_d, p.tasa_compra_d, lp.cantidad_aprob, lp.descuento_aprob,lp.id as lpid, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM pedidos pe JOIN libros_pedidos lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN presupuestos p ON p.id_colegio=pe.id_colegio AND p.id_libro=lp.id_libro AND pe.id_periodo=p.id_periodo JOIN colegios c ON c.id=p.id_colegio JOIN usuarios u ON pe.id_usuario=u.id WHERE pe.fecha BETWEEN '".$desde."' AND '".$hasta."'  GROUP BY pe.id ORDER BY pe.id;";

    }else{
    	$sql = "SELECT pe.id, pe.fecha, c.colegio,pe.estado, pe.observaciones, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn, m.materia, lp.cantidad, p.cod_area, p.descuento_d, p.tasa_compra_d, lp.cantidad_aprob, lp.descuento_aprob,lp.id as lpid FROM pedidos pe JOIN libros_pedidos lp ON lp.cod_pedido=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN presupuestos p ON p.id_colegio=pe.id_colegio AND p.id_libro=lp.id_libro AND pe.id_periodo=p.id_periodo JOIN colegios c ON c.id=p.id_colegio WHERE pe.id_usuario='".$_POST["usuario"]."' AND pe.fecha BETWEEN '".$desde."' AND '".$hasta."'  GROUP BY pe.id ORDER BY pe.id;";
    }

	

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros= $req->fetchAll();

	$estados_map = [];
	foreach ($bdd->query("SELECT id, estado FROM estados_pedidos")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $estados_map[$row['id']] = $row['estado'];

	$pedido_ids = array_column($libros, 'id');
	$op_map_eo = []; $agrup_map = []; $agrup_count = [];
	if (!empty($pedido_ids)) {
	    $ph_pid = implode(',', array_fill(0, count($pedido_ids), '?'));
	    $req_op_eo = $bdd->prepare("SELECT o.id_pedido, o.id, o.estado, o.n_doc, t.tipo FROM ordenes_pedidos o JOIN tipo_doc t ON t.id=o.tipo_doc WHERE o.id_pedido IN ($ph_pid)");
	    $req_op_eo->execute($pedido_ids);
	    foreach ($req_op_eo->fetchAll(PDO::FETCH_ASSOC) as $row)
	        $op_map_eo[$row['id_pedido']] = $row;

	    $req_agrup = $bdd->prepare("SELECT a.id_pedido, a.op, o.estado, o.n_doc, t.tipo FROM op_pedidos_agrupados a JOIN ordenes_pedidos o ON o.id=a.op JOIN tipo_doc t ON t.id=o.tipo_doc WHERE a.id_pedido IN ($ph_pid)");
	    $req_agrup->execute($pedido_ids);
	    foreach ($req_agrup->fetchAll(PDO::FETCH_ASSOC) as $row) {
	        $agrup_count[$row['id_pedido']] = ($agrup_count[$row['id_pedido']] ?? 0) + 1;
	        if (!isset($agrup_map[$row['id_pedido']]))
	            $agrup_map[$row['id_pedido']] = $row;
	    }
	}

	$conta=5;
	foreach($libros as $libro) {

		$estado = ['estado' => $estados_map[$libro["estado"]] ?? ''];
		$op     = $op_map_eo[$libro["id"]] ?? [];
		$op2    = $agrup_map[$libro["id"]] ?? null;
		$num    = $agrup_count[$libro["id"]] ?? 0;

		if ($_POST["usuario"]==0) {

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[promotor]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$estado[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$libro[colegio]");

			if ($num !=0) {
				$op2 = $req2->fetch();

				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$op2[op]");
				if ($op2["estado"]==2) {
					$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "Si");
				}else{
					$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "No");
				}

				$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op2[tipo]");
				$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$op2[n_doc]");
			

			}else{

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

					$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op[tipo]");
					$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$op[n_doc]");
				}

			}
			
			
			$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$libro[observaciones]");
			

		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$estado[estado]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$libro[colegio]");

			if ($num !=0) {
				$op2 = $req2->fetch();

				$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$op2[op]");
				if (!empty($op["estado"])) {
					if ($op["estado"]==2) {
						$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "Si");
					}else{
						$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "No");
					}
				}
				

				$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$op2[tipo]");
				$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op2[n_doc]");

			}else{

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

					$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$op[tipo]");
					$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$op[n_doc]");
				}

				

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