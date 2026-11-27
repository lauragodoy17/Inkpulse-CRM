<?php
/**
 * /ajax/existencias_wo_bloque.php — datos de reporte_existencias_wo.php (ver includes/existencias_wo_datos.php)
 *   ?accion=ultima      la última consulta guardada + el estado de la actualización
 *   ?accion=estado      solo el avance de la actualización en curso (el informe lo consulta cada 1,5 s)
 *   ?accion=actualizar  actualiza todo desde World Office (~30 s). Sigue aunque se cierre la página.
 */
require_once("../php/aut.php");
header('Content-Type: application/json');

if (!in_array((int)($_SESSION['tipo'] ?? 0), [1, 2], true)) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
$idUsuario = (int)($_SESSION['id'] ?? 0);
// Libera la sesión: mientras se actualiza, el informe sigue consultando el avance y el resto del CRM responde.
session_write_close();

require_once("../conexion/bdd.php");
require_once("../includes/existencias_wo_datos.php");

$accion = (string)($_GET['accion'] ?? 'ultima');

if ($accion === 'actualizar') {
    ignore_user_abort(true);
    set_time_limit(300);
    $r = ewo_actualizar($bdd, $idUsuario);
    if (isset($r['error'])) {
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true] + $r);
    exit;
}

if ($accion === 'estado') {
    echo json_encode(['success' => true, 'estado' => ewo_estado($bdd)]);
    exit;
}

echo json_encode([
    'success' => true,
    'ultima' => ewo_ultima($bdd),
    'estado' => ewo_estado($bdd),
    'vigencia' => EWO_MINUTOS_VIGENCIA,
], JSON_UNESCAPED_UNICODE);
