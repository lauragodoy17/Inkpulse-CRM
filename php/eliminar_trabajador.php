<?php
include("../conexion/bdd.php");
if (session_status() === PHP_SESSION_NONE) session_start();
require_once("registrar_historial.php");

header('Content-Type: application/json');

$id = intval($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false]); exit; }

$stmt = $bdd->prepare("SELECT id_colegio, nombre, apellido FROM trabajadores_colegios WHERE id = :id AND activo = 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) { echo json_encode(['ok' => false]); exit; }

$bdd->prepare("UPDATE trabajadores_colegios SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

registrar_historial(
    $bdd,
    $row['id_colegio'],
    intval($_SESSION['id'] ?? 0),
    'Información de contacto',
    'Contacto desactivado',
    trim($row['nombre'] . ' ' . $row['apellido']),
    ''
);

echo json_encode(['ok' => true]);
