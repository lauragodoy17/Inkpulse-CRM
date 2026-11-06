<?php
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

ini_set('memory_limit', '1024M');
set_time_limit(120);
// Libera el lock del archivo de sesión apenas se confirma el acceso: mientras esta consulta corre
// (puede tardar unos segundos con muchos pedidos), PHP mantiene bloqueada la sesión del usuario y
// cualquier otra pestaña/página que intente usarla se queda esperando hasta agotar su propio tiempo
// de ejecución (así se manifestó el timeout reportado en php/aut.php).
session_write_close();

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/periodos_fechas.php");
header('Content-Type: application/json');

asegurar_fechas_periodos($bdd);
$infoPeriodos = obtener_periodos_backorders($bdd);
$idPeriodo = isset($_GET['id_periodo']) && $_GET['id_periodo'] !== ''
    ? (int)$_GET['id_periodo']
    : $infoPeriodos['periodoActivo'];
$idUsuario = isset($_GET['id_usuario']) && $_GET['id_usuario'] !== '' ? (int)$_GET['id_usuario'] : null;
$idCliente = isset($_GET['id_cliente']) && $_GET['id_cliente'] !== '' ? (int)$_GET['id_cliente'] : null;

$datos = obtener_datos_backorders($bdd, $idPeriodo, $idUsuario, $idCliente);
$datos['sinVerificar'] = obtener_backorders_sin_verificar($bdd);
// El selector "Usuario" se acota al RANGO DE FECHAS del calendario del período (no al id_periodo
// literal) — mismo criterio que los buscadores de cliente/pedido, pedido por el usuario
// 2026-09-18.
[$fechaInicioPeriodo, $fechaFinPeriodo] = obtener_rango_fechas_periodo($bdd, $idPeriodo);
$idsUsuariosConPendiente = obtener_usuarios_con_pendiente_en_rango($bdd, $fechaInicioPeriodo, $fechaFinPeriodo);
$datos['usuarios'] = obtener_usuarios_backorders($bdd, $idsUsuariosConPendiente);
echo json_encode(['success' => true, 'idPeriodo' => $idPeriodo, 'idUsuario' => $idUsuario, 'idCliente' => $idCliente] + $infoPeriodos + $datos);
