<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if ($_SESSION["tipo"] != 1 || !isset($_POST["libro"])) {
    header("Location: ../libros.php");
    exit;
}

$sql = "INSERT INTO libros (isbn, id_materia, id_grado, libro, precio, presupuesto, etiqueta, pri_sec, columna, editorial, serie)
        VALUES (:isbn, :materia, :grado, :libro, :precio, :presupuesto, '', 0, 0, 0, 0)";

$req = $bdd->prepare($sql);
$req->execute([
    ':isbn'        => $_POST["isbn"],
    ':materia'     => $_POST["materia"],
    ':grado'       => $_POST["grado"],
    ':libro'       => $_POST["libro"],
    ':precio'      => $_POST["precio"] !== '' ? $_POST["precio"] : 0,
    ':presupuesto' => $_POST["presupuesto"],
]);

header("Location: ../libros.php?ink_status=ok&ink_msg=" . urlencode('Libro creado correctamente.'));
