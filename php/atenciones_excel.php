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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../php/aut.php");
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

$objSpreadsheet->getActiveSheet()->mergeCells('D2:F2');
$objSpreadsheet->getActiveSheet()->getStyle('D2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('D2')->applyFromArray($estilo_centrar);
$objSpreadsheet->getActiveSheet()->SetCellValue("D2", "REPORTE DE ATENCIONES A CLIENTES");

$sql_periodo="SELECT periodo FROM periodos WHERE id='".$_POST["periodo"]."'";

$req_periodo = $bdd->prepare($sql_periodo);
$req_periodo->execute();
$gp_periodo = $req_periodo->fetch();
$fecha=date("Y-m-d");

$objSpreadsheet->getActiveSheet()->getStyle('C4')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('D4')->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("C4", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("D4", "$fecha");

$objSpreadsheet->getActiveSheet()->SetCellValue("A6", "Consecutivo");
$objSpreadsheet->getActiveSheet()->SetCellValue("B6", "Usuario");
$objSpreadsheet->getActiveSheet()->SetCellValue("C6", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("D6", "Promotor");
$objSpreadsheet->getActiveSheet()->SetCellValue("E6", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("F6", "Recurso solicitado");
$objSpreadsheet->getActiveSheet()->SetCellValue("G6", "Tipo");
$objSpreadsheet->getActiveSheet()->SetCellValue("H6", "Categoría");
$objSpreadsheet->getActiveSheet()->SetCellValue("I6", "Valor");
$objSpreadsheet->getActiveSheet()->SetCellValue("J6", "Estado");
$objSpreadsheet->getActiveSheet()->SetCellValue("K6", "Acumulado");
$objSpreadsheet->getActiveSheet()->SetCellValue("L6", "Tipo recurso entregado");
$objSpreadsheet->getActiveSheet()->SetCellValue("M6", "Valor recurso entregado");
$objSpreadsheet->getActiveSheet()->SetCellValue("N6", "fecha recurso entregado");
$objSpreadsheet->getActiveSheet()->SetCellValue("O6", "Legalizado");
$objSpreadsheet->getActiveSheet()->SetCellValue("P6", "Contabilizado");
$objSpreadsheet->getActiveSheet()->SetCellValue("Q6", "Presupuesto");
$objSpreadsheet->getActiveSheet()->SetCellValue("R6", "Valor adopción total");
$objSpreadsheet->getActiveSheet()->SetCellValue("S6", "Venta real");
$objSpreadsheet->getActiveSheet()->SetCellValue("T6", "Fecha y hora de registro");


$objSpreadsheet->getActiveSheet()->getStyle('A6:T6')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '00FF84'
        ]
    ]
]);

if ($_POST['promotor']==0) {

	$sql="SELECT s.id,e.estado,s.fecha, s.solicitante, s.estado as idestado, s.contab, s.conse, c.colegio, c.cod_zona, c.id as cid, r.id as id_recurso, r.recurso, t.tipo, cat.categoria, r.presupuesto, r.tipo_e, r.valor_e, r.fecha_e, r.legaliza, CONCAT(u.nombres,' ', u.apellidos) AS promotor FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado JOIN colegios c ON c.id=s.id_colegio JOIN recursos_solicitados r ON r.id_solicitud=s.id JOIN tipos_recursos t ON t.id=r.tipo JOIN categoria_recursos cat ON cat.id=r.categoria JOIN usuarios u ON u.id=s.usuario WHERE s.id_periodo='".$_POST["periodo"]."' ORDER BY s.id DESC";

}else{

    $sql="SELECT s.id,e.estado,s.fecha, s.solicitante, s.estado as idestado, s.contab, s.conse, c.colegio, c.cod_zona, c.id as cid, r.id as id_recurso, r.recurso, t.tipo, cat.categoria, r.presupuesto, r.tipo_e, r.valor_e, r.fecha_e, r.legaliza, CONCAT(u.nombres,' ', u.apellidos) AS promotor FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado JOIN colegios c ON c.id=s.id_colegio JOIN recursos_solicitados r ON r.id_solicitud=s.id JOIN tipos_recursos t ON t.id=r.tipo JOIN categoria_recursos cat ON cat.id=r.categoria JOIN usuarios u ON u.cod_zona=c.cod_zona WHERE s.id_periodo='".$_POST["periodo"]."' AND u.id='".$_POST["promotor"]."' ORDER BY `s`.`id` DESC";


}



/*$sql = "SELECT e.estado, s.id,s.fecha, s.solicitante, s.cargo, s.fecha_entrega FROM solicitudes_recursos s JOIN estados_pedidos e ON e.id=s.estado WHERE s.id_colegio='".$colegio["id"]."' AND s.id_periodo='".$gp_periodo['id']."' ORDER BY s.id DESC";*/

