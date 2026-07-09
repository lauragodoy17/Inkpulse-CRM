<?php
require_once("../php/aut.php");
include("../conexion/bdd.php");
header('Content-Type: application/json; charset=utf-8');

$id_colegio = intval($_GET['id_colegio'] ?? 0);

$profesores = [];
if ($id_colegio > 0) {
    $stmt = $bdd->prepare("SELECT id, CONCAT(nombre, ' ', apellido) AS nombre FROM trabajadores_colegios WHERE id_colegio = :id_colegio AND cargo = 6 AND activo = 1 ORDER BY nombre, apellido");
    $stmt->execute([':id_colegio' => $id_colegio]);
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode($profesores);
