<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

// Carlos Puentes (id=21) puede ver/editar ubicación de bodega, pero no crear libros.
if ($_SESSION["tipo"] != 1 || !isset($_POST["libro"])) {
    header("Location: ../libros.php");
    exit;
}

$sql = "INSERT INTO libros (isbn, id_materia, id_grado, libro, precio, presupuesto, etiqueta, pri_sec, columna, editorial, serie, id_wo)
        VALUES (:isbn, :materia, :grado, :libro, :precio, :presupuesto, '', 0, 0, 0, 0, :id_wo)";

$req = $bdd->prepare($sql);
$req->execute([
    ':isbn'        => $_POST["isbn"],
    ':materia'     => $_POST["materia"],
    ':grado'       => $_POST["grado"],
    ':libro'       => $_POST["libro"],
    ':precio'      => $_POST["precio"] !== '' ? $_POST["precio"] : 0,
    ':presupuesto' => $_POST["presupuesto"],
    // Cuando el libro se crea desde el panel de "libros nuevos" (World Office),
    // el formulario manda el id de WO en un campo oculto para no perder el
    // vínculo; si se crea a mano desde el botón normal, queda en 0.
    ':id_wo'       => !empty($_POST["id_wo"]) ? (int)$_POST["id_wo"] : 0,
]);

header("Location: ../libros.php?ink_status=ok&ink_msg=" . urlencode('Libro creado correctamente.'));
