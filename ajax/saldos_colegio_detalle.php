<?php
/**
 * /ajax/saldos_colegio_detalle.php?id_colegio=&id_periodo=
 * Datos de saldos_colegio.php: remisionado − devoluciones de corte − devoluciones físicas, valorizado con la adopción
 * (dvv_saldo_colegio() en includes/devoluciones_ventas_datos.php). Solo lectura.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/devoluciones_ventas_datos.php");

dm_validar_acceso(true);
dvv_asegurar_columnas($bdd);
session_write_close(); // la consulta a WO puede tardar: no bloquear las demás pestañas

$res = dvv_saldo_colegio($bdd, (int)($_GET['id_colegio'] ?? 0), (int)($_GET['id_periodo'] ?? 0));
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE);
