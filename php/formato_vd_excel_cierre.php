<?php
/**
 * /php/formato_vd_excel_cierre.php  (lo incluye php/formato_vd_excel.php; no se abre solo)
 * Excel del formato de venta directa CON LA VENTA CERRADA y la tabla por grado del cierre (2026-11-26, la usuaria): misma
 * estructura del formato en Excel que maneja la usuaria (Formato_venta_directa_2_Cooperativo_Venecia_Ver_3.xlsx):
 *  - Inventario y ventas: Título | Detalle | Solicitud | Inventario entregado | Unidades devueltas | Inventario final |
 *    Unidades vendidas | Precio venta | Total venta (= precio × vendidas, con fórmula) | nota (J).
 *    · Un renglón por paquete vendido (2026-11-27, la usuaria), con borde grueso: en A el paquete ("Paquete 1 (Primero)"),
 *      en B sus libros; vendidas = kits del paquete y precio = precio del paquete (total = kits × precio del paquete).
 *    · LIBROS SUELTOS: en A el grado y en B el libro; vendidas = unidades sueltas, precio = precio suelto.
 *    · Solicitud / entregado / devueltas / inventario final de cada libro van en LIBROS SUELTOS (todos los libros aparecen
 *      ahí una vez, aunque no tengan sueltos), para que los totales no se dupliquen. J = "N libros pagados pero no había en bodega" (columna Sin inventario).
 *  - TOTALES (total venta en amarillo); total precio kit, total precio suelto y GRAN TOTAL; VENTA (efectivo, datáfono,
 *    Bre-B, recaudo de la venta en amarillo y diferencia con el total) y BASE DE CAJA (base entregada − gastos); Cierre =
 *    base devuelta + recaudo; NOTA; OBSERVACIONES y pie.
 * Usa las variables de php/formato_vd_excel.php: $hoja, $f, $cl, $fila, $PESOS, $borde, $relleno, $texto, $seccion,
 *  $AMARILLO y $paq_incompleto (en amarillo los libros remisionados por debajo de lo solicitado y sus paquetes).
 * Termina escribiendo el archivo (exit).
 */
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$medio = ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '000000']]]];
$fino = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]]];

// Encabezados de la tabla.
$hoja->fromArray(['Título', 'Detalle', 'Solicitud', 'Inventario entregado', 'Unidades devueltas', 'Inventario final', 'Unidades vendidas', 'Precio venta', 'Total venta'], null, "A$fila");
$hoja->getStyle("A$fila:I$fila")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$hoja->getStyle("A$fila:I$fila")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
$relleno("A$fila:I$fila", '1F3864');
$hoja->getStyle("A$fila:I$fila")->applyFromArray($fino);
$hoja->getRowDimension($fila)->setRowHeight(30.75);
$fila++;
$ini = $fila;

// Filas por id de la tabla del cierre.
$porId = [];
foreach ($cl['grados'] as $g) foreach ($g['filas'] as $r) $porId[(int)$r['id']] = $r + ['grado_txt' => $g['grado']];
$mostrado = []; // id de fila => ya se escribieron sus cantidades
// Nota (J): solo lo escrito en Sin inventario.
$sinBodega = function ($n) { $n = (int)$n; return $n > 0 ? $n . ' libro' . ($n === 1 ? '' : 's') . ' pagado' . ($n === 1 ? '' : 's') . ' pero no había en bodega' : ''; };
$nota = fn($r) => $sinBodega((int)$r['sin_inventario']);
$escribir = function ($r, $vendidas, $precio) use ($hoja, $texto, &$fila, &$mostrado, $nota) {
    $texto("B$fila", $r['libro']);
    if (!isset($mostrado[(int)$r['id']])) {
        $hoja->setCellValue("C$fila", (int)$r['solicitado']);
        $hoja->setCellValue("D$fila", (int)$r['remisionado']);
        $hoja->setCellValue("E$fila", (int)$r['devuelto']);
        $hoja->setCellValue("F$fila", (int)$r['devuelto']); // inventario final = lo que volvió
        if (($n = $nota($r)) !== '') $texto("J$fila", $n);
        $mostrado[(int)$r['id']] = true;
    }
    $hoja->setCellValue("G$fila", (int)$vendidas);
    $hoja->setCellValue("H$fila", (float)$precio);
    $hoja->setCellValue("I$fila", "=+H$fila*G$fila");
};

// 1) Un renglón por paquete vendido: kits × precio del paquete.
foreach ($cl['paquetes'] as $p) {
    if ($p['kits'] <= 0) continue;
    $texto("A$fila", $p['paquete']);
    $texto("B$fila", implode(', ', array_column($p['libros'], 'nombre')));
    $hoja->getStyle("B$fila")->getAlignment()->setWrapText(true);
    $hoja->setCellValue("G$fila", (int)$p['kits']);
    $hoja->setCellValue("H$fila", (float)$p['precio']);
    $hoja->setCellValue("I$fila", "=+H$fila*G$fila");
    $hoja->getStyle("A$fila:I$fila")->applyFromArray($fino);
    $hoja->getStyle("A$fila:I$fila")->applyFromArray($medio);
    if ($paq_incompleto($p)) $relleno("A$fila:I$fila", $AMARILLO);
    $fila++;
}
$finKits = $fila - 1;

