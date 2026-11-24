<?php
/**
 * /php/devoluciones_ventas_guardar.php
 * Guarda una devolución del módulo devoluciones_ventas.php (uno o varios pedidos de venta del mismo colegio).
 * Las cantidades se vuelven a validar aquí contra lo despachado en World Office y lo ya devuelto
 * (dvv_guardar_devolucion), así que el tope no se puede saltar desde el navegador.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/devoluciones_ventas_datos.php");
require_once("../includes/formatos_vd_datos.php"); // formato de venta directa automático (fvd_desde_devolucion)
require_once("../includes/solicitudes_vd_datos.php"); // venta (consecutivo reservado) elegida para la devolución

dm_validar_acceso(false, '../');
dvv_asegurar_columnas($bdd); // antes de la transacción (DDL = commit implícito)
fvd_asegurar_tablas($bdd);

$claves = array_values(array_filter(array_map('dvd_clave_valida', (array)($_POST['claves'] ?? []))));
$volver = dvv_url('../devoluciones_ventas.php' . (($_POST['tipo_devolucion'] ?? '') === 'corte' ? '?tipo=corte' : ''));
$error = null;
$redirect = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$claves) {
    $error = 'Solicitud inválida.';
} else {
    $cantidades = is_array($_POST['cantidad'] ?? null) ? $_POST['cantidad'] : [];
    $observaciones = mb_substr(trim(str_replace(["'", '"'], '', (string)($_POST['observaciones'] ?? ''))), 0, 1000);
    // Venta directa: documentos de WO elegidos (docs[]) y paquetes / libros sueltos (paquete[], suelto[], abierto[] + suelto_paq[][]).
    $es_vd = !empty($_POST['modo_vd']);
    $docs_sel = $es_vd ? array_map('strval', (array)($_POST['docs'] ?? [])) : null;
    $post_vd = $es_vd ? ['paquete' => (array)($_POST['paquete'] ?? []), 'suelto' => (array)($_POST['suelto'] ?? []), 'suelto_paq' => (array)($_POST['suelto_paq'] ?? []),
                'abierto' => (array)($_POST['abierto'] ?? []), 'sp_desc' => (array)($_POST['sp_desc'] ?? []), 'sp_precio' => (array)($_POST['sp_precio'] ?? []),
                'suelto_curso' => (array)($_POST['suelto_curso'] ?? [])] : null;
    // Reparto por curso de los títulos pedidos en varios cursos (curso[id_libro][cod_area], 2026-11-24).
    $cursos_post = !$es_vd && is_array($_POST['curso'] ?? null) ? $_POST['curso'] : [];
    // Venta directa (2026-11-24): a qué venta (formato con consecutivo reservado) va la devolución; se valida ANTES de guardar.
    $id_formato = $es_vd ? (int)($_POST['id_formato'] ?? 0) : 0;
    $ped0 = $es_vd ? dvv_obtener_pedido($bdd, $claves[0]) : null;
    $error_venta = $ped0 ? svd_validar_venta($bdd, $id_formato, (int)$ped0['id_colegio'], (int)$ped0['id_periodo'], $claves) : null;
    $res = $error_venta ? ['ok' => false, 'error' => $error_venta]
         : dvv_guardar_devolucion($bdd, $claves, $cantidades, $observaciones, (string)($_POST['token'] ?? ''),
                                  dvv_tipo_devolucion((string)($_POST['tipo_devolucion'] ?? '')), $docs_sel, $post_vd, $cursos_post);
    if ($res['ok']) {
        $redirect = '../devolucion_colegio.php?id_pedido=' . $res['id_devolucion'];
        // Venta directa (2026-11-11): la devolución crea su formato de venta directa (o se vincula al que ya tengan
        // sus pedidos) y se abre ese formato para llenar los auxiliares y el dinero.
        $formato = null;
        $aviso_formato = null;
        if ($es_vd && empty($res['repetida'])) {
            $formato = fvd_desde_devolucion($bdd, $res['id_devolucion'], $id_formato);
            if ($formato['ok']) {
                // 2026-11-24: la 2a devolución de venta directa se suma al formato de la 1a y descuenta sus unidades.
                if ($formato['accion'] === 'agregado') {
                    $txt = 'La devolución #' . (int)$res['id_devolucion'] . ' se sumó al formato de venta directa: se descontaron sus unidades de lo vendido y se recalculó el total'
                         . (!empty($formato['reabierto']) ? '. El formato estaba cerrado: vuelve al cierre de dinero para confirmarlo con el total nuevo' : '');
                } else {
                    $txt = $formato['accion'] === 'creado' ? 'Se creó el formato de venta directa'
                         : ($formato['accion'] === 'reservado' ? 'Los pedidos y la devolución quedaron en el formato de la venta elegida' : 'Se vinculó al formato de venta directa');
                }
                $txt .= ($formato['etapa'] ?? 0) >= FVD_ETAPA_RECIBIDO ? ' con el total vendido calculado. Llena los auxiliares de la venta y el cierre de dinero.'
                      : (($formato['etapa'] ?? 0) === FVD_ETAPA_DEV_CONFIRMADA ? ', pero falta configurar precios de venta para calcular el total vendido.' : '.');
                $redirect = '../formato_venta_directa.php?id=' . (int)$formato['id'] . '&ink_status=success&ink_msg=' . urlencode($txt);
            } else {
                $aviso_formato = 'La devolución quedó guardada, pero no se generó el formato de venta directa: ' . $formato['error'];
                if (!empty($formato['id'])) $redirect = '../formato_venta_directa.php?id=' . (int)$formato['id'];
            }
        }
    } else {
        $error = $res['error'];
    }
}
$ids = array_map(fn($c) => (int)substr($c, 1), $claves);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Registrar devolución de venta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .alert-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.10); padding: 40px 48px; text-align: center; max-width: 480px; width: 90%; }
    .icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; }
    .icon-ok  { background: #dcfce7; color: #16a34a; }
    .icon-err { background: #fee2e2; color: #dc2626; }
    h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; }
    p  { font-size: .9rem; color: #64748b; line-height: 1.5; }
    .btn { display: inline-block; margin-top: 24px; padding: 10px 28px; border-radius: 8px; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .btn-ok  { background: #16a34a; color: #fff; }
    .btn-err { background: #dc2626; color: #fff; }
    .countdown { font-size: .78rem; color: #94a3b8; margin-top: 10px; }
  </style>
</head>
<body>
<?php if (!$error): ?>
  <div class="alert-card">
    <div class="icon-wrap icon-ok">&#10003;</div>
    <h2>¡Devolución registrada!</h2>
    <p>La devolución de <?= count($ids) === 1 ? 'el pedido #' . $ids[0] : 'los pedidos #' . implode(', #', $ids) ?> fue guardada correctamente.</p>
    <?php if (!empty($aviso_formato)): ?>
      <p style="margin-top:12px;color:#b45309;background:#fef3c7;border-radius:8px;padding:10px;"><?= htmlspecialchars($aviso_formato) ?></p>
      <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-ok"><?= !empty($formato['id']) ? 'Ver formato' : 'Ver devolución' ?></a>
  </div>
    <?php else: ?>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-ok"><?= !empty($formato['ok']) ? 'Ver formato de venta directa' : 'Ver devolución' ?></a>
  </div>
  <script>
    var dest = <?= json_encode($redirect) ?>, s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = dest; }
    }, 1000);
  </script>
    <?php endif; ?>
<?php else: ?>
  <div class="alert-card">
    <div class="icon-wrap icon-err">&#10007;</div>
    <h2>No se registró la devolución</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="<?= htmlspecialchars($volver) ?>" class="btn btn-err">Volver</a>
  </div>
<?php endif; ?>
</body>
</html>
