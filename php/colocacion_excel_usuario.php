<?php
/**
 * /php/colocacion_excel_usuario.php
 * Versión "por usuario" del reporte de Colocación: un solo asesor o distribuidor elige el
 * período y descarga SOLO sus propios colegios (misma fuente de datos que
 * includes/colocacion_datos.php, el mismo motor que usa el reporte admin de
 * reporte_colocacion.php / php/colocacion_excel.php), sin el panel de sincronización con World
 * Office ni la posibilidad de asignar colegio a un documento sin cruzar — eso sigue siendo
 * exclusivo del admin en reporte_colocacion.php.
 *
 * Columnas y columnas dinámicas de Fecha/Valor (REM-CEUR y POS) IGUALES a php/colocacion_excel.php
 * (misma función auxiliar de negocio, mismo criterio) — la única diferencia real es que aquí no
 * hay subtotales por Empresa/Cliente (no tienen sentido: es la información de una sola persona),
 * solo una fila de "Total general" al final, igual que la de php/colocacion_excel.php.
 *
 * Alcance (mismo patrón que php/valoriza_global_excel.php):
 *  - tipo 1/2/7 (admin-like): puede elegir un usuario puntual (queda con el alcance de ese
 *    usuario) o "Todos" (sin filtro, reporte completo).
 *  - Cualquier otro tipo (asesor real tipo=3, distribuidor tipo=6, etc.): alcance fijo a su
 *    propia zona ($_SESSION['zona'], igual que el "else" de valoriza_global_excel.php).
 */
require_once("aut.php");

require_once("../conexion/bdd.php");
require_once("../includes/colocacion_datos.php");
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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$tipo_sesion = intval($_SESSION['tipo'] ?? 0);

// ── Alcance: igual criterio que el "else" / rama admin de php/valoriza_global_excel.php ──
if (in_array($tipo_sesion, [1, 2, 7], true)) {
    $usuario_id = intval($_POST['usuario'] ?? -1);
    $cod_zona_scope = null; // "Todos" o sin selección válida: sin filtro.
    if ($usuario_id > 0) {
        $req_u = $bdd->prepare("SELECT cod_zona FROM usuarios WHERE id = ?");
        $req_u->execute([$usuario_id]);
        $cz = $req_u->fetchColumn();
        $cod_zona_scope = ($cz !== false && $cz !== '') ? $cz : null;
    }
} else {
    $cz = $_SESSION['zona'] ?? '';
    $cod_zona_scope = ($cz !== '') ? $cz : null;
}

$periodos = obtener_periodos_colocacion($bdd);
$idsPermitidos = array_column($periodos, 'id');
$id_periodo = null;
if (isset($_POST['periodo']) && in_array((int)$_POST['periodo'], $idsPermitidos, true)) {
    $id_periodo = (int)$_POST['periodo'];
}

$datos = obtener_datos_colocacion($bdd, $id_periodo, $cod_zona_scope);
$filas = $datos['filas'];
usort($filas, function ($a, $b) {
    return [$a['empresa'], $a['colegio']] <=> [$b['empresa'], $b['colegio']];
});

// ── Columnas dinámicas de Fecha/Valor: cuántos pares hacen falta según el colegio con más
// movimientos (mismo criterio que php/colocacion_excel.php) ──
$maxMovimientosWo = 0;
$maxMovimientosPos = 0;
foreach ($filas as $f) {
    $maxMovimientosWo = max($maxMovimientosWo, count($f['colocacion_wo']));
    $maxMovimientosPos = max($maxMovimientosPos, count($f['colocacion_pos']));
}
$maxMovimientosWo = max($maxMovimientosWo, 1);
$maxMovimientosPos = max($maxMovimientosPos, 1);

$encabezados = ['Empresa', 'Colegio', 'Presupuesto Registrado en CRM', 'Adopciones CRM', 'Atenciones a Clientes', 'Población General', 'Compradores Activos', 'Descuento Promedio', 'Número de la Adopción', 'Cliente', 'Factura de Venta', 'Abonos'];
for ($i = 1; $i <= $maxMovimientosWo; $i++) {
    $encabezados[] = "Colocación World Office (REM-CEUR) - Fecha $i";
    $encabezados[] = "Colocación World Office (REM-CEUR) - Valor $i";
}
$colInicioPos = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosPos; $i++) {
    $encabezados[] = "Facturas POS - Fecha $i";
    $encabezados[] = "Facturas POS - Valor $i";
}
$encabezados[] = 'Devoluciones';
$encabezados[] = 'Total Colocado';

$totalColumnas = count($encabezados);
$colDevoluciones = $totalColumnas - 1;
$colDevolucionesLetra = Coordinate::stringFromColumnIndex($colDevoluciones);
$ultimaColumna = Coordinate::stringFromColumnIndex($totalColumnas);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator('Ing. Alejandro Rangel');
$objSpreadsheet->getProperties()->setTitle('Colocación');
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle('Colocacion');
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

$estilo_negrita = ['font' => ['bold' => true]];
$estilo_centrar = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];

$drawing = new Drawing();
$drawing->setName('logo');
$drawing->setDescription('logo');
$drawing->setPath('../vendors/images/logo_eureka.png');
$drawing->setHeight(100);
$drawing->setCoordinates('A1');
$drawing->setWorksheet($hoja);

