<?php
/**
 * /php/cron_alertas_vd.php
 * Recordatorio de ventas directas cercanas (includes/alertas_vd.php) para una tarea programada, por ejemplo cada
 * hora en el cron del servidor:
 *     0 * * * *  php /ruta/del/crm/php/cron_alertas_vd.php
 * Sin la tarea, el CRM igual revisa solo mientras alguien lo usa (template/nav_side.php). Solo corre por consola.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
date_default_timezone_set('America/Bogota');
require_once(__DIR__ . '/../conexion/bdd.php');
require_once(__DIR__ . '/../includes/alertas_vd.php');

$n = alertas_vd_recordatorios($bdd, true);
echo date('Y-m-d H:i') . " - recordatorios de venta directa enviados: $n\n";
