<?php
/**
 * Buscador (select2) de terceros para "Backorders sin adopción": consulta EN VIVO a World Office
 * (listarTerceros), por nombre o por número de identificación — ver bsa_buscar_terceros_wo().
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

header('Content-Type: application/json; charset=utf-8');
if (!bsa_tiene_acceso()) { echo json_encode(['results' => []]); exit; }
session_write_close(); // la consulta a World Office puede tardar: no bloquear la sesión

$terceros = bsa_buscar_terceros_wo($_GET['q'] ?? '');
if ($terceros === null) {
    echo json_encode(['results' => [], 'error' => 'No se pudo consultar World Office. Intenta de nuevo.']);
    exit;
}
$out = [];
foreach ($terceros as $t) {
    $out[] = [
        'id' => $t['id_wo'],
        'text' => $t['nombre'] . ($t['identificacion'] !== '' ? ' — ' . $t['identificacion'] : '') . ($t['ciudad'] !== '' ? ' (' . $t['ciudad'] . ')' : ''),
    ];
}
echo json_encode(['results' => $out]);
