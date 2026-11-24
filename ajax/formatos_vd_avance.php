<?php
/**
 * /ajax/formatos_vd_avance.php?id=N
 * Hoja en vivo del formato de venta directa (ver_formato_venta_directa.php, 2026-11-24): devuelve la huella del estado
 * del formato (fvd_huella); si cambió desde que se pintó la hoja, la hoja se vuelve a cargar sola.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/formatos_vd_datos.php");

dm_validar_acceso(true);
fvd_asegurar_tablas($bdd);
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$id = (int)($_GET['id'] ?? 0);
if (!fvd_obtener($bdd, $id)) { // no existe o no está en el alcance del usuario
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'huella' => fvd_huella($bdd, $id), 'hora' => date('g:i:s a')]);
