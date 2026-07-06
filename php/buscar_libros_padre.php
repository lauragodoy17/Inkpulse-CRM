<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if ($_SESSION["tipo"] != 1) {
    echo json_encode(['results' => []]);
    exit;
}

$q = $_GET["q"] ?? '';
$excluir = intval($_GET["excluir"] ?? 0);
$materia = intval($_GET["materia"] ?? 0);

$sql = "SELECT id, libro, isbn FROM libros
        WHERE pri_sec = 0 AND id_grado IN (15, 16) AND id_materia = :materia AND presupuesto = 1
        AND id != :excluir AND (libro LIKE :q OR isbn LIKE :q)
        ORDER BY libro LIMIT 30";
$req = $bdd->prepare($sql);
$req->execute([':materia' => $materia, ':excluir' => $excluir, ':q' => '%' . $q . '%']);
$libros = $req->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($libros as $libro) {
    $results[] = ['id' => $libro["id"], 'text' => $libro["libro"] . ' (' . $libro["isbn"] . ')'];
}

echo json_encode(['results' => $results]);
