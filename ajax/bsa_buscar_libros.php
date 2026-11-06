<?php
/**
 * Buscador (select2) de libros para "Backorders sin adopción": cualquier libro del CRM, por título
 * o ISBN, sin limitarse a los que tengan adopciones.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

header('Content-Type: application/json; charset=utf-8');
if (!bsa_tiene_acceso()) { echo json_encode([]); exit; }

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$stmt = $bdd->prepare("SELECT id, libro, isbn FROM libros WHERE libro LIKE ? OR isbn LIKE ? ORDER BY libro LIMIT 40");
$stmt->execute(['%' . $q . '%', '%' . $q . '%']);
$out = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $out[] = ['id' => (int)$l['id'], 'text' => $l['libro'] . ($l['isbn'] !== '' ? ' — ' . $l['isbn'] : '')];
}
echo json_encode($out);
