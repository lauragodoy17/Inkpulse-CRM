<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$resultados = [];

if ($q !== '') {
    $sql = "SELECT id, cliente FROM clientes WHERE cliente LIKE :q ORDER BY id DESC LIMIT 50";
    $req = $bdd->prepare($sql);
    $req->execute([':q' => '%'.$q.'%']);
    foreach ($req->fetchAll() as $row) {
        $resultados[] = ['id' => $row['id'], 'text' => $row['cliente']];
    }
}

echo json_encode($resultados);
