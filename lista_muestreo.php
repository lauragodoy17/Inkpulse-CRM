<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/lista_muestreo_query.php");

$tp = intval($_GET['tp'] ?? 2);

$status_cfg = [
  2 => ['label'=>'Pendientes',  'badge'=>'lm-badge-yellow', 'icon'=>'bi-hourglass-split'],
  3 => ['label'=>'Aprobados',   'badge'=>'lm-badge-green',  'icon'=>'bi-check-circle-fill'],
  4 => ['label'=>'Enviado',    'badge'=>'lm-badge-green',    'icon'=>'bi-truck'],
  5 => ['label'=>'Anulados',    'badge'=>'lm-badge-red',    'icon'=>'bi-x-circle-fill'],
  6 => ['label'=>'Procesando',  'badge'=>'lm-badge-blue',   'icon'=>'bi-shuffle'],
  7 => ['label'=>'Facturación',    'badge'=>'lm-badge-yellow',    'icon'=>'bi-file-earmark-text'],
  8 => ['label'=>'En despacho', 'badge'=>'lm-badge-purple', 'icon'=>'bi-box-seam'],
];
$st = $status_cfg[$tp] ?? $status_cfg[2];

// Paleta de color por estado: header, filas pares, hover, acento para botón y borde
$st_accent = [
  2 => ['hdr'=>'#BBB50E', 'even'=>'#F3E017', 'hover'=>'#F0F25C', 'accent'=>'#ABAA05'],
  3 => ['hdr'=>'#166534', 'even'=>'#f0fdf4', 'hover'=>'#dcfce7', 'accent'=>'#16a34a'],
  4 => ['hdr'=>'#4ADC4E', 'even'=>'#F0FDF4', 'hover'=>'#DCFCEA', 'accent'=>'#2CC713'],
  5 => ['hdr'=>'#991b1b', 'even'=>'#fff1f2', 'hover'=>'#fee2e2', 'accent'=>'#b91c1c'],
  6 => ['hdr'=>'#1e40af', 'even'=>'#eff6ff', 'hover'=>'#dbeafe', 'accent'=>'#2563eb'],
  7 => ['hdr'=>'#92400e', 'even'=>'#fffbeb', 'hover'=>'#fef3c7', 'accent'=>'#b45309'],
  8 => ['hdr'=>'#6d28d9', 'even'=>'#f5f3ff', 'hover'=>'#ede9fe', 'accent'=>'#7c3aed'],
];
$ac = $st_accent[$tp] ?? $st_accent[2];

// Config de la barra de selección masiva por tp — mismo patrón que
// lista_pedidos.php, para todo el tramo "desde Aprobado en adelante".
$bulk_cfg = [
  3 => ['label' => 'Pasar a Procesando y generar planilla', 'icon' => 'bi-shuffle',
        'endpoint' => 'php/generar_planilla_procesamiento_muestreo.php', 'download' => true,
        'confirm_title' => '¿Pasar a Procesando?',
        'confirm_text'  => 'Se pasarán a estado "Procesando" y se descargará la planilla en PDF.'],
  6 => ['label' => 'Pasar a Facturación', 'icon' => 'bi-file-earmark-text',
        'endpoint' => 'php/generar_lote_facturacion_muestreo.php', 'download' => false,
        'confirm_title' => '¿Pasar a Facturación?',
        'confirm_text'  => 'Quedarán guardados para enviarlos luego por correo desde la lista de Facturación.'],
  7 => ['label' => 'Pasar a En despacho', 'icon' => 'bi-box-seam',
        'endpoint' => 'php/generar_lote_despacho_muestreo.php', 'download' => false,
        'confirm_title' => '¿Pasar a En despacho?',
        'confirm_text'  => 'Los muestreos seleccionados pasarán a estado "En despacho".'],
  8 => ['label' => 'Marcar como Despachado', 'icon' => 'bi-truck',
        'endpoint' => 'php/generar_lote_entrega_muestreo.php', 'download' => false,
        'confirm_title' => '¿Marcar como Despachado?',
        'confirm_text'  => 'Los muestreos seleccionados quedarán marcados como "Despachado".'],
];

