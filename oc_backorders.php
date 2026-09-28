<?php
require_once("php/aut.php");

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Backorders de órdenes de compra</title>
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
    #bo-table thead th { background:#1e40af !important; color:#fff !important; font-weight:600; font-size:.80rem; padding:11px 12px; white-space:nowrap; border:none; }
    #bo-table tbody tr:nth-child(even) td { background:#eff6ff; }
    #bo-table tbody tr:hover td { background:#dbeafe !important; cursor:pointer; }
    #bo-table td { font-size:.82rem; vertical-align:middle; }
    #bo-table td.num, #bo-table th.num { text-align:right; white-space:nowrap; }
    .bo-filtros { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; margin-bottom:16px; }
    .bo-filtros label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:3px; }
    .bo-aviso-error { border-radius:10px; padding:11px 15px; font-size:.84rem; margin-bottom:14px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .bo-hidden { display:none; }
    .bo-mov { width:100%; font-size:.82rem; border-collapse:collapse; }
    .bo-mov th { background:#e2e8f0; color:#334155; font-size:.72rem; text-transform:uppercase; padding:6px 8px; text-align:left; }
    .bo-mov td { padding:6px 8px; border-bottom:1px solid #f1f5f9; }
    .bo-mov .num { text-align:right; white-space:nowrap; }
    .bo-dato-l { font-size:.7rem; font-weight:700; text-transform:uppercase; color:#64748b; }
    .bo-dato-v { font-size:.88rem; font-weight:600; color:#0f172a; margin-bottom:10px; }
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
            <div class="title"><h4>Backorders de órdenes de compra</h4></div>
            <small class="text-muted">Productos que faltan por recibir, generados automáticamente al registrar recepciones.</small>
          </div>
        </div>
      </div>

      <?php include("template/oc_tabs.php"); ?>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#fef3c7;color:#b45309"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-info-modern"><h3 id="bo-abiertos">…</h3><p class="stat-label">Backorders abiertos</p><span class="stat-sub">Pendientes y parciales</span></div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#fee2e2;color:#b91c1c"><i class="bi bi-box"></i></div>
            <div class="stat-info-modern"><h3 id="bo-unidades">…</h3><p class="stat-label">Unidades pendientes</p><span class="stat-sub">Por recibir de proveedores</span></div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#dbeafe;color:#1d4ed8"><i class="bi bi-cart-check"></i></div>
            <div class="stat-info-modern"><h3 id="bo-ordenes">…</h3><p class="stat-label">Órdenes con faltantes</p><span class="stat-sub">Con al menos un backorder abierto</span></div>
          </div>
        </div>
      </div>

      <div id="bo-error" class="bo-aviso-error bo-hidden"></div>

      <div class="bo-filtros">
        <div><label>Orden de compra</label><input type="text" id="bo-f-orden" class="form-control form-control-sm" placeholder="Número o prefijo"></div>
        <div><label>Proveedor</label><input type="text" id="bo-f-proveedor" class="form-control form-control-sm"></div>
        <div><label>Producto</label><input type="text" id="bo-f-producto" class="form-control form-control-sm" placeholder="Nombre, código o ISBN"></div>
        <div><label>Estado</label>
          <select id="bo-f-estado" class="form-control form-control-sm">
            <option value="abiertos">Abiertos (pendiente y parcial)</option>
            <option value="pendiente">Pendiente</option>
            <option value="parcial">Parcial</option>
            <option value="completado">Completado</option>
            <option value="excedente">Excedente</option>
            <option value="">Todos</option>
          </select>
        </div>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Backorders</h5>
          <span style="display:flex; gap:10px; align-items:center;">
            <span class="lm-count-badge" id="bo-count">Cargando…</span>
            <a href="#" id="bo-excel" class="btn btn-success btn-sm" title="Descarga los backorders con los filtros actuales"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
          </span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="bo-table" style="width:100%">
            <thead>
              <tr>
                <th>Orden de compra</th><th>Fecha orden</th><th>Proveedor</th><th>Producto</th><th>Código / ISBN</th>
                <th class="num">Solicitada</th><th class="num">Recibida</th><th class="num">Pendiente</th><th>Generado</th><th>Estado</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <p style="font-size:.78rem; color:#64748b;">Haz clic en un backorder para ver su historial de recepciones.</p>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<!-- Historial de un backorder -->
<div class="modal fade" id="bo-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bo-modal-titulo">Historial del backorder</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="bo-modal-cuerpo"></div>
      <div class="modal-footer">
        <a href="#" class="btn btn-primary btn-sm" id="bo-modal-orden"><i class="bi bi-box-arrow-in-right"></i> Ir a la orden de compra</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
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
  function n(v) { return (parseFloat(v) || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 }); }
  function fecha(f) { if (!f) return '—'; var p = String(f).substr(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : f; }
  function fechaHora(f) { if (!f) return '—'; var p = String(f).split(/[- :]/); return p.length >= 5 ? p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4] : f; }
  function estado(e, label) { return '<span class="oc-est oc-est-' + h(e) + '">' + h(label) + '</span>'; }

  var table = $('#bo-table').DataTable({
    autoWidth: false,
    processing: true,
    order: [[8, 'desc']],
    pageLength: 25,
    ajax: {
      url: 'php/oc_backorders_listar.php',
      dataSrc: function (json) {
        if (!json || !json.ok) { $('#bo-error').text((json && json.error) || 'No se pudieron cargar los backorders.').removeClass('bo-hidden'); return []; }
        var abiertos = json.data.filter(function (b) { return b.estado === 'pendiente' || b.estado === 'parcial'; });
        $('#bo-abiertos').text(abiertos.length);
        $('#bo-unidades').text(n(abiertos.reduce(function (a, b) { return a + b.pendiente; }, 0)));
        $('#bo-ordenes').text(abiertos.map(function (b) { return b.id_orden_wo; }).filter(function (v, i, a) { return a.indexOf(v) === i; }).length);
        return json.data;
      },
      error: function () { $('#bo-error').text('No se pudieron cargar los backorders. Intenta de nuevo.').removeClass('bo-hidden'); }
    },
    columns: [
      { data: 'orden', render: function (d, type) { return type === 'display' ? '<strong>' + h(d) + '</strong>' : d; } },
      { data: 'fecha_orden', render: function (d, type) { return type === 'display' ? fecha(d) : d; } },
      { data: 'proveedor', render: function (d) { return h(d); } },
      { data: 'producto', render: function (d) { return h(d); } },
      { data: 'codigo', render: function (d, type, row) { return type === 'display' ? h(d) + (row.isbn && row.isbn !== d ? '<div style="font-size:.72rem;color:#94a3b8;">ISBN ' + h(row.isbn) + '</div>' : '') : d + ' ' + row.isbn; } },
      { data: 'solicitada', className: 'num', render: function (d, type) { return type === 'display' ? n(d) : d; } },
      { data: 'recibida', className: 'num', render: function (d, type) { return type === 'display' ? n(d) : d; } },
      { data: 'pendiente', className: 'num', render: function (d, type) { return type === 'display' ? '<strong>' + n(d) + '</strong>' : d; } },
      { data: 'fecha_generacion', render: function (d, type) { return type === 'display' ? fechaHora(d) : d; } },
      { data: 'estado', render: function (d, type, row) { return type === 'display' ? estado(d, row.estado_label) : d; } }
    ],
    language: {
      lengthMenu: 'Mostrar _MENU_ registros', zeroRecords: 'No hay backorders con esos filtros',
      emptyTable: 'No hay backorders: todavía no se ha registrado ninguna recepción con faltantes',
      info: 'Mostrando _START_ a _END_ de _TOTAL_ registros', infoEmpty: 'Sin registros disponibles',
      infoFiltered: '(filtrado de _MAX_ registros)', loadingRecords: 'Cargando...', processing: 'Cargando...',
      search: '', paginate: { first:'«', previous:'‹', next:'›', last:'»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); },
    drawCallback: function () { $('#bo-count').text(this.api().rows({ search: 'applied' }).count() + ' registros'); }
  });

  // Filtros por orden, proveedor, producto (nombre/código/ISBN) y estado.
  $.fn.dataTable.ext.search.push(function (settings, data, idx) {
    if (settings.nTable.id !== 'bo-table') return true;
    var b = table.row(idx).data();
    var est = $('#bo-f-estado').val();
    if (est === 'abiertos' && b.estado !== 'pendiente' && b.estado !== 'parcial') return false;
    if (est && est !== 'abiertos' && b.estado !== est) return false;
    function tiene(txt, q) { return !q || String(txt || '').toLowerCase().indexOf(q) >= 0; }
    return tiene(b.orden, $('#bo-f-orden').val().trim().toLowerCase()) &&
           tiene(b.proveedor, $('#bo-f-proveedor').val().trim().toLowerCase()) &&
           tiene(b.producto + ' ' + b.codigo + ' ' + b.isbn, $('#bo-f-producto').val().trim().toLowerCase());
  });
  var t;
  $('#bo-f-orden, #bo-f-proveedor, #bo-f-producto').on('keyup', function () { clearTimeout(t); t = setTimeout(function () { table.draw(); }, 250); });
  $('#bo-f-estado').on('change', function () { table.draw(); });

  // El Excel se genera en el servidor con los mismos filtros que están puestos en pantalla.
  $('#bo-excel').on('click', function (e) {
    e.preventDefault();
    window.location = 'php/oc_backorders_excel.php?' + $.param({
      estado: $('#bo-f-estado').val(), orden: $('#bo-f-orden').val().trim(),
      proveedor: $('#bo-f-proveedor').val().trim(), producto: $('#bo-f-producto').val().trim()
    });
  });

  // Historial del backorder seleccionado.
  $('#bo-table tbody').on('click', 'tr', function () {
    var b = table.row(this).data();
    if (!b) return;
    $('#bo-modal-titulo').text('Historial — ' + b.producto);
    $('#bo-modal-orden').attr('href', 'orden_compra.php?id=' + encodeURIComponent(b.id_orden_wo));
    $('#bo-modal-cuerpo').html('<div style="color:#64748b;"><i class="bi bi-arrow-repeat"></i> Cargando historial…</div>');
    $('#bo-modal').modal('show');
    $.getJSON('php/oc_backorders_listar.php', { id: b.id }).done(function (r) {
      if (!r || !r.ok) { $('#bo-modal-cuerpo').html('<div class="bo-aviso-error">' + h((r && r.error) || 'No se pudo cargar el historial.') + '</div>'); return; }
      var x = r.backorder;
      var html = '<div class="row">' +
        '<div class="col-md-4"><div class="bo-dato-l">Orden de compra</div><div class="bo-dato-v">' + h(x.orden) + '</div></div>' +
        '<div class="col-md-8"><div class="bo-dato-l">Proveedor</div><div class="bo-dato-v">' + h(x.proveedor) + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Código</div><div class="bo-dato-v">' + h(x.codigo) + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Estado</div><div class="bo-dato-v">' + estado(x.estado, x.estado_label) + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Generado</div><div class="bo-dato-v">' + h(fechaHora(x.fecha_generacion)) + (x.usuario_genero ? ' · ' + h(x.usuario_genero) : '') + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Solicitada</div><div class="bo-dato-v">' + n(x.solicitada) + ' ' + h(x.unidad) + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Recibida</div><div class="bo-dato-v">' + n(x.recibida) + '</div></div>' +
        '<div class="col-md-4"><div class="bo-dato-l">Pendiente</div><div class="bo-dato-v">' + n(x.pendiente) + '</div></div></div>';
      html += '<div class="bo-dato-l" style="margin:6px 0;">Recepciones de este producto</div>';
      html += r.movimientos.length
        ? '<table class="bo-mov"><thead><tr><th>Fecha</th><th>Usuario</th><th class="num">Recibida</th><th class="num">Acumulado</th><th class="num">Pendiente después</th><th>Observaciones</th></tr></thead><tbody>' +
          r.movimientos.map(function (m) {
            return '<tr><td>' + h(fechaHora(m.fecha)) + '</td><td>' + h(m.usuario || '—') + '</td><td class="num">' + n(m.cantidad) + '</td><td class="num">' + n(m.acumulado) +
              '</td><td class="num">' + n(m.pendiente) + '</td><td style="font-size:.76rem;color:#64748b;">' + h(m.observaciones) + '</td></tr>';
          }).join('') + '</tbody></table>'
        : '<div style="color:#64748b;">Sin recepciones registradas.</div>';
      $('#bo-modal-cuerpo').html(html);
    }).fail(function () { $('#bo-modal-cuerpo').html('<div class="bo-aviso-error">No se pudo cargar el historial.</div>'); });
  });
});
</script>
</body>
</html>
