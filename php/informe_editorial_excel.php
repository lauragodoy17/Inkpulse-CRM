<?php
/**
 * /php/informe_editorial_excel.php
 * "Informe Cumplimiento" por asesor (tipo=3 + Hector Morales, id=69): adopción y presupuesto
 * asignado valorizados, desglosados por editorial (Eureka / McGraw Hill / Otra), con % de
 * cumplimiento, comparación contra el último informe guardado (ver
 * php/informe_editorial_snapshot_cron.php) y cuánto falta para llegar a la meta del 100%.
 * Layout pedido por el usuario 2026-09-18 (ver captura de referencia). Columna B "Venta real
 * temporada {año anterior}" agregada 2026-09-22 (ver obtener_venta_real_temporada_anterior()).
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
asegurar_snapshot_semanal_informe_editorial($bdd, $idPeriodo);
$ultimo = obtener_ultimo_snapshot_informe_editorial($bdd, $idPeriodo);
// Presupuesto por temporada ya guardado (ver reporte_editorial.php) — se precarga en la columna K
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
$drawing = new Drawing();
$drawing->setName('logo');
$drawing->setDescription('logo');
$drawing->setPath('../vendors/images/logo_eureka.png');
$drawing->setHeight(70);
$drawing->setCoordinates('A1');
$drawing->setWorksheet($hoja);

$estiloTitulo   = ['font' => ['bold' => true, 'size' => 14]];
$estiloEtiqueta = ['font' => ['bold' => true]];

$hoja->mergeCells('D1:L1');
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
// Layout de columnas (2026-09-22, con la nueva B insertada, todo lo demás corrido +1 letra
// respecto a la versión anterior): A=Asesores, B=Venta real temporada anterior,
// C-F=ADOPCIONES, G-J=PRESUPUESTO ASIGNADO, K=Presupuesto por temporada (manual), L-N=Cumplimientos,
// O-T=métricas de cumplimiento/variación/meta.
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
    ['C', 'F', "ADOPCIONES {$periodoLabel}"],
    ['G', 'J', "PRESUPUESTO ASIGNADO {$periodoLabel}"],
    ['L', 'N', "CUMPLIMIENTOS"],
];
foreach ($grupos as [$colIni, $colFin, $titulo]) {
    if ($colIni !== $colFin) $hoja->mergeCells("{$colIni}{$filaGrupo}:{$colFin}{$filaGrupo}");
    $hoja->setCellValue("{$colIni}{$filaGrupo}", $titulo);
}
// Columnas de una sola celda que abarcan las DOS filas de encabezado: B (nueva, "Venta real
// temporada anterior"), K ("Presupuesto asignado por temporada") y O..T.
foreach (['B', 'K', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) {
    $hoja->mergeCells("{$col}{$filaGrupo}:{$col}{$filaCol}");
}
$hoja->getStyle("A{$filaGrupo}:T{$filaCol}")->applyFromArray($estiloGrupo);
// Alto explícito: sin esto, las filas de encabezado se quedan con el alto por defecto (~14pt) y el
// texto largo con wrapText (ej. "Valor Pendiente de adopción para llegar a") queda recortado
// dentro de la celda. Reportado por el usuario 2026-09-18.
$hoja->getRowDimension($filaGrupo)->setRowHeight(42);
$hoja->getRowDimension($filaCol)->setRowHeight(28);

$fechaUltimoLabel = $ultimo['fecha'] ? date('d/m/Y', strtotime($ultimo['fecha'])) : 'Sin informes previos';

$titulosCol = [
    'A' => 'Asesores',
    'B' => $labelVentaRealAnterior,
    'C' => 'Eureka', 'D' => 'McGraw Hill', 'E' => 'Otra', 'F' => 'Total adopciones',
    'G' => 'Eureka', 'H' => 'McGraw Hill', 'I' => 'Otra', 'J' => 'Total PPTO',
    'K' => 'Presupuesto asignado por temporada',
    'L' => 'Eureka', 'M' => 'McGraw Hill', 'N' => 'Otra',
    'O' => 'TOTAL CUMPLIMIENTO (Informe a Hoy)',
    'P' => "Último informe enviado día {$fechaUltimoLabel}",
    'Q' => 'Variación frente al último',
    'R' => 'Meta llegar a',
    'S' => '% Pendiente por Llegar a Meta',
    'T' => 'Valor Pendiente de adopción para llegar a',
];
foreach ($titulosCol as $col => $titulo) {
    $hoja->setCellValue("{$col}{$filaCol}", $titulo);
}
$hoja->getStyle("A{$filaCol}:T{$filaCol}")->applyFromArray($estiloCol);
// B, K y O..T ya llevan su título en la fila de grupo (celda combinada verticalmente) — se limpia
// la fila de columna para esas letras para no repetir el texto dos veces.
foreach (['B', 'K', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) $hoja->setCellValue("{$col}{$filaCol}", '');
foreach (['B', 'K', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) $hoja->setCellValue("{$col}{$filaGrupo}", $titulosCol[$col]);

$estiloVerde   = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C6E9C6']]];
$estiloNaranja = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE4B4']]];
$hoja->getStyle("O{$filaGrupo}:O{$filaCol}")->applyFromArray($estiloVerde + ['font' => ['bold' => true]]);
$hoja->getStyle("P{$filaGrupo}:P{$filaCol}")->applyFromArray($estiloNaranja + ['font' => ['bold' => true]]);
// La columna K es de llenado MANUAL (no se calcula) — se resalta distinto para que quede claro que
// es un campo para escribir a mano. Pedido por el usuario 2026-09-18 ("deberás dejar la columna en
// blanco... si hay algún dato se calcula...").
$hoja->getStyle("K{$filaGrupo}:K{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']], 'font' => ['bold' => true]]);
// La columna B es dato de REFERENCIA (temporada ya cerrada, no se recalcula con lo que se llene
// hoy) — se resalta con un tono distinto (lavanda) para diferenciarla de las columnas de la
// temporada vigente. Pedido por el usuario 2026-09-22.
$hoja->getStyle("B{$filaGrupo}:B{$filaCol}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4DFF7']], 'font' => ['bold' => true]]);

// ── Filas de datos, una por asesor ──────────────────────────────────────────────────────
$fmtMoney = '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"??_);_(@_)';
// O y T quedan en la MISMA escala que pidió el usuario (números tipo 8.52, ya multiplicados por
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
$totalVentaRealAnterior = 0.0;

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

    // B: Venta real de la temporada ANTERIOR (referencia, no forma parte de ningún cálculo de
    // cumplimiento de la temporada vigente) — 0 si el asesor no tuvo venta real registrada.
    $ventaRealAsesor = $ventaRealAnterior['porAsesor'][$a['id_usuario']] ?? 0.0;
    $totalVentaRealAnterior += $ventaRealAsesor;
    $hoja->setCellValue("B{$fila}", $ventaRealAsesor);
    $hoja->getStyle("B{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    $hoja->setCellValue("C{$fila}", $adop['eureka']);
    $hoja->setCellValue("D{$fila}", $adop['mcgraw']);
    $hoja->setCellValue("E{$fila}", $adop['otra']);
    $hoja->setCellValue("F{$fila}", $adop['total']);
    $hoja->setCellValue("G{$fila}", $pres['eureka']);
    $hoja->setCellValue("H{$fila}", $pres['mcgraw']);
    $hoja->setCellValue("I{$fila}", $pres['otra']);
    $hoja->setCellValue("J{$fila}", $pres['total']);
    $hoja->getStyle("C{$fila}:J{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    // K: "Presupuesto asignado por temporada" — se PRECARGA con lo ya guardado (ver
    // reporte_editorial.php) para no tener que volver a escribirlo cada semana; si nadie ha
    // guardado nada para este asesor, queda en blanco como antes (editable a mano igual).
    // Pedido por el usuario 2026-09-18.
    $valorGuardadoK = $presupuestoTemporadaGuardado[$a['id_usuario']] ?? null;
    if ($valorGuardadoK !== null) $hoja->setCellValue("K{$fila}", $valorGuardadoK);
    $hoja->getStyle("K{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

    foreach (['eureka' => 'L', 'mcgraw' => 'M', 'otra' => 'N'] as $bucket => $col) {
        $cump = cumplimiento_informe_editorial($adop[$bucket], $pres[$bucket]);
        if ($cump === null) {
            $hoja->setCellValue("{$col}{$fila}", 'Sin Ppto Asignado');
            $hoja->getStyle("{$col}{$fila}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => '94A3B8']]]);
        } else {
            $hoja->setCellValue("{$col}{$fila}", $cump / 100);
            $hoja->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode('0.00%');
        }
    }

    // O: TOTAL CUMPLIMIENTO (Informe a Hoy) — fórmula EN VIVO pedida por el usuario 2026-09-18: si
    // se llena a mano la columna K, el cumplimiento se recalcula contra ESE presupuesto
    // (F÷K×100); mientras K esté vacío sigue usando el presupuesto ya cargado en el CRM (F÷J×100,
    // el mismo cálculo de siempre). Se envuelve en IFERROR por si J llega a ser 0.
    $hoja->setCellValue("O{$fila}", "=IF(K{$fila}=\"\",IFERROR(F{$fila}/J{$fila}*100,0),F{$fila}/K{$fila}*100)");
    $hoja->getStyle("O{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);
    $hoja->getStyle("O{$fila}")->applyFromArray(['font' => ['bold' => true]] + $estiloVerde);

    // $cumpTotal sigue haciendo falta en PHP para la flecha de variación (Q, comparada contra el
    // snapshot guardado) y para el color inicial de Meta (R) — son estilos fijos que reflejan el
    // estado "recién generado" (igual a lo que muestra O con el K precargado) y no se
    // recalculan solos si alguien cambia K a mano después.
    $cumpTotal = cumplimiento_informe_editorial($adop['total'], $valorGuardadoK ?? $pres['total']);

    $cumpAnterior = $ultimo['porAsesor'][$a['id_usuario']]['cumplimiento'] ?? null;
    $hoja->setCellValue("P{$fila}", $cumpAnterior !== null ? $cumpAnterior / 100 : '—');
    if ($cumpAnterior !== null) $hoja->getStyle("P{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("P{$fila}")->applyFromArray($estiloNaranja);

    if ($cumpTotal !== null && $cumpAnterior !== null && abs($cumpTotal - $cumpAnterior) >= 0.005) {
        if ($cumpTotal > $cumpAnterior) {
            $hoja->setCellValue("Q{$fila}", '▲');
            $hoja->getStyle("Q{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '16A34A']]]);
        } else {
            $hoja->setCellValue("Q{$fila}", '▼');
            $hoja->getStyle("Q{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]]);
        }
    } else {
        $hoja->setCellValue("Q{$fila}", '—');
        $hoja->getStyle("Q{$fila}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'D97706']]]);
    }
    $hoja->getStyle("Q{$fila}")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    // R: Meta llegar a — ahora es FÓRMULA (pedido por el usuario 2026-09-18: S y T la usan como
    // referencia, así que también tiene que reaccionar si O cambia por llenar K). 100 = la meta
    // (100% de cumplimiento); el texto aparece cuando O ya llegó o pasó la meta.
    $hoja->setCellValue("R{$fila}", "=IF(O{$fila}>=100,\"Esta cumpliendo la meta\",100)");
    $hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode('0"%"');
    $hoja->getStyle("R{$fila}")->applyFromArray(
        ($cumpTotal !== null && $cumpTotal >= 100)
            ? ['font' => ['italic' => true, 'color' => ['rgb' => '15803D']]]
            : ['font' => ['color' => ['rgb' => 'DC2626']]]
    );

    // S: % Pendiente por Llegar a Meta — fórmula EXACTA pedida por el usuario 2026-09-18:
    // IFERROR((Meta-Cumplimiento)/Meta, "") — da "" (en blanco) cuando R es texto ("Esta
    // cumpliendo la meta"), porque restarle un número a un texto da error y IFERROR lo atrapa.
    $hoja->setCellValue("S{$fila}", "=IFERROR((R{$fila}-O{$fila})/R{$fila},\"\")");
    $hoja->getStyle("S{$fila}")->getNumberFormat()->setFormatCode('0.00%');
    $hoja->getStyle("S{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    // T: Valor Pendiente de adopción para llegar a — EN PESOS (corregido 2026-09-18: la fórmula
    // literal que dio el usuario, Meta-Cumplimiento, daba puntos porcentuales; pidió que se quede
    // en pesos como antes). Es cuánto dinero falta adoptar para llegar a la meta: presupuesto
    // objetivo (K si está lleno, si no J, el mismo que usa O como denominador) menos lo ya
    // adoptado (F). En blanco cuando ya se cumplió la meta (O>=100); nunca negativo (MAX 0).
    $hoja->setCellValue("T{$fila}", "=IF(O{$fila}>=100,\"\",MAX(0,IF(K{$fila}=\"\",J{$fila}-F{$fila},K{$fila}-F{$fila})))");
    $hoja->getStyle("T{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
    $hoja->getStyle("T{$fila}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626']]]);

    $fila++;
}
$finDatos = $fila - 1;

// ── Fila de totales ──────────────────────────────────────────────────────────────────────
$estiloTotal = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]];
$hoja->setCellValue("A{$fila}", 'Total PPTO');
$hoja->setCellValue("B{$fila}", $totalVentaRealAnterior);
$hoja->getStyle("B{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);
$hoja->setCellValue("C{$fila}", $totales['adopcion']['eureka']);
$hoja->setCellValue("D{$fila}", $totales['adopcion']['mcgraw']);
$hoja->setCellValue("E{$fila}", $totales['adopcion']['otra']);
$hoja->setCellValue("F{$fila}", $totales['adopcion']['total']);
$hoja->setCellValue("G{$fila}", $totales['presupuesto']['eureka']);
$hoja->setCellValue("H{$fila}", $totales['presupuesto']['mcgraw']);
$hoja->setCellValue("I{$fila}", $totales['presupuesto']['otra']);
$hoja->setCellValue("J{$fila}", $totales['presupuesto']['total']);
$hoja->getStyle("C{$fila}:J{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

// K de la fila de totales: suma lo que se haya escrito a mano en las filas de arriba (da 0 si
// nadie ha escrito nada todavía, ya que SUM() de puras celdas vacías es 0, no "").
if ($inicioDatos <= $finDatos) {
    $hoja->setCellValue("K{$fila}", "=SUM(K{$inicioDatos}:K{$finDatos})");
} else {
    $hoja->setCellValue("K{$fila}", 0);
}
$hoja->getStyle("K{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->setCellValue("O{$fila}", "=IF(K{$fila}=0,IFERROR(F{$fila}/J{$fila}*100,0),F{$fila}/K{$fila}*100)");
$hoja->getStyle("O{$fila}")->getNumberFormat()->setFormatCode($fmtPctYaEscalado);

$hoja->setCellValue("R{$fila}", "=IF(O{$fila}>=100,\"Esta cumpliendo la meta\",100)");
$hoja->getStyle("R{$fila}")->getNumberFormat()->setFormatCode('0"%"');
$hoja->setCellValue("S{$fila}", "=IFERROR((R{$fila}-O{$fila})/R{$fila},\"\")");
$hoja->getStyle("S{$fila}")->getNumberFormat()->setFormatCode('0.00%');
// T en pesos, igual que en las filas de asesor (K aquí es la SUMA de la columna, así que se
// compara contra 0 en vez de "").
$hoja->setCellValue("T{$fila}", "=IF(O{$fila}>=100,\"\",MAX(0,IF(K{$fila}=0,J{$fila}-F{$fila},K{$fila}-F{$fila})))");
$hoja->getStyle("T{$fila}")->getNumberFormat()->setFormatCode($fmtMoney);

$hoja->getStyle("A{$fila}:T{$fila}")->applyFromArray($estiloTotal);

if (empty($asesores)) {
    $hoja->setCellValue("A{$inicioDatos}", 'No hay presupuesto/adopción para este período.');
}

$hoja->getStyle("B{$inicioDatos}:T{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle("A{$inicioDatos}:A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$hoja->getStyle("A{$filaCol}:T{$fila}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// Autosize solo para las columnas de datos normales (A, C-J, L-N): el ancho calculado les queda
// bien solo. B (header largo, "Venta real temporada ####"), K (manual, vacía) y O-T (encabezados
// largos + fórmulas) llevan ancho FIJO — setAutoSize(true) pisa cualquier setWidth() puesto
// después, así que hay que excluirlas del autosize desde el principio en vez de "corregir" su
// ancho más abajo. Reportado por el usuario 2026-09-18 ("el scroll me permita ver todos los
// datos").
foreach (['A','C','D','E','F','G','H','I','J','L','M','N'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->getColumnDimension('B')->setWidth(24);
$hoja->getColumnDimension('K')->setWidth(22);
$hoja->getColumnDimension('O')->setWidth(20);
$hoja->getColumnDimension('P')->setWidth(24);
$hoja->getColumnDimension('Q')->setWidth(14);
$hoja->getColumnDimension('R')->setWidth(22);
$hoja->getColumnDimension('S')->setWidth(16);
$hoja->getColumnDimension('T')->setWidth(22);
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
