<?php
/**
 * /php/informe_editorial_excel.php
 * "Informe Cumplimiento" por asesor (tipo=3 + Hector Morales, id=69): adopción y presupuesto
 * asignado valorizados, desglosados por editorial (Eureka / McGraw Hill / Otra), con % de
 * cumplimiento, comparación contra el último informe guardado (ver
 * php/informe_editorial_snapshot_cron.php) y cuánto falta para llegar a la meta del 100%.
 * Layout pedido por el usuario 2026-09-18 (ver captura de referencia). Columnas B-E "Venta real
 * temporada {año anterior}" agregadas 2026-09-22 y desglosadas por editorial 2026-10-02 (ver
 * obtener_venta_real_temporada_anterior()).
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
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
asegurar_snapshot_semanal_informe_editorial($bdd, $idPeriodo);
$ultimo = obtener_ultimo_snapshot_informe_editorial($bdd, $idPeriodo);
// Presupuesto por temporada ya guardado (ver reporte_editorial.php) — se precarga en la columna N
// en vez de dejarla en blanco, para no tener que volver a escribirlo cada semana. Pedido por el
// usuario 2026-09-18.
$presupuestoTemporadaGuardado = obtener_presupuesto_temporada_informe_editorial($bdd, $idPeriodo);
// Venta real de la temporada ANTERIOR por asesor (ej. pedir 2027 trae 2026+2025B) — pedido por el
// usuario 2026-09-22 para la nueva columna B, de referencia frente a lo que se está adoptando hoy.
$ventaRealAnterior = obtener_venta_real_temporada_anterior($bdd, $idPeriodo);
// Títulos pedidos por el usuario 2026-10-05: el bloque se llama "Temporada {año}" y su desglose
// "Venta real por editorial {año}".
$anioVentaReal = $ventaRealAnterior['anioAnterior'] !== null ? (string)$ventaRealAnterior['anioAnterior'] : 'anterior';
$labelVentaRealAnterior = 'Temporada ' . $anioVentaReal;

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
excel_encabezado($hoja, $bdd, 'INFORME CUMPLIMIENTO ' . $periodoLabel, 'Periodo: ' . $periodoLabel);
$hoja->setCellValue('D4', 'Objetivo: Pasar de un 100% en cumplimiento');

// ── Encabezados de la tabla (2 filas: grupo + columna) ──────────────────────────────────
// Layout de columnas (2026-10-02: la venta real pasó de una sola columna B a B-E desglosada por
// editorial, todo lo demás corrido +3 letras; 2026-10-05: el usuario pidió el orden Venta real →
// Presupuesto → Adopciones): A=Asesores, B-E=Venta real temporada anterior, F-I=PRESUPUESTO
// ASIGNADO, J-M=ADOPCIONES, N=Presupuesto por temporada (manual), O-Q=Cumplimientos, R-W=métricas
// de cumplimiento/variación/meta. Debajo: desglose de "Otra" por editorial y, si la temporada
// tiene presupuesto oficial cargado, la comparación oficial vs. CRM.
$filaGrupo = EXCEL_FILA_ENCABEZADOS;
$filaCol   = EXCEL_FILA_ENCABEZADOS + 1;
$filaInicioTabla = $filaGrupo;

$estiloGrupo = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
];
$estiloCol = [
    'font' => ['bold' => true, 'color' => ['rgb' => '1F2937']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCE6F1']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C6E0']]],
];

// Grupos que abarcan varias columnas.
$grupos = [
    ['A', 'A', "INFORME CUMPLIMIENTO {$periodoLabel}"],
    ['B', 'E', $labelVentaRealAnterior],
    ['F', 'I', 'Presupuesto'],
    ['J', 'M', 'Adopciones por editorial'],
    ['O', 'Q', "CUMPLIMIENTOS"],
];
foreach ($grupos as [$colIni, $colFin, $titulo]) {
    if ($colIni !== $colFin) $hoja->mergeCells("{$colIni}{$filaGrupo}:{$colFin}{$filaGrupo}");
    $hoja->setCellValue("{$colIni}{$filaGrupo}", $titulo);
}
// Columnas de una sola celda que abarcan las DOS filas de encabezado: N ("Presupuesto asignado por
// temporada") y R..W.
foreach (['N', 'R', 'S', 'T', 'U', 'V', 'W'] as $col) {
    $hoja->mergeCells("{$col}{$filaGrupo}:{$col}{$filaCol}");
}
$hoja->getStyle("A{$filaGrupo}:W{$filaCol}")->applyFromArray($estiloGrupo);
// Alto explícito: sin esto, las filas de encabezado se quedan con el alto por defecto (~14pt) y el
// texto largo con wrapText (ej. "Valor Pendiente de adopción para llegar a") queda recortado
// dentro de la celda. Reportado por el usuario 2026-09-18.
$hoja->getRowDimension($filaGrupo)->setRowHeight(42);
$hoja->getRowDimension($filaCol)->setRowHeight(28);

$fechaUltimoLabel = $ultimo['fecha'] ? date('d/m/Y', strtotime($ultimo['fecha'])) : 'Sin informes previos';

$titulosCol = [
    'A' => 'Asesores',
    'B' => 'Eureka', 'C' => 'McGraw Hill', 'D' => 'Otra', 'E' => 'Total venta real',
    'F' => 'Eureka', 'G' => 'McGraw Hill', 'H' => 'Otra', 'I' => 'Total PPTO',
    'J' => 'Eureka', 'K' => 'McGraw Hill', 'L' => 'Otra', 'M' => 'Total adopciones',
    'N' => 'Presupuesto asignado por temporada',
    'O' => 'Eureka', 'P' => 'McGraw Hill', 'Q' => 'Otra',
    'R' => 'TOTAL CUMPLIMIENTO (Informe a Hoy)',
    'S' => "Último informe enviado día {$fechaUltimoLabel}",
    'T' => 'Variación frente al último',
    'U' => 'Meta llegar a',
    'V' => '% Pendiente por Llegar a Meta',
    'W' => 'Valor Pendiente de adopción para llegar a',
];
foreach ($titulosCol as $col => $titulo) {
    $hoja->setCellValue("{$col}{$filaCol}", $titulo);
}
$hoja->getStyle("A{$filaCol}:W{$filaCol}")->applyFromArray($estiloCol);
// N y R..W ya llevan su título en la fila de grupo (celda combinada verticalmente) — se limpia
// la fila de columna para esas letras para no repetir el texto dos veces.
foreach (['N', 'R', 'S', 'T', 'U', 'V', 'W'] as $col) $hoja->setCellValue("{$col}{$filaCol}", '');
foreach (['N', 'R', 'S', 'T', 'U', 'V', 'W'] as $col) $hoja->setCellValue("{$col}{$filaGrupo}", $titulosCol[$col]);

$estiloVerde   = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C6E9C6']]];
$estiloNaranja = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE4B4']]];
$hoja->getStyle("R{$filaGrupo}:R{$filaCol}")->applyFromArray($estiloVerde + ['font' => ['bold' => true]]);
$hoja->getStyle("S{$filaGrupo}:S{$filaCol}")->applyFromArray($estiloNaranja + ['font' => ['bold' => true]]);
// La columna N es de llenado MANUAL (no se calcula) — se resalta distinto para que quede claro que
// es un campo para escribir a mano. Pedido por el usuario 2026-09-18 ("deberás dejar la columna en
// blanco... si hay algún dato se calcula...").
$hoja->getStyle("N{$filaGrupo}:N{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']], 'font' => ['bold' => true]]);
// Las columnas B-E son dato de REFERENCIA (temporada ya cerrada, no se recalcula con lo que se
// llene hoy) — se resaltan con un tono distinto (lavanda) para diferenciarlas de las columnas de
// la temporada vigente. Pedido por el usuario 2026-09-22.
$hoja->getStyle("B{$filaGrupo}:E{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4DFF7']], 'font' => ['bold' => true]]);

// ── Filas de datos, una por asesor ──────────────────────────────────────────────────────
$fmtMoney = '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)';
// R y U quedan en la MISMA escala que pidió el usuario (números tipo 8.52, ya multiplicados por
// 100 en la fórmula, no fracciones 0.0852) — por eso llevan un formato de texto con "%" literal en
// vez del formato de porcentaje nativo de Excel ('0.00%'), que volvería a multiplicar por 100 y
// mostraría "852.00%" en vez de "8.52%".
$fmtPctYaEscalado = '0.00"%"';
$fila = $filaCol + 1;
$inicioDatos = $fila;
$filaPorAsesor = []; // id_usuario => fila del cuadro principal
// "PPTO 25-26" por asesor (ver PRESUPUESTO_OFICIAL_INFORME_EDITORIAL, imagen "presupuesto.jpeg" del
// usuario 2026-10-05). Vacío si la temporada no lo tiene cargado: el informe queda como siempre.
$presupuestoOficial = obtener_presupuesto_oficial_informe_editorial($bdd, $idPeriodo);

$totales = [
    'adopcion' => ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0],
    'presupuesto' => ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0],
];
$totalesVentaReal = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0];

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
    $filaPorAsesor[$a['id_usuario']] = $fila;
    if (!$a['activo']) $hoja->getStyle("A{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'C0392B']]]);

    // B-E: Venta real de la temporada ANTERIOR por editorial (referencia, no forma parte de ningún
    // cálculo de cumplimiento de la temporada vigente) — 0 si el asesor no tuvo venta real
    // registrada. Desglose por editorial pedido por el usuario 2026-10-02.
    $venta = $ventaRealAnterior['porAsesor'][$a['id_usuario']] ?? ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0];
    foreach ($totalesVentaReal as $k => $_) $totalesVentaReal[$k] += $venta[$k];
    $hoja->setCellValue("B{$fila}", $venta['eureka']);
    $hoja->setCellValue("C{$fila}", $venta['mcgraw']);
    $hoja->setCellValue("D{$fila}", $venta['otra']);
    $hoja->setCellValue("E{$fila}", $venta['total']);
    $hoja->getStyle("B{$fila}:E{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    $hoja->setCellValue("F{$fila}", $pres['eureka']);
    $hoja->setCellValue("G{$fila}", $pres['mcgraw']);
    $hoja->setCellValue("H{$fila}", $pres['otra']);
    $hoja->setCellValue("I{$fila}", $pres['total']);
    $hoja->setCellValue("J{$fila}", $adop['eureka']);
    $hoja->setCellValue("K{$fila}", $adop['mcgraw']);
    $hoja->setCellValue("L{$fila}", $adop['otra']);
    $hoja->setCellValue("M{$fila}", $adop['total']);
    $hoja->getStyle("F{$fila}:M{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    // N: "Presupuesto asignado por temporada" — se PRECARGA con lo ya guardado (ver
    // reporte_editorial.php) para no tener que volver a escribirlo cada semana; si nadie ha
    // guardado nada para este asesor, queda en blanco como antes (editable a mano igual).
    // Pedido por el usuario 2026-09-18.
    $valorGuardadoK = $presupuestoTemporadaGuardado[$a['id_usuario']] ?? null;
    if ($valorGuardadoK !== null) $hoja->setCellValue("N{$fila}", $valorGuardadoK);
    $hoja->getStyle("N{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    foreach (['eureka' => 'O', 'mcgraw' => 'P', 'otra' => 'Q'] as $bucket => $col) {
        $cump = cumplimiento_informe_editorial($adop[$bucket], $pres[$bucket]);
        if ($cump === null) {
            $hoja->setCellValue("{$col}{$fila}", 'Sin Ppto Asignado');
            $hoja->getStyle("{$col}{$fila}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => '94A3B8']]]);
        } else {
            $hoja->setCellValue("{$col}{$fila}", $cump / 100);
            $hoja->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode('0.00%');
        }
    }

    // R: TOTAL CUMPLIMIENTO (Informe a Hoy) — fórmula EN VIVO pedida por el usuario 2026-09-18: si
    // se llena a mano la columna N, el cumplimiento se recalcula contra ESE presupuesto
    // (M÷N×100); mientras N esté vacío sigue usando el presupuesto ya cargado en el CRM (M÷I×100,
    // el mismo cálculo de siempre). Se envuelve en IFERROR por si I llega a ser 0.
    $hoja->setCellValue("R{$fila}", "=IF(N{$fila}=\"\",IFERROR(M{$fila}/I{$fila}*100,0),M{$fila}/N{$fila}*100)");
    $hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);
    $hoja->getStyle("R{$fila}")->applyFromArray(['font' => ['bold' => true]] + $estiloVerde);

    // $cumpTotal sigue haciendo falta en PHP para la flecha de variación (T, comparada contra el
    // snapshot guardado) y para el color inicial de Meta (U) — son estilos fijos que reflejan el
    // estado "recién generado" (igual a lo que muestra R con el N precargado) y no se
    // recalculan solos si alguien cambia N a mano después.
    $cumpTotal = cumplimiento_informe_editorial($adop['total'], $valorGuardadoK ?? $pres['total']);

    $cumpAnterior = $ultimo['porAsesor'][$a['id_usuario']]['cumplimiento'] ?? null;
    $hoja->setCellValue("S{$fila}", $cumpAnterior !== null ? $cumpAnterior / 100 : '—');
    if ($cumpAnterior !== null) $hoja->getStyle("S{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("S{$fila}")->applyFromArray($estiloNaranja);

    if ($cumpTotal !== null && $cumpAnterior !== null && abs($cumpTotal - $cumpAnterior) >= 0.005) {
        if ($cumpTotal > $cumpAnterior) {
            $hoja->setCellValue("T{$fila}", '▲');
            $hoja->getStyle("T{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '16A34A']]]);
        } else {
            $hoja->setCellValue("T{$fila}", '▼');
            $hoja->getStyle("T{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]]);
        }
    } else {
        $hoja->setCellValue("T{$fila}", '—');
        $hoja->getStyle("T{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'D97706']]]);
    }
    $hoja->getStyle("T{$fila}")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    // U: Meta llegar a — ahora es FÓRMULA (pedido por el usuario 2026-09-18: V y W la usan como
    // referencia, así que también tiene que reaccionar si R cambia por llenar N). 100 = la meta
    // (100% de cumplimiento); el texto aparece cuando R ya llegó o pasó la meta.
    $hoja->setCellValue("U{$fila}", "=IF(R{$fila}>=100,\"Esta cumpliendo la meta\",100)");
    $hoja->getStyle("U{$fila}")->getNumberFormat()->setFormatCode('0"%"');
    $hoja->getStyle("U{$fila}")->applyFromArray(
        ($cumpTotal !== null && $cumpTotal >= 100)
            ? ['font' => ['italic' => true, 'color' => ['rgb' => '15803D']]]
            : ['font' => ['color' => ['rgb' => 'DC2626']]]
    );

    // V: % Pendiente por Llegar a Meta — fórmula EXACTA pedida por el usuario 2026-09-18:
    // IFERROR((Meta-Cumplimiento)/Meta, "") — da "" (en blanco) cuando U es texto ("Esta
    // cumpliendo la meta"), porque restarle un número a un texto da error y IFERROR lo atrapa.
    $hoja->setCellValue("V{$fila}", "=IFERROR((U{$fila}-R{$fila})/U{$fila},\"\")");
    $hoja->getStyle("V{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("V{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    // W: Valor Pendiente de adopción para llegar a — EN PESOS (corregido 2026-09-18: la fórmula
    // literal que dio el usuario, Meta-Cumplimiento, daba puntos porcentuales; pidió que se quede
    // en pesos como antes). Es cuánto dinero falta adoptar para llegar a la meta: presupuesto
    // objetivo (N si está lleno, si no I, el mismo que usa R como denominador) menos lo ya
    // adoptado (M). En blanco cuando ya se cumplió la meta (R>=100); nunca negativo (MAX 0).
    $hoja->setCellValue("W{$fila}", "=IF(R{$fila}>=100,\"\",MAX(0,IF(N{$fila}=\"\",I{$fila}-M{$fila},N{$fila}-M{$fila})))");
    $hoja->getStyle("W{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
    $hoja->getStyle("W{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    $fila++;
}
$finDatos = $fila - 1;

// ── Fila de totales ──────────────────────────────────────────────────────────────────────
$estiloTotal = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$hoja->setCellValue("A{$fila}", 'Total PPTO');
$hoja->setCellValue("B{$fila}", $totalesVentaReal['eureka']);
$hoja->setCellValue("C{$fila}", $totalesVentaReal['mcgraw']);
$hoja->setCellValue("D{$fila}", $totalesVentaReal['otra']);
$hoja->setCellValue("E{$fila}", $totalesVentaReal['total']);
$hoja->getStyle("B{$fila}:E{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
$hoja->setCellValue("F{$fila}", $totales['presupuesto']['eureka']);
$hoja->setCellValue("G{$fila}", $totales['presupuesto']['mcgraw']);
$hoja->setCellValue("H{$fila}", $totales['presupuesto']['otra']);
$hoja->setCellValue("I{$fila}", $totales['presupuesto']['total']);
$hoja->setCellValue("J{$fila}", $totales['adopcion']['eureka']);
$hoja->setCellValue("K{$fila}", $totales['adopcion']['mcgraw']);
$hoja->setCellValue("L{$fila}", $totales['adopcion']['otra']);
$hoja->setCellValue("M{$fila}", $totales['adopcion']['total']);
$hoja->getStyle("F{$fila}:M{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

// N de la fila de totales: suma lo que se haya escrito a mano en las filas de arriba (da 0 si
// nadie ha escrito nada todavía, ya que SUM() de puras celdas vacías es 0, no "").
if ($inicioDatos <= $finDatos) {
    $hoja->setCellValue("N{$fila}", "=SUM(N{$inicioDatos}:N{$finDatos})");
} else {
    $hoja->setCellValue("N{$fila}", 0);
}
$hoja->getStyle("N{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->setCellValue("R{$fila}", "=IF(N{$fila}=0,IFERROR(M{$fila}/I{$fila}*100,0),M{$fila}/N{$fila}*100)");
$hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);

$hoja->setCellValue("U{$fila}", "=IF(R{$fila}>=100,\"Esta cumpliendo la meta\",100)");
$hoja->getStyle("U{$fila}")->getNumberFormat()->setFormatCode('0"%"');
$hoja->setCellValue("V{$fila}", "=IFERROR((U{$fila}-R{$fila})/U{$fila},\"\")");
$hoja->getStyle("V{$fila}")->getNumberFormat()->setFormatCode('0.00%');
// W en pesos, igual que en las filas de asesor (N aquí es la SUMA de la columna, así que se
// compara contra 0 en vez de "").
$hoja->setCellValue("W{$fila}", "=IF(R{$fila}>=100,\"\",MAX(0,IF(N{$fila}=0,I{$fila}-M{$fila},N{$fila}-M{$fila})))");
$hoja->getStyle("W{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->getStyle("A{$fila}:W{$fila}")->applyFromArray($estiloTotal);

if (empty($asesores)) {
    $hoja->setCellValue("A{$inicioDatos}", 'No hay presupuesto/adopción para este período.');
}

$hoja->getStyle("B{$inicioDatos}:W{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle("A{$inicioDatos}:A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$hoja->getStyle("A{$filaCol}:W{$fila}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// ── PPTO 25-26 dentro de Venta Real ──────────────────────────────────────────────────────
// Pedido por el usuario 2026-10-05: los valores de su imagen "PPTO 25-26" van en el bloque Venta
// Real, cada uno al lado de su columna (Eureka → junto a B, McGraw Hill → junto a C, Total → junto
// a E), SIN columnas de diferencia. Se insertan de derecha a izquierda para usar las letras
// originales: queda B Eureka | C PPTO | D McGraw Hill | E PPTO | F Otra | G Total | H PPTO, y todo lo
// que estaba de F en adelante se corre 3 columnas (PhpSpreadsheet ajusta fórmulas y estilos).
$colsPpto = !empty($presupuestoOficial) ? 3 : 0;
if ($colsPpto) {
    $hoja->unmergeCells("B{$filaGrupo}:E{$filaGrupo}");
    $hoja->insertNewColumnBefore('F', 1);
    $hoja->insertNewColumnBefore('D', 1);
    $hoja->insertNewColumnBefore('C', 1);
    $hoja->mergeCells("B{$filaGrupo}:H{$filaGrupo}");
    $hoja->setCellValue("B{$filaGrupo}", $labelVentaRealAnterior);

    $pptoPorAsesor = [];
    foreach ($presupuestoOficial as $of) {
        if ($of['id_usuario'] !== null) $pptoPorAsesor[$of['id_usuario']] = $of;
    }
    $columnasPpto = [
        'C' => ['Eureka PPTO 25-26', fn($of) => $of['eureka']],
        'E' => ['McGraw Hill PPTO 25-26', fn($of) => $of['mcgraw']],
        'H' => ['Total PPTO 25-26', fn($of) => $of['eureka'] + $of['mcgraw']],
    ];
    foreach ($columnasPpto as $col => [$tituloPpto, $valor]) {
        $hoja->setCellValue("{$col}{$filaCol}", $tituloPpto);
        foreach ($filaPorAsesor as $idUsuario => $f) {
            if (isset($pptoPorAsesor[$idUsuario])) $hoja->setCellValue("{$col}{$f}", $valor($pptoPorAsesor[$idUsuario]));
        }
        $hoja->setCellValue("{$col}{$fila}", "=SUM({$col}{$inicioDatos}:{$col}{$finDatos})");
    }
    $hoja->getStyle("B{$filaGrupo}:H{$filaGrupo}")->applyFromArray($estiloGrupo);
    $hoja->getStyle("B{$filaGrupo}:H{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4DFF7']], 'font' => ['bold' => true]]);
    $hoja->getStyle("B{$filaCol}:H{$filaCol}")->applyFromArray($estiloCol);
    $hoja->getStyle("B{$filaCol}:H{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4DFF7']]]);
    $hoja->getStyle("B{$inicioDatos}:H{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
    $hoja->getStyle("B{$inicioDatos}:H{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $hoja->getStyle("B{$fila}:H{$fila}")->applyFromArray($estiloTotal);
}
// Letra final de una columna del layout original después de insertar las de PPTO 25-26.
$colFinal = function ($letra) use ($colsPpto) {
    $idx = Coordinate::columnIndexFromString($letra);
    if (!$colsPpto || $idx < 3) $desp = 0;
    elseif ($idx === 3) $desp = 1;      // C (McGraw Hill) → D
    elseif ($idx <= 5) $desp = 2;       // D (Otra) → F, E (Total) → G
    else $desp = 3;
    return Coordinate::stringFromColumnIndex($idx + $desp);
};

// ── Cuadros debajo de la tabla ──────────────────────────────────────────────────────────
// Pinta título (fila combinada) + encabezados de un cuadro desde la columna A; devuelve la fila
// donde empiezan los datos.
$pintarEncabezadoCuadro = function ($filaTitulo, $titulo, array $encabezados) use ($hoja, $estiloGrupo, $estiloCol) {
    $ultimaCol = Coordinate::stringFromColumnIndex(count($encabezados));
    $hoja->mergeCells("A{$filaTitulo}:{$ultimaCol}{$filaTitulo}");
    $hoja->setCellValue("A{$filaTitulo}", $titulo);
    $hoja->getStyle("A{$filaTitulo}:{$ultimaCol}{$filaTitulo}")->applyFromArray($estiloGrupo);
    foreach ($encabezados as $i => $texto) $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . ($filaTitulo + 1), $texto);
    $hoja->getStyle("A" . ($filaTitulo + 1) . ":{$ultimaCol}" . ($filaTitulo + 1))->applyFromArray($estiloCol);
    $hoja->getRowDimension($filaTitulo + 1)->setRowHeight(30);
    return $filaTitulo + 2;
};

// Desglose de "Otra" por editorial — un cuadro por cada grupo (venta real, presupuesto, adopciones)
// para ver de qué editoriales se compone esa columna (ej. cuánto es ALTIVA). Pedido por el usuario
// 2026-10-05; Altiva va con el mismo formato y orden (por monto) que las demás, a pedido del
// usuario. Solo salen las editoriales con valor en ese grupo; "Total Otra" cuadra con la columna
// Otra de cada bloque del cuadro principal.
$cuadrosOtra = [
    ["Venta real por editorial {$anioVentaReal}", fn($a) => $ventaRealAnterior['porAsesor'][$a['id_usuario']]['otra_ed'] ?? []],
    ["Presupuesto por editorial {$periodoLabel}", fn($a) => $a['presupuesto_otra_ed']],
    ["Adopciones por editorial {$periodoLabel}", fn($a) => $a['adopcion_otra_ed']],
];
$fila += 3;
foreach ($cuadrosOtra as [$tituloCuadro, $obtenerOtraEd]) {
    $totalPorEd = [];
    foreach ($asesores as $a) sumar_montos_por_id_informe($totalPorEd, $obtenerOtraEd($a));
    $totalPorEd = array_filter($totalPorEd, fn($v) => abs($v) >= 0.5);
    if (empty($totalPorEd)) continue;
    arsort($totalPorEd);
    $idsEd = array_keys($totalPorEd);
    $nombresEd = nombres_editoriales_informe($bdd, $idsEd);

    $encabezados = ['Asesores'];
    foreach ($idsEd as $idEd) $encabezados[] = $nombresEd[$idEd];
    $encabezados[] = 'Total Otra';
    $colTotal = Coordinate::stringFromColumnIndex(count($encabezados));
    $fila = $pintarEncabezadoCuadro($fila, $tituloCuadro, $encabezados);
    $iniCuadro = $fila;

    foreach ($asesores as $a) {
        $porEd = $obtenerOtraEd($a);
        $hoja->setCellValue("A{$fila}", $a['nombre'] . ($a['activo'] ? '' : ' (inactivo)'));
        foreach ($idsEd as $i => $idEd) $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 2) . $fila, $porEd[$idEd] ?? 0);
        $hoja->setCellValue("{$colTotal}{$fila}", "=SUM(B{$fila}:" . Coordinate::stringFromColumnIndex(count($idsEd) + 1) . "{$fila})");
        $fila++;
    }
    $hoja->setCellValue("A{$fila}", 'Total');
    for ($c = 2; $c <= count($encabezados); $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $hoja->setCellValue("{$L}{$fila}", "=SUM({$L}{$iniCuadro}:{$L}" . ($fila - 1) . ")");
    }
    $hoja->getStyle("A{$fila}:{$colTotal}{$fila}")->applyFromArray($estiloTotal);
    $hoja->getStyle("B{$iniCuadro}:{$colTotal}{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
    $hoja->getStyle("{$colTotal}{$iniCuadro}:{$colTotal}{$fila}")->getFont()->setBold(true);
    $fila += 3;
}

// Autosize solo para las columnas de datos normales (A-M, O-Q): el ancho calculado les queda
// bien solo. N (manual, vacía) y R-W (encabezados largos + fórmulas) llevan ancho FIJO —
// setAutoSize(true) pisa cualquier setWidth() puesto después, así que hay que excluirlas del
// autosize desde el principio en vez de "corregir" su ancho más abajo. Reportado por el usuario 2026-09-18 ("el scroll me permita ver todos los
// datos").
foreach (['A','B','C','D','E','F','G','H','I','J','K','L','M','O','P','Q'] as $col) {
    $hoja->getColumnDimension($colFinal($col))->setAutoSize(true);
}
foreach (['N' => 22, 'R' => 20, 'S' => 24, 'T' => 14, 'U' => 22, 'V' => 16, 'W' => 22] as $col => $ancho) {
    $hoja->getColumnDimension($colFinal($col))->setWidth($ancho);
}
// Columnas PPTO 25-26 (C, E y H cuando existen).
if ($colsPpto) foreach (['C', 'E', 'H'] as $col) $hoja->getColumnDimension($col)->setWidth(20);
// Sin freezePane: las filas del encabezado quedaban inmovilizadas al hacer scroll hacia abajo y
// tapaban/recortaban la vista de las filas de datos reales — pedido por el usuario 2026-09-18
// ("quita el inmovilizar de esas [filas]").

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="informe_cumplimiento_' . $periodoLabel . '_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
