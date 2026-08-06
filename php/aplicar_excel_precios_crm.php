<?php
/**
 * /php/aplicar_excel_precios_crm.php
 * Segundo paso de "Actualizar precios CRM": recibe las filas YA VALIDADAS
 * por previsualizar_excel_precios_crm.php (solo las que el usuario confirmó
 * en la vista previa) y actualiza el precio en dos tablas:
 *   - libros.precio (el catálogo)
 *   - presupuestos.precio, pero SOLO las filas del período más reciente
 *     (MAX(id) de la tabla periodos) — los períodos anteriores son
 *     historial y no se tocan, aunque el libro ya tenga otro precio
 *     registrado ahí. Si ese colegio+período no tiene fila de presupuesto
 *     todavía para ese libro, simplemente no hay nada que actualizar (no es
 *     un error, no se crea una fila nueva).
 * No crea libros, no toca materia ni el flag "activo en presupuesto" de
 * libros (eso es otro campo, ver php/aplicar_excel_libros.php).
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

if (!$filas) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron filas para aplicar']);
    exit;
}

$idUltimoPeriodo = $bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$idUltimoPeriodo) {
    echo json_encode(['success' => false, 'message' => 'No hay ningún período registrado; no hay dónde aplicar el precio de presupuesto.']);
    exit;
}

$stmtLibro = $bdd->prepare("SELECT id FROM libros WHERE id = ?");
$stmtActualizarLibro = $bdd->prepare("UPDATE libros SET precio = ?, updated_at = NOW() WHERE id = ?");
$stmtActualizarPresupuesto = $bdd->prepare("UPDATE presupuestos SET precio = ? WHERE id_periodo = ? AND id_libro = ?");

$actualizados = [];
$omitidos = [];

foreach ($filas as $f) {
    $idLibro = isset($f['idLibro']) ? (int)$f['idLibro'] : 0;
    $precio = isset($f['precioNuevo']) ? (float)$f['precioNuevo'] : null;

    if (!$idLibro || $precio === null) {
        $omitidos[] = ['fila' => $f['fila'] ?? null, 'motivo' => 'Datos incompletos'];
        continue;
    }

    $stmtLibro->execute([$idLibro]);
    if (!$stmtLibro->fetchColumn()) {
        $omitidos[] = ['fila' => $f['fila'] ?? null, 'motivo' => 'El libro ya no existe (id ' . $idLibro . ')'];
        continue;
    }

    $stmtActualizarLibro->execute([$precio, $idLibro]);

    $stmtActualizarPresupuesto->execute([$precio, $idUltimoPeriodo, $idLibro]);

    $actualizados[] = [
        'fila' => $f['fila'] ?? null,
        'idLibro' => $idLibro,
        'isbn' => $f['isbn'] ?? null,
        'filasPresupuestoActualizadas' => $stmtActualizarPresupuesto->rowCount(),
    ];
}

echo json_encode([
    'success' => true,
    'idUltimoPeriodo' => (int)$idUltimoPeriodo,
    'actualizados' => $actualizados,
    'omitidos' => $omitidos,
]);
