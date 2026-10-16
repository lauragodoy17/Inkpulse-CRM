<?php
/**
 * /php/sesion_ping.php
 * Lo llaman cada pocos minutos las páginas donde se trabaja largo rato sin guardar (ej. lista_empaque.php)
 * para que PHP no borre la sesión por inactividad. No usa aut.php para no redirigir.
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => ($_SESSION['autentificado'] ?? '') === 'SI']);
