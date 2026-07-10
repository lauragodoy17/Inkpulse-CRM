<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if ($_SESSION["tipo"] != 1 || !isset($_POST["id"])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$sql = "UPDATE periodos SET id_calendario=:id_calendario, periodo=:periodo, f_cierre=:f_cierre,
        t_preescolar=:t_preescolar, t_primaria=:t_primaria, t_6_9=:t_6_9, t_10_11=:t_10_11, updated_at=NOW()
        WHERE id=:id";

$req = $bdd->prepare($sql);
$req->execute([
    ':id_calendario' => $_POST["id_calendario"],
    ':periodo'       => $_POST["periodo"],
    ':f_cierre'      => $_POST["f_cierre"],
    ':t_preescolar'  => $_POST["t_preescolar"],
    ':t_primaria'    => $_POST["t_primaria"],
    ':t_6_9'         => $_POST["t_6_9"],
    ':t_10_11'       => $_POST["t_10_11"],
    ':id'            => $_POST["id"],
]);

echo json_encode(['success' => true, 'message' => 'Período actualizado correctamente.']);
