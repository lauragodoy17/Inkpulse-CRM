<?php
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

if (!in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: ../index.php");
    exit;
}

header("Content-Type:text/html;charset=utf-8");

$error = null;
$oe    = intval($_POST['oe'] ?? 0);

// Eliminar materiales que ya no están en el formulario
$req = $bdd->prepare("SELECT id FROM materiales_oe WHERE oeid = ?");
$req->execute([$oe]);
$ids_bd   = array_column($req->fetchAll(), 'id');
$ids_post = array_map('intval', $_POST['mpid'] ?? []);
$eliminar = array_diff($ids_bd, $ids_post);

foreach ($eliminar as $id_elim) {
    $bdd->prepare("DELETE FROM materiales_oe WHERE id = ?")->execute([$id_elim]);
}

// Insertar nuevos materiales
foreach ($_POST['material_e'] ?? [] as $material_raw) {
    if (trim($material_raw) === '') continue;
    $parts    = explode("/", $material_raw, 3);
    $nombre   = $parts[0] ?? '';
    $cantidad = $parts[1] ?? '';
    $costo    = $parts[2] ?? 0;
    if (trim($nombre) === '') continue;

    $nombre = str_replace(['"', "'"], '', $nombre);
    $ok = $bdd->prepare("INSERT INTO materiales_oe(oeid,material,cantidad,costo) VALUES(?,?,?,?)")
               ->execute([$oe, $nombre, $cantidad, $costo]);
    if (!$ok) { $error = "Error al insertar un material nuevo."; break; }
}

// Actualizar cantidad, costo y título por material
if (!$error) {
    foreach ($_POST['mat_p'] ?? [] as $val) {
        if (trim($val) === '') continue;
        $parts  = explode("/", $val, 4);
        $mat    = $parts[0] ?? '';
        $cant   = $parts[1] ?? '';
        $costo  = $parts[2] ?? 0;
        $nombre = trim($parts[3] ?? '');
        if (trim($mat) === '') continue;

        if ($nombre !== '') {
            $nombre = str_replace(['"', "'"], '', $nombre);
            $ok = $bdd->prepare("UPDATE materiales_oe SET material = ?, cantidad = ?, costo = ? WHERE id = ?")
                       ->execute([$nombre, $cant, $costo, $mat]);
        } else {
            $ok = $bdd->prepare("UPDATE materiales_oe SET cantidad = ?, costo = ? WHERE id = ?")
                       ->execute([$cant, $costo, $mat]);
        }
        if (!$ok) { $error = "Error al actualizar un material."; break; }
    }
}

// Registrar las entregas diligenciadas en la tabla (no es necesario llenar todas las columnas)
$hubo_entrega = false;
if (!$error) {
    $stmt_ent = $bdd->prepare("INSERT INTO entregas_oe(fecha, id_material_oe, cant_entregada, observacion_entrega) VALUES(?, ?, ?, ?)");
    foreach ($_POST['entrega'] ?? [] as $col_valores) {
        foreach ($col_valores as $val) {
            if (trim($val) === '') continue;

            $parts       = explode("/", $val, 3);
            $material_id = $parts[0] ?? '';
            $cantidad    = $parts[1] ?? '';
            $fecha       = trim($parts[2] ?? '') !== '' ? $parts[2] : date('Y-m-d');

            if (trim($material_id) === '' || trim($cantidad) === '') continue;

            $ok = $stmt_ent->execute([$fecha, $material_id, $cantidad, $_POST['observaciones'] ?? '']);
            if (!$ok) { $error = "Error al registrar una de las entregas."; break 2; }
            $hubo_entrega = true;
        }
    }
}

// Actualizar datos generales de la orden
if (!$error) {
    $ok = $bdd->prepare(
        "UPDATE ordenes_externas SET observaciones = ?, proveedor = ?, fecha_ent_s = ? WHERE id = ?"
    )->execute([
        $_POST['observaciones'] ?? '',
        $_POST['proveedor']     ?? '',
        $_POST['fecha_ent_s']   ?? '',
        $oe
    ]);
    if (!$ok) { $error = "Error al actualizar los datos de la orden."; }
}

// Si se registraron entregas nuevas, marcar la orden como "en proceso" (sin bajar una ya cumplida)
if (!$error && $hubo_entrega) {
    $ok = $bdd->prepare(
        "UPDATE ordenes_externas SET fecha_entrega = NOW(), estado = IF(estado = 0, 2, estado) WHERE id = ?"
    )->execute([$oe]);
    if (!$ok) { $error = "Las entregas se guardaron pero no se pudo actualizar el estado de la orden."; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Guardar Orden Externa</title>
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
    <p>La orden externa #<?= $oe ?> fue actualizada correctamente.</p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="../oe_solicitada.php?oe=<?= $oe ?>" class="btn btn-ok">Ver orden</a>
  </div>
  <script>
    var s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = '../oe_solicitada.php?oe=<?= $oe ?>'; }
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
