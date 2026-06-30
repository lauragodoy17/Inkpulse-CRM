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
$objSpreadsheet->getProperties()->setTitle("valorización libro a libro");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("valorización libro a libro");
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

$estilo_fuente = array(
    'font' => array(
        'size' => 8.5
    )
);

$estilo_borde = [
    'borders' => [
        'top' => ['style' => Border::BORDER_THIN],
        'right' => ['style' => Border::BORDER_THIN],
        'bottom' => ['style' => Border::BORDER_THIN],
        'left' => ['style' => Border::BORDER_THIN],
    ]

    
];

//poner imagen
$drawing = new Drawing();
$drawing->setName('test_img');
$drawing->setDescription('test_img');
$drawing->setPath('../vendors/images/logo_eureka.png'); // Ruta relativa o absoluta a la imagen
$drawing->setHeight(100); // Puedes ajustar el tamaño si deseas
$drawing->setCoordinates('A1'); // Posición en la hoja
$drawing->setWorksheet($objSpreadsheet->getActiveSheet());

$objSpreadsheet->getActiveSheet()->mergeCells('A1:B4');


if ($_POST['promotor']!=0) {

    $sql = "SELECT nombres, apellidos FROM usuarios WHERE id='".$_POST['promotor']."'";

    $req = $bdd->prepare($sql);
    $req->execute();
    $usuario = $req->fetch();

    $nombre_completo=$usuario["nombres"]." ".$usuario["apellidos"];
    $objSpreadsheet->getActiveSheet()->SetCellValue("E4", "$nombre_completo");
}





$objSpreadsheet->getActiveSheet()->mergeCells('C2:D2');
$objSpreadsheet->getActiveSheet()->getStyle('C2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('C2')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "Reporte Cantidades adopciones");

if (isset($_POST['desde']) ) {
    $objSpreadsheet->getActiveSheet()->getStyle('E2')->applyFromArray($estilo_negrita);
    $objSpreadsheet->getActiveSheet()->SetCellValue("E2", "Desde # $_POST[desde] Hasta # $_POST[hasta]");
}

$sql_periodo="SELECT periodo FROM periodos WHERE id='".$_POST["periodo"]."'";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();
$fecha=date("Y-m-d");

$objSpreadsheet->getActiveSheet()->getStyle('C4')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('D4')->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "$fecha");

$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "Libro");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Etiqueta");
$objSpreadsheet->getActiveSheet()->SetCellValue("C6", "PVP");
$objSpreadsheet->getActiveSheet()->SetCellValue("D6", "Población total");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "Compradores activos");
$objSpreadsheet->getActiveSheet()->SetCellValue("F6", "Venta estimada");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Unidades Venta real");


