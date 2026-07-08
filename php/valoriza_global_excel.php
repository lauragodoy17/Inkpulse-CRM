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
$objSpreadsheet->getProperties()->setTitle("valorización global");
$objSpreadsheet->createSheet(0);
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("valorización global");
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


$objSpreadsheet->getActiveSheet()->mergeCells('E2:G2');
$objSpreadsheet->getActiveSheet()->getStyle('E2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('E2')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->getStyle('H2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('H2')->applyFromArray($estilo_centrar);

$objSpreadsheet->getActiveSheet()->SetCellValue("E2", "REPORTE de valorización global");

$sql_periodo="SELECT periodo FROM periodos WHERE id='".$_POST["periodo"]."'";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();
$fecha=date("Y-m-d");

$objSpreadsheet->getActiveSheet()->SetCellValue("H2", "Periodo $gp_periodo[periodo]");

$mostrar_ia_g = ($gp_periodo["periodo"] >= 2027);

$objSpreadsheet->getActiveSheet()->getStyle('C4')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('D4')->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("E4", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("F4", "$fecha");

$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "#");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Empresa");
$objSpreadsheet->getActiveSheet()->SetCellValue("C6", "Zona");
$objSpreadsheet->getActiveSheet()->SetCellValue("D6", "Asesor");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "Dane");
$objSpreadsheet->getActiveSheet()->SetCellValue("F6", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Departamento");
$objSpreadsheet->getActiveSheet()->SetCellValue("H6", "Ciudad");
$objSpreadsheet->getActiveSheet()->SetCellValue("I6", "Población total");
$objSpreadsheet->getActiveSheet()->SetCellValue("J6", "Cantidad Presupuestada");
$objSpreadsheet->getActiveSheet()->SetCellValue("K6", "Valor Presupuestado");
$objSpreadsheet->getActiveSheet()->SetCellValue("L6", "Compradores activos");
$objSpreadsheet->getActiveSheet()->SetCellValue("M6", "Valor adopciones");
$objSpreadsheet->getActiveSheet()->SetCellValue("N6", "Venta real");
$objSpreadsheet->getActiveSheet()->SetCellValue("O6", "Valor atenciones entregadas");
if ($mostrar_ia_g) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("P6", "Costo semanal IA (COP)");
    $objSpreadsheet->getActiveSheet()->SetCellValue("Q6", "Costo anual IA (COP)");
}


