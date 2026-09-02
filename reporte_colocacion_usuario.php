<?php
require_once("php/aut.php");

// tipo=1 ya tiene esta misma exportación fusionada dentro de reporte_colocacion.php ("Exportar
// por usuario") — un solo archivo de Colocación para el admin, en vez de dos (ver
// memory/project_colocacion_modulo.md). El resto de tipos (2, 3, 6, etc.) sigue usando este archivo.
if (intval($_SESSION['tipo'] ?? 0) === 1) {
    header("Location: reporte_colocacion.php");
    exit;
}

require_once("conexion/bdd.php");
require_once("includes/colocacion_datos.php");

$tipo_sesion = intval($_SESSION['tipo'] ?? 0);
$es_admin_like = in_array($tipo_sesion, [1, 2, 7], true);
$periodos = obtener_periodos_colocacion($bdd);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Reporte de colocación</title>
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
            <div class="title"><h4>Reporte de colocación</h4></div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-file-earmark-excel"></i></span>
          <span class="sm-section-title">Parámetros del reporte</span>
        </div>
        <div class="sm-section-body">
          <form action="php/colocacion_excel_usuario.php" method="POST">
            <div class="row">
              <?php if ($es_admin_like): ?>
              <div class="col-md-5 col-12 mb-3">
                <label class="control-label">Usuario <small style="color:red;">*</small></label>
                <select name="usuario" id="usuario" class="form-control custom-select2" required>
                  <option value="">Seleccionar</option>
                  <option value="0">Todos</option>
                  <?php
                    $sql = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor FROM usuarios WHERE (tipo=3 || tipo=6 || tipo=1 || tipo=10) AND act=1";
                    $req = $bdd->prepare($sql); $req->execute();
                    foreach ($req->fetchAll() as $p)
                      echo '<option value="'.$p['id'].'">'.$p['promotor'].'</option>';
                  ?>
                </select>
              </div>
              <?php endif; ?>
              <div class="col-md-3 col-12 mb-3">
                <label class="control-label">Periodo <small style="color:red;">*</small></label>
                <select name="periodo" id="periodo" class="form-control" required>
                  <?php foreach ($periodos as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['periodo']) ?> (Calendario <?= htmlspecialchars($p['calendario']) ?>)</option>
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
