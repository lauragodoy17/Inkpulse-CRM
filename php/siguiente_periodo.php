<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if ($_SESSION["tipo"] != 1 || !isset($_GET["id_calendario"]) || $_GET["id_calendario"] === '') {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$id_calendario = $_GET["id_calendario"];

$req_cal = $bdd->prepare("SELECT calendario FROM calendarios WHERE id=:id");
$req_cal->execute([':id' => $id_calendario]);
$calendario = $req_cal->fetchColumn();

if ($calendario === false) {
    echo json_encode(['success' => false, 'message' => 'Calendario no encontrado.']);
    exit;
}

$req_ultimo = $bdd->prepare("SELECT periodo, t_preescolar, t_primaria, t_6_9, t_10_11 FROM periodos WHERE id_calendario=:id ORDER BY id DESC LIMIT 1");
$req_ultimo->execute([':id' => $id_calendario]);
$ultimo = $req_ultimo->fetch(PDO::FETCH_ASSOC);

$anio = $ultimo ? ((int) preg_replace('/[^0-9]/', '', $ultimo["periodo"]) + 1) : (int) date('Y');
$sufijo = ($calendario === 'B') ? 'B' : '';

echo json_encode([
    'success'      => true,
    'periodo'      => $anio . $sufijo,
    't_preescolar' => $ultimo["t_preescolar"] ?? '',
    't_primaria'   => $ultimo["t_primaria"] ?? '',
    't_6_9'        => $ultimo["t_6_9"] ?? '',
    't_10_11'      => $ultimo["t_10_11"] ?? '',
]);