$objSpreadsheet->getActiveSheet()->getStyle($mostrar_ia_g ? 'A6:Q6' : 'A6:O6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

if ($_SESSION['tipo']==1 || $_SESSION['tipo']==2 || $_SESSION['tipo']==7) {



    
    if ($_POST['promotor']!=0) {


     $sql = "SELECT c.id, c.dane, p.sub_zona, p.conse, p.year, c.responsable, c.departamento,c.ciudad, c.colegio, c.direccion, c.barrio,c.telefono, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, u.id as uid, z.zona FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN zonas z ON p.cod_zona=z.codigo JOIN usuarios u on u.id=p.id_usuario WHERE p.id_usuario='".$_POST['promotor']."' AND (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' GROUP BY c.id ORDER BY p.conse DESC";
                                  

 

    }else{

         $sql = "SELECT c.id, c.dane, p.sub_zona, p.conse, p.year, c.responsable, c.departamento,c.ciudad, c.colegio, c.direccion, c.barrio,c.telefono, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, u.id as uid, z.zona FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN zonas z ON p.cod_zona=z.codigo JOIN usuarios u on u.id=p.id_usuario WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' GROUP BY c.id ORDER BY p.conse DESC";
     
    }

}elseif($_SESSION['tipo']==3){

    $sql = "SELECT c.id, c.dane, p.sub_zona, p.conse, p.year, c.responsable, c.departamento,c.ciudad, c.colegio, c.direccion, c.barrio,c.telefono, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, u.id as uid, z.zona FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN zonas z ON p.cod_zona=z.codigo JOIN usuarios u on u.id=p.id_usuario WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_SESSION['id']."' GROUP BY c.id ORDER BY p.conse DESC";

  
}else{

     $sql = "SELECT c.id, c.dane, p.sub_zona, p.conse, p.year, c.responsable, c.departamento,c.ciudad, c.colegio, c.direccion, c.barrio,c.telefono, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, u.id as uid, z.zona FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN zonas z ON p.cod_zona=z.codigo JOIN usuarios u on u.id=p.id_usuario WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND (c.cod_zona='".$_SESSION['zona']."' OR c.zona_madre='".$_SESSION['zona']."') GROUP BY c.id ORDER BY p.conse DESC";
}





/*$sql = "SELECT e.estado, s.id,s.fecha, s.solicitante, s.cargo, s.fecha_entrega FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$gp_periodo['id']."' ORDER BY s.id DESC";*/

$req = $bdd->prepare($sql);
$req->execute();
$colegios = $req->fetchAll();

// ── Pre-fetch para eliminar N+1 queries ──────────────────────────
$cole_ids_g = array_values(array_unique(array_column($colegios, 'id')));
$ph_g = !empty($cole_ids_g) ? implode(',', array_fill(0, count($cole_ids_g), '?')) : '0';

$pres_map_g = [];
if (!empty($cole_ids_g)) {
    $req_pres = $bdd->prepare("SELECT p.id_colegio, p.id_usuario, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d, p.precio, p.pre_definido, p.definido, p.cod_area, p.uni_vr, l.id as idlibro, l.id_grado FROM presupuestos p JOIN libros l ON p.id_libro=l.id WHERE p.id_colegio IN ($ph_g) AND (p.pre_definido=1 OR p.definido=1) AND p.id_periodo=?");
    $req_pres->execute(array_merge($cole_ids_g, [$_POST['periodo']]));
    foreach ($req_pres->fetchAll(PDO::FETCH_ASSOC) as $row)
        $pres_map_g[$row['id_colegio']][$row['id_usuario']][] = $row;
}

$vreal_map_g = [];
if (!empty($cole_ids_g)) {
    $req_vr_g = $bdd->prepare("SELECT id_colegio, venta_real FROM recursos WHERE id_colegio IN ($ph_g) AND id_periodo=?");
    $req_vr_g->execute(array_merge($cole_ids_g, [$_POST['periodo']]));
    foreach ($req_vr_g->fetchAll(PDO::FETCH_ASSOC) as $row)
        $vreal_map_g[$row['id_colegio']] = $row['venta_real'];
}

$ao_map_g = [];
if (!empty($cole_ids_g)) {
    $req_ao_g = $bdd->prepare("SELECT id_colegio, id_libro_eureka, codigo, id_grado_otro FROM areas_objetivas WHERE id_colegio IN ($ph_g) AND id_periodo=?");
    $req_ao_g->execute(array_merge($cole_ids_g, [$_POST['periodo']]));
    foreach ($req_ao_g->fetchAll(PDO::FETCH_ASSOC) as $row)
        $ao_map_g[$row['id_colegio']][$row['id_libro_eureka']][$row['codigo']] = $row['id_grado_otro'];
}

$gp_map_g = [];
if (!empty($cole_ids_g)) {
    $req_gp_g = $bdd->prepare("SELECT id_colegio, id_grado, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio IN ($ph_g) AND id_periodo=? AND alumnos > 0 GROUP BY id_colegio, id_grado");
    $req_gp_g->execute(array_merge($cole_ids_g, [$_POST['periodo']]));
    foreach ($req_gp_g->fetchAll(PDO::FETCH_ASSOC) as $row)
        $gp_map_g[$row['id_colegio']][$row['id_grado']] = (int)$row['alumnos'];
}

$total_map_g = [];
if (!empty($cole_ids_g)) {
    $req_tot_g = $bdd->prepare("SELECT s.id_colegio, SUM(r.legaliza) as total FROM solicitudes_recursos s JOIN recursos_solicitados r ON s.id=r.id_solicitud WHERE s.id_colegio IN ($ph_g) AND s.id_periodo=? AND s.estado='4' GROUP BY s.id_colegio");
    $req_tot_g->execute(array_merge($cole_ids_g, [$_POST['periodo']]));
    foreach ($req_tot_g->fetchAll(PDO::FETCH_ASSOC) as $row)
        $total_map_g[$row['id_colegio']] = $row['total'];
}

$sz_map_g = [];
$sz_ids_g = array_values(array_filter(array_unique(array_column($colegios, 'sub_zona'))));
if (!empty($sz_ids_g)) {
    $ph_sz_g = implode(',', array_fill(0, count($sz_ids_g), '?'));
    $req_sz_g = $bdd->prepare("SELECT id, sub_zona FROM sub_zonas WHERE id IN ($ph_sz_g)");
    $req_sz_g->execute($sz_ids_g);
    foreach ($req_sz_g->fetchAll(PDO::FETCH_ASSOC) as $row)
        $sz_map_g[$row['id']] = $row['sub_zona'];
}

$dep_map_g = [];
foreach ($bdd->query("SELECT id, departamento FROM departamentos")->fetchAll(PDO::FETCH_ASSOC) as $row)
    $dep_map_g[$row['id']] = $row['departamento'];

$costo_ia_cop_g = 0;
$interacciones_g = 0;
$profes_map_g = [];
if ($mostrar_ia_g) {
    $sql_costo_ia = "SELECT mt.id AS id_modelo_tokens, COALESCE(mt.valor_entrada * mt.tokens_entrada + mt.valor_salida * mt.tokens_salida, 0) AS costo_ia
                      FROM ia_modelos m
                      JOIN ia_modelo_tokens mt ON mt.id_modelo = m.id
                      WHERE m.activo = 1
                      ORDER BY mt.id DESC LIMIT 1";
    $modelo_activo_g = $bdd->query($sql_costo_ia)->fetch();
    $costo_ia_g = $modelo_activo_g['costo_ia'] ?? 0;

    $trm_actual_g = $bdd->query("SELECT trm FROM ia_trm ORDER BY fecha DESC, id DESC LIMIT 1")->fetch()['trm'] ?? 0;
    $costo_ia_cop_g = $costo_ia_g * $trm_actual_g;

    $req_interacciones_g = $bdd->prepare("SELECT interacciones FROM ia_presupuestos WHERE id_modelo_tokens=? AND id_periodo=? ORDER BY id DESC LIMIT 1");
    $req_interacciones_g->execute([$modelo_activo_g['id_modelo_tokens'] ?? 0, $_POST['periodo']]);
    $interacciones_g = $req_interacciones_g->fetch()['interacciones'] ?? 0;

    if (!empty($cole_ids_g)) {
        $req_profes_g = $bdd->prepare("SELECT id_colegio, COUNT(*) as total FROM trabajadores_colegios WHERE id_colegio IN ($ph_g) AND cargo=6 AND activo=1 GROUP BY id_colegio");
        $req_profes_g->execute($cole_ids_g);
        foreach ($req_profes_g->fetchAll(PDO::FETCH_ASSOC) as $row)
            $profes_map_g[$row['id_colegio']] = (int)$row['total'];
    }
}
// ── Fin pre-fetch ─────────────────────────────────────────────────

$conta=7;
foreach ($colegios as $colegio) {

    $adopciones = $pres_map_g[$colegio["id"]][$colegio["uid"]] ?? [];
    $vr_val     = $vreal_map_g[$colegio["id"]] ?? null;
    $v_real     = (is_numeric($vr_val) && $vr_val > 0) ? ['venta_real' => $vr_val] : false;

    foreach ($adopciones as $adopcion) {

        if ($adopcion["id_grado"] != 17 && $adopcion["cod_area"] == "") {
            $grado_lookup = $adopcion["id_grado"];
        } else {
            $grado_lookup = $ao_map_g[$colegio['id']][$adopcion["idlibro"]][$adopcion["cod_area"]] ?? 0;
        }

        $alumnos_gp = $gp_map_g[$colegio["id"]][$grado_lookup] ?? 0;

        if ($adopcion["pre_definido"] == 1) {
            $alumnos_tasa = floor($alumnos_gp * $adopcion["tasa_compra"]);
            $precio_neto  = $adopcion["precio"] - ($adopcion["precio"] * $adopcion["descuento"]);
            $valor_presup[$colegio["id"]][] = $precio_neto * $alumnos_tasa;
            $cant_presup[$colegio["id"]][]  = $alumnos_tasa;
        }

        if ($adopcion["definido"] != 0) {
            if ($adopcion["tasa_compra_d"] == 0.00) {
                $alumnos_tasa_d = floor($alumnos_gp * $adopcion["tasa_compra"]);
                $precio_neto_d  = $adopcion["precio"] - ($adopcion["precio"] * $adopcion["descuento"]);
            } else {
                $alumnos_tasa_d = floor($alumnos_gp * $adopcion["tasa_compra_d"]);
                $precio_neto_d  = $adopcion["precio"] - ($adopcion["precio"] * $adopcion["descuento_d"]);
            }

            if (!$v_real) {
                $venta_real[$colegio["id"]][] = $precio_neto_d * $adopcion["uni_vr"];
            }

            $valor_adopcion[$colegio["id"]][] = $precio_neto_d * $alumnos_tasa_d;
            $castigo[$colegio["id"]][]         = $alumnos_tasa_d;
        }

        $alms[$colegio["id"]][] = $alumnos_gp;
    }

    $t_cant_presup[$colegio["id"]]    = array_sum($cant_presup[$colegio["id"]] ?? []);
    $t_valor_presup[$colegio["id"]]   = array_sum($valor_presup[$colegio["id"]] ?? []);
    $t_castigo[$colegio["id"]]        = array_sum($castigo[$colegio["id"]] ?? []);
    $t_valor_adopcion[$colegio["id"]] = array_sum($valor_adopcion[$colegio["id"]] ?? []);
    $t_alms[$colegio["id"]]           = array_sum($alms[$colegio["id"]] ?? []);

    $total_val = $total_map_g[$colegio["id"]] ?? null;

    $conse = $colegio["year"]."-".$colegio["conse"];
    $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$conse");
    if ($colegio["tipouser"] != 6) {
        list($empresa, $n_zona) = explode("/", $colegio["zona"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$empresa");
        $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$n_zona");
        $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$colegio[promotor]");
    } else {
        $sz_nombre_g = $sz_map_g[$colegio["sub_zona"]] ?? '';
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$colegio[promotor]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$sz_nombre_g");
        $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$colegio[responsable]");
    }

    $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$colegio[dane]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$colegio[colegio]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", $dep_map_g[$colegio['departamento']] ?? '');
    $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$colegio[ciudad]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", $t_alms[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", $t_cant_presup[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", $t_valor_presup[$colegio["id"]]);
    $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", $t_castigo[$colegio["id"]]);

    if ($t_valor_adopcion[$colegio["id"]] == 0) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", $colegio["conse"] > 0 ? "Anulada" : 0);
    } else {
        $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", $t_valor_adopcion[$colegio["id"]]);
    }

    if ($v_real) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", $v_real["venta_real"]);
    } else {
        $t_vr = empty($venta_real[$colegio["id"]]) ? 0 : array_sum($venta_real[$colegio["id"]]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", $t_vr);
    }

    $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", $total_val ?? '');

    if ($mostrar_ia_g) {
        $cantidad_profesores_g = $profes_map_g[$colegio["id"]] ?? 0;
        $costo_semanal_g = $costo_ia_cop_g * $interacciones_g * $cantidad_profesores_g;
        $costo_anual_g   = $costo_semanal_g * 53;

        $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", $costo_semanal_g);
        $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", $costo_anual_g);
    }

    $conta++;
}


$objSpreadsheet->getActiveSheet()->getStyle("K7:K$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

$objSpreadsheet->getActiveSheet()->getStyle("L7:L$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

$objSpreadsheet->getActiveSheet()->getStyle("M7:M$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

if ($mostrar_ia_g) {
    $objSpreadsheet->getActiveSheet()->getStyle("P7:P$conta")
              ->getNumberFormat()
              ->setFormatCode(
              '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)'
            );

    $objSpreadsheet->getActiveSheet()->getStyle("Q7:Q$conta")
              ->getNumberFormat()
              ->setFormatCode(
              '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)'
            );
}




foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="Valorización_global.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');