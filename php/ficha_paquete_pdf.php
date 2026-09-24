<?php
/**
 * Ficha técnica de paquete(s) en PDF — misma información que ficha_paquete.php
 * (datos_fichas_paquetes()), un paquete por página. Solo con Tipo de adopción
 * "Paquetes" o "Ambos" guardado y para Administrador/Héctor Morales.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/paquetes_colegio.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/planilla_pdf.php"); // lp_latin1()

if (!puede_usar_tipo_adopcion()) {
    header("Location: ../index.php");
    exit;
}

crear_tablas_paquetes($bdd);

$fichas = datos_fichas_paquetes($bdd, $_GET['colegio'] ?? 0, $_GET['periodo'] ?? 0, $_GET['paquete'] ?? 0);
if (!$fichas || !$fichas['paquetes']) {
    header('Content-Type: text/html; charset=utf-8');
    echo 'No hay fichas técnicas disponibles: el Tipo de adopción guardado del colegio debe ser "Paquetes" o "Ambos" y tener paquetes guardados.';
    exit;
}

function fp_cop($v) {
    $v = (float)$v;
    $dec = (abs($v - round($v)) >= 0.005) ? 2 : 0;
    return '$ ' . number_format($v, $dec, ',', '.');
}

// Parte un texto (ya en latin1) en renglones que quepan en $w mm con la fuente actual.
function fp_lineas(FPDF $pdf, $texto, $w) {
    $lineas = [];
    $actual = '';
    foreach (explode(' ', $texto) as $palabra) {
        $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;
        if ($actual !== '' && $pdf->GetStringWidth($prueba) > $w) {
            $lineas[] = $actual;
            $actual = $palabra;
        } else {
            $actual = $prueba;
        }
    }
    if ($actual !== '') $lineas[] = $actual;
    return $lineas ?: [''];
}

class FichaPaquetePDF extends FPDF {
    public $pie = '';
    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, $this->pie, 0, 0, 'L');
        $this->Cell(0, 5, lp_latin1('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'R');
    }
}

$pdf = new FichaPaquetePDF('P', 'mm', 'Letter');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->pie = lp_latin1('Inkpulse · Generado el ' . date('Y-m-d H:i'));

$ancho = 215.9 - 30; // Letter menos márgenes

foreach ($fichas['paquetes'] as $p) {
    $pdf->AddPage();

    // ── Encabezado (banda de color) ──
    // Cabezote verde azulado (mismo tono que .fp-head en includes/fichas_paquete_vista.php)
    $pdf->SetFillColor(17, 94, 89);
    $pdf->Rect(0, 0, 215.9, 44, 'F');
    $pdf->SetFillColor(13, 148, 136);
    $pdf->Rect(0, 44, 215.9, 1.6, 'F');

    $pdf->SetXY(15, 10);
    $pdf->SetTextColor(167, 220, 212);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(120, 4, lp_latin1('FICHA TÉCNICA DE PAQUETE'), 0, 2);

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetXY(15, 16);
    $pdf->MultiCell(140, 6.5, lp_latin1($p['nombre']), 0, 'L');

    // Grado destacado a la derecha
    $pdf->SetFillColor(13, 148, 136);
    $pdf->Rect(160, 11, 40.9, 22, 'F');
    $pdf->SetXY(160, 13);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(40.9, 4, 'GRADO', 0, 2, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->MultiCell(40.9, 6, lp_latin1($p['grado']), 0, 'C');

    // ── Datos generales ──
    $pdf->SetXY(15, 52);
    $datos = [
        ['Colegio', $fichas['colegio']],
        ['DANE', $fichas['dane']],
        ['Código del paquete', $p['codigo']],
        ['Periodo', $fichas['periodo']],
    ];
    $anchos_datos = [$ancho * 0.40, $ancho * 0.18, $ancho * 0.26, $ancho * 0.16];
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 116, 139);
    foreach ($datos as $i => $d) $pdf->Cell($anchos_datos[$i], 4, lp_latin1(mb_strtoupper($d[0], 'UTF-8')), 0, 0);
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetTextColor(32, 35, 66);
    foreach ($datos as $i => $d) $pdf->Cell($anchos_datos[$i], 5, pdf_fit_text($pdf, $d[1], $anchos_datos[$i] - 3), 0, 0);
    $pdf->Ln(11);

    // ── Tabla de libros ──
    $w = [10, $ancho - 10 - 38 - 32, 38, 32];
    $pdf->SetFillColor(13, 148, 136);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($w[0], 8, '#', 0, 0, 'C', true);
    $pdf->Cell($w[1], 8, lp_latin1('Libro'), 0, 0, 'L', true);
    $pdf->Cell($w[2], 8, 'ISBN', 0, 0, 'L', true);
    $pdf->Cell($w[3], 8, 'Precio del libro', 0, 1, 'R', true);

    $pdf->SetTextColor(32, 35, 66);
    foreach ($p['libros'] as $i => $l) {
        $pdf->SetFont('Arial', '', 8.5);
        $lineas = fp_lineas($pdf, lp_latin1($l['libro']), $w[1] - 4);
        $alto = max(7, count($lineas) * 4.2 + 2.8);

        if ($pdf->GetY() + $alto > 279.4 - 18) $pdf->AddPage();

        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->SetFillColor($i % 2 ? 255 : 248, $i % 2 ? 255 : 250, $i % 2 ? 255 : 252);
        $pdf->Rect($x, $y, $ancho, $alto, 'F');

        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetTextColor(67, 97, 238);
        $pdf->Cell($w[0], $alto, (string)($i + 1), 0, 0, 'C');

        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(32, 35, 66);
        $pdf->SetXY($x + $w[0] + 1, $y + 1.4);
        $pdf->MultiCell($w[1] - 2, 4.2, implode("\n", $lineas), 0, 'L');

        $pdf->SetXY($x + $w[0] + $w[1], $y);
        $pdf->SetFont('Courier', '', 8.5);
        $pdf->Cell($w[2], $alto, $l['isbn'] !== '' ? $l['isbn'] : '-', 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->Cell($w[3], $alto, fp_cop($l['precio']), 0, 0, 'R');

        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Line($x, $y + $alto, $x + $ancho, $y + $alto);
        $pdf->SetXY($x, $y + $alto);
    }

    // ── Totales ──
    if ($pdf->GetY() + 34 > 279.4 - 18) $pdf->AddPage();
    $pdf->Ln(6);
    $x_tot = 15 + $ancho - 95;
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetX($x_tot);
    $pdf->Cell(60, 6, lp_latin1('Suma de los libros (' . count($p['libros']) . ')'), 0, 0, 'L');
    $pdf->SetTextColor(32, 35, 66);
    $pdf->Cell(35, 6, fp_cop($p['precio_neto_sumado']), 0, 1, 'R');
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetX($x_tot);
    $pdf->Cell(60, 6, 'Precio redondeado', 0, 0, 'L');
    $pdf->SetTextColor(32, 35, 66);
    $pdf->Cell(35, 6, $p['precio_redondeado'] !== null ? fp_cop($p['precio_redondeado']) : 'Sin definir', 0, 1, 'R');

    $pdf->Ln(2);
    $pdf->SetFillColor(24, 31, 72);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($x_tot);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(50, 13, '  PRECIO TOTAL DEL PAQUETE', 0, 0, 'L', true);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(45, 13, fp_cop($p['precio_final']) . '  ', 0, 1, 'R', true);
}

$nombre_archivo = 'Ficha_paquete_' . preg_replace('/[^A-Za-z0-9]+/', '_', $fichas['dane'] . '_' . $fichas['periodo']) .
    (count($fichas['paquetes']) === 1 ? '_' . $fichas['paquetes'][0]['codigo'] : '') . '.pdf';
$pdf->Output('D', $nombre_archivo);
