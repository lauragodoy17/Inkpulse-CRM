<?php
require_once("../php/aut.php");
require_once('../conexion/bdd.php');

if (!in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: ../index.php");
    exit;
}

header("Content-Type:text/html;charset=utf-8");

$error = null;
$oe_id = null;

// Subida de archivo
$dir_subida     = $_SERVER['DOCUMENT_ROOT'] . '/adjuntos_oe/';
$nombre_archivo = '';
if (!empty($_FILES['archivo']['name'])) {
    $nombre_archivo = uniqid() . "_" . $_FILES['archivo']['name'];
    $fichero_subido = $dir_subida . basename($nombre_archivo);
    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $fichero_subido)) {
        $nombre_archivo = '';
    }
}

// Insertar orden
$observaciones    = str_replace(['"', "'"], '', $_POST["observaciones"] ?? '');
$descripcion_otro = $_POST["descrip"] === '3' ? str_replace(['"', "'"], '', $_POST["descrip_otro"] ?? '') : '';

$sql_p2 = "INSERT INTO ordenes_externas(usuario,solicitante,empresa_solicitante,proveedor,descripcion,descripcion_otro,observaciones,adjunto,fecha_ent_s,anio)
            VALUES(?,?,?,?,?,?,?,?,?,?)";
$query_p2 = $bdd->prepare($sql_p2);
$ok = $query_p2->execute([
    $_SESSION["id"],
    $_POST["solicitante"]          ?? '',
    $_POST["empresa_solicitante"]  ?? '',
    $_POST["proveedor"]            ?? '',
    $_POST["descrip"]              ?? '',
    $descripcion_otro,
    $observaciones,
    $nombre_archivo,
    $_POST["fecha_ent_s"]          ?? '',
    date("y")
]);

if (!$ok) {
    $error = "No se pudo guardar la orden externa.";
}

if (!$error) {
    // Obtener id recién insertado
    $pedido = $bdd->query("SELECT id FROM ordenes_externas ORDER BY id DESC LIMIT 1")->fetch();
    $oe_id = $pedido['id'];

    // Insertar materiales
    foreach ($_POST["material_e"] as $material_raw) {
        if (trim($material_raw) === '') continue;

        $parts    = explode("/", $material_raw, 3);
        $nombre   = $parts[0] ?? '';
        $cantidad = $parts[1] ?? '';
        $costo    = $parts[2] ?? 0;

        if (trim($nombre) === '') continue;

        $nombre  = str_replace(['"', "'"], '', $nombre);
        $sql_p   = "INSERT INTO materiales_oe(oeid,material,cantidad,costo) VALUES(?,?,?,?)";
        $query_p = $bdd->prepare($sql_p);
        $ok_mat  = $query_p->execute([$oe_id, $nombre, $cantidad, $costo]);

        if (!$ok_mat) {
            $error = "La orden se guardó pero ocurrió un error al registrar uno de los materiales.";
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inkpulse - Orden externa</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .alert-card {
      background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.10);
      padding: 40px 48px; text-align: center; max-width: 440px; width: 90%;
    }
    .icon-wrap {
      width: 64px; height: 64px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 20px; font-size: 28px;
    }
    .icon-ok  { background: #dcfce7; color: #16a34a; }
    .icon-err { background: #fee2e2; color: #dc2626; }
    h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; }
    p  { font-size: .9rem; color: #64748b; line-height: 1.5; }
    .btn {
      display: inline-block; margin-top: 24px; padding: 10px 28px;
      border-radius: 8px; font-size: .9rem; font-weight: 600;
      text-decoration: none; cursor: pointer; border: none;
    }
    .btn-ok  { background: #16a34a; color: #fff; }
    .btn-err { background: #dc2626; color: #fff; }
    .countdown { font-size: .78rem; color: #94a3b8; margin-top: 10px; }
  </style>
</head>
<body>

<?php if (!$error): ?>
  <div class="alert-card">
    <div class="icon-wrap icon-ok">&#10003;</div>
    <h2>¡Orden registrada con éxito!</h2>
    <p>La orden externa #<?= htmlspecialchars($oe_id) ?> fue creada correctamente.</p>
    <p class="countdown" id="msg">Redirigiendo en 3 segundos...</p>
    <a href="../oe_solicitada.php?oe=<?= $oe_id ?>" class="btn btn-ok">Ver orden</a>
  </div>
  <script>
    var s = 3;
    var t = setInterval(function () {
      s--;
      document.getElementById('msg').textContent = 'Redirigiendo en ' + s + ' segundo' + (s !== 1 ? 's' : '') + '...';
      if (s <= 0) { clearInterval(t); window.location.href = '../oe_solicitada.php?oe=<?= $oe_id ?>'; }
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
