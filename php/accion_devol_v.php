<?php
/**
 * /php/accion_devol_v.php?(rechazar|aprobar|proceso)=<id>[&motivo=...]
 * Cambios de estado de las devoluciones de ventas (tabla devoluciones_v) desde
 * devolucion_colegio.php. Anular (rechazar) exige motivo y queda en historial_estados (2026-09-28).
 */
require_once("aut.php");
// aut.php redirige sin detener la ejecución: aquí se corta explícitamente.
if (($_SESSION["autentificado"] ?? '') !== 'SI') {
    header("Location: ../login.php");
    exit;
}
include("../conexion/bdd.php");
require_once("../includes/historial_estados.php");
crear_tabla_historial_estados($bdd);

$volver = $_SERVER['HTTP_REFERER'] ?? '../ver_devol_ventas.php';

if (isset($_GET["rechazar"])) {
    $id = intval($_GET["rechazar"]);
    $motivo = leer_motivo_anulacion($_GET["motivo"] ?? '');
    if ($motivo === '') {
        header("Location: " . $volver . (strpos($volver, '?') === false ? '?' : '&') . "ink_status=error&ink_msg=" . urlencode('Para anular la devolución debes escribir el motivo. No se hizo ningún cambio.'));
        exit;
    }
    $bdd->prepare("UPDATE devoluciones_v SET estado='3' WHERE id=?")->execute([$id]);
    registrar_historial_estado($bdd, 'devoluciones_v', $id, ESTADO_ANULADO, intval($_SESSION['id'] ?? 0), $motivo);

} elseif (isset($_GET["aprobar"])) {
    $bdd->prepare("UPDATE devoluciones_v SET estado='2' WHERE id=?")->execute([intval($_GET["aprobar"])]);

} else {
    $bdd->prepare("UPDATE devoluciones_v SET estado='4', fecha_proceso=? WHERE id=?")->execute([date("Y-m-d H:i:s"), intval($_GET["proceso"] ?? 0)]);
}

header("Location: " . $volver);
exit;