$req = $bdd->prepare($sql);
$req->execute();
$solicitudes = $req->fetchAll();


// Pre-computar presupuesto, adopción total y venta real por colegio
$colegios_datos = [];
$unique_cids = array_unique(array_column($solicitudes, 'cid'));
foreach ($unique_cids as $cid) {
    $val_presup = 0;
    $val_adopcion = 0;
    $val_venta_real = 0;

    $req_vr = $bdd->prepare("SELECT venta_real FROM recursos WHERE id_colegio=? AND id_periodo=?");
    $req_vr->execute([$cid, $_POST["periodo"]]);
    $v_real = $req_vr->fetch();

    $req_books = $bdd->prepare("SELECT p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d, p.precio, p.pre_definido, p.definido, p.cod_area, p.uni_vr, l.id as idlibro, l.id_grado FROM presupuestos p JOIN libros l ON p.id_libro=l.id WHERE p.id_colegio=? AND (p.pre_definido=1 OR p.definido=1) AND p.id_periodo=?");
    $req_books->execute([$cid, $_POST["periodo"]]);
    $books = $req_books->fetchAll();

    foreach ($books as $book) {
        if ($book["id_grado"] != 17 && $book["cod_area"] == "") {
            $req_gp = $bdd->prepare("SELECT SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio=? AND id_grado=? AND id_periodo=? AND alumnos > 0");
            $req_gp->execute([$cid, $book["id_grado"], $_POST["periodo"]]);
        } else {
            $req_go = $bdd->prepare("SELECT id_grado_otro FROM areas_objetivas WHERE id_colegio=? AND id_libro_eureka=? AND id_periodo=? AND codigo=?");
            $req_go->execute([$cid, $book["idlibro"], $_POST["periodo"], $book["cod_area"]]);
            $grado_o = $req_go->fetch();
            $id_grado_o = $grado_o['id_grado_otro'] ?? 0;
            $req_gp = $bdd->prepare("SELECT SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio=? AND id_grado=? AND id_periodo=? AND alumnos > 0");
            $req_gp->execute([$cid, $id_grado_o, $_POST["periodo"]]);
        }
        $gp = $req_gp->fetch();
        $alumnos = $gp["alumnos"] ?? 0;

        if ($book["pre_definido"] == 1) {
            $precio_neto = $book["precio"] - ($book["precio"] * $book["descuento"]);
            $val_presup += $precio_neto * floor($alumnos * $book["tasa_compra"]);
        }
        if ($book["definido"] != 0) {
            if ($book["tasa_compra_d"] == 0.00) {
                $alumnos_tasa_d = floor($alumnos * $book["tasa_compra"]);
                $precio_neto_d  = $book["precio"] - ($book["precio"] * $book["descuento"]);
            } else {
                $alumnos_tasa_d = floor($alumnos * $book["tasa_compra_d"]);
                $precio_neto_d  = $book["precio"] - ($book["precio"] * $book["descuento_d"]);
            }
            $val_adopcion += $precio_neto_d * $alumnos_tasa_d;
            if (!is_array($v_real) || !isset($v_real['venta_real']) || $v_real['venta_real'] < 1) {
                $val_venta_real += $precio_neto_d * $book["uni_vr"];
            }
        }
    }
    if (is_array($v_real) && isset($v_real['venta_real']) && $v_real['venta_real'] > 0) {
        $val_venta_real = $v_real['venta_real'];
    }
    $colegios_datos[$cid] = ['presupuesto' => $val_presup, 'adopcion' => $val_adopcion, 'venta_real' => $val_venta_real];
}

$conta=7;

