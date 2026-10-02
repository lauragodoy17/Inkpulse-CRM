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
$labelVentaRealAnterior = $ventaRealAnterior['anioAnterior'] !== null
    ? 'VENTA REAL TEMPORADA ' . $ventaRealAnterior['anioAnterior']
    : 'VENTA REAL TEMPORADA ANTERIOR';

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
// editorial, todo lo demás corrido +3 letras): A=Asesores, B-E=Venta real temporada anterior,
// F-I=ADOPCIONES, J-M=PRESUPUESTO ASIGNADO, N=Presupuesto por temporada (manual), O-Q=Cumplimientos,
// R-W=métricas de cumplimiento/variación/meta.
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
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCE6F1']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C6E0']]],
];

// Grupos que abarcan varias columnas.
$grupos = [
    ['A', 'A', "INFORME CUMPLIMIENTO {$periodoLabel}"],
    ['B', 'E', $labelVentaRealAnterior],
    ['F', 'I', "ADOPCIONES {$periodoLabel}"],
    ['J', 'M', "PRESUPUESTO ASIGNADO {$periodoLabel}"],
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
    'F' => 'Eureka', 'G' => 'McGraw Hill', 'H' => 'Otra', 'I' => 'Total adopciones',
    'J' => 'Eureka', 'K' => 'McGraw Hill', 'L' => 'Otra', 'M' => 'Total PPTO',
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

    $hoja->setCellValue("F{$fila}", $adop['eureka']);
    $hoja->setCellValue("G{$fila}", $adop['mcgraw']);
    $hoja->setCellValue("H{$fila}", $adop['otra']);
    $hoja->setCellValue("I{$fila}", $adop['total']);
    $hoja->setCellValue("J{$fila}", $pres['eureka']);
    $hoja->setCellValue("K{$fila}", $pres['mcgraw']);
    $hoja->setCellValue("L{$fila}", $pres['otra']);
    $hoja->setCellValue("M{$fila}", $pres['total']);
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
    // (I÷N×100); mientras N esté vacío sigue usando el presupuesto ya cargado en el CRM (I÷M×100,
    // el mismo cálculo de siempre). Se envuelve en IFERROR por si M llega a ser 0.
    $hoja->setCellValue("R{$fila}", "=IF(N{$fila}=\"\",IFERROR(I{$fila}/M{$fila}*100,0),I{$fila}/N{$fila}*100)");
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
    // objetivo (N si está lleno, si no M, el mismo que usa R como denominador) menos lo ya
    // adoptado (I). En blanco cuando ya se cumplió la meta (R>=100); nunca negativo (MAX 0).
    $hoja->setCellValue("W{$fila}", "=IF(R{$fila}>=100,\"\",MAX(0,IF(N{$fila}=\"\",M{$fila}-I{$fila},N{$fila}-I{$fila})))");
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
$hoja->setCellValue("F{$fila}", $totales['adopcion']['eureka']);
$hoja->setCellValue("G{$fila}", $totales['adopcion']['mcgraw']);
$hoja->setCellValue("H{$fila}", $totales['adopcion']['otra']);
$hoja->setCellValue("I{$fila}", $totales['adopcion']['total']);
$hoja->setCellValue("J{$fila}", $totales['presupuesto']['eureka']);
$hoja->setCellValue("K{$fila}", $totales['presupuesto']['mcgraw']);
$hoja->setCellValue("L{$fila}", $totales['presupuesto']['otra']);
$hoja->setCellValue("M{$fila}", $totales['presupuesto']['total']);
$hoja->getStyle("F{$fila}:M{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

// N de la fila de totales: suma lo que se haya escrito a mano en las filas de arriba (da 0 si
// nadie ha escrito nada todavía, ya que SUM() de puras celdas vacías es 0, no "").
if ($inicioDatos <= $finDatos) {
    $hoja->setCellValue("N{$fila}", "=SUM(N{$inicioDatos}:N{$finDatos})");
} else {
    $hoja->setCellValue("N{$fila}", 0);
}
$hoja->getStyle("N{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->setCellValue("R{$fila}", "=IF(N{$fila}=0,IFERROR(I{$fila}/M{$fila}*100,0),I{$fila}/N{$fila}*100)");
$hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);

$hoja->setCellValue("U{$fila}", "=IF(R{$fila}>=100,\"Esta cumpliendo la meta\",100)");
$hoja->getStyle("U{$fila}")->getNumberFormat()->setFormatCode('0"%"');
$hoja->setCellValue("V{$fila}", "=IFERROR((U{$fila}-R{$fila})/U{$fila},\"\")");
$hoja->getStyle("V{$fila}")->getNumberFormat()->setFormatCode('0.00%');
// W en pesos, igual que en las filas de asesor (N aquí es la SUMA de la columna, así que se
// compara contra 0 en vez de "").
$hoja->setCellValue("W{$fila}", "=IF(R{$fila}>=100,\"\",MAX(0,IF(N{$fila}=0,M{$fila}-I{$fila},N{$fila}-I{$fila})))");
$hoja->getStyle("W{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->getStyle("A{$fila}:W{$fila}")->applyFromArray($estiloTotal);

if (empty($asesores)) {
    $hoja->setCellValue("A{$inicioDatos}", 'No hay presupuesto/adopción para este período.');
}

$hoja->getStyle("B{$inicioDatos}:W{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle("A{$inicioDatos}:A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$hoja->getStyle("A{$filaCol}:W{$fila}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// Autosize solo para las columnas de datos normales (A-M, O-Q): el ancho calculado les queda
// bien solo. N (manual, vacía) y R-W (encabezados largos + fórmulas) llevan ancho FIJO —
// setAutoSize(true) pisa cualquier setWidth() puesto después, así que hay que excluirlas del
// autosize desde el principio en vez de "corregir" su ancho más abajo. Reportado por el usuario 2026-09-18 ("el scroll me permita ver todos los
// datos").
foreach (['A','B','C','D','E','F','G','H','I','J','K','L','M','O','P','Q'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->getColumnDimension('N')->setWidth(22);
$hoja->getColumnDimension('R')->setWidth(20);
$hoja->getColumnDimension('S')->setWidth(24);
$hoja->getColumnDimension('T')->setWidth(14);
$hoja->getColumnDimension('U')->setWidth(22);
$hoja->getColumnDimension('V')->setWidth(16);
$hoja->getColumnDimension('W')->setWidth(22);
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
