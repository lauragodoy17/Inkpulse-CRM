<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if (!in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $bdd->prepare("SELECT DISTINCT libro FROM libros WHERE libro LIKE ? ORDER BY libro LIMIT 20");
$stmt->execute(['%' . $q . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

$resultado = array_map(function ($l) {
    return ['id' => $l, 'text' => $l];
}, $rows);

echo json_encode($resultado);
