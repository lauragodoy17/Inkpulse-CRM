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


$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "Empresa");
  
$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Departamento");
$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "Ciudad");
$objSpreadsheet->getActiveSheet()->SetCellValue("G4", "Barrio");
$objSpreadsheet->getActiveSheet()->SetCellValue("H4", "Paralelos prescolar");
$objSpreadsheet->getActiveSheet()->SetCellValue("I4", "Paralelos primaria");
$objSpreadsheet->getActiveSheet()->SetCellValue("J4", "Paralelos bachillerato");
$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "Paralelos global");
$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "Alumnos prescolar");
$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "Alumnos primaria");
$objSpreadsheet->getActiveSheet()->SetCellValue("N4", "Alumnos bachillerato");
$objSpreadsheet->getActiveSheet()->SetCellValue("O4", "Alumnos global");
$objSpreadsheet->getActiveSheet()->SetCellValue("P4", "Status");
$objSpreadsheet->getActiveSheet()->SetCellValue("Q4", "Propuesta comercial");
$objSpreadsheet->getActiveSheet()->SetCellValue("R4", "Segmento");
$objSpreadsheet->getActiveSheet()->SetCellValue("S4", "Estado del cliente");
$objSpreadsheet->getActiveSheet()->SetCellValue("T4", "Fecha de último contacto");
$objSpreadsheet->getActiveSheet()->getStyle("A1:T1")->getFont()->getColor()->applyFromArray(
  array(
  'rgb' => '#251919'
  )
);

