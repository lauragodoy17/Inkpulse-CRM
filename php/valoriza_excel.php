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
ini_set('memory_limit', '512M');
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

$objSpreadsheet->getActiveSheet()->mergeCells('C2:D2');
$objSpreadsheet->getActiveSheet()->getStyle('C2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('C2')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->SetCellValue("C2", "REPORTE DE VALORIZACIÓN");

$sql_periodo="SELECT periodo FROM periodos WHERE id='".$_POST["periodo"]."'";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();
$fecha=date("Y-m-d");

$objSpreadsheet->getActiveSheet()->getStyle('C4')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('D4')->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "$fecha");

$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "Empresa");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Asesor");
$objSpreadsheet->getActiveSheet()->SetCellValue("C6", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("D6", "Dane");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "Zona");
$objSpreadsheet->getActiveSheet()->SetCellValue("F6", "Departamento");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Ciudad");
$objSpreadsheet->getActiveSheet()->SetCellValue("H6", "Editorial");
$objSpreadsheet->getActiveSheet()->SetCellValue("I6", "Etiqueta");
$objSpreadsheet->getActiveSheet()->SetCellValue("J6", "Grado");
$objSpreadsheet->getActiveSheet()->SetCellValue("K6", "Libro");
$objSpreadsheet->getActiveSheet()->SetCellValue("L6", "Cantidades Presupuestadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("M6", "Descuento Presupuestado");
$objSpreadsheet->getActiveSheet()->SetCellValue("N6", "Valor Presupuestado");
$objSpreadsheet->getActiveSheet()->SetCellValue("O6", "Probabilidad");
$objSpreadsheet->getActiveSheet()->SetCellValue("P6", "Cantidades Adopciones");
$objSpreadsheet->getActiveSheet()->SetCellValue("Q6", "Descuento Adopción");
$objSpreadsheet->getActiveSheet()->SetCellValue("R6", "Valor Adopciones");
$objSpreadsheet->getActiveSheet()->SetCellValue("S6", "Unidades Venta Real");
$objSpreadsheet->getActiveSheet()->SetCellValue("T6", "Venta Real");
$objSpreadsheet->getActiveSheet()->SetCellValue("U6", "Muestras entregadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("V6", "Valor atenciones entregadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("W6", "Total visitas ejecutadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("X6", "Status");


$objSpreadsheet->getActiveSheet()->getStyle('A6:X6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

if ($_SESSION['tipo']==1 || $_SESSION['tipo']==2 || $_SESSION['tipo']==7) {
    
    if ($_POST['promotor']!=0) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, p.probabilidad, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_POST['promotor']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";



    }else{
        $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, p.probabilidad, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

    }

}elseif($_SESSION['tipo']==3) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, p.probabilidad, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_SESSION['id']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

}elseif($_SESSION['tipo']==10) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, p.probabilidad, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."'  AND (c.cod_zona='".$_SESSION['zona']."' OR c.zona_madre='".$_SESSION['zona']."')  AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

}





/*$sql = "SELECT e.estado, s.id,s.fecha, s.solicitante, s.cargo, s.fecha_entrega FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$gp_periodo['id']."' ORDER BY s.id DESC";*/

$req = $bdd->prepare($sql);
$req->execute();
$colegios = $req->fetchAll();

// ── Pre-fetch para eliminar N+1 queries ──────────────────────────
$v_cole_ids = array_values(array_unique(array_column($colegios, 'id')));
$ph_vc = !empty($v_cole_ids) ? implode(',', array_fill(0, count($v_cole_ids), '?')) : '0';

$grados_map = [];
foreach ($bdd->query("SELECT id, grado FROM grados")->fetchAll(PDO::FETCH_ASSOC) as $row)
    $grados_map[$row['id']] = $row['grado'];

$dep_map_v = [];
foreach ($bdd->query("SELECT id, departamento FROM departamentos")->fetchAll(PDO::FETCH_ASSOC) as $row)
    $dep_map_v[$row['id']] = $row['departamento'];

$probabilidad_map = [];
foreach ($bdd->query("SELECT id, probabilidad, valor FROM probabilidades")->fetchAll(PDO::FETCH_ASSOC) as $row)
    $probabilidad_map[$row['id']] = $row['probabilidad'].' ('.$row['valor'].'%)';

$ao_map_v = [];
if (!empty($v_cole_ids)) {
    $req_ao = $bdd->prepare("SELECT id_colegio, id_libro_eureka, codigo, id_grado_otro FROM areas_objetivas WHERE id_colegio IN ($ph_vc) AND id_periodo = ?");
    $req_ao->execute(array_merge($v_cole_ids, [$_POST['periodo']]));
    foreach ($req_ao->fetchAll(PDO::FETCH_ASSOC) as $row)
        $ao_map_v[$row['id_colegio']][$row['id_libro_eureka']][$row['codigo']] = $row['id_grado_otro'];
}

$muestras_map = [];
if (!empty($v_cole_ids)) {
    $req_mu = $bdd->prepare("SELECT m.id_colegio, l.id_libro, SUM(l.cantidad) as cant FROM libros_muestreos_e l JOIN muestreos_e m ON l.cod_muestreo = m.codigo WHERE m.id_periodo = ? AND m.id_colegio IN ($ph_vc) GROUP BY m.id_colegio, l.id_libro");
    $req_mu->execute(array_merge([$_POST['periodo']], $v_cole_ids));
    foreach ($req_mu->fetchAll(PDO::FETCH_ASSOC) as $row)
        $muestras_map[$row['id_colegio']][$row['id_libro']] = $row['cant'];
}

$total_legal_map = [];
if (!empty($v_cole_ids)) {
    $req_tl = $bdd->prepare("SELECT s.id_colegio, SUM(r.legaliza) as total FROM solicitudes_recursos s JOIN recursos_solicitados r ON s.id = r.id_solicitud WHERE s.id_colegio IN ($ph_vc) AND s.id_periodo = ? AND s.estado = '4' GROUP BY s.id_colegio");
    $req_tl->execute(array_merge($v_cole_ids, [$_POST['periodo']]));
    foreach ($req_tl->fetchAll(PDO::FETCH_ASSOC) as $row)
        $total_legal_map[$row['id_colegio']] = $row['total'];
}

$ejecu_map = [];
if (!empty($v_cole_ids)) {
    $req_ej = $bdd->prepare("SELECT id_colegio, COUNT(id) as ejecu FROM plan_trabajo WHERE id_colegio IN ($ph_vc) AND id_periodo = ? AND resultado = '1' GROUP BY id_colegio");
    $req_ej->execute(array_merge($v_cole_ids, [$_POST['periodo']]));
    foreach ($req_ej->fetchAll(PDO::FETCH_ASSOC) as $row)
        $ejecu_map[$row['id_colegio']] = $row['ejecu'];
}

$status_map_v = [];
if (!empty($v_cole_ids)) {
    $status_prio = [6=>0,5=>1,1=>2,2=>3,3=>4,4=>5];
    $req_st_v = $bdd->prepare("SELECT cs.id_colegio, cs.id_status, s.status FROM colegios_status cs JOIN status_cubrimiento s ON cs.id_status = s.id WHERE cs.id_colegio IN ($ph_vc) AND cs.id_periodo = ?");
    $req_st_v->execute(array_merge($v_cole_ids, [$_POST['periodo']]));
    $tmp_status = [];
    foreach ($req_st_v->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = $row['id_colegio'];
        $p = $status_prio[$row['id_status']] ?? 99;
        if (!isset($tmp_status[$cid]) || $p < $tmp_status[$cid]['prio'])
            $tmp_status[$cid] = ['prio' => $p, 'status' => $row['status']];
    }
    foreach ($tmp_status as $cid => $v) $status_map_v[$cid] = $v['status'];

    $req_st_fb = $bdd->prepare("SELECT cs.id_colegio, s.status FROM colegios_status cs JOIN status_cubrimiento s ON cs.id_status = s.id WHERE cs.id_colegio IN ($ph_vc) AND s.id != 4 ORDER BY cs.id_periodo DESC");
    $req_st_fb->execute($v_cole_ids);
    foreach ($req_st_fb->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($status_map_v[$row['id_colegio']]))
            $status_map_v[$row['id_colegio']] = $row['status'];
    }
}