// 2) Libros sueltos: lo vendido suelto y los libros que aún no aparecen (para que sus cantidades queden en el formato).
$hoja->setCellValue("A$fila", 'LIBROS SUELTOS');
$hoja->getStyle("A$fila")->getFont()->setBold(true);
$hoja->getStyle("A$fila:I$fila")->applyFromArray($fino);
$fila++;
$iniSue = $fila;
foreach ($cl['grados'] as $g) {
    foreach ($g['filas'] as $r) {
        $texto("A$fila", $g['grado']);
        $escribir($r, (int)$r['suelto_ef'], $r['precio_suelto']);
        if ($r['faltante']) $relleno("A$fila:I$fila", $AMARILLO);
        $fila++;
    }
}
$finSue = $fila - 1;
if ($finSue >= $iniSue) {
    $hoja->getStyle("A$iniSue:I$finSue")->applyFromArray($fino);
    $hoja->getStyle("A$iniSue:I$finSue")->applyFromArray($medio);
}
$fin = $fila - 1;
if ($fin >= $ini) {
    $hoja->getStyle("B$ini:B$fin")->getFont()->setSize(9)->getColor()->setRGB('475569');
    $hoja->getStyle("C$ini:G$fin")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $hoja->getStyle("H$ini:I$fin")->getNumberFormat()->setFormatCode($PESOS);
    $hoja->getStyle("A$ini:A$fin")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
}

// TOTALES (el total de venta en amarillo, como en el formato de la usuaria).
$tot = $fila;
$hoja->setCellValue("A$tot", 'TOTALES');
foreach (['C', 'D', 'E', 'F', 'I'] as $c) $hoja->setCellValue("$c$tot", "=SUM($c$ini:$c$fin)");
$hoja->getStyle("A$tot:I$tot")->getFont()->setBold(true);
$relleno("A$tot:H$tot", 'F1F5F9');
$relleno("I$tot", 'FFFF00');
$hoja->getStyle("I$tot")->getNumberFormat()->setFormatCode($PESOS);
$hoja->getStyle("C$tot:G$tot")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$hoja->getStyle("A$tot:I$tot")->applyFromArray($fino);
$hoja->getStyle("A$tot:I$tot")->applyFromArray($medio);

