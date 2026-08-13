<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$sql = "SELECT id, materia FROM materias";
$req = $bdd->prepare($sql);
$req->execute();
$materias = $req->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Solicitar pedido sin adopción</title>
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
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .custom-select2 { width: 100% !important; }

    .libro-block { border-top: 1px solid #e2e8f0; margin-top: 16px; padding-top: 16px; }
    .libro-block:first-child { border-top: none; margin-top: 0; padding-top: 0; }
    .libro-num { font-size: .78rem; font-weight: 700; color: #475569; text-transform: uppercase;
                 letter-spacing: .06em; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
    .libro-num i { color: #6366f1; }
    .libro-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .libro-header .libro-num { margin-bottom: 0; }
    .btn-remove-book {
      display: inline-flex; align-items: center; gap: 4px;
      background: #fee2e2; color: #dc2626; border: none;
      border-radius: 6px; padding: 4px 10px; font-size: .76rem;
      font-weight: 600; cursor: pointer; transition: background .15s;
    }
    .btn-remove-book:hover { background: #fca5a5; }
    .btn-save-book {
      display: inline-flex; align-items: center; gap: 4px;
      background: #dcfce7; color: #15803d; border: none;
      border-radius: 6px; padding: 4px 10px; font-size: .76rem;
      font-weight: 600; cursor: pointer; transition: background .15s;
    }
    .btn-save-book:hover { background: #bbf7d0; }

    .mc-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 10px 22px; border-radius: 8px; font-size: .9rem; font-weight: 700;
      border: none; cursor: pointer; text-decoration: none;
      transition: opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity: .88; transform: translateY(-1px); color: #fff; text-decoration: none; }
    .mc-btn-blue  { background: linear-gradient(135deg,#1d4ed8,#2563eb); color: #fff; }
    .mc-btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: 7px; font-size: .85rem; font-weight: 600;
      border: 1.5px solid #6366f1; color: #6366f1; background: transparent;
      cursor: pointer; transition: background .15s, color .15s;
    }
    .mc-btn-ghost:hover { background: #6366f1; color: #fff; }
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
            <div class="title"><h4>Solicitar pedido sin adopción</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Pedidos sin adopción</li>
                <li class="breadcrumb-item active">Solicitar</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <form action="php/pedido_sa.php" method="POST" id="miFormulario" enctype="multipart/form-data">

        <!-- Datos generales -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-file-earmark-text mr-2"></i> Datos del pedido</h5>
          </div>
          <div class="px-4 py-3">
            <div class="row">
              <div class="col-md-4 col-sm-6 col-12">
                <div class="form-group">
                  <label for="colegio" class="control-label">Colegio <small style="color:red;">*</small></label>
                  <input type="text" class="form-control" name="colegio" id="colegio" required>
                </div>
              </div>
              <div class="col-md-4 col-sm-6 col-12">
                <div class="form-group">
                  <label for="fac_rem" class="control-label">Factura o Remisión <small style="color:red;">*</small></label>
                  <select name="fac_rem" id="fac_rem" class="form-control" required>
                    <option value="0">Seleccionar</option>
                    <option value="1">Factura</option>
                    <option value="2">Remisión</option>
                  </select>
                </div>
              </div>
              <div class="col-md-4 col-sm-6 col-12">
                <div class="form-group">
                  <label for="archivo" class="control-label">Archivo adjunto</label>
                  <input type="file" name="archivo" id="archivo" class="form-control" />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Libros -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-book mr-2"></i> Libros</h5>
          </div>
          <div class="px-4 py-3">
            <div class="otro_l">

              <!-- Libro #1 -->
              <div class="libro-block">
                <p class="libro-num"><i class="bi bi-bookmark-fill"></i> Libro #1</p>
                <div class="row">
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_materia" for="materia" class="control-label">Materia <small style="color:red;">*</small></label>
                    <select name="materia[]" id="materia" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php foreach ($materias as $m): ?>
                      <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['materia']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_libro" for="libro" class="control-label">Libro <small style="color:red;">*</small></label>
                    <select name="libro" id="libro" class="form-control custom-select2"></select>
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_descuento" for="descuento" class="control-label">Descuento % <small style="color:red;">*</small></label>
                    <input type="number" class="form-control" name="descuento" id="descuento">
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_cantidad" for="cantidad" class="control-label">Cantidad <small style="color:red;">*</small></label>
                    <input type="number" class="form-control cantidad" name="cantidad" id="cantidad">
                  </div>
                </div>
                <div id="ls_pri_sec"></div>
                <input type="hidden" name="libro_e[]" id="libro_e">
              </div>

              <!-- Libros adicionales -->
              <?php for ($i = 1; $i < 100; $i++): ?>
              <div id="agg_l<?= $i ?>" class="d-none libro-block">
                <div class="libro-header">
                  <p class="libro-num"><i class="bi bi-bookmark-fill"></i> Libro #<?= $i + 1 ?></p>
                  <div style="display:flex;gap:6px;">
                    <button type="button" class="btn-save-book"><i class="bi bi-floppy"></i> Guardar</button>
                    <button type="button" class="btn-remove-book" data-idx="<?= $i ?>">
                      <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                  </div>
                </div>
                <div class="row">
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_materia<?= $i ?>" for="materia<?= $i ?>" class="control-label">Materia <small style="color:red;">*</small></label>
                    <select name="materia[]" id="materia<?= $i ?>" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php foreach ($materias as $m): ?>
                      <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['materia']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_libro<?= $i ?>" for="libro<?= $i ?>" class="control-label">Libro <small style="color:red;">*</small></label>
                    <select name="libro" id="libro<?= $i ?>" class="form-control custom-select2"></select>
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_descuento<?= $i ?>" for="descuento<?= $i ?>" class="control-label">Descuento % <small style="color:red;">*</small></label>
                    <input type="number" class="form-control" name="descuento" id="descuento<?= $i ?>">
                  </div>
                  <div class="form-group col-md-3 col-sm-6">
                    <label id="l_cantidad<?= $i ?>" for="cantidad<?= $i ?>" class="control-label">Cantidad <small style="color:red;">*</small></label>
                    <input type="number" class="form-control cantidad" name="cantidad" id="cantidad<?= $i ?>">
                  </div>
                </div>
                <div id="ls_pri_sec<?= $i ?>"></div>
                <input type="hidden" name="libro_e[]" id="libro_e<?= $i ?>">
              </div>
              <?php endfor; ?>

            </div>

            <div style="margin-top:16px;">
              <button type="button" id="agregar_libro" class="mc-btn-ghost">
                <i class="bi bi-plus-circle"></i> Agregar libro
              </button>
            </div>
          </div>
        </div>

        <!-- Fecha y observaciones -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-calendar2-week mr-2"></i> Fecha y observaciones</h5>
          </div>
          <div class="px-4 py-3">
            <div class="row">
              <div class="col-md-4 col-sm-6 col-12">
                <div class="form-group">
                  <label for="fecha_r" class="control-label">Fecha de recogida <small style="color:red;">*</small></label>
                  <div class="input-group">
                    <input type="text" class="form-control date-picker" name="fecha_r" id="fecha_r"
                           data-date-format="yyyy-mm-dd" required autocomplete="off" />
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                  </div>
                </div>
              </div>
              <div class="col-md-8 col-12">
                <div class="form-group">
                  <label for="observaciones" class="control-label">Observaciones</label>
                  <textarea name="observaciones" id="observaciones" rows="4" class="form-control"></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div style="margin-bottom:24px;">
          <button type="button" id="solicitar" class="mc-btn mc-btn-blue">
            <i class="bi bi-eye"></i> Vista previa
          </button>
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
  $('#materia').on('change',function(){
    var valor = $(this).val();
    var dataString = 'mat_gra='+valor;
    $.ajax({
      url: "ajax/buscar_l_eureka_sp.php", type: "POST", data: dataString, dataType: "html",
      success: function (resp) { $("#libro").html(resp); },
      error: function (jqXHR,estado,error){ alert("error"); console.log(estado); console.log(error); }
    });
  });

  $('#libro').on('change',function(){
    var cant  = $('#cantidad').val();
    var libro = $('#libro').val();
    var desc  = $('#descuento').val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado==15 || grado==16) {
      $('#l_cantidad').addClass("d-none"); $('#cantidad').addClass("d-none");
      $('#l_descuento').addClass("d-none"); $('#descuento').addClass("d-none");
      $.ajax({
        url: "ajax/buscar_pri_sec_desc.php", type: "POST", data: 'pri_sec='+libro, dataType: "html",
        success: function (resp) { $("#ls_pri_sec").html('').append(resp); },
        error: function (jqXHR,estado,error){ alert("error"); }
      });
    } else {
      $('#libro_e').val(libro+'/'+cant+'/'+desc);
    }
  });

  $('#cantidad').keyup(function(){
    var cant = $(this).val(); var libro=$('#libro').val(); var desc=$('#descuento').val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado!=15 || grado!=16) { $('#libro_e').val(libro+'/'+cant+'/'+desc); }
  });

  $('#descuento').keyup(function(){
    var cant=$('#cantidad').val(); var libro=$('#libro').val(); var desc=$(this).val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado!=15 || grado!=16) { $('#libro_e').val(libro+'/'+cant+'/'+desc); }
  });

  $('#solicitar').on('click', function () {
    var form = document.getElementById('miFormulario');
    if (form.checkValidity()) {
      form.submit();
    } else {
      form.reportValidity();
    }
  });

  var m = 1;

  $("#agregar_libro").click(function(){
    if (m > 98) { $(this).addClass("d-none"); }
    $("#agg_l"+m).removeClass("d-none");
    m++;

    <?php for ($i = 1; $i < 100; $i++): ?>
    $('#materia<?= $i ?>').on('change',function(){
      var valor = $(this).val();
      $.ajax({
        url: "ajax/buscar_l_eureka_sp.php", type: "POST", data: 'mat_gra='+valor, dataType: "html",
        success: function (resp) { $("#libro<?= $i ?>").html(resp); },
        error: function (jqXHR,estado,error){ alert("error"); }
      });
    });

    $('#libro<?= $i ?>').on('change',function(){
      var cant  = $('#cantidad<?= $i ?>').val();
      var libro = $('#libro<?= $i ?>').val();
      var desc  = $('#descuento<?= $i ?>').val();
      var grado = $('#libro<?= $i ?> option:selected').attr('data-grado');
      if (grado==15 || grado==16) {
        $('#l_cantidad<?= $i ?>').addClass("d-none"); $('#cantidad<?= $i ?>').addClass("d-none");
        $('#l_descuento<?= $i ?>').addClass("d-none"); $('#descuento<?= $i ?>').addClass("d-none");
        $.ajax({
          url: "ajax/buscar_pri_sec.php", type: "POST", data: 'pri_sec='+libro, dataType: "html",
          success: function (resp) { $("#ls_pri_sec<?= $i ?>").html('').append(resp); },
          error: function (jqXHR,estado,error){ alert("error"); }
        });
      } else {
        $('#libro_e<?= $i ?>').val(libro+'/'+cant+'/'+desc);
      }
    });

    $('#cantidad<?= $i ?>').keyup(function(){
      var cant=$('#cantidad<?= $i ?>').val(); var libro=$('#libro<?= $i ?>').val(); var desc=$('#descuento<?= $i ?>').val();
      var grado = $('#libro option:selected').attr('data-grado');
      if (grado!=15 || grado!=16) { $('#libro_e<?= $i ?>').val(libro+'/'+cant+'/'+desc); }
    });

    $('#descuento<?= $i ?>').keyup(function(){
      var cant=$('#cantidad<?= $i ?>').val(); var libro=$('#libro<?= $i ?>').val(); var desc=$('#descuento<?= $i ?>').val();
      var grado = $('#libro option:selected').attr('data-grado');
      if (grado!=15 || grado!=16) { $('#libro_e<?= $i ?>').val(libro+'/'+cant+'/'+desc); }
    });
    <?php endfor; ?>
  });

  $(document).on('click', '.btn-remove-book', function () {
    var idx = $(this).data('idx');
    $('#agg_l' + idx).addClass('d-none');
    $('#materia'   + idx).val('');
    $('#libro'     + idx).html('');
    $('#descuento' + idx).val('');
    $('#cantidad'  + idx).val('');
    $('#libro_e'   + idx).val('');
    $('#ls_pri_sec' + idx).html('');
    $('#l_cantidad' + idx).removeClass('d-none');
    $('#cantidad'   + idx).removeClass('d-none');
    $('#l_descuento' + idx).removeClass('d-none');
    $('#descuento'   + idx).removeClass('d-none');
  });

  $(document).on('click', '.btn-save-book', function () {
    $('#miFormulario').submit();
  });
</script>
</body>
</html>
