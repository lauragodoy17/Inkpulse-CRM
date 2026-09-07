<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header("Content-Type:text/html;charset=utf-8");

$error = null;
$opd   = intval($_POST['opd'] ?? 0);

// Eliminar libros que ya no están en el formulario
$req = $bdd->prepare("SELECT id FROM libros_opd WHERE opid = ?");
$req->execute([$opd]);
$ids_bd  = array_column($req->fetchAll(), 'id');
$ids_post = array_map('intval', $_POST['lpid'] ?? []);
$eliminar = array_diff($ids_bd, $ids_post);

foreach ($eliminar as $id_elim) {
    $bdd->prepare("DELETE FROM libros_opd WHERE id = ?")->execute([$id_elim]);
}

// Insertar nuevos materiales
foreach ($_POST['libro_e'] ?? [] as $libro_raw) {
    if (trim($libro_raw) === '') continue;
    $parts    = explode("/", $libro_raw, 3);
    $nombre   = $parts[0] ?? '';
    $cantidad = $parts[1] ?? '';
    $enca     = $parts[2] ?? '';
    if (trim($nombre) === '') continue;

    $nombre = str_replace(['"', "'"], '', $nombre);
    $ok = $bdd->prepare("INSERT INTO libros_opd(opid,libro,encaratulado,cantidad) VALUES(?,?,?,?)")
               ->execute([$opd, $nombre, $enca, $cantidad]);
    if (!$ok) { $error = "Error al insertar un material nuevo."; break; }
}

// Actualizar cantidad y título por libro
if (!$error) {
    foreach ($_POST['lib_p'] ?? [] as $val) {
        if (trim($val) === '') continue;
        $parts  = explode("/", $val, 3);
        $lib    = $parts[0] ?? '';
        $cant   = $parts[1] ?? '';
        $titulo = str_replace(['"', "'"], '', $parts[2] ?? '');
        if (trim($lib) === '') continue;
        $ok = $bdd->prepare("UPDATE libros_opd SET cantidad = ?, libro = ? WHERE id = ?")->execute([$cant, $titulo, $lib]);
        if (!$ok) { $error = "Error al actualizar cantidad de un material."; break; }
    }
}

// Actualizar clicks
if (!$error) {
    foreach ($_POST['i_click'] ?? [] as $val) {
        if (trim($val) === '') continue;
        $parts = explode("/", $val, 2);
        $lib   = $parts[0] ?? '';
        $click = $parts[1] ?? '';
        if (trim($lib) === '') continue;
        $ok = $bdd->prepare("UPDATE libros_opd SET click = ? WHERE id = ?")->execute([$click, $lib]);
        if (!$ok) { $error = "Error al actualizar clicks de un material."; break; }
    }
}

// Actualizar impresora y valor_click
if (!$error) {
    foreach ($_POST['i_impresora'] ?? [] as $val) {
        if (trim($val) === '') continue;
        $parts   = explode("/", $val, 3);
        $lib     = $parts[0] ?? '';
        $impre   = $parts[1] ?? '';
        $valor   = $parts[2] ?? '';
        if (trim($lib) === '') continue;
        $ok = $bdd->prepare("UPDATE libros_opd SET impresora = ?, valor_click = ? WHERE id = ?")->execute([$impre, $valor, $lib]);
        if (!$ok) { $error = "Error al actualizar impresora de un material."; break; }
    }
}

// Actualizar datos generales de la OPD
if (!$error) {
    $ok = $bdd->prepare(
        "UPDATE ordenes_produccion SET observaciones = ?, cliente = ?, fecha_ent_s = ?, solicitante = ? WHERE id = ?"
    )->execute([
        $_POST['observaciones'] ?? '',
        $_POST['persona']       ?? '',
        $_POST['fecha_ent_s']   ?? '',
        $_POST['solicitante']   ?? '',
        $opd
    ]);
    if (!$ok) { $error = "Error al actualizar los datos de la orden."; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Guardar OPD</title>
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
    <h2>¡Cambios guardados!</h2>
    <p>La OPD #<?= $opd ?> fue actualizada correctamente.</p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="../opd_solicitada.php?opd=<?= $opd ?>" class="btn btn-ok">Ver OPD</a>
  </div>
  <script>
    var s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = '../opd_solicitada.php?opd=<?= $opd ?>'; }
    }, 1000);
  </script>

<?php else: ?>
  <div class="alert-card">
    <div class="icon-wrap icon-err">&#10007;</div>
    <h2>Error al guardar</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="javascript:history.back()" class="btn btn-err">Volver e intentar de nuevo</a>
  </div>
<?php endif; ?>

</body>
</html>