// Resumen (A:B etiqueta, C:D valor) y VENTA (E:F etiqueta, G valor) lado a lado.
$r0 = $tot + 2;
$resumen = [
    ['TOTAL PRECIO KIT (' . (int)$cl['kits'] . ' kit' . ((int)$cl['kits'] === 1 ? '' : 's') . ')', $finKits >= $ini ? "=SUM(I$ini:I$finKits)" : 0, true],
    ['TOTAL PRECIO SUELTO', $finSue >= $iniSue ? "=SUM(I$iniSue:I$finSue)" : 0, true],
    ['GRAN TOTAL', "=+C$r0+C" . ($r0 + 1), true],
    ['LIBROS SIN INVENTARIO', array_sum(array_map(fn($r) => (int)$r['sin_inventario'], $porId)), false],
    ['TOTAL DE CAJAS', $f['total_cajas'] !== null ? (int)$f['total_cajas'] : '', false],
    ['DEVOLUCIÓN INKPULSE', $f['legalizacion'] ? $f['legalizacion']['texto'] : '', false],
];
foreach ($resumen as $i => [$lbl, $v, $pesos]) {
    $r = $r0 + $i;
    $hoja->setCellValue("A$r", $lbl);
    $hoja->mergeCells("A$r:B$r");
    if (is_string($v) && $v !== '' && $v[0] !== '=') $texto("C$r", $v); else $hoja->setCellValue("C$r", $v);
    $hoja->mergeCells("C$r:D$r");
    if ($pesos) $hoja->getStyle("C$r")->getNumberFormat()->setFormatCode($PESOS);
    $hoja->getStyle("C$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}
$rFin = $r0 + count($resumen) - 1;
$relleno("A$r0:A$rFin", 'E5E7EB');
$hoja->getStyle("A$r0:A$rFin")->getFont()->setBold(true)->setSize(9);
$hoja->getStyle("A" . ($r0 + 2) . ":D" . ($r0 + 2))->getFont()->setBold(true);
$hoja->getStyle("A$r0:D$rFin")->applyFromArray($fino);

$hoja->setCellValue("E$r0", 'VENTA');
$hoja->mergeCells("E$r0:G$r0");
$hoja->getStyle("E$r0")->getFont()->setBold(true);
$hoja->getStyle("E$r0")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$venta = [['EFECTIVO', (float)$f['efectivo']], ['DATAFONO', (float)$f['datafono']], ['BRE-B', (float)$f['breb']]];
foreach ($venta as $i => [$lbl, $v]) {
    $r = $r0 + 1 + $i;
    $hoja->setCellValue("E$r", $lbl);
    $hoja->mergeCells("E$r:F$r");
    $hoja->setCellValue("G$r", $v);
}
$rRec = $r0 + 4;
$rDif = $r0 + 5;
$hoja->setCellValue("E$rRec", 'RECAUDO DE LA VENTA');
$hoja->mergeCells("E$rRec:F$rRec");
$hoja->setCellValue("G$rRec", "=SUM(G" . ($r0 + 1) . ":G" . ($r0 + 3) . ")");
$relleno("G$rRec", 'FFFF00');
$hoja->setCellValue("E$rDif", 'DIFERENCIA');
$hoja->mergeCells("E$rDif:F$rDif");
$hoja->setCellValue("G$rDif", "=+G$rRec-I$tot");
$hoja->setCellValue("H$rDif", 'Recaudo − total venta');
$hoja->getStyle("H$rDif")->getFont()->setSize(9)->getColor()->setRGB('64748B');
$hoja->getStyle("E" . ($r0 + 1) . ":E$rDif")->getFont()->setBold(true);
$hoja->getStyle("G$rRec")->getFont()->setBold(true);
$hoja->getStyle("G$rDif")->getFont()->setBold(true)->getColor()->setRGB(round((float)$f['diferencia']) == 0 ? '15803D' : 'DC2626');
$hoja->getStyle("G" . ($r0 + 1) . ":G$rDif")->getNumberFormat()->setFormatCode($PESOS);
$hoja->getStyle("E$r0:G$rDif")->applyFromArray($fino);
$hoja->getStyle("E$r0:G$rDif")->applyFromArray($medio);

// BASE DE CAJA (debajo del resumen).
$b0 = $rFin + 2;
$hoja->setCellValue("A$b0", 'BASE DE CAJA');
$hoja->getStyle("A$b0")->getFont()->setBold(true);
$caja = [['BASE ENTREGADA', (float)$f['base_entregada']], ['GASTOS', (float)$f['gastos']], ['VALOR AUXILIARES', (float)$f['auxiliares_total']]];
foreach ($caja as $i => [$lbl, $v]) {
    $r = $b0 + 1 + $i;
    $hoja->setCellValue("A$r", $lbl);
    $hoja->setCellValue("B$r", $v);
}
$bTot = $b0 + 1 + count($caja);
$hoja->setCellValue("A$bTot", 'TOTAL');
$hoja->setCellValue("B$bTot", "=+B" . ($b0 + 1) . "-B" . ($b0 + 2)); // base devuelta = base entregada − gastos
$hoja->getStyle("A" . ($b0 + 1) . ":A$bTot")->getFont()->setBold(true);
$hoja->getStyle("B" . ($b0 + 1) . ":B$bTot")->getNumberFormat()->setFormatCode($PESOS);
$hoja->getStyle("A$b0:B$bTot")->applyFromArray($fino);
$cierre = $bTot + 2;
$hoja->setCellValue("A$cierre", 'Cierre');
$hoja->setCellValue("B$cierre", "=+B$bTot+G$rRec"); // base devuelta + recaudo de la venta
$hoja->getStyle("A$cierre:B$cierre")->getFont()->setBold(true);
$hoja->getStyle("B$cierre")->getNumberFormat()->setFormatCode($PESOS);

// NOTA (recuadro para escribir a mano).
$n0 = $rDif + 3;
$hoja->setCellValue("E$n0", 'NOTA:');
$hoja->mergeCells("E$n0:I" . ($n0 + 3));
$hoja->getStyle("E$n0")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
$hoja->getStyle("E$n0:I" . ($n0 + 3))->applyFromArray($medio);

// OBSERVACIONES y pie.
$fila = max($cierre, $n0 + 3) + 2;
$obs = trim((string)$f['observaciones'] . ($f['obs_cierre'] ? "\n\nCierre de dinero: " . $f['obs_cierre'] : ''));
$hoja->setCellValue("A$fila", 'OBSERVACIONES');
$hoja->mergeCells("A$fila:I$fila");
$hoja->getStyle("A$fila")->getFont()->setBold(true);
$relleno("A$fila:I$fila", 'E2E8F0');
$fila++;
$texto("A$fila", $obs);
$hoja->mergeCells("A$fila:I$fila");
$hoja->getStyle("A$fila")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$hoja->getRowDimension($fila)->setRowHeight(max(30, 15 * (substr_count($obs, "\n") + 2)));
$hoja->getStyle("A$fila:I$fila")->applyFromArray($fino);
$fila += 2;
$hoja->setCellValue("A$fila", fvd_numero($f['consecutivo']) . ' · ' . $f['colegio'] . ' · Descargado el ' . date('d/m/Y g:i A'));
$hoja->getStyle("A$fila")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');

foreach (['A' => 46, 'B' => 32.4, 'C' => 12, 'D' => 14, 'E' => 13, 'F' => 13, 'G' => 13, 'H' => 18.7, 'I' => 16, 'J' => 39.6] as $c => $w) $hoja->getColumnDimension($c)->setWidth($w);

$nombre = 'Formato_venta_directa_' . (int)$f['consecutivo'] . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $f['colegio']) ?: 'colegio');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . trim($nombre, '_') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($libro))->save('php://output');
exit;
