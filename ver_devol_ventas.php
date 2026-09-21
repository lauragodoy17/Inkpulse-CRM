<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/ver_devol_ventas_query.php");

// El listado real de filas ahora lo trae ajax/ver_devol_ventas_data.php
// (server-side DataTables). Aquí solo se necesita el total (para la
// tarjeta) y las opciones del select de estado.
list($from, $where, $params) = ver_devol_ventas_query_parts();

$req = $bdd->prepare("SELECT COUNT(*) $from $where");
$req->execute($params);
$total = intval($req->fetchColumn());

$req = $bdd->prepare("SELECT DISTINCT e.estado $from $where ORDER BY e.estado");
$req->execute($params);
$estados_uniq = array_column($req->fetchAll(), 'estado');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Devoluciones de venta</title>
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
    .lm-count-badge { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .ft-date-wrap   { display:flex; align-items:center; gap:6px; }
    .ft-date-label  { font-size:12px; color:#64748b; font-weight:600; white-space:nowrap; margin:0; }

    .estado-badge          { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
    .estado-badge.amarillo { background:#fef3c7; color:#b45309; }
    .estado-badge.azul     { background:#dbeafe; color:#1d4ed8; }
    .estado-badge.verde    { background:#dcfce7; color:#15803d; }
    .estado-badge.rojo     { background:#fee2e2; color:#dc2626; }
    .estado-badge.teal     { background:#ccfbf1; color:#0f766e; }

    #dv-table thead th {
      background: #1e40af !important; color: #fff !important;
      font-weight: 600; font-size: .80rem; padding: 11px 12px; white-space: nowrap; border: none;
    }
    #dv-table tbody tr:nth-child(even) td { background: #eff6ff; }
    #dv-table tbody tr:hover td           { background: #dbeafe !important; }
    #dv-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #dv-table tbody tr:hover              { border-left-color: #1d4ed8; }

    .lm-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid #1d4ed8; color: #1d4ed8; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #dv-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #dv-table th, #dv-table td { padding: 3px 4px !important; }
      #dv-table thead th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #dv-table thead, #dv-table tfoot { display: table-row-group !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
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
              <h4>
                Devoluciones de venta
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;
                  padding:3px 12px;border-radius:20px;margin-left:10px;vertical-align:middle;
                  background:#dbeafe;color:#1d4ed8;">
                  <i class="bi bi-arrow-return-left"></i> Ventas
                </span>
              </h4>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#dbeafe;color:#1d4ed8">
              <i class="bi bi-arrow-return-left"></i>
            </div>
            <div class="stat-info-modern">
              <h3><?= $total ?></h3>
              <p class="stat-label">Devoluciones de venta</p>
              <span class="stat-sub">Total de registros</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="dv-search" placeholder="Buscar por cliente, colegio, usuario...">
        </div>
        <?php if (!empty($estados_uniq)): ?>
        <select class="ft-select" id="dv-estado">
          <option value="">Todos los estados</option>
          <?php foreach ($estados_uniq as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="dv-fecha-desde" style="min-width:140px">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="dv-fecha-hasta" style="min-width:140px">
        </div>
        <button class="ft-btn ft-apply" id="dv-btn-apply"><i class="bi bi-funnel"></i> Filtrar</button>
        <button class="ft-btn ft-clear" id="dv-btn-clear"><i class="bi bi-x-circle"></i> Limpiar</button>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista — Devoluciones de venta</h5>
          <span class="lm-count-badge"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="dv-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Colegio</th>
                <th>Calendario</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Las filas las pinta DataTables via ajax/ver_devol_ventas_data.php (server-side) -->
            </tbody>
          </table>
        </div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="src/ink-alerts.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script>
$(document).ready(function () {
  function estadoCls(estado) {
    var est = (estado || '').toLowerCase();
    if (est.indexOf('anul') !== -1 || est.indexOf('rechaz') !== -1) return 'rojo';
    if (est.indexOf('realiz') !== -1 || est.indexOf('recib') !== -1 ||
        est.indexOf('aprob') !== -1 || est.indexOf('complet') !== -1) return 'verde';
    if (est.indexOf('entreg') !== -1) return 'teal';
    if (est.indexOf('proceso') !== -1 || est.indexOf('atend') !== -1 || est.indexOf('camino') !== -1) return 'azul';
    return 'amarillo';
  }

  var table = $('#dv-table').DataTable({
    autoWidth:  false,
    processing: true,
    serverSide: true,
    order:      [[0, 'desc']],
    ajax: {
      url: 'ajax/ver_devol_ventas_data.php',
      type: 'POST',
      data: function (d) {
        d.estado      = $('#dv-estado').val();
        d.fecha_desde = $('#dv-fecha-desde').val();
        d.fecha_hasta = $('#dv-fecha-hasta').val();
      },
      dataSrc: function (json) {
        $('.lm-count-badge').text(json.recordsFiltered + ' registros');
        return json.data;
      }
    },
    columns: [
      { data: 'id' },
      { data: 'fecha_d' },
      { data: 'usuario' },
      { data: 'colegio' },
      { data: 'calendario' },
      { data: 'cliente' },
      { data: 'estado', render: function (data, type, row) {
          return '<span class="estado-badge ' + estadoCls(data) + '">' + data + '</span>';
        }
      },
      { data: null, orderable: false, render: function (data, type, row) {
          return '<a href="devolucion_colegio.php?id_pedido=' + row.id + '" class="lm-btn-ver">' +
                 '<i class="bi bi-eye"></i> Ver detalle</a>';
        }
      },
    ],
    language: {
      lengthMenu:   'Mostrar _MENU_ registros',
      zeroRecords:  'No se encontraron resultados',
      emptyTable:   'No hay información para mostrar',
      info:         'Mostrando _START_ a _END_ de _TOTAL_ registros',
      infoEmpty:    'Sin registros disponibles',
      infoFiltered: '(filtrado de _MAX_ registros)',
      processing:   'Buscando...',
      search:       '',
      paginate: { first:'«', previous:'‹', next:'›', last:'»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); }
  });

  var searchTimer;
  $('#dv-search').on('keyup', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () { table.search(val).draw(); }, 300);
  });
  $('#dv-btn-apply').on('click', function () { table.draw(); });
  $('#dv-fecha-desde, #dv-fecha-hasta, #dv-estado').on('change', function () { table.draw(); });
  $('#dv-btn-clear').on('click', function () {
    $('#dv-search').val('');
    $('#dv-estado').val('');
    $('#dv-fecha-desde, #dv-fecha-hasta').val('');
    table.search('').draw();
  });
});
</script>
</body>
</html>
