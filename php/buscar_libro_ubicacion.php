<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if (($_SESSION["tipo"] ?? null) != 1 && ($_SESSION["id"] ?? null) != 21) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

$palabras = array_filter(preg_split('/\s+/', $q), fn($p) => $p !== '');

$condiciones = [];
$params = [];
foreach (array_values($palabras) as $i => $palabra) {
    $ph = ":p$i";
    $condiciones[] = "(isbn LIKE $ph OR libro LIKE $ph)";
    $params[$ph] = '%' . $palabra . '%';
}

$condiciones[] = "presupuesto = 1";
$condiciones[] = "id_grado NOT IN (15, 16)";

$stmt = $bdd->prepare(
    "SELECT id, isbn, libro FROM libros WHERE " . implode(' AND ', $condiciones) . " ORDER BY libro LIMIT 20"
);
$stmt->execute($params);
$libros = $stmt->fetchAll();

$resultado = [];
if ($libros) {
    $ids = array_column($libros, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $bdd->prepare(
        "SELECT lu.id_libro, lu.posicion, u.id AS ubicacion_id, u.piso, u.ubicacion AS slot,
                l.id AS lugar_id, l.id_tipo, l.corredor, l.lugar
         FROM libros_ubicaciones lu
         JOIN ubicaciones u ON u.id = lu.ubicacion AND u.act = 1
         JOIN lugares l     ON l.id = u.id_lugar AND l.act = 1
         WHERE lu.id_libro IN ($in)"
    );
    $stmt->execute($ids);
    $ubi_map = [];
    foreach ($stmt->fetchAll() as $row) {
        $ubi_map[$row['id_libro']][] = $row;
    }

    foreach ($libros as $libro) {
        $resultado[] = [
            'id'          => $libro['id'],
            'isbn'        => $libro['isbn'],
            'libro'       => $libro['libro'],
            'ubicaciones' => $ubi_map[$libro['id']] ?? [],
        ];
    }
}

echo json_encode($resultado);
