<?php
/**
 * /ajax/solicitudes_vd_unidades.php?periodo=N&colegio=M
 * Comparativo en unidades de un colegio de venta directa para solicitudes_venta_directa.php (2026-11-24): solicitado,
 * remisionado (World Office), devuelto y vendido (svd_resumen_unidades). Solo colegios dentro del alcance del usuario.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/solicitudes_vd_datos.php");

dm_validar_acceso(true);
session_write_close(); // la consulta a World Office puede tardar: no bloquear las demás pestañas

header('Content-Type: application/json; charset=utf-8');
$periodo = (int)($_GET['periodo'] ?? 0);
$colegio = (int)($_GET['colegio'] ?? 0);
foreach (svd_listar($bdd, $periodo) as $col) { // svd_listar ya filtra por el alcance del usuario
    if ($col['id_colegio'] === $colegio) {
        echo json_encode(svd_resumen_unidades($bdd, $col), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
echo json_encode(['ok' => false, 'error' => 'El colegio no está en tu alcance o no tiene pedidos en el período.']);
