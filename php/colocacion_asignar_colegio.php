<?php
/**
 * /php/colocacion_asignar_colegio.php
 * Asigna manualmente un colegio de Calendario B a un documento de World Office que el
 * cruce automático (includes/matching_colegios.php) no pudo emparejar. Marca
 * `asignado_manual = 1` para que una futura sincronización (php/sync_colocacion_wo.php) no
 * sobrescriba la asignación manual con el resultado (probablemente NULL de nuevo) del matcher.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$id_wo = isset($_POST['id_wo']) ? (int)$_POST['id_wo'] : 0;
$id_colegio = isset($_POST['id_colegio']) ? (int)$_POST['id_colegio'] : 0;

if ($id_wo <= 0 || $id_colegio <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$stmt = $bdd->prepare("SELECT id FROM colegios WHERE id = ? AND id_calendario = 2");
$stmt->execute([$id_colegio]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'El colegio no existe en Calendario B']);
    exit;
}

try {
    $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD COLUMN asignado_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER id_colegio");
} catch (Exception $e) {}

$stmt = $bdd->prepare("UPDATE wo_documentos_colocacion SET id_colegio = ?, asignado_manual = 1, updated_at = NOW() WHERE id_wo = ?");
$stmt->execute([$id_colegio, $id_wo]);

echo json_encode(['success' => $stmt->rowCount() > 0]);
