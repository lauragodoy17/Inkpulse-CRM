<?php
/**
 * Generación de la planilla en PDF (pedido -> bodega -> facturación ->
 * despacho, con firmas de "recibido por"), compartida entre los tres
 * módulos que pasan de Aprobado a Procesando: pedidos (con adopción),
 * pedidos sin adopción y muestreo. Cada módulo pasa su propio $tipo_label
 * para dejar explícito en la planilla de cuál de los tres se trata.
 */

// Los fonts core de FPDF (Arial/Helvetica) están en Windows-1252/ISO-8859-1,
// no en UTF-8; utf8_decode() está deprecado desde PHP 8.2, así que se
// convierte con mbstring, que es la alternativa vigente.
function lp_latin1($s) {
    return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
}

function planilla_pdf_columnas() {
    // Calcada de la planilla física en papel que ya usan (pedido -> bodega ->
    // facturación -> despacho, cada tramo con fecha/hora y una firma de
    // "recibido por"). Solo Pedido/Fecha Pedido/Cliente se llenan con datos
    // del sistema; el resto queda en blanco para diligenciar a mano.
    return [
        ['key' => 'pedido',    'label' => ['No.', 'Pedido'],        'w' => 14, 'grupo' => null],
        ['key' => 'fecha_ped', 'label' => ['Fecha', 'Pedido'],      'w' => 16, 'grupo' => null],
        ['key' => 'cliente',   'label' => ['Cliente'],              'w' => 38, 'grupo' => null],
        ['key' => 'bod_fecha', 'label' => ['Fecha', 'Entrega'],     'w' => 16, 'grupo' => 'BODEGA'],
        ['key' => 'bod_hora',  'label' => ['Hora', 'Entrega'],      'w' => 13, 'grupo' => 'BODEGA'],
        ['key' => 'bod_rec',   'label' => ['Recibido', 'por'],      'w' => 24, 'grupo' => 'BODEGA'],
        ['key' => 'fact_fecha','label' => ['Entrega', 'Fact.'],     'w' => 16, 'grupo' => 'FACTURACION'],
        ['key' => 'fact_hora', 'label' => ['Hora'],                 'w' => 12, 'grupo' => 'FACTURACION'],
        ['key' => 'fact_rec',  'label' => ['Recibido', 'por'],      'w' => 24, 'grupo' => 'FACTURACION'],
        ['key' => 'no_doc',    'label' => ['No.', 'Doc.'],          'w' => 14, 'grupo' => null],
        ['key' => 'rec_bod',   'label' => ['Recibido', 'Bodega'],   'w' => 20, 'grupo' => null],
        ['key' => 'ent_desp',  'label' => ['Entrega', 'Despacho'],  'w' => 16, 'grupo' => null],
        ['key' => 'rec_desp',  'label' => ['Recibido', 'Despach.'], 'w' => 24, 'grupo' => null],
    ];
}

function pdf_multiline_header(FPDF $pdf, $w, $h, $lines) {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->Cell($w, $h, '', 1, 0, 'C', true);
    $n = max(count($lines), 1);
    $lh = $h / $n;
    foreach ($lines as $i => $line) {
        $pdf->SetXY($x, $y + $i * $lh);
        $pdf->Cell($w, $lh, lp_latin1($line), 0, 0, 'C');
    }
    $pdf->SetXY($x + $w, $y);
}

function pdf_fit_text(FPDF $pdf, $text, $maxw) {
    $text = lp_latin1($text);
    if ($pdf->GetStringWidth($text) <= $maxw) return $text;
    while (strlen($text) > 0 && $pdf->GetStringWidth($text . '...') > $maxw) {
        $text = substr($text, 0, -1);
    }
    return $text . '...';
}

function planilla_pdf_header($pdf, $cols) {
    $h1 = 6;  // fila de encabezados de grupo (BODEGA / FACTURACION)
    $h2 = 10; // fila de sub-encabezados

    $pdf->SetFillColor(30, 64, 175);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);

    // Fila 1: agrupa por tramo del proceso; las columnas sin grupo quedan en
    // blanco aquí (su etiqueta va directo en la fila 2, con doble alto).
    $i = 0;
    while ($i < count($cols)) {
        $grupo = $cols[$i]['grupo'];
        if ($grupo === null) {
            $pdf->Cell($cols[$i]['w'], $h1, '', 1, 0, 'C', true);
            $i++;
        } else {
            $w = 0;
            while ($i < count($cols) && $cols[$i]['grupo'] === $grupo) { $w += $cols[$i]['w']; $i++; }
            $pdf->Cell($w, $h1, lp_latin1($grupo), 1, 0, 'C', true);
        }
    }
    $pdf->Ln($h1);

    // Fila 2: etiqueta de cada columna (1 o 2 líneas).
    $pdf->SetFont('Arial', 'B', 7);
    foreach ($cols as $c) {
        pdf_multiline_header($pdf, $c['w'], $h2, $c['label']);
    }
    $pdf->Ln($h2);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);

    return $h1 + $h2;
}

/**
 * $tipo_label identifica de cuál de los tres módulos es la planilla: "Con
 * adopción" (pedidos), "Sin adopción" (pedidos2) o "Muestreo" (muestreos).
 */
function planilla_pdf_generar($consecutivo, $fecha_descarga, $procesados, $cols, $tipo_label) {
    $pdf = new FPDF('L', 'mm', 'Letter');
    $pdf->SetMargins(10, 10, 10);
    // El salto de página de la tabla se maneja a mano (ver bucle más abajo)
    // para poder repetir el encabezado en cada página nueva.
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, lp_latin1('PLANILLA DE PROCESAMIENTO DE PEDIDOS'), 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(124, 58, 237);
    $pdf->Cell(0, 6, lp_latin1('Tipo: ' . $tipo_label), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(32, 6, 'Consecutivo No.', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(40, 6, str_pad($consecutivo, 6, '0', STR_PAD_LEFT), 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(50, 6, lp_latin1('Fecha y hora de descarga'), 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, $fecha_descarga, 0, 1);
    $pdf->Ln(4);

    $fila_h = 12;
    planilla_pdf_header($pdf, $cols);
    $limite_inferior = $pdf->GetPageHeight() - 10; // margen inferior, en mm

    foreach ($procesados as $p) {
        if ($pdf->GetY() + $fila_h > $limite_inferior) {
            $pdf->AddPage();
            planilla_pdf_header($pdf, $cols);
        }
        $fecha_pedido = !empty($p['fecha']) ? date('d/m/Y', strtotime($p['fecha'])) : '';
        $valores = [
            'pedido'    => $p['id'],
            'fecha_ped' => $fecha_pedido,
            'cliente'   => null, // se dibuja aparte, con truncado por ancho
            'bod_fecha' => '', 'bod_hora' => '', 'bod_rec' => '',
            'fact_fecha'=> '', 'fact_hora'=> '', 'fact_rec'=> '',
            'no_doc'    => '', 'rec_bod'  => '', 'ent_desp' => '', 'rec_desp' => '',
        ];
        foreach ($cols as $c) {
            if ($c['key'] === 'cliente') {
                $pdf->Cell($c['w'], $fila_h, pdf_fit_text($pdf, ($p['cliente'] ?? '') !== '' ? $p['cliente'] : ($p['responsable'] ?? ''), $c['w'] - 2), 1, 0, 'L');
            } else {
                $pdf->Cell($c['w'], $fila_h, $valores[$c['key']], 1, 0, 'C');
            }
        }
        $pdf->Ln($fila_h);
    }

    return $pdf->Output('S'); // devuelve el PDF como string, sin enviarlo aún
}
