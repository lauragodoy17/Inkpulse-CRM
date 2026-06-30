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
$objSpreadsheet->getProperties()->setTitle("Reporte trabajadores");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Reporte trabajadores");
$objSpreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToPage(true);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);


//~ Ingreo de datos en la hojda de excel

	$objSpreadsheet->getActiveSheet()->SetCellValue("A1", "Zona");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Asesor");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Colegio");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D1", "Nombre");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E1", "Cargo");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F1", "Materia");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G1", "Teléfono");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H1", "Correo electrónico");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I1", "Cumpleaños");


$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();



	$sql = "SELECT t.nombre, t.telefono,t.email,t.cumpleaños,m.materia, ca.cargo, c.colegio, z.zona, u.nombres,u.apellidos FROM trabajadores_colegios t JOIN colegios c ON t.id_colegio=c.id JOIN cargos ca ON t.cargo=ca.id JOIN zonas z ON c.cod_zona=z.codigo JOIN usuarios u ON z.codigo=u.cod_zona LEFT JOIN materias m ON m.id=t.area WHERE t.nombre !='' GROUP BY t.nombre, t.cargo ORDER BY z.id";
	$req = $bdd->prepare($sql);
	$req->execute();
	$coles = $req->fetchAll();

$conta=2;

foreach($coles as $cole) {

		$promotor=$cole["nombres"]. " ".$cole["apellidos"];

		$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$cole[zona]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$promotor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$cole[colegio]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$cole[nombre]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$cole[cargo]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cole[materia]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$cole[telefono]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$cole[email]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$cole[cumpleaños]");

	
	


$conta++;
}


foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="trabajadores_general.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>