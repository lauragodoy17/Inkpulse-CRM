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


require_once("aut.php");
include("../conexion/bdd.php");

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Reporte de cubrimiento");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Reporte de cubrimiento");
$objSpreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToPage(true);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);



$sql = "SELECT nombres, apellidos, cod_zona, id_pais, tipo FROM usuarios WHERE id='".$_SESSION["id"]."'";
$req = $bdd->prepare($sql);
$req->execute();
$usuario = $req->fetch();
$nombre_completo=$usuario["nombres"]." ".$usuario["apellidos"];
$sql_zona="SELECT zona FROM zonas WHERE codigo='".$usuario["cod_zona"]."'";
$req_zona = $bdd->prepare($sql_zona);
$req_zona->execute();
	$zona = $req_zona->fetch();



$fecha=date("Y-m-d");



//~ Ingreo de datos en la hojda de excel



list($empresa,$n_zona) = explode("/", $zona["zona"]);
$objSpreadsheet->getActiveSheet()->SetCellValue("A1", "Zona");
$objSpreadsheet->getActiveSheet()->SetCellValue("A2", "$zona[zona]");
$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Asesor");
$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$nombre_completo");


$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Fecha Reporte");
$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "$fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "Dane");
$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Calendario");



if ($usuario["tipo"]==3 || $usuario["tipo"]==1) {
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Empresa");
}else{
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Zona / Asesor");
}



$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Departamento");
$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Ciudad");
$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Barrio");
$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Dirección");
$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Teléfono");
$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Status");
$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Propuesta comercial");
$objSpreadsheet->getActiveSheet()->getStyle("A1:K1")->getFont()->getColor()->applyFromArray(
	array(
	'rgb' => '#251919'
	)
);

$objSpreadsheet->getActiveSheet()->getStyle('A4:K4')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

$sql_periodo="SELECT id, id_calendario FROM periodos WHERE id='".$_POST["periodo"]."'";
$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();


if ($_SESSION["tipo"] == 3) {

	$sql = "SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, z.zona, c.responsable, ca.calendario, sc.status FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status WHERE z.codigo='".$usuario["cod_zona"]."' AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id";
}else{

	$sql = "SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, c.zona_madre, z.zona, c.responsable, ca.calendario, sc.status FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status WHERE (z.codigo='".$usuario["cod_zona"]."' OR c.zona_madre='".$usuario["cod_zona"]."') AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id";
}

$req = $bdd->prepare($sql);
$req->execute();
$coles = $req->fetchAll();

$conta=5;
foreach($coles as $cole) {

	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$cole[codigo]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$cole[colegio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$cole[calendario]");
	
	if ($_SESSION["tipo"]==3) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$empresa");
	}else{
		if ($cole["zona_madre"]=="") {
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$empresa");
		}else{
			$sql_sz="SELECT sub_zona FROM sub_zonas WHERE id='".$cole["sub_zona"]."'";

			$req_sz = $bdd->prepare($sql_sz);

			$req_sz->execute();

			$sub_zona = $req_sz->fetch();
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$sub_zona[sub_zona] / $cole[responsable]");
		}

	}
	
	
	$sql_dep="SELECT departamento FROM departamentos WHERE id='".$cole['departamento']."' ";
  $req_dep = $bdd->prepare($sql_dep);
  $req_dep->execute();
  $dep = $req_dep->fetch();

  $sql = "SELECT id FROM adjuntos WHERE id_colegio='".$cole["id"]."' AND id_periodo='".$_POST["periodo"]."' AND tipo=1";

  $req = $bdd->prepare($sql);
  $req->execute();
  $count_p = $req->rowCount();

	$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$dep[departamento]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cole[ciudad]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$cole[barrio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$cole[direccion]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$cole[telefono]");		
	$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$cole[status]");
	
	if ($count_p < 1) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "No");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "Si");
	}



$conta++;
}



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="Zonificación_'.$nombre_completo.'.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
?>