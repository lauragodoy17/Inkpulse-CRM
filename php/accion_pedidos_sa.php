<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header("Content-Type:text/html;charset=utf-8");

$error    = null;
$titulo   = '';
$mensaje  = '';
$redirect = '../lista_pedidos_sa.php?tp=2';

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    if (isset($_GET['rechazar'])) {
        $id = intval($_GET['rechazar']);
        $bdd->prepare("UPDATE pedidos2 SET estado = '3' WHERE id = ?")->execute([$id]);
        $titulo   = '¡Pedido rechazado!';
        $mensaje  = "El pedido SA #$id fue rechazado correctamente.";
        $redirect = '../lista_pedidos_sa.php?tp=2';

    } elseif (isset($_GET['aprobar'])) {
        $id = intval($_GET['aprobar']);
        $bdd->prepare("UPDATE pedidos2 SET estado = '2' WHERE id = ?")->execute([$id]);
        $titulo   = '¡Pedido aprobado!';
        $mensaje  = "El pedido SA #$id fue aprobado correctamente.";
        $redirect = '../lista_pedidos_sa.php?tp=3';

    } elseif (isset($_GET['entregado'])) {
        $id = intval($_GET['entregado']);
        $bdd->prepare("UPDATE pedidos2 SET estado = '4' WHERE id = ?")->execute([$id]);
        $titulo   = '¡Pedido entregado!';
        $mensaje  = "El pedido SA #$id fue marcado como entregado.";
        $redirect = '../lista_pedidos_sa.php?tp=3';

    } else {
        $error = 'Acción no reconocida.';
    }
} catch (Exception $e) {
    $error = 'Error al procesar la acción: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Acción pedido SA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .alert-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.10); padding: 40px 48px; text-align: center; max-width: 440px; width: 90%; }
    .icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; }
    .icon-ok  { background: #dcfce7; color: #16a34a; }
    .icon-err { background: #fee2e2; color: #dc2626; }
    h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; }
    p  { font-size: .9rem; color: #64748b; line-height: 1.5; }
    .btn { display: inline-block; margin-top: 24px; padding: 10px 28px; border-radius: 8px; font-size: .9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; }
    .btn-ok  { background: #16a34a; color: #fff; }
    .btn-err { background: #dc2626; color: #fff; }
    .countdown { font-size: .78rem; color: #94a3b8; margin-top: 10px; }
  </style>
</head>
<body>

<?php if (!$error): ?>
  <div class="alert-card">
    <div class="icon-wrap icon-ok">&#10003;</div>
    <h2><?= htmlspecialchars($titulo) ?></h2>
    <p><?= htmlspecialchars($mensaje) ?></p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-ok">Ir a la lista</a>
  </div>
  <script>
    var dest = <?= json_encode($redirect) ?>;
    var s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = dest; }
    }, 1000);
  </script>

<?php else: ?>
  <div class="alert-card">
    <div class="icon-wrap icon-err">&#10007;</div>
    <h2>Error al procesar</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="javascript:history.back()" class="btn btn-err">Volver e intentar de nuevo</a>
  </div>
<?php endif; ?>

</body>
</html>