$sz_map_v = [];
$v_sz_ids = array_values(array_filter(array_unique(array_column($colegios, 'sub_zona'))));
if (!empty($v_sz_ids)) {
    $ph_sz_v = implode(',', array_fill(0, count($v_sz_ids), '?'));
    $req_szv = $bdd->prepare("SELECT id, sub_zona FROM sub_zonas WHERE id IN ($ph_sz_v)");
    $req_szv->execute($v_sz_ids);
    foreach ($req_szv->fetchAll(PDO::FETCH_ASSOC) as $row)
        $sz_map_v[$row['id']] = $row['sub_zona'];
}

$alumnos_map_v = [];
if (!empty($v_cole_ids)) {
    $req_gp_all_v = $bdd->prepare("SELECT id_colegio, id_grado, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio IN ($ph_vc) AND id_periodo=? AND alumnos > 0 GROUP BY id_colegio, id_grado");
    $req_gp_all_v->execute(array_merge($v_cole_ids, [$_POST['periodo']]));
    foreach ($req_gp_all_v->fetchAll(PDO::FETCH_ASSOC) as $row)
        $alumnos_map_v[$row['id_colegio']][$row['id_grado']] = (int)$row['alumnos'];
}
// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=7;
$cache_calculo_tasa = [];
$cache_precio_venta = [];