foreach ($solicitudes as $solicitud) {

	$objSpreadsheet->getActiveSheet()->getStyle("I$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("K$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle("O$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $sql="SELECT CONCAT(nombres,' ', apellidos) AS promotor FROM usuarios WHERE cod_zona='".$solicitud["cod_zona"]."'";

    $req = $bdd->prepare($sql);
    $req->execute();
    $promo_colegio = $req->fetch();

    $sql = "SELECT tipo FROM tipos_recursos WHERE id='".$solicitud["tipo_e"]."'";

    $req = $bdd->prepare($sql);
    $req->execute();
    $tipo_e = $req->fetch();

    $sql = "SELECT SUM(r.valor_e) as total_e FROM solicitudes_recursos s JOIN recursos_solicitados r ON s.id=r.id_solicitud WHERE s.id_colegio='".$solicitud["cid"]."' AND s.id_periodo='".$_POST["periodo"]."' AND s.estado='4';";

    $req = $bdd->prepare($sql);
    $req->execute();
    $total = $req->fetch();
	
    if ($solicitud["id"] < 221) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$solicitud[id]");
    }else{
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", "$solicitud[conse]");
    }
	
	$objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", "$solicitud[promotor]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", "$solicitud[colegio]");
    if (!empty($promo_colegio["promotor"])) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", "$promo_colegio[promotor]");
    }
	$objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", "$solicitud[fecha]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "$solicitud[recurso]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", "$solicitud[tipo]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "$solicitud[categoria]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$solicitud[presupuesto]");
	$objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", "$solicitud[estado]");
    
    $objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", "$total[total_e]");
    
    
    if (empty($tipo_e["tipo"])) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "");
    }else{
        $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", "$tipo_e[tipo]");
    }
    $objSpreadsheet->getActiveSheet()->getStyle("M$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
    );

    $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", "$solicitud[valor_e]");
    $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", "$solicitud[fecha_e]");

    // Total legalizado desde trazabilidad para este recurso
    $tot_legal_r = 0;
    try {
      $req_lr = $bdd->prepare("SELECT COALESCE(SUM(valor),0) FROM trazabilidad_entregas WHERE id_recurso=? AND tipo='legalizacion'");
      $req_lr->execute([$solicitud["id_recurso"]]);
      $tot_legal_r = (float)$req_lr->fetchColumn();
    } catch (Exception $e) {
      $tot_legal_r = (float)$solicitud["legaliza"];
    }
    $datos_cole = $colegios_datos[$solicitud["cid"]] ?? ['presupuesto' => 0, 'adopcion' => 0, 'venta_real' => 0];
    $fmt_money = '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)';

    // Sub-filas: entregas y legalizaciones desde trazabilidad
    $entregas_traz = [];
    try {
      $req_ent = $bdd->prepare(
        "SELECT valor, fecha, fecha_registro FROM trazabilidad_entregas
         WHERE id_recurso=? AND tipo='entrega' ORDER BY fecha_registro ASC"
      );
      $req_ent->execute([$solicitud["id_recurso"]]);
      $entregas_traz = $req_ent->fetchAll();
    } catch (Exception $e) {}

    $legalizaciones = [];
    try {
      $req_traz = $bdd->prepare(
        "SELECT valor, fecha, fecha_registro FROM trazabilidad_entregas
         WHERE id_recurso=? AND tipo='legalizacion' ORDER BY fecha_registro ASC"
      );
      $req_traz->execute([$solicitud["id_recurso"]]);
      $legalizaciones = $req_traz->fetchAll();
    } catch (Exception $e) {}

    if (!empty($entregas_traz) || !empty($legalizaciones)) {
      // Quitar I, K, M, O, P, Q, R, S de la fila principal (van al total)
      $main_conta = $conta;
      $objSpreadsheet->getActiveSheet()->SetCellValue("I$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("K$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("M$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("O$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("P$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("Q$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("R$main_conta", "");
      $objSpreadsheet->getActiveSheet()->SetCellValue("S$main_conta", "");

      $conse_val = ($solicitud["id"] < 221) ? $solicitud["id"] : $solicitud["conse"];

      // Sub-filas de entregas
      $num_ent = 1;
      foreach ($entregas_traz as $ent) {
        $conta++;
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", $conse_val);
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", $solicitud["promotor"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", $solicitud["colegio"]);
        if (!empty($promo_colegio["promotor"]))
          $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", $promo_colegio["promotor"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", $solicitud["fecha"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "  \u{2514} Entrega #$num_ent  —  " . $solicitud["recurso"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", $solicitud["tipo"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", $solicitud["categoria"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", $solicitud["estado"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", $tipo_e["tipo"] ?? "");
        $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", $ent['fecha']);
        $objSpreadsheet->getActiveSheet()->getStyle("M$conta")->getNumberFormat()->setFormatCode($fmt_money);
        $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", (float)$ent['valor']);
        $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", $ent['fecha_registro']);
        $num_ent++;
      }

      // Sub-filas de legalizaciones
      $num_legal = 1;
      foreach ($legalizaciones as $leg) {
        $conta++;
        $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", $conse_val);
        $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", $solicitud["promotor"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", $solicitud["colegio"]);
        if (!empty($promo_colegio["promotor"]))
          $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", $promo_colegio["promotor"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", $solicitud["fecha"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "  \u{2514} Legalización #$num_legal  —  " . $solicitud["recurso"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("G$conta", $solicitud["tipo"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", $solicitud["categoria"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("J$conta", $solicitud["estado"]);
        $objSpreadsheet->getActiveSheet()->SetCellValue("L$conta", $tipo_e["tipo"] ?? "");
        $objSpreadsheet->getActiveSheet()->SetCellValue("N$conta", $leg['fecha']);
        $objSpreadsheet->getActiveSheet()->getStyle("O$conta")->getNumberFormat()->setFormatCode($fmt_money);
        $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", (float)$leg['valor']);
        $objSpreadsheet->getActiveSheet()->SetCellValue("T$conta", $leg['fecha_registro']);
        $num_legal++;
      }

      // Total entregas para la fila resumen
      $tot_entrega_r = !empty($entregas_traz)
        ? array_sum(array_column($entregas_traz, 'valor'))
        : (float)$solicitud["valor_e"];

      // Fila de total: negrita, con todos los valores numéricos
      $conta++;
      $objSpreadsheet->getActiveSheet()->getStyle("A$conta:T$conta")->applyFromArray(['font' => ['bold' => true]]);
      $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", $conse_val);
      $objSpreadsheet->getActiveSheet()->SetCellValue("F$conta", "  TOTAL  —  " . $solicitud["recurso"]);
      $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", $solicitud["contab"] ? "Si" : "No");
      $objSpreadsheet->getActiveSheet()->getStyle("I$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", $solicitud["presupuesto"]);
      $objSpreadsheet->getActiveSheet()->getStyle("K$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("K$conta", $total["total_e"]);
      $objSpreadsheet->getActiveSheet()->getStyle("M$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("M$conta", $tot_entrega_r);
      $objSpreadsheet->getActiveSheet()->getStyle("O$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", $tot_legal_r);
      $objSpreadsheet->getActiveSheet()->getStyle("Q$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", $datos_cole['presupuesto']);
      $objSpreadsheet->getActiveSheet()->getStyle("R$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("R$conta", $datos_cole['adopcion']);
      $objSpreadsheet->getActiveSheet()->getStyle("S$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", $datos_cole['venta_real']);

    } else {
      // Sin legalizaciones: todo en la fila principal como antes
      $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "");
      if ($solicitud["contab"]==0) {
        $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", "No");
      } else {
        $objSpreadsheet->getActiveSheet()->SetCellValue("P$conta", "Si");
      }
      $objSpreadsheet->getActiveSheet()->getStyle("Q$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("Q$conta", $datos_cole['presupuesto']);
      $objSpreadsheet->getActiveSheet()->getStyle("R$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("R$conta", $datos_cole['adopcion']);
      $objSpreadsheet->getActiveSheet()->getStyle("S$conta")->getNumberFormat()->setFormatCode($fmt_money);
      $objSpreadsheet->getActiveSheet()->SetCellValue("S$conta", $datos_cole['venta_real']);
    }

	$conta++;

    $total_p[]=$solicitud["presupuesto"];

    if ($solicitud["idestado"]==2 || $solicitud["idestado"]==4) {
       $total_a[]=$solicitud["presupuesto"];
    }

    $total_l[]=$tot_legal_r;

}	
$conta++;
if (isset($total_p)) {
    $total_p=array_sum($total_p);
}

if (isset($total_a)) {
    $total_a=array_sum($total_a);
}
if (isset($total_l)) {
    $total_l=array_sum($total_l);
}

$objSpreadsheet->getActiveSheet()->getStyle('H'.$conta)->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('I'.$conta)->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->getStyle('N'.$conta)->applyFromArray($estilo_negrita);

$objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "Total");

$objSpreadsheet->getActiveSheet()->getStyle("I$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

$objSpreadsheet->getActiveSheet()->getStyle("O$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

if (!empty($total_p)) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$total_p");
}

if (!empty($total_l)) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("O$conta", "$total_l");
}



$conta++;

if (isset($total_a)) {

    $objSpreadsheet->getActiveSheet()->getStyle("I$conta")
          ->getNumberFormat()
          ->setFormatCode(
          '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
        );

    $objSpreadsheet->getActiveSheet()->getStyle('G'.$conta)->applyFromArray($estilo_negrita);
    $objSpreadsheet->getActiveSheet()->getStyle('I'.$conta)->applyFromArray($estilo_negrita);

    $objSpreadsheet->getActiveSheet()->SetCellValue("H$conta", "Total aprobado");
    $objSpreadsheet->getActiveSheet()->SetCellValue("I$conta", "$total_a");
}

function excelColumnRange($start, $end) {
    $columns = [];
    $current = $start;
    while ($current !== $end) {
        $columns[] = $current;
        $current++;
    }
    $columns[] = $end;
    return $columns;
}

foreach (range('A', 'Z') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}
foreach (excelColumnRange('AA', 'ZZ') as $columnID) {
  $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);  
}


$objWriter = new Xlsx($objSpreadsheet); //Escribir archivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="Atenciones.xlsx"');


header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
?>