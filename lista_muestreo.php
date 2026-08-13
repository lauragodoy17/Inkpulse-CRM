<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/lista_muestreo_query.php");

$tp = intval($_GET['tp'] ?? 2);

$status_cfg = [
  1 => ['label'=>'Solicitudes', 'badge'=>'lm-badge-purple', 'icon'=>'bi-clipboard-check'],
  2 => ['label'=>'Pendientes',  'badge'=>'lm-badge-yellow', 'icon'=>'bi-hourglass-split'],
  3 => ['label'=>'Aprobados',   'badge'=>'lm-badge-green',  'icon'=>'bi-check-circle-fill'],
  4 => ['label'=>'Despachados', 'badge'=>'lm-badge-blue',   'icon'=>'bi-truck'],
  5 => ['label'=>'Anulados',    'badge'=>'lm-badge-red',    'icon'=>'bi-x-circle-fill'],
];
$st = $status_cfg[$tp] ?? $status_cfg[2];

// Paleta de color por estado: header, filas pares, hover, acento para botón y borde
$st_accent = [
  1 => ['hdr'=>'#5b21b6', 'even'=>'#faf5ff', 'hover'=>'#ede9fe', 'accent'=>'#6d28d9'],
  2 => ['hdr'=>'#92400e', 'even'=>'#fffbeb', 'hover'=>'#fef3c7', 'accent'=>'#b45309'],
  3 => ['hdr'=>'#166534', 'even'=>'#f0fdf4', 'hover'=>'#dcfce7', 'accent'=>'#16a34a'],
  4 => ['hdr'=>'#1e40af', 'even'=>'#eff6ff', 'hover'=>'#dbeafe', 'accent'=>'#2563eb'],
  5 => ['hdr'=>'#991b1b', 'even'=>'#fff1f2', 'hover'=>'#fee2e2', 'accent'=>'#b91c1c'],
];
$ac = $st_accent[$tp] ?? $st_accent[2];

// El listado real de filas ahora lo trae ajax/lista_muestreo_data.php (server-side
// DataTables). Aquí solo se necesita el total (para la tarjeta) y, para tp!=1,
// las opciones de los selects de filtro — con consultas livianas.
$responsables_uniq = [];
$empresas_uniq     = [];

