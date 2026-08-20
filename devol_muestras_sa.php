<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <?php
    if ($_GET['tp'] == 1)     echo '<title>Inkpulse - Devolución de muestras</title>';
    elseif ($_GET['tp'] == 2) echo '<title>Inkpulse - Devolución de proveedores</title>';
    else                      echo '<title>Inkpulse - Devolución de venta sin adopción</title>';
  ?>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    .custom-select2 { width:auto !important; }

    .sm-page-desc { font-size:13px; color:#6b7280; margin:2px 0 0; font-weight:400; }

    .sm-section {
      background:#fff; border:1px solid #e5e7eb; border-radius:12px;
      overflow:hidden; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,.05);
    }
    .sm-section-head {
      display:flex; align-items:center; gap:10px;
      padding:13px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb;
    }
    .sm-step {
      width:22px; height:22px; border-radius:50%; background:#4361ee; color:#fff;
      font-size:11px; font-weight:700; display:flex; align-items:center;
      justify-content:center; flex-shrink:0;
    }
    .sm-sec-icon {
      width:30px; height:30px; border-radius:8px; background:#eff6ff; color:#4361ee;
      font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .sm-section-title { font-size:13.5px; font-weight:600; color:#1e293b; margin:0; }
    .sm-section-body  { padding:22px 24px; }

    .sm-book-block {
      border:1px solid #e5e7eb; border-radius:10px; padding:16px 16px 4px;
      margin-bottom:14px; background:#fafafa;
    }
    .sm-book-block:first-of-type { background:#fff; }

    .sm-book-label {
      font-size:12px; font-weight:700; color:#374151; text-transform:uppercase;
      letter-spacing:.04em; margin:0 0 12px 0; display:flex; align-items:center; gap:6px;
    }
    .sm-book-label i { color:#4361ee; }

    .sm-add-btn {
      display:inline-flex; align-items:center; gap:6px; color:#4361ee;
      font-size:13px; font-weight:600; cursor:pointer; border:none;
      background:none; padding:0; margin-bottom:20px; text-decoration:none;
    }
    .sm-add-btn:hover { color:#2d4cda; }

    .sm-book-header {
      display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;
    }
    .sm-book-header .sm-book-label { margin-bottom:0; }
    .btn-remove-book {
      display:inline-flex; align-items:center; gap:4px;
      background:#fee2e2; color:#dc2626; border:none;
      border-radius:6px; padding:4px 10px; font-size:.76rem;
      font-weight:600; cursor:pointer; transition:background .15s;
    }
    .btn-remove-book:hover { background:#fca5a5; }
    .btn-save-book {
      display:inline-flex; align-items:center; gap:4px;
      background:#dcfce7; color:#15803d; border:none;
      border-radius:6px; padding:4px 10px; font-size:.76rem;
      font-weight:600; cursor:pointer; transition:background .15s;
    }
    .btn-save-book:hover { background:#bbf7d0; }

    .sm-divider { border:none; border-top:1px solid #e5e7eb; margin:4px 0 20px; }

    .sm-footer {
      display:flex; justify-content:flex-end; align-items:center; padding-top:4px;
    }
    .sm-footer .btn-primary {
      display:inline-flex; align-items:center; gap:6px;
      padding:9px 22px; font-size:14px; font-weight:600; border-radius:8px;
    }
  </style>
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
              <?php
                if ($_GET['tp'] == 1)     echo '<h4>Devolución de muestras</h4>';
                elseif ($_GET['tp'] == 2) echo '<h4>Devolución de proveedores</h4>';
                else                      echo '<h4>Devolución de venta sin adopción</h4>';
              ?>
              <p class="sm-page-desc">Registra la información de la devolución.</p>
            </div>
          </div>
        </div>
      </div>

      <form action="php/reg_devol.php" method="POST" id="miFormulario" enctype="multipart/form-data">

        <!-- ── Sección 1: Cliente / Proveedor ──────────────── -->
        <div class="sm-section">
          <div class="sm-section-head">
            <span class="sm-step">1</span>
            <span class="sm-sec-icon"><i class="bi bi-person-lines-fill"></i></span>
            <span class="sm-section-title">
              <?= $_GET['tp'] == 2 ? 'Seleccione un proveedor' : 'Seleccione un cliente' ?>
            </span>
          </div>
          <div class="sm-section-body">
            <div class="row">
              <?php if ($_GET['tp'] == 2): ?>
                <div class="col-md-5 col-12 mb-3">
                  <label class="control-label">Proveedor <small style="color:red;">*</small></label>
                  <select class="form-control custom-select2" name="persona" id="persona" style="width:100%;" required>
                    <option value="">Seleccionar</option>
                    <?php
                      $sql = "SELECT * FROM proveedores";
                      $req = $bdd->prepare($sql); $req->execute();
                      foreach ($req->fetchAll() as $c)
                        echo '<option value="'.$c["id"].'">'.$c["proveedor"].'</option>';
                    ?>
                  </select>
                </div>
              <?php endif; ?>

              <?php if ($_SESSION["tipo"] == 1 || $_SESSION["tipo"] == 2): ?>
              <div class="col-md-5 col-12 mb-3">
                <label class="control-label">Soporte adjunto</label>
                <input type="file" name="archivo" id="archivo" class="form-control" />
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ── Sección 2: Libros ───────────────────────────── -->
        <div class="sm-section">
          <div class="sm-section-head">
            <span class="sm-step">2</span>
            <span class="sm-sec-icon"><i class="bi bi-book"></i></span>
            <span class="sm-section-title">Información del libro</span>
          </div>
          <div class="sm-section-body">

            <div class="otro_l">

              <!-- Libro #1 -->
              <div class="sm-book-block">
                <p class="sm-book-label"><i class="bi bi-bookmark-fill"></i> Libro #1</p>
                <div class="row">
                  <div class="form-group col-sm-4 col-12">
                    <label for="materia" class="control-label">Materia <small style="color:red;">*</small></label>
                    <select name="materia[]" id="materia" class="form-control">
                      <option value="">Selecciona una materia</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $m)
                          echo '<option value="'.$m['id'].'">'.$m['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-4 col-12">
                    <label id="l_libro" for="libro" class="control-label">Libro <small style="color:red;">*</small></label>
                    <select name="libro" id="libro" class="form-control custom-select2"></select>
                  </div>
                  <div class="form-group col-sm-4 col-12">
                    <label id="l_cantidad" for="cantidad" class="control-label">Cantidad <small style="color:red;">*</small></label>
                    <input type="number" class="form-control cantidad" name="cantidad" id="cantidad" placeholder="Cantidad">
                  </div>
                </div>
                <div id="ls_pri_sec"></div>
                <input type="hidden" name="libro_e[]" id="libro_e">
              </div>

              <!-- Libros adicionales -->
              <?php for ($i = 1; $i < 100; $i++): ?>
              <div id="agg_l<?= $i ?>" class="d-none sm-book-block">
                <div class="sm-book-header">
                  <p class="sm-book-label"><i class="bi bi-bookmark-fill"></i> Libro #<?= $i + 1 ?></p>
                  <div style="display:flex;gap:6px;">
                    <button type="button" class="btn-save-book"><i class="bi bi-floppy"></i> Guardar</button>
                    <button type="button" class="btn-remove-book" data-idx="<?= $i ?>">
                      <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                  </div>
                </div>
                <div class="row">
                  <div class="form-group col-sm-4 col-12">
                    <label id="l_materia<?= $i ?>" for="materia<?= $i ?>" class="control-label">Materia <small style="color:red;">*</small></label>
                    <select name="materia[]" id="materia<?= $i ?>" class="form-control">
                      <option value="">Selecciona una materia</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $m)
                          echo '<option value="'.$m['id'].'">'.$m['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-4 col-12">
                    <label id="l_libro<?= $i ?>" for="libro<?= $i ?>" class="control-label">Libro <small style="color:red;">*</small></label>
                    <select name="libro" id="libro<?= $i ?>" class="form-control custom-select2" width="200"></select>
                  </div>
                  <div class="form-group col-sm-4 col-12">
                    <label id="l_cantidad<?= $i ?>" for="cantidad<?= $i ?>" class="control-label">Cantidad <small style="color:red;">*</small></label>
                    <input type="number" class="form-control cantidad" name="cantidad" id="cantidad<?= $i ?>" placeholder="Cantidad">
                  </div>
                </div>
                <div id="ls_pri_sec<?= $i ?>"></div>
                <input type="hidden" name="libro_e[]" id="libro_e<?= $i ?>">
              </div>
              <?php endfor; ?>

            </div>

            <a id="agregar_libro" class="sm-add-btn">
              <i class="bi bi-plus-circle"></i> Agregar libro
            </a>

            <hr class="sm-divider">

            <div class="row">
              <div class="col-md-8 col-12">
                <div class="form-group mb-3">
                  <label for="observaciones" class="control-label">Observaciones</label>
                  <textarea name="observaciones" id="observaciones" class="form-control" rows="3" placeholder="Escribe observaciones opcionales..."></textarea>
                </div>
              </div>
            </div>

            <div class="sm-footer">
              <input type="hidden" name="tp" value="<?= $_GET['tp'] ?>">
              <button class="btn btn-primary" id="solicitar">
                <i class="bi bi-send"></i> Registrar devolución
              </button>
            </div>

          </div>
        </div>

      </form>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<script>
  $('#materia').on('change', function () {
    $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:'mat_gra='+$(this).val(), dataType:"html",
      success: function (resp) { $("#libro").html(resp); }
    });
  });

  $('#libro').on('change', function () {
    var cant = $('#cantidad').val(), libro = $(this).val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado == 15 || grado == 16) {
      $('#l_cantidad').addClass("d-none"); $('#cantidad').addClass("d-none");
      $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
        success: function (resp) { $("#ls_pri_sec").html('').append(resp); }
      });
    } else { $('#libro_e').val(libro + '/' + cant); }
  });

  $('#cantidad').keyup(function () {
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado != 15 || grado != 16) $('#libro_e').val($('#libro').val() + '/' + $(this).val());
  });

  var m = 1;
  $("#agregar_libro").click(function () {
    if (m > 98) $(this).addClass("d-none");
    $("#agg_l" + m).removeClass("d-none");
    m++;

    <?php for ($i = 1; $i < 100; $i++): ?>
    $('#materia<?= $i ?>').on('change', function () {
      $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:'mat_gra='+$(this).val(), dataType:"html",
        success: function (resp) { $("#libro<?= $i ?>").html(resp); }
      });
    });
    $('#libro<?= $i ?>').on('change', function () {
      var cant = $('#cantidad<?= $i ?>').val(), libro = $(this).val();
      var grado = $('#libro<?= $i ?> option:selected').attr('data-grado');
      if (grado == 15 || grado == 16) {
        $('#l_cantidad<?= $i ?>').addClass("d-none"); $('#cantidad<?= $i ?>').addClass("d-none");
        $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
          success: function (resp) { $("#ls_pri_sec<?= $i ?>").html('').append(resp); }
        });
      } else { $('#libro_e<?= $i ?>').val(libro + '/' + cant); }
    });
    $('#cantidad<?= $i ?>').keyup(function () {
      var grado = $('#libro option:selected').attr('data-grado');
      if (grado != 15 || grado != 16) $('#libro_e<?= $i ?>').val($('#libro<?= $i ?>').val() + '/' + $(this).val());
    });
    <?php endfor; ?>
  });

  $(document).on('click', '.btn-remove-book', function () {
    var idx = $(this).data('idx');
    $('#agg_l' + idx).addClass('d-none');
    $('#materia' + idx).val('');
    $('#libro' + idx).html('');
    $('#cantidad' + idx).val('');
    $('#libro_e' + idx).val('');
    $('#ls_pri_sec' + idx).html('');
    $('#l_cantidad' + idx).removeClass('d-none');
    $('#cantidad' + idx).removeClass('d-none');
  });

  $(document).on('click', '.btn-save-book', function () {
    $('#miFormulario').submit();
  });
</script>

</body>
</html>
