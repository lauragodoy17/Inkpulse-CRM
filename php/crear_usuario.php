<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if ($_SESSION["tipo"] != 1 || !isset($_POST["correo"])) {
    header("Location: ../usuarios.php");
    exit;
}

$req_existe = $bdd->prepare("SELECT id FROM usuarios WHERE correo=:correo");
$req_existe->execute([':correo' => $_POST["correo"]]);

if ($req_existe->rowCount() > 0) {
    header("Location: ../usuarios.php?ink_status=error&ink_msg=" . urlencode('Ese correo ya existe, intente con otro.'));
    exit;
}

$sql = "INSERT INTO usuarios (tipo, id_pais, nombres, apellidos, telefono, correo, clave, cedula, direccion, cod_zona, act)
        VALUES (:tipo, :pais, :nombres, :apellidos, :telefono, :correo, :clave, '', '', '', 1)";
$req = $bdd->prepare($sql);
$req->execute([
    ':tipo'      => $_POST["tipo"],
    ':pais'      => $_POST["pais"],
    ':nombres'   => $_POST["nombres"],
    ':apellidos' => $_POST["apellidos"],
    ':telefono'  => $_POST["telefono"],
    ':correo'    => $_POST["correo"],
    ':clave'     => md5($_POST["clave"]),
]);

header("Location: ../usuarios.php?ink_status=ok&ink_msg=" . urlencode('Usuario creado correctamente.'));