if ($tp == 1) {
  $gp_periodo = $bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetch();
  $req = $bdd->prepare("SELECT COUNT(*) FROM solicitudes_recursos s WHERE s.id_periodo = :periodo");
  $req->execute([':periodo' => $gp_periodo['id']]);
  $total = intval($req->fetchColumn());
} else {
  list($lm_from, $lm_where, $lm_params, $lm_select_calc) = lista_muestreo_query_parts($tp);

  $req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id $lm_from $lm_where GROUP BY p.id) t");
  $req->execute($lm_params);
  $total = intval($req->fetchColumn());

  $req = $bdd->prepare("SELECT DISTINCT $lm_select_calc $lm_from $lm_where");
  $req->execute($lm_params);
  foreach ($req->fetchAll() as $r) {
    if (!empty($r['resp_calc'])    && !in_array($r['resp_calc'], $responsables_uniq))    $responsables_uniq[] = $r['resp_calc'];
    if (!empty($r['empresa_calc']) && !in_array($r['empresa_calc'], $empresas_uniq))      $empresas_uniq[]     = $r['empresa_calc'];
  }
  sort($empresas_uniq);
  sort($responsables_uniq);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Muestreo</title>
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
    .lm-badge-purple { background:#ede9fe; color:#6d28d9; }
    .lm-count-badge  { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .ft-date-wrap    { display:flex; align-items:center; gap:6px; }
    .ft-date-label   { font-size:12px; color:#64748b; font-weight:600; white-space:nowrap; margin:0; }
    .estado-badge    { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
    .eb-pending  { background:#fef3c7; color:#b45309; }
    /* ── Color temático por estado ─────────────────────────────── */
    #lm-table thead th {
      background: <?= $ac['hdr'] ?> !important;
      color: #fff !important;
      font-weight: 600;
      font-size: 0.80rem;
      padding: 11px 12px;
      white-space: nowrap;
      border: none;
    }
    #lm-table tbody tr:nth-child(even) td { background: <?= $ac['even'] ?>; }
    #lm-table tbody tr:hover td           { background: <?= $ac['hover'] ?> !important; }
    #lm-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #lm-table tbody tr:hover              { border-left-color: <?= $ac['accent'] ?>; }
    .lm-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid <?= $ac['accent'] ?>; color: <?= $ac['accent'] ?>; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: <?= $ac['accent'] ?>; border-color: <?= $ac['accent'] ?>; color: #fff; text-decoration: none; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #lm-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #lm-table th, #lm-table td { padding: 3px 4px !important; }
      #lm-table thead th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #lm-table thead, #lm-table tfoot { display: table-row-group !important; }
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
                Muestreo
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
          <input type="text" id="lm-search" placeholder="Buscar por colegio, responsable, empresa...">
        </div>
        <?php if ($tp != 1 && !empty($responsables_uniq)): ?>
        <select class="ft-select" id="lm-responsable">
          <option value="">Todos los responsables</option>
          <?php foreach ($responsables_uniq as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($tp != 1 && !empty($empresas_uniq)): ?>
        <select class="ft-select" id="lm-empresa">
          <option value="">Todas las empresas</option>
          <?php foreach ($empresas_uniq as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="lm-fecha-desde" style="min-width:140px">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="lm-fecha-hasta" style="min-width:140px">
        </div>
        <button class="ft-btn ft-apply" id="lm-btn-apply"><i class="bi bi-funnel"></i> Filtrar</button>
        <button class="ft-btn ft-clear" id="lm-btn-clear"><i class="bi bi-x-circle"></i> Limpiar</button>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista — <?= $st['label'] ?></h5>
          <span class="lm-count-badge" style="background:<?= $ac['hover'] ?>;color:<?= $ac['accent'] ?>"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">

          <?php if ($tp == 1): ?>
          <table class="table table-sm table-hover" id="lm-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Conse</th>
                <th>Fecha</th>
                <th>Fecha entrega</th>
                <th>Solicitante</th>
                <th>Cargo</th>
                <th>Colegio</th>
                <th>Promotor</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <!-- Las filas las pinta DataTables via ajax/lista_muestreo_data.php (server-side) -->
            </tbody>
          </table>

          <?php else: ?>
          <table class="table table-sm table-hover" id="lm-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Empresa</th>
                <th>Zona</th>
                <th>Responsable</th>
                <th>Colegio</th>
                <th>Calendario</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Las filas las pinta DataTables via ajax/lista_muestreo_data.php (server-side) -->
            </tbody>
          </table>
          <?php endif; ?>

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
  var TP  = <?= json_encode($tp) ?>;
  var TP1 = (TP === 1);

  var columns = TP1 ? [
    { data: 'id' },
    { data: 'conse' },
    { data: 'fecha_d' },
    { data: 'fecha_entrega' },
    { data: 'solicitante' },
    { data: 'cargo' },
    { data: 'colegio' },
    { data: 'promotor' },
    {
      data: 'estado_nombre', orderable: false,
      render: function (data) { return '<span class="estado-badge eb-pending">' + data + '</span>'; }
    },
  ] : [
    { data: 'id' },
    { data: 'fecha_d' },
    { data: 'empresa' },
    { data: 'zona' },
    { data: 'responsable' },
    { data: 'colegio' },
    { data: 'calendario' },
    {
      data: null, orderable: false,
      render: function (data, type, row) {
        return '<a href="' + row.url_detalle + '" class="lm-btn-ver"><i class="bi bi-eye"></i> Ver detalle</a>';
      }
    },
  ];

  var table = $('#lm-table').DataTable({
    autoWidth:   false,
    processing:  true,
    serverSide:  true,
    order:       [[0, 'desc']],
    ajax: {
      url: 'ajax/lista_muestreo_data.php',
      type: 'POST',
      data: function (d) {
        d.tp = TP;
        if (!TP1) {
          d.responsable = $('#lm-responsable').val();
          d.empresa     = $('#lm-empresa').val();
        }
        d.fecha_desde = $('#lm-fecha-desde').val();
        d.fecha_hasta = $('#lm-fecha-hasta').val();
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
    initComplete: function () { $('.dataTables_filter').hide(); }
  });

  var searchTimer;
  $('#lm-search').on('keyup', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () { table.search(val).draw(); }, 300);
  });
  $('#lm-btn-apply').on('click', function () { table.draw(); });
  $('#lm-fecha-desde, #lm-fecha-hasta, #lm-responsable, #lm-empresa').on('change', function () { table.draw(); });
  $('#lm-btn-clear').on('click', function () {
    $('#lm-search').val('');
    $('#lm-responsable, #lm-empresa').val('');
    $('#lm-fecha-desde, #lm-fecha-hasta').val('');
    table.search('').draw();
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
