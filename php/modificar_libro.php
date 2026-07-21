<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

// Carlos Puentes (id=21) solo puede actualizar la ubicación en bodega (guardar_ubicacion_libro.php),
// no isbn/título/materia/grado/precio/presupuesto.
if ($_SESSION["tipo"] != 1 || !isset($_POST["id_libro"])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$req_grado = $bdd->prepare("SELECT id_grado FROM libros WHERE id=:id");
$req_grado->execute([':id' => $_POST["id_libro"]]);
$libro_actual = $req_grado->fetch();

if (!$libro_actual) {
    echo json_encode(['success' => false, 'message' => 'El libro no existe.']);
    exit;
}

if ($libro_actual["id_grado"] == 15 || $libro_actual["id_grado"] == 16) {

    $sql = "UPDATE libros SET isbn=:isbn, libro=:libro, id_materia=:materia, id_grado=:grado, presupuesto=:presupuesto WHERE id=:id";
    $req = $bdd->prepare($sql);
    $req->execute([
        ':isbn'        => $_POST["isbn"],
        ':libro'       => $_POST["libro"],
        ':materia'     => $_POST["materia"],
        ':grado'       => $_POST["grado"],
        ':presupuesto' => $_POST["presupuesto"],
        ':id'          => $_POST["id_libro"],
    ]);

    $sql_serie = "UPDATE libros SET presupuesto=:presupuesto WHERE pri_sec=:id";
    $req_serie = $bdd->prepare($sql_serie);
    $req_serie->execute([
        ':presupuesto' => $_POST["presupuesto"],
        ':id'          => $_POST["id_libro"],
    ]);

} else {

    $sql = "UPDATE libros SET isbn=:isbn, libro=:libro, id_materia=:materia, id_grado=:grado, precio=:precio, presupuesto=:presupuesto WHERE id=:id";
    $req = $bdd->prepare($sql);
    $req->execute([
        ':isbn'        => $_POST["isbn"],
        ':libro'       => $_POST["libro"],
        ':materia'     => $_POST["materia"],
        ':grado'       => $_POST["grado"],
        ':precio'      => $_POST["precio"],
        ':presupuesto' => $_POST["presupuesto"],
        ':id'          => $_POST["id_libro"],
    ]);
}

echo json_encode(['success' => true, 'message' => 'Libro actualizado correctamente.']);
