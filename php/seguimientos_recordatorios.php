<?php
/**
 * /php/seguimientos_recordatorios.php
 * Envía los recordatorios de "Gestión y seguimiento" de colegios (un día antes de la próxima fecha).
 * Ver includes/seguimientos_colegio.php.
 *
 * - Por línea de comandos (tarea programada, una vez al día, ej. 7:00 am):
 *     php /ruta/al/crm/php/seguimientos_recordatorios.php
 *   En el servidor: cron "0 7 * * *". En Windows: Programador de tareas con C:\xampp\php\php.exe y esta ruta.
 * - Por web: lo llama template/nav_side.php una vez al día por navegador, por si la tarea no corrió.
 *   Solo con sesión iniciada. Cada seguimiento se avisa una sola vez, así que correrlo de más no repite correos.
 */
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    if (($_SESSION['autentificado'] ?? '') !== 'SI') {
        http_response_code(401);
        echo json_encode(['ok' => false]);
        exit;
    }
    session_write_close(); // no bloquear las demás páginas del usuario mientras se envían los correos
}

chdir(__DIR__);
require_once("../conexion/bdd.php");
require_once("../includes/seguimientos_colegio.php");
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $res = seg_enviar_recordatorios($bdd);
} catch (Exception $e) {
    error_log('Recordatorios de seguimiento: ' . $e->getMessage());
    $res = ['enviados' => 0, 'errores' => [$e->getMessage()]];
}

if ($cli) {
    echo date('Y-m-d H:i:s') . ' - Recordatorios enviados: ' . $res['enviados'] . PHP_EOL;
    foreach ($res['errores'] as $err) echo '  ERROR ' . $err . PHP_EOL;
} else {
    echo json_encode(['ok' => true, 'enviados' => $res['enviados']]);
}
