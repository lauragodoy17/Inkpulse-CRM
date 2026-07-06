<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if ($_SESSION["tipo"] != 1 || !isset($_POST["id_usuario"])) {
    header("Location: ../usuarios.php");
    exit;
}

if ($_POST["id_usuario"] == $_SESSION["id"]) {
    header("Location: ../usuarios.php?ink_status=error&ink_msg=" . urlencode('No puedes desactivar tu propia cuenta.'));
    exit;
}

$sql = "UPDATE usuarios SET act=0 WHERE id=:id";
$req = $bdd->prepare($sql);
$req->execute([':id' => $_POST["id_usuario"]]);

header("Location: ../usuarios.php?ink_status=ok&ink_msg=" . urlencode('Usuario desactivado correctamente.'));
