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
ini_set('memory_limit', '200000M');
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
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Responsable");
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
$objSpreadsheet->getActiveSheet()->SetCellValue("M6", "Valor Presupuestado");
$objSpreadsheet->getActiveSheet()->SetCellValue("N6", "Cantidades Adopciones");
$objSpreadsheet->getActiveSheet()->SetCellValue("O6", "Valor Adopciones");
$objSpreadsheet->getActiveSheet()->SetCellValue("P6", "Unidades Venta Real");
$objSpreadsheet->getActiveSheet()->SetCellValue("Q6", "Venta Real");
$objSpreadsheet->getActiveSheet()->SetCellValue("R6", "Muestras entregadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("S6", "Valor atenciones entregadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("T6", "Total visitas ejecutadas");
$objSpreadsheet->getActiveSheet()->SetCellValue("U6", "Status");


$objSpreadsheet->getActiveSheet()->getStyle('A6:U6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

if ($_SESSION['tipo']==1 || $_SESSION['tipo']==2 || $_SESSION['tipo']==7) {
    
    if ($_POST['promotor']!=0) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_POST['promotor']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

 

    }else{
        $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";
     
    }

}elseif($_SESSION['tipo']==3) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."' AND p.id_usuario='".$_SESSION['id']."'   AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

}elseif($_SESSION['tipo']==10) {

    $sql ="SELECT z.zona,c.id, c.colegio, c.departamento, c.ciudad, c.dane, c.sub_zona, c.responsable, CONCAT(u.nombres, ' ',u.apellidos) as promotor, u.tipo as tipouser, l.id as idlibro, l.libro, l.id_grado,l.id_materia, l.etiqueta, p.precio, p.tasa_compra, p.descuento,p.tasa_compra_d,p.descuento_d, p.pre_definido, p.definido, p.cod_area, p.uni_vr, e.editorial FROM colegios c JOIN presupuestos p ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_usuario JOIN libros l ON p.id_libro=l.id JOIN editoriales e ON l.editorial=e.id JOIN zonas z ON z.codigo=c.cod_zona  WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo='".$_POST['periodo']."'  AND (c.cod_zona='".$_SESSION['zona']."' OR c.zona_madre='".$_SESSION['zona']."')  AND p.probabilidad !=3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00) GROUP BY p.id ORDER BY u.tipo, p.id_usuario, p.id_colegio, l.libro";

}





/*$sql = "SELECT e.estado, s.id,s.fecha, s.solicitante, s.cargo, s.fecha_entrega FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$gp_periodo['id']."' ORDER BY s.id DESC";*/

$req = $bdd->prepare($sql);
$req->execute();
$colegios = $req->fetchAll();


$conta=7;

