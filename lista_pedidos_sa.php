<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$tp = intval($_GET['tp'] ?? 2);
$show_stock = ($tp == 2 || $tp == 3) && (($_SESSION['tipo'] ?? null) == 1);

$status_cfg = [
  2 => ['label'=>'Pendientes',  'badge'=>'lm-badge-yellow', 'icon'=>'bi-hourglass-split'],
  3 => ['label'=>'Aprobados',   'badge'=>'lm-badge-green',  'icon'=>'bi-check-circle-fill'],
  4 => ['label'=>'Entregados',  'badge'=>'lm-badge-blue',   'icon'=>'bi-truck'],
  5 => ['label'=>'Anulados',    'badge'=>'lm-badge-red',    'icon'=>'bi-x-circle-fill'],
];
$st = $status_cfg[$tp] ?? $status_cfg[2];

$st_accent = [
  2 => ['hdr'=>'#92400e', 'even'=>'#fffbeb', 'hover'=>'#fef3c7', 'accent'=>'#b45309'],
  3 => ['hdr'=>'#166534', 'even'=>'#f0fdf4', 'hover'=>'#dcfce7', 'accent'=>'#16a34a'],
  4 => ['hdr'=>'#1e40af', 'even'=>'#eff6ff', 'hover'=>'#dbeafe', 'accent'=>'#2563eb'],
  5 => ['hdr'=>'#991b1b', 'even'=>'#fff1f2', 'hover'=>'#fee2e2', 'accent'=>'#b91c1c'],
];
$ac = $st_accent[$tp] ?? $st_accent[2];

// El listado real de filas ahora lo trae ajax/lista_pedidos_sa_data.php
// (server-side DataTables). Aquí solo se necesita el total para la tarjeta.
if ($tp == 2) {
  $where_estado = "p.estado='1' AND p.verify='1'";
} elseif ($tp == 3) {
  $where_estado = "p.estado='2'";
} elseif ($tp == 4) {
  $where_estado = "p.estado='4'";
} else {
  $where_estado = "p.estado='3'";
}

