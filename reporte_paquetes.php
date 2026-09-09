<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/paquetes_colegio.php");

// Todo lo relacionado con "Tipo de adopción" (incluido este reporte) es exclusivo
// de Administrador (tipo=1) y Héctor Morales (id=69) — ver puede_usar_tipo_adopcion().
if (!puede_usar_tipo_adopcion()) {
    header("Location: index.php");
    exit;
}

// Usuarios "eureka": promotores (tipo=3) y Héctor Morales (id=69).
$sqlUsuarios = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor
                FROM usuarios
                WHERE (tipo = 3 OR id = 69) AND act = 1
                ORDER BY nombres";
$usuarios = $bdd->query($sqlUsuarios)->fetchAll(PDO::FETCH_ASSOC);

$periodos = $bdd->query("SELECT id, periodo FROM periodos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Reporte Paquetes</title>
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
            <div class="title"><h4>Reporte Paquetes</h4></div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-box-seam"></i></span>
          <span class="sm-section-title">Paquetes por grado</span>
        </div>
        <div class="sm-section-body">
          <form action="php/paquetes_reporte_excel.php" method="POST">
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