$objSpreadsheet->getActiveSheet()->getStyle('A6:G6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

if ($_SESSION['tipo']==1 || $_SESSION['tipo']==2 || $_SESSION['tipo']==7) {
    
    if ($_POST['promotor']!=0) {

    $sql="SELECT l.id, l.libro, l.etiqueta, l.id_grado, e.editorial, p.precio FROM presupuestos p JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id WHERE p.definido=1 AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_POST['promotor']."' AND p.probabilidad !=3 GROUP BY l.id ORDER BY l.libro";

 

    }else{

        if (!isset($_POST['desde']) ) {

            $sql="SELECT l.id, l.libro, l.etiqueta, l.id_grado, e.editorial, p.precio FROM presupuestos p JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id WHERE p.definido=1 AND p.id_periodo='".$_POST['periodo']."' AND p.probabilidad !=3 GROUP BY l.id ORDER BY l.libro";
        }else{

            $sql="SELECT l.id, l.libro, l.etiqueta, l.id_grado, e.editorial, p.precio FROM presupuestos p JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id WHERE p.definido=1 AND p.id_periodo='".$_POST['periodo']."' AND p.probabilidad !=3 AND p.conse BETWEEN '".$_POST["desde"]."' AND '".$_POST["hasta"]."' GROUP BY l.id ORDER BY l.libro";
        }

        
       
     
    }

}else{

    //$sql ="SELECT c.id, c.colegio, CONCAT(u.nombres, ' ',u.apellidos) as promotor, l.id as idlibro, l.libro, l.id_grado,l.id_materia, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.definido, p.cod_area, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_SESSION['id']."'  AND p.fila_zona > 0 AND p.probabilidad !=3 GROUP BY p.id ORDER BY c.id, l.libro";

}





/*$sql = "SELECT e.estado, s.id,s.fecha, s.solicitante, s.cargo, s.fecha_entrega FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$gp_periodo['id']."' ORDER BY s.id DESC";*/

$req = $bdd->prepare($sql);
$req->execute();
$colegios = $req->fetchAll();

// ── Pre-fetch presupuestos para todos los libros en una sola query ─
$libro_ids = array_column($colegios, 'id');
$pres_por_libro = [];
if (!empty($libro_ids)) {
    $ph_lb = implode(',', array_fill(0, count($libro_ids), '?'));
    if ($_POST['promotor'] != 0) {
        $sql_pres = "SELECT p.id_libro, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d, p.definido, p.cod_area, p.id_colegio, SUM(p.uni_vr) AS uni_vr FROM presupuestos p WHERE p.id_libro IN ($ph_lb) AND p.definido=1 AND p.id_periodo=? AND p.id_usuario=? AND p.probabilidad!=3 GROUP BY p.id_libro, p.id_colegio";
        $params_pres = array_merge($libro_ids, [$_POST['periodo'], $_POST['promotor']]);
    } elseif (isset($_POST['desde'])) {
        $sql_pres = "SELECT p.id_libro, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d, p.definido, p.cod_area, p.id_colegio, SUM(p.uni_vr) AS uni_vr FROM presupuestos p WHERE p.id_libro IN ($ph_lb) AND p.definido=1 AND p.id_periodo=? AND p.probabilidad!=3 AND p.conse BETWEEN ? AND ? GROUP BY p.id_libro, p.id_colegio";
        $params_pres = array_merge($libro_ids, [$_POST['periodo'], $_POST['desde'], $_POST['hasta']]);
    } else {
        $sql_pres = "SELECT p.id_libro, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d, p.definido, p.cod_area, p.id_colegio, SUM(p.uni_vr) AS uni_vr FROM presupuestos p WHERE p.id_libro IN ($ph_lb) AND p.definido=1 AND p.id_periodo=? AND p.probabilidad!=3 GROUP BY p.id_libro, p.id_colegio";
        $params_pres = array_merge($libro_ids, [$_POST['periodo']]);
    }
    $req_pres = $bdd->prepare($sql_pres);
    $req_pres->execute($params_pres);
    foreach ($req_pres->fetchAll(PDO::FETCH_ASSOC) as $row)
        $pres_por_libro[$row['id_libro']][] = $row;
}

// Colegios únicos de los presupuestos → pre-fetch grados y areas
$all_cole_ids = [];
foreach ($pres_por_libro as $rows)
    foreach ($rows as $r) $all_cole_ids[$r['id_colegio']] = true;
$all_cole_ids = array_keys($all_cole_ids);

$gp_map_ca = [];
$ao_map_ca  = [];
if (!empty($all_cole_ids)) {
    $ph_ca = implode(',', array_fill(0, count($all_cole_ids), '?'));
    $req_gp_ca = $bdd->prepare("SELECT id_colegio, id_grado, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio IN ($ph_ca) AND id_periodo=? AND alumnos > 0 GROUP BY id_colegio, id_grado");
    $req_gp_ca->execute(array_merge($all_cole_ids, [$_POST['periodo']]));
    foreach ($req_gp_ca->fetchAll(PDO::FETCH_ASSOC) as $row)
        $gp_map_ca[$row['id_colegio']][$row['id_grado']] = (int)$row['alumnos'];

    $req_ao_ca = $bdd->prepare("SELECT id_colegio, id_libro_eureka, codigo, id_grado_otro FROM areas_objetivas WHERE id_colegio IN ($ph_ca) AND id_periodo=?");
    $req_ao_ca->execute(array_merge($all_cole_ids, [$_POST['periodo']]));
    foreach ($req_ao_ca->fetchAll(PDO::FETCH_ASSOC) as $row)
        $ao_map_ca[$row['id_colegio']][$row['id_libro_eureka']][$row['codigo']] = $row['id_grado_otro'];
}
// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=7;
$last_uni_vr = 0;

foreach ($colegios as $colegio) {

    $libros = $pres_por_libro[$colegio["id"]] ?? [];
    $last_uni_vr = 0;

    foreach ($libros as $libro) {

        if ($colegio["id_grado"] != 17) {
            $alumnos = $gp_map_ca[$libro["id_colegio"]][$colegio["id_grado"]] ?? 0;
        } else {
            $grado_o_id = $ao_map_ca[$libro["id_colegio"]][$colegio["id"]][$libro["cod_area"]] ?? 0;
            $alumnos    = $gp_map_ca[$libro["id_colegio"]][$grado_o_id] ?? 0;
        }

        if ($libro["tasa_compra_d"] == 0.00) {
            $alumnos_tasa_d = floor($alumnos * $libro["tasa_compra"]);
            $precio_neto_d  = $colegio["precio"] - ($colegio["precio"] * $libro["descuento"]);
        } else {
            $alumnos_tasa_d = floor($alumnos * $libro["tasa_compra_d"]);
            $precio_neto_d  = $colegio["precio"] - ($colegio["precio"] * $libro["descuento_d"]);
        }

        $poblacion[$colegio["id"]][]      = $alumnos;
        $castigo[$colegio["id"]][]        = $alumnos_tasa_d;
        $valor_adopcion[$colegio["id"]][] = $precio_neto_d * $alumnos_tasa_d;
        $last_uni_vr = $libro["uni_vr"];
    }

    $t_poblacion[$colegio["id"]]     = array_sum($poblacion[$colegio["id"]] ?? []);
    $t_castigo[$colegio["id"]]       = array_sum($castigo[$colegio["id"]] ?? []);
    $t_valor_adopcion[$colegio["id"]]= array_sum($valor_adopcion[$colegio["id"]] ?? []);

	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$colegio[libro]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$colegio[etiqueta]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$colegio[precio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", $t_poblacion[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", $t_castigo[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", $t_valor_adopcion[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", $last_uni_vr);

	$conta++;
}

$objSpreadsheet->getActiveSheet()->getStyle("C7:C$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

$objSpreadsheet->getActiveSheet()->getStyle("F7:F$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );




foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

if ($_POST['promotor']!=0) {
    header('Content-Disposition: attachment; filename="Cantidades_adopcion_'.$nombre_completo.'.xlsx"');
}else{
    header('Content-Disposition: attachment; filename="Cantidades_adopcion.xlsx"');
}


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
	
?>