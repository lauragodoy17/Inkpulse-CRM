<?php
/**
 * /php/previsualizar_excel_precios_crm.php
 * Primer paso del módulo "Actualizar precios CRM": lee el mismo tipo de
 * Excel que "Actualizar precios y materia desde Excel" (columnas Código,
 * Descripción, Unidad, Estado, Precio 1) pero acá solo importan Código
 * (ISBN) y Precio 1 — Descripción, Unidad y Estado, si vienen, se ignoran.
 * Compara el precio del archivo contra libros.precio por ISBN y devuelve
 * actual → nuevo para que libros.php lo muestre en vista previa. No crea
 * libros nuevos (si el ISBN no existe en el catálogo, la fila queda
 * marcada como error) ni escribe nada — eso lo hace
 * php/aplicar_excel_precios_crm.php después de que el usuario confirme.
 */
require_once("aut.php");

header('Content-Type: application/json');

if (($_SESSION["tipo"] ?? null) != 1) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../lib/autoload-phpspreadsheet.php");
require_once("../conexion/bdd.php");
use PhpOffice\PhpSpreadsheet\IOFactory;

function normalizar_precios_crm($texto) {
    $texto = trim((string)$texto);
    $texto = mb_strtolower($texto, 'UTF-8');
    $reemplazos = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
    return strtr($texto, $reemplazos);
}

if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo']);
    exit;
}

try {
    $spreadsheet = IOFactory::load($_FILES['archivo']['tmp_name']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'No se pudo leer el archivo como Excel: ' . $e->getMessage()]);
    exit;
}

$sheet = $spreadsheet->getSheet(0);
$highestRow = $sheet->getHighestDataRow();
$highestCol = $sheet->getHighestDataColumn();
$highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

// Detectar columnas por el texto del encabezado (fila 1). Solo Código y
// Precio importan acá; Descripción/Unidad/Estado pueden venir en el archivo
// (es el mismo export de World Office) pero se ignoran.
$colIsbn = $colPrecio = null;
for ($c = 1; $c <= $highestColIndex; $c++) {
    $encabezado = normalizar_precios_crm($sheet->getCellByColumnAndRow($c, 1)->getValue());
    if ($encabezado === '') continue;
    if ($colIsbn === null && (strpos($encabezado, 'codigo') !== false || strpos($encabezado, 'isbn') !== false)) $colIsbn = $c;
    if ($colPrecio === null && strpos($encabezado, 'precio') !== false) $colPrecio = $c;
}

$faltantes = [];
if ($colIsbn === null) $faltantes[] = 'Código';
if ($colPrecio === null) $faltantes[] = 'Precio';
if ($faltantes) {
    echo json_encode(['success' => false, 'message' => 'No se encontró columna de encabezado para: ' . implode(', ', $faltantes) . '. La fila 1 debe tener los títulos de columna.']);
    exit;
}

$filasExcel = [];
for ($r = 2; $r <= $highestRow; $r++) {
    $isbnTexto = trim((string)$sheet->getCellByColumnAndRow($colIsbn, $r)->getValue());
    $precioTexto = trim((string)$sheet->getCellByColumnAndRow($colPrecio, $r)->getValue());

    if ($isbnTexto === '' && $precioTexto === '') {
        continue; // fila vacía, se ignora
    }

    $filasExcel[] = ['fila' => $r, 'isbn' => $isbnTexto, 'precioTexto' => $precioTexto];
}

if (!$filasExcel) {
    echo json_encode(['success' => false, 'message' => 'El archivo no tiene filas de datos debajo del encabezado']);
    exit;
}

// Un solo query para todos los ISBN del archivo.
$isbnsArchivo = array_values(array_unique(array_filter(array_column($filasExcel, 'isbn'))));
$librosLocales = [];
if ($isbnsArchivo) {
    $marcadores = implode(',', array_fill(0, count($isbnsArchivo), '?'));
    $stmt = $bdd->prepare("SELECT id, isbn, libro, precio FROM libros WHERE TRIM(isbn) IN ($marcadores)");
    $stmt->execute($isbnsArchivo);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $librosLocales[trim($l['isbn'])] = $l;
    }
}

// El período que va a recibir el precio nuevo en `presupuestos` es siempre el
// más reciente (MAX(id) de periodos) — se informa acá para que la vista
// previa le diga al usuario exactamente cuál es antes de aplicar.
$periodoRow = $bdd->query("SELECT id, periodo FROM periodos ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$filas = [];
foreach ($filasExcel as $f) {
    $errores = [];
    $libroLocal = $librosLocales[$f['isbn']] ?? null;

    if ($f['isbn'] === '') {
        $errores[] = 'Fila sin ISBN';
    } elseif (!$libroLocal) {
        $errores[] = 'ISBN no encontrado en el catálogo local';
    }

    $precioNuevo = null;
    if ($f['precioTexto'] === '') {
        $errores[] = 'Precio vacío';
    } else {
        // Acepta "123456", "123456.78", "123456,78" o "123.456,78" (miles con
        // punto, decimal con coma).
        $limpio = str_replace(' ', '', str_replace('$', '', $f['precioTexto']));
        if (strpos($limpio, ',') !== false && strpos($limpio, '.') !== false) {
            $limpio = str_replace(',', '.', str_replace('.', '', $limpio));
        } elseif (strpos($limpio, ',') !== false) {
            $limpio = str_replace(',', '.', $limpio);
        }
        if (is_numeric($limpio)) {
            $precioNuevo = (float)$limpio;
        } else {
            $errores[] = 'Precio no numérico: "' . $f['precioTexto'] . '"';
        }
    }

    $sinCambio = $libroLocal && $precioNuevo !== null && abs((float)$libroLocal['precio'] - $precioNuevo) < 0.0001;

    $filas[] = [
        'fila' => $f['fila'],
        'isbn' => $f['isbn'],
        'idLibro' => $libroLocal['id'] ?? null,
        'libro' => $libroLocal['libro'] ?? null,
        'precioActual' => $libroLocal ? (float)$libroLocal['precio'] : null,
        'precioNuevo' => $precioNuevo,
        'sinCambio' => $sinCambio,
        'ok' => empty($errores),
        'errores' => $errores,
    ];
}

$validas = array_values(array_filter($filas, function ($f) { return $f['ok']; }));

echo json_encode([
    'success' => true,
    'filas' => $filas,
    'totalFilas' => count($filas),
    'totalValidas' => count($validas),
    'totalErrores' => count($filas) - count($validas),
    'ultimoPeriodo' => $periodoRow ? ['id' => (int)$periodoRow['id'], 'periodo' => $periodoRow['periodo']] : null,
]);
