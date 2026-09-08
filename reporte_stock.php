<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (!in_array($_SESSION['tipo'] ?? null, [1, 2], true)) {
    header("Location: index.php");
    exit;
}

// Mismo selector de usuarios que reporte_valoriza_global.php.
$sqlUsuarios = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor FROM usuarios WHERE (tipo=3 || tipo=6 || tipo=1 || tipo=10) AND act=1";
$usuarios = $bdd->query($sqlUsuarios)->fetchAll(PDO::FETCH_ASSOC);

$periodos = $bdd->query("SELECT id, periodo FROM periodos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Reporte de stock</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Reporte de stock</h4></div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-check-circle"></i></span>
          <span class="sm-section-title">Pedidos de venta</span>
        </div>
        <div class="sm-section-body">
          <form action="php/reporte_stock_excel.php" method="POST">
            <input type="hidden" name="origen" value="pedidos">
            <div class="row">
              <div class="col-md-5 col-12 mb-3">
                <label class="control-label">Usuario <small style="color:red;">*</small></label>
                <select name="usuario" class="form-control custom-select2" required>
                  <option value="">Seleccionar</option>
                  <option value="0">Todos</option>
                  <?php foreach ($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['promotor']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-12 mb-3">
                <label class="control-label">Periodo <small style="color:red;">*</small></label>
                <select name="periodo" class="form-control" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($periodos as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['periodo']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="sm-footer" style="display:flex; gap:20px; flex-wrap:wrap;">
              <button type="submit" name="detalle" value="0" class="btn btn-primary"><i class="bi bi-download"></i> Exportar Excel (General)</button>
              <button type="submit" name="detalle" value="1" class="btn btn-outline-primary"><i class="bi bi-download"></i> Exportar Excel (Detallado por colegio)</button>
            </div>
          </form>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-x-circle"></i></span>
          <span class="sm-section-title">Pedidos sin adopción</span>
        </div>
        <div class="sm-section-body">
          <form action="php/reporte_stock_excel.php" method="POST">
            <input type="hidden" name="origen" value="pedidos2">
            <div class="row">
              <div class="col-md-5 col-12 mb-3">
                <label class="control-label">Usuario <small style="color:red;">*</small></label>
                <select name="usuario" class="form-control custom-select2" required>
                  <option value="">Seleccionar</option>
                  <option value="0">Todos</option>
                  <?php foreach ($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['promotor']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-12 mb-3">
                <label class="control-label">Periodo <small style="color:red;">*</small></label>
                <select name="periodo" class="form-control" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($periodos as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['periodo']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="sm-footer">
              <button class="btn btn-primary"><i class="bi bi-download"></i> Exportar Excel</button>
            </div>
          </form>
        </div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
</body>
</html>
