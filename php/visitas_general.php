<?php
ini_set('display_errors', 1);

ini_set('display_startup_errors', 1);

error_reporting(E_ALL);
//include("../lib/ZipStream/src/ZipStream.php");
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

require_once("../php/aut.php");
include("../conexion/bdd.php");

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Reporte de visitas");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Reporte de visitas");
$objSpreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToPage(true);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);




	if ($_POST["promo"] !="todos") {

		$sql = "SELECT nombres, apellidos, cod_zona FROM usuarios WHERE id='".$_POST["promo"]."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$usuario = $req->fetch();
		$nombre_completo=$usuario["nombres"]." ".$usuario["apellidos"];

		$sql_zona="SELECT zona FROM zonas WHERE codigo='".$usuario["cod_zona"]."'";

		$req_zona = $bdd->prepare($sql_zona);
		$req_zona->execute();
		$zona = $req_zona->fetch();

	}

	




//~ Ingreo de datos en la hojda de excel
if ($_POST["promo"] !="todos") {

	$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Zona");
	if (!empty($zona["zona"])) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$zona[zona]");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "");
	}
	
	$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Promotor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "$nombre_completo");

}

if ($_POST["promo"] =="todos") {

	$objSpreadsheet->getActiveSheet()->getStyle('A4:N4')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);


	$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "Promotor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Fecha planificada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Hora planificada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Colegio / Otro lugar");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Status");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Profesor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Cargo");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Objetivo");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Resultado");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Efectiva");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Fecha llegada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Fecha ejecutada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Comentarios");
	$objSpreadsheet->getActiveSheet()->SetCellValue("N4", "Participante externo");

}else {

		$objSpreadsheet->getActiveSheet()->getStyle('A4:M4')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

	$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "Fecha planificada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Hora planificada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Colegio / Otro lugar");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Status");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Profesor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Cargo");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Objetivo");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Resultado");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Efectiva");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Fecha llegada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Fecha ejecutada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Comentarios");
	$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Participante externo");

}



$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();

$desde=$_POST["desde"]." "."00:00:00";
$hasta=$_POST["hasta"]." "."23:59:59";


	if ($_POST["promo"] =="todos") {

		$sql = "SELECT p.id as planid, p.resultado, p.cod_profesor, p.id_objetivo, p.otro_lugar, p.otro_participante, p.otro_objetivo, c.id as cid, UPPER(c.colegio) as colegio, p.start, CONCAT(u.nombres,' ',u.apellidos) as promotor FROM plan_trabajo p LEFT JOIN colegios c ON p.id_colegio=c.id JOIN usuarios u ON p.id_promotor=u.id WHERE p.start BETWEEN '".$desde."' AND '".$hasta."' ORDER BY start ASC";
		$req = $bdd->prepare($sql);
		$req->execute();
		$planes = $req->fetchAll();

	}else{

		$sql = "SELECT p.id as planid, p.resultado, p.cod_profesor, p.id_objetivo, p.otro_lugar, p.otro_participante, p.otro_objetivo, c.id as cid, UPPER(c.colegio) as colegio, p.start FROM plan_trabajo p LEFT JOIN colegios c ON p.id_colegio=c.id WHERE p.id_promotor='".$_POST["promo"]."' AND p.start BETWEEN '".$desde."' AND '".$hasta."' ORDER BY start ASC";
		$req = $bdd->prepare($sql);
		$req->execute();
		$planes = $req->fetchAll();

	}


$conta=5;

