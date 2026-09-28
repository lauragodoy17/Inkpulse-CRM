<?php
require_once("php/aut.php");

// Mismo acceso que los módulos de compras/despacho (Listas de empaque, Devoluciones de proveedores).
if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Órdenes de compra</title>
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
    #oc-table thead th {
      background: #1e40af !important; color: #fff !important;
      font-weight: 600; font-size: .80rem; padding: 11px 12px;
      white-space: nowrap; border: none;
    }
    #oc-table tbody tr:nth-child(even) td { background: #eff6ff; }
    #oc-table tbody tr:hover td           { background: #dbeafe !important; }
    #oc-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #oc-table tbody tr:hover              { border-left-color: #2563eb; }
    #oc-table td { font-size: .82rem; vertical-align: middle; }
    .oc-concepto { font-size: .78rem; color: #475569; max-width: 340px; white-space: normal; }
    .oc-badge-anulada { background:#fee2e2; color:#dc2626; border-radius:20px; padding:1px 8px; font-size:10.5px; font-weight:600; margin-left:4px; }
    .oc-aviso { border-radius:10px; padding:11px 15px; font-size:.84rem; margin-bottom:14px; }
    .oc-aviso-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .oc-aviso-warn  { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .oc-hidden { display:none; }
    .oc-btn-abrir {
      display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:7px; font-size:12px; font-weight:600;
      border:1.5px solid #2563eb; color:#2563eb; background:transparent; white-space:nowrap; text-decoration:none; transition:background .15s, color .15s;
    }
    .oc-btn-abrir:hover { background:#2563eb; color:#fff; text-decoration:none; }
    .oc-filtro-estado { max-width:230px; height:38px; font-size:.85rem; }
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
            <div class="title"><h4>Órdenes de compra</h4></div>
            <small class="text-muted">Consultadas en World Office en el momento (documentos tipo OC). La recepción se registra en el CRM.</small>
          </div>
        </div>
      </div>

      <?php include("template/oc_tabs.php"); ?>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#dbeafe;color:#1d4ed8">
              <i class="bi bi-cart-check"></i>
            </div>
            <div class="stat-info-modern">
              <h3 id="oc-total">…</h3>
              <p class="stat-label">Órdenes de compra</p>
              <span class="stat-sub">Registradas en World Office</span>
            </div>
          </div>
        </div>
      </div>

      <div id="oc-error" class="oc-aviso oc-aviso-error oc-hidden"></div>
      <div id="oc-incompleto" class="oc-aviso oc-aviso-warn oc-hidden"></div>

      <div class="filter-toolbar" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="oc-search" placeholder="Buscar por número, proveedor o concepto...">
        </div>
        <select id="oc-filtro-estado" class="form-control oc-filtro-estado">
          <option value="">Todos los estados de recepción</option>
          <option value="pendiente">Pendiente de recepción</option>
          <option value="parcial">Recepción parcial</option>
          <option value="completa">Recibida completamente</option>
          <option value="excedentes">Con excedentes</option>
        </select>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de órdenes de compra</h5>
          <span class="lm-count-badge" id="oc-count">Cargando…</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="oc-table" style="width:100%">
            <thead>
              <tr>
                <th>N.º orden</th>
                <th>Fecha</th>
                <th>Empresa</th>
                <th>Proveedor</th>
                <th>Concepto</th>
                <th>Responsable</th>
                <th>Forma de pago</th>
                <th>Recepción</th>
                <th></th>
              </tr>
            </thead>
            <tbody></tbody>
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
  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function mostrarError(msg) {
    $('#oc-error').html('<i class="bi bi-exclamation-octagon"></i> ' + h(msg)).removeClass('oc-hidden');
    $('#oc-total').text('—');
    $('#oc-count').text('Sin datos');
  }

  // Todas las órdenes se traen una vez desde World Office (php/ordenes_compra_listar.php recorre
  // las páginas del servicio y agrega el estado de recepción del CRM); DataTables pagina, ordena y
  // busca en el navegador.
  var table = $('#oc-table').DataTable({
    autoWidth:  false,
    processing: true,
    order:      [[1, 'desc']],
    pageLength: 25,
    ajax: {
      url: 'php/ordenes_compra_listar.php',
      dataSrc: function (json) {
        if (!json || !json.ok) { mostrarError((json && json.error) || 'No se pudo consultar World Office.'); return []; }
        $('#oc-total').text(json.data.length);
        $('#oc-count').text(json.data.length + ' registros');
        if (json.incompleto) {
          $('#oc-incompleto').html('<i class="bi bi-exclamation-triangle"></i> World Office reporta ' + json.total + ' órdenes de compra; se muestran las ' + json.data.length + ' más recientes.').removeClass('oc-hidden');
        }
        return json.data;
      },
      error: function () { mostrarError('No se pudo conectar con World Office. Intenta de nuevo en unos minutos.'); table.clear().draw(); }
    },
    columns: [
      { data: 'numero_completo', render: function (d, type, row) {
          if (type !== 'display') return d;
          return '<strong>' + h(d || row.id) + '</strong>' + (row.senAnulado ? '<span class="oc-badge-anulada">Anulada</span>' : '');
        } },
      // Se ordena por la fecha en formato ISO y se muestra como la manda WO (dd/mm/aaaa).
      { data: 'fecha', render: function (d, type, row) { return type === 'sort' || type === 'type' ? row.fecha_iso : h(d); } },
      { data: 'empresa', render: function (d) { return h(d); } },
      { data: 'terceroExterno', render: function (d) { return h(d); } },
      { data: 'concepto', render: function (d, type) { return type === 'display' ? '<div class="oc-concepto">' + h(d) + '</div>' : d; } },
      { data: 'responsable', render: function (d) { return h(d); } },
      { data: 'formaPago', render: function (d) { return h(d); } },
      { data: 'estado_recepcion', render: function (d, type, row) {
          return type === 'display' ? '<span class="oc-est oc-est-' + h(d) + '">' + h(row.estado_recepcion_label) + '</span>' : d;
        } },
      { data: null, orderable: false, searchable: false, render: function (d, type, row) {
          return type === 'display' ? '<a href="orden_compra.php?id=' + encodeURIComponent(row.id) + '" class="oc-btn-abrir"><i class="bi bi-box-arrow-in-right"></i> Ver / recibir</a>' : '';
        } }
    ],
    language: {
      lengthMenu:   'Mostrar _MENU_ registros',
      zeroRecords:  'No se encontraron órdenes de compra con esa búsqueda',
      emptyTable:   'No hay órdenes de compra registradas en World Office',
      info:         'Mostrando _START_ a _END_ de _TOTAL_ registros',
      infoEmpty:    'Sin registros disponibles',
      infoFiltered: '(filtrado de _MAX_ registros)',
      loadingRecords: 'Consultando World Office...',
      processing:   'Consultando World Office...',
      search:       '',
      paginate: { first:'«', previous:'‹', next:'›', last:'»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); }
  });

  // Búsqueda sobre número, proveedor y concepto (columnas 0, 3 y 4) + filtro por estado de recepción.
  $.fn.dataTable.ext.search.push(function (settings, data, idx) {
    if (settings.nTable.id !== 'oc-table') return true;
    var estado = $('#oc-filtro-estado').val();
    if (estado && table.row(idx).data().estado_recepcion !== estado) return false;
    var q = $('#oc-search').val().trim().toLowerCase();
    if (!q) return true;
    return [data[0], data[3], data[4]].some(function (v) { return String(v || '').toLowerCase().indexOf(q) >= 0; });
  });
  var searchTimer;
  $('#oc-search').on('keyup', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { table.draw(); }, 250);
  });
  $('#oc-filtro-estado').on('change', function () { table.draw(); });
});
</script>
</body>
</html>
