<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1) {
    header("Location: index.php");
    exit;
}

$total_periodos = $bdd->query("SELECT COUNT(*) FROM periodos")->fetchColumn();
$total_cal_a = $bdd->query("SELECT COUNT(*) FROM periodos p JOIN calendarios c ON p.id_calendario = c.id WHERE c.calendario = 'A'")->fetchColumn();
$total_cal_b = $bdd->query("SELECT COUNT(*) FROM periodos p JOIN calendarios c ON p.id_calendario = c.id WHERE c.calendario = 'B'")->fetchColumn();

$sql_calendarios = "SELECT id, calendario FROM calendarios WHERE act=1 ORDER BY id";
$req_calendarios = $bdd->prepare($sql_calendarios);
$req_calendarios->execute();
$calendarios = $req_calendarios->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Períodos</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    @media (max-width: 575px) {
      #periodos-table_wrapper { overflow-x: auto; }
      #periodos-table { min-width: 850px; }
    }

    .badge-calendario { display: inline-flex; align-items: center; font-size: .72rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; }
    .badge-calendario-a { background: #eef0ff; color: #4361ee; }
    .badge-calendario-b { background: #fff4ec; color: #f77f00; }

    /* Columna de acciones (mismo estilo que libros.php/zonas.php) */
    .acciones-periodo { display: inline-flex; align-items: center; gap: 6px; }
    .btn-editar-periodo {
      width: 32px; height: 32px; padding: 0; border: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: .88rem; cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
    }
    .btn-editar-periodo:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-editar-periodo:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(67,56,202,.3); }
    .btn-editar-periodo:focus { outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,.25); }

    .btn-eliminar-periodo {
      width: 32px; height: 32px; padding: 0; border: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: .88rem; cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
      background: linear-gradient(135deg,#ef4444,#dc2626);
      box-shadow: 0 3px 8px rgba(220,38,38,.28);
    }
    .btn-eliminar-periodo:hover {
      background: linear-gradient(135deg,#dc2626,#b91c1c);
      box-shadow: 0 5px 14px rgba(220,38,38,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-eliminar-periodo:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(220,38,38,.3); }
    .btn-eliminar-periodo:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }

    /* Modal: Crear periodo / Editar periodo */
    #ModalCrearPeriodo .modal-content, #ModalEditarPeriodo .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearPeriodo .ml-header, #ModalEditarPeriodo .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearPeriodo .ml-icon-badge, #ModalEditarPeriodo .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearPeriodo .ml-icon-badge i, #ModalEditarPeriodo .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearPeriodo .ml-title, #ModalEditarPeriodo .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearPeriodo .ml-subtitle, #ModalEditarPeriodo .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearPeriodo .close, #ModalEditarPeriodo .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearPeriodo .ml-body, #ModalEditarPeriodo .ml-body { padding: 22px 24px 4px; }
    #ModalCrearPeriodo .ml-row, #ModalEditarPeriodo .ml-row { display: flex; gap: 14px; }
    #ModalCrearPeriodo .ml-row > .ml-field, #ModalEditarPeriodo .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearPeriodo .ml-field, #ModalEditarPeriodo .ml-field { margin-bottom: 18px; }
    #ModalCrearPeriodo .ml-label, #ModalEditarPeriodo .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearPeriodo .ml-label .req, #ModalEditarPeriodo .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearPeriodo .ml-input, #ModalCrearPeriodo .ml-select,
    #ModalEditarPeriodo .ml-input, #ModalEditarPeriodo .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearPeriodo .ml-input:focus, #ModalCrearPeriodo .ml-select:focus,
    #ModalEditarPeriodo .ml-input:focus, #ModalEditarPeriodo .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearPeriodo .ml-input:disabled { background: #f1f5f9; color: #64748b; }
    #ModalCrearPeriodo .ml-select, #ModalEditarPeriodo .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearPeriodo .ml-hint, #ModalEditarPeriodo .ml-hint { font-size: .74rem; color: #94a3b8; margin-top: 6px; }
    #ModalCrearPeriodo .ml-footer, #ModalEditarPeriodo .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearPeriodo .ml-btn-cancel, #ModalEditarPeriodo .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearPeriodo .ml-btn-submit, #ModalEditarPeriodo .ml-btn-submit {
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
            <div class="title"><h4>Períodos</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Períodos</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-6 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#ModalCrearPeriodo">
              <i class="bi bi-calendar-plus mr-1"></i> Crear período
            </button>
          </div>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-calendar-range"></i></div>
            <div class="stat-info-modern">
              <h3 id="pe-stat-total"><?= number_format($total_periodos, 0, ',', '.') ?></h3>
              <p class="stat-label">Total períodos</p>
              <span class="stat-sub">Registrados en el sistema</span>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_cal_a, 0, ',', '.') ?></h3>
              <p class="stat-label">Calendario A</p>
              <span class="stat-sub">Períodos registrados</span>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sorange"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_cal_b, 0, ',', '.') ?></h3>
              <p class="stat-label">Calendario B</p>
              <span class="stat-sub">Períodos registrados</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="periodos-search" placeholder="Buscar por período o calendario...">
        </div>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de períodos</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="periodos-table">
            <thead>
              <tr>
                <th>Período</th>
                <th>Calendario</th>
                <th>Fecha de cierre</th>
                <th>Tasa preescolar</th>
                <th>Tasa primaria</th>
                <th>Tasa 6°-9°</th>
                <th>Tasa 10°-11°</th>
                <th>Acciones</th>
              </tr>
            </thead>
          </table>
        </div>
      </div>

      <!-- Modal: Crear periodo -->
      <div class="modal fade" id="ModalCrearPeriodo" tabindex="-1" role="dialog" aria-labelledby="ModalCrearPeriodoLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_periodo.php" id="form-crear-periodo">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-calendar-plus"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearPeriodoLabel">Crear período</h5>
                  <p class="ml-subtitle">Registra un nuevo período académico</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="crear_calendario">Calendario<span class="req">*</span></label>
                    <select name="id_calendario" id="crear_calendario" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($calendarios as $cal): ?>
                        <option value="<?= $cal["id"] ?>"><?= htmlspecialchars($cal["calendario"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="crear_periodo_preview">Período</label>
                    <input type="text" id="crear_periodo_preview" class="ml-input" placeholder="Seleccione un calendario" disabled>
                    <p class="ml-hint">Se calcula automáticamente a partir del último período registrado.</p>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="crear_f_cierre">Fecha de cierre<span class="req">*</span></label>
                  <input type="date" name="f_cierre" id="crear_f_cierre" class="ml-input" required>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="crear_t_preescolar">Tasa preescolar %<span class="req">*</span></label>
                    <input type="number" name="t_preescolar" id="crear_t_preescolar" class="ml-input" step="any" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="crear_t_primaria">Tasa primaria %<span class="req">*</span></label>
                    <input type="number" name="t_primaria" id="crear_t_primaria" class="ml-input" step="any" required>
                  </div>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="crear_t_6_9">Tasa 6°-9° %<span class="req">*</span></label>
                    <input type="number" name="t_6_9" id="crear_t_6_9" class="ml-input" step="any" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="crear_t_10_11">Tasa 10°-11° %<span class="req">*</span></label>
                    <input type="number" name="t_10_11" id="crear_t_10_11" class="ml-input" step="any" required>
                  </div>
                </div>

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear período</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal: Editar periodo -->
      <div class="modal fade" id="ModalEditarPeriodo" tabindex="-1" role="dialog" aria-labelledby="ModalEditarPeriodoLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form id="form-editar-periodo">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-pencil-square"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalEditarPeriodoLabel">Editar período</h5>
                  <p class="ml-subtitle">Actualiza los datos del período</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_calendario">Calendario<span class="req">*</span></label>
                    <select name="id_calendario" id="editar_calendario" class="ml-select" required>
                      <?php foreach ($calendarios as $cal): ?>
                        <option value="<?= $cal["id"] ?>"><?= htmlspecialchars($cal["calendario"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="editar_periodo">Período<span class="req">*</span></label>
                    <input type="text" name="periodo" id="editar_periodo" class="ml-input" required>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="editar_f_cierre">Fecha de cierre<span class="req">*</span></label>
                  <input type="date" name="f_cierre" id="editar_f_cierre" class="ml-input" required>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_t_preescolar">Tasa preescolar %<span class="req">*</span></label>
                    <input type="number" name="t_preescolar" id="editar_t_preescolar" class="ml-input" step="any" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="editar_t_primaria">Tasa primaria %<span class="req">*</span></label>
                    <input type="number" name="t_primaria" id="editar_t_primaria" class="ml-input" step="any" required>
                  </div>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_t_6_9">Tasa 6°-9° %<span class="req">*</span></label>
                    <input type="number" name="t_6_9" id="editar_t_6_9" class="ml-input" step="any" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="editar_t_10_11">Tasa 10°-11° %<span class="req">*</span></label>
                    <input type="number" name="t_10_11" id="editar_t_10_11" class="ml-input" step="any" required>
                  </div>
                </div>

                <input type="hidden" name="id" id="editar_id" value="">

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Guardar cambios</button>
              </div>
            </form>
          </div>
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
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script>
  var CALENDARIOS_MAP = <?= json_encode(array_column($calendarios, 'calendario', 'id')) ?>;

$(document).ready(function () {
  $.fn.dataTable.ext.errMode = 'none';

  var table = $('#periodos-table').DataTable({
    processing: true,
    serverSide: true,
    responsive: { details: false },
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    ajax: {
      url: "php/periodos_tabla.php"
    },
    order: [],
    columns: [
      { data: "periodo", width: "12%" },
      { data: "calendario", width: "12%" },
      { data: "f_cierre", width: "14%" },
      { data: "t_preescolar", width: "13%" },
      { data: "t_primaria", width: "13%" },
      { data: "t_6_9", width: "12%" },
      { data: "t_10_11", width: "13%" },
      { data: "acciones", orderable: false, width: "11%" },
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
      $('#pe-stat-total').text(this.api().page.info().recordsTotal.toLocaleString('es-CO'));
    }
  });

  $('#periodos-search').on('keyup', function () {
    table.search(this.value).draw();
  });

  // ── Cálculo automático del siguiente período al elegir el calendario ──
  $('#crear_calendario').on('change', function () {
    var idCalendario = $(this).val();
    var $preview = $('#crear_periodo_preview');

    if (!idCalendario) {
      $preview.val('').attr('placeholder', 'Seleccione un calendario');
      return;
    }

    $preview.val('').attr('placeholder', 'Calculando...');
    $.getJSON('php/siguiente_periodo.php', { id_calendario: idCalendario }, function (resp) {
      if (!resp.success) return;
      $preview.val(resp.periodo);
      $('#crear_t_preescolar').val(resp.t_preescolar || '');
      $('#crear_t_primaria').val(resp.t_primaria || '');
      $('#crear_t_6_9').val(resp.t_6_9 || '');
      $('#crear_t_10_11').val(resp.t_10_11 || '');
    });
  });

  $('#ModalCrearPeriodo').on('hidden.bs.modal', function () {
    $('#form-crear-periodo')[0].reset();
    $('#crear_periodo_preview').attr('placeholder', 'Seleccione un calendario');
  });

  $('#periodos-table').on('click', '.btn-editar-periodo', function () {
    var $btn = $(this);
    $('#editar_id').val($btn.data('id'));
    $('#editar_calendario').val($btn.data('calendario-id'));
    $('#editar_periodo').val($btn.data('periodo'));
    $('#editar_f_cierre').val($btn.data('f-cierre'));
    $('#editar_t_preescolar').val($btn.data('t-preescolar'));
    $('#editar_t_primaria').val($btn.data('t-primaria'));
    $('#editar_t_6_9').val($btn.data('t-6-9'));
    $('#editar_t_10_11').val($btn.data('t-10-11'));
    $('#ModalEditarPeriodo').modal('show');
  });

  // ── Al cambiar el calendario en edición, ajusta el sufijo del período (ej: 2027 -> 2027B) ──
  $('#editar_calendario').on('change', function () {
    var nuevoCalendario = CALENDARIOS_MAP[$(this).val()] || '';
    var anio = String($('#editar_periodo').val() || '').replace(/[^0-9]/g, '');
    if (!anio) return;
    var sufijo = (nuevoCalendario === 'B') ? 'B' : '';
    $('#editar_periodo').val(anio + sufijo);
  });

  $('#form-editar-periodo').on('submit', function (e) {
    e.preventDefault();
    var payload = {
      id:           $('#editar_id').val(),
      id_calendario:$('#editar_calendario').val(),
      periodo:      $('#editar_periodo').val(),
      f_cierre:     $('#editar_f_cierre').val(),
      t_preescolar: $('#editar_t_preescolar').val(),
      t_primaria:   $('#editar_t_primaria').val(),
      t_6_9:        $('#editar_t_6_9').val(),
      t_10_11:      $('#editar_t_10_11').val(),
    };
    $.post('php/modificar_periodo.php', payload, function (resp) {
      inkToast(resp.message, resp.success ? 'ok' : 'error');
      if (resp.success) {
        $('#ModalEditarPeriodo').modal('hide');
        table.ajax.reload(null, false);
      }
    }, 'json');
  });

  $('#periodos-table').on('click', '.btn-eliminar-periodo', function () {
    var id = $(this).data('id');
    var periodo = $(this).data('periodo');
    inkConfirm({
      title: '¿Eliminar este período?',
      text: 'Se eliminará el período "' + periodo + '". Si ya tiene información asociada (presupuestos, pedidos, adopciones, etc.) no podrá eliminarse.',
      btnOk: 'Eliminar'
    }, function () {
      $.post('php/eliminar_periodo.php', { id: id }, function (resp) {
        inkToast(resp.message, resp.success ? 'ok' : 'error');
        if (resp.success) table.ajax.reload(null, false);
      }, 'json');
    });
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
