<?php
/**
 * /php/seguimiento_colegio_guardar.php (POST)
 * Registra una gestión/seguimiento del colegio (pestaña Visitas → Gestión y seguimiento de colegio.php).
 * Ver includes/seguimientos_colegio.php.
 */
require_once("aut.php");
header('Content-Type: application/json; charset=utf-8');

// aut.php no corta la ejecución si la sesión venció.
if (($_SESSION['autentificado'] ?? '') !== 'SI') {
    header_remove('Location');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Tu sesión expiró. Inicia sesión en otra pestaña y vuelve a guardar.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/seguimientos_colegio.php");
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    seg_asegurar_tabla($bdd);
    $res = seg_guardar($bdd, $_POST, (int)($_SESSION['id'] ?? 0));
    // Si el próximo seguimiento es mañana, el aviso de "un día antes" ya toca hoy.
    if ($res['ok'] && ($_POST['proxima_fecha'] ?? '') === date('Y-m-d', strtotime('+1 day'))) {
        $env = seg_enviar_recordatorios($bdd);
        $res['recordatorio'] = $env['errores'] ? 'No se pudo enviar el recordatorio: ' . implode(' ', $env['errores']) : 'Se envió el recordatorio por correo.';
    }
} catch (Exception $e) {
    error_log('Seguimiento colegio: ' . $e->getMessage());
    $res = ['ok' => false, 'error' => 'No se pudo guardar el seguimiento.'];
}
echo json_encode($res, JSON_UNESCAPED_UNICODE);
