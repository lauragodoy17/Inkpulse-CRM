<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}

require_once("conexion/bdd.php");
require_once("includes/listas_empaque_datos.php");
le_crear_tablas($bdd);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Nueva lista de empaque</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <style>
    .le-section { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(15,23,42,.08); margin-bottom:20px; overflow:hidden; }
    .le-section-head { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:16px 24px; border-bottom:1px solid #e2e8f0; }
    .le-section-title { font-size:.95rem; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:10px; }
    .le-paso { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%; background:#1d4ed8; color:#fff; font-size:.8rem; font-weight:700; }
    .le-section-body { padding:20px 24px; }
    .le-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-size:.85rem; font-weight:700; background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border:none; cursor:pointer; text-decoration:none; }
    .le-btn:hover { opacity:.9; color:#fff; text-decoration:none; }
    .le-btn:disabled { opacity:.45; cursor:not-allowed; }
    .le-btn-outline { background:#fff; color:#1d4ed8; border:1.5px solid #1d4ed8; }
    .le-btn-outline:hover { color:#1d4ed8; }
    .le-btn-sm { padding:5px 12px; font-size:.78rem; }
    .le-btn-danger { background:#fff; color:#dc2626; border:1.5px solid #fecaca; }
    .le-btn-success { background:linear-gradient(135deg,#15803d,#16a34a); }
    .select2-container .select2-selection--single { height:38px !important; border:1px solid #ced4da !important; }
    .select2-container .select2-selection__rendered { line-height:36px !important; font-size:.9rem; }
    .select2-container .select2-selection__arrow { height:36px !important; }
    /* Clientes elegidos (select2 múltiple): el gris por defecto con letra blanca casi no se lee. */
    .le-clientes .select2-selection--multiple { min-height:38px; border:1px solid #ced4da !important; }
    .le-clientes .select2-selection--multiple .select2-selection__choice { background:#dbeafe !important; border:1px solid #93c5fd !important; color:#1e3a8a !important; font-size:.82rem; font-weight:600; border-radius:6px; padding:2px 8px; }
    .le-clientes .select2-selection--multiple .select2-selection__choice__remove { color:#1d4ed8 !important; font-weight:800; margin-right:6px; }
    .le-clientes .select2-selection--multiple .select2-selection__choice__remove:hover { color:#dc2626 !important; }
    .le-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px 20px; }
    .le-dato-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
    .le-dato-valor { font-size:.9rem; color:#0f172a; font-weight:600; word-break:break-word; }
    .le-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    .le-table th { background:#f8fafc; color:#64748b; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; }
    .le-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .le-table .num { text-align:right; white-space:nowrap; }
    .le-table tfoot td { font-weight:700; background:#f8fafc; }
    .le-aviso { border-radius:10px; padding:11px 15px; font-size:.84rem; margin-bottom:14px; }
    .le-aviso-warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .le-aviso-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
    .le-aviso-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .le-aviso-ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .le-badge { display:inline-block; font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:999px; white-space:nowrap; }
    .le-badge-rem { background:#dcfce7; color:#15803d; }
    .le-badge-fv { background:#dbeafe; color:#1d4ed8; }
    .le-badge-gris { background:#f1f5f9; color:#475569; }
    .le-badge-rojo { background:#fee2e2; color:#b91c1c; }
    .le-dif-pos { color:#b45309; font-weight:700; }
    .le-dif-neg { color:#dc2626; font-weight:700; }
    .le-dif-ok { color:#16a34a; font-weight:700; }
    .le-doc-items { background:#f8fafc; }
    .le-doc-items td { font-size:.78rem; color:#475569; padding:5px 10px 5px 40px; }
    .le-caja { border:1.5px solid #e2e8f0; border-radius:12px; margin-bottom:14px; }
    .le-caja-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:12px 12px 0 0; }
    .le-caja-head strong { font-size:.92rem; color:#0f172a; }
    .le-caja-body { padding:10px 14px; }
    .le-caja-body select, .le-caja-body input, .le-caja-head input { font-size:.82rem; }
    .le-cant { width:90px; text-align:right; }
    .le-cargando { text-align:center; padding:30px; color:#64748b; font-size:.9rem; }
    .le-preview { border:1px solid #e2e8f0; border-radius:10px; padding:26px; background:#fff; }
    .le-pv-head { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; border-bottom:2px solid #1d4ed8; padding-bottom:14px; margin-bottom:16px; }
    .le-pv-titulo { font-size:1.3rem; font-weight:800; color:#1d4ed8; margin:2px 0 0; }
    .le-pv-num { border:1px solid #bfdbfe; background:#eff6ff; border-radius:8px; padding:8px 16px; text-align:center; }
    .le-pv-sec { font-size:.75rem; font-weight:800; color:#1d4ed8; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #bfdbfe; margin:18px 0 10px; padding-bottom:4px; }
    .le-hidden { display:none; }
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
            <div class="title"><h4>Nueva lista de empaque</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="listas_empaque.php">Listas de empaque</a></li>
                <li class="breadcrumb-item active" aria-current="page">Nueva</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <a href="listas_empaque.php" class="le-btn le-btn-outline"><i class="bi bi-clock-history"></i> Historial</a>
          </div>
        </div>
      </div>

      <!-- Paso 1: cliente y sus remisiones/facturas -->
      <div class="le-section">
        <div class="le-section-head"><p class="le-section-title"><span class="le-paso">1</span> Seleccionar los clientes y sus remisiones o facturas</p></div>
        <div class="le-section-body">
          <div class="row">
            <div class="col-md-8 form-group le-clientes">
              <label class="le-dato-label">Clientes (puedes elegir varios)</label>
              <select id="le-cliente-filtro" style="width:100%" multiple></select>
            </div>
          </div>
          <div id="le-cargando" class="le-cargando le-hidden"><i class="bi bi-arrow-repeat"></i> <span id="le-cargando-texto"></span></div>
          <div id="le-error-pedido" class="le-aviso le-aviso-error le-hidden" style="margin-top:14px;"></div>
          <div id="le-lista-cliente"></div>
        </div>
      </div>

      <!-- Paso 2: documentos elegidos y cruce -->
      <div class="le-section le-hidden" id="le-sec-docs">
        <div class="le-section-head"><p class="le-section-title"><span class="le-paso">2</span> Documentos elegidos y sus productos</p></div>
        <div class="le-section-body">
          <div id="le-docs"></div>
          <div id="le-cruce" style="margin-top:18px;"></div>
          <div style="margin-top:16px; text-align:right;">
            <button type="button" class="le-btn" id="le-ir-cajas" disabled><i class="bi bi-box-seam"></i> Continuar a distribución por cajas</button>
          </div>
        </div>
      </div>

      <!-- Paso 3: cajas -->
      <div class="le-section le-hidden" id="le-sec-cajas">
        <div class="le-section-head">
          <p class="le-section-title"><span class="le-paso">3</span> Distribución por cajas</p>
          <button type="button" class="le-btn le-btn-outline le-btn-sm" id="le-agregar-caja"><i class="bi bi-plus-lg"></i> Agregar caja</button>
        </div>
        <div class="le-section-body">
          <div class="row">
            <div class="col-lg-7">
              <div id="le-cajas"></div>
            </div>
            <div class="col-lg-5">
              <p class="le-dato-label" style="margin-bottom:6px;">Control de cantidades</p>
              <div id="le-control"></div>
              <p class="le-dato-label" style="margin:16px 0 6px;">Resumen de la distribución</p>
              <div id="le-resumen-cajas"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Paso 4: datos logísticos -->
      <div class="le-section le-hidden" id="le-sec-datos">
        <div class="le-section-head"><p class="le-section-title"><span class="le-paso">4</span> Datos del cliente y logísticos</p></div>
        <div class="le-section-body">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="le-dato-label">Cliente / empresa (World Office)</label>
              <input type="text" class="form-control form-control-sm" id="le-cliente" readonly>
            </div>
            <div class="col-md-6 form-group">
              <label class="le-dato-label">Ciudad</label>
              <input type="text" class="form-control form-control-sm" id="le-ciudad" maxlength="150">
              <small class="text-muted">Se sugiere la de World Office; puedes cambiarla.</small>
            </div>
            <div class="col-md-12 form-group">
              <label class="le-dato-label">Dirección de entrega *</label>
              <textarea class="form-control form-control-sm" id="le-direccion" rows="2" maxlength="500" autocomplete="off"></textarea>
            </div>
            <div class="col-md-12 form-group">
              <label class="le-dato-label">Persona que recibe</label>
              <input type="text" class="form-control form-control-sm" id="le-persona-recibe" maxlength="150" autocomplete="off">
            </div>
            <div class="col-md-4 form-group">
              <label class="le-dato-label">Peso neto total (kg) — opcional</label>
              <input type="number" class="form-control form-control-sm" id="le-peso-neto" min="0" step="0.01" placeholder="Opcional">
              <small class="text-muted" id="le-peso-ayuda"></small>
            </div>
            <div class="col-md-8 form-group">
              <label class="le-dato-label">Empacado por *</label>
              <input type="text" class="form-control form-control-sm" id="le-empacado-por" maxlength="150">
            </div>
          </div>
          <div id="le-error-final" class="le-aviso le-aviso-error le-hidden"></div>
          <div style="text-align:right;">
            <button type="button" class="le-btn" id="le-vista-previa"><i class="bi bi-eye"></i> Vista previa</button>
          </div>
        </div>
      </div>

      <!-- Paso 5: vista previa y generación -->
      <div class="le-section le-hidden" id="le-sec-preview">
        <div class="le-section-head">
          <p class="le-section-title"><span class="le-paso">5</span> Vista previa</p>
          <div style="display:flex; gap:10px;">
            <button type="button" class="le-btn le-btn-outline le-btn-sm" id="le-corregir"><i class="bi bi-pencil"></i> Corregir</button>
            <button type="button" class="le-btn le-btn-success" id="le-generar"><i class="bi bi-file-earmark-pdf"></i> Generar lista de empaque</button>
          </div>
        </div>
        <div class="le-section-body">
          <div id="le-resultado" class="le-hidden"></div>
          <div id="le-preview"></div>
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
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
(function () {
  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function n(v) { v = parseFloat(v) || 0; return (v % 1 === 0 ? v : v.toFixed(2)).toLocaleString('es-CO'); }
  function fecha(f) { if (!f) return '—'; var p = String(f).substr(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : f; }

  var datos = null;        // respuesta de php/lista_empaque_documentos.php (documentos elegidos)
  var despachado = {};     // id_inventario => {id_inventario, codigo, descripcion, cantidad}
  var cajas = [];          // [{peso:'', items:[{id_inventario, cantidad}]}]
  var guardada = false;

  // ── Paso 1: cliente y sus remisiones/facturas ──────────────────
  var idiomaSelect2 = { noResults: function () { return 'Sin resultados'; }, searching: function () { return 'Buscando…'; } };
  $('#le-cliente-filtro').select2({
    placeholder: 'Escribe el nombre o el documento del cliente…',
    multiple: true, closeOnSelect: true, width: '100%', minimumInputLength: 0,
    ajax: {
      url: 'php/lista_empaque_buscar_cliente.php', dataType: 'json', delay: 250,
      data: function (p) { return { q: p.term || '' }; },
      processResults: function (d) {
        return { results: (d || []).map(function (c) { return { id: c.id, text: c.cliente + (c.documento ? ' — ' + c.documento : '') }; }) };
      }
    },
    language: idiomaSelect2
  });
  var idsClientes = [];    // clientes elegidos en el selector
  var docsCliente = [];    // todas las REM/FV de esos clientes en WO (php/lista_empaque_documentos_cliente.php)
  var elegidos = {};       // clave => true, las que el usuario marcó
  var consultaClientes = 0; // para descartar respuestas viejas si cambian los clientes mientras carga

  function cargando(texto) {
    $('#le-cargando-texto').text(texto || '');
    $('#le-cargando').toggleClass('le-hidden', !texto);
  }

  // Al agregar o quitar un cliente se vuelven a traer los documentos de todos los elegidos; lo que ya
  // estaba marcado se conserva si sigue en la lista.
  $('#le-cliente-filtro').on('change', function () {
    reiniciar();
    idsClientes = ($(this).val() || []).map(String);
    var consulta = ++consultaClientes;
    if (!idsClientes.length) { docsCliente = []; elegidos = {}; $('#le-lista-cliente').html(''); cargando(''); return; }
    cargando('Consultando las remisiones y facturas de ' + (idsClientes.length > 1 ? idsClientes.length + ' clientes' : 'el cliente') + ' en World Office…');
    $.getJSON('php/lista_empaque_documentos_cliente.php', { clientes: idsClientes.join(',') }).done(function (r) {
      if (consulta !== consultaClientes) return;
      if (!r.ok) { $('#le-error-pedido').text(r.error || 'No se pudieron consultar los documentos.').removeClass('le-hidden'); $('#le-lista-cliente').html(''); return; }
      docsCliente = r.documentos;
      var siguen = {};
      docsCliente.forEach(function (d) { if (elegidos[d.clave] && !d.bloqueo) siguen[d.clave] = true; });
      elegidos = siguen;
      if (!docsCliente.length) {
        $('#le-lista-cliente').html('');
        $('#le-error-pedido').text('World Office no tiene remisiones ni facturas a nombre de ' + (idsClientes.length > 1 ? 'estos clientes.' : 'este cliente.')).removeClass('le-hidden');
        return;
      }
      pintarListaCliente();
    }).fail(function () {
      if (consulta !== consultaClientes) return;
      $('#le-error-pedido').text('No se pudo consultar World Office. Intenta de nuevo.').removeClass('le-hidden');
    }).always(function () { if (consulta === consultaClientes) cargando(''); });
  });

  function variosClientes() { return idsClientes.length > 1; }

  function pintarListaCliente() {
    var usables = docsCliente.filter(function (d) { return !d.bloqueo; }).length;
    var html = '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:6px 0 10px;">' +
      '<input type="text" id="le-filtro-docs" class="form-control form-control-sm" style="max-width:340px;" placeholder="Filtrar por número, cliente o concepto…">' +
      '<span style="font-size:.8rem; color:#64748b;">' + docsCliente.length + ' documento(s) en World Office · ' + usables + ' disponible(s)</span></div>' +
      '<div class="table-responsive" style="max-height:420px; overflow:auto;"><table class="le-table"><thead><tr>' +
      '<th><input type="checkbox" id="le-marcar-todos" title="Marcar todos los visibles"></th><th>Tipo</th><th>Documento</th><th>Fecha</th>' +
      (variosClientes() ? '<th>Cliente</th>' : '') + '<th>Concepto</th></tr></thead><tbody>';
    docsCliente.forEach(function (d) {
      var texto = (d.tipo_label + ' ' + d.documento + ' ' + d.cliente_crm + ' ' + d.concepto).toLowerCase();
      html += '<tr class="le-fila-doc" data-texto="' + h(texto) + '"' + (d.bloqueo ? ' style="opacity:.55;"' : '') + '>' +
        '<td><input type="checkbox" class="le-elegir" value="' + h(d.clave) + '"' + (d.bloqueo ? ' disabled' : (elegidos[d.clave] ? ' checked' : '')) + '></td>' +
        '<td><span class="le-badge ' + (d.tipo === 'REM' ? 'le-badge-rem' : 'le-badge-fv') + '">' + h(d.tipo_label) + '</span></td>' +
        '<td><strong>' + h(d.documento) + '</strong>' + (d.bloqueo ? ' <span class="le-badge le-badge-gris">' + h(d.bloqueo) + '</span>' : '') + '</td>' +
        '<td style="white-space:nowrap;">' + fecha(d.fecha) + '</td>' +
        (variosClientes() ? '<td style="font-size:.8rem;">' + h(d.cliente_crm) + '</td>' : '') +
        '<td style="font-size:.74rem; color:#64748b;">' + h(d.concepto) + '</td></tr>';
    });
    html += '</tbody></table></div>' +
      '<div style="margin-top:12px; display:flex; justify-content:flex-end; align-items:center; gap:12px;">' +
      '<span id="le-contador" style="font-size:.84rem; color:#475569;"></span>' +
      '<button type="button" class="le-btn" id="le-continuar" disabled><i class="bi bi-arrow-right"></i> Continuar con los seleccionados</button></div>';
    $('#le-lista-cliente').html(html);
    actualizarContador();
  }

  function clavesElegidas() { return docsCliente.filter(function (d) { return elegidos[d.clave]; }).map(function (d) { return d.clave; }); }
  function actualizarContador() {
    var c = clavesElegidas().length;
    $('#le-contador').text(c ? c + ' seleccionado(s)' : 'Marca una o varias remisiones o facturas');
    $('#le-continuar').prop('disabled', !c);
  }
  $(document).on('input', '#le-filtro-docs', function () {
    var q = this.value.trim().toLowerCase();
    $('.le-fila-doc').each(function () { $(this).toggle(!q || String($(this).data('texto')).indexOf(q) >= 0); });
  });
  $(document).on('change', '.le-elegir', function () {
    if (this.checked) elegidos[this.value] = true; else delete elegidos[this.value];
    actualizarContador(); ocultarDesde('docs');
  });
  $(document).on('change', '#le-marcar-todos', function () {
    var marcar = this.checked;
    $('.le-fila-doc:visible .le-elegir:not(:disabled)').each(function () {
      this.checked = marcar;
      if (marcar) elegidos[this.value] = true; else delete elegidos[this.value];
    });
    actualizarContador(); ocultarDesde('docs');
  });
  $(document).on('click', '#le-continuar', function () { cargarDocumentos(clavesElegidas()); });

  function reiniciar() {
    datos = null; despachado = {}; cajas = []; guardada = false;
    $('#le-error-pedido, #le-sec-docs, #le-sec-cajas, #le-sec-datos, #le-sec-preview, #le-resultado').addClass('le-hidden');
  }

  // Trae los documentos marcados con sus productos.
  function cargarDocumentos(claves) {
    reiniciar();
    cargando('Consultando los productos de ' + claves.length + ' documento(s) en World Office…');
    $('#le-continuar').prop('disabled', true);
    $.getJSON('php/lista_empaque_documentos.php', { clientes: idsClientes.join(','), claves: claves.join(',') }).done(function (r) {
      if (!r.ok) { $('#le-error-pedido').text(r.error || 'No se pudieron cargar los documentos.').removeClass('le-hidden'); return; }
      datos = r;
      pintarDocumentos();
      $('html,body').animate({ scrollTop: $('#le-sec-docs').offset().top - 80 }, 250);
    }).fail(function () {
      $('#le-error-pedido').text('No se pudo consultar World Office. Intenta de nuevo.').removeClass('le-hidden');
    }).always(function () { cargando(''); actualizarContador(); });
  }

  function dato(l, v) { return '<div><div class="le-dato-label">' + h(l) + '</div><div class="le-dato-valor">' + h(v) + '</div></div>'; }

  // ── Paso 2: documentos y cruce ─────────────────────────────────
  function pintarDocumentos() {
    var html = '<div class="table-responsive"><table class="le-table"><thead><tr><th>Tipo</th><th>Documento</th><th>Fecha</th><th>Cliente</th><th>Ciudad</th><th class="num">Unidades</th><th></th></tr></thead><tbody>';
    datos.documentos.forEach(function (d, i) {
      var und = d.items.reduce(function (a, it) { return a + it.cantidad; }, 0);
      html += '<tr>' +
        '<td><span class="le-badge ' + (d.tipo === 'REM' ? 'le-badge-rem' : 'le-badge-fv') + '">' + h(d.tipo_label) + '</span></td>' +
        '<td><strong>' + h(d.documento) + '</strong><div style="font-size:.72rem; color:#94a3b8;">' + h(d.concepto) + '</div></td>' +
        '<td style="white-space:nowrap;">' + fecha(d.fecha) + '</td><td style="font-size:.8rem;">' + h(d.cliente) + '</td><td>' + h(d.ciudad || '—') + '</td>' +
        '<td class="num">' + n(und) + '</td>' +
        '<td><a href="javascript:;" class="le-ver-items" data-i="' + i + '">Ver productos</a></td></tr>';
      html += '<tr class="le-doc-items le-hidden" data-items="' + i + '"><td colspan="7"><table style="width:100%">' +
        d.items.map(function (it) { return '<tr><td style="width:160px">' + h(it.codigo) + '</td><td>' + h(it.descripcion) + '</td><td class="num">' + n(it.cantidad) + '</td></tr>'; }).join('') +
        '</table></td></tr>';
    });
    html += '</tbody></table></div>';
    $('#le-docs').html(html);
    $('#le-sec-docs').removeClass('le-hidden');
    pintarProductos();
  }

  $(document).on('click', '.le-ver-items', function () { $('tr[data-items="' + $(this).data('i') + '"]').toggleClass('le-hidden'); });

  function docsElegidos() { return datos ? datos.documentos : []; }

  // Productos de los documentos elegidos, sumados por producto de WO: es lo que se reparte en cajas.
  function pintarProductos() {
    despachado = {};
    docsElegidos().forEach(function (d) {
      d.items.forEach(function (it) {
        if (!despachado[it.id_inventario]) despachado[it.id_inventario] = { id_inventario: it.id_inventario, codigo: it.codigo, descripcion: it.descripcion, cantidad: 0 };
        despachado[it.id_inventario].cantidad += it.cantidad;
      });
    });
    var tot = 0, html = '<p class="le-dato-label" style="margin-bottom:6px;">Productos de los documentos elegidos</p>' +
      '<div class="table-responsive"><table class="le-table"><thead><tr><th>Referencia</th><th>Producto</th><th class="num">Cantidad</th></tr></thead><tbody>';
    Object.keys(despachado).forEach(function (k) {
      var it = despachado[k];
      tot += it.cantidad;
      html += '<tr><td>' + h(it.codigo) + '</td><td>' + h(it.descripcion) + '</td><td class="num">' + n(it.cantidad) + '</td></tr>';
    });
    html += '</tbody><tfoot><tr><td colspan="2">Total</td><td class="num">' + n(tot) + '</td></tr></tfoot></table></div>';
    $('#le-cruce').html(html);
    $('#le-ir-cajas').prop('disabled', Object.keys(despachado).length === 0);
  }

  // ── Paso 3: cajas ──────────────────────────────────────────────
  $('#le-ir-cajas').on('click', function () {
    if (!cajas.length) cajas = [{ peso: '', items: [] }];
    pintarCajas();
    $('#le-sec-cajas').removeClass('le-hidden');
    prepararDatos();
    $('#le-sec-datos').removeClass('le-hidden');
    $('html,body').animate({ scrollTop: $('#le-sec-cajas').offset().top - 80 }, 250);
  });
  $('#le-agregar-caja').on('click', function () { cajas.push({ peso: '', items: [] }); pintarCajas(); ocultarDesde('preview'); });

  function asignadoPorProducto() {
    var a = {};
    cajas.forEach(function (c) { c.items.forEach(function (it) { a[it.id_inventario] = (a[it.id_inventario] || 0) + (parseInt(it.cantidad, 10) || 0); }); });
    return a;
  }
  function pendiente(k) { return despachado[k].cantidad - (asignadoPorProducto()[k] || 0); }

  // Unidades que puede llevar una fila: lo pendiente del producto + lo que la fila ya tiene.
  function maxFila(ci, ii) {
    var it = cajas[ci].items[ii];
    if (!it.id_inventario) return 0;
    return pendiente(it.id_inventario) + (parseInt(it.cantidad, 10) || 0);
  }

  // Productos que puede elegir una fila: el que ya tiene, o los que aún tienen unidades sin
  // asignar y no están ya en otra fila de la misma caja (para eso se sube la cantidad de esa fila).
  function productosDisponibles(ci, ii) {
    var sel = String(cajas[ci].items[ii] ? cajas[ci].items[ii].id_inventario : '');
    return Object.keys(despachado).filter(function (k) {
      if (k === sel) return true;
      if (pendiente(k) <= 0) return false;
      return !cajas[ci].items.some(function (it, j) { return j !== ii && String(it.id_inventario) === k; });
    });
  }

  function opcionesProducto(ci, ii) {
    var it = cajas[ci].items[ii], sel = String(it.id_inventario || '');
    return '<option value="">— Producto —</option>' + productosDisponibles(ci, ii).map(function (k) {
      var quedan = pendiente(k) + (k === sel ? (parseInt(it.cantidad, 10) || 0) : 0);
      return '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + h(despachado[k].descripcion) + ' (' + h(despachado[k].codigo) + ') — quedan ' + n(quedan) + '</option>';
    }).join('');
  }

  // Productos con unidades pendientes que todavía no están en la caja ci.
  function puedeAgregarFila(ci) {
    return Object.keys(despachado).some(function (k) {
      return pendiente(k) > 0 && !cajas[ci].items.some(function (it) { return String(it.id_inventario) === k; });
    });
  }
  function hayPendiente() { return Object.keys(despachado).some(function (k) { return pendiente(k) > 0; }); }

  // Refresca desplegables, topes de cantidad y botones sin volver a pintar las cajas (para no
  // perder el foco mientras se escribe una cantidad).
  function refrescarFilas() {
    $('.le-item-prod').each(function () {
      var ci = $(this).data('c'), ii = $(this).data('i');
      $(this).html(opcionesProducto(ci, ii));
    });
    $('.le-item-cant').each(function () {
      var ci = $(this).data('c'), ii = $(this).data('i');
      $(this).attr('max', maxFila(ci, ii) || null);
    });
    var pend = hayPendiente();
    $('.le-agregar-item').each(function () {
      var ok = puedeAgregarFila($(this).data('c'));
      $(this).prop('disabled', !ok).attr('title', ok ? '' : (pend ? 'Lo pendiente ya está en esta caja: sube la cantidad de esa fila' : 'Ya se asignaron todas las unidades despachadas'));
    });
    $('.le-llenar-caja').prop('disabled', !pend);
  }

  function pintarCajas() {
    var html = '';
    cajas.forEach(function (c, ci) {
      var und = c.items.reduce(function (a, it) { return a + (parseInt(it.cantidad, 10) || 0); }, 0);
      html += '<div class="le-caja"><div class="le-caja-head"><strong><i class="bi bi-box"></i> Caja ' + (ci + 1) + '</strong>' +
        '<span class="le-badge le-badge-gris">' + n(und) + ' und</span>' +
        '<span style="flex:1"></span>' +
        '<label style="margin:0; font-size:.75rem; color:#64748b;">Peso (kg, opcional)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm le-caja-peso" data-c="' + ci + '" value="' + h(c.peso) + '" placeholder="—" style="width:95px">' +
        (cajas.length > 1 ? '<button type="button" class="le-btn le-btn-danger le-btn-sm le-quitar-caja" data-c="' + ci + '" title="Eliminar caja"><i class="bi bi-trash"></i></button>' : '') +
        '</div><div class="le-caja-body">';
      c.items.forEach(function (it, ii) {
        html += '<div style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">' +
          '<select class="form-control form-control-sm le-item-prod" data-c="' + ci + '" data-i="' + ii + '">' + opcionesProducto(ci, ii) + '</select>' +
          '<input type="number" min="1" step="1" class="form-control form-control-sm le-cant le-item-cant" data-c="' + ci + '" data-i="' + ii + '" value="' + h(it.cantidad) + '" placeholder="Cant.">' +
          '<button type="button" class="le-btn le-btn-danger le-btn-sm le-quitar-item" data-c="' + ci + '" data-i="' + ii + '" title="Quitar"><i class="bi bi-x-lg"></i></button></div>';
      });
      html += '<div style="display:flex; gap:8px; flex-wrap:wrap;">' +
        '<button type="button" class="le-btn le-btn-outline le-btn-sm le-agregar-item" data-c="' + ci + '"><i class="bi bi-plus"></i> Agregar producto</button>' +
        '<button type="button" class="le-btn le-btn-outline le-btn-sm le-llenar-caja" data-c="' + ci + '" title="Pone en esta caja todo lo que falta por asignar"><i class="bi bi-box-arrow-in-down"></i> Agregar todo lo pendiente</button>' +
        '</div></div></div>';
    });
    $('#le-cajas').html(html);
    pintarControl();
  }

  function pintarControl() {
    var asig = asignadoPorProducto(), ok = true;
    var html = '<table class="le-table"><thead><tr><th>Producto</th><th class="num">Despach.</th><th class="num">En cajas</th><th class="num">Falta</th></tr></thead><tbody>';
    Object.keys(despachado).forEach(function (k) {
      var d = despachado[k], a = asig[k] || 0, falta = d.cantidad - a;
      if (falta !== 0) ok = false;
      var cls = falta === 0 ? 'le-dif-ok' : (falta < 0 ? 'le-dif-neg' : 'le-dif-pos');
      html += '<tr><td>' + h(d.descripcion) + '</td><td class="num">' + n(d.cantidad) + '</td><td class="num">' + n(a) + '</td><td class="num ' + cls + '">' +
        (falta === 0 ? '<i class="bi bi-check-lg"></i>' : (falta < 0 ? 'Sobran ' + n(-falta) : n(falta))) + '</td></tr>';
    });
    html += '</tbody></table>';
    html += ok ? '<div class="le-aviso le-aviso-ok" style="margin-top:10px;"><i class="bi bi-check-circle"></i> Todas las unidades despachadas están en cajas.</div>'
               : '<div class="le-aviso le-aviso-warn" style="margin-top:10px;"><i class="bi bi-exclamation-triangle"></i> Las cantidades en cajas deben ser exactamente las despachadas.</div>';
    $('#le-control').html(html);

    var r = '<table class="le-table"><thead><tr><th>Caja</th><th>Producto</th><th class="num">Cant.</th></tr></thead><tbody>', hay = false;
    cajas.forEach(function (c, ci) {
      c.items.forEach(function (it) {
        if (!it.id_inventario || !(parseInt(it.cantidad, 10) > 0)) return;
        hay = true;
        r += '<tr><td>Caja ' + (ci + 1) + '</td><td>' + h(despachado[it.id_inventario].descripcion) + '</td><td class="num">' + n(it.cantidad) + '</td></tr>';
      });
    });
    r += '</tbody></table>';
    $('#le-resumen-cajas').html(hay ? r : '<div class="le-aviso le-aviso-info">Aún no hay productos en cajas.</div>');

    var pesos = cajas.map(function (c) { return parseFloat(c.peso); }).filter(function (p) { return !isNaN(p); });
    $('#le-peso-ayuda').text(pesos.length === cajas.length && pesos.length ? 'Suma de los pesos por caja: ' + n(pesos.reduce(function (a, b) { return a + b; }, 0)) + ' kg' : '');
    refrescarFilas();
  }

  function actualizarBadgeCaja(ci) {
    var und = cajas[ci].items.reduce(function (a, it) { return a + (parseInt(it.cantidad, 10) || 0); }, 0);
    $('.le-caja').eq(ci).find('.le-caja-head .le-badge').text(n(und) + ' und');
  }

  $(document).on('input', '.le-caja-peso', function () { cajas[$(this).data('c')].peso = this.value; pintarControl(); ocultarDesde('preview'); });
  $(document).on('change', '.le-item-prod', function () {
    var ci = $(this).data('c'), ii = $(this).data('i'), it = cajas[ci].items[ii];
    // Al cambiar de producto, la fila deja de "reservar" unidades del anterior y toma lo que quede del nuevo.
    it.id_inventario = this.value;
    it.cantidad = '';
    if (this.value) it.cantidad = pendiente(this.value);
    pintarCajas(); ocultarDesde('preview');
  });
  $(document).on('input', '.le-item-cant', function () {
    var ci = $(this).data('c'), ii = $(this).data('i'), it = cajas[ci].items[ii];
    var tope = maxFila(ci, ii), v = parseInt(this.value, 10);
    if (it.id_inventario && !isNaN(v) && v > tope) {
      inkToast(h('Solo quedan ' + n(tope) + ' unidades de ' + despachado[it.id_inventario].descripcion + ' por asignar.'), 'warn');
      this.value = tope;
    }
    it.cantidad = this.value;
    actualizarBadgeCaja(ci);
    pintarControl(); ocultarDesde('preview');
  });
  $(document).on('click', '.le-agregar-item', function () {
    var ci = $(this).data('c');
    // Propone el primer producto con unidades pendientes que no esté ya en esta caja, con esa cantidad.
    var k = Object.keys(despachado).find(function (x) {
      return pendiente(x) > 0 && !cajas[ci].items.some(function (it) { return String(it.id_inventario) === x; });
    });
    if (!k) { inkToast('Ya se asignaron todas las unidades despachadas.', 'warn'); return; }
    cajas[ci].items.push({ id_inventario: k, cantidad: pendiente(k) });
    pintarCajas(); ocultarDesde('preview');
  });
  $(document).on('click', '.le-llenar-caja', function () {
    var c = cajas[$(this).data('c')];
    Object.keys(despachado).forEach(function (k) {
      var p = pendiente(k);
      if (p <= 0) return;
      var existente = c.items.find(function (it) { return String(it.id_inventario) === k; });
      if (existente) existente.cantidad = (parseInt(existente.cantidad, 10) || 0) + p;
      else c.items.push({ id_inventario: k, cantidad: p });
    });
    pintarCajas(); ocultarDesde('preview');
  });
  $(document).on('click', '.le-quitar-item', function () { cajas[$(this).data('c')].items.splice($(this).data('i'), 1); pintarCajas(); ocultarDesde('preview'); });
  $(document).on('click', '.le-quitar-caja', function () {
    var ci = $(this).data('c');
    inkConfirm({ title: 'Eliminar caja ' + (ci + 1), text: 'Sus productos vuelven a quedar pendientes por asignar.', type: 'danger', btnOk: 'Eliminar' }, function () {
      cajas.splice(ci, 1); pintarCajas(); ocultarDesde('preview');
    });
  });

  // ── Paso 4: datos ──────────────────────────────────────────────
  function prepararDatos() {
    var docs = docsElegidos();
    if (!docs.length) return;
    // Con documentos de varios clientes se muestran todos (así se guarda también).
    $('#le-cliente').val(docs.map(function (d) { return (d.cliente || '').trim(); })
      .filter(function (c, i, a) { return c && a.indexOf(c) === i; }).join(', '));
    // La ciudad se sugiere desde WO sin pisar lo que el usuario ya escribió; la dirección de entrega
    // se deja en blanco para que la escriban (pedido del usuario 2026-09-28).
    if (!$('#le-ciudad').val().trim()) $('#le-ciudad').val(docs[0].ciudad || '');
  }
  $('#le-ciudad, #le-direccion, #le-persona-recibe, #le-peso-neto, #le-empacado-por').on('input', function () { ocultarDesde('preview'); });

  // 'docs': cambió la selección del paso 1 → se descarta todo lo que sigue; 'cajas': se rehacen las
  // cajas; 'preview': solo se oculta la vista previa.
  function ocultarDesde(paso) {
    if (guardada) return;
    if (paso === 'docs') { datos = null; despachado = {}; $('#le-sec-docs').addClass('le-hidden'); }
    if (paso === 'docs' || paso === 'cajas') { cajas = []; $('#le-sec-cajas, #le-sec-datos').addClass('le-hidden'); }
    $('#le-sec-preview').addClass('le-hidden');
  }

  function validarTodo() {
    if (!docsElegidos().length) return 'Selecciona al menos una remisión o factura del cliente.';
    if (!cajas.length) return 'Agrega al menos una caja.';
    for (var ci = 0; ci < cajas.length; ci++) {
      var c = cajas[ci];
      if (!c.items.length) return 'La caja ' + (ci + 1) + ' está vacía. Asígnale productos o elimínala.';
      for (var ii = 0; ii < c.items.length; ii++) {
        var it = c.items[ii], v = String(it.cantidad).trim();
        if (!it.id_inventario) return 'En la caja ' + (ci + 1) + ' hay una fila sin producto.';
        if (!/^\d+$/.test(v) || parseInt(v, 10) <= 0) return 'En la caja ' + (ci + 1) + ' hay una cantidad no válida.';
      }
    }
    var asig = asignadoPorProducto();
    for (var k in despachado) {
      var a = asig[k] || 0;
      if (a !== despachado[k].cantidad) return despachado[k].descripcion + ': se despacharon ' + n(despachado[k].cantidad) + ' y en las cajas hay ' + n(a) + '.';
    }
    if (!$('#le-direccion').val().trim()) return 'Escribe la dirección de entrega.';
    var peso = $('#le-peso-neto').val();
    if (peso !== '' && (isNaN(parseFloat(peso)) || parseFloat(peso) < 0)) return 'El peso neto no es válido.';
    if (!$('#le-empacado-por').val().trim()) return 'Escribe quién empacó.';
    return null;
  }

  // ── Paso 5: vista previa y generación ──────────────────────────
  $('#le-vista-previa').on('click', function () {
    var err = validarTodo();
    if (err) { $('#le-error-final').text(err).removeClass('le-hidden'); return; }
    $('#le-error-final').addClass('le-hidden');
    $('#le-preview').html(htmlVistaPrevia('(se asigna al generar)'));
    $('#le-resultado').addClass('le-hidden');
    $('#le-generar, #le-corregir').show();
    $('#le-sec-preview').removeClass('le-hidden');
    $('html,body').animate({ scrollTop: $('#le-sec-preview').offset().top - 80 }, 250);
  });
  $('#le-corregir').on('click', function () { $('html,body').animate({ scrollTop: $('#le-sec-cajas').offset().top - 80 }, 250); });

  function htmlVistaPrevia(consecutivo) {
    var docs = docsElegidos();
    var totalUnd = 0;
    cajas.forEach(function (c) { c.items.forEach(function (it) { totalUnd += parseInt(it.cantidad, 10) || 0; }); });
    var peso = $('#le-peso-neto').val();
    var hoy = new Date();
    var html = '<div class="le-preview">' +
      '<div class="le-pv-head"><div style="display:flex; gap:16px; align-items:center;"><img src="vendors/images/logo_eureka.png" style="height:52px">' +
      '<div><div style="font-weight:700; font-size:.9rem;">' + h(docs[0].empresa || 'EUREKA CONTENIDOS EDUCATIVOS SAS') + '</div><div class="le-pv-titulo">LISTA DE EMPAQUE</div></div></div>' +
      '<div class="le-pv-num"><div class="le-dato-label">N.º de lista</div><div style="font-weight:800; font-size:1.05rem;">' + h(consecutivo) + '</div>' +
      '<div style="font-size:.75rem; color:#64748b;">Generada: ' + hoy.toLocaleDateString('es-CO') + '</div></div></div>';

    html += '<div class="le-pv-sec">Datos del cliente</div><div class="le-grid">' +
      dato('Cliente / empresa', $('#le-cliente').val()) + dato('Dirección de entrega', $('#le-direccion').val()) + dato('Ciudad', $('#le-ciudad').val() || '—') +
      dato('Persona que recibe', $('#le-persona-recibe').val().trim() || '—') + '</div>';
    html += '<div class="le-pv-sec">Documentos asociados</div><div class="le-dato-valor">' +
      docs.map(function (d) { return h(d.tipo_label + ' ' + d.documento) + ' (' + fecha(d.fecha) + ')'; }).join('<br>') + '</div>';
    html += '<div class="le-pv-sec">Resumen del despacho</div><div class="le-grid">' +
      dato('Cantidad total de productos', n(totalUnd) + ' unidades') + dato('Número total de cajas', cajas.length) + dato('Peso neto', peso !== '' ? n(peso) + ' kg' : '—') + '</div>';
    html += '<div class="le-pv-sec">Detalle de las cajas</div><table class="le-table"><thead><tr><th>N.º de caja</th><th>Referencia</th><th>Producto</th><th class="num">Cantidad</th></tr></thead><tbody>';
    cajas.forEach(function (c, ci) {
      var und = 0;
      c.items.forEach(function (it) {
        var d = despachado[it.id_inventario];
        und += parseInt(it.cantidad, 10);
        html += '<tr><td><strong>' + (ci + 1) + '</strong></td><td>' + h(d.codigo) + '</td><td>' + h(d.descripcion) + '</td><td class="num">' + n(it.cantidad) + '</td></tr>';
      });
      html += '<tr style="background:#f1f5f9;"><td colspan="3" class="num" style="font-size:.78rem; font-weight:700;">Total caja ' + (ci + 1) + (c.peso !== '' ? ' · Peso ' + n(c.peso) + ' kg' : '') + '</td><td class="num"><strong>' + n(und) + '</strong></td></tr>';
    });
    html += '</tbody><tfoot><tr><td colspan="3" class="num">TOTAL: ' + cajas.length + ' caja(s)</td><td class="num">' + n(totalUnd) + '</td></tr></tfoot></table>';
    html += '<div class="le-pv-sec">Datos de empaque</div>' + dato('Empacado por', $('#le-empacado-por').val()) + '</div>';
    return html;
  }

  $('#le-generar').on('click', function () {
    var err = validarTodo();
    if (err) { inkToast(h(err), 'error'); return; }
    var $btn = $(this).prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Generando…');
    var cuerpo = {
      clientes: idsClientes,
      documentos: docsElegidos().map(function (d) { return d.clave; }),
      cajas: cajas.map(function (c) { return { peso: c.peso, items: c.items.map(function (it) { return { id_inventario: parseInt(it.id_inventario, 10), cantidad: parseInt(it.cantidad, 10) }; }) }; }),
      ciudad: $('#le-ciudad').val(),
      direccion: $('#le-direccion').val(),
      persona_recibe: $('#le-persona-recibe').val(),
      peso_neto: $('#le-peso-neto').val(),
      empacado_por: $('#le-empacado-por').val()
    };
    $.ajax({ url: 'php/lista_empaque_guardar.php', method: 'POST', contentType: 'application/json', data: JSON.stringify(cuerpo), dataType: 'json' })
      .done(function (r) {
        if (!r.ok) { inkToast(h(r.error || 'No se pudo generar la lista.'), 'error'); return; }
        guardada = true;
        $('#le-preview').html(htmlVistaPrevia(r.consecutivo));
        $('#le-generar, #le-corregir').hide();
        $('#le-resultado').html('<div class="le-aviso le-aviso-ok" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;"><span><i class="bi bi-check-circle"></i> Se generó la <strong>lista de empaque ' + h(r.consecutivo) + '</strong>.</span>' +
          '<a class="le-btn le-btn-sm" href="php/lista_empaque_pdf.php?id=' + r.id + '" target="_blank" rel="noopener"><i class="bi bi-download"></i> Descargar PDF</a>' +
          '<a class="le-btn le-btn-outline le-btn-sm" href="lista_empaque_ver.php?id=' + r.id + '">Ver en el historial</a>' +
          '<a class="le-btn le-btn-outline le-btn-sm" href="lista_empaque.php">Nueva lista</a></div>').removeClass('le-hidden');
        // Ya guardada: se bloquea la edición para no generar una segunda lista por accidente.
        $('#le-sec-docs input, #le-sec-cajas input, #le-sec-cajas select, #le-sec-cajas button, #le-sec-datos input, #le-sec-datos textarea, #le-sec-datos button, #le-ir-cajas').prop('disabled', true);
        $('#le-cliente-filtro, #le-lista-cliente input, #le-continuar').prop('disabled', true);
        window.open('php/lista_empaque_pdf.php?id=' + r.id, '_blank');
      })
      .fail(function () { inkToast('No se pudo generar la lista. Intenta de nuevo.', 'error'); })
      .always(function () { $btn.prop('disabled', false).html('<i class="bi bi-file-earmark-pdf"></i> Generar lista de empaque'); });
  });
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
