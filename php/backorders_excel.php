<?php
/**
 * /php/backorders_excel.php
 * Exporta la relación de backorders con el mismo layout del archivo manual
 * (C:\Users\USUARIO\Downloads\BACKORDER.xlsx): una columna por pedido con
 * DOCUMENTO/CLIENTE/COLEGIO/# PEDIDO/# OP en las primeras filas, una fila
 * por título de libro, y totales por fila y por columna.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

// Este reporte puede llegar a tener muchas columnas (una por pedido con backorder) — se sube el
// límite de memoria/tiempo igual que otros exportables grandes (ver valoriza_global_excel.php).
ini_set('memory_limit', '2048M');
set_time_limit(180);
// Libera el lock de sesión apenas se confirma el acceso (ver el mismo comentario en
// php/backorders_tabla.php) para que la generación del Excel no bloquee otras pestañas del usuario.
session_write_close();

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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/periodos_fechas.php");

asegurar_fechas_periodos($bdd);
$idPeriodo = isset($_GET['id_periodo']) && $_GET['id_periodo'] !== ''
    ? (int)$_GET['id_periodo']
    : obtener_periodos_backorders($bdd)['periodoActivo'];
$idUsuario = isset($_GET['id_usuario']) && $_GET['id_usuario'] !== '' ? (int)$_GET['id_usuario'] : null;
$idCliente = isset($_GET['id_cliente']) && $_GET['id_cliente'] !== '' ? (int)$_GET['id_cliente'] : null;

$datos = obtener_datos_backorders($bdd, $idPeriodo, $idUsuario, $idCliente);
$columnas = $datos['columnas'];
$filas = $datos['filas'];

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Backorders");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("RELACION PENDIENTES");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

// Etiquetas legibles de los filtros con que se generó este Excel — para que quien lo reciba sepa
// exactamente qué se filtró sin tener que preguntar (pedido por el usuario 2026-09-18).
$periodoLabelExcel = 'Todos';
if ($idPeriodo) {
    $reqPer = $bdd->prepare("SELECT p.periodo, c.calendario FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE p.id = ?");
    $reqPer->execute([$idPeriodo]);
    $rowPer = $reqPer->fetch(PDO::FETCH_ASSOC);
    if ($rowPer) $periodoLabelExcel = $rowPer['periodo'] . ' (Calendario ' . $rowPer['calendario'] . ')';
}
$usuarioLabelExcel = 'Todos';
if ($idUsuario) {
    $reqUsr = $bdd->prepare("SELECT CONCAT(nombres, ' ', apellidos) FROM usuarios WHERE id = ?");
    $reqUsr->execute([$idUsuario]);
    $usuarioLabelExcel = $reqUsr->fetchColumn() ?: $usuarioLabelExcel;
}
$clienteLabelExcel = 'Todos';
if ($idCliente) {
    $reqCli = $bdd->prepare("SELECT cliente FROM clientes WHERE id = ?");
    $reqCli->execute([$idCliente]);
    $clienteLabelExcel = $reqCli->fetchColumn() ?: $clienteLabelExcel;
}

// Encabezado del Excel: logo (mismo que el resto de reportes del CRM), título, filtros
// aplicados y fecha de generación — antes el archivo arrancaba directo en la fila de datos, sin
// forma de saber con qué filtros se había generado. Mismo formato estándar que el resto de
// informes (includes/excel_formato.php): la tabla de datos arranca en la fila 6.
excel_encabezado($hoja, $bdd, 'DETALLE DE BACKORDERS PENDIENTES Y TRAZABILIDAD', 'Periodo: ' . $periodoLabelExcel);
$hoja->setCellValue('D4', 'Asesor: ' . $usuarioLabelExcel . '   ·   Cliente: ' . $clienteLabelExcel);

function col_letra($idx) {
    // 0 = A (título), 1 = B (ISBN), 2 = C (existencias), 3..n+2 = pedidos, última = TOTAL
    $letras = '';
    $idx++;
    while ($idx > 0) {
        $mod = ($idx - 1) % 26;
        $letras = chr(65 + $mod) . $letras;
        $idx = intdiv($idx - 1, 26);
    }
    return $letras;
}

$totalColIdx = count($columnas) + 3; // A=0 título, B=1 ISBN, C=2 EXISTENCIAS, 3..n+2 pedidos, última TOTAL
$colTotalLetra = col_letra($totalColIdx);
$colIsbnLetra = col_letra(1);
$colExistenciasLetra = col_letra(2);

$estiloEncabezado = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']]];
$estiloTotal = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']]];
// Fila hermana de "solicitado" (debajo de cada título): gris e itálica para distinguirla a simple
// vista de la fila de PENDIENTE, sin dejar de ser una fila normal (se puede sumar/filtrar en Excel).
$estiloSolicitado = ['font' => ['italic' => true, 'color' => ['rgb' => '6B7280']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']]];

// Mismos umbrales de color que la vista web y el reporte por título (rojo/amarillo/verde), para
// que la prioridad se note a simple vista en el Excel también.
function estilo_cumplimiento_backorders($pct) {
    if ($pct < 50)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['color' => ['rgb' => '991B1B']]];
    if ($pct < 80)  return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['color' => ['rgb' => '854D0E']]];
    return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']], 'font' => ['color' => ['rgb' => '166534']]];
}
// Despachado en World Office ÷ solicitado — ver porcentaje_cumplimiento_backorder().
function cumplimiento_backorders($solicitada, $despachada) {
    return porcentaje_cumplimiento_backorder($solicitada, $despachada);
}

$filaInicioTabla = EXCEL_FILA_ENCABEZADOS;
$filaExcel = $filaInicioTabla;
$etiquetas = [
    ['CONSECUTIVO→', 'consecutivo'],
    ['DOCUMENTO→', 'documento'],
    ['CLIENTE→', 'cliente'],
    ['COLEGIO→', 'colegio'],
    ['# PEDIDO→', 'pedidos_txt'],
    ['# OP→', 'op'],
];
foreach ($etiquetas as $et) {
    $hoja->setCellValue('A' . $filaExcel, $et[0]);
    foreach ($columnas as $i => $col) {
        $hoja->setCellValue(col_letra($i + 3) . $filaExcel, $col[$et[1]]);
    }
    $hoja->setCellValue($colTotalLetra . $filaExcel, $et[0] === 'DOCUMENTO→' ? 'TOTAL' : '');
    $hoja->getStyle('A' . $filaExcel . ':' . $colTotalLetra . $filaExcel)->applyFromArray($estiloEncabezado);
    $filaExcel++;
}

// % de cumplimiento por pedido (despachado ÷ solicitado de todo el pedido) — misma columna que
// ya se ve en la tabla web, para priorizar aquí mismo qué pedido urge más. El estilo de color
// (rojo/amarillo/verde) se aplica DESPUÉS del estilo de encabezado, celda por celda, para que
// este último no lo tape (applyFromArray sobre todo el rango pisaría el relleno de color).
$filaCumplimiento = $filaExcel;
$hoja->setCellValue('A' . $filaCumplimiento, '% CUMPLIMIENTO→');
$hoja->getStyle('A' . $filaCumplimiento)->applyFromArray($estiloEncabezado);

foreach ($columnas as $i => $col) {
    $pct = cumplimiento_backorders($col['total_solicitada'], $col['total_despachada']);
    $coord = col_letra($i + 3) . $filaCumplimiento;
    $hoja->setCellValue($coord, $pct / 100);
    $hoja->getStyle($coord)->applyFromArray(estilo_cumplimiento_backorders($pct));
}
$totalSolicitadaGeneral = array_sum(array_column($columnas, 'total_solicitada'));
$totalDespachadaGeneral = array_sum(array_column($columnas, 'total_despachada'));
$pctGeneral = cumplimiento_backorders($totalSolicitadaGeneral, $totalDespachadaGeneral);
$hoja->setCellValue($colTotalLetra . $filaCumplimiento, $pctGeneral / 100);
$hoja->getStyle($colTotalLetra . $filaCumplimiento)->applyFromArray(estilo_cumplimiento_backorders($pctGeneral));
$hoja->getStyle(col_letra(3) . $filaCumplimiento . ':' . $colTotalLetra . $filaCumplimiento)->getNumberFormat()->setFormatCode('0.0%');
$filaExcel++;

$hoja->setCellValue('A' . $filaExcel, '↓TITULO↓');
$hoja->setCellValue($colIsbnLetra . $filaExcel, 'ISBN');
$hoja->setCellValue($colExistenciasLetra . $filaExcel, 'EXISTENCIAS');
$hoja->getStyle('A' . $filaExcel . ':' . $colExistenciasLetra . $filaExcel)->applyFromArray($estiloEncabezado);
$filaExcel++;

// Cada título ocupa DOS filas: la de PENDIENTE (lo que falta por surtir) y, justo debajo, la de
// SOLICITADO (lo que traía el pedido originalmente) — así la cantidad solicitada queda visible
// directamente en la hoja (no solo como comentario al pasar el mouse), en las mismas columnas por
// pedido y con su propia columna TOTAL, igual que la fila de pendiente.
$inicioDatos = $filaExcel;
foreach ($filas as $fila) {
    $filaPendiente  = $filaExcel;
    $filaSolicitado = $filaExcel + 1;

    $hoja->setCellValue('A' . $filaPendiente, $fila['titulo']);
    $hoja->setCellValue('A' . $filaSolicitado, '    Solicitado');
    // ISBN como texto: si se guarda como número, Excel lo muestra en notación científica.
    $hoja->setCellValueExplicit($colIsbnLetra . $filaPendiente, $fila['isbn'] !== '' ? $fila['isbn'] : '—', DataType::TYPE_STRING);

    // Existencia real en bodega General de World Office para este título — solo en la fila de
    // PENDIENTE (es una propiedad del título, no algo que tenga sentido repetir en "Solicitado").
    if ($fila['existencia'] !== null) {
        $hoja->setCellValue($colExistenciasLetra . $filaPendiente, $fila['existencia']);
        if ($fila['stock_bajo']) {
            $hoja->getStyle($colExistenciasLetra . $filaPendiente)->applyFromArray(
                ['font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]]
            );
        }
    } else {
        $hoja->setCellValue($colExistenciasLetra . $filaPendiente, '—');
    }

    foreach ($columnas as $i => $col) {
        $v = $fila['valores'][$col['clave']] ?? null;
        if ($v) {
            $letra = col_letra($i + 3);
            $hoja->setCellValue($letra . $filaPendiente, $v['pendiente']);
            $hoja->setCellValue($letra . $filaSolicitado, $v['solicitada']);
        }
    }
    $hoja->setCellValue($colTotalLetra . $filaPendiente, $fila['total']);
    $hoja->setCellValue($colTotalLetra . $filaSolicitado, $fila['total_solicitada']);

    $hoja->getStyle('A' . $filaSolicitado . ':' . $colTotalLetra . $filaSolicitado)->applyFromArray($estiloSolicitado);

    $filaExcel += 2;
}
$finDatos = $filaExcel - 1;

foreach ($columnas as $i => $col) {
    $letra = col_letra($i + 3);
    $hoja->setCellValue($letra . $filaExcel, $col['total']);
    $hoja->setCellValue($letra . ($filaExcel + 1), $col['total_solicitada']);
}
$totalGeneral = array_sum(array_column($columnas, 'total'));
$totalGeneralSolicitada = array_sum(array_column($columnas, 'total_solicitada'));
$hoja->setCellValue('A' . $filaExcel, 'TOTAL');
$hoja->setCellValue($colTotalLetra . $filaExcel, $totalGeneral);
$hoja->getStyle('A' . $filaExcel . ':' . $colTotalLetra . $filaExcel)->applyFromArray($estiloTotal);

$filaExcel++;
$hoja->setCellValue('A' . $filaExcel, '    Solicitado');
$hoja->setCellValue($colTotalLetra . $filaExcel, $totalGeneralSolicitada);
$hoja->getStyle('A' . $filaExcel . ':' . $colTotalLetra . $filaExcel)->applyFromArray($estiloSolicitado);

if (empty($filas)) {
    $hoja->setCellValue('A' . $filaExcel, 'No hay backorders pendientes.');
}

$hoja->getStyle('A' . $filaInicioTabla . ':' . $colTotalLetra . $filaExcel)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle('A' . $filaInicioTabla . ':A' . $filaExcel)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

for ($i = 0; $i <= $totalColIdx; $i++) {
    $hoja->getColumnDimension(col_letra($i))->setAutoSize(true);
}
$hoja->freezePane('D' . $inicioDatos);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="backorders_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
