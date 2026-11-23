<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
$tp = isset($_GET['tp']) ? (int)$_GET['tp'] : 1;
$tpConfig = [
    1 => ['label' => 'Todas las OP',  'icon' => 'bi-list-ul',         'hbg' => '#f5f3ff', 'hborder' => '#7c3aed', 'hcolor' => '#6d28d9'],
    2 => ['label' => 'OP pendientes', 'icon' => 'bi-hourglass-split',  'hbg' => '#fefce8', 'hborder' => '#ca8a04', 'hcolor' => '#854d0e'],
    3 => ['label' => 'OP atendidas',  'icon' => 'bi-check-circle',     'hbg' => '#f0fdf4', 'hborder' => '#16a34a', 'hcolor' => '#166534'],
    4 => ['label' => 'OP anuladas',   'icon' => 'bi-x-circle',         'hbg' => '#fff1f2', 'hborder' => '#dc2626', 'hcolor' => '#991b1b'],
];
$cfg = $tpConfig[$tp] ?? $tpConfig[1];
// vd=1: OP de venta directa (numeración propia OPVD-n); ver includes/venta_directa_datos.php.
$vd = !empty($_GET['vd']);
if ($vd) $cfg['label'] = str_replace('OP', 'OP de venta directa', $cfg['label']) . ' (OPVD)';
$req_estados = $bdd->query("SELECT id, estado FROM estados_op ORDER BY id");
$estados_list = $req_estados->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - <?= htmlspecialchars($cfg['label']) ?></title>
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
    .op-badge {
      display: inline-flex; align-items: center;
      padding: 3px 10px; border-radius: 20px;
      font-size: .76rem; font-weight: 600; white-space: nowrap;
    }
    .op-badge-yellow { background: #fef9c3; color: #854d0e; }
    .op-badge-green  { background: #dcfce7; color: #166534; }
    .op-badge-red    { background: #fee2e2; color: #991b1b; }
    .op-badge-gray   { background: #f1f5f9; color: #64748b; }

    .ft-date-range { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .ft-date-label { font-size: .78rem; font-weight: 600; color: #64748b; white-space: nowrap; }
    @media (max-width: 575px) {
      #lo-table_wrapper { overflow-x: auto; }
      #lo-table { min-width: <?= $tp == 4 ? '900px' : '700px' ?>; }
      #lo-table td, #lo-table th { display: table-cell !important; }
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
            <div class="title"><h4><?= htmlspecialchars($cfg['label']) ?></h4></div>
          </div>
        </div>
      </div>

      <!-- Tarjeta de estadística -->
      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-file-earmark-text"></i></div>
            <div class="stat-info-modern">
              <h3 id="lo-stat-total">—</h3>
              <p class="stat-label">Total OP</p>
              <span class="stat-sub"><?= htmlspecialchars($cfg['label']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="lo-search" placeholder="Buscar por OP o cliente...">
        </div>
        <?php if ($tp == 1): ?>
        <select class="ft-select" id="lo-filter-estado">
          <option value="">Todos los estados</option>
          <?php foreach ($estados_list as $est): ?>
          <option value="<?= $est['id'] ?>"><?= htmlspecialchars($est['estado']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="ft-search" style="max-width:190px">
          <i class="bi bi-person ft-search-icon"></i>
          <input type="text" id="lo-filter-cliente" placeholder="Filtrar por cliente...">
        </div>
        <div class="ft-date-range">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="lo-filter-desde">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="lo-filter-hasta">
        </div>
        <button class="ft-btn ft-apply" id="lo-btn-apply">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
        <button class="ft-btn ft-clear" id="lo-btn-clear">
          <i class="bi bi-x-circle"></i> Limpiar
        </button>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="lo-table">
            <thead>
              <tr>
                <th>OP #</th>
                <th>Usuario</th>
                <th>Documento</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Estado</th>
                <?php if ($tp == 4): ?>
                  <th>Fecha anulada</th>
                  <th>Usuario anulación</th>
                <?php endif; ?>
                <th></th>
              </tr>
            </thead>
          </table>
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
$(document).ready(function () {
  $.fn.dataTable.ext.errMode = 'none';

  var table = $('#lo-table').dataTable({
    processing: true,
    serverSide: true,
    responsive: { details: false },
    autoWidth:  false,
    dom:        '<"top"l>rt<"bottom"ip>',
    ajax: {
      url: 'ajax/ops.php?tp=<?= $tp ?><?= $vd ? '&vd=1' : '' ?>',
      data: function (d) {
        d.filter_estado  = $('#lo-filter-estado').val();
        d.filter_cliente = $('#lo-filter-cliente').val();
        d.filter_desde   = $('#lo-filter-desde').val();
        d.filter_hasta   = $('#lo-filter-hasta').val();
      }
    },
    columns: [
      { data: 'id',        responsivePriority: 1, className: 'all' },
      { data: 'usuario',   responsivePriority: 4 },
      { data: 'documento', responsivePriority: 5 },
      { data: 'fecha',     responsivePriority: 3 },
      { data: 'cliente',   responsivePriority: 2, className: 'all' },
      { data: 'estado',    responsivePriority: 3 },
      <?php if ($tp == 4): ?>
      { data: 'fecha_anu',   responsivePriority: 6 },
      { data: 'usuario_anu', responsivePriority: 7 },
      <?php endif; ?>
      { data: 'acciones', orderable: false, responsivePriority: 1, className: 'all' },
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
      paginate:     { first: "«", previous: "‹", next: "›", last: "»" }
    },
    initComplete: function () {
      var api = this.api();
      $('#lo-search').on('keyup', function () {
        var val = this.value;
        if (val.length >= 2 || val.length === 0) {
          api.search(val).draw();
        }
      });
      $('#lo-stat-total').text(api.page.info().recordsTotal.toLocaleString('es-CO'));
    }
  });

  table.api().on('draw', function () {
    $('#lo-stat-total').text(table.api().page.info().recordsTotal.toLocaleString('es-CO'));
  });

  $('#lo-filter-estado').on('change', function () {
    table.api().draw();
  });

  $('#lo-filter-cliente').on('keyup', function () {
    var val = this.value;
    if (val.length >= 2 || val.length === 0) table.api().draw();
  });

  $('#lo-btn-apply').on('click', function () {
    table.api().search($('#lo-search').val()).draw();
  });

  $('#lo-btn-clear').on('click', function () {
    $('#lo-search').val('');
    $('#lo-filter-estado').val('');
    $('#lo-filter-cliente').val('');
    $('#lo-filter-desde, #lo-filter-hasta').val('');
    table.api().search('').draw();
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