foreach($planes as $plan) {

	if ($plan["resultado"]==1) {

		$sql = "SELECT observaciones, fecha_llegada, fecha,efectiva FROM visitas WHERE id_plan_trabajo='".$plan["planid"]."'";
		$req = $bdd->prepare($sql);
		$req->execute();
		$visitas = $req->fetch();
	}

	$sql_profe = "SELECT t.nombre, t.codigo, t.cargo as id_cargo, t.area, c.cargo FROM trabajadores_colegios t JOIN cargos c ON c.id=t.cargo WHERE codigo='".$plan["cod_profesor"]."' AND codigo!=''";
	$req_profe = $bdd->prepare($sql_profe);
	$req_profe->execute();
	$profe = $req_profe->fetch();

	$sql_objetivo = "SELECT objetivo FROM objetivos WHERE id='".$plan["id_objetivo"]."'";
	$req_objetivo = $bdd->prepare($sql_objetivo);
	$req_objetivo->execute();
	$objetivo = $req_objetivo->fetch();

	if (!empty($profe["id_cargo"])) {
		if ($profe["id_cargo"]==5) {


			$sql_area = "SELECT materia FROM materias WHERE id='".$profe["area"]."'";
			$req_area = $bdd->prepare($sql_area);
			$req_area->execute();

			$area = $req_area->fetch();

			$cargo= $profe["cargo"]." ".$area["materia"];

		}elseif ($profe["id_cargo"]==6) {
			
			$sql_area = "SELECT m.materia FROM materias m JOIN grados_materias gm ON m.id=gm.id_materia WHERE gm.cod_profesor='".$profe["codigo"]."'";
			$req_area = $bdd->prepare($sql_area);
			$req_area->execute();

			$area = $req_area->fetch();
			if (empty($area["materia"])) {
				$cargo= $profe["cargo"];
			}else{
				$cargo= $profe["cargo"]." ".$area["materia"];
			}
			


		}else {

			$cargo= $profe["cargo"];

		}
	}
	

	list($fecha,$hora)=explode(" ", $plan["start"]);

	// Colegio / Otro lugar
	$col_lugar = !empty($plan["otro_lugar"])
	    ? "Otro lugar - " . $plan["otro_lugar"]
	    : ($plan["colegio"] ?? '');

	// Objetivo / Otro objetivo
	$col_objetivo = !empty($plan["otro_objetivo"])
	    ? "Otro objetivo - " . $plan["otro_objetivo"]
	    : ($objetivo["objetivo"] ?? '');

	// Participante externo
	$col_participante = $plan["otro_participante"] ?? '';

	$sql_st = "SELECT status FROM colegios_status cs JOIN status_cubrimiento s ON cs.id_status=s.id WHERE cs.id_colegio='".$plan["cid"]."' ORDER BY cs.id DESC, FIELD (cs.id_status,'5','1','2','3','4')";
		$req_st = $bdd->prepare($sql_st);
		$req_st->execute();
		$status = $req_st->fetch();

	if ($_POST["promo"] =="todos") {
		$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$plan[promotor]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$hora");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", $col_lugar);
		if (empty($status["status"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$status[status]");
		}

		if (!empty($profe["nombre"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$profe[nombre]");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "");
		}

		if (!empty($profe["id_cargo"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$cargo");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "");
		}

		$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", $col_objetivo);
	 	if ($plan["resultado"]==1) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "Ejecutada");
		}
		else {
			$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "No ejecutada");
		}

		if ($plan["resultado"]==1) {

			if ($visitas["efectiva"]==1) {

				$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "SI");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "NO");
			}

			$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$visitas[fecha_llegada]");

			$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$visitas[fecha]");

			$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$visitas[observaciones]");
		}

		if (!empty($col_participante)) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", $col_participante);
		}

	}else{

		$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$fecha");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$hora");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", $col_lugar);
		if (empty($status["status"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$status[status]");
		}

		if (!empty($profe["nombre"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$profe[nombre]");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "");
		}
		if (!empty($profe["id_cargo"])) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cargo");
		}else{
			$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "");
		}

		$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", $col_objetivo);
	 	if ($plan["resultado"]==1) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "Ejecutada");
		}
		else {
			$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "No ejecutada");
		}

		if ($plan["resultado"]==1) {

			if ($visitas["efectiva"]==1) {

				$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "SI");
			}else{
				$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "NO");
			}

			$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$visitas[fecha_llegada]");

			$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$visitas[fecha]");

			$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$visitas[observaciones]");
		}

		if (!empty($col_participante)) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", $col_participante);
		}

	}

	


$conta++;
}
foreach (range('A', 'N') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
if ($_POST["promo"] !="todos") {
	header('Content-Disposition: attachment; filename="Visitas_'.$nombre_completo.'.xlsx"');
}else{
	header('Content-Disposition: attachment; filename="Visitas_general.xlsx"');
}


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>