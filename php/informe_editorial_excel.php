<?php
/**
 * /php/informe_editorial_excel.php
 * "Informe Cumplimiento" por asesor (tipo=3 + Hector Morales, id=69): adopción y presupuesto
 * asignado valorizados, desglosados por editorial (Eureka / McGraw Hill / Otra), con % de
 * cumplimiento, comparación contra el último informe guardado (ver
 * php/informe_editorial_snapshot_cron.php) y cuánto falta para llegar a la meta del 100%.
 * Layout pedido por el usuario 2026-09-18 (ver captura de referencia).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

require_once("../lib/autoload-phpspreadsheet.php");
require_once("../lib/ZipStream/src/Option/Archive.php");
require_once("../lib/MyCLabs/Enum/Enum.php");
require_once("../lib/ZipStream/src/Option/Method.php");
require_once("../lib/ZipStream/src/ZipStream.php");
require_once("../lib/ZipStream/src/Bigint.php");
require_once("../lib/ZipStream/src/Option/File.php");
require_once("../lib/ZipStream/src/File.php");
require_once("../lib/ZipStream/src/Option/Version.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once("../conexion/bdd.php");
require_once("../includes/periodos_fechas.php");
require_once("../includes/informe_editorial_datos.php");

ini_set('memory_limit', '512M');
set_time_limit(120);

asegurar_fechas_periodos($bdd);
$idPeriodo = isset($_REQUEST['periodo']) && $_REQUEST['periodo'] !== ''
    ? (int)$_REQUEST['periodo']
    : obtener_periodo_activo($bdd)['periodoActivo'];

// Una "temporada" cruza los dos calendarios que corren en paralelo (ej. "2027" y "2026B" — ver
// resolver_temporada_informe_editorial()); el informe de un período incluye los datos de su
// pareja, y el título/etiquetas muestran ambos. Pedido por el usuario 2026-09-18.
$periodoLabel = resolver_temporada_informe_editorial($bdd, $idPeriodo)['labelCombinado'];

$datos = obtener_datos_informe_editorial($bdd, $idPeriodo);
$asesores = $datos['asesores'];
$ultimo = obtener_ultimo_snapshot_informe_editorial($bdd, $idPeriodo);
// Presupuesto por temporada ya guardado (ver reporte_editorial.php) — se precarga en la columna J
// en vez de dejarla en blanco, para no tener que volver a escribirlo cada semana. Pedido por el
// usuario 2026-09-18.
$presupuestoTemporadaGuardado = obtener_presupuesto_temporada_informe_editorial($bdd, $idPeriodo);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Informe Cumplimiento");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("INFORME CUMPLIMIENTO");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

// ── Encabezado: logo, título, filtro de período y fecha — mismo patrón ya usado en
// php/backorders_excel.php ────────────────────────────────────────────────────────────
$drawing = new Drawing();
$drawing->setName('logo');
$drawing->setDescription('logo');
$drawing->setPath('../vendors/images/logo_eureka.png');
$drawing->setHeight(70);
$drawing->setCoordinates('A1');
$drawing->setWorksheet($hoja);

$estiloTitulo   = ['font' => ['bold' => true, 'size' => 14]];
$estiloEtiqueta = ['font' => ['bold' => true]];

$hoja->mergeCells('D1:K1');
$hoja->getStyle('D1')->applyFromArray($estiloTitulo);
$hoja->setCellValue('D1', 'INFORME CUMPLIMIENTO ' . $periodoLabel);

$hoja->getStyle('D2')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D2', 'Período:');
$hoja->setCellValue('E2', $periodoLabel);
$hoja->getStyle('D3')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D3', 'Fecha:');
$hoja->setCellValue('E3', date('Y-m-d'));
$hoja->getStyle('D4')->applyFromArray($estiloEtiqueta);
$hoja->setCellValue('D4', 'Objetivo:');
$hoja->setCellValue('E4', 'Pasar de un 100% en cumplimiento');

// ── Encabezados de la tabla (2 filas: grupo + columna) ──────────────────────────────────
$filaGrupo = 8;
$filaCol   = 9;
$filaInicioTabla = $filaGrupo;

$estiloGrupo = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
];
$estiloCol = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCE6F1']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C6E0']]],
];

// Grupos que abarcan varias columnas.
$grupos = [
    ['A', 'A', "INFORME CUMPLIMIENTO {$periodoLabel}"],
    ['B', 'E', "ADOPCIONES {$periodoLabel}"],
    ['F', 'I', "PRESUPUESTO ASIGNADO {$periodoLabel}"],
    ['K', 'M', "CUMPLIMIENTOS"],
];
foreach ($grupos as [$colIni, $colFin, $titulo]) {
    if ($colIni !== $colFin) $hoja->mergeCells("{$colIni}{$filaGrupo}:{$colFin}{$filaGrupo}");
    $hoja->setCellValue("{$colIni}{$filaGrupo}", $titulo);
}
// Columnas de una sola celda que abarcan las DOS filas de encabezado: J (nueva, "Presupuesto
// asignado por temporada") y N..S.
foreach (['J', 'N', 'O', 'P', 'Q', 'R', 'S'] as $col) {
    $hoja->mergeCells("{$col}{$filaGrupo}:{$col}{$filaCol}");
}
$hoja->getStyle("A{$filaGrupo}:S{$filaCol}")->applyFromArray($estiloGrupo);
// Alto explícito: sin esto, las filas de encabezado se quedan con el alto por defecto (~14pt) y el
// texto largo con wrapText (ej. "Valor Pendiente de adopción para llegar a") queda recortado
// dentro de la celda. Reportado por el usuario 2026-09-18.
$hoja->getRowDimension($filaGrupo)->setRowHeight(42);
$hoja->getRowDimension($filaCol)->setRowHeight(28);

$fechaUltimoLabel = $ultimo['fecha'] ? date('d/m/Y', strtotime($ultimo['fecha'])) : 'Sin informes previos';

$titulosCol = [
    'A' => 'Asesores',
    'B' => 'Eureka', 'C' => 'McGraw Hill', 'D' => 'Otra', 'E' => 'Total PPTO',
    'F' => 'Eureka', 'G' => 'McGraw Hill', 'H' => 'Otra', 'I' => 'Total PPTO',
    'J' => 'Presupuesto asignado por temporada',
    'K' => 'Eureka', 'L' => 'McGraw Hill', 'M' => 'Otra',
    'N' => 'TOTAL CUMPLIMIENTO (Informe a Hoy)',
    'O' => "Último informe enviado día {$fechaUltimoLabel}",
    'P' => 'Variación frente al último',
    'Q' => 'Meta llegar a',
    'R' => '% Pendiente por Llegar a Meta',
    'S' => 'Valor Pendiente de adopción para llegar a',
];
foreach ($titulosCol as $col => $titulo) {
    $hoja->setCellValue("{$col}{$filaCol}", $titulo);
}
$hoja->getStyle("A{$filaCol}:S{$filaCol}")->applyFromArray($estiloCol);
// J y N..S ya llevan su título en la fila de grupo (celda combinada verticalmente) — se limpia la
// fila de columna para esas letras para no repetir el texto dos veces.
foreach (['J', 'N', 'O', 'P', 'Q', 'R', 'S'] as $col) $hoja->setCellValue("{$col}{$filaCol}", '');
foreach (['J', 'N', 'O', 'P', 'Q', 'R', 'S'] as $col) $hoja->setCellValue("{$col}{$filaGrupo}", $titulosCol[$col]);

$estiloVerde   = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C6E9C6']]];
$estiloNaranja = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE4B4']]];
$hoja->getStyle("N{$filaGrupo}:N{$filaCol}")->applyFromArray($estiloVerde + ['font' => ['bold' => true]]);
$hoja->getStyle("O{$filaGrupo}:O{$filaCol}")->applyFromArray($estiloNaranja + ['font' => ['bold' => true]]);
// La columna J es de llenado MANUAL (no se calcula) — se resalta distinto para que quede claro que
// es un campo para escribir a mano. Pedido por el usuario 2026-09-18 ("deberás dejar la columna en
// blanco... si hay algún dato se calcula...").
$hoja->getStyle("J{$filaGrupo}:J{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']], 'font' => ['bold' => true]]);

// ── Filas de datos, una por asesor ──────────────────────────────────────────────────────
$fmtMoney = '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)';
// N y S quedan en la MISMA escala que pidió el usuario (números tipo 8.52, ya multiplicados por
// 100 en la fórmula, no fracciones 0.0852) — por eso llevan un formato de texto con "%" literal en
// vez del formato de porcentaje nativo de Excel ('0.00%'), que volvería a multiplicar por 100 y
// mostraría "852.00%" en vez de "8.52%".
$fmtPctYaEscalado = '0.00"%"';
$fila = $filaCol + 1;
$inicioDatos = $fila;

$totales = [
    'adopcion' => ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0],
    'presupuesto' => ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0],
];

foreach ($asesores as $a) {
    $adop = $a['adopcion'];
    $pres = $a['presupuesto'];
    foreach (['eureka', 'mcgraw', 'otra', 'total'] as $k) {
        $totales['adopcion'][$k] += $adop[$k];
        $totales['presupuesto'][$k] += $pres[$k];
    }

    $nombreFila = $a['nombre'];
    if (!$a['activo']) $nombreFila .= ' (inactivo)';
    $hoja->setCellValue("A{$fila}", $nombreFila);
    if (!$a['activo']) $hoja->getStyle("A{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'C0392B']]]);

    $hoja->setCellValue("B{$fila}", $adop['eureka']);
    $hoja->setCellValue("C{$fila}", $adop['mcgraw']);
    $hoja->setCellValue("D{$fila}", $adop['otra']);
    $hoja->setCellValue("E{$fila}", $adop['total']);
    $hoja->setCellValue("F{$fila}", $pres['eureka']);
    $hoja->setCellValue("G{$fila}", $pres['mcgraw']);
    $hoja->setCellValue("H{$fila}", $pres['otra']);
    $hoja->setCellValue("I{$fila}", $pres['total']);
    $hoja->getStyle("B{$fila}:I{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    // J: "Presupuesto asignado por temporada" — se PRECARGA con lo ya guardado (ver
    // reporte_editorial.php) para no tener que volver a escribirlo cada semana; si nadie ha
    // guardado nada para este asesor, queda en blanco como antes (editable a mano igual).
    // Pedido por el usuario 2026-09-18.
    $valorGuardadoJ = $presupuestoTemporadaGuardado[$a['id_usuario']] ?? null;
    if ($valorGuardadoJ !== null) $hoja->setCellValue("J{$fila}", $valorGuardadoJ);
    $hoja->getStyle("J{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    foreach (['eureka' => 'K', 'mcgraw' => 'L', 'otra' => 'M'] as $bucket => $col) {
        $cump = cumplimiento_informe_editorial($adop[$bucket], $pres[$bucket]);
        if ($cump === null) {
            $hoja->setCellValue("{$col}{$fila}", 'Sin Ppto Asignado');
            $hoja->getStyle("{$col}{$fila}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => '94A3B8']]]);
        } else {
            $hoja->setCellValue("{$col}{$fila}", $cump / 100);
            $hoja->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode('0.00%');
        }
    }

    // N: TOTAL CUMPLIMIENTO (Informe a Hoy) — fórmula EN VIVO pedida por el usuario 2026-09-18: si
    // se llena a mano la columna J, el cumplimiento se recalcula contra ESE presupuesto
    // (E÷J×100); mientras J esté vacío sigue usando el presupuesto ya cargado en el CRM (E÷I×100,
    // el mismo cálculo de siempre). Se envuelve en IFERROR por si I llega a ser 0.
    $hoja->setCellValue("N{$fila}", "=IF(J{$fila}=\"\",IFERROR(E{$fila}/I{$fila}*100,0),E{$fila}/J{$fila}*100)");
    $hoja->getStyle("N{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);
    $hoja->getStyle("N{$fila}")->applyFromArray(['font' => ['bold' => true]] + $estiloVerde);

    // $cumpTotal sigue haciendo falta en PHP para la flecha de variación (P, comparada contra el
    // snapshot guardado) y para el color inicial de Meta (Q) — son estilos fijos que reflejan el
    // estado "recién generado" (igual a lo que muestra N mientras J siga vacío) y no se
    // recalculan solos si alguien llena J a mano después.
    $cumpTotal = cumplimiento_informe_editorial($adop['total'], $pres['total']);

    $cumpAnterior = $ultimo['porAsesor'][$a['id_usuario']]['cumplimiento'] ?? null;
    $hoja->setCellValue("O{$fila}", $cumpAnterior !== null ? $cumpAnterior / 100 : '—');
    if ($cumpAnterior !== null) $hoja->getStyle("O{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("O{$fila}")->applyFromArray($estiloNaranja);

    if ($cumpTotal !== null && $cumpAnterior !== null && abs($cumpTotal - $cumpAnterior) >= 0.005) {
        if ($cumpTotal > $cumpAnterior) {
            $hoja->setCellValue("P{$fila}", '▲');
            $hoja->getStyle("P{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '16A34A']]]);
        } else {
            $hoja->setCellValue("P{$fila}", '▼');
            $hoja->getStyle("P{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]]);
        }
    } else {
        $hoja->setCellValue("P{$fila}", '—');
        $hoja->getStyle("P{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'D97706']]]);
    }
    $hoja->getStyle("P{$fila}")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    // Q: Meta llegar a — ahora es FÓRMULA (pedido por el usuario 2026-09-18: R y S la usan como
    // referencia, así que también tiene que reaccionar si N cambia por llenar J). 100 = la meta
    // (100% de cumplimiento); el texto aparece cuando N ya llegó o pasó la meta.
    $hoja->setCellValue("Q{$fila}", "=IF(N{$fila}>=100,\"Esta cumpliendo la meta\",100)");
    $hoja->getStyle("Q{$fila}")->getNumberFormat()->setFormatCode('0"%"');
    $hoja->getStyle("Q{$fila}")->applyFromArray(
        ($cumpTotal !== null && $cumpTotal >= 100)
            ? ['font' => ['italic' => true, 'color' => ['rgb' => '15803D']]]
            : ['font' => ['color' => ['rgb' => 'DC2626']]]
    );

    // R: % Pendiente por Llegar a Meta — fórmula EXACTA pedida por el usuario 2026-09-18:
    // IFERROR((Meta-Cumplimiento)/Meta, "") — da "" (en blanco) cuando Q es texto ("Esta
    // cumpliendo la meta"), porque restarle un número a un texto da error y IFERROR lo atrapa.
    $hoja->setCellValue("R{$fila}", "=IFERROR((Q{$fila}-N{$fila})/Q{$fila},\"\")");
    $hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("R{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    // S: Valor Pendiente de adopción para llegar a — EN PESOS (corregido 2026-09-18: la fórmula
    // literal que dio el usuario, Meta-Cumplimiento, daba puntos porcentuales; pidió que se quede
    // en pesos como antes). Es cuánto dinero falta adoptar para llegar a la meta: presupuesto
    // objetivo (J si está lleno, si no I, el mismo que usa N como denominador) menos lo ya
    // adoptado (E). En blanco cuando ya se cumplió la meta (N>=100); nunca negativo (MAX 0).
    $hoja->setCellValue("S{$fila}", "=IF(N{$fila}>=100,\"\",MAX(0,IF(J{$fila}=\"\",I{$fila}-E{$fila},J{$fila}-E{$fila})))");
    $hoja->getStyle("S{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
    $hoja->getStyle("S{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    $fila++;
}
$finDatos = $fila - 1;

// ── Fila de totales ──────────────────────────────────────────────────────────────────────
$estiloTotal = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$hoja->setCellValue("A{$fila}", 'Total PPTO');
$hoja->setCellValue("B{$fila}", $totales['adopcion']['eureka']);
$hoja->setCellValue("C{$fila}", $totales['adopcion']['mcgraw']);
$hoja->setCellValue("D{$fila}", $totales['adopcion']['otra']);
$hoja->setCellValue("E{$fila}", $totales['adopcion']['total']);
$hoja->setCellValue("F{$fila}", $totales['presupuesto']['eureka']);
$hoja->setCellValue("G{$fila}", $totales['presupuesto']['mcgraw']);
$hoja->setCellValue("H{$fila}", $totales['presupuesto']['otra']);
$hoja->setCellValue("I{$fila}", $totales['presupuesto']['total']);
$hoja->getStyle("B{$fila}:I{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

// J de la fila de totales: suma lo que se haya escrito a mano en las filas de arriba (da 0 si
// nadie ha escrito nada todavía, ya que SUM() de puras celdas vacías es 0, no "").
if ($inicioDatos <= $finDatos) {
    $hoja->setCellValue("J{$fila}", "=SUM(J{$inicioDatos}:J{$finDatos})");
} else {
    $hoja->setCellValue("J{$fila}", 0);
}
$hoja->getStyle("J{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->setCellValue("N{$fila}", "=IF(J{$fila}=0,IFERROR(E{$fila}/I{$fila}*100,0),E{$fila}/J{$fila}*100)");
$hoja->getStyle("N{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);

$hoja->setCellValue("Q{$fila}", "=IF(N{$fila}>=100,\"Esta cumpliendo la meta\",100)");
$hoja->getStyle("Q{$fila}")->getNumberFormat()->setFormatCode('0"%"');
$hoja->setCellValue("R{$fila}", "=IFERROR((Q{$fila}-N{$fila})/Q{$fila},\"\")");
$hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode('0.00%');
// S en pesos, igual que en las filas de asesor (J aquí es la SUMA de la columna, así que se
// compara contra 0 en vez de "").
$hoja->setCellValue("S{$fila}", "=IF(N{$fila}>=100,\"\",MAX(0,IF(J{$fila}=0,I{$fila}-E{$fila},J{$fila}-E{$fila})))");
$hoja->getStyle("S{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->getStyle("A{$fila}:S{$fila}")->applyFromArray($estiloTotal);

if (empty($asesores)) {
    $hoja->setCellValue("A{$inicioDatos}", 'No hay presupuesto/adopción para este período.');
}

$hoja->getStyle("B{$inicioDatos}:S{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle("A{$inicioDatos}:A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$hoja->getStyle("A{$filaCol}:S{$fila}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// Autosize solo para las columnas de datos normales (A-I, K-M): el ancho calculado les queda bien
// solo. J (manual, vacía) y N-S (encabezados largos + fórmulas) llevan ancho FIJO —
// setAutoSize(true) pisa cualquier setWidth() puesto después, así que hay que excluirlas del
// autosize desde el principio en vez de "corregir" su ancho más abajo. Reportado por el usuario
// 2026-09-18 ("el scroll me permita ver todos los datos").
foreach (['A','B','C','D','E','F','G','H','I','K','L','M'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->getColumnDimension('J')->setWidth(22);
$hoja->getColumnDimension('N')->setWidth(20);
$hoja->getColumnDimension('O')->setWidth(24);
$hoja->getColumnDimension('P')->setWidth(14);
$hoja->getColumnDimension('Q')->setWidth(22);
$hoja->getColumnDimension('R')->setWidth(16);
$hoja->getColumnDimension('S')->setWidth(22);
// Sin freezePane: las filas 8-9 (encabezado) quedaban inmovilizadas al hacer scroll hacia abajo y
// tapaban/recortaban la vista de las filas de datos reales — pedido por el usuario 2026-09-18
// ("quita el inmovilizar de esas [filas]").

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="informe_cumplimiento_' . $periodoLabel . '_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