foreach ($colegios as$colegio) {

    

    if ($colegio["id_grado"] != 17) {
        $sq_gp = "SELECT  SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$colegio["id"]."' AND id_grado='".$colegio["id_grado"]."' AND id_periodo='".$_POST['periodo']."' AND alumnos > 0";

        $sql_gra = "SELECT grado FROM grados WHERE id='".$colegio["id_grado"]."'";
            
        $req_gra = $bdd->prepare($sql_gra);
        $req_gra->execute();
        $n_grado = $req_gra->fetch();

    }else{

       
        $sql_go = "SELECT id_grado_otro FROM areas_objetivas WHERE id_colegio='".$colegio['id']."' AND id_libro_eureka='".$colegio["idlibro"]."' AND id_periodo='".$_POST['periodo']."' AND codigo='".$colegio["cod_area"]."'";
            
        $req_go = $bdd->prepare($sql_go);
        $req_go->execute();
        $grado_o = $req_go->fetch();

        $sq_gp = "SELECT  SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$colegio["id"]."' AND id_grado='".$grado_o["id_grado_otro"]."' AND id_periodo='".$_POST['periodo']."' AND alumnos > 0";

        $sql_gra = "SELECT grado FROM grados WHERE id='".$grado_o["id_grado_otro"]."'";
            
        $req_gra = $bdd->prepare($sql_gra);
        $req_gra->execute();
        $n_grado = $req_gra->fetch();
    }



        $req_gp = $bdd->prepare($sq_gp);
        $req_gp->execute();
        $gp = $req_gp->fetch();


    if ($colegio["pre_definido"] ==1) {

        $alumnos_tasa= floor($gp["alumnos"] * $colegio["tasa_compra"]);
        $precio_neto=$colegio["precio"] - ($colegio["precio"] * $colegio["descuento"]);
         $venta_ppto=$precio_neto * $alumnos_tasa;

        $alumnos_tasa_d=0;
        $precio_neto_d=0;
        $venta_ppto_d=0;
    }


    if ($colegio["definido"] !=0) {
        if ($colegio["tasa_compra_d"] == 0.00) {
            $alumnos_tasa_d= floor($gp["alumnos"] * $colegio["tasa_compra"]);
            $precio_neto_d=$colegio["precio"] - ($colegio["precio"] * $colegio["descuento"]);
           
        }else{
            $alumnos_tasa_d= floor($gp["alumnos"] * $colegio["tasa_compra_d"]);
            $precio_neto_d=$colegio["precio"] - ($colegio["precio"] * $colegio["descuento_d"]);
            
        }

        $venta_ppto_d=$precio_neto_d * $alumnos_tasa_d;

        $venta_real= $precio_neto_d * $colegio["uni_vr"];

    }
   
   $sql="SELECT SUM(l.cantidad) as cant FROM libros_muestreos_e l JOIN muestreos_e m ON l.cod_muestreo=m.codigo WHERE m.id_periodo='".$_POST['periodo']."' AND m.id_colegio='".$colegio["id"]."' AND l.id_libro='".$colegio["idlibro"]."'";
    
    $req = $bdd->prepare($sql);
    $req->execute();
    $muestras = $req->fetch();

    $sql = "SELECT SUM(r.legaliza) as total FROM solicitudes_recursos s JOIN recursos_solicitados r ON s.id=r.id_solicitud WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$_POST['periodo']."' AND s.estado='4'";

    $req = $bdd->prepare($sql);
    $req->execute();
    $total = $req->fetch();

    $sql = "SELECT COUNT(id) as ejecu FROM plan_trabajo WHERE id_colegio='".$colegio["id"]."' AND id_periodo='".$_POST['periodo']."' AND resultado='1'";
    $req = $bdd->prepare($sql);
    $req->execute();
    $ejecutadas = $req->fetch();

    $sql_st = "SELECT status FROM colegios_status cs JOIN status_cubrimiento s ON cs.id_status=s.id WHERE cs.id_colegio='".$colegio["id"]."' AND cs.id_periodo='".$_POST['periodo']."' ORDER BY FIELD (cs.id_status,'6','5','1','2','3','4')";
    $req_st = $bdd->prepare($sql_st);
    $req_st->execute();
    $status = $req_st->fetch();

    if (empty($status)) {

        $sql_st = "SELECT status FROM colegios_status cs JOIN status_cubrimiento s ON cs.id_status=s.id WHERE cs.id_colegio='".$colegio["id"]."' AND s.id != 4 ORDER BY cs.id_periodo DESC";
        $req_st = $bdd->prepare($sql_st);
        $req_st->execute();
        $status2 = $req_st->fetch();

    }

    if ($colegio["tipouser"]!=6) {
        list($empresa,$n_zona) = explode("/", $colegio["zona"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$empresa");
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$colegio[promotor]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$n_zona");
    }else{

        $sql_sz="SELECT sub_zona FROM sub_zonas WHERE id='".$colegio["sub_zona"]."'";
        $req_sz = $bdd->prepare($sql_sz);
        $req_sz->execute();
        $sub_zona = $req_sz->fetch();

        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$colegio[promotor]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$sub_zona[sub_zona]");
        $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$colegio[responsable]");
    }
    
    $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$colegio[dane]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$colegio[colegio]");

    $sql_dep="SELECT departamento FROM departamentos WHERE id='".$colegio['departamento']."' ";
    $req_dep = $bdd->prepare($sql_dep);
    $req_dep->execute();
    $dep = $req_dep->fetch();

    $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$dep[departamento]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$colegio[ciudad]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$colegio[editorial]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$colegio[etiqueta]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$n_grado[grado]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$colegio[libro]");
    if ($colegio["pre_definido"] ==1) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$alumnos_tasa");
        $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$venta_ppto");
    }
    $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$alumnos_tasa_d");
    $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "$venta_ppto_d");
    $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", "$colegio[uni_vr]");
    if ($colegio["definido"] !=0) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", "$venta_real");
    }else{
        $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", "0");
    }

    $objSpreadsheet->getActiveSheet()->SetCellValue("R$conta", "$muestras[cant]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", "$total[total]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", "$ejecutadas[ejecu]");

    if (!empty($status)) {
        
        $objSpreadsheet->getActiveSheet()->SetCellValue("U$conta", "$status[status]");

    }elseif(!empty($status2)){

        $objSpreadsheet->getActiveSheet()->SetCellValue("U$conta", "$status2[status]");

    }else{

        $objSpreadsheet->getActiveSheet()->SetCellValue("U$conta", "Por definir");
    }

    $conta++;


}   


$objSpreadsheet->getActiveSheet()->getStyle("M7:M$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("O7:O$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("Q7:Q$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("S7:S$conta")
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