$hoja->mergeCells('E2:G2');
$hoja->getStyle('E2')->applyFromArray($estilo_negrita);
$hoja->getStyle('E2')->applyFromArray($estilo_centrar);
$hoja->getStyle('H2')->applyFromArray($estilo_negrita);
$hoja->getStyle('H2')->applyFromArray($estilo_centrar);
$hoja->setCellValue('E2', 'REPORTE de colocación');
$hoja->setCellValue('H2', 'Periodo ' . $datos['periodo'] . ' (Calendario ' . $datos['calendario'] . ')');

$hoja->setCellValue('E4', 'Fecha');
$hoja->setCellValue('F4', date('Y-m-d'));
$hoja->getStyle('E4')->applyFromArray($estilo_negrita);

$filaEncabezado = 6;
foreach ($encabezados as $i => $titulo) {
    $col = Coordinate::stringFromColumnIndex($i + 1);
    $hoja->setCellValue("{$col}{$filaEncabezado}", $titulo);
}
$hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']],
]);
$hoja->freezePane('A' . ($filaEncabezado + 1));

// Columnas de dinero (índice base 1) — presupuesto, adopciones, atenciones, factura, abonos, cada
// Valor N de REM y de POS, devoluciones y el total al final. Descuento Promedio (col 8) tiene su
// propio formato (no es moneda), aplicado más abajo.
$columnasDinero = [3, 4, 5, 11, 12];
for ($i = 0; $i < $maxMovimientosWo; $i++) $columnasDinero[] = 14 + ($i * 2);
for ($i = 0; $i < $maxMovimientosPos; $i++) $columnasDinero[] = $colInicioPos + 1 + ($i * 2);
$columnasDinero[] = $colDevoluciones;
$columnasDinero[] = $totalColumnas;

$fila = $filaEncabezado + 1;
$totales = array_fill(0, $totalColumnas + 1, 0);

foreach ($filas as $f) {
    $hoja->setCellValue("A{$fila}", $f['empresa']);
    $hoja->setCellValue("B{$fila}", $f['colegio']);
    $hoja->setCellValue("C{$fila}", $f['presupuesto_crm']);
    $hoja->setCellValue("D{$fila}", $f['adopciones_crm']);
    $hoja->setCellValue("E{$fila}", $f['atenciones_clientes']);
    $hoja->setCellValue("F{$fila}", $f['poblacion_general']);
    $hoja->setCellValue("G{$fila}", $f['compradores_activos']);
    $hoja->setCellValue("H{$fila}", round((float)$f['descuento_promedio'], 2));
    $hoja->setCellValue("I{$fila}", $f['numero_adopcion']);
    $hoja->setCellValue("J{$fila}", $f['cliente']);
    $hoja->setCellValue("K{$fila}", $f['factura_venta']);
    $hoja->setCellValue("L{$fila}", $f['abonos']);

    $colIdx = 13;
    foreach ($f['colocacion_wo'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['valor']);
        $colIdx += 2;
    }

    $colIdx = $colInicioPos;
    foreach ($f['colocacion_pos'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['valor']);
        $colIdx += 2;
    }

    $hoja->setCellValue("{$colDevolucionesLetra}{$fila}", $f['devoluciones']);
    $hoja->setCellValue("{$ultimaColumna}{$fila}", $f['total_colocado']);

    $totales[3] += $f['presupuesto_crm'];
    $totales[4] += $f['adopciones_crm'];
    $totales[5] += $f['atenciones_clientes'];
    $totales[11] += $f['factura_venta'];
    $totales[12] += $f['abonos'];
    $totales[$colDevoluciones] += $f['devoluciones'];
    $totales[$totalColumnas] += $f['total_colocado'];
    $colIdx = 13;
    foreach ($f['colocacion_wo'] as $mov) {
        $totales[$colIdx + 1] += $mov['valor'];
        $colIdx += 2;
    }
    $colIdx = $colInicioPos;
    foreach ($f['colocacion_pos'] as $mov) {
        $totales[$colIdx + 1] += $mov['valor'];
        $colIdx += 2;
    }

    $fila++;
}

// Fila de "Total general" al pie — sin subtotales por Empresa/Cliente (es la información de una
// sola persona, no tiene sentido desglosarla), igual criterio de negocio que la fila final de
// php/colocacion_excel.php.
$hoja->setCellValue("B{$fila}", 'Total general');
$hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
]);
foreach ($columnasDinero as $ci) {
    $hoja->setCellValue(Coordinate::stringFromColumnIndex($ci) . $fila, $totales[$ci]);
}

foreach ($columnasDinero as $ci) {
    $col = Coordinate::stringFromColumnIndex($ci);
    $hoja->getStyle("{$col}{$filaEncabezado}:{$col}{$fila}")->getNumberFormat()->setFormatCode(
        '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)'
    );
}
$hoja->getStyle("H{$filaEncabezado}:H{$fila}")->getNumberFormat()->setFormatCode('0.00"%"');

foreach (range(1, $totalColumnas) as $ci) {
    $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
}

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Colocacion.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
