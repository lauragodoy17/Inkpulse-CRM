<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

// Asociar libros a una serie es exclusivo del usuario id=1.
if (($_SESSION["id"] ?? null) != 1 || !isset($_POST["id_libro"])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$id_libro = intval($_POST["id_libro"]);
$id_padre = intval($_POST["id_padre"] ?? 0);

if ($id_padre > 0) {
    if ($id_padre === $id_libro) {
        echo json_encode(['success' => false, 'message' => 'Un libro no puede ser padre de sí mismo.']);
        exit;
    }

    $req_check = $bdd->prepare("SELECT COUNT(*) FROM libros WHERE id = :id AND pri_sec = 0");
    $req_check->execute([':id' => $id_padre]);
    if (!$req_check->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'El libro seleccionado no puede ser padre de una serie.']);
        exit;
    }

    $req_check_hijos = $bdd->prepare("SELECT COUNT(*) FROM libros WHERE pri_sec = :id");
    $req_check_hijos->execute([':id' => $id_libro]);
    if ($req_check_hijos->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Este libro ya tiene libros asociados y no puede asociarse a otro.']);
        exit;
    }
}

$req = $bdd->prepare("UPDATE libros SET pri_sec = :pri_sec WHERE id = :id");
$req->execute([':pri_sec' => $id_padre, ':id' => $id_libro]);

echo json_encode(['success' => true, 'message' => $id_padre > 0 ? 'Libro asociado correctamente.' : 'Asociación eliminada.']);
