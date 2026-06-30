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
	$objSpreadsheet->getProperties()->setTitle("Muestreo en excel");
	$objSpreadsheet->createSheet(0);
	$objSpreadsheet->setActiveSheetIndex(0);
	$objSpreadsheet->getActiveSheet()->setTitle("Muestreo en excel");
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
		
		/*$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Usuario");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");*/
		$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "#");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Usuario");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Colegio");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Estado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "OP");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Isbn");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Libro");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Cantidad");

	}else{

		$sql = "SELECT CONCAT(nombres, ' ', apellidos) as nombre_c FROM usuarios WHERE id='".$_POST["usuario"]."'";
		$req = $bdd->prepare($sql);
		$req->execute();
		$usuario = $req->fetch();

		$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Usuario");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$usuario[nombre_c]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "#");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Colegio");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Estado");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "OP");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Isbn");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Libro");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Cantidad");
	}

	

	$objSpreadsheet->getActiveSheet()->getStyle("A1:I1")->getFont()->getColor()->applyFromArray(
		array(
		'rgb' => '#251919'
		)
	);
	$objSpreadsheet->getActiveSheet()->getStyle("A4:I4")->getFont()->getColor()->applyFromArray(
		array(
		'rgb' => '#251919'
		)
	);


    $objSpreadsheet->getActiveSheet()->getStyle('A4:I4')->applyFromArray([
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

    	
    	$sql="SELECT pe.id, pe.fecha, c.colegio,pe.estado, l.libro, l.precio, l.isbn, m.materia, lp.cantidad, lp.cantidad_aprob, CONCAT(u.nombres, ' ',u.apellidos) as promotor FROM muestreos pe JOIN libros_muestreos lp ON lp.cod_muestreo=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN colegios c ON c.id=pe.id_colegio JOIN usuarios u ON pe.id_usuario=u.id WHERE pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id";

    }else{
    	$sql="SELECT pe.id, pe.fecha, c.colegio,pe.estado, l.libro, l.precio, l.isbn, m.materia, lp.cantidad, lp.cantidad_aprob FROM muestreos pe JOIN libros_muestreos lp ON lp.cod_muestreo=pe.codigo JOIN libros l ON l.id=lp.id_libro JOIN materias m ON l.id_materia=m.id JOIN colegios c ON c.id=pe.id_colegio WHERE pe.id_usuario='".$_POST["usuario"]."' AND pe.fecha BETWEEN '".$desde."' AND '".$hasta."' ORDER BY pe.id";
    }

    

    

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros= $req->fetchAll();

	$estados_map = [];
	foreach ($bdd->query("SELECT id, estado FROM estados_pedidos")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $estados_map[$row['id']] = $row['estado'];

	$muestreo_ids = array_column($libros, 'id');
	$op_map = [];
	if (!empty($muestreo_ids)) {
	    $ph_mids = implode(',', array_fill(0, count($muestreo_ids), '?'));
	    $req_op = $bdd->prepare("SELECT id_muestreo, id as op FROM ordenes_pedidos WHERE id_muestreo IN ($ph_mids)");
	    $req_op->execute($muestreo_ids);
	    foreach ($req_op->fetchAll(PDO::FETCH_ASSOC) as $row)
	        $op_map[$row['id_muestreo']] = $row['op'];
	}

	$conta=5;
	foreach($libros as $libro) {

        if ($libro["cantidad_aprob"]==0) {
           $cantidad=$libro["cantidad"];
	    }else{
	      $cantidad=$libro["cantidad_aprob"];
	    }

	    $estado = ['estado' => $estados_map[$libro["estado"]] ?? ''];
	    $op     = ['op'     => $op_map[$libro["id"]] ?? null];

		if ($_POST["usuario"]==0) {

			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[promotor]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$libro[colegio]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$estado[estado]");
			if (!empty($op["op"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$op[op]");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "");
			}
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$libro[isbn]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$libro[libro]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$cantidad");

		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$libro[id]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$libro[fecha]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$libro[colegio]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$estado[estado]");
			if (!empty($op["op"])) {
				$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$op[op]");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "");
			}
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$libro[isbn]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$libro[libro]");
			$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$cantidad");
		}
		
		


		$conta++;
	}




	foreach (range('A', 'Z') as $columnID) {
	  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
	}


	$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	if ($_POST["usuario"]==0) {
		header('Content-Disposition: attachment; filename="muestreos_general.xlsx"');
	}else{
		header('Content-Disposition: attachment; filename="muestreos_'.$usuario["nombre_c"].'.xlsx"');
	}


	header('Cache-Control: max-age=0');
	header('Expires: 0');
	header('Pragma: public');
	$objWriter->save('php://output');
?>