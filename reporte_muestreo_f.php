<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <?php if ($_GET['tp'] == 1): ?>
    <title>Inkpulse - Reporte de muestras solicitadas</title>
  <?php else: ?>
    <title>Inkpulse - Reporte de muestras entregadas</title>
  <?php endif; ?>
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
            <div class="title">
              <?php if ($_GET['tp'] == 1): ?>
                <h4>Reporte de muestras solicitadas</h4>
              <?php else: ?>
                <h4>Reporte de muestras entregadas</h4>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-file-earmark-excel"></i></span>
          <span class="sm-section-title">Parámetros del reporte</span>
        </div>
        <div class="sm-section-body">

          <?php if ($_GET['tp'] == 1): ?>
            <form action="php/muestreo_f_excel.php" method="POST">
          <?php else: ?>
            <form action="php/muestreo_e_f_excel.php" method="POST">
          <?php endif; ?>

            <div class="row">
              <div class="col-md-4 col-12 mb-3">
                <label class="control-label">Usuario <small style="color:red;">*</small></label>
                <select name="usuario" id="usuario" class="form-control custom-select2" required>
                  <option value="">Seleccionar</option>
                  <option value="0">Todos</option>
                  <?php
                    $sql = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor FROM usuarios WHERE (tipo=3 OR tipo=6) AND act=1";
                    $req = $bdd->prepare($sql); $req->execute();
                    foreach ($req->fetchAll() as $p)
                      echo '<option value="'.$p['id'].'">'.$p['promotor'].'</option>';
                  ?>
                </select>
              </div>

              <div class="col-md-4 col-12 mb-3">
                <label class="control-label">Desde <small style="color:red;">*</small></label>
                <div class="input-group">
                  <input type="text" class="form-control date-picker" name="desde" id="desde" data-date-format="yyyy-mm-dd" required autocomplete="off" placeholder="Seleccionar fecha" />
                  <div class="input-group-append">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                  </div>
                </div>
              </div>

              <div class="col-md-4 col-12 mb-3">
                <label class="control-label">Hasta <small style="color:red;">*</small></label>
                <div class="input-group">
                  <input type="text" class="form-control date-picker" name="hasta" id="hasta" data-date-format="yyyy-mm-dd" required autocomplete="off" placeholder="Seleccionar fecha" />
                  <div class="input-group-append">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                  </div>
                </div>
              </div>
            </div>

            <div class="sm-footer">
              <button class="btn btn-primary">
                <i class="bi bi-download"></i> Exportar Excel
              </button>
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