// tp=7 (Facturación): cuántos muestreos quedaron guardados en
// lotes_facturacion_muestreo que todavía no se han enviado por correo.
$pend_facturacion = 0;
if ($tp == 7) {
  $bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo (
      id INT AUTO_INCREMENT PRIMARY KEY,
      fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      id_usuario INT NOT NULL,
      cantidad_pedidos INT NOT NULL DEFAULT 0,
      enviado TINYINT(1) NOT NULL DEFAULT 0,
      fecha_envio DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo_pedidos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_lote INT NOT NULL,
      id_pedido INT NOT NULL,
      KEY idx_lote (id_lote),
      FOREIGN KEY (id_lote) REFERENCES lotes_facturacion_muestreo(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pend_facturacion = intval($bdd->query(
    "SELECT COUNT(*) FROM lotes_facturacion_muestreo_pedidos lp
     JOIN lotes_facturacion_muestreo l ON l.id = lp.id_lote
     WHERE l.enviado = 0"
  )->fetchColumn());
}

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
      display: inline-flex; align-items: center; gap: 4px;
      padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600;
      border: 1.5px solid <?= $ac['accent'] ?>; color: <?= $ac['accent'] ?>; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: <?= $ac['accent'] ?>; border-color: <?= $ac['accent'] ?>; color: #fff; text-decoration: none; }

    /* ── Columna Acciones: checkbox + Ver detalle en línea ──────────── */
    .lp-acciones-cell { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .lp-chk-pedido {
      width: 16px; height: 16px; margin: 0; flex-shrink: 0;
      accent-color: <?= $ac['accent'] ?>; cursor: pointer;
    }

    /* ── Barra de acción masiva / avisos ─────────────────────────────── */
    .lp-sel-bar {
      display: flex; align-items: center; justify-content: space-between; gap: 14px;
      flex-wrap: wrap;
      padding: 12px 18px; margin: 0 8px 14px;
      background: linear-gradient(135deg, #eff6ff, #f5f8ff);
      border: 1px solid #bfdbfe; border-radius: 12px;
      box-shadow: 0 1px 3px rgba(15,23,42,.06);
      font-size: 13.5px; color: #1e3a8a;
    }
    .lp-sel-bar.lp-sel-bar-warn {
      background: linear-gradient(135deg, #fffbeb, #fffdf5);
      border-color: #fde68a; color: #92400e;
    }
    .lp-sel-bar-info { display: flex; align-items: center; gap: 10px; }
    .lp-sel-bar-icon {
      width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; background: rgba(255,255,255,.65); color: inherit;
    }
    .lp-btn-action {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border: none; border-radius: 9px;
      font-size: 13px; font-weight: 700; font-family: 'Inter', sans-serif;
      background: #2563eb; color: #fff; cursor: pointer;
      box-shadow: 0 1px 2px rgba(37,99,235,.3);
      transition: background .15s, transform .1s, box-shadow .15s;
      white-space: nowrap;
    }
    .lp-btn-action:hover:not(:disabled) { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(37,99,235,.35); }
    .lp-btn-action:active:not(:disabled) { transform: translateY(0); }
    .lp-btn-action:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
    .lp-sel-bar-warn .lp-btn-action { background: #d97706; box-shadow: 0 1px 2px rgba(217,119,6,.3); }
    .lp-sel-bar-warn .lp-btn-action:hover:not(:disabled) { background: #b45309; box-shadow: 0 3px 8px rgba(217,119,6,.35); }
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
          <input type="text" id="lm-search" placeholder="Buscar por # de pedido, colegio, responsable, empresa...">
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
        <?php if (isset($bulk_cfg[$tp])): $bc = $bulk_cfg[$tp]; ?>
        <div class="lp-sel-bar" id="lp-sel-bar">
          <div class="lp-sel-bar-info">
            <span class="lp-sel-bar-icon"><i class="bi bi-check2-square"></i></span>
            <span><strong id="lp-sel-count">0</strong> muestreo(s) seleccionado(s)</span>
          </div>
          <button type="button" id="lp-btn-procesar" class="lp-btn-action" disabled>
            <i class="bi <?= $bc['icon'] ?>"></i> <?= htmlspecialchars($bc['label']) ?>
          </button>
        </div>
        <?php endif; ?>
        <?php if ($tp == 7 && $pend_facturacion > 0): ?>
        <div class="lp-sel-bar lp-sel-bar-warn" id="lf-envio-bar">
          <div class="lp-sel-bar-info">
            <span class="lp-sel-bar-icon"><i class="bi bi-envelope"></i></span>
            <span>Hay <strong><?= $pend_facturacion ?></strong> muestreo(s) pendientes de enviar por correo a facturación.</span>
          </div>
          <button type="button" id="lf-btn-enviar" class="lp-btn-action">
            <i class="bi bi-send"></i> Enviar por correo
          </button>
        </div>
        <?php endif; ?>
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
                <th>Tipo</th>
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
  var BULK_CFG = <?= json_encode($bulk_cfg, JSON_UNESCAPED_UNICODE) ?>;
  var bulkCfg = BULK_CFG[TP] || null;

  function confirmar(title, text, onOk) {
    if (window.inkConfirm) {
      window.inkConfirm({ type: 'info', title: title, text: text, btnOk: 'Sí, continuar' }, onOk);
    } else if (confirm(title + '\n' + text)) {
      onOk();
    }
  }

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
    { data: 'tipo' },
    { data: 'calendario' },
    {
      data: null, orderable: false,
      render: function (data, type, row) {
        var chk = bulkCfg ? '<input type="checkbox" class="lp-chk-pedido" data-id="' + row.id + '">' : '';
        return '<div class="lp-acciones-cell">' + chk +
          '<a href="' + row.url_detalle + '" class="lm-btn-ver"><i class="bi bi-eye"></i> Ver detalle</a></div>';
      }
    },
  ];

  // Selección de muestreos persistida entre páginas del DataTable
  // (server-side): se guarda en un objeto {id: true} y se vuelve a marcar
  // el checkbox correspondiente cada vez que se pinta una página nueva.
  var selectedIds = {};
  function lpUpdateSelBar() {
    var n = Object.keys(selectedIds).length;
    $('#lp-sel-count').text(n);
    $('#lp-btn-procesar').prop('disabled', n === 0);
  }
  $('#lm-table').on('change', '.lp-chk-pedido', function () {
    var id = $(this).data('id');
    if (this.checked) selectedIds[id] = true;
    else delete selectedIds[id];
    lpUpdateSelBar();
  });
  $('#lp-btn-procesar').on('click', function () {
    var ids = Object.keys(selectedIds);
    if (!ids.length || !bulkCfg) return;

    confirmar(bulkCfg.confirm_title, ids.length + ' muestreo(s). ' + bulkCfg.confirm_text, function () {
      var $form = $('<form>', { method: 'POST', action: bulkCfg.endpoint });
      if (bulkCfg.download) $form.attr('target', '_blank');
      ids.forEach(function (id) {
        $form.append($('<input>', { type: 'hidden', name: 'ids[]', value: id }));
      });
      $('body').append($form);
      $form.submit();

      if (bulkCfg.download) {
        $form.remove();
        var n = ids.length;
        selectedIds = {};
        lpUpdateSelBar();
        table.ajax.reload(null, false);
        if (window.inkToast) {
          window.inkToast(n + ' muestreo(s) actualizado(s). La planilla se está descargando en una pestaña nueva.', 'ok');
        }
      }
    });
  });

  <?php if ($tp == 7): ?>
  $('#lf-btn-enviar').on('click', function () {
    confirmar('¿Enviar por correo?', 'Se enviarán a facturacion3@somoseureka.com.co los muestreos pendientes.', function () {
      var $form = $('<form>', { method: 'POST', action: 'php/enviar_lote_facturacion_muestreo.php' });
      $('body').append($form);
      $form.submit();
    });
  });
  <?php endif; ?>

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
    initComplete: function () { $('.dataTables_filter').hide(); },
    drawCallback: function () {
      if (bulkCfg) {
        $('.lp-chk-pedido').each(function () {
          $(this).prop('checked', !!selectedIds[$(this).data('id')]);
        });
      }
    }
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
