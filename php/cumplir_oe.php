<?php

require_once("../php/aut.php");
require_once("../conexion/bdd.php");

if (!in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: ../index.php");
    exit;
}

$sql_e = "UPDATE ordenes_externas SET estado = 4, fecha_cumplida = ? WHERE id = ?";

$query_e = $bdd->prepare($sql_e);
$sth_e   = $query_e->execute([date("Y-m-d H:i:s"), intval($_POST['oe'] ?? 0)]);

header("Location: ../oe_solicitada.php?oe=" . intval($_POST['oe'] ?? 0));
