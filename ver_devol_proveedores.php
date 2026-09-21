<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/ver_devol_proveedores_query.php");

// El listado real de filas ahora lo trae ajax/ver_devol_proveedores_data.php
// (server-side DataTables). Aquí solo se necesita el total para la tarjeta.
list($from, $where, $params) = ver_devol_proveedores_query_parts();

$req = $bdd->prepare("SELECT COUNT(*) $from $where");
$req->execute($params);
$total = intval($req->fetchColumn());
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Devoluciones de proveedores</title>
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
    #vdp-table thead th {
      background: #1e40af !important; color: #fff !important;
      font-weight: 600; font-size: .80rem; padding: 11px 12px;
      white-space: nowrap; border: none;
    }
    #vdp-table tbody tr:nth-child(even) td { background: #eff6ff; }
    #vdp-table tbody tr:hover td           { background: #dbeafe !important; }
    #vdp-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #vdp-table tbody tr:hover              { border-left-color: #2563eb; }
    .vdp-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid #2563eb; color: #2563eb; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .vdp-btn-ver:hover { background: #2563eb; color: #fff; text-decoration: none; }
    .vdp-badge-realizada { background:#dcfce7; color:#15803d; border-radius:20px; padding:2px 10px; font-size:11px; font-weight:600; }
    .vdp-badge-anulada   { background:#fee2e2; color:#dc2626; border-radius:20px; padding:2px 10px; font-size:11px; font-weight:600; }
    .vdp-badge-otro      { background:#fef9c3; color:#92400e; border-radius:20px; padding:2px 10px; font-size:11px; font-weight:600; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #vdp-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #vdp-table th, #vdp-table td { padding: 3px 4px !important; }
      #vdp-table thead th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #vdp-table thead, #vdp-table tfoot { display: table-row-group !important; }
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
            <div class="title"><h4>Devoluciones de proveedores</h4></div>
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
              <p class="stat-label">Devoluciones</p>
              <span class="stat-sub">Total de registros</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="vdp-search" placeholder="Buscar por proveedor, usuario, estado...">
        </div>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de devoluciones</h5>
          <span class="lm-count-badge"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="vdp-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Proveedor</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Las filas las pinta DataTables via ajax/ver_devol_proveedores_data.php (server-side) -->
            </tbody>
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
<script src="src/ink-alerts.js"></script>
<script>
$(document).ready(function () {
  var estadoMap = {
    'Pendiente': { txt: 'Realizada', cls: 'vdp-badge-realizada' },
    'Aprobado':  { txt: 'Aprobado',  cls: 'vdp-badge-realizada' },
    'Anulado':   { txt: 'Anulado',   cls: 'vdp-badge-anulada' }
  };

  var table = $('#vdp-table').DataTable({
    autoWidth:  false,
    processing: true,
    serverSide: true,
    order:      [[0, 'desc']],
    ajax: {
      url: 'ajax/ver_devol_proveedores_data.php',
      type: 'POST',
      dataSrc: function (json) {
        $('.lm-count-badge').text(json.recordsFiltered + ' registros');
        return json.data;
      }
    },
    columns: [
      { data: 'id' },
      { data: 'fecha_d' },
      { data: 'usuario' },
      { data: 'proveedor' },
      { data: 'estado', render: function (data, type, row) {
          var m = estadoMap[data] || { txt: data, cls: 'vdp-badge-otro' };
          return '<span class="' + m.cls + '">' + m.txt + '</span>';
        }
      },
      { data: null, orderable: false, render: function (data, type, row) {
          return '<a href="vista_devol.php?id_devol=' + row.id + '&tipo=' + row.tipo + '" class="vdp-btn-ver">' +
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
  $('#vdp-search').on('keyup', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () { table.search(val).draw(); }, 300);
  });
});
</script>
</body>
</html>
