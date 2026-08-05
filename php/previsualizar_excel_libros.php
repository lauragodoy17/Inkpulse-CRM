<?php
/**
 * /php/previsualizar_excel_libros.php
 * Primer paso de la carga de precios/estado por Excel: recibe el archivo,
 * lo lee (sin tocar la base de datos) y devuelve, fila por fila, qué se
 * encontró y qué se va a actualizar — para que libros.php lo muestre como
 * vista previa antes de que el usuario confirme con "Aplicar cambios"
 * (php/aplicar_excel_libros.php). No escribe nada acá.
 *
 * El archivo es el típico export de World Office: columnas "Código",
 * "Descripción", "Unidad", "Estado" y "Precio 1" (detectadas por el texto
 * del encabezado en la fila 1, en cualquier orden, sin distinguir
 * mayúsculas/tildes; "Unidad" no se usa). Código = ISBN, Descripción =
 * título, Estado = activo/inactivo (se usa para "presupuesto") y Precio 1 =
 * precio. Ese archivo NO trae materia, así que la materia nunca se completa
 * sola acá: el usuario la elige a mano en la tabla, tanto para libros que ya
 * existen (se sugiere la materia actual) como para los que hay que crear.
 *
 * A propósito, esto NO llama a la API de World Office fila por fila: el
 * archivo ya trae el título en "Descripción", así que no hace falta, y
 * hacer un llamado HTTP por cada ISBN nuevo (que puede ser la mayoría de un
 * archivo grande) dejaba la previsualización pegada por minutos.
 *
 * Si libros.php manda un POST "isbns" (JSON con la lista de ISBN que ya
 * están en la tabla de abajo, típicamente los que el usuario seleccionó
 * arriba con "Agregar seleccionados"), solo se procesan esas filas del
 * Excel — el archivo puede traer todo el inventario de World Office y no
 * todo interesa en ese momento. Sin ese parámetro se procesa el archivo
 * completo, como antes.
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

function normalizar($texto) {
    $texto = trim((string)$texto);
    $texto = mb_strtolower($texto, 'UTF-8');
    $reemplazos = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
    return strtr($texto, $reemplazos);
}

// Traduce el texto de la columna "Estado" a 1 (activo) / 0 (inactivo). Acepta
// los valores típicos de World Office ("Activo"/"Inactivo") y variantes
// comunes de Excel (Sí/No, 1/0). Devuelve null si no se reconoce.
function parsear_estado_activo($texto) {
    $norm = normalizar($texto);
    if ($norm === '') return null;
    if (in_array($norm, ['activo', 'active', 'si', 's', '1', 'true', 'x'], true)) return 1;
    if (in_array($norm, ['inactivo', 'inactive', 'no', 'n', '0', 'false'], true)) return 0;
    if (strpos($norm, 'inactiv') !== false) return 0;
    if (strpos($norm, 'activ') !== false) return 1;
    return null;
}

if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo']);
    exit;
}

$isbnsFiltro = [];
if (!empty($_POST['isbns'])) {
    $decodificado = json_decode($_POST['isbns'], true);
    if (is_array($decodificado)) {
        $isbnsFiltro = array_values(array_unique(array_filter(array_map(function ($v) {
            return trim((string)$v);
        }, $decodificado))));
    }
}
$filtroActivo = !empty($isbnsFiltro);
$isbnsFiltroSet = array_flip($isbnsFiltro);

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

// Detectar columnas por el texto del encabezado (fila 1), no por letra fija.
$colIsbn = $colDescripcion = $colEstado = $colPrecio = null;
for ($c = 1; $c <= $highestColIndex; $c++) {
    $encabezado = normalizar($sheet->getCellByColumnAndRow($c, 1)->getValue());
    if ($encabezado === '') continue;
    if ($colIsbn === null && (strpos($encabezado, 'codigo') !== false || strpos($encabezado, 'isbn') !== false)) $colIsbn = $c;
    if ($colDescripcion === null && (strpos($encabezado, 'descripcion') !== false || strpos($encabezado, 'titulo') !== false)) $colDescripcion = $c;
    if ($colEstado === null && (strpos($encabezado, 'estado') !== false || strpos($encabezado, 'activo') !== false)) $colEstado = $c;
    if ($colPrecio === null && strpos($encabezado, 'precio') !== false) $colPrecio = $c;
}

$faltantes = [];
if ($colIsbn === null) $faltantes[] = 'Código';
if ($colDescripcion === null) $faltantes[] = 'Descripción';
if ($colEstado === null) $faltantes[] = 'Estado';
if ($colPrecio === null) $faltantes[] = 'Precio';
if ($faltantes) {
    echo json_encode(['success' => false, 'message' => 'No se encontró columna de encabezado para: ' . implode(', ', $faltantes) . '. La fila 1 debe tener los títulos de columna.']);
    exit;
}

$filasExcel = [];
for ($r = 2; $r <= $highestRow; $r++) {
    $isbnTexto = trim((string)$sheet->getCellByColumnAndRow($colIsbn, $r)->getValue());
    $descripcionTexto = trim((string)$sheet->getCellByColumnAndRow($colDescripcion, $r)->getValue());
    $estadoTexto = trim((string)$sheet->getCellByColumnAndRow($colEstado, $r)->getValue());
    $precioTexto = trim((string)$sheet->getCellByColumnAndRow($colPrecio, $r)->getValue());

    if ($isbnTexto === '' && $descripcionTexto === '' && $estadoTexto === '' && $precioTexto === '') {
        continue; // fila vacía, se ignora
    }

    if ($filtroActivo && !isset($isbnsFiltroSet[$isbnTexto])) {
        continue; // no está entre los libros seleccionados/en cola: se ignora
    }

    $filasExcel[] = ['fila' => $r, 'isbn' => $isbnTexto, 'descripcionTexto' => $descripcionTexto, 'estadoTexto' => $estadoTexto, 'precioTexto' => $precioTexto];
}

if (!$filasExcel) {
    $mensaje = $filtroActivo
        ? 'Ninguno de los libros seleccionados arriba se encontró en este archivo (se compara por ISBN/Código).'
        : 'El archivo no tiene filas de datos debajo del encabezado';
    echo json_encode(['success' => false, 'message' => $mensaje]);
    exit;
}

$isbnsNoEncontrados = $filtroActivo
    ? array_values(array_diff($isbnsFiltro, array_column($filasExcel, 'isbn')))
    : [];

// Un solo query para todos los ISBN del archivo.
$isbnsArchivo = array_values(array_unique(array_filter(array_column($filasExcel, 'isbn'))));
$librosLocales = [];
if ($isbnsArchivo) {
    $marcadores = implode(',', array_fill(0, count($isbnsArchivo), '?'));
    $stmt = $bdd->prepare("SELECT id, isbn, libro, precio, presupuesto, id_materia FROM libros WHERE TRIM(isbn) IN ($marcadores)");
    $stmt->execute($isbnsArchivo);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $librosLocales[trim($l['isbn'])] = $l;
    }
}

$materias = $bdd->query("SELECT id, materia FROM materias")->fetchAll(PDO::FETCH_ASSOC);
$materiaActualPorId = [];
foreach ($materias as $m) { $materiaActualPorId[$m['id']] = $m['materia']; }

$filas = [];
foreach ($filasExcel as $f) {
    $errores = [];
    $libroLocal = $librosLocales[$f['isbn']] ?? null;

    if ($f['isbn'] === '') {
        $errores[] = 'Fila sin ISBN';
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

    $presupuestoNuevo = parsear_estado_activo($f['estadoTexto']);
    if ($f['estadoTexto'] === '') {
        $errores[] = 'Estado vacío';
    } elseif ($presupuestoNuevo === null) {
        $errores[] = 'Estado no reconocido: "' . $f['estadoTexto'] . '"';
    }

    $filas[] = [
        'fila' => $f['fila'],
        'isbn' => $f['isbn'],
        'libroActual' => $libroLocal['libro'] ?? null,
        'libroNuevo' => $f['descripcionTexto'],
        'precioActual' => $libroLocal ? (float)$libroLocal['precio'] : null,
        'precioNuevo' => $precioNuevo,
        'presupuestoActual' => $libroLocal ? (int)$libroLocal['presupuesto'] : null,
        'presupuestoNuevo' => $presupuestoNuevo,
        'materiaActualId' => $libroLocal['id_materia'] ?? null,
        'materiaActualNombre' => $libroLocal ? ($materiaActualPorId[$libroLocal['id_materia']] ?? null) : null,
        'idLibro' => $libroLocal['id'] ?? null,
        'faltanteEnCatalogo' => !$libroLocal && $f['isbn'] !== '',
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
    'filtroActivo' => $filtroActivo,
    'isbnsNoEncontrados' => $isbnsNoEncontrados,
]);
