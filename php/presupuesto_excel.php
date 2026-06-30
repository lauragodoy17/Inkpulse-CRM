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
$objSpreadsheet->getProperties()->setTitle("Presupuesto en excel");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Presupuesto en excel");
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

	$sql_cole="SELECT colegio, cod_zona FROM colegios WHERE id='".$_GET["cole"]."'";

	$req_cole = $bdd->prepare($sql_cole);
	$req_cole->execute();
	$cole = $req_cole->fetch();

	$sql_zona="SELECT zona FROM zonas WHERE codigo='".$cole["cod_zona"]."'";

	$req_zona = $bdd->prepare($sql_zona);
	$req_zona->execute();
	$zona = $req_zona->fetch();


	$sql = "SELECT nombres, apellidos FROM usuarios WHERE cod_zona='".$cole["cod_zona"]."'";

	$req = $bdd->prepare($sql);
	$req->execute();
	$usuario = $req->fetch();

	$nombre_completo=$usuario["nombres"]." ".$usuario["apellidos"];


	

//~ Ingreo de datos en la hojda de excel

$objSpreadsheet->getActiveSheet()->mergeCells('F2:H2');
$objSpreadsheet->getActiveSheet()->getStyle('F2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('F2')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->SetCellValue("F2", "REPORTE DE PRESUPUESTO");

$objSpreadsheet->getActiveSheet()->getStyle('B5')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('B5')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('B6')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('E4')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E4')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('E5')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E5')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('E6')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E6')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('A10:N10')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('A9:L9')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('A10:N10')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('F11:J11')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('F11:J11')->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("C5", "          ");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "          ");
$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "                 ");
$objSpreadsheet->getActiveSheet()->SetCellValue("K4", "                 ");
$objSpreadsheet->getActiveSheet()->SetCellValue("L4", "                 ");
$objSpreadsheet->getActiveSheet()->SetCellValue("M4", "                 ");
$objSpreadsheet->getActiveSheet()->SetCellValue("N4", "                 ");

$objSpreadsheet->getActiveSheet()->SetCellValue("E5", "Zona:");
$objSpreadsheet->getActiveSheet()->SetCellValue("F5", "$zona[zona]");
$objSpreadsheet->getActiveSheet()->mergeCells('B5:D5');
$objSpreadsheet->getActiveSheet()->mergeCells('B6:C6');
$objSpreadsheet->getActiveSheet()->SetCellValue("A5", "Colegio:");
$objSpreadsheet->getActiveSheet()->SetCellValue("B5", "$cole[colegio]");
$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "Asesor:");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "$nombre_completo");
$objSpreadsheet->getActiveSheet()->mergeCells('G5:I5');
$objSpreadsheet->getActiveSheet()->mergeCells('G6:I6');
$objSpreadsheet->getActiveSheet()->mergeCells('G7:I7');
$objSpreadsheet->getActiveSheet()->mergeCells('G8:I8');



$sql_descuento =  "SELECT avg(descuento) as descuento_pactado FROM presupuestos p  WHERE p.id_colegio='".$_GET["cole"]."' AND p.id_periodo='".$_GET["periodo"]."' AND p.pre_aprob=1 AND p.probabilidad !=3";

$req_descuento = $bdd->prepare($sql_descuento);
$req_descuento->execute();
$descuento = $req_descuento->fetch();
$descuento_pactado= $descuento["descuento_pactado"] * 100;


$objSpreadsheet->getActiveSheet()->mergeCells('A10:A11');
$objSpreadsheet->getActiveSheet()->mergeCells('B10:B11');
$objSpreadsheet->getActiveSheet()->mergeCells('C10:C11');
$objSpreadsheet->getActiveSheet()->mergeCells('D10:D11');
$objSpreadsheet->getActiveSheet()->mergeCells('E10:E11');
$objSpreadsheet->getActiveSheet()->mergeCells('F10:J10');
$objSpreadsheet->getActiveSheet()->mergeCells('K10:K11');
$objSpreadsheet->getActiveSheet()->mergeCells('L10:L11');
$objSpreadsheet->getActiveSheet()->mergeCells('M10:M11');
$objSpreadsheet->getActiveSheet()->mergeCells('N10:N11');
$objSpreadsheet->getActiveSheet()->mergeCells('O10:O11');
$objSpreadsheet->getActiveSheet()->getStyle('E10')->applyFromArray($estilo_centrar);

