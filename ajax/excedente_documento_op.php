<?php
/**
 * Revisa si el documento de World Office que se va a asociar a una OP envía más unidades de las
 * solicitadas en sus pedidos (ver includes/excedentes_despacho.php). Lo usa op_pendiente.php antes
 * de guardar el documento, atender la OP o editar un despacho/registro del historial, para mostrar
 * la alerta y pedir confirmación.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/excedentes_despacho.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['autentificado'] ?? '') !== 'SI') {
    echo json_encode(['ok' => false, 'error' => 'Sesión vencida.']);
    exit;
}

$calc = excedentes_calcular($bdd, intval($_GET['op'] ?? 0), intval($_GET['id_wo'] ?? 0));
echo json_encode($calc, JSON_UNESCAPED_UNICODE);
