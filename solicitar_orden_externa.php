<?php
require_once("php/aut.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && !in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Solicitar Orden Externa</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    input[type=number] { -moz-appearance: textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .sec-label {
      font-size: .78rem; font-weight: 700; color: #64748b;
      text-transform: uppercase; letter-spacing: .05em;
      margin: 0 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;
    }
    .material-block {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 8px; padding: 16px 20px; margin-bottom: 12px;
    }
    .material-block .mat-title {
      font-size: .78rem; font-weight: 700; color: #7c3aed;
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px;
    }
    .add-material-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: #f1f5f9; color: #475569;
      border: 1.5px dashed #94a3b8; border-radius: 8px;
      padding: 8px 18px; font-size: .84rem; font-weight: 600;
      cursor: pointer; transition: background .15s; text-decoration: none;
    }
    .add-material-btn:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }
    .req { color: #dc2626; }
    .mat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .mat-header .mat-title { margin-bottom: 0; }
    .btn-remove-mat {
      display: inline-flex; align-items: center; gap: 4px;
      background: #fee2e2; color: #dc2626; border: none;
      border-radius: 6px; padding: 4px 10px; font-size: .76rem;
      font-weight: 600; cursor: pointer; transition: background .15s;
    }
    .btn-remove-mat:hover { background: #fca5a5; }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-sm-12">
            <div class="title">
              <h4><i class="bi bi-file-earmark-plus mr-2" style="color:#7c3aed"></i>Solicitar orden externa</h4>
            </div>
          </div>
        </div>
      </div>

      <div class="modern-card" style="padding: 24px 28px">
        <form action="php/orden_externa.php" method="POST" id="formul" enctype="multipart/form-data">

          <p class="sec-label">Datos generales</p>

          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="form-group">
                <label for="solicitante">Solicitante <span class="req">*</span></label>
                <select class="form-control" name="solicitante" id="solicitante" required>
                  <option value="">Seleccionar</option>
                  <option value="Wilmer Suarez">Wilmer Suarez</option>
                  <option value="Liliana Toledo">Liliana Toledo</option>
                  <option value="Jhon Rubiano">Jhon Rubiano</option>
                </select>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="form-group">
                <label for="empresa_solicitante">Empresa solicitante <span class="req">*</span></label>
                <select class="form-control" name="empresa_solicitante" id="empresa_solicitante" required>
                  <option value="">Seleccionar</option>
                  <option value="EEE">EEE</option>
                  <option value="Eureka">Eureka</option>
                </select>
              </div>
            </div>
            <div class="col-md-4 col-sm-6">
              <div class="form-group">
                <label for="proveedor">Proveedor <span class="req">*</span></label>
                <select class="form-control" name="proveedor" id="proveedor" style="width:100%" required>
                  <option value="">Seleccionar</option>
                  <option value="Disonex Zona Franca">Disonex Zona Franca</option>
                  <option value="Carvajal Soluciones de Comunicación">Carvajal Soluciones de Comunicación</option>
                  <option value="Multi-impresos">Multi-impresos</option>
                  <option value="Xpress Estudio Gráfico">Xpress Estudio Gráfico</option>
                  <option value="Panamericana Formas e Impresos">Panamericana Formas e Impresos</option>
                  <option value="DIDICOM">DIDICOM</option>
                  <option value="EEE Taller de producción">EEE Taller de producción</option>
                </select>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="form-group">
                <label for="descrip">Descripción pedido <span class="req">*</span></label>
                <select class="form-control" name="descrip" id="descrip" required>
                  <option value="">Seleccionar</option>
                  <option value="1">Libro estudiante</option>
                  <option value="2">Guía</option>
                  <option value="3">Otro</option>
                </select>
              </div>
            </div>
            <div class="col-md-4 col-sm-6 d-none" id="descrip_otro_wrap">
              <div class="form-group">
                <label for="descrip_otro">Especifique descripción <span class="req">*</span></label>
                <input type="text" class="form-control" name="descrip_otro" id="descrip_otro">
              </div>
            </div>
            <div class="col-md-4 col-sm-6">
              <div class="form-group">
                <label for="archivo">Archivo adjunto</label>
                <input type="file" name="archivo" id="archivo" class="form-control">
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="form-group">
                <label for="fecha_ent_s">Fecha de entrega solicitada <span class="req">*</span></label>
                <div class="input-group">
                  <input type="text" class="form-control date-picker" name="fecha_ent_s" id="fecha_ent_s" data-date-format="yyyy-mm-dd" required autocomplete="off">
                  <span class="input-group-addon"><i class="fa fa-calendar bigger-110"></i></span>
                </div>
              </div>
            </div>
          </div>

          <hr style="border-color:#e2e8f0; margin: 20px 0 24px">

          <p class="sec-label">Materiales</p>

          <div id="materiales-container">

            <div class="material-block" id="mat-0">
              <p class="mat-title">Material #1</p>
              <div class="row">
                <div class="col-md-5">
                  <div class="form-group">
                    <label for="titulo">Título <span class="req">*</span></label>
                    <select class="form-control titulo-select" name="titulo" id="titulo" style="width:100%"></select>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-group">
                    <label for="cantidad">Cantidad <span class="req">*</span></label>
                    <input type="number" class="form-control" name="cantidad" id="cantidad">
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-group">
                    <label for="costo">Costo unitario <span class="req">*</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" name="costo" id="costo">
                  </div>
                </div>
              </div>
              <input type="hidden" name="material_e[]" id="material_e">
            </div>

            <?php for ($i = 1; $i < 100; $i++): ?>
            <div id="agg_l<?= $i ?>" class="material-block d-none">
              <div class="mat-header">
                <p class="mat-title">Material #<?= $i + 1 ?></p>
                <button type="button" class="btn-remove-mat" data-idx="<?= $i ?>">
                  <i class="bi bi-x-circle"></i> Eliminar
                </button>
              </div>
              <div class="row">
                <div class="col-md-5">
                  <div class="form-group">
                    <label for="titulo<?= $i ?>">Título <span class="req">*</span></label>
                    <select class="form-control titulo-select" name="titulo" id="titulo<?= $i ?>" style="width:100%"></select>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-group">
                    <label for="cantidad<?= $i ?>">Cantidad <span class="req">*</span></label>
                    <input type="number" class="form-control" name="cantidad" id="cantidad<?= $i ?>">
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-group">
                    <label for="costo<?= $i ?>">Costo unitario <span class="req">*</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" name="costo" id="costo<?= $i ?>">
                  </div>
                </div>
              </div>
              <input type="hidden" name="material_e[]" id="material_e<?= $i ?>">
            </div>
            <?php endfor; ?>

          </div>

          <a id="agregar_material" class="add-material-btn mb-4 d-inline-flex">
            <i class="bi bi-plus-circle"></i> Agregar material
          </a>

          <hr style="border-color:#e2e8f0; margin: 24px 0 20px">

          <div class="row">
            <div class="col-md-8">
              <div class="form-group">
                <label for="observaciones">Observaciones</label>
                <textarea name="observaciones" id="observaciones" class="form-control" rows="5"></textarea>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send mr-1"></i> Solicitar
          </button>

        </form>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
  $('#proveedor').select2({
    placeholder: 'Seleccionar proveedor',
    allowClear: true,
    width: '100%',
    language: { noResults: function () { return 'Sin resultados'; } }
  });

  $('#descrip').on('change', function () {
    var esOtro = $(this).val() === '3';
    $('#descrip_otro_wrap').toggleClass('d-none', !esOtro);
    $('#descrip_otro').prop('required', esOtro);
    if (!esOtro) $('#descrip_otro').val('');
  });

  function initTituloSelect($el) {
    $el.select2({
      placeholder: 'Escriba para buscar un libro...',
      allowClear: true,
      width: '100%',
      minimumInputLength: 2,
      tags: true,
      language: {
        noResults: function () { return 'Sin resultados'; },
        searching: function () { return 'Buscando...'; },
        inputTooShort: function () { return 'Escriba al menos 2 letras para buscar'; }
      },
      ajax: {
        url: 'php/buscar_libros_oe.php',
        dataType: 'json',
        delay: 300,
        data: function (params) { return { q: params.term }; },
        processResults: function (data) { return { results: data }; },
        cache: true
      }
    });
  }

  initTituloSelect($('#titulo'));
</script>
<script>
  function syncMaterial(idx) {
    var suffix = idx === 0 ? '' : idx;
    var cant   = $('#cantidad' + suffix).val();
    var titulo = $('#titulo'   + suffix).val();
    var costo  = $('#costo'    + suffix).val();
    $('#material_e' + suffix).val(titulo + '/' + cant + '/' + costo);
  }

  $('#cantidad, #costo').on('keyup', function () { syncMaterial(0); });
  $('#titulo').on('change', function () { syncMaterial(0); });

  var m = 1;

  $('#agregar_material').on('click', function () {
    if (m >= 99) { $(this).addClass('d-none'); return; }
    $('#agg_l' + m).removeClass('d-none');
    (function (idx) {
      initTituloSelect($('#titulo' + idx));
      $('#cantidad' + idx + ', #costo' + idx).on('keyup', function () { syncMaterial(idx); });
      $('#titulo' + idx).on('change', function () { syncMaterial(idx); });
    })(m);
    m++;
  });

  $(document).on('click', '.btn-remove-mat', function () {
    var idx = $(this).data('idx');
    $('#agg_l' + idx).addClass('d-none');
    $('#titulo'      + idx).val(null).trigger('change');
    $('#cantidad'    + idx).val('');
    $('#costo'       + idx).val('');
    $('#material_e'  + idx).val('');
  });

  const maxSize = 6000000;
  document.querySelector('#archivo').addEventListener('change', function () {
    if (!this.files.length) return;
    if (this.files[0].size > maxSize) {
      alert('El tamaño máximo es ' + (maxSize / 1000000) + ' MB');
      this.value = '';
    }
  });
</script>
</body>
</html>