foreach ($colegios as $colegio) {
    $descuento_p=0;
    $descuento_d=0;

    if ($colegio["id_grado"] != 17 && $colegio["cod_area"] == "") {
        $alumnos      = $alumnos_map_v[$colegio["id"]][$colegio["id_grado"]] ?? 0;
        $n_grado_str  = $grados_map[$colegio["id_grado"]] ?? '';
    } else {
        $grado_o_id   = $ao_map_v[$colegio['id']][$colegio["idlibro"]][$colegio["cod_area"]] ?? 0;
        $alumnos      = $alumnos_map_v[$colegio["id"]][$grado_o_id] ?? 0;
        $n_grado_str  = $grados_map[$grado_o_id] ?? '';
    }



    if ($colegio["pre_definido"] ==1) {

        // Creamos una clave única con los valores involucrados
        $key_calc = $alumnos . "_" . $colegio["tasa_compra"];

        if (!isset($cache_calculo_tasa[$key_calc])) {
            $alumnos_tasa = floor($alumnos * $colegio["tasa_compra"]);
            $cache_calculo_tasa[$key_calc] = $alumnos_tasa;
        }

        // Usar siempre el valor desde la caché
        $alumnos_tasa = $cache_calculo_tasa[$key_calc];

        // Creamos clave única para esta operación
        $key_precio = $colegio["precio"] . "_" . $colegio["descuento"] . "_" . $alumnos_tasa;
        $descuento_p=$colegio["descuento"] * 100;
        if (!isset($cache_precio_venta[$key_precio])) {
            $precio_neto = $colegio["precio"] - ($colegio["precio"] * $colegio["descuento"]);
            $venta_ppto = $precio_neto * $alumnos_tasa;

            $cache_precio_venta[$key_precio] = [
                'precio_neto' => $precio_neto,
                'venta_ppto' => $venta_ppto
            ];
        }

        $precio_neto = $cache_precio_venta[$key_precio]['precio_neto'];
        $venta_ppto = $cache_precio_venta[$key_precio]['venta_ppto'];


        $alumnos_tasa_d=0;
        $precio_neto_d=0;
        $venta_ppto_d=0;

    }

   
    if ($colegio["definido"] !=0) {
        if ($colegio["tasa_compra_d"] == 0.00) {

            $alumnos_tasa_d = floor($alumnos * $colegio["tasa_compra"]);
            
            $key_precio = $colegio["precio"] . "_" . $colegio["descuento"] . "_" . $alumnos_tasa;


            $precio_neto_d = $colegio["precio"] - ($colegio["precio"] * $colegio["descuento"]);
            $venta_ppto_d = $precio_neto_d * $alumnos_tasa_d;


             $descuento_d=$colegio["descuento"] * 100;


           
        }else{
           
            $alumnos_tasa_d = floor($alumnos * $colegio["tasa_compra_d"]);

         
            $precio_neto_d = $colegio["precio"] - ($colegio["precio"] * $colegio["descuento_d"]);
            $venta_ppto_d=$precio_neto_d * $alumnos_tasa_d;
               
            
            $descuento_d=$colegio["descuento_d"] * 100;
            
           
            $venta_ppto_d=$precio_neto_d * $alumnos_tasa_d;

            $venta_real= $precio_neto_d * $colegio["uni_vr"];
        }

        

    }
   
    $muestras_cant = $muestras_map[$colegio["id"]][$colegio["idlibro"]] ?? null;
    $total_val     = $total_legal_map[$colegio["id"]] ?? null;
    $ejecu_val     = $ejecu_map[$colegio["id"]] ?? 0;
    $status_str    = $status_map_v[$colegio["id"]] ?? '';

    if ($colegio["tipouser"]!=6) {
        list($empresa, $n_zona) = array_pad(explode("/", $colegio["zona"] ?? ''), 2, '');
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$empresa");
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$colegio[promotor]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$n_zona");
    }else{
        $sz_nombre_v = $sz_map_v[$colegio["sub_zona"]] ?? '';
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$colegio[promotor]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$sz_nombre_v");
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$colegio[responsable]");
    }
	
	$objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$colegio[dane]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$colegio[colegio]");

    $dep_str = $dep_map_v[$colegio['departamento']] ?? '';

    $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", $dep_str);
    $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$colegio[ciudad]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$colegio[editorial]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$colegio[etiqueta]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", $n_grado_str);
   
    
	$objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$colegio[libro]");
    if ($colegio["pre_definido"] ==1) {
    	$objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$alumnos_tasa");
        $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$descuento_p");
    	$objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$venta_ppto");
    }
    $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", $probabilidad_map[$colegio["probabilidad"]] ?? '');
	$objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", "$alumnos_tasa_d");
    $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", "$descuento_d");
	$objSpreadsheet->getActiveSheet()->SetCellValue("R$conta", "$venta_ppto_d");
    $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", "$colegio[uni_vr]");
    if ($colegio["definido"] !=0) {

        if (empty($venta_real)) {
            $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "0");
        }else{
            $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "$venta_real");
        }


    }else{
        $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "0");
    }

    $objSpreadsheet->getActiveSheet()->SetCellValue("U$conta", $muestras_cant ?? 0);
    $objSpreadsheet->getActiveSheet()->SetCellValue("V$conta", $total_val ?? 0);
    $objSpreadsheet->getActiveSheet()->SetCellValue("W$conta", $ejecu_val);

    $objSpreadsheet->getActiveSheet()->SetCellValue("X$conta", !empty($status_str) ? $status_str : "Por definir");

	$conta++;


}	


$objSpreadsheet->getActiveSheet()->getStyle("N7:N$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("R7:R$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("T7:T$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("V7:V$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );
	


foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="Valorización.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
?>