<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if (($_SESSION["tipo"] != 1 && ($_SESSION["id"] ?? null) != 21) || !isset($_POST["id_libro"])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$id_libro     = $_POST["id_libro"];
$id_ubicacion = trim($_POST["id_ubicacion"] ?? '');
$posicion     = trim($_POST["posicion"] ?? '');

if ($id_ubicacion !== '') {
    $req_check = $bdd->prepare("SELECT id FROM ubicaciones WHERE id=:id AND act=1");
    $req_check->execute([':id' => $id_ubicacion]);
    if (!$req_check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'La ubicación seleccionada no es válida.']);
        exit;
    }
}

$req_del = $bdd->prepare("DELETE FROM libros_ubicaciones WHERE id_libro=:id");
$req_del->execute([':id' => $id_libro]);

if ($id_ubicacion !== '') {
    $req_ins = $bdd->prepare("INSERT INTO libros_ubicaciones (id_libro, ubicacion, posicion) VALUES (:id_libro, :ubicacion, :posicion)");
    $req_ins->execute([
        ':id_libro'  => $id_libro,
        ':ubicacion' => $id_ubicacion,
        ':posicion'  => $posicion,
    ]);
}

echo json_encode(['success' => true, 'message' => 'Ubicación actualizada correctamente.']);
