<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if ($_SESSION["tipo"] != 1 || !isset($_POST["id_calendario"])) {
    header("Location: ../periodos.php");
    exit;
}

$id_calendario = $_POST["id_calendario"];

$req_cal = $bdd->prepare("SELECT calendario FROM calendarios WHERE id=:id");
$req_cal->execute([':id' => $id_calendario]);
$calendario = $req_cal->fetchColumn();

if ($calendario === false) {
    header("Location: ../periodos.php?ink_status=error&ink_msg=" . urlencode('Calendario no válido.'));
    exit;
}

$req_ultimo = $bdd->prepare("SELECT periodo FROM periodos WHERE id_calendario=:id ORDER BY id DESC LIMIT 1");
$req_ultimo->execute([':id' => $id_calendario]);
$ultimo_periodo = $req_ultimo->fetchColumn();

$anio = $ultimo_periodo !== false ? ((int) preg_replace('/[^0-9]/', '', $ultimo_periodo) + 1) : (int) date('Y');
$sufijo = ($calendario === 'B') ? 'B' : '';
$periodo = $anio . $sufijo;

$sql = "INSERT INTO periodos (id_calendario, periodo, f_cierre, t_preescolar, t_primaria, t_6_9, t_10_11, created_at)
        VALUES (:id_calendario, :periodo, :f_cierre, :t_preescolar, :t_primaria, :t_6_9, :t_10_11, NOW())";

$req = $bdd->prepare($sql);
$req->execute([
    ':id_calendario' => $id_calendario,
    ':periodo'       => $periodo,
    ':f_cierre'      => $_POST["f_cierre"],
    ':t_preescolar'  => $_POST["t_preescolar"],
    ':t_primaria'    => $_POST["t_primaria"],
    ':t_6_9'         => $_POST["t_6_9"],
    ':t_10_11'       => $_POST["t_10_11"],
]);

header("Location: ../periodos.php?ink_status=ok&ink_msg=" . urlencode('Período "' . $periodo . '" creado correctamente.'));
