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


if ($_POST['promo']!=0) {
	$sql = "SELECT nombres, apellidos, cod_zona, id_pais, tipo FROM usuarios WHERE id='".$_POST["promo"]."'";
	$req = $bdd->prepare($sql);
	$req->execute();
	$usuario = $req->fetch();
	$nombre_completo=$usuario["nombres"]." ".$usuario["apellidos"];
	$sql_zona="SELECT zona FROM zonas WHERE codigo='".$usuario["cod_zona"]."'";
	$req_zona = $bdd->prepare($sql_zona);
	$req_zona->execute();
	$zona = $req_zona->fetch();
}


$fecha=date("Y-m-d");



//~ Ingreo de datos en la hojda de excel
if ($_POST['promo']!=0) {

	if ($usuario["tipo"]==3 || $usuario["tipo"]==1 || $usuario["tipo"]==10) {
		list($empresa,$n_zona) = explode("/", $zona["zona"]);
		$objSpreadsheet->getActiveSheet()->SetCellValue("A1", "Zona");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A2", "$zona[zona]");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B1", "Asesor");
		$objSpreadsheet->getActiveSheet()->SetCellValue("B2", "$nombre_completo");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("A1", "Empresa");
		$objSpreadsheet->getActiveSheet()->SetCellValue("A2", "$zona[zona]");
	}

}



$objSpreadsheet->getActiveSheet()->SetCellValue("C1", "Fecha Reporte");
$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "$fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("A4", "Dane");
$objSpreadsheet->getActiveSheet()->SetCellValue("B4", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Calendario");

if ($_POST['promo']!=0) {

	if ($usuario["tipo"]==3 || $usuario["tipo"]==1) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Empresa");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Zona / Asesor");
	}

}else{
	$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Usuario");
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

if ($_POST['promo']!=0) {

	if ($usuario["tipo"]!=10) {
		$sql = "SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, c.zona_madre, z.zona, c.responsable, ca.calendario, sc.status FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status WHERE z.codigo='".$usuario["cod_zona"]."' AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id";
	}else{
		$sql = "SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, c.zona_madre, z.zona, c.responsable, ca.calendario, sc.status FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status WHERE (z.codigo='".$usuario["cod_zona"]."' OR c.zona_madre='".$usuario["cod_zona"]."') AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id";
	}
	
}else{

	$sql = "SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, c.zona_madre, z.zona, c.responsable, ca.calendario, sc.status, CONCAT (u.nombres, ' ',u.apellidos) as promotor FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario JOIN usuarios u ON u.cod_zona=z.codigo LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status WHERE z.codigo !='74838' AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id ORDER BY u.id";
}

	$req = $bdd->prepare($sql);
	$req->execute();
	$coles = $req->fetchAll();

// ── Pre-fetch para eliminar N+1 queries ──────────────────────────
$cole_ids = array_column($coles, 'id');
$dep_map = []; $adj_map2 = []; $sz_map2 = [];

$req_all_dep = $bdd->query("SELECT id, departamento FROM departamentos");
foreach ($req_all_dep->fetchAll(PDO::FETCH_ASSOC) as $row)
    $dep_map[$row['id']] = $row['departamento'];

if (!empty($cole_ids)) {
    $ph = implode(',', array_fill(0, count($cole_ids), '?'));
    $req_adj2 = $bdd->prepare("SELECT id_colegio FROM adjuntos WHERE id_colegio IN ($ph) AND id_periodo = ? AND tipo = 1 GROUP BY id_colegio");
    $req_adj2->execute(array_merge($cole_ids, [$_POST["periodo"]]));
    foreach ($req_adj2->fetchAll(PDO::FETCH_ASSOC) as $row)
        $adj_map2[$row['id_colegio']] = true;
}
$all_sz_ids2 = array_values(array_filter(array_unique(array_column($coles, 'sub_zona'))));
if (!empty($all_sz_ids2)) {
    $ph_sz2 = implode(',', array_fill(0, count($all_sz_ids2), '?'));
    $req_sz2 = $bdd->prepare("SELECT id, sub_zona FROM sub_zonas WHERE id IN ($ph_sz2)");
    $req_sz2->execute($all_sz_ids2);
    foreach ($req_sz2->fetchAll(PDO::FETCH_ASSOC) as $row)
        $sz_map2[$row['id']] = $row['sub_zona'];
}
// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=5;
foreach($coles as $cole) {

	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$cole[codigo]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$cole[colegio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$cole[calendario]");

	if ($_POST['promo']!=0) {
		if ($usuario["tipo"]==3 || $usuario["tipo"]==1) {
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$empresa");
		} elseif ($usuario["tipo"]==10) {
			if ($cole["zona_madre"]=="") {
				$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$empresa");
			} else {
				$sznombre = $sz_map2[$cole["sub_zona"]] ?? '';
				$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$sznombre / $cole[responsable]");
			}
		} else {
			$sznombre = $sz_map2[$cole["sub_zona"]] ?? '';
			$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$sznombre / $cole[responsable]");
		}
	} else {
		$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$cole[promotor]");
	}

	$dep_nombre = $dep_map[$cole['departamento']] ?? '';
	$count_p2   = isset($adj_map2[$cole['id']]) ? 1 : 0;

	$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", $dep_nombre);
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cole[ciudad]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$cole[barrio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$cole[direccion]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$cole[telefono]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$cole[status]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", $count_p2 < 1 ? "No" : "Si");

	$conta++;
}

foreach (range('A', 'K') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
if ($_POST['promo']!=0) {
	header('Content-Disposition: attachment; filename="Zonificación_'.$nombre_completo.'.xlsx"');
}else{
	header('Content-Disposition: attachment; filename="Zonificación_general.xlsx"');
}

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
?>