$req = $bdd->query("SELECT COUNT(*) FROM (SELECT p.id FROM pedidos2 p JOIN usuarios u ON u.id=p.id_usuario WHERE $where_estado GROUP BY p.id) t");
$total = intval($req->fetchColumn());
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Pedidos SA <?= $st['label'] ?></title>
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
    .lm-status-badge { display:inline-flex; align-items:center; gap:5px; font-size:13px; font-weight:600; padding:3px 12px; border-radius:20px; margin-left:10px; vertical-align:middle; }
    .lm-badge-yellow { background:#fef3c7; color:#b45309; }
    .lm-badge-green  { background:#dcfce7; color:#15803d; }
    .lm-badge-blue   { background:#dbeafe; color:#1d4ed8; }
    .lm-badge-red    { background:#fee2e2; color:#dc2626; }
    .lm-count-badge  { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .ft-date-wrap    { display:flex; align-items:center; gap:6px; }
    .ft-date-label   { font-size:12px; color:#64748b; font-weight:600; white-space:nowrap; margin:0; }
    #lps-table thead th {
      background: <?= $ac['hdr'] ?> !important;
      color: #fff !important;
      font-weight: 600; font-size: .80rem; padding: 11px 12px;
      white-space: nowrap; border: none;
    }
    #lps-table tbody tr:nth-child(even) td { background: <?= $ac['even'] ?>; }
    #lps-table tbody tr:hover td           { background: <?= $ac['hover'] ?> !important; }
    #lps-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #lps-table tbody tr:hover              { border-left-color: <?= $ac['accent'] ?>; }
    .lm-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid <?= $ac['accent'] ?>; color: <?= $ac['accent'] ?>; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: <?= $ac['accent'] ?>; color: #fff; text-decoration: none; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #lps-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #lps-table th, #lps-table td { padding: 3px 4px !important; }
      #lps-table thead th { background: <?= $ac['hdr'] ?> !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #lps-table thead, #lps-table tfoot { display: table-row-group !important; }
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
                Pedidos sin adopción
                <span class="lm-status-badge <?= $st['badge'] ?>">
                  <i class="bi <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
                </span>
              </h4>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:<?= $ac['hover'] ?>;color:<?= $ac['accent'] ?>">
              <i class="bi <?= $st['icon'] ?>"></i>
            </div>
            <div class="stat-info-modern">
              <h3><?= $total ?></h3>
              <p class="stat-label"><?= $st['label'] ?></p>
              <span class="stat-sub">Total de registros</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="lps-search" placeholder="Buscar por # de pedido, colegio o distribuidor...">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="lps-fecha-desde" style="min-width:140px">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="lps-fecha-hasta" style="min-width:140px">
        </div>
        <button class="ft-btn ft-apply" id="lps-btn-apply"><i class="bi bi-funnel"></i> Filtrar</button>
        <button class="ft-btn ft-clear" id="lps-btn-clear"><i class="bi bi-x-circle"></i> Limpiar</button>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista — <?= $st['label'] ?></h5>
          <span class="lm-count-badge" style="background:<?= $ac['hover'] ?>;color:<?= $ac['accent'] ?>"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="lps-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Distribuidor</th>
                <th>Colegio</th>
                <?php if ($show_stock): ?><th>Stock</th><?php endif; ?>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Las filas las pinta DataTables via ajax/lista_pedidos_sa_data.php (server-side) -->
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
<script>
$(document).ready(function () {
  var TP = <?= json_encode($tp) ?>;
  var SHOW_STOCK = <?= json_encode($show_stock) ?>;

  var columns = [
    { data: 'id' },
    { data: 'fecha_d' },
    { data: 'promotor' },
    { data: 'colegio' },
  ];
  if (SHOW_STOCK) {
    columns.push({ data: null, orderable: false, className: 'lps-stock-cell', render: function () { return '—'; } });
  }
  columns.push({
    data: null, orderable: false,
    render: function (data, type, row) {
      return '<a href="pedido_colegio_sa.php?id_pedido=' + row.id + '&tp=' + TP + '" class="lm-btn-ver">' +
             '<i class="bi bi-eye"></i> Ver detalle</a>';
    }
  });

  var table = $('#lps-table').DataTable({
    autoWidth: false,
    processing: true,
    serverSide: true,
    order: [[0, 'desc']],
    ajax: {
      url: 'ajax/lista_pedidos_sa_data.php',
      type: 'POST',
      data: function (d) {
        d.tp          = TP;
        d.fecha_desde = $('#lps-fecha-desde').val();
        d.fecha_hasta = $('#lps-fecha-hasta').val();
      },
      dataSrc: function (json) {
        $('.lm-count-badge').text(json.recordsFiltered + ' registros');
        return json.data;
      }
    },
    columns: columns,
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
    initComplete: function () { $('.dataTables_filter').hide(); },
    drawCallback: function () {
      if (SHOW_STOCK) cargarStockBajo(table);
    }
  });

  var searchTimer;
  $('#lps-search').on('keyup', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () { table.search(val).draw(); }, 300);
  });
  $('#lps-btn-apply').on('click', function () { table.draw(); });
  $('#lps-fecha-desde, #lps-fecha-hasta').on('change', function () { table.draw(); });
  $('#lps-btn-clear').on('click', function () {
    $('#lps-search').val('');
    $('#lps-fecha-desde, #lps-fecha-hasta').val('');
    table.search('').draw();
  });

  function cargarStockBajo(table) {
    var filas = table.rows({ page: 'current' }).data().toArray();
    var ids = filas.map(function (r) { return r.id; });
    if (!ids.length) return;

    $.ajax({
      url: 'ajax/stock_bajo_pedidos.php',
      type: 'POST',
      data: { origen: 'pedidos2', ids: ids },
      dataType: 'json'
    }).done(function (data) {
      $(table.column(4).nodes()).each(function (i) {
        var libros = data[filas[i].id];
        var $celda = $(this);
        if (libros && libros.length) {
          var titulo = libros.map(function (l) { return l.libro + ': ' + l.existencia + ' unid.'; }).join('\n');
          $celda.html(
            '<span class="lm-stock-badge" title="' + $('<div>').text(titulo).html() + '">' +
            '<i class="bi bi-exclamation-triangle-fill"></i> Stock bajo</span>'
          );
        } else {
          $celda.html('<span style="color:#94a3b8;font-size:12px">OK</span>');
        }
      });
    }).fail(function () {
      $(table.column(4).nodes()).html('<span style="color:#94a3b8;font-size:12px" title="No se pudo verificar">—</span>');
    });
  }
});
</script>
<?php if ($show_stock): ?>
<style>
  .lm-stock-badge {
    display:inline-flex; align-items:center; gap:4px;
    font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px;
    background:#fee2e2; color:#dc2626; cursor:default;
  }
</style>
<?php endif; ?>
</body>
</html>
