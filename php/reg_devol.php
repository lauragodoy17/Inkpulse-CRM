<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header("Content-Type:text/html;charset=utf-8");

$error          = null;
$redirect       = '../devol_muestras_sa.php?tp=' . intval($_POST['tp'] ?? 1);
$nombre_archivo = '';
$tp             = intval($_POST['tp'] ?? 1);

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    // Subida de archivo (solo admins)
    if ($_SESSION["tipo"] == 1 || $_SESSION["tipo"] == 2 || $_SESSION["id"] == 19) {
        $estado = 2;
        if (!empty($_FILES['archivo']['tmp_name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $dir_subida     = $_SERVER['DOCUMENT_ROOT'] . '/promotores/adjuntos/';
            $nombre_archivo = uniqid() . '_' . basename($_FILES['archivo']['name']);
            if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dir_subida . $nombre_archivo)) {
                throw new Exception("No se pudo subir el archivo adjunto.");
            }
        }
    } else {
        $estado = 1;
    }

    // Tabla según tipo
    $table_cod = $tp == 1 ? 'devoluciones' : ($tp == 2 ? 'devoluciones_prov' : 'devoluciones_v');

    // Generar código único
    do {
        $cod_pedido = '';
        for ($i = 0; $i < 10; $i++) $cod_pedido .= rand(0, 9);
        $ck = $bdd->prepare("SELECT codigo FROM $table_cod WHERE codigo = ?");
        $ck->execute([$cod_pedido]);
    } while ($ck->fetch());

    // Insertar libros normales
    foreach ($_POST['libro_e'] ?? [] as $libro) {
        if (empty($libro) || strpos($libro, '/') === false) continue;
        $parts    = explode('/', $libro, 2);
        $id_libro = $parts[0] ?? '';
        $cantidad = $parts[1] ?? 0;
        if ($cantidad <= 0 || trim($id_libro) === '') continue;
        $tbl = ($tp == 3) ? 'libros_devol_v' : 'libros_devol';
        $bdd->prepare("INSERT INTO $tbl(cod_pedido, id_libro, cantidad) VALUES(?, ?, ?)")
            ->execute([$cod_pedido, $id_libro, $cantidad]);
    }

    // Insertar libros primaria/secundaria
    foreach ($_POST['pri_sec'] ?? [] as $index => $id_libro) {
        $cantidad = $_POST['cantidad_pri_sec'][$index] ?? 0;
        if ($cantidad <= 0) continue;
        $tbl = ($tp == 3) ? 'libros_devol_v' : 'libros_devol';
        $bdd->prepare("INSERT INTO $tbl(cod_pedido, id_libro, cantidad) VALUES(?, ?, ?)")
            ->execute([$cod_pedido, $id_libro, $cantidad]);
    }

    // Insertar encabezado
    $obs = str_replace(["'", '"'], '', $_POST['observaciones'] ?? '');
    if ($tp == 1) {
        $bdd->prepare("INSERT INTO devoluciones(codigo, tipo, id_periodo, id_usuario, observaciones, archivo, estado) VALUES(?, '1', '1', ?, ?, ?, ?)")
            ->execute([$cod_pedido, $_POST['cliente'] ?? '', $_SESSION['id'], $obs, $nombre_archivo, $estado]);
    } elseif ($tp == 2) {
        $bdd->prepare("INSERT INTO devoluciones_prov(codigo, tipo, id_periodo, persona, id_usuario, observaciones, archivo, estado) VALUES(?, '2', '1', ?, ?, ?, ?, ?)")
            ->execute([$cod_pedido, $_POST['persona'] ?? '', $_SESSION['id'], $obs, $nombre_archivo, $estado]);
    } else {
        $bdd->prepare("INSERT INTO devoluciones_v(codigo, id_usuario, observaciones, cliente, tipo, estado) VALUES(?, ?, ?, ?, '1', '1')")
            ->execute([$cod_pedido, $_SESSION['id'], $obs, $_POST['cliente'] ?? '']);
    }

    // ID generado para redirect
    $sr = $bdd->prepare("SELECT id, tipo FROM $table_cod WHERE codigo = ?");
    $sr->execute([$cod_pedido]);
    $pedido = $sr->fetch();

    if ($tp == 3) {
        $redirect = '../devolucion_colegio.php?id_pedido=' . $pedido['id'];
    } else {
        $redirect = '../vista_devol.php?id_devol=' . $pedido['id'] . '&tipo=' . $pedido['tipo'];
    }

} catch (Exception $e) {
    $error = 'Error al registrar la devolución: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Registrar devolución</title>
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
    <h2>¡Devolución registrada!</h2>
    <p>La devolución fue guardada correctamente.</p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-ok">Ver devolución</a>
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
    <h2>Error al registrar</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="javascript:history.back()" class="btn btn-err">Volver e intentar de nuevo</a>
  </div>
<?php endif; ?>

</body>
</html>
