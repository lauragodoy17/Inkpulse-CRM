<?php
require_once("php/aut.php");

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    header("Location: index.php");
    exit;
}
$idOrden = intval($_GET['id'] ?? 0);
if ($idOrden <= 0) {
    header("Location: ordenes_compra.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Orden de compra</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .oc-section { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(15,23,42,.08); margin-bottom:20px; overflow:hidden; }
    .oc-section-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 22px; border-bottom:1px solid #e2e8f0; font-size:.95rem; font-weight:700; color:#0f172a; }
    .oc-section-body { padding:18px 22px; }
    .oc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px 22px; }
    .oc-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
    .oc-valor { font-size:.9rem; color:#0f172a; font-weight:600; word-break:break-word; }
    .oc-resumen { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
    .oc-res-card { border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; }
    .oc-res-card .v { font-size:1.35rem; font-weight:800; color:#0f172a; }
    .oc-res-card.pend .v { color:#b45309; }
    .oc-res-card.exc .v { color:#b91c1c; }
    .oc-res-card.rec .v { color:#15803d; }
    .oc-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    .oc-table th { background:#1e40af; color:#fff; font-size:.72rem; font-weight:600; padding:9px 10px; text-align:left; white-space:nowrap; }
    .oc-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .oc-table .num { text-align:right; white-space:nowrap; }
    .oc-table tfoot td { font-weight:700; background:#f8fafc; }
    .oc-table tr.dif td { background:#fffbeb; }
    .oc-table tr.exc td { background:#fef2f2; }
    .oc-cant { width:100px; text-align:right; font-size:.85rem; }
    .oc-cant.invalida { border-color:#dc2626; background:#fef2f2; }
    .oc-aviso { border-radius:10px; padding:11px 15px; font-size:.84rem; margin-bottom:14px; }
    .oc-aviso-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .oc-aviso-warn  { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .oc-aviso-info  { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
    .oc-aviso-ok    { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .oc-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-size:.85rem; font-weight:700; background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border:none; cursor:pointer; text-decoration:none; }
    .oc-btn:hover { opacity:.9; color:#fff; text-decoration:none; }
    .oc-btn:disabled { opacity:.45; cursor:not-allowed; }
    .oc-btn-outline { background:#fff; color:#1d4ed8; border:1.5px solid #1d4ed8; }
    .oc-btn-outline:hover { color:#1d4ed8; }
    .oc-btn-sm { padding:5px 12px; font-size:.78rem; }
    .oc-btn-success { background:linear-gradient(135deg,#15803d,#16a34a); }
    .oc-cargando { text-align:center; padding:40px; color:#64748b; }
    .oc-rec { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; }
    .oc-rec-head { display:flex; gap:14px; flex-wrap:wrap; align-items:center; padding:10px 14px; cursor:pointer; font-size:.84rem; }
    .oc-rec-head:hover { background:#f8fafc; }
    .oc-rec-body { padding:0 14px 12px; display:none; }
    .oc-hidden { display:none; }
    .oc-alerta-exc { color:#b91c1c; font-size:.72rem; font-weight:700; }
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
            <div class="title"><h4 id="oc-titulo">Orden de compra</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="ordenes_compra.php">Órdenes de compra</a></li>
                <li class="breadcrumb-item active" aria-current="page">Detalle y recepción</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <a href="ordenes_compra.php" class="oc-btn oc-btn-outline"><i class="bi bi-arrow-left"></i> Volver al listado</a>
          </div>
        </div>
      </div>

      <?php include("template/oc_tabs.php"); ?>

      <div id="oc-cargando" class="oc-cargando"><i class="bi bi-arrow-repeat"></i> Consultando la orden en World Office…</div>
      <div id="oc-error" class="oc-aviso oc-aviso-error oc-hidden"></div>

      <div id="oc-contenido" class="oc-hidden">
        <!-- Encabezado de la orden (World Office) -->
        <div class="oc-section">
          <div class="oc-section-head"><span><i class="bi bi-file-earmark-text"></i> Datos de la orden (World Office)</span><span id="oc-estado"></span></div>
          <div class="oc-section-body"><div class="oc-grid" id="oc-encabezado"></div></div>
        </div>

        <!-- Resumen de cantidades -->
        <div class="oc-section">
          <div class="oc-section-head"><span><i class="bi bi-bar-chart"></i> Resumen de la recepción</span></div>
          <div class="oc-section-body">
            <div class="oc-resumen" id="oc-resumen"></div>
            <div id="oc-diferencias" style="margin-top:14px;"></div>
          </div>
        </div>

        <!-- Recepción -->
        <div class="oc-section">
          <div class="oc-section-head">
            <span><i class="bi bi-box-arrow-in-down"></i> Registrar recepción</span>
            <span style="display:flex; gap:8px; flex-wrap:wrap;">
              <label style="margin:0; font-size:.8rem; font-weight:500; color:#475569;"><input type="checkbox" id="oc-solo-dif"> Solo productos con diferencias</label>
              <button type="button" class="oc-btn oc-btn-outline oc-btn-sm" id="oc-llenar"><i class="bi bi-magic"></i> Llenar con lo pendiente</button>
              <button type="button" class="oc-btn oc-btn-outline oc-btn-sm" id="oc-ceros"><i class="bi bi-0-circle"></i> Poner 0 en los vacíos</button>
            </span>
          </div>
          <div class="oc-section-body">
            <div id="oc-anulada" class="oc-aviso oc-aviso-error oc-hidden"><i class="bi bi-slash-circle"></i> Esta orden está anulada en World Office; no se pueden registrar recepciones.</div>
            <p style="font-size:.82rem; color:#475569;">Escribe en <strong>"Recibida en esta entrega"</strong> lo que llegó físicamente (0 si no llegó nada de ese producto). Al guardar, lo que siga faltando queda como backorder de la orden.</p>
            <div class="table-responsive">
              <table class="oc-table">
                <thead><tr>
                  <th>Código</th><th>ISBN</th><th>Producto</th><th class="num">Solicitada</th><th class="num">Recibida antes</th>
                  <th class="num">Recibida en esta entrega</th><th class="num">Total recibido</th><th class="num">Pendiente</th><th>Estado</th>
                </tr></thead>
                <tbody id="oc-productos"></tbody>
                <tfoot id="oc-productos-tot"></tfoot>
              </table>
            </div>
            <div class="row" style="margin-top:14px;">
              <div class="col-12" style="display:flex; justify-content:flex-end; padding-bottom:16px;">
                <button type="button" class="oc-btn oc-btn-success" id="oc-guardar"><i class="bi bi-save"></i> Guardar recepción</button>
              </div>
            </div>
            <div id="oc-error-guardar" class="oc-aviso oc-aviso-error oc-hidden"></div>
          </div>
        </div>

        <!-- Historial -->
        <div class="oc-section">
          <div class="oc-section-head"><span><i class="bi bi-clock-history"></i> Historial de recepciones</span></div>
          <div class="oc-section-body" id="oc-historial"></div>
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
<script src="src/ink-alerts.js"></script>
<script>
(function () {
  var ID_ORDEN = <?= json_encode($idOrden) ?>;
  var ESTADOS_PROD = { pendiente: 'Pendiente', parcial: 'Parcial', completado: 'Completado', excedente: 'Excedente' };
  var datos = null;   // respuesta de php/orden_compra_detalle.php
  var token = null;   // único por formulario: si se envía dos veces, el servidor no duplica
  var guardando = false;

  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function n(v) { return (parseFloat(v) || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 }); }
  function fechaHora(f) { if (!f) return '—'; var p = String(f).split(/[- :]/); return p.length >= 5 ? p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4] : f; }
  function nuevoToken() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'oc-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
  }
  function estadoProducto(s, r) { return r > s ? 'excedente' : (r <= 0 ? (s > 0 ? 'pendiente' : 'completado') : (r < s ? 'parcial' : 'completado')); }
  function dato(l, v) { return '<div><div class="oc-label">' + h(l) + '</div><div class="oc-valor">' + v + '</div></div>'; }

  function cargar(mensajeOk) {
    $('#oc-error').addClass('oc-hidden');
    $.getJSON('php/orden_compra_detalle.php', { id: ID_ORDEN }).done(function (r) {
      if (!r || !r.ok) { $('#oc-error').html('<i class="bi bi-exclamation-octagon"></i> ' + h((r && r.error) || 'No se pudo consultar la orden.')).removeClass('oc-hidden'); $('#oc-contenido').addClass('oc-hidden'); return; }
      datos = r;
      token = nuevoToken();
      pintar();
      $('#oc-contenido').removeClass('oc-hidden');
      if (mensajeOk) inkToast(mensajeOk, 'ok');
    }).fail(function () {
      $('#oc-error').html('<i class="bi bi-exclamation-octagon"></i> No se pudo conectar con World Office. Intenta de nuevo.').removeClass('oc-hidden');
    }).always(function () { $('#oc-cargando').addClass('oc-hidden'); });
  }

  function pintar() {
    var o = datos.orden;
    $('#oc-titulo').text('Orden de compra ' + (o.documento || ''));
    document.title = 'Inkpulse - Orden de compra ' + (o.documento || '');
    $('#oc-estado').html('<span class="oc-est oc-est-' + h(o.estado_recepcion) + '">' + h(o.estado_recepcion_label) + '</span>');
    $('#oc-encabezado').html(
      dato('Número de orden', '<strong>' + h(o.documento) + '</strong>' + (o.prefijo ? ' <span style="font-weight:500;color:#64748b;">(prefijo ' + h(o.prefijo) + ')</span>' : '') + (o.anulada ? ' <span class="oc-est oc-est-excedente">Anulada</span>' : '')) +
      dato('Fecha', h(o.fecha || '—')) +
      dato('Proveedor', h(o.proveedor) + (o.nit ? '<div style="font-size:.76rem;font-weight:500;color:#64748b;">NIT ' + h(o.nit) + '</div>' : '')) +
      dato('Empresa', h(o.empresa || '—')) +
      dato('Responsable', h(o.responsable || '—')) +
      dato('Forma de pago', h(o.forma_pago || '—')) +
      dato('Estado de recepción', '<span class="oc-est oc-est-' + h(o.estado_recepcion) + '">' + h(o.estado_recepcion_label) + '</span>') +
      '<div style="grid-column:1/-1;"><div class="oc-label">Concepto</div><div class="oc-valor" style="font-weight:500;">' + h(o.concepto || '—') + '</div></div>');
    pintarResumen();
    pintarProductos();
    pintarHistorial();
    $('#oc-anulada').toggleClass('oc-hidden', !o.anulada);
    $('#oc-guardar, #oc-llenar, #oc-ceros').prop('disabled', o.anulada || !datos.productos.length);
  }

  function pintarResumen() {
    var r = datos.resumen;
    $('#oc-resumen').html(
      '<div class="oc-res-card"><div class="oc-label">Solicitado</div><div class="v">' + n(r.solicitado) + '</div></div>' +
      '<div class="oc-res-card rec"><div class="oc-label">Recibido</div><div class="v">' + n(r.recibido) + '</div></div>' +
      '<div class="oc-res-card pend"><div class="oc-label">Pendiente</div><div class="v">' + n(r.pendiente) + '</div></div>' +
      (r.excedente > 0 ? '<div class="oc-res-card exc"><div class="oc-label">Excedente</div><div class="v">' + n(r.excedente) + '</div></div>' : '') +
      '<div class="oc-res-card"><div class="oc-label">Entregas registradas</div><div class="v">' + datos.recepciones.length + '</div></div>');
    // Productos con diferencias (solo tiene sentido después de alguna recepción).
    var dif = datos.recepciones.length ? datos.productos.filter(function (p) { return p.estado !== 'completado'; }) : [];
    $('#oc-diferencias').html(!datos.recepciones.length
      ? '<div class="oc-aviso oc-aviso-info" style="margin:0;"><i class="bi bi-info-circle"></i> Todavía no se ha registrado ninguna recepción de esta orden.</div>'
      : (!dif.length ? '<div class="oc-aviso oc-aviso-ok" style="margin:0;"><i class="bi bi-check-circle"></i> Todos los productos se recibieron en las cantidades solicitadas.</div>'
        : '<div class="oc-aviso oc-aviso-warn" style="margin:0;"><strong><i class="bi bi-exclamation-triangle"></i> Productos con diferencias:</strong><ul style="margin:6px 0 0 18px;">' +
          dif.map(function (p) {
            return '<li><strong>' + h(p.descripcion) + '</strong>: ' + (p.estado === 'excedente'
              ? 'se recibieron ' + n(p.excedente) + ' de más (revisar)' : 'faltan ' + n(p.pendiente) + ' de ' + n(p.cantidad)) + '</li>';
          }).join('') + '</ul></div>'));
  }

  function pintarProductos() {
    var html = '';
    datos.productos.forEach(function (p, i) {
      html += '<tr data-i="' + i + '">' +
        '<td>' + h(p.codigo || '—') + '</td><td>' + h(p.isbn || '—') + '</td>' +
        '<td>' + h(p.descripcion) + (p.bodega ? '<div style="font-size:.72rem;color:#94a3b8;">Bodega ' + h(p.bodega) + '</div>' : '') + '</td>' +
        '<td class="num">' + n(p.cantidad) + ' ' + h(p.unidad) + '</td>' +
        '<td class="num">' + n(p.recibido) + '</td>' +
        '<td class="num"><input type="number" min="0" step="' + (p.entero ? '1' : '0.01') + '" class="form-control form-control-sm oc-cant" data-i="' + i + '" placeholder="0"></td>' +
        '<td class="num oc-total-rec"></td><td class="num oc-pend"></td><td class="oc-est-cel"></td></tr>';
    });
    $('#oc-productos').html(html || '<tr><td colspan="9" style="text-align:center;color:#64748b;padding:20px;">La orden no tiene productos en World Office.</td></tr>');
    recalcular();
  }

  // Recalcula en vivo total recibido, pendiente y estado de cada fila con lo que se está escribiendo.
  function recalcular() {
    var tot = { s: 0, antes: 0, ahora: 0, rec: 0, pend: 0 };
    datos.productos.forEach(function (p, i) {
      var $in = $('.oc-cant[data-i="' + i + '"]'), v = $in.val();
      var ahora = v === '' ? 0 : parseFloat(v);
      var valido = v === '' || (!isNaN(ahora) && ahora >= 0 && (!p.entero || Math.floor(ahora) === ahora));
      $in.toggleClass('invalida', !valido);
      if (!valido) ahora = 0;
      var total = Math.round((p.recibido + ahora) * 100) / 100;
      var pend = Math.max(0, Math.round((p.cantidad - total) * 100) / 100);
      var est = estadoProducto(p.cantidad, total);
      var $tr = $in.closest('tr');
      $tr.find('.oc-total-rec').text(n(total));
      $tr.find('.oc-pend').text(n(pend));
      $tr.find('.oc-est-cel').html('<span class="oc-est oc-est-' + est + '">' + ESTADOS_PROD[est] + '</span>' +
        (est === 'excedente' ? '<div class="oc-alerta-exc"><i class="bi bi-exclamation-triangle"></i> Excede en ' + n(total - p.cantidad) + '</div>' : ''));
      $tr.toggleClass('dif', est === 'parcial' || est === 'pendiente').toggleClass('exc', est === 'excedente');
      $tr.toggle(!$('#oc-solo-dif').is(':checked') || est !== 'completado');
      tot.s += p.cantidad; tot.antes += p.recibido; tot.ahora += ahora; tot.rec += total; tot.pend += pend;
    });
    if (datos.productos.length) {
      $('#oc-productos-tot').html('<tr><td colspan="3">Total</td><td class="num">' + n(tot.s) + '</td><td class="num">' + n(tot.antes) + '</td><td class="num">' + n(tot.ahora) +
        '</td><td class="num">' + n(tot.rec) + '</td><td class="num">' + n(tot.pend) + '</td><td></td></tr>');
    }
  }

  function pintarHistorial() {
    if (!datos.recepciones.length) { $('#oc-historial').html('<div style="color:#64748b;font-size:.85rem;">Sin recepciones registradas.</div>'); return; }
    $('#oc-historial').html(datos.recepciones.map(function (r, i) {
      return '<div class="oc-rec"><div class="oc-rec-head" data-i="' + i + '">' +
        '<strong>Entrega ' + (datos.recepciones.length - i) + '</strong><span>' + h(fechaHora(r.fecha)) + '</span><span><i class="bi bi-person"></i> ' + h(r.usuario || '—') + '</span>' +
        '<span><strong>' + n(r.total_unidades) + '</strong> unidades</span>' + (r.observaciones ? '<span style="color:#64748b;">' + h(r.observaciones) + '</span>' : '') +
        '<span style="margin-left:auto;color:#1d4ed8;font-size:.78rem;">Ver detalle <i class="bi bi-chevron-down"></i></span></div>' +
        '<div class="oc-rec-body" data-i="' + i + '"><table class="oc-table"><thead><tr><th>Código</th><th>Producto</th><th class="num">Solicitada</th><th class="num">Recibida en esta entrega</th><th class="num">Acumulado después</th></tr></thead><tbody>' +
        r.items.map(function (it) { return '<tr><td>' + h(it.codigo) + '</td><td>' + h(it.descripcion) + '</td><td class="num">' + n(it.solicitado) + '</td><td class="num">' + n(it.cantidad) + '</td><td class="num">' + n(it.acumulado) + '</td></tr>'; }).join('') +
        '</tbody></table></div></div>';
    }).join(''));
  }

  $(document).on('input', '.oc-cant', recalcular);
  $('#oc-solo-dif').on('change', recalcular);
  $(document).on('click', '.oc-rec-head', function () { $('.oc-rec-body[data-i="' + $(this).data('i') + '"]').slideToggle(150); });
  $('#oc-llenar').on('click', function () {
    datos.productos.forEach(function (p, i) { $('.oc-cant[data-i="' + i + '"]').val(Math.max(0, Math.round((p.cantidad - p.recibido) * 100) / 100)); });
    recalcular();
  });
  $('#oc-ceros').on('click', function () {
    $('.oc-cant').each(function () { if (this.value === '') this.value = 0; });
    recalcular();
  });

  $('#oc-guardar').on('click', function () {
    if (guardando || !datos) return;
    $('#oc-error-guardar').addClass('oc-hidden');
    var cantidades = {}, faltan = [], invalidas = [], unidades = 0, pendientes = [], excedentes = [];
    datos.productos.forEach(function (p, i) {
      var v = $('.oc-cant[data-i="' + i + '"]').val();
      if (v === '') { faltan.push(p.descripcion); return; }
      var c = parseFloat(v);
      if (isNaN(c) || c < 0 || (p.entero && Math.floor(c) !== c)) { invalidas.push(p.descripcion); return; }
      cantidades[p.clave] = v;
      unidades += c;
      var total = p.recibido + c;
      if (total < p.cantidad) pendientes.push(p.descripcion + ' (faltan ' + n(p.cantidad - total) + ')');
      if (total > p.cantidad) excedentes.push(p.descripcion + ' (+' + n(total - p.cantidad) + ')');
    });
    if (invalidas.length) { $('#oc-error-guardar').text('Cantidad no válida (debe ser un número entero igual o mayor a 0) en: ' + invalidas.join(', ') + '.').removeClass('oc-hidden'); return; }
    if (faltan.length) { $('#oc-error-guardar').text('Escribe la cantidad recibida de todos los productos (0 si no llegó). Faltan: ' + faltan.join(', ') + '. Puedes usar "Poner 0 en los vacíos".').removeClass('oc-hidden'); return; }

    // inkConfirm inserta título y texto como HTML: todo lo que viene de WO va escapado.
    var texto = 'Se registrarán <strong>' + n(unidades) + '</strong> unidades recibidas.';
    if (pendientes.length) texto += '<br><br><strong>Quedarán como backorder:</strong> ' + h(pendientes.join('; ')) + '.';
    if (excedentes.length) texto += '<br><br><strong>Recepción excedente</strong> (quedará marcada para revisión): ' + h(excedentes.join('; ')) + '.';
    inkConfirm({ title: 'Guardar recepción de la orden ' + h(datos.orden.documento), text: texto, type: excedentes.length ? 'warning' : 'info', btnOk: 'Guardar recepción' }, function () {
      guardando = true;
      var $btn = $('#oc-guardar').prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Guardando…');
      $.ajax({
        url: 'php/orden_compra_recepcion_guardar.php', method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: ID_ORDEN, token: token, version: datos.version, cantidades: cantidades })
      }).done(function (r) {
        if (r && r.ok) {
          cargar('Recepción guardada. Estado de la orden: ' + r.estado_recepcion_label + (r.backorders_nuevos ? '. Se generaron ' + r.backorders_nuevos + ' backorder(s).' : '.'));
          return;
        }
        // Ya guardada (doble envío): se recarga para mostrar lo que quedó, sin volver a registrar.
        if (r && r.ya_guardada) { cargar(); inkToast(h(r.error), 'warn'); return; }
        $('#oc-error-guardar').text((r && r.error) || 'No se pudo guardar la recepción.').removeClass('oc-hidden');
      }).fail(function () {
        $('#oc-error-guardar').text('No se pudo guardar la recepción (sin conexión con el servidor). No se registró ningún cambio; intenta de nuevo.').removeClass('oc-hidden');
      }).always(function () {
        guardando = false;
        $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Guardar recepción');
      });
    });
  });

  cargar();
})();
</script>
</body>
</html>
