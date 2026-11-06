<?php
/**
 * JSON de "Backorders sin adopción" (backorders_sa.php y backorders_sa_informe.php) con los filtros
 * libro, tercero, estado, desde, hasta. Ver bsa_listar() en includes/backorders_sa_datos.php.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

header('Content-Type: application/json; charset=utf-8');
if (!bsa_tiene_acceso()) { echo json_encode(['success' => false, 'message' => 'No autorizado']); exit; }
session_write_close();

echo json_encode(['success' => true] + bsa_listar($bdd, bsa_filtros_desde_request($_GET)));
