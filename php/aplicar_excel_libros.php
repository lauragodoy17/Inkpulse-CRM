<?php
/**
 * /php/aplicar_excel_libros.php
 * Segundo paso de la carga de precios/estado por Excel: recibe la lista de
 * filas YA VALIDADAS por previsualizar_excel_libros.php (solo las que el
 * usuario confirmó en la vista previa) y actualiza `libros.precio`,
 * `libros.presupuesto` (viene de la columna "Estado" del Excel) y
 * `libros.id_materia` (elegida a mano en la tabla, porque el Excel no trae
 * materia). Vuelve a validar id_libro/id_materia contra la base antes de
 * escribir, por si la vista previa quedó desactualizada.
 *
 * Además recibe, en "nuevas", los libros que todavía no existen en el
 * catálogo (detectados por el Excel o traídos manualmente desde la búsqueda
 * de libros nuevos en World Office, y completados a mano en la tabla de
 * libros.php) y los crea, igual que php/crear_libro.php pero en lote.
 */
require_once("aut.php");

header('Content-Type: application/json');

if (($_SESSION["tipo"] ?? null) != 1) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");

$body = json_decode(file_get_contents('php://input'), true);
$filas = isset($body['filas']) && is_array($body['filas']) ? $body['filas'] : [];
$nuevas = isset($body['nuevas']) && is_array($body['nuevas']) ? $body['nuevas'] : [];

if (!$filas && !$nuevas) {
    echo json_encode(['success' => false, 'message' => 'No se recibió nada para aplicar']);
    exit;
}

$stmtLibro = $bdd->prepare("SELECT id FROM libros WHERE id = ?");
$stmtMateria = $bdd->prepare("SELECT id FROM materias WHERE id = ?");
$stmtGrado = $bdd->prepare("SELECT id FROM grados WHERE id = ?");
$stmtEditorial = $bdd->prepare("SELECT id FROM editoriales WHERE id = ?");
$stmtIsbnExiste = $bdd->prepare("SELECT id FROM libros WHERE TRIM(isbn) = TRIM(?)");
$stmtActualizar = $bdd->prepare("UPDATE libros SET precio = ?, id_materia = ?, presupuesto = ?, updated_at = NOW() WHERE id = ?");
// libros.tipo: bodega de World Office a la que pertenece (mismo dominio que usa
// php/clasificar_libros_bodega.php: 0 Ninguna, 1 General, 4 Muestras General,
// 3 Ambas). Se elige a mano en la tabla de creación; si no se elige, queda en 0.
$stmtCrear = $bdd->prepare("INSERT INTO libros (isbn, id_materia, id_grado, libro, precio, presupuesto, etiqueta, pri_sec, columna, editorial, serie, id_wo, tipo)
        VALUES (?, ?, ?, ?, ?, ?, '', 0, 0, ?, 0, ?, ?)");

$actualizados = [];
$creados = [];
$omitidos = [];

foreach ($filas as $f) {
    $idLibro = isset($f['idLibro']) ? (int)$f['idLibro'] : 0;
    $idMateria = isset($f['materiaNuevaId']) ? (int)$f['materiaNuevaId'] : 0;
    $precio = isset($f['precioNuevo']) ? (float)$f['precioNuevo'] : null;
    $presupuesto = isset($f['presupuestoNuevo']) && $f['presupuestoNuevo'] !== '' && $f['presupuestoNuevo'] !== null ? (int)$f['presupuestoNuevo'] : null;

    if (!$idLibro || !$idMateria || $precio === null || $presupuesto === null) {
        $omitidos[] = ['fila' => $f['fila'] ?? null, 'motivo' => 'Datos incompletos'];
        continue;
    }

    $stmtLibro->execute([$idLibro]);
    if (!$stmtLibro->fetchColumn()) {
        $omitidos[] = ['fila' => $f['fila'] ?? null, 'motivo' => 'El libro ya no existe (id ' . $idLibro . ')'];
        continue;
    }

    $stmtMateria->execute([$idMateria]);
    if (!$stmtMateria->fetchColumn()) {
        $omitidos[] = ['fila' => $f['fila'] ?? null, 'motivo' => 'La materia ya no existe (id ' . $idMateria . ')'];
        continue;
    }

    $stmtActualizar->execute([$precio, $idMateria, $presupuesto, $idLibro]);
    $actualizados[] = ['fila' => $f['fila'] ?? null, 'idLibro' => $idLibro, 'isbn' => $f['isbn'] ?? null];
}

foreach ($nuevas as $n) {
    $isbn = trim((string)($n['isbn'] ?? ''));
    $libro = trim((string)($n['libro'] ?? ''));
    $idMateria = isset($n['id_materia']) ? (int)$n['id_materia'] : 0;
    $idGrado = isset($n['id_grado']) ? (int)$n['id_grado'] : 0;
    $idEditorial = isset($n['id_editorial']) ? (int)$n['id_editorial'] : 0;
    $tipo = isset($n['tipo']) && in_array((string)$n['tipo'], ['0', '1', '3', '4'], true) ? (int)$n['tipo'] : 0;
    $precio = isset($n['precio']) && $n['precio'] !== '' ? (float)$n['precio'] : null;
    $presupuesto = isset($n['presupuesto']) && $n['presupuesto'] !== '' ? (int)$n['presupuesto'] : null;
    $idWo = !empty($n['id_wo']) ? (int)$n['id_wo'] : 0;

    if ($isbn === '' || $libro === '' || !$idMateria || !$idGrado || !$idEditorial || $precio === null || $presupuesto === null) {
        $omitidos[] = ['isbn' => $isbn ?: null, 'motivo' => 'Datos incompletos'];
        continue;
    }

    $stmtIsbnExiste->execute([$isbn]);
    if ($stmtIsbnExiste->fetchColumn()) {
        $omitidos[] = ['isbn' => $isbn, 'motivo' => 'El ISBN ya existe en el catálogo'];
        continue;
    }

    $stmtMateria->execute([$idMateria]);
    if (!$stmtMateria->fetchColumn()) {
        $omitidos[] = ['isbn' => $isbn, 'motivo' => 'La materia ya no existe (id ' . $idMateria . ')'];
        continue;
    }

    $stmtGrado->execute([$idGrado]);
    if (!$stmtGrado->fetchColumn()) {
        $omitidos[] = ['isbn' => $isbn, 'motivo' => 'El grado ya no existe (id ' . $idGrado . ')'];
        continue;
    }

    $stmtEditorial->execute([$idEditorial]);
    if (!$stmtEditorial->fetchColumn()) {
        $omitidos[] = ['isbn' => $isbn, 'motivo' => 'La editorial ya no existe (id ' . $idEditorial . ')'];
        continue;
    }

    $stmtCrear->execute([$isbn, $idMateria, $idGrado, $libro, $precio, $presupuesto, $idEditorial, $idWo, $tipo]);
    $nuevoId = (int)$bdd->lastInsertId();

    $creados[] = ['isbn' => $isbn, 'idLibro' => $nuevoId, 'libro' => $libro];
}

echo json_encode([
    'success' => true,
    'actualizados' => $actualizados,
    'creados' => $creados,
    'omitidos' => $omitidos,
]);