$objSpreadsheet->getActiveSheet()->getStyle('A10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('B10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('C10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('D10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('E10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('F10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('G10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('H10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('I10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('J10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('K10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('L10')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('M10')->applyFromArray($estilo_borde);
/*$objSpreadsheet->getActiveSheet()->getStyle('N10')->applyFromArray($estilo_borde);*/
$objSpreadsheet->getActiveSheet()->getStyle('F11')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('G11')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('H11')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('I11')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('J11')->applyFromArray($estilo_borde);

$objSpreadsheet->getActiveSheet()->SetCellValue("A10", "TITULO");
$objSpreadsheet->getActiveSheet()->SetCellValue("B10", "GRADO");
$objSpreadsheet->getActiveSheet()->SetCellValue("C10", "CURSOS");
$objSpreadsheet->getActiveSheet()->SetCellValue("D10", "ALUMNOS");
$objSpreadsheet->getActiveSheet()->SetCellValue("E10", "% COMPRADORES");
$objSpreadsheet->getActiveSheet()->SetCellValue("F10", "VENTA ESTIMADA");
$objSpreadsheet->getActiveSheet()->SetCellValue("F11", "COMPRADORES ACTIVOS");
$objSpreadsheet->getActiveSheet()->SetCellValue("G11", "PVP");
$objSpreadsheet->getActiveSheet()->SetCellValue("H11", "DESCUENTO %");
$objSpreadsheet->getActiveSheet()->SetCellValue("I11", "VENTA BRUTA");
$objSpreadsheet->getActiveSheet()->SetCellValue("J11", "PRECIO FACTURACIÓN");
$objSpreadsheet->getActiveSheet()->SetCellValue("K10", "VENTA ESTIMADA");
$objSpreadsheet->getActiveSheet()->SetCellValue("L10", "PROBABILIDAD");
$objSpreadsheet->getActiveSheet()->SetCellValue("M10", "VENTA AJUSTADA");
/*$objSpreadsheet->getActiveSheet()->SetCellValue("N10", "VENTA \n REAL");
$objSpreadsheet->getActiveSheet()->SetCellValue("O10", "DIFEREN \n CIA");*/


$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();



	$sql = "SELECT l.libro, l.id_grado, g.grado, m.materia, p.precio, p.tasa_compra, p.descuento, p.precio_venta_final, p.id_libro, p.cod_area, p.probabilidad FROM libros l JOIN presupuestos p ON l.id=p.id_libro JOIN grados g ON l.id_grado=g.id JOIN materias m ON m.id=l.id_materia  WHERE p.id_periodo='".$_GET["periodo"]."' AND p.id_colegio='".$_GET["cole"]."' AND p.tasa_compra > 0 AND p.pre_aprob='1'";
	$req = $bdd->prepare($sql);
	$req->execute();
	$adopciones = $req->fetchAll();

	if (empty($adopciones) ) {
 		echo "<script>alert('Aun no hay adopciones en este colegio');window.location='../reporte_adopcion.php'</script>";
 	}else {

	// ── Pre-fetch para eliminar N+1 ──────────────────────────────────
	$ao_map_pre = [];
	$req_ao_pre = $bdd->prepare("SELECT codigo, id_grado_otro FROM areas_objetivas WHERE id_colegio=? AND id_periodo=?");
	$req_ao_pre->execute([$_GET["cole"], $_GET["periodo"]]);
	foreach ($req_ao_pre->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $ao_map_pre[$row['codigo']] = $row['id_grado_otro'];

	$gp_map_pre = [];
	$req_gp_pre = $bdd->prepare("SELECT id_grado, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio=? AND id_periodo=? AND alumnos > 0 GROUP BY id_grado");
	$req_gp_pre->execute([$_GET["cole"], $_GET["periodo"]]);
	foreach ($req_gp_pre->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $gp_map_pre[$row['id_grado']] = (int)$row['alumnos'];

	$prob_map_pre = [];
	foreach ($bdd->query("SELECT id, probabilidad, valor FROM probabilidades")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $prob_map_pre[$row['id']] = $row;

	$grados_map_pre = [];
	foreach ($bdd->query("SELECT id, grado FROM grados")->fetchAll(PDO::FETCH_ASSOC) as $row)
	    $grados_map_pre[$row['id']] = $row['grado'];
	// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=12;

foreach($adopciones as $adopcion) {

	$grado_otro_id = $ao_map_pre[$adopcion["cod_area"]] ?? 0;
	$go = ['id_grado_otro' => $grado_otro_id];

	if ($go["id_grado_otro"] == 0) {
	    $gp = ['alumnos' => $gp_map_pre[$adopcion["id_grado"]] ?? 0];
	} else {
	    $gp = ['alumnos' => $gp_map_pre[$go["id_grado_otro"]] ?? 0];
	}

	$probab = $prob_map_pre[$adopcion["probabilidad"]] ?? null;


    $tasa_compra=$adopcion["tasa_compra"] * 100;
    $comp_activos=$gp["alumnos"] * $adopcion["tasa_compra"];
    $comp_activos=floor($comp_activos);
    $descuento=$adopcion["descuento"] * 100;
    $venta_bruta=$adopcion["precio"] * $comp_activos;
    $precio_fact=$adopcion["precio"] - ($adopcion["precio"] * $adopcion["descuento"]);
    $venta_estimada=$precio_fact * $comp_activos;
    $venta_real= $adopcion["precio_venta_final"] * $comp_activos;
    $diferencia=$venta_real - $venta_estimada;
    $prob_valor = ($probab && isset($probab["valor"])) ? floatval($probab["valor"]) : 0;
    $venta_ajustada = $venta_estimada * ($prob_valor / 100);
    $venta_ajustada1 = number_format($venta_ajustada, 0, ",", ".");

    $objSpreadsheet->getActiveSheet()->getStyle('A'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('B'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('C'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('D'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('E'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('F'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('G'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('H'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('I'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('J'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('K'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('L'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('M'.$conta)->applyFromArray($estilo_borde);
	/*$objSpreadsheet->getActiveSheet()->getStyle('N'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('O'.$conta)->applyFromArray($estilo_borde);*/

	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$adopcion[libro]");

	if ($go["id_grado_otro"] == 0) {
		$effective_grado = $adopcion["id_grado"];
		$grade_label_map[$effective_grado] = $adopcion["grado"];
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$adopcion[grado]");
		if ($adopcion["id_grado"] < 4) {
			$p_pre[]=$tasa_compra;
		}elseif ($adopcion["id_grado"] > 3 && $adopcion["id_grado"] < 9) {
			$p_pri[]=$tasa_compra;
		}else {
			$p_sec[]=$tasa_compra;
		}

	}else{
		$effective_grado = $go["id_grado_otro"];
		$grado_nombre = $grados_map_pre[$go["id_grado_otro"]] ?? '';
		$grade_label_map[$effective_grado] = $grado_nombre;
		$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", $grado_nombre);
		if ($go["id_grado_otro"] < 4) {
			$p_pre[]=$tasa_compra;
		}elseif ($go["id_grado_otro"] > 3 && $go["id_grado_otro"] < 9) {
			$p_pri[]=$tasa_compra;
		}else {
			$p_sec[]=$tasa_compra;
		}
	}

	$pvp=number_format($adopcion["precio"],0,",", ".");
	$venta_bruta1=number_format($venta_bruta,0,",", ".");
	$precio_fact1=number_format($precio_fact,0,",", ".");
	$venta_estimada1=number_format($venta_estimada,0,",", ".");
	$p_final=number_format($adopcion["precio_venta_final"],0,",", ".");
	$venta_real1=number_format($venta_real,0,",", ".");
	$diferencia1=number_format($diferencia,0,",", ".");
	if (empty($gp["paralelos"])) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$gp[paralelos]");
	}
	
	$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$gp[alumnos]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$tasa_compra");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$comp_activos");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$$adopcion[precio]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$descuento");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$$venta_bruta1");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$$precio_fact1");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$$venta_estimada1");
	if (empty($probab["probabilidad"])) {
		$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "");
	}else{
		$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$probab[probabilidad]");
	}
	$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$$venta_ajustada1");

	/*$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$$p_final");
	$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$$venta_real1");
	$objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "$$diferencia1");*/


	$conta++;
	if ($adopcion["probabilidad"] !=3) {
		$t_alumnos[]=$gp["alumnos"];
		if (empty($gp["paralelos"])) {
			$t_paralelos[]=0;
		}else{
			$t_paralelos[]=$gp["paralelos"];
		}

		$t_compradores[]=$comp_activos;
		$t_venta_bruta[]=$venta_bruta;
		$t_venta_estimada[]=$venta_estimada;
		$t_venta_ajustada[]=$venta_ajustada;
		$t_venta_real[]=$venta_real;
		$t_diferencia[]=$diferencia;
		$t_alumnos_g[$effective_grado][] = $gp["alumnos"];
		$t_compradores_g[$effective_grado][] = $comp_activos;
		$t_descuento_g[$effective_grado][] = $descuento;
		$t_precio_fact_g[$effective_grado][] = $precio_fact;
		$t_venta_bruta_g[$effective_grado][] = $venta_bruta;
		$t_venta_estimada_g[$effective_grado][] = $venta_estimada;
		$t_venta_ajustada_g[$effective_grado][] = $venta_ajustada;
		$t_precio_fact_total[] = $precio_fact;
	}
	
	
}
if (isset($p_pre)) {
	$p_pre=array_sum($p_pre)/count($p_pre);
}else {
	$p_pre=0;
}
if (isset($p_pri)) {
	$p_pri=array_sum($p_pri)/count($p_pri);
}else{
	$p_pri=0;
}
if (isset($p_sec)) {
	$p_sec=array_sum($p_sec)/count($p_sec);
}else{
	$p_sec=0;
}

$objSpreadsheet->getActiveSheet()->SetCellValue("G5", "Potencial compra preescolar %");
$objSpreadsheet->getActiveSheet()->getStyle('J5')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->SetCellValue("J5", "$p_pre");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Potencial compra primaria %");
$objSpreadsheet->getActiveSheet()->getStyle('J6')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->SetCellValue("J6", "$p_pri");
$objSpreadsheet->getActiveSheet()->SetCellValue("G7", "Potencial venta bachillerato %");
$objSpreadsheet->getActiveSheet()->getStyle('J7')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->SetCellValue("J7", "$p_sec");
$objSpreadsheet->getActiveSheet()->SetCellValue("G8", "Promedio descuento %");
$objSpreadsheet->getActiveSheet()->getStyle('J8')->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->SetCellValue("J8", "$descuento_pactado");

$t_paralelos=array_sum($t_paralelos);
$t_alumnos=array_sum($t_alumnos);
$t_compradores=array_sum($t_compradores);
$t_venta_bruta=array_sum($t_venta_bruta);
$t_venta_estimada=array_sum($t_venta_estimada);
$t_venta_real=array_sum($t_venta_real);
$t_diferencia=array_sum($t_diferencia);

// Totales por grado (antes del sort para que queden intercalados con los libros de cada grado)
$grade_order = [1,2,3,4,5,6,7,8,9,10,11,12,13,14];
foreach ($grade_order as $gid) {
	if (!isset($t_alumnos_g[$gid])) continue;
	$conta++;
	$sg_alumnos        = array_sum($t_alumnos_g[$gid]);
	$sg_compradores    = array_sum($t_compradores_g[$gid]);
	$sg_descuento      = round(array_sum($t_descuento_g[$gid]) / count($t_descuento_g[$gid]), 2);
	$sg_precio_fact    = number_format(array_sum($t_precio_fact_g[$gid]), 0, ",", ".");
	$sg_venta_bruta    = number_format(array_sum($t_venta_bruta_g[$gid]), 0, ",", ".");
	$sg_venta_estimada = number_format(array_sum($t_venta_estimada_g[$gid]), 0, ",", ".");
	$sg_venta_ajustada = number_format(array_sum($t_venta_ajustada_g[$gid]), 0, ",", ".");
	$glabel = isset($grade_label_map[$gid]) ? $grade_label_map[$gid] : '';

	$objSpreadsheet->getActiveSheet()->getStyle('A'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('B'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('D'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('F'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('H'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('I'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('J'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('K'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('M'.$conta)->applyFromArray($estilo_borde);
	$objSpreadsheet->getActiveSheet()->getStyle('A'.$conta.':M'.$conta)->applyFromArray($estilo_negrita);

	$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "TOTAL GRADO");
	$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", $glabel);
	$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", $sg_alumnos);
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", $sg_compradores);
	$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", $sg_descuento);
	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$$sg_venta_bruta");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$$sg_precio_fact");
	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$$sg_venta_estimada");
	$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$$sg_venta_ajustada");
}

// Ordenar todas las filas de datos por grado (columna B), con TOTAL GRADO al final de cada grupo
$sheet = $objSpreadsheet->getActiveSheet();
$highestColumn = $sheet->getHighestColumn();
$highestRow = $conta;
$dataArray = [];
for ($row = 12; $row <= $highestRow; $row++) {
	$rowData = [];
	for ($col = 'A'; $col <= $highestColumn; $col++) {
		$rowData[$col] = $sheet->getCell($col . $row)->getValue();
	}
	$dataArray[] = $rowData;
}
usort($dataArray, function ($a, $b) {
	$cmp = strnatcmp((string) ($a['B'] ?? ''), (string) ($b['B'] ?? ''));
	if ($cmp !== 0) return $cmp;
	// Dentro del mismo grado, TOTAL GRADO va al final
	$aIsTotal = ($a['A'] === 'TOTAL GRADO') ? 1 : 0;
	$bIsTotal = ($b['A'] === 'TOTAL GRADO') ? 1 : 0;
	return $aIsTotal - $bIsTotal;
});
$rowIndex = 12;
foreach ($dataArray as $rowData) {
	$colIndex = 'A';
	foreach ($rowData as $value) {
		$sheet->setCellValue($colIndex . $rowIndex, $value);
		$colIndex++;
	}
	$rowIndex++;
}

// TOTAL VENTA al final (después del sort, para que siempre quede en la última fila)
$conta++;
$objSpreadsheet->getActiveSheet()->getStyle('A'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('B'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('C'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('D'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('E'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('F'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('G'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('H'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('I'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('J'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('K'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('L'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('M'.$conta)->applyFromArray($estilo_borde);
/*$objSpreadsheet->getActiveSheet()->getStyle('N'.$conta)->applyFromArray($estilo_borde);
$objSpreadsheet->getActiveSheet()->getStyle('O'.$conta)->applyFromArray($estilo_borde);*/

$t_venta_bruta=number_format($t_venta_bruta,0,",", ".");
$t_venta_estimada=number_format($t_venta_estimada,0,",", ".");
$t_venta_ajustada=array_sum($t_venta_ajustada ?? []);
$t_venta_ajustada=number_format($t_venta_ajustada,0,",", ".");
$t_venta_real=number_format($t_venta_real,0,",", ".");
$t_diferencia=number_format($t_diferencia,0,",", ".");

$t_precio_fact_total_fmt = number_format(array_sum($t_precio_fact_total ?? []), 0, ",", ".");

$objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "TOTAL VENTA");
$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$t_paralelos");
$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$t_alumnos");
$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$t_compradores");
$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$descuento_pactado");
$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$$t_venta_bruta");
$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$$t_precio_fact_total_fmt");
$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$$t_venta_estimada");
$objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$$t_venta_ajustada");
/*$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$$t_venta_real");
$objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "$$t_diferencia");*/

$conta1=$conta + 2;
$conta2=$conta1 + 1;

$conta3=$conta2 + 2;
$conta4=$conta3 + 1;

$conta5=$conta4+ 2;

$conta6=$conta5 + 2;
$conta7=$conta6 + 1;

$conta8=$conta7 + 4;


$objSpreadsheet->getActiveSheet()->getStyle('A1:O'.$conta8)->applyFromArray($estilo_fuente);


$objSpreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth('10');
$objSpreadsheet->getActiveSheet()->getRowDimension(10)->setRowHeight(20);
foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}

$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="Presupuesto_'.$cole["colegio"].'.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;

}
?>