$objSpreadsheet->getActiveSheet()->getStyle('A4:T4')->applyFromArray([
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


  if ($_POST["periodo"] < 7) {

  $sql="SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, z.zona, c.responsable, ca.calendario, CONCAT (u.nombres, ' ',u.apellidos) as promotor, sc.status, s.segmento, d.departamento FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario JOIN usuarios u ON u.cod_zona=z.codigo LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' AND cs.id_status != 4 LEFT JOIN segmentos s ON c.id_segmento=s.id LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status JOIN departamentos d ON d.id=c.departamento WHERE z.codigo='".$usuario["cod_zona"]."' AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id ORDER BY u.id";

  }else{
    $sql="SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, z.zona, c.responsable, ca.calendario, CONCAT (u.nombres, ' ',u.apellidos) as promotor, sc.status, s.segmento, d.departamento FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario JOIN usuarios u ON u.cod_zona=z.codigo LEFT JOIN colegios_status cs ON c.id=cs.id_colegio  AND cs.id_status != 4 LEFT JOIN segmentos s ON c.id_segmento=s.id LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status JOIN departamentos d ON d.id=c.departamento WHERE z.codigo='".$usuario["cod_zona"]."' AND c.id_calendario='".$gp_periodo['id_calendario']."' AND cs.id_periodo = '".$_POST["periodo"]."' GROUP BY c.id ORDER BY u.id";
  }

}else{

  if ($_POST["periodo"] < 7) {

  $sql="SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, z.zona, c.responsable, ca.calendario, CONCAT (u.nombres, ' ',u.apellidos) as promotor, sc.status, s.segmento, d.departamento FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario JOIN usuarios u ON u.cod_zona=z.codigo LEFT JOIN colegios_status cs ON c.id=cs.id_colegio AND cs.id_periodo = '".$_POST["periodo"]."' AND cs.id_status != 4 LEFT JOIN segmentos s ON c.id_segmento=s.id LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status JOIN departamentos d ON d.id=c.departamento WHERE (z.codigo='".$usuario["cod_zona"]."' OR c.zona_madre='".$usuario["cod_zona"]."') AND c.id_calendario='".$gp_periodo['id_calendario']."' GROUP BY c.id ORDER BY u.id";

  }else{
    $sql="SELECT c.id, c.dane as codigo, UPPER(c.colegio) as colegio, c.barrio,c.ciudad, c.departamento, c.direccion,c.telefono, c.sub_zona, z.zona, c.responsable, ca.calendario, CONCAT (u.nombres, ' ',u.apellidos) as promotor, sc.status, s.segmento, d.departamento FROM colegios c JOIN zonas z ON c.cod_zona=z.codigo JOIN calendarios ca ON ca.id=c.id_calendario JOIN usuarios u ON u.cod_zona=z.codigo LEFT JOIN colegios_status cs ON c.id=cs.id_colegio  AND cs.id_status != 4 LEFT JOIN segmentos s ON c.id_segmento=s.id LEFT JOIN status_cubrimiento sc ON sc.id=cs.id_status JOIN departamentos d ON d.id=c.departamento WHERE (z.codigo='".$usuario["cod_zona"]."' OR c.zona_madre='".$usuario["cod_zona"]."') AND c.id_calendario='".$gp_periodo['id_calendario']."' AND cs.id_periodo = '".$_POST["periodo"]."' GROUP BY c.id ORDER BY u.id";
  }

}



  
$req = $bdd->prepare($sql);
$req->execute();
$coles = $req->fetchAll();

// ── Pre-fetch para eliminar N+1 queries ──────────────────────────
$cole_ids2 = array_column($coles, 'id');
$gp_pre_map = []; $gp_pri_map = []; $gp_bach_map = [];
$uc_map2 = []; $adj_map3 = []; $est_map2 = [];

if (!empty($cole_ids2)) {
    $ph2 = implode(',', array_fill(0, count($cole_ids2), '?'));

    $req_gp2 = $bdd->prepare("SELECT id_colegio, id_grado, COUNT(alumnos) as paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio IN ($ph2) AND id_periodo=? AND alumnos!=0 GROUP BY id_colegio, id_grado");
    $req_gp2->execute(array_merge($cole_ids2, [$gp_periodo["id"]]));
    foreach ($req_gp2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = $row['id_colegio']; $gid = (int)$row['id_grado'];
        if ($gid >= 1 && $gid <= 3) {
            $gp_pre_map[$cid]['paralelos'] = ($gp_pre_map[$cid]['paralelos'] ?? 0) + $row['paralelos'];
            $gp_pre_map[$cid]['alumnos']   = ($gp_pre_map[$cid]['alumnos']   ?? 0) + $row['alumnos'];
        } elseif ($gid >= 4 && $gid <= 8) {
            $gp_pri_map[$cid]['paralelos'] = ($gp_pri_map[$cid]['paralelos'] ?? 0) + $row['paralelos'];
            $gp_pri_map[$cid]['alumnos']   = ($gp_pri_map[$cid]['alumnos']   ?? 0) + $row['alumnos'];
        } elseif ($gid >= 9 && $gid <= 14) {
            $gp_bach_map[$cid]['paralelos'] = ($gp_bach_map[$cid]['paralelos'] ?? 0) + $row['paralelos'];
            $gp_bach_map[$cid]['alumnos']   = ($gp_bach_map[$cid]['alumnos']   ?? 0) + $row['alumnos'];
        }
    }

    $req_uc2 = $bdd->prepare("SELECT p.id_colegio, MAX(v.fecha) as ultimo_contacto FROM plan_trabajo p JOIN visitas v ON p.id=v.id_plan_trabajo WHERE p.id_colegio IN ($ph2) AND p.resultado=1 GROUP BY p.id_colegio");
    $req_uc2->execute($cole_ids2);
    foreach ($req_uc2->fetchAll(PDO::FETCH_ASSOC) as $row)
        $uc_map2[$row['id_colegio']] = $row['ultimo_contacto'];

    $req_adj3 = $bdd->prepare("SELECT id_colegio FROM adjuntos WHERE id_colegio IN ($ph2) AND id_periodo=? AND tipo=1 GROUP BY id_colegio");
    $req_adj3->execute(array_merge($cole_ids2, [$_POST["periodo"]]));
    foreach ($req_adj3->fetchAll(PDO::FETCH_ASSOC) as $row)
        $adj_map3[$row['id_colegio']] = true;

    if ($_POST["periodo"] >= 7) {
        $req_est2 = $bdd->prepare("SELECT ce.id_colegio, e.estado FROM estados_cliente e JOIN colegios_estados_clientes ce ON e.id=ce.id_estado_cliente WHERE ce.id_colegio IN ($ph2) AND ce.id_periodo=?");
        $req_est2->execute(array_merge($cole_ids2, [$_POST["periodo"]]));
        foreach ($req_est2->fetchAll(PDO::FETCH_ASSOC) as $row)
            $est_map2[$row['id_colegio']] = $row['estado'];
    }
}
// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=5;
foreach($coles as $cole) {

    $gp_pre  = $gp_pre_map[$cole['id']]  ?? ['paralelos' => 0, 'alumnos' => 0];
    $gp_pri  = $gp_pri_map[$cole['id']]  ?? ['paralelos' => 0, 'alumnos' => 0];
    $gp_bach = $gp_bach_map[$cole['id']] ?? ['paralelos' => 0, 'alumnos' => 0];
    $paralelos_global = $gp_pre["paralelos"] + $gp_pri["paralelos"] + $gp_bach["paralelos"];
    $alumnos_global   = $gp_pre["alumnos"]   + $gp_pri["alumnos"]   + $gp_bach["alumnos"];
    $uc        = ['ultimo_contacto' => $uc_map2[$cole["id"]] ?? null];
    $count_p   = isset($adj_map3[$cole["id"]]) ? 1 : 0;

  $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$cole[codigo]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$cole[colegio]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$cole[calendario]");
    
  $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$empresa");
    
  $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$cole[departamento]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$cole[ciudad]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$cole[barrio]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$gp_pre[paralelos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$gp_pri[paralelos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$gp_bach[paralelos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$paralelos_global");
  $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$gp_pre[alumnos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$gp_pri[alumnos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$gp_bach[alumnos]");
  $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "$alumnos_global");
  $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", "$cole[status]");

  if ($count_p < 1) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", "No");
  }else{
    $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", "Si");
  }
  
  $objSpreadsheet->getActiveSheet()->SetCellValue("R$conta", "$cole[segmento]");

  if ($_POST["periodo"] < 7) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", "");
  } else {
    $estado_cli = $est_map2[$cole["id"]] ?? '';
    $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", $estado_cli);
  }
  

  if (empty($uc["ultimo_contacto"])) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "");
  }else{
    $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "$uc[ultimo_contacto]");
  }


$conta++;
}



foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Cubrimiento_'.$nombre_completo.'.xlsx"');

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
?>