<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1) {
    header("Location: index.php");
    exit;
}

$puede_gestionar = ($_SESSION["tipo"] == 1);

$total_libros = $bdd->query("SELECT COUNT(*) FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id")->fetchColumn();
$total_activos = $bdd->query("SELECT COUNT(*) FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id WHERE l.presupuesto=1")->fetchColumn();

$sql_materias = "SELECT id, materia FROM materias ORDER BY materia";
$req_materias = $bdd->prepare($sql_materias);
$req_materias->execute();
$materias = $req_materias->fetchAll();

$sql_grados = "SELECT id, grado FROM grados ORDER BY id";
$req_grados = $bdd->prepare($sql_grados);
$req_grados->execute();
$grados = $req_grados->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Libros</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    #libros-table input[type="text"],
    #libros-table input[type="number"],
    #libros-table select {
      width: 100%;
      min-width: 90px;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      padding: 4px 8px;
      font-size: .8rem;
    }
    @media (max-width: 575px) {
      #libros-table_wrapper { overflow-x: auto; }
      #libros-table { min-width: 1050px; }
    }

    /* Botón de guardar (acciones) */
    .btn-save-libro {
      width: 34px; height: 34px; padding: 0; border: none; border-radius: 10px;
      display: inline-flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      color: #fff; font-size: .95rem; cursor: pointer;
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-save-libro:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-save-libro:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(67,56,202,.3); }
    .btn-save-libro:focus { outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,.25); }

    /* Botón de eliminar (acciones) */
    .acciones-libro { display: flex; align-items: center; gap: 6px; }
    .btn-delete-libro {
      width: 34px; height: 34px; padding: 0; border: none; border-radius: 10px;
      display: inline-flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg,#ef4444,#dc2626);
      color: #fff; font-size: .95rem; cursor: pointer;
      box-shadow: 0 3px 8px rgba(220,38,38,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-delete-libro:hover {
      background: linear-gradient(135deg,#dc2626,#b91c1c);
      box-shadow: 0 5px 14px rgba(220,38,38,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-delete-libro:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(220,38,38,.3); }
    .btn-delete-libro:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }

    /* Badges de asociación (serie padre/hijo) */
    .badge-serie {
      display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; font-weight: 600;
      padding: 4px 8px; border-radius: 6px; white-space: nowrap;
    }
    .badge-serie-padre { background: #e0e7ff; color: #3730a3; }
    .badge-serie-hijo { background: #dcfce7; color: #15803d; max-width: 140px; overflow: hidden; text-overflow: ellipsis; }
    .badge-serie-libre { background: #f1f5f9; color: #64748b; }
    .btn-asociar-serie {
      margin-left: 4px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px;
      font-size: .7rem; padding: 3px 7px; cursor: pointer; color: #4338ca;
    }
    .btn-asociar-serie:hover { background: #eef2ff; border-color: #a5b4fc; }

    /* Modal: Asociar a serie */
    #ModalAsociarSerie .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalAsociarSerie .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalAsociarSerie .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalAsociarSerie .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalAsociarSerie .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalAsociarSerie .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalAsociarSerie .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalAsociarSerie .ml-body { padding: 22px 24px 4px; }
    #ModalAsociarSerie .ml-field { margin-bottom: 18px; }
    #ModalAsociarSerie .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalAsociarSerie .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalAsociarSerie .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalAsociarSerie .ml-btn-submit {
      padding: 9px 22px; border-radius: 8px; border: none; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); box-shadow: 0 4px 12px rgba(79,70,229,.3);
    }
    #select-libro-padre + .select2-container { width: 100% !important; }

    /* Modal: Crear libro */
    #ModalCrearLibro .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearLibro .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearLibro .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearLibro .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearLibro .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearLibro .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearLibro .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearLibro .ml-body { padding: 22px 24px 4px; }
    #ModalCrearLibro .ml-row { display: flex; gap: 14px; }
    #ModalCrearLibro .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearLibro .ml-field { margin-bottom: 18px; }
    #ModalCrearLibro .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearLibro .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearLibro .ml-input, #ModalCrearLibro .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearLibro .ml-input:focus, #ModalCrearLibro .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearLibro .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearLibro .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearLibro .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearLibro .ml-btn-submit {
      padding: 9px 22px; border-radius: 8px; border: none; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); box-shadow: 0 4px 12px rgba(79,70,229,.3);
    }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <!-- Encabezado -->
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Libros</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Libros</li>
              </ol>
            </nav>
          </div>
          <?php if ($puede_gestionar): ?>
          <div class="col-md-6 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#ModalCrearLibro">
              <i class="bi bi-plus-circle mr-1"></i> Crear libro
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-book"></i></div>
            <div class="stat-info-modern">
              <h3 id="lb-stat-total"><?= number_format($total_libros, 0, ',', '.') ?></h3>
              <p class="stat-label">Total libros</p>
              <span class="stat-sub">En el catálogo</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sgreen"><i class="bi bi-check-circle"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_activos, 0, ',', '.') ?></h3>
              <p class="stat-label">Activos en presupuesto</p>
              <span class="stat-sub">Disponibles para presupuestar</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="libros-search" placeholder="Buscar por ISBN, título, materia o grado (mín. 4 caracteres)...">
        </div>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de libros</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="libros-table">
            <thead>
              <tr>
                <th>ISBN</th>
                <th>Título</th>
                <th>Materia</th>
                <th>Grado</th>
                <th>Precio $</th>
                <th>Activo</th>
                <th>Asociación</th>
                <th>Acciones</th>
              </tr>
            </thead>
          </table>
        </div>
      </div>

      <!-- Modal: Crear libro -->
      <?php if ($puede_gestionar): ?>
      <div class="modal fade" id="ModalCrearLibro" tabindex="-1" role="dialog" aria-labelledby="ModalCrearLibroLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_libro.php">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-journal-plus"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearLibroLabel">Crear libro</h5>
                  <p class="ml-subtitle">Agrega un nuevo título al catálogo</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="isbn">ISBN<span class="req">*</span></label>
                    <input type="text" name="isbn" id="isbn" class="ml-input" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="precio">Precio $</label>
                    <input type="number" name="precio" id="precio" class="ml-input" step="any">
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="libro">Título<span class="req">*</span></label>
                  <input type="text" name="libro" id="libro" class="ml-input" required>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="materia">Materia<span class="req">*</span></label>
                    <select name="materia" id="materia" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($materias as $materia): ?>
                        <option value="<?= $materia["id"] ?>"><?= htmlspecialchars($materia["materia"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="grado">Grado<span class="req">*</span></label>
                    <select name="grado" id="grado" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado["id"] ?>"><?= htmlspecialchars($grado["grado"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="presupuesto">¿Activo en presupuesto?<span class="req">*</span></label>
                  <select name="presupuesto" id="presupuesto" class="ml-select" required>
                    <option value="">Seleccione</option>
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                  </select>
                </div>

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear libro</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Modal: Asociar a serie -->
      <?php if ($puede_gestionar): ?>
      <div class="modal fade" id="ModalAsociarSerie" tabindex="-1" role="dialog" aria-labelledby="ModalAsociarSerieLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form id="form-asociar-serie">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-diagram-3"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalAsociarSerieLabel">Asociar a una serie</h5>
                  <p class="ml-subtitle">Libro: <strong id="asociar-libro-nombre"></strong></p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">
                <div class="ml-field">
                  <label class="ml-label" for="select-libro-padre">Libro padre</label>
                  <select id="select-libro-padre" class="ml-select" style="width:100%"></select>
                  <small class="text-muted">Deja el campo vacío y guarda para quitar la asociación.</small>
                </div>
              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Guardar</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
  $.fn.dataTable.ext.errMode = 'none';

  var table = $('#libros-table').DataTable({
    processing: true,
    serverSide: true,
    responsive: { details: false },
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    ajax: {
      url: "php/libros_tabla.php"
    },
    columns: [
      { data: "isbn", width: "10%" },
      { data: "libro", width: "23%" },
      { data: "materia", width: "14%" },
      { data: "grado", width: "11%" },
      { data: "precio", width: "8%" },
      { data: "presupuesto", width: "7%" },
      { data: "asociacion", orderable: false, width: "17%" },
      { data: "acciones", orderable: false, width: "10%" },
    ],
    language: {
      lengthMenu:   "Mostrar _MENU_ registros",
      zeroRecords:  "No se encontraron resultados",
      emptyTable:   "No hay información para mostrar",
      info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty:    "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      search:       "Buscar:",
      processing:   '<div class="dt-loading"><i class="bi bi-arrow-repeat"></i> Cargando...</div>',
      paginate: { first: "«", previous: "‹", next: "›", last: "»" }
    },
    initComplete: function () {
      $('#lb-stat-total').text(this.api().page.info().recordsTotal.toLocaleString('es-CO'));
    }
  });

  $('#libros-search').on('keyup', function () {
    var val = this.value;
    if (val.length >= 4 || val.length === 0) {
      table.search(val).draw();
    }
  });

  $('#libros-table').on('click', '.btn-save-libro', function () {
    var $btn = $(this);
    var $tr = $btn.closest('tr');
    var payload = {
      id_libro:    $btn.data('id'),
      isbn:        $tr.find('.dt-isbn').val(),
      libro:       $tr.find('.dt-libro').val(),
      materia:     $tr.find('.dt-materia').val(),
      grado:       $tr.find('.dt-grado').val(),
      precio:      $tr.find('.dt-precio').val() || 0,
      presupuesto: $tr.find('.dt-presupuesto').val(),
    };
    $.post('php/modificar_libro.php', payload, function (resp) {
      inkToast(resp.message, resp.success ? 'ok' : 'error');
      if (resp.success) table.ajax.reload(null, false);
    }, 'json');
  });

  $('#libros-table').on('click', '.btn-delete-libro', function () {
    var id = $(this).data('id');
    var titulo = $(this).data('titulo');
    inkConfirm({
      title: '¿Eliminar este libro?',
      text: 'Se eliminará "' + titulo + '" del catálogo. Esta acción no se puede deshacer.',
      btnOk: 'Eliminar'
    }, function () {
      $.post('php/eliminar_libro.php', { id_libro: id }, function (resp) {
        inkToast(resp.message, resp.success ? 'ok' : 'error');
        if (resp.success) table.ajax.reload(null, false);
      }, 'json');
    });
  });

  var currentLibroId = null;
  var currentMateriaId = null;

  $('#select-libro-padre').select2({
    dropdownParent: $('#ModalAsociarSerie'),
    placeholder: 'Buscar libro de esa materia (Primaria/Secundaria)...',
    minimumInputLength: 0,
    allowClear: true,
    ajax: {
      url: 'php/buscar_libros_padre.php',
      dataType: 'json',
      delay: 300,
      data: function (params) {
        return { q: params.term || '', excluir: currentLibroId, materia: currentMateriaId };
      },
      processResults: function (data) {
        return { results: data.results };
      }
    }
  });

  $('#libros-table').on('click', '.btn-asociar-serie', function () {
    var $btn = $(this);
    currentLibroId = $btn.data('id');
    currentMateriaId = $btn.data('materia');
    $('#asociar-libro-nombre').text($btn.data('libro'));

    $('#select-libro-padre').empty().val(null).trigger('change');
    var padreId = $btn.data('padre-id');
    if (padreId) {
      var opt = new Option($btn.data('padre-nombre'), padreId, true, true);
      $('#select-libro-padre').append(opt).trigger('change');
    }
    $('#ModalAsociarSerie').modal('show');
  });

  $('#form-asociar-serie').on('submit', function (e) {
    e.preventDefault();
    var idPadre = $('#select-libro-padre').val() || 0;
    $.post('php/asociar_libro_serie.php', { id_libro: currentLibroId, id_padre: idPadre }, function (resp) {
      inkToast(resp.message, resp.success ? 'ok' : 'error');
      if (resp.success) {
        $('#ModalAsociarSerie').modal('hide');
        table.ajax.reload(null, false);
      }
    }, 'json');
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
