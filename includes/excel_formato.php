<?php
// Encabezado estándar de los informes exportados a Excel (mismo formato que php/pedidos_excel.php):
//   - Logo de Eureka en A1
//   - Fila 2: título en negrita
//   - Fila 4: "Fecha: <fecha y hora de descarga>", "Rango: ..." (si aplica) y "Usuario: <quien descarga>"
//   - Fila 6: encabezados de columnas con fondo verde (los datos empiezan en la fila 7)

use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!defined('EXCEL_FILA_ENCABEZADOS')) {
	define('EXCEL_FILA_ENCABEZADOS', 6);
}

// Nombre del usuario en sesión (quien descarga el informe).
function excel_usuario_descarga($bdd) {
	static $nombre = null;
	if ($nombre === null) {
		$req = $bdd->prepare("SELECT CONCAT(nombres, ' ', apellidos) FROM usuarios WHERE id=?");
		$req->execute([$_SESSION['id'] ?? 0]);
		$nombre = (string)($req->fetchColumn() ?: '');
	}
	return $nombre;
}

// Pinta logo, título, fecha, rango y usuario en la hoja.
// $rango: texto completo del filtro de fechas/periodo (ej. "Rango: 2026-01-01 - 2026-01-31",
//         "Periodo: 2027"); null o '' si el informe no tiene rango.
// $col:   columna donde van el título y la fecha; rango y usuario van en las dos siguientes.
function excel_encabezado($sheet, $bdd, $titulo, $rango = null, $col = 'E') {
	$drawing = new Drawing();
	$drawing->setName('logo');
	$drawing->setDescription('logo');
	$drawing->setPath(__DIR__ . '/../vendors/images/logo_eureka.png');
	$drawing->setHeight(100);
	$drawing->setCoordinates('A1');
	$drawing->setWorksheet($sheet);

	$i = Coordinate::columnIndexFromString($col);
	$col_rango   = Coordinate::stringFromColumnIndex($i + 1);
	$col_usuario = Coordinate::stringFromColumnIndex($i + 2);

	$sheet->setCellValue($col . '2', $titulo);
	$sheet->getStyle($col . '2')->applyFromArray([
		'font'      => ['bold' => true],
		'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
	]);

	$sheet->setCellValue($col . '4', 'Fecha: ' . date('Y-m-d H:i:s'));
	if ($rango !== null && $rango !== '') {
		$sheet->setCellValue($col_rango . '4', $rango);
	}
	$sheet->setCellValue($col_usuario . '4', 'Usuario: ' . excel_usuario_descarga($bdd));
}

// Fondo verde de la fila de encabezados de columnas (ej. 'A6:N6').
function excel_estilo_encabezados($sheet, $rango_celdas) {
	$sheet->getStyle($rango_celdas)->applyFromArray([
		'fill' => [
			'fillType'   => Fill::FILL_SOLID,
			'startColor' => ['rgb' => '00FF84'],
		],
	]);
}

// insertNewRowBefore() de la versión de PhpSpreadsheet en lib/ lanza avisos "Deprecated: Use of
// "self" in callables" en PHP 8.2+; con display_errors=1 esos avisos se imprimen dentro del .xlsx y
// Excel lo rechaza ("la extensión no es válida"). Se silencian solo los E_DEPRECATED durante la llamada.
function excel_insertar_filas($sheet, $antes_de, $cantidad) {
	$nivel = error_reporting();
	error_reporting($nivel & ~E_DEPRECATED);
	try {
		$sheet->insertNewRowBefore($antes_de, $cantidad);
	} finally {
		error_reporting($nivel);
	}
}

// Para informes que se construyen desde la fila 1: inserta las filas del encabezado
// estándar encima de lo ya escrito (fórmulas, combinaciones y estilos se desplazan solos)
// y pinta el encabezado. $filas_encabezado = fila donde quedaban los títulos de columnas (1 normalmente).
function excel_insertar_encabezado($sheet, $bdd, $titulo, $rango = null, $col = 'E', $filas_encabezado = 1) {
	excel_insertar_filas($sheet, 1, EXCEL_FILA_ENCABEZADOS - $filas_encabezado);
	excel_encabezado($sheet, $bdd, $titulo, $rango, $col);
	$ultima = $sheet->getHighestDataColumn(EXCEL_FILA_ENCABEZADOS);
	excel_estilo_encabezados($sheet, 'A' . EXCEL_FILA_ENCABEZADOS . ':' . $ultima . EXCEL_FILA_ENCABEZADOS);
}
