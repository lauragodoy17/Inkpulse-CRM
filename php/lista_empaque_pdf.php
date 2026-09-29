<?php
/**
 * /php/lista_empaque_pdf.php?id=<id_lista>
 * PDF de una lista de empaque YA GUARDADA. Todo sale de la copia congelada al guardar
 * (le_obtener_lista()), no de World Office ni del pedido actual, para que el documento sea
 * siempre el mismo aunque el origen cambie. El encabezado (logo, empresa, título, consecutivo y
 * fecha) y el encabezado de la tabla de cajas se repiten en cada página.
 */
require_once("aut.php");

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    header("Location: ../index.php");
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/planilla_pdf.php"); // lp_latin1(), pdf_fit_text()

le_crear_tablas($bdd);
$lista = le_obtener_lista($bdd, intval($_GET['id'] ?? 0));
if (!$lista) {
    http_response_code(404);
    exit('Lista de empaque no encontrada.');
}

const LE_PDF_ANCHO = 215.9 - 30; // Letter menos márgenes
const LE_PDF_ALTO_PAGINA = 279.4;

class ListaEmpaquePDF extends FPDF {
    public $lista;
    public $tablaCajasActiva = false;

    function Header() {
        $l = $this->lista;
        $this->SetFillColor(29, 78, 216);
        $this->Rect(0, 0, 215.9, 2.2, 'F');

        $this->Image(__DIR__ . '/../vendors/images/logo_eureka_pdf.png', 15, 8, 38);
        $this->SetXY(57, 9);
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 5, pdf_fit_text($this, $l['empresa'] ?: 'EUREKA CONTENIDOS EDUCATIVOS SAS', 80), 0, 2);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(29, 78, 216);
        $this->Cell(80, 9, 'LISTA DE EMPAQUE', 0, 2);

        // Consecutivo y fecha, en recuadro a la derecha
        $this->SetFillColor(239, 246, 255);
        $this->SetDrawColor(191, 219, 254);
        $this->Rect(145, 8, 55.9, 20, 'DF');
        $this->SetXY(145, 10);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(55.9, 4, lp_latin1('N.º DE LISTA'), 0, 2, 'C');
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(55.9, 7, $l['consecutivo_fmt'], 0, 2, 'C');
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(55.9, 4, lp_latin1('Generada: ' . date('d/m/Y H:i', strtotime($l['fecha']))), 0, 2, 'C');

        $this->SetDrawColor(226, 232, 240);
        $this->Line(15, 32, 200.9, 32);
        $this->SetY(36);

        if ((int)$l['estado'] === 0) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(220, 38, 38);
            $this->Cell(LE_PDF_ANCHO, 5, lp_latin1('LISTA ANULADA - ' . $l['motivo_anulacion']), 0, 1, 'C');
            $this->Ln(1);
        }
        if ($this->tablaCajasActiva) tabla_cajas_encabezado($this);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, lp_latin1('Lista de empaque ' . $this->lista['consecutivo_fmt'] . ($this->lista['pedidos'] !== '' ? ' · Pedido ' . $this->lista['pedidos'] : '')), 0, 0, 'L');
        $this->Cell(0, 5, lp_latin1('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'R');
    }
}

function titulo_seccion(FPDF $pdf, $texto) {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(29, 78, 216);
    $pdf->Cell(LE_PDF_ANCHO, 6, lp_latin1(mb_strtoupper($texto, 'UTF-8')), 0, 1);
    $pdf->SetDrawColor(191, 219, 254);
    $pdf->Line(15, $pdf->GetY(), 200.9, $pdf->GetY());
    $pdf->Ln(2);
}

