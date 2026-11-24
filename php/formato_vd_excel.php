<?php
/**
 * /php/formato_vd_excel.php?id=N
 * Descarga en Excel del formato de venta directa (2026-11-19, la usuaria): lo mismo del "formato completo" de
 * ver_formato_venta_directa.php (datos de la venta, inventario y ventas, caja y observaciones), sin el historial de etapas.
 * 2026-11-23 (la usuaria): número de la adopción junto al colegio, pedidos y remisiones en el encabezado de Solicitud e Inventario
 * entregado, "Recibido final", libros sueltos por curso, y "Datos de la operación" / "Cierre de dinero" lado a lado como
 * en la pantalla.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/formatos_vd_datos.php");

dm_validar_acceso(false, "../");
fvd_asegurar_tablas($bdd);

$f = fvd_obtener($bdd, (int)($_GET['id'] ?? 0));
if (!$f) {
    header('Location: ../formatos_venta_directa.php?ink_status=error&ink_msg=' . urlencode('El formato no existe o no está en tu alcance.'));
    exit;
}
if ($f['automatico'] && $f['activo'] && $f['etapa'] < FVD_ETAPA_CERRADO) {
    header('Location: ../formato_venta_directa.php?id=' . (int)$f['id'] . '&ink_status=info&ink_msg=' . urlencode('El formato se genera al confirmar el cierre de dinero.'));
    exit;
}
if ($f['automatico'] && fvd_completar_datos_venta($bdd, $f['id'])) $f = fvd_obtener($bdd, $f['id']);
if (fvd_asegurar_referencias($bdd, $f)) $f = fvd_obtener($bdd, $f['id']);

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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$entregado = $f['etapa'] >= FVD_ETAPA_ENTREGADO;
$devuelto = $f['etapa'] >= FVD_ETAPA_DEV_GENERADA;
$conciliado = $f['etapa'] >= FVD_ETAPA_CONCILIADO;
$recibido = $f['etapa'] >= FVD_ETAPA_RECIBIDO;
$sin_bodega = !empty($f['automatico']);
$con_rec = $recibido && !$sin_bodega;
$horas = trim(($f['hora_inicio'] ? date('g:i A', strtotime($f['hora_inicio'])) : '') . ($f['hora_fin'] ? ' - ' . date('g:i A', strtotime($f['hora_fin'])) : ''));
$PESOS = '"$" #,##0;-"$" #,##0';

$libro = new Spreadsheet();
$libro->getProperties()->setCreator('Inkpulse')->setTitle(fvd_numero($f['consecutivo']));
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Formato ' . (int)$f['consecutivo']);
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER)
     ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
// Columnas: A título, B detalle, C solicitud, D entregado, E devueltas,
// F recibido final, G vendidas, H precio, I total, (J recibidas bodega)
$ult = $con_rec ? 'J' : 'I';
$borde = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]]];
$relleno = function ($rango, $color) use ($hoja) { $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color); };
$texto = function ($celda, $v) use ($hoja) { $hoja->setCellValueExplicit($celda, (string)$v, DataType::TYPE_STRING); };
$seccion = function ($fila, $txt, $desde = 'A', $hasta = null) use ($hoja, $ult, $relleno) {
    $hasta = $hasta ?? $ult;
    $hoja->setCellValue("$desde$fila", mb_strtoupper($txt));
    $hoja->mergeCells("$desde$fila:$hasta$fila");
    $hoja->getStyle("$desde$fila")->getFont()->setBold(true);
    $relleno("$desde$fila:$hasta$fila", 'E2E8F0');
};

// Encabezado
$hoja->setCellValue('A1', mb_strtoupper(fvd_numero($f['consecutivo'])) . (!$f['activo'] ? ' (ANULADO)' : ''));
$hoja->mergeCells("A1:{$ult}1");
$hoja->setCellValue('A2', mb_strtoupper($f['colegio']) . ($f['numero_adopcion'] ? ' (' . $f['numero_adopcion'] . ')' : ''));
$hoja->mergeCells("A2:{$ult}2");
$hoja->getStyle("A1:A2")->getFont()->setBold(true);
$hoja->getStyle('A1')->getFont()->setSize(14);
$hoja->getStyle("A1:A2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$pedidos = implode(', ', array_map(fn($c) => '#' . (int)substr($c, 1), array_filter(explode(',', (string)$f['ids_pedidos']))));
$datos = [
    ['Fecha venta', $f['fecha_venta'] ? date('d/m/Y', strtotime($f['fecha_venta'])) : '', 'Hora de venta', $horas],
    ['Responsable venta', $f['responsable'], 'Tel del responsable', $f['tel_responsable']],
    ['Responsable colegio', $f['contacto_colegio'], 'Tel contacto', $f['tel_contacto']],
    ['Dirección', $f['direccion'], 'Auxiliares', fvd_auxiliares_texto($f)],
    ['Pedidos', $pedidos . ' · Período ' . $f['periodo'], 'Solicitó', ($f['usuario'] ?: '—') . ' · ' . date('d/m/Y g:i A', strtotime($f['created_at']))],
];
$fila = 4;
foreach ($datos as $d) {
    $hoja->setCellValue("A$fila", $d[0]); $texto("B$fila", $d[1]);
    $hoja->mergeCells("B$fila:D$fila");
    $hoja->setCellValue("E$fila", $d[2]); $texto("F$fila", $d[3]);
    $hoja->mergeCells("F$fila:$ult$fila");
    $hoja->getStyle("A$fila")->getFont()->setBold(true);
    $hoja->getStyle("E$fila")->getFont()->setBold(true);
    $fila++;
}
$hoja->getStyle("A4:$ult" . ($fila - 1))->applyFromArray($borde);

// Inventario y ventas
$fila++;
$seccion($fila, 'Inventario y ventas');
$fila++;
// Pedidos y remisiones del formato solo en el encabezado de Solicitud / Inventario entregado (2026-11-23).
$enc_sol = fvd_encabezado_solicitud($f);
$enc_rem = fvd_encabezado_remisiones($f);
$tit = ['Título', 'Detalle', 'Solicitud' . ($enc_sol !== '' ? "\n" . $enc_sol : ''), 'Inventario entregado' . ($enc_rem !== '' ? "\n" . $enc_rem : ''),
        'Unidades devueltas', 'Recibido final', 'Unidades vendidas', 'Precio venta', 'Total venta'];
if ($con_rec) $tit[] = 'Recibidas bodega';
$hoja->fromArray($tit, null, "A$fila");
$hoja->getStyle("A$fila:$ult$fila")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$hoja->getStyle("A$fila:$ult$fila")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
$relleno("A$fila:$ult$fila", '1F3864');
$hoja->getRowDimension($fila)->setRowHeight(max(32, 13 * (1 + (int)ceil(mb_strlen($enc_rem) / 22))));
$cab = $fila;
$fila++;
$ini = $fila;
$filasCurso = [];
foreach (fvd_filas_por_curso($bdd, $f) as $x) {
    if (isset($x['curso'])) { // libros sueltos y plan lector por curso de la adopción (2026-11-23)
        $plan = strpos($x['curso'], 'Plan lector') === 0;
        $hoja->setCellValue("A$fila", mb_strtoupper($x['curso']));
        $hoja->mergeCells("A$fila:$ult$fila");
        $hoja->getStyle("A$fila")->getFont()->setBold(true)->setSize(9);
        if ($plan) $hoja->getStyle("A$fila")->getFont()->getColor()->setRGB('166534');
        $relleno("A$fila:$ult$fila", $plan ? 'DCFCE7' : 'E2E8F0');
        $filasCurso[] = $fila;
        $fila++;
        continue;
    }
    $it = $x['item'];
    $texto("A$fila", $it['descripcion']);
    // 2 precios (2026-11-24): en el detalle, cuántas vendidas fueron a cada precio.
    $n2_x = $conciliado ? min(max(0, (int)($it['vendidas_2'] ?? 0)), max(0, (int)$it['vendidas'])) : 0;
    $p2_x = (float)($it['precio_2'] ?? 0) ?: (float)$it['precio'];
    $texto("B$fila", trim((string)$it['detalle'] . ($n2_x ? ' · Vendidas: ' . ((int)$it['vendidas'] - $n2_x) . ' × $' . number_format((float)$it['precio'], 0, ',', '.')
                                                          . ' + ' . $n2_x . ' × $' . number_format($p2_x, 0, ',', '.') : ''), ' ·'));
    $hoja->setCellValue("C$fila", (int)$it['solicitud']);
    if ($entregado) $hoja->setCellValue("D$fila", (int)$it['inv_inicial']);
    if ($devuelto) $hoja->setCellValue("E$fila", (int)$it['devueltas']);
    if ($conciliado) {
        $hoja->setCellValue("F$fila", (int)$it['inv_final']);
        $hoja->setCellValue("G$fila", (int)$it['vendidas']);
        $hoja->setCellValue("H$fila", (float)$it['precio']);
        $hoja->setCellValue("I$fila", (float)$it['total']);
    }
    if ($con_rec) $hoja->setCellValue("J$fila", (int)$it['recibidas']);
    $fila++;
}
$fin = $fila - 1;
$hoja->setCellValue("A$fila", 'TOTALES');
foreach (array_merge(['C', 'D', 'E', 'F', 'G', 'I'], $con_rec ? ['J'] : []) as $c) {
    if ($fin >= $ini) $hoja->setCellValue("$c$fila", "=SUM($c$ini:$c$fin)");
}
$hoja->getStyle("A$fila:$ult$fila")->getFont()->setBold(true);
$relleno("A$fila:$ult$fila", 'F1F5F9');
$hoja->getStyle("H$ini:I$fila")->getNumberFormat()->setFormatCode($PESOS);
$hoja->getStyle("C$ini:G$fila")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
if ($con_rec) $hoja->getStyle("J$ini:J$fila")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
if ($fin >= $ini) {
    $hoja->getStyle("B$ini:B$fin")->getFont()->setSize(9)->getColor()->setRGB('475569');
    $hoja->getStyle("A$ini:$ult$fin")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $hoja->getStyle("A$ini:B$fin")->getAlignment()->setWrapText(true);
    foreach ($filasCurso as $fc) $hoja->getStyle("A$fc")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(false);
}
$hoja->getStyle("A$cab:$ult$fila")->applyFromArray($borde);
$fila += 2;

// Datos de la operación (A:B) y cierre de dinero (E:I) lado a lado, como en la pantalla.
$operacion = [];
if (!$sin_bodega) $operacion[] = ['Aux. bodega que entrega mercancía:', (string)$f['aux_entrega'], false];
$operacion[] = ['Total de cajas:', $f['total_cajas'] !== null ? (string)(int)$f['total_cajas'] : '', false];
$operacion[] = ['Devolución InkPulse:', $f['legalizacion'] ? $f['legalizacion']['texto'] : '', false];
$operacion[] = ['Auxiliares:', fvd_auxiliares_texto($f), false];
$operacion[] = ['Valor total inventario devuelto', (float)$f['valor_inventario'], true];
if (!$sin_bodega) {
    $operacion[] = ['Aux. bodega que recibe mercancía:', (string)$f['aux_recibe'], false];
    $operacion[] = ['Fecha y hora recibido:', $f['recibido_at'] ? date('d/m/Y g:i A', strtotime($f['recibido_at'])) : '', false];
}
$dinero = [
    ['Base entregada:', (float)$f['base_entregada']],
    ['Gastos:', (float)$f['gastos']],
    ['Valor auxiliares:', (float)$f['auxiliares_total']],
    ['Base devuelta:', (float)$f['base_devuelta']],
    ['Efectivo', (float)$f['efectivo']],
    ['Datáfono', (float)$f['datafono']],
    ['Bre-B', (float)$f['breb']],
    ['Suma total recaudada', (float)$f['suma_total']],
    ['Total venta', (float)$f['total_venta']],
    ['Diferencia', (float)$f['diferencia']],
];
$seccion($fila, 'Datos de la operación', 'A', 'B');
$seccion($fila, 'Cierre de dinero', 'E', 'I');
$hoja->getStyle("A$fila:B$fila")->applyFromArray($borde);
$hoja->getStyle("E$fila:I$fila")->applyFromArray($borde);
$fila++;
$iniCaja = $fila;
foreach ($operacion as $i => [$lbl, $v, $esPesos]) {
    $r = $iniCaja + $i;
    $hoja->setCellValue("A$r", mb_strtoupper($lbl));
    if ($esPesos) { $hoja->setCellValue("B$r", $v); $hoja->getStyle("B$r")->getNumberFormat()->setFormatCode($PESOS); }
    else $texto("B$r", $v);
    $hoja->getStyle("B$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setWrapText(true);
}
$finOp = $iniCaja + count($operacion) - 1;
$relleno("A$iniCaja:A$finOp", 'E5E7EB');
$hoja->getStyle("A$iniCaja:A$finOp")->getFont()->setBold(true)->setSize(9);
$hoja->getStyle("A$iniCaja:B$finOp")->applyFromArray($borde);
foreach ($dinero as $i => [$lbl, $v]) {
    $r = $iniCaja + $i;
    $hoja->setCellValue("E$r", mb_strtoupper($lbl));
    $hoja->mergeCells("E$r:G$r");
    $hoja->setCellValue("H$r", $v);
    $hoja->mergeCells("H$r:I$r");
    $hoja->getStyle("H$r")->getNumberFormat()->setFormatCode($PESOS);
    $hoja->getStyle("H$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    if (in_array($lbl, ['Suma total recaudada', 'Total venta', 'Diferencia'], true)) $hoja->getStyle("H$r")->getFont()->setBold(true);
    if ($lbl === 'Diferencia') $hoja->getStyle("H$r")->getFont()->getColor()->setRGB(round($v) == 0 ? '15803D' : 'DC2626');
}
$finDin = $iniCaja + count($dinero) - 1;
$relleno("E$iniCaja:G$finDin", 'E5E7EB');
$hoja->getStyle("E$iniCaja:E$finDin")->getFont()->setBold(true)->setSize(9);
$hoja->getStyle("E$iniCaja:I$finDin")->applyFromArray($borde);
$fila = max($finOp, $finDin) + 2;

// Observaciones
$obs = trim((string)$f['observaciones']
    . ($f['obs_recepcion'] && !$sin_bodega ? "\n\nRecepción en bodega: " . $f['obs_recepcion'] : '')
    . ($f['obs_cierre'] ? "\n\nCierre de dinero: " . $f['obs_cierre'] : ''));
$seccion($fila, 'Observaciones');
$fila++;
$texto("A$fila", $obs);
$hoja->mergeCells("A$fila:$ult$fila");
$hoja->getStyle("A$fila")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$hoja->getRowDimension($fila)->setRowHeight(max(30, 15 * (substr_count($obs, "\n") + 2)));
$hoja->getStyle("A$fila:$ult$fila")->applyFromArray($borde);
$fila += 2;
$hoja->setCellValue("A$fila", fvd_numero($f['consecutivo']) . ' · ' . $f['colegio'] . ' · Descargado el ' . date('d/m/Y g:i A'));
$hoja->getStyle("A$fila")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');

foreach (['A' => 46, 'B' => 30, 'C' => 18, 'D' => 24, 'E' => 13, 'F' => 13, 'G' => 13, 'H' => 15, 'I' => 16, 'J' => 13] as $c => $w) $hoja->getColumnDimension($c)->setWidth($w);

$nombre = 'Formato_venta_directa_' . (int)$f['consecutivo'] . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $f['colegio']) ?: 'colegio');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . trim($nombre, '_') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($libro))->save('php://output');
