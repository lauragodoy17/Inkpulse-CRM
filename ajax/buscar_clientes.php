<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$resultados = [];

if ($q !== '') {

    $sql = "SELECT periodo, SUBSTRING(periodo, 3, 2) AS anio FROM periodos WHERE id = '".$_GET["periodo"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    $periodo = $req->fetch();

    $sql = "
        SELECT id, cliente
        FROM clientes
        WHERE cliente LIKE :q
          AND (
                cliente NOT REGEXP '^AA[0-9]{2}[[:space:]]*-'
                OR cliente REGEXP CONCAT('^AA', :anio, '[[:space:]]*-')
              )
        ORDER BY id DESC
        LIMIT 50
    ";

    $req = $bdd->prepare($sql);

    $req->execute([
        ':q' => '%' . $q . '%',
        ':anio' => $periodo["anio"]
    ]);

    foreach ($req->fetchAll() as $row) {
        $resultados[] = ['id' => $row['id'], 'text' => $row['cliente']];
    }
}

echo json_encode($resultados);
