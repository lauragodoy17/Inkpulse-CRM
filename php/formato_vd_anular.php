<?php
/**
 * /php/formato_vd_anular.php (POST id, motivo)
 * Anula un formato de venta directa antes de generar su devolución (etapas 1 y 2), con motivo obligatorio.
 * Sus pedidos quedan libres para otro formato. Ver includes/formatos_vd_datos.php.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/formatos_vd_datos.php");

dm_validar_acceso(false, "../");
fvd_asegurar_tablas($bdd);

$id = (int)($_POST["id"] ?? 0);
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !$id) {
    header("Location: ../formatos_venta_directa.php?ink_status=error&ink_msg=" . urlencode("Solicitud inválida."));
    exit;
}
$res = fvd_anular($bdd, $id, leer_motivo_anulacion($_POST["motivo"] ?? ""));
header("Location: ../formato_venta_directa.php?id=" . $id . "&ink_status=" . ($res["ok"] ? "success" : "error")
    . "&ink_msg=" . urlencode($res["ok"] ? "Formato anulado." : $res["error"]));
exit;
