<?php
/**
 * /php/formato_vd_guardar.php   (POST, accion=...)
 * Guarda cada etapa del formato de venta directa (ver includes/formatos_vd_datos.php). Cada acción solo se
 * acepta en su etapa; la función correspondiente lo vuelve a verificar con el formato bloqueado:
 *   solicitud            formato nuevo (propuesta en la sesión bajo el token)  → etapa 1
 *   entrega              entrega de bodega con firmas                           → etapa 2
 *   devolucion_vincular  (2026-11-10) vincula una devolución registrada en Devoluciones de ventas; sus
 *                        unidades pasan al formato y sigue el cierre automático       → etapa 3, 4 o 5
 *   devolucion_borrador  devolución en preparación (no cambia la etapa; ya sin uso en pantalla)
 *   devolucion_generar   genera la devolución en InkPulse; si se verifica, la confirma y calcula
 *                        el cierre del inventario solo (fvd_avanzar_automatico)  → etapa 3, 4 o 5
 *   devolucion_confirmar verifica y confirma la devolución (respaldo manual)    → etapa 4
 *   inventario_confirmar cierre del inventario con precios (respaldo manual)    → etapa 5
 *   recepcion            recepción en bodega con firma                          → etapa 6
 *   dinero_borrador      guarda el cierre de dinero (no cambia la etapa)
 *   dinero_cerrar        confirma el cierre de dinero                           → etapa 7
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/formatos_vd_datos.php");

dm_validar_acceso(false, "../");
dm_asegurar_columnas($bdd);
fvd_asegurar_tablas($bdd); // antes de la transacción (DDL = commit implícito)

$accion = (string)($_POST["accion"] ?? "");
$id = (int)($_POST["id"] ?? 0);
$token = (string)($_POST["token"] ?? "");
$res = null;
$error = null;
$mensajes = [
    "solicitud" => "Solicitud guardada. Sigue la entrega de bodega.",
    "entrega" => "Entrega confirmada. Ya se puede registrar la devolución.",
    "devolucion_vincular" => "Devolución vinculada.",
    "devolucion_borrador" => "Devolución en preparación guardada.",
    "devolucion_generar" => "Devolución generada en InkPulse. Revisa la verificación y confírmala.",
    "devolucion_confirmar" => "Devolución confirmada. Sigue el cierre del inventario.",
    "inventario_confirmar" => "Inventario conciliado. Sigue la recepción en bodega.",
    "recepcion" => "Recepción registrada; la devolución quedó como Recibido. Sigue el cierre de dinero.",
    "armar_paquetes" => "Paquetes armados; el total de venta se recalculó.",
    "dinero_borrador" => "Cierre de dinero guardado.",
    "dinero_cerrar" => "Operación cerrada.",
];
// Si PHP descartó el envío por tamaño (post_max_size), llegan vacíos $_POST y $_FILES.
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$_POST && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
    $error = "El envío es demasiado grande (revisa el tamaño de las fotos o PDF de las firmas).";
} elseif ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($mensajes[$accion])) {
    $error = "Solicitud inválida.";
} elseif ($accion === "solicitud") {
    if (!isset($_SESSION["fvd_prop"][$token])) {
        // Puede ser un doble envío ya registrado: el token quedó en el formato.
        $st = $bdd->prepare("SELECT id FROM formatos_vd WHERE token_registro = ?");
        $st->execute([$token]);
        $ya = $token !== "" ? $st->fetchColumn() : false;
        $res = $ya ? ["ok" => true, "id" => (int)$ya] : ["ok" => false, "error" => "El formulario venció; vuelve a elegir los pedidos y abre el formato de nuevo."];
    } else {
        $res = fvd_guardar_nuevo($bdd, $_SESSION["fvd_prop"][$token], $_POST, $token);
        if ($res["ok"]) unset($_SESSION["fvd_prop"][$token]);
    }
} else {
    $f = fvd_obtener($bdd, $id);
    if (!$f) $error = "El formato no existe o no está en tu alcance.";
    elseif (!$f["activo"]) $error = "El formato está anulado.";
    else {
        switch ($accion) {
            case "entrega": $res = fvd_confirmar_entrega($bdd, $f, $_POST, $_FILES); break;
            case "devolucion_vincular": $res = fvd_vincular_devolucion($bdd, $f, (int)($_POST["id_devolucion"] ?? 0)); break;
            case "devolucion_borrador": $res = fvd_guardar_devueltas($bdd, $f, $_POST); break;
            case "devolucion_generar": $res = fvd_generar_devolucion($bdd, $f, $_POST); break;
            case "devolucion_confirmar": $res = fvd_confirmar_devolucion($bdd, $f); break;
            case "inventario_confirmar": $res = fvd_confirmar_inventario($bdd, $f); break;
            case "recepcion": $res = fvd_confirmar_recepcion($bdd, $f, $_POST, $_FILES); break;
            case "armar_paquetes": $res = fvd_armar_paquetes($bdd, $f, $_POST); break;
            case "dinero_borrador": $res = fvd_guardar_dinero($bdd, $f, $_POST, false); break;
            case "dinero_cerrar": $res = fvd_guardar_dinero($bdd, $f, $_POST, true); break;
        }
    }
}
if (!$error && $res["ok"]) {
    $msg = $mensajes[$accion];
    if ($accion === "devolucion_generar" || $accion === "devolucion_vincular") {
        // La devolución documentada confirma y calcula el cierre del inventario sola (fvd_avanzar_automatico).
        $hecha = $accion === "devolucion_vincular" ? "Devolución #" . (int)$res["id_devolucion"] . " vinculada" : "Devolución generada";
        $msg = $res["etapa"] >= FVD_ETAPA_CONCILIADO ? $hecha . " y verificada; unidades vendidas y cierre del inventario calculados. Sigue la recepción en bodega."
             : ($res["etapa"] === FVD_ETAPA_DEV_CONFIRMADA ? $hecha . " y verificada; unidades vendidas calculadas, pero falta configurar precios de venta."
             : $hecha . ", pero no pasó la verificación: revísala.");
        if (!empty($res["saldo"])) $msg .= " Saldo " . $res["saldo"]["numero"] . " por $ " . number_format($res["saldo"]["total"], 0, ",", ".") . ".";
    }
    // Cierre confirmado (2026-11-13): se abre el formato completo para imprimir.
    if ($accion === "dinero_cerrar") {
        header("Location: ../ver_formato_venta_directa.php?id=" . (int)$res["id"]);
        exit;
    }
    header("Location: ../formato_venta_directa.php?id=" . (int)$res["id"] . "&ink_status=success&ink_msg=" . urlencode($msg));
    exit;
}
$error = $error ?? $res["error"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Formato de venta directa</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .alert-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.10); padding: 40px 48px; text-align: center; max-width: 480px; width: 90%; }
    .icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; background: #fee2e2; color: #dc2626; }
    h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; }
    p  { font-size: .9rem; color: #64748b; line-height: 1.5; }
    .btn { display: inline-block; margin-top: 24px; padding: 10px 28px; border-radius: 8px; font-size: .9rem; font-weight: 600; text-decoration: none; background: #dc2626; color: #fff; border: none; cursor: pointer; }
  </style>
</head>
<body>
  <div class="alert-card">
    <div class="icon-wrap">&#10007;</div>
    <h2>No se guardó</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <!-- Volver con el navegador conserva lo que se escribió en el formulario (las firmas dibujadas hay que repetirlas). -->
    <button type="button" class="btn" onclick="history.back()">Volver al formato</button>
  </div>
</body>
</html>
