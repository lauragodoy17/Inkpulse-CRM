<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if (($_SESSION["tipo"] ?? null) != 1 && ($_SESSION["id"] ?? null) != 21) {
    header("Location: ../ubicaciones.php");
    exit;
}

$id_tipo  = (int) ($_POST["id_tipo"] ?? 0);
$corredor = trim($_POST["corredor"] ?? '');
$lugar    = trim($_POST["lugar"] ?? '');

if (!in_array($id_tipo, [1, 2], true) || $corredor === '' || $lugar === '') {
    header("Location: ../ubicaciones.php?ink_status=error&ink_msg=" . urlencode('Completa todos los campos obligatorios.'));
    exit;
}

$dup = $bdd->prepare("SELECT id FROM lugares WHERE lugar = :lugar AND act = 1");
$dup->execute([':lugar' => $lugar]);
if ($dup->rowCount() > 0) {
    header("Location: ../ubicaciones.php?ink_status=error&ink_msg=" . urlencode('Ya existe una ubicación activa con ese nombre.'));
    exit;
}

$req = $bdd->prepare("INSERT INTO lugares (id_tipo, corredor, lugar, act) VALUES (:tipo, :corredor, :lugar, 1)");
$req->execute([':tipo' => $id_tipo, ':corredor' => $corredor, ':lugar' => $lugar]);
$id_lugar = $bdd->lastInsertId();

$ins = $bdd->prepare("INSERT INTO ubicaciones (id_lugar, piso, ubicacion, act) VALUES (:id_lugar, :piso, :ubicacion, 1)");

if ($id_tipo === 1) {
    $num_pisos   = max(1, min(26, (int) ($_POST["num_pisos"] ?? 0)));
    $num_pallets = max(1, min(50, (int) ($_POST["num_pallets"] ?? 0)));

    for ($p = 0; $p < $num_pisos; $p++) {
        $piso = chr(65 + $p);
        for ($pallet = 1; $pallet <= $num_pallets; $pallet++) {
            $ins->execute([':id_lugar' => $id_lugar, ':piso' => $piso, ':ubicacion' => $pallet]);
        }
    }
} else {
    $num_bandejas = max(1, min(26, (int) ($_POST["num_bandejas"] ?? 0)));

    for ($b = 0; $b < $num_bandejas; $b++) {
        $bandeja = chr(65 + $b);
        $ins->execute([':id_lugar' => $id_lugar, ':piso' => '', ':ubicacion' => $bandeja]);
    }
}

header("Location: ../ubicaciones.php?ink_status=ok&ink_msg=" . urlencode('Ubicación creada correctamente.'));