/** Pares etiqueta/valor en columnas; los valores largos se parten en varias líneas. */
function campos(FPDF $pdf, array $pares, array $anchos) {
    $pdf->SetFont('Arial', 'B', 6.8);
    $pdf->SetTextColor(100, 116, 139);
    foreach ($pares as $i => $p) $pdf->Cell($anchos[$i], 4, lp_latin1(mb_strtoupper($p[0], 'UTF-8')), 0, 0);
    $pdf->Ln(4.5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(15, 23, 42);
    $y = $pdf->GetY();
    $maxY = $y;
    $x = 15;
    foreach ($pares as $i => $p) {
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($anchos[$i] - 3, 4.6, lp_latin1($p[1] !== '' && $p[1] !== null ? $p[1] : '-'), 0, 'L');
        $maxY = max($maxY, $pdf->GetY());
        $x += $anchos[$i];
    }
    $pdf->SetY($maxY + 3);
}

const LE_COLS_CAJAS = [20, 38, 105.9, 22]; // N.º caja, Referencia, Producto, Cantidad

function tabla_cajas_encabezado(FPDF $pdf) {
    $w = LE_COLS_CAJAS;
    $pdf->SetFillColor(29, 78, 216);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($w[0], 7, lp_latin1('N.º caja'), 0, 0, 'C', true);
    $pdf->Cell($w[1], 7, 'Referencia', 0, 0, 'L', true);
    $pdf->Cell($w[2], 7, 'Producto', 0, 0, 'L', true);
    $pdf->Cell($w[3], 7, 'Cantidad', 0, 1, 'R', true);
}

$pdf = new ListaEmpaquePDF('P', 'mm', 'Letter');
$pdf->lista = $lista;
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->SetTitle(lp_latin1('Lista de empaque ' . $lista['consecutivo_fmt']));
$pdf->AddPage();

titulo_seccion($pdf, 'Datos del cliente');
campos($pdf, [
    ['Cliente / empresa', $lista['cliente']],
    ['Dirección de entrega', $lista['direccion']],
    ['Ciudad', $lista['ciudad']],
], [LE_PDF_ANCHO * 0.36, LE_PDF_ANCHO * 0.44, LE_PDF_ANCHO * 0.20]);
if (($lista['persona_recibe'] ?? '') !== '') campos($pdf, [['Persona que recibe', $lista['persona_recibe']]], [LE_PDF_ANCHO]);

$docsTxt = implode("\n", array_map(fn($d) => $d['tipo_label'] . ' ' . $d['documento'] . ($d['fecha_doc'] ? ' (' . date('d/m/Y', strtotime($d['fecha_doc'])) . ')' : '')
    . ($d['origen'] === 'manual' ? ' - asociado manualmente' : ''), $lista['documentos']));
if ($lista['pedidos'] !== '' || $lista['ops'] !== '') {
    // Listas anteriores al 2026-09-28, que se armaban por pedido.
    titulo_seccion($pdf, 'Datos del pedido y documentos asociados');
    campos($pdf, [
        [strpos($lista['pedidos'], ',') !== false ? 'Pedidos' : 'N.º de pedido', $lista['pedidos']],
        ['OP asociada', $lista['ops']],
        ['Colegio', $lista['colegio']],
        ['Documentos World Office', $docsTxt],
    ], [LE_PDF_ANCHO * 0.14, LE_PDF_ANCHO * 0.18, LE_PDF_ANCHO * 0.32, LE_PDF_ANCHO * 0.36]);
} else {
    titulo_seccion($pdf, 'Documentos asociados');
    campos($pdf, [['Documentos World Office', $docsTxt]], [LE_PDF_ANCHO]);
}

titulo_seccion($pdf, 'Resumen del despacho');
$pesoNeto = $lista['peso_neto'] !== null ? number_format((float)$lista['peso_neto'], 2, ',', '.') . ' kg' : '';
campos($pdf, [
    ['Cantidad total de productos', number_format((int)$lista['total_unidades'], 0, ',', '.') . ' unidades'],
    ['Número total de cajas', (string)(int)$lista['total_cajas']],
    ['Peso neto', $pesoNeto],
], [LE_PDF_ANCHO / 3, LE_PDF_ANCHO / 3, LE_PDF_ANCHO / 3]);

titulo_seccion($pdf, 'Detalle de las cajas');
$w = LE_COLS_CAJAS;
tabla_cajas_encabezado($pdf);
$pdf->tablaCajasActiva = true;
$limite = LE_PDF_ALTO_PAGINA - 18;
foreach ($lista['cajas'] as $ci => $caja) {
    $fondo = $ci % 2 ? [255, 255, 255] : [248, 250, 252];
    foreach ($caja['items'] as $it) {
        $pdf->SetFont('Arial', '', 8.5);
        $lineas = max(1, (int)ceil($pdf->GetStringWidth(lp_latin1($it['descripcion'])) / ($w[2] - 3)));
        $alto = max(6.5, $lineas * 4.2 + 2.3);
        if ($pdf->GetY() + $alto > $limite) $pdf->AddPage();
        $x = 15;
        $y = $pdf->GetY();
        $pdf->SetFillColor($fondo[0], $fondo[1], $fondo[2]);
        $pdf->Rect($x, $y, LE_PDF_ANCHO, $alto, 'F');
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w[0], $alto, (string)$caja['numero'], 0, 0, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w[1], $alto, pdf_fit_text($pdf, $it['codigo'], $w[1] - 2), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($x + $w[0] + $w[1], $y + 1.15);
        $pdf->MultiCell($w[2] - 3, 4.2, lp_latin1($it['descripcion']), 0, 'L');
        $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w[3], $alto, number_format((int)$it['cantidad'], 0, ',', '.'), 0, 1, 'R');
    }
    // Subtotal de la caja (y su peso, si se registró)
    if ($pdf->GetY() + 6 > $limite) $pdf->AddPage();
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetFont('Arial', 'B', 7.8);
    $pdf->SetTextColor(51, 65, 85);
    $etiqueta = 'Total caja ' . $caja['numero'] . ($caja['peso'] !== null ? ' · Peso ' . number_format((float)$caja['peso'], 2, ',', '.') . ' kg' : '');
    $pdf->Cell($w[0] + $w[1] + $w[2], 5.5, lp_latin1($etiqueta), 0, 0, 'R', true);
    $pdf->Cell($w[3], 5.5, number_format($caja['unidades'], 0, ',', '.'), 0, 1, 'R', true);
}
$pdf->tablaCajasActiva = false;
if ($pdf->GetY() + 8 > $limite) $pdf->AddPage();
$pdf->SetFillColor(29, 78, 216);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($w[0] + $w[1] + $w[2], 7, lp_latin1('TOTAL: ' . (int)$lista['total_cajas'] . ' caja(s)'), 0, 0, 'R', true);
$pdf->Cell($w[3], 7, number_format((int)$lista['total_unidades'], 0, ',', '.'), 0, 1, 'R', true);

// Empacado por + firma
if ($pdf->GetY() + 30 > $limite) $pdf->AddPage();
$pdf->Ln(10);
titulo_seccion($pdf, 'Datos de empaque');
$pdf->SetFont('Arial', 'B', 6.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(LE_PDF_ANCHO / 2, 4, 'EMPACADO POR', 0, 0);
$pdf->Cell(LE_PDF_ANCHO / 2, 4, 'FIRMA', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(LE_PDF_ANCHO / 2, 8, lp_latin1($lista['empacado_por']), 0, 0);
$pdf->SetDrawColor(148, 163, 184);
$pdf->Line(15 + LE_PDF_ANCHO / 2, $pdf->GetY() + 8, 200.9, $pdf->GetY() + 8);

$pdf->Output('I', 'lista_empaque_' . $lista['consecutivo_fmt'] . '.pdf');
