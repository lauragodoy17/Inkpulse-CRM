<?php
/**
 * /php/legalizaciones_muestras_guardar.php
 * Guarda una legalización del módulo legalizaciones_muestras.php. Las cantidades se vuelven a validar
 * aquí contra lo despachado en World Office y el saldo (despachado − devuelto − legalizado), así que
 * el tope no se puede saltar desde el navegador.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/legalizaciones_muestras_datos.php");

dm_validar_acceso(false, '../');
dm_asegurar_columnas($bdd); // antes de la transacción (DDL = commit implícito)

$id_muestreo = (int)($_POST['id_muestreo'] ?? 0);
$volver = '../legalizaciones_muestras.php' . ($id_muestreo ? '?id_muestreo=' . $id_muestreo : '');
$error = null;
$redirect = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id_muestreo <= 0) {
    $error = 'Solicitud inválida.';
} else {
    $cantidades = is_array($_POST['cantidad'] ?? null) ? $_POST['cantidad'] : [];
    $observaciones = mb_substr(trim(str_replace(["'", '"'], '', (string)($_POST['observaciones'] ?? ''))), 0, 300);

    // Soporte adjunto: cualquier usuario, misma carpeta que el formulario anterior (php/crear_muestreo.php).
    $archivo = '';
    if (!empty($_FILES['archivo']['tmp_name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $dir = dirname(__DIR__) . '/uploads/muestreos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = uniqid() . '_' . preg_replace('/[^\w.\-]+/u', '_', basename($_FILES['archivo']['name']));
        if (move_uploaded_file($_FILES['archivo']['tmp_name'], $dir . $nombre)) {
            $archivo = 'uploads/muestreos/' . $nombre;
        } else {
            $error = 'No se pudo subir el archivo adjunto.';
        }
    }

    if (!$error) {
        $res = lm_guardar_legalizacion($bdd, $id_muestreo, $cantidades, $observaciones, $archivo, (string)($_POST['cierre'] ?? ''));
        if ($res['ok']) {
            $redirect = '../muestreo_colegio_resto.php?id_muestras_e=' . $res['id_legalizacion'];
        } else {
            $error = $res['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Legalizar muestras</title>
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
    <h2>¡Muestras legalizadas!</h2>
    <p>La legalización del pedido de muestras #<?= $id_muestreo ?> fue guardada correctamente.</p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-ok">Ver legalización</a>
  </div>
  <script>
    var dest = <?= json_encode($redirect) ?>, s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = dest; }
    }, 1000);
  </script>
<?php else: ?>
  <div class="alert-card">
    <div class="icon-wrap icon-err">&#10007;</div>
    <h2>No se registró la legalización</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="<?= htmlspecialchars($volver) ?>" class="btn btn-err">Volver</a>
  </div>
<?php endif; ?>
</body>
</html>
