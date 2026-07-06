<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if ($_SESSION["tipo"] != 1 || !isset($_POST["id_usuario"])) {
    header("Location: ../usuarios.php");
    exit;
}

if ($_POST["id_usuario"] == $_SESSION["id"] && $_POST["act"] == 0) {
    header("Location: ../usuarios.php?ink_status=error&ink_msg=" . urlencode('No puedes desactivar tu propia cuenta.'));
    exit;
}

if ($_POST["clave"] !== '') {
    $sql = "UPDATE usuarios SET nombres=:nombres, apellidos=:apellidos, telefono=:telefono, correo=:correo, tipo=:tipo, id_pais=:pais, act=:act, clave=:clave WHERE id=:id";
    $req = $bdd->prepare($sql);
    $req->execute([
        ':nombres'   => $_POST["nombres"],
        ':apellidos' => $_POST["apellidos"],
        ':telefono'  => $_POST["telefono"],
        ':correo'    => $_POST["correo"],
        ':tipo'      => $_POST["tipo"],
        ':pais'      => $_POST["pais"],
        ':act'       => $_POST["act"],
        ':clave'     => md5($_POST["clave"]),
        ':id'        => $_POST["id_usuario"],
    ]);
} else {
    $sql = "UPDATE usuarios SET nombres=:nombres, apellidos=:apellidos, telefono=:telefono, correo=:correo, tipo=:tipo, id_pais=:pais, act=:act WHERE id=:id";
    $req = $bdd->prepare($sql);
    $req->execute([
        ':nombres'   => $_POST["nombres"],
        ':apellidos' => $_POST["apellidos"],
        ':telefono'  => $_POST["telefono"],
        ':correo'    => $_POST["correo"],
        ':tipo'      => $_POST["tipo"],
        ':pais'      => $_POST["pais"],
        ':act'       => $_POST["act"],
        ':id'        => $_POST["id_usuario"],
    ]);
}

header("Location: ../usuarios.php?ink_status=ok&ink_msg=" . urlencode('Datos actualizados correctamente.'));
