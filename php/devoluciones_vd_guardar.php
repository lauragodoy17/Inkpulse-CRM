<?php
/**
 * /php/devoluciones_vd_guardar.php
 * Antes registraba la legalización de venta directa desde devoluciones_venta_directa.php?formato=N. Desde
 * 2026-10-22 la devolución se genera en el paso 3 del formato de venta directa (fvd_generar_devolucion,
 * que además cambia la etapa del formato), así que aquí ya no se registra nada: se vuelve al formato.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/formatos_vd_datos.php");

dm_validar_acceso(false, "../");

$id_formato = (int)($_POST["id_formato"] ?? 0);
header("Location: ../" . ($id_formato ? "formato_venta_directa.php?id=" . $id_formato . "&" : "devoluciones_venta_directa.php?")
    . "ink_status=error&ink_msg=" . urlencode("La devolución se genera desde el paso 3 (Devolución) del formato de venta directa."));
exit;
