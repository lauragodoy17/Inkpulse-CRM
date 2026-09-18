<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Planillas generadas</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .pp-section { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.08); padding: 24px; margin-bottom: 20px; }
    .pp-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 18px; border-radius: 8px; font-size: .85rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer; text-decoration: none; height: 31px;
    }
    .pp-btn:hover { opacity: .9; color: #fff; text-decoration: none; }
    .pp-resumen-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    @media (max-width: 860px) { .pp-resumen-grid { grid-template-columns: 1fr 1fr; } }
    .pp-resumen-card { border-radius: 10px; padding: 14px 16px; text-align: center; background: #f8fafc; }
    .pp-resumen-card .num { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
    .pp-resumen-card .lbl { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #64748b; margin-top: 2px; }
    .pp-resumen-card.venta { background: #eff6ff; } .pp-resumen-card.venta .num { color: #1d4ed8; }
    .pp-resumen-card.muestreo { background: #f5f3ff; } .pp-resumen-card.muestreo .num { color: #7c3aed; }
    .pp-resumen-card.sa { background: #f0fdf4; } .pp-resumen-card.sa .num { color: #16a34a; }

    .pp-filtros { display: flex; gap: 16px; flex-wrap: wrap; align-items: end; margin-bottom: 18px; }
    .pp-filtros label { display: block; font-size: .75rem; color: #64748b; margin-bottom: 4px; }
    .pp-filtros select, .pp-filtros input { min-width: 170px; }

    table.pp-tabla { width: 100%; border-collapse: collapse; font-size: .85rem; }
    table.pp-tabla th, table.pp-tabla td { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; }
    table.pp-tabla thead th { background: #eef2ff; color: #1e3a8a; font-weight: 700; }
    table.pp-tabla td.pp-num { text-align: center; font-variant-numeric: tabular-nums; }
    table.pp-tabla tbody tr.pp-fila-planilla { cursor: pointer; }
    table.pp-tabla tbody tr.pp-fila-planilla:hover { background: #f8fafc; }
    .pp-link-pdf { color: #dc2626; font-size: 1.1rem; text-decoration: none; }
    .pp-link-pdf:hover { color: #991b1b; }

    .pp-badge-tipo { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: 700; }
    .pp-badge-venta { background: #dbeafe; color: #1e40af; }
    .pp-badge-muestreo { background: #ede9fe; color: #5b21b6; }
    .pp-badge-sa { background: #dcfce7; color: #15803d; }

    .pp-fila-detalle td { background: #f8fafc; padding: 0; }
    .pp-fila-detalle .pp-detalle-wrap { padding: 12px 20px; }
    table.pp-detalle-tabla { width: 100%; border-collapse: collapse; font-size: .8rem; }
    table.pp-detalle-tabla th, table.pp-detalle-tabla td { border: 1px solid #e2e8f0; padding: 6px 10px; }
    table.pp-detalle-tabla thead th { background: #fff; color: #64748b; font-weight: 700; }

    .pp-vacio { color: #64748b; font-size: .9rem; padding: 40px 0; text-align: center; }
    .pp-cargando { color: #64748b; font-size: .8rem; padding: 10px 20px; }
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
            <div class="title"><h4>Planillas generadas</h4></div>
          </div>
        </div>
      </div>

      <div class="pp-section">
        <div class="pp-resumen-grid">
          <div class="pp-resumen-card"><div class="num" id="pp-total-todas">0</div><div class="lbl">Total planillas</div></div>
          <div class="pp-resumen-card venta"><div class="num" id="pp-total-venta">0</div><div class="lbl">Pedidos de venta</div></div>
          <div class="pp-resumen-card muestreo"><div class="num" id="pp-total-muestreo">0</div><div class="lbl">Muestras</div></div>
          <div class="pp-resumen-card sa"><div class="num" id="pp-total-sa">0</div><div class="lbl">Pedidos sin adopción</div></div>
        </div>

        <div class="pp-filtros">
          <div>
            <label>Desde</label>
            <input type="date" id="pp-desde" class="form-control form-control-sm">
          </div>
          <div>
            <label>Hasta</label>
            <input type="date" id="pp-hasta" class="form-control form-control-sm">
          </div>
          <div>
            <label>Tipo</label>
            <select id="pp-tipo" class="form-control form-control-sm">
              <option value="">Todos</option>
              <option value="venta">Pedidos de venta</option>
              <option value="muestreo">Muestras</option>
              <option value="sa">Pedidos sin adopción</option>
            </select>
          </div>
          <div>
            <!-- El href se actualiza en JS cada vez que cambian los filtros, para que la
                 descarga siempre respete lo mismo que se está viendo en pantalla. -->
            <a href="php/planillas_procesamiento_excel.php" id="pp-link-excel" class="pp-btn">
              <i class="bi bi-file-earmark-excel"></i> Descargar Excel
            </a>
          </div>
        </div>

        <div class="table-responsive">
          <table class="pp-tabla" id="pp-tabla">
            <thead>
              <tr>
                <th>Consecutivo</th>
                <th>Tipo</th>
                <th>Fecha de generación</th>
                <th>Generada por</th>
                <th class="pp-num"># Pedidos</th>
                <th class="pp-num">PDF</th>
              </tr>
            </thead>
            <tbody id="pp-tbody"></tbody>
          </table>
        </div>
        <div class="pp-vacio" id="pp-vacio" style="display:none;">No hay planillas generadas para este filtro.</div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script>
(function () {
  var BADGE_CLASE = { venta: 'pp-badge-venta', muestreo: 'pp-badge-muestreo', sa: 'pp-badge-sa' };

  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function fechaCorta(v) {
    if (!v) return '—';
    var d = new Date(v.replace(' ', 'T'));
    if (isNaN(d.getTime())) return v;
    return d.toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function leerFiltros() {
    var params = {};
    if (document.getElementById('pp-desde').value) params.desde = document.getElementById('pp-desde').value;
    if (document.getElementById('pp-hasta').value) params.hasta = document.getElementById('pp-hasta').value;
    if (document.getElementById('pp-tipo').value) params.tipo = document.getElementById('pp-tipo').value;
    return params;
  }

  function actualizarEnlaceExcel() {
    var params = leerFiltros();
    var qs = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    document.getElementById('pp-link-excel').href = 'php/planillas_procesamiento_excel.php' + (qs ? '?' + qs : '');
  }

  function cargarTabla() {
    $.getJSON('php/planillas_procesamiento_tabla.php', leerFiltros(), function (resp) {
      if (!resp.success) return;
      renderTabla(resp.filas || []);
      renderResumen(resp.filas || []);
    });
  }

  function renderResumen(filas) {
    var totales = { venta: 0, muestreo: 0, sa: 0 };
    filas.forEach(function (f) { if (totales[f.tipo] !== undefined) totales[f.tipo]++; });
    document.getElementById('pp-total-todas').textContent = filas.length;
    document.getElementById('pp-total-venta').textContent = totales.venta;
    document.getElementById('pp-total-muestreo').textContent = totales.muestreo;
    document.getElementById('pp-total-sa').textContent = totales.sa;
  }

  function renderTabla(filas) {
    var $tbody = $('#pp-tbody');
    if (!filas.length) {
      $tbody.html('');
      $('#pp-vacio').show();
      return;
    }
    $('#pp-vacio').hide();

    $tbody.html(filas.map(function (f) {
      var claseBadge = BADGE_CLASE[f.tipo] || '';
      var urlPdf = 'php/planilla_procesamiento_pdf.php?tipo=' + encodeURIComponent(f.tipo) + '&id=' + f.id;
      return '<tr class="pp-fila-planilla" data-tipo="' + h(f.tipo) + '" data-id="' + f.id + '">' +
        '<td>#' + h(String(f.id).padStart(6, '0')) + '</td>' +
        '<td><span class="pp-badge-tipo ' + claseBadge + '">' + h(f.tipo_label) + '</span></td>' +
        '<td>' + fechaCorta(f.fecha_generacion) + '</td>' +
        '<td>' + h(f.usuario) + '</td>' +
        '<td class="pp-num">' + f.cantidad_pedidos + '</td>' +
        '<td class="pp-num">' +
          '<a href="' + urlPdf + '" class="pp-link-pdf" title="Descargar planilla en PDF" onclick="event.stopPropagation();">' +
          '<i class="bi bi-file-earmark-pdf"></i></a>' +
        '</td>' +
        '</tr>' +
        '<tr class="pp-fila-detalle" data-detalle-de="' + f.tipo + '-' + f.id + '" style="display:none;"><td colspan="6"></td></tr>';
    }).join(''));
  }

  $('#pp-tbody').on('click', '.pp-fila-planilla', function () {
    var $fila = $(this);
    var tipo = $fila.data('tipo');
    var id = $fila.data('id');
    var $filaDetalle = $fila.next('.pp-fila-detalle');
    var $celda = $filaDetalle.find('td');

    if ($filaDetalle.is(':visible')) {
      $filaDetalle.hide();
      return;
    }
    // Colapsa cualquier otro detalle abierto, para no acumular varias tablas largas en pantalla.
    $('.pp-fila-detalle').not($filaDetalle).hide();

    if (!$filaDetalle.data('cargado')) {
      $celda.html('<div class="pp-cargando">Cargando...</div>');
      $filaDetalle.show();
      $.getJSON('php/planillas_procesamiento_detalle.php', { tipo: tipo, id: id }, function (resp) {
        if (!resp.success || !resp.detalle || !resp.detalle.length) {
          $celda.html('<div class="pp-cargando">Sin datos de detalle disponibles.</div>');
          return;
        }
        var filasHtml = resp.detalle.map(function (d) {
          return '<tr><td>#' + h(d.id) + '</td><td>' + h(d.colegio || '—') + '</td><td>' + h(d.responsable || '—') + '</td><td>' + h(d.fecha || '—') + '</td></tr>';
        }).join('');
        $celda.html(
          '<div class="pp-detalle-wrap"><table class="pp-detalle-tabla"><thead><tr>' +
          '<th>#</th><th>Colegio</th><th>Responsable</th><th>Fecha</th>' +
          '</tr></thead><tbody>' + filasHtml + '</tbody></table></div>'
        );
        $filaDetalle.data('cargado', true);
      });
    } else {
      $filaDetalle.show();
    }
  });

  $('#pp-desde, #pp-hasta, #pp-tipo').on('change', function () { actualizarEnlaceExcel(); cargarTabla(); });
  actualizarEnlaceExcel();
  cargarTabla();
})();
</script>
</body>
</html>
