<?php
/**
 * /php/colocacion_excel.php
 * Exportable del reporte de Colocación (mismos datos que reporte_colocacion.php / ver
 * includes/colocacion_datos.php), para verificar más fácil contra
 * C:\Users\USUARIO\Desktop\colocacion\final_automatizado.xlsx. La hoja "Colocacion" agrupa las
 * filas por Empresa y, dentro de cada Empresa, por Cliente (igual estructura que el Excel de
 * referencia, generado ahí con la función "Subtotales" de Excel): tras cada bloque de Cliente
 * va una fila "Total <Cliente>" (o "Total Sin Cliente"), y tras cada bloque de Empresa una fila
 * "Total General <Empresa>". Las hojas "Totales por Empresa" y "Totales por Cliente" traen el
 * mismo dato agregado y ordenado alfabéticamente por grupo, cada una con su propio total
 * general al pie.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}

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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$periodos = obtener_periodos_colocacion($bdd);
$idsPermitidos = array_column($periodos, 'id');

$id_periodo = null;
if (isset($_GET['id_periodo']) && in_array((int)$_GET['id_periodo'], $idsPermitidos, true)) {
    $id_periodo = (int)$_GET['id_periodo'];
}

$datos = obtener_datos_colocacion($bdd, $id_periodo);
$filas = $datos['filas'];

// Orden igual al pivote del Excel de referencia: Empresa, luego Cliente (para que cada bloque de
// Cliente quede contiguo y se le pueda poner su fila de subtotal), luego Colegio.
usort($filas, function ($a, $b) {
    return [$a['empresa'], $a['cliente'], $a['colegio']] <=> [$b['empresa'], $b['cliente'], $b['colegio']];
});

// Cuántos movimientos (Documento/Fecha/Valor) de cada tipo hacen falta: el colegio con más
// movimientos de cada tipo define cuántas columnas se generan (dinámico). Factura de Venta,
// Abonos (RC, agregado 2026-09-02) y Devoluciones ahora también traen su propio detalle por
// documento (antes eran un solo total sumado) — el total escalar ('factura_venta'/'abonos'/
// 'devoluciones') se conserva para los subtotales por Cliente/Empresa, igual que antes.
$maxMovimientosWo = 0;
$maxMovimientosPos = 0;
$maxMovimientosFv = 0;
$maxMovimientosAbonos = 0;
$maxMovimientosDev = 0;
foreach ($filas as $f) {
    $maxMovimientosWo = max($maxMovimientosWo, count($f['colocacion_wo']));
    $maxMovimientosPos = max($maxMovimientosPos, count($f['colocacion_pos']));
    $maxMovimientosFv = max($maxMovimientosFv, count($f['factura_venta_mov']));
    $maxMovimientosAbonos = max($maxMovimientosAbonos, count($f['abonos_mov']));
    $maxMovimientosDev = max($maxMovimientosDev, count($f['devoluciones_mov']));
}
$maxMovimientosWo = max($maxMovimientosWo, 1);
$maxMovimientosPos = max($maxMovimientosPos, 1);
$maxMovimientosFv = max($maxMovimientosFv, 1);
$maxMovimientosAbonos = max($maxMovimientosAbonos, 1);
$maxMovimientosDev = max($maxMovimientosDev, 1);

$encabezados = ['Empresa', 'Colegio', 'Presupuesto Registrado en CRM', 'Adopciones CRM', 'Atenciones a Clientes', 'Poblacion General', 'Compradores Activos (# Adopciones)', 'Descuento Promedio', 'Numero de la Adopcion', 'Cliente', 'Factura de Venta', 'Abonos'];
// A..L (1..12) sin cambios respecto a antes — el resto de columnas se calcula con contadores en
// vez de índices fijos, para no repetir el problema de "hay que desplazar todo a mano" cada vez
// que se agrega un grupo dinámico más (ver memory/project_colocacion_modulo.md).
$colInicioAbonosDet = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosAbonos; $i++) {
    $encabezados[] = "Abonos - Documento $i";
    $encabezados[] = "Abonos - Fecha $i";
    $encabezados[] = "Abonos - Valor $i";
}
$colInicioWo = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosWo; $i++) {
    $encabezados[] = "Colocacion World Office (REM-CEUR) - Documento $i";
    $encabezados[] = "Colocacion World Office (REM-CEUR) - Fecha $i";
    $encabezados[] = "Colocacion World Office (REM-CEUR) - Valor $i";
}
$colInicioPos = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosPos; $i++) {
    $encabezados[] = "Facturas POS - Documento $i";
    $encabezados[] = "Facturas POS - Fecha $i";
    $encabezados[] = "Facturas POS - Valor $i";
}
$colInicioFvDet = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosFv; $i++) {
    $encabezados[] = "Factura de Venta - Documento $i";
    $encabezados[] = "Factura de Venta - Fecha $i";
    $encabezados[] = "Factura de Venta - Valor $i";
}
$colDevoluciones = count($encabezados) + 1;
$encabezados[] = 'Devoluciones';
$colInicioDevDet = count($encabezados) + 1;
for ($i = 1; $i <= $maxMovimientosDev; $i++) {
    $encabezados[] = "Devoluciones - Documento $i";
    $encabezados[] = "Devoluciones - Fecha $i";
    $encabezados[] = "Devoluciones - Valor $i";
}
$encabezados[] = 'Total Colocado (Factura + RCEUR + POS - Devoluciones)';
$colTotal = count($encabezados);

$totalColumnas = count($encabezados);
$colAtenciones = 5; // Columna E, fija — justo después de "Adopciones CRM" (D), a pedido del usuario.
$colAtencionesLetra = Coordinate::stringFromColumnIndex($colAtenciones);
$colDevolucionesLetra = Coordinate::stringFromColumnIndex($colDevoluciones);

$objSpreadsheet = new Spreadsheet();
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle('Colocacion');
$objSpreadsheet->getProperties()->setTitle('Colocación Calendario ' . $datos['calendario'] . ' - ' . $datos['periodo']);

foreach ($encabezados as $i => $titulo) {
    $col = Coordinate::stringFromColumnIndex($i + 1);
    $hoja->setCellValue("{$col}1", $titulo);
}
$ultimaColumna = Coordinate::stringFromColumnIndex($totalColumnas);
$hoja->getStyle("A1:{$ultimaColumna}1")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
]);
$hoja->freezePane('A2');

// Columnas de dinero (índice base 1) — presupuesto, adopciones, atenciones, factura, abonos, cada
// Valor N de REM y de POS, devoluciones y el total al final. Descuento Promedio (col 8) y Numero
// de la Adopcion (col 9) tienen su propio formato (no son moneda), aplicado más abajo.
$columnasDinero = [3, 4, $colAtenciones, 11, 12];
for ($i = 0; $i < $maxMovimientosAbonos; $i++) $columnasDinero[] = $colInicioAbonosDet + 2 + ($i * 3);
for ($i = 0; $i < $maxMovimientosWo; $i++) $columnasDinero[] = $colInicioWo + 2 + ($i * 3);
for ($i = 0; $i < $maxMovimientosPos; $i++) $columnasDinero[] = $colInicioPos + 2 + ($i * 3);
for ($i = 0; $i < $maxMovimientosFv; $i++) $columnasDinero[] = $colInicioFvDet + 2 + ($i * 3);
$columnasDinero[] = $colDevoluciones;
for ($i = 0; $i < $maxMovimientosDev; $i++) $columnasDinero[] = $colInicioDevDet + 2 + ($i * 3);
$columnasDinero[] = $colTotal;

// Subtotal de Cliente (fila amarilla) o de Empresa (fila verde), igual estilo que el Excel de
// referencia: etiqueta en la columna B (Cliente) o A (Empresa) y sumas solo en las columnas de
// dinero/cifras agregadas — no tiene sentido subtotalizar Cliente (texto) ni las columnas
// Fecha/Valor de movimientos WO puntuales (cada colegio trae los suyos en posiciones distintas).
function fila_totales_vacia_colocacion() {
    return ['presupuesto' => 0, 'adopciones' => 0, 'poblacion' => 0, 'compradores' => 0, 'factura' => 0, 'abonos' => 0, 'devoluciones' => 0, 'total' => 0];
}
function sumar_totales_colocacion(array &$totales, array $f) {
    $totales['presupuesto'] += $f['presupuesto_crm'];
    $totales['adopciones'] += $f['adopciones_crm'];
    $totales['poblacion'] += $f['poblacion_general'];
    $totales['compradores'] += $f['compradores_activos'];
    $totales['factura'] += $f['factura_venta'];
    $totales['abonos'] += $f['abonos'];
    $totales['devoluciones'] += $f['devoluciones'];
    $totales['total'] += $f['total_colocado'];
}
function escribir_fila_subtotal_colocacion($hoja, &$fila, $columnaEtiqueta, $etiqueta, array $totales, $ultimaColumna, $colDevolucionesLetra, $rgb) {
    $hoja->setCellValue("{$columnaEtiqueta}{$fila}", $etiqueta);
    $hoja->setCellValue("C{$fila}", $totales['presupuesto']);
    $hoja->setCellValue("D{$fila}", $totales['adopciones']);
    $hoja->setCellValue("F{$fila}", $totales['poblacion']);
    $hoja->setCellValue("G{$fila}", $totales['compradores']);
    // Columnas E (Atenciones a Clientes), H (Descuento Promedio) e I (Numero de la Adopcion) sin
    // subtotal: una suma de atenciones o un promedio de promedios no tiene significado agregado
    // por Cliente/Empresa, y mezclado entre las filas de detalle resultaba confuso de leer
    // (usuario 2026-08-31, ver captura de un total de Atenciones mucho mayor que cualquier fila
    // individual apareciendo repetido en varias filas de subtotal).
    $hoja->setCellValue("K{$fila}", $totales['factura']);
    $hoja->setCellValue("L{$fila}", $totales['abonos']);
    $hoja->setCellValue("{$colDevolucionesLetra}{$fila}", $totales['devoluciones']);
    $hoja->setCellValue("{$ultimaColumna}{$fila}", $totales['total']);
    $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]],
    ]);
    $fila++;
}

$COLOR_SUBTOTAL_CLIENTE = 'FFFF00'; // amarillo
$COLOR_SUBTOTAL_EMPRESA = '92D050'; // verde

$fila = 2;
$totales = array_fill(0, $totalColumnas + 1, 0);
$totalesCliente = fila_totales_vacia_colocacion();
$totalesEmpresa = fila_totales_vacia_colocacion();
$empresaActual = null;
$clienteActual = null;
$huboFilaPrevia = false;

foreach ($filas as $f) {
    $cambioEmpresa = $huboFilaPrevia && $f['empresa'] !== $empresaActual;
    $cambioCliente = $huboFilaPrevia && ($cambioEmpresa || $f['cliente'] !== $clienteActual);

    if ($cambioCliente) {
        $etiquetaCliente = $clienteActual !== '' ? "Total {$clienteActual}" : 'Total Sin Cliente';
        escribir_fila_subtotal_colocacion($hoja, $fila, 'B', $etiquetaCliente, $totalesCliente, $ultimaColumna, $colDevolucionesLetra, $COLOR_SUBTOTAL_CLIENTE);
        $totalesCliente = fila_totales_vacia_colocacion();
    }
    if ($cambioEmpresa) {
        escribir_fila_subtotal_colocacion($hoja, $fila, 'A', "Total General {$empresaActual}", $totalesEmpresa, $ultimaColumna, $colDevolucionesLetra, $COLOR_SUBTOTAL_EMPRESA);
        $totalesEmpresa = fila_totales_vacia_colocacion();
    }

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

    $colIdx = $colInicioAbonosDet;
    foreach ($f['abonos_mov'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['tipo'] . ' #' . $mov['numero']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 2) . $fila, $mov['valor']);
        $colIdx += 3;
    }

    $colIdx = $colInicioWo;
    foreach ($f['colocacion_wo'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['tipo'] . ' #' . $mov['numero']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 2) . $fila, $mov['valor']);
        $colIdx += 3;
    }

    $colIdx = $colInicioPos;
    foreach ($f['colocacion_pos'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['tipo'] . ' #' . $mov['numero']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 2) . $fila, $mov['valor']);
        $colIdx += 3;
    }

    $colIdx = $colInicioFvDet;
    foreach ($f['factura_venta_mov'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['tipo'] . ' #' . $mov['numero']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 2) . $fila, $mov['valor']);
        $colIdx += 3;
    }

    $hoja->setCellValue("{$colDevolucionesLetra}{$fila}", $f['devoluciones']);
    $colIdx = $colInicioDevDet;
    foreach ($f['devoluciones_mov'] as $mov) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $fila, $mov['tipo'] . ' #' . $mov['numero']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . $fila, $mov['fecha']);
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 2) . $fila, $mov['valor']);
        $colIdx += 3;
    }

    $hoja->setCellValue(Coordinate::stringFromColumnIndex($colTotal) . $fila, $f['total_colocado']);

    $totales[3] += $f['presupuesto_crm'];
    $totales[4] += $f['adopciones_crm'];
    $totales[$colAtenciones] += $f['atenciones_clientes'];
    $totales[11] += $f['factura_venta'];
    $totales[12] += $f['abonos'];
    $totales[$colDevoluciones] += $f['devoluciones'];
    $totales[$colTotal] += $f['total_colocado'];
    $colIdx = $colInicioAbonosDet;
    foreach ($f['abonos_mov'] as $mov) {
        $totales[$colIdx + 2] += $mov['valor'];
        $colIdx += 3;
    }
    $colIdx = $colInicioWo;
    foreach ($f['colocacion_wo'] as $mov) {
        $totales[$colIdx + 2] += $mov['valor'];
        $colIdx += 3;
    }
    $colIdx = $colInicioPos;
    foreach ($f['colocacion_pos'] as $mov) {
        $totales[$colIdx + 2] += $mov['valor'];
        $colIdx += 3;
    }
    $colIdx = $colInicioFvDet;
    foreach ($f['factura_venta_mov'] as $mov) {
        $totales[$colIdx + 2] += $mov['valor'];
        $colIdx += 3;
    }
    $colIdx = $colInicioDevDet;
    foreach ($f['devoluciones_mov'] as $mov) {
        $totales[$colIdx + 2] += $mov['valor'];
        $colIdx += 3;
    }

    sumar_totales_colocacion($totalesCliente, $f);
    sumar_totales_colocacion($totalesEmpresa, $f);
    $empresaActual = $f['empresa'];
    $clienteActual = $f['cliente'];
    $huboFilaPrevia = true;

    $fila++;
}

// Cerrar el último bloque de Cliente/Empresa que quedó abierto.
if ($huboFilaPrevia) {
    $etiquetaCliente = $clienteActual !== '' ? "Total {$clienteActual}" : 'Total Sin Cliente';
    escribir_fila_subtotal_colocacion($hoja, $fila, 'B', $etiquetaCliente, $totalesCliente, $ultimaColumna, $colDevolucionesLetra, $COLOR_SUBTOTAL_CLIENTE);
    escribir_fila_subtotal_colocacion($hoja, $fila, 'A', "Total General {$empresaActual}", $totalesEmpresa, $ultimaColumna, $colDevolucionesLetra, $COLOR_SUBTOTAL_EMPRESA);
}

// Fila de "Total general" al pie.
$hoja->setCellValue("B{$fila}", 'Total general');
$hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
]);
foreach ($columnasDinero as $ci) {
    $col = Coordinate::stringFromColumnIndex($ci);
    $hoja->setCellValue("{$col}{$fila}", $totales[$ci]);
}

foreach ($columnasDinero as $ci) {
    $col = Coordinate::stringFromColumnIndex($ci);
    $hoja->getStyle("{$col}2:{$col}{$fila}")->getNumberFormat()->setFormatCode('_("$"* #,##0_);_("$"* (#,##0);_("$"* "-"??_);_(@_)');
}
// Descuento Promedio (columna H): mismo formato que php/descuento_adopciones_excel.php.
$hoja->getStyle("H2:H{$fila}")->getNumberFormat()->setFormatCode('0.00"%"');

foreach (range(1, $totalColumnas) as $ci) {
    $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
}

// ── Totales por Empresa y por Cliente, cada uno como hoja aparte (ver comentario de cabecera) ──
function agrupar_totales_colocacion(array $filas, $campo, $etiquetaVacio) {
    $grupos = [];
    foreach ($filas as $f) {
        $clave = ($f[$campo] !== null && $f[$campo] !== '') ? $f[$campo] : $etiquetaVacio;
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = ['colegios' => 0, 'presupuesto' => 0, 'adopciones' => 0, 'factura' => 0, 'colocacion_wo' => 0, 'colocacion_pos' => 0, 'devoluciones' => 0, 'atenciones' => 0, 'total' => 0];
        }
        $grupos[$clave]['colegios']++;
        $grupos[$clave]['presupuesto'] += $f['presupuesto_crm'];
        $grupos[$clave]['adopciones'] += $f['adopciones_crm'];
        $grupos[$clave]['factura'] += $f['factura_venta'];
        $grupos[$clave]['colocacion_wo'] += array_sum(array_column($f['colocacion_wo'], 'valor'));
        $grupos[$clave]['colocacion_pos'] += array_sum(array_column($f['colocacion_pos'], 'valor'));
        $grupos[$clave]['devoluciones'] += $f['devoluciones'];
        $grupos[$clave]['atenciones'] += $f['atenciones_clientes'];
        $grupos[$clave]['total'] += $f['total_colocado'];
    }
    ksort($grupos, SORT_STRING | SORT_FLAG_CASE);
    return $grupos;
}

function escribir_hoja_resumen_colocacion(Spreadsheet $objSpreadsheet, $titulo, $tituloColumna, array $grupos) {
    $hoja = $objSpreadsheet->createSheet();
    $hoja->setTitle($titulo);

    $encabezados = [$tituloColumna, 'Colegios', 'Presupuesto Registrado en CRM', 'Adopciones CRM', 'Atenciones a Clientes', 'Factura de Venta', 'Colocacion World Office (REM-CEUR)', 'Facturas POS', 'Devoluciones', 'Total Colocado'];
    foreach ($encabezados as $i => $t) {
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $t);
    }
    $hoja->getStyle('A1:J1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
    ]);
    $hoja->freezePane('A2');

    $fila = 2;
    $totalGeneral = ['colegios' => 0, 'presupuesto' => 0, 'adopciones' => 0, 'factura' => 0, 'colocacion_wo' => 0, 'colocacion_pos' => 0, 'devoluciones' => 0, 'atenciones' => 0, 'total' => 0];
    foreach ($grupos as $nombre => $g) {
        $hoja->setCellValue("A{$fila}", $nombre);
        $hoja->setCellValue("B{$fila}", $g['colegios']);
        $hoja->setCellValue("C{$fila}", $g['presupuesto']);
        $hoja->setCellValue("D{$fila}", $g['adopciones']);
        $hoja->setCellValue("E{$fila}", $g['atenciones']);
        $hoja->setCellValue("F{$fila}", $g['factura']);
        $hoja->setCellValue("G{$fila}", $g['colocacion_wo']);
        $hoja->setCellValue("H{$fila}", $g['colocacion_pos']);
        $hoja->setCellValue("I{$fila}", $g['devoluciones']);
        $hoja->setCellValue("J{$fila}", $g['total']);
        foreach ($totalGeneral as $k => $v) $totalGeneral[$k] += $g[$k];
        $fila++;
    }

    $hoja->setCellValue("A{$fila}", 'Total general');
    $hoja->setCellValue("B{$fila}", $totalGeneral['colegios']);
    $hoja->setCellValue("C{$fila}", $totalGeneral['presupuesto']);
    $hoja->setCellValue("D{$fila}", $totalGeneral['adopciones']);
    $hoja->setCellValue("E{$fila}", $totalGeneral['atenciones']);
    $hoja->setCellValue("F{$fila}", $totalGeneral['factura']);
    $hoja->setCellValue("G{$fila}", $totalGeneral['colocacion_wo']);
    $hoja->setCellValue("H{$fila}", $totalGeneral['colocacion_pos']);
    $hoja->setCellValue("I{$fila}", $totalGeneral['devoluciones']);
    $hoja->setCellValue("J{$fila}", $totalGeneral['total']);
    $hoja->getStyle("A{$fila}:J{$fila}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    ]);

    foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
        $hoja->getStyle("{$col}2:{$col}{$fila}")->getNumberFormat()->setFormatCode('_("$"* #,##0_);_("$"* (#,##0);_("$"* "-"??_);_(@_)');
    }
    foreach (range(1, 10) as $ci) {
        $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
    }
}

escribir_hoja_resumen_colocacion($objSpreadsheet, 'Totales por Empresa', 'Empresa', agrupar_totales_colocacion($filas, 'empresa', 'Sin asignar'));
escribir_hoja_resumen_colocacion($objSpreadsheet, 'Totales por Cliente', 'Cliente', agrupar_totales_colocacion($filas, 'cliente', 'Sin cliente'));
$objSpreadsheet->setActiveSheetIndex(0);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Colocacion_Calendario_' . $datos['calendario'] . '_' . preg_replace('/[^A-Za-z0-9]/', '', $datos['periodo']) . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
