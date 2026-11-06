<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/backorders_sa_datos.php");

if (!bsa_tiene_acceso()) {
    header("Location: index.php");
    exit;
}
bsa_asegurar_tablas($bdd);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Backorders sin adopción</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <?php include("template/backorders_sa_estilos.php"); ?>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Backorders sin adopción</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Backorders sin adopción</li>
                <li class="breadcrumb-item active" aria-current="page">Administrar</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <!-- Nuevo backorder: un pedido sin adopción (y su cliente) + uno o varios libros (cada libro queda como su propio backorder) -->
      <div class="bsa-nuevo">
        <div class="bsa-nuevo-head">
          <div class="bsa-nuevo-head-txt">
            <div class="bsa-nuevo-icono"><i class="bi bi-journal-plus"></i></div>
            <div>
              <h5>Nuevo backorder</h5>
              <p>Registra los libros que se le deben a un pedido sin adopción. Cada libro queda como un backorder independiente.</p>
            </div>
          </div>
          <a href="backorders_sa_informe.php" class="bsa-btn bsa-btn-glass"><i class="bi bi-bar-chart"></i> Ver informe</a>
        </div>

        <form id="form-nuevo-bsa" method="POST" action="php/backorders_sa_guardar.php">
          <div class="bsa-nuevo-body">

            <div class="bsa-paso">
              <div class="bsa-paso-num">1</div>
              <div class="bsa-paso-cont">
                <p class="bsa-paso-titulo">Pedido sin adopción <span class="bsa-req">*</span></p>
                <p class="bsa-paso-ayuda"><i class="bi bi-receipt"></i> Busca por número de pedido, colegio o cliente. El backorder queda relacionado con el pedido y su cliente.</p>
                <select id="bsa-pedido" name="id_pedido2" style="width:100%;"><option></option></select>
                <div id="bsa-pedido-cliente" class="bsa-paso-ayuda" style="display:none; margin-top:8px;"></div>
              </div>
            </div>

            <div class="bsa-paso">
              <div class="bsa-paso-num">2</div>
              <div class="bsa-paso-cont">
                <p class="bsa-paso-titulo">Libros <span class="bsa-req">*</span></p>
                <p class="bsa-paso-ayuda"><i class="bi bi-book"></i> Si el pedido tiene libros cargados, aparecen aquí con su cantidad. Si no, busca cualquier libro del CRM por título o ISBN. Agrega todos los que necesites.</p>
                <div id="bsa-lineas"></div>
                <button type="button" class="bsa-btn-agregar" id="bsa-btn-agregar-libro"><i class="bi bi-plus-lg"></i> Añadir otro libro</button>
              </div>
            </div>

            <div class="bsa-paso">
              <div class="bsa-paso-num">3</div>
              <div class="bsa-paso-cont">
                <p class="bsa-paso-titulo">Observaciones <span class="bsa-opcional">opcional</span></p>
                <textarea name="observaciones" class="bsa-input" rows="2" maxlength="1000" placeholder="Información adicional sobre la solicitud..."></textarea>
              </div>
            </div>

          </div>

          <div class="bsa-nuevo-footer">
            <div class="bsa-nuevo-resumen">
              <span class="bsa-chip"><i class="bi bi-book"></i> <strong id="bsa-res-libros">0</strong> libro(s)</span>
              <span class="bsa-chip"><i class="bi bi-stack"></i> <strong id="bsa-res-unidades">0</strong> unidad(es)</span>
              <span class="bsa-nuevo-fecha"><i class="bi bi-calendar-check"></i> La fecha de registro se asigna automáticamente al guardar.</span>
            </div>
            <button type="submit" class="bsa-btn bsa-btn-lg"><i class="bi bi-save"></i> Guardar backorder</button>
          </div>
        </form>

        <!-- Plantilla de una línea de libro (JS la clona en "Añadir otro libro") -->
        <template id="tpl-bsa-linea">
          <div class="bsa-linea">
            <span class="bsa-linea-num"></span>
            <div class="bsa-linea-libro">
              <select name="id_libro[]" class="bsa-sel-libro" style="width:100%;"><option></option></select>
              <span class="bsa-max" style="display:none; font-size:.72rem; color:#64748b; margin-top:3px;"></span>
            </div>
            <div class="bsa-stepper" title="Cantidad solicitada">
              <button type="button" class="bsa-step" data-paso="-1" aria-label="Restar"><i class="bi bi-dash"></i></button>
              <input type="number" name="cantidad[]" min="1" step="1" value="1" class="bsa-cant" aria-label="Cantidad">
              <button type="button" class="bsa-step" data-paso="1" aria-label="Sumar"><i class="bi bi-plus"></i></button>
            </div>
            <button type="button" class="bsa-linea-quitar" title="Quitar libro"><i class="bi bi-trash"></i></button>
          </div>
        </template>
      </div>

      <!-- Listado -->
      <div class="bsa-section">
        <div class="bsa-section-head">
          <p class="bsa-section-title"><i class="bi bi-list-check"></i> Backorders registrados</p>
        </div>
        <div class="bsa-section-body">
          <?php $bsa_ocultar_atendidos = true; include("template/backorders_sa_filtros.php"); ?>
          <div class="bsa-resumen" id="bsa-resumen"></div>
          <div class="table-responsive">
            <table class="bsa-tabla">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Fecha de registro</th>
                  <th>Libro</th>
                  <th>ISBN</th>
                  <th>Pedido / Cliente</th>
                  <th class="bsa-num">Solicitada</th>
                  <th class="bsa-num">Atendida</th>
                  <th class="bsa-num">Pendiente</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody id="bsa-tbody"><tr><td colspan="10" class="bsa-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<!-- Modal Atender -->
<div class="modal fade" id="ModalAtenderBsa" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="border-radius:12px; overflow:hidden;">
      <form method="POST" action="php/backorders_sa_atender.php" id="form-atender-bsa">
        <div class="modal-header" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
          <h4 class="modal-title" style="color:#1e3a8a; font-weight:700; font-size:1rem;"><i class="bi bi-box-seam"></i> Atender backorder #<span id="at-id-txt"></span></h4>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" style="padding:20px;">
          <input type="hidden" name="id" id="at-id">
          <p style="font-size:.85rem; color:#475569; margin:0 0 14px;">
            <strong id="at-libro"></strong> para <strong id="at-tercero"></strong>.
          </p>
          <div class="bsa-grid-3">
            <div><label class="bsa-label">Solicitada</label><div class="bsa-dato" id="at-solicitada"></div></div>
            <div><label class="bsa-label">Atendida</label><div class="bsa-dato" id="at-atendida"></div></div>
            <div><label class="bsa-label">Pendiente</label><div class="bsa-dato" id="at-pendiente"></div></div>
          </div>
          <div style="margin-bottom:14px;">
            <label class="bsa-label">Cantidad a atender <span class="bsa-req">*</span></label>
            <input type="number" name="cantidad" id="at-cantidad" min="1" step="1" required class="form-control form-control-sm" style="max-width:180px;">
          </div>
          <div>
            <label class="bsa-label">Observaciones</label>
            <textarea name="observaciones" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
          </div>
        </div>
        <div class="modal-footer" style="background:#fafafa;">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
          <button type="submit" class="bsa-btn"><i class="bi bi-check-lg"></i> Atender</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Anular -->
<div class="modal fade" id="ModalAnularBsa" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="border-radius:12px; overflow:hidden;">
      <form method="POST" action="php/backorders_sa_anular.php" id="form-anular-bsa">
        <div class="modal-header" style="background:#fff4f4; border-bottom:1px solid #fecaca;">
          <h4 class="modal-title" style="color:#dc2626; font-weight:700; font-size:1rem;"><i class="bi bi-x-circle-fill"></i> Anular backorder #<span id="an-id-txt"></span></h4>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" style="padding:20px;">
          <input type="hidden" name="id" id="an-id">
          <p style="font-size:.85rem; color:#475569;">
            <strong id="an-libro"></strong> para <strong id="an-tercero"></strong> — pendiente: <strong id="an-pendiente"></strong>.
            El registro no se borra: queda anulado con la fecha, tu usuario y el motivo. Los pedidos ya generados se conservan.
          </p>
          <label class="bsa-label">Motivo de la anulación <span class="bsa-req">*</span></label>
          <textarea name="motivo" id="an-motivo" class="form-control form-control-sm" rows="3" maxlength="1000" required></textarea>
        </div>
        <div class="modal-footer" style="background:#fafafa;">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
          <button type="submit" class="bsa-btn bsa-btn-danger"><i class="bi bi-x-lg"></i> Anular</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include("template/backorders_sa_modal_historial.php"); ?>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script src="src/backorders_sa.js"></script>
<script>
(function () {
  var BSA = window.BSA;

  // Pedido sin adopción: al abrir ya trae los más recientes; se puede buscar por número, colegio o cliente.
  $('#bsa-pedido').select2({
    placeholder: 'Busca el pedido por número, colegio o cliente...', allowClear: true, width: '100%', minimumInputLength: 0,
    ajax: {
      url: 'ajax/bsa_buscar_pedidos.php', dataType: 'json', delay: 250,
      data: function (p) { return { q: p.term || '' }; },
      processResults: function (data) { return { results: (data && data.results) || [] }; }
    },
    language: BSA.idiomaSelect2
  });
  // Cliente del pedido elegido (es el que queda relacionado con el backorder) y sus libros: si el
  // pedido tiene libros cargados se precargan en "Libros" (editables); si no, se agregan a mano.
  $('#bsa-pedido').on('select2:select', function (e) {
    var d = e.params.data;
    var cliente = '<i class="bi bi-person-check"></i> Cliente: <strong>' + BSA.h(d.cliente || '') + '</strong>' +
      (d.documento ? ' — ' + BSA.h(d.documento) : '');
    $('#bsa-pedido-cliente').show().html(cliente + ' · <span style="color:#94a3b8;">cargando libros del pedido...</span>');
    $.getJSON('ajax/bsa_libros_pedido.php', { id_pedido2: d.id }, function (libros) {
      libros = libros || [];
      maximos = {};
      libros.forEach(function (l) { maximos[l.id] = parseInt(l.cantidad, 10) || 0; });
      reiniciarLineas(libros);
      $('#bsa-pedido-cliente').html(cliente + ' · ' + (libros.length
        ? '<strong>' + libros.length + '</strong> libro(s) cargados del pedido: ajusta las cantidades o quita los que no se deban.'
        : 'El pedido no tiene libros cargados: agrégalos manualmente.'));
    }).fail(function () {
      maximos = {};
      reiniciarLineas([]);
      $('#bsa-pedido-cliente').html(cliente + ' · No se pudieron cargar los libros del pedido: agrégalos manualmente.');
    });
  });
  $('#bsa-pedido').on('select2:clear', function () {
    $('#bsa-pedido-cliente').hide();
    maximos = {};
    reiniciarLineas([]);
  });

  // ── Líneas de libros del formulario "Nuevo backorder" ──
  var $lineas = $('#bsa-lineas');
  // id_libro => cantidad solicitada en el pedido elegido: es el MÁXIMO para ese libro (pedido por la
  // usuaria 2026-10-20; el servidor también lo valida). Libros que no están en el pedido: sin tope.
  var maximos = {};
  function toast(msg) { if (window.inkToast) window.inkToast(msg, 'error'); }

  function maximoDe($fila) {
    var max = maximos[$fila.find('.bsa-sel-libro').val()];
    return max > 0 ? max : null;
  }
  // Pone el tope del libro de la línea en su cantidad (y lo indica debajo del libro).
  function aplicarMaximo($fila) {
    var max = maximoDe($fila), $in = $fila.find('.bsa-cant');
    if (max) {
      $in.attr('max', max);
      if ((parseInt($in.val(), 10) || 0) > max) $in.val(max);
      $fila.find('.bsa-max').css('display', 'block').text('Solicitado en el pedido: ' + BSA.num(max) + ' (máximo)');
    } else {
      $in.removeAttr('max');
      $fila.find('.bsa-max').hide().text('');
    }
  }

  function initLibro($sel) {
    $sel.select2({
      placeholder: 'Busca el libro por título o ISBN...', allowClear: true, width: '100%', minimumInputLength: 2,
      ajax: {
        url: 'ajax/bsa_buscar_libros.php', dataType: 'json', delay: 250,
        data: function (p) { return { q: p.term }; },
        processResults: function (data) { return { results: data || [] }; }
      },
      language: BSA.idiomaSelect2
    });
    // Un mismo libro no puede quedar en dos líneas: se suma la cantidad en una sola.
    $sel.on('select2:select', function (e) {
      var id = String(e.params.data.id), repetido = false;
      $lineas.find('.bsa-sel-libro').not(this).each(function () { if (String($(this).val()) === id) repetido = true; });
      if (repetido) {
        $(this).val(null).trigger('change');
        toast('Ese libro ya está en la lista: ajusta la cantidad en su línea.');
      }
      aplicarMaximo($(this).closest('.bsa-linea'));
      actualizarResumen();
    });
    $sel.on('select2:clear', function () { aplicarMaximo($(this).closest('.bsa-linea')); actualizarResumen(); });
  }

  function renumerar() {
    var $filas = $lineas.find('.bsa-linea');
    $filas.each(function (i) { $(this).find('.bsa-linea-num').text(i + 1); });
    $filas.find('.bsa-linea-quitar').prop('disabled', $filas.length <= 1);
  }

  function actualizarResumen() {
    var libros = 0, unidades = 0;
    $lineas.find('.bsa-linea').each(function () {
      if ($(this).find('.bsa-sel-libro').val()) {
        libros++;
        unidades += Math.max(0, parseInt($(this).find('.bsa-cant').val(), 10) || 0);
      }
    });
    $('#bsa-res-libros').text(libros);
    $('#bsa-res-unidades').text(BSA.num(unidades));
    $('#form-nuevo-bsa button[type=submit]').html('<i class="bi bi-save"></i> ' + (libros > 1 ? 'Guardar ' + libros + ' backorders' : 'Guardar backorder'));
  }

  // libro (opcional): {id, text, cantidad} para dejar la línea ya llena (libros del pedido).
  function agregarLinea(enfocar, libro) {
    var $fila = $($('#tpl-bsa-linea').html()).appendTo($lineas);
    var $sel = $fila.find('.bsa-sel-libro');
    if (libro) {
      $sel.append(new Option(libro.text, libro.id, true, true));
      $fila.find('.bsa-cant').val(Math.max(1, parseInt(libro.cantidad, 10) || 1));
    }
    initLibro($sel);
    aplicarMaximo($fila);
    renumerar();
    actualizarResumen();
    if (enfocar) $sel.select2('open');
  }

  // Reemplaza todas las líneas por los libros dados (o una línea vacía si no hay ninguno).
  function reiniciarLineas(libros) {
    $lineas.find('.bsa-sel-libro').each(function () {
      if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
    });
    $lineas.empty();
    if (libros.length) libros.forEach(function (l) { agregarLinea(false, l); });
    else agregarLinea(false);
  }

  $('#bsa-btn-agregar-libro').on('click', function () { agregarLinea(true); });
  $lineas.on('click', '.bsa-linea-quitar', function () {
    if ($lineas.find('.bsa-linea').length <= 1) return;
    $(this).closest('.bsa-linea').remove();
    renumerar();
    actualizarResumen();
  });
  $lineas.on('click', '.bsa-step', function () {
    var $in = $(this).siblings('.bsa-cant');
    var max = maximoDe($(this).closest('.bsa-linea'));
    var v = Math.max(1, (parseInt($in.val(), 10) || 0) + parseInt($(this).data('paso'), 10));
    if (max && v > max) { v = max; toast('No puede superar lo solicitado en el pedido (' + BSA.num(max) + ').'); }
    $in.val(v);
    actualizarResumen();
  });
  $lineas.on('input change', '.bsa-cant', function () {
    var max = maximoDe($(this).closest('.bsa-linea'));
    if (max && (parseInt($(this).val(), 10) || 0) > max) {
      $(this).val(max);
      toast('No puede superar lo solicitado en el pedido (' + BSA.num(max) + ').');
    }
    actualizarResumen();
  });
  agregarLinea(false);

  $('#form-nuevo-bsa').on('submit', function (e) {
    e.preventDefault();
    var form = this, libros = 0, error = null, unidades = 0;
    if (!$('#bsa-pedido').val()) error = 'Selecciona el pedido sin adopción.';
    $lineas.find('.bsa-linea').each(function () {
      var libro = $(this).find('.bsa-sel-libro').val();
      var cant = $(this).find('.bsa-cant').val();
      if (!libro) return;
      if (!/^\d+$/.test(cant) || parseInt(cant, 10) <= 0) { error = error || 'Cada libro debe tener una cantidad entera mayor que cero.'; return; }
      var max = maximoDe($(this));
      if (max && parseInt(cant, 10) > max) { error = error || 'Una cantidad supera lo solicitado en el pedido (' + BSA.num(max) + ').'; return; }
      libros++; unidades += parseInt(cant, 10);
    });
    if (!error && !libros) error = 'Agrega al menos un libro.';
    if (error) { toast(error); return; }
    var pedido = $('#bsa-pedido').select2('data')[0];
    inkConfirm({
      title: libros > 1 ? '¿Registrar ' + libros + ' backorders?' : '¿Registrar el backorder?',
      text: libros + ' libro(s), ' + BSA.num(unidades) + ' unidad(es) del pedido #' + (pedido ? pedido.id + ' (' + pedido.cliente + ')' : '') + '.',
      type: 'success', btnOk: 'Sí, guardar'
    }, function () { form.submit(); });
  });

  var porId = {};

  function acciones(b) {
    var html = '<div class="bsa-acciones">';
    if (b.estado !== 'anulado' && b.cantidad_pendiente > 0) {
      html += '<button type="button" class="bsa-btn bsa-btn-sm bsa-btn-atender" data-id="' + b.id + '"><i class="bi bi-box-seam"></i> Atender</button>';
      html += '<button type="button" class="bsa-btn bsa-btn-sm bsa-btn-danger bsa-btn-anular" data-id="' + b.id + '"><i class="bi bi-x-lg"></i> Anular</button>';
    }
    html += '<button type="button" class="bsa-btn bsa-btn-sm bsa-btn-outline bsa-btn-historial" data-id="' + b.id + '"><i class="bi bi-clock-history"></i> Historial</button>';
    return html + '</div>';
  }

  function render(data) {
    porId = {};
    $('#bsa-resumen').html(BSA.resumenHtml(data.totales));
    var filas = data.filas || [];
    if (!filas.length) {
      $('#bsa-tbody').html('<tr><td colspan="10" class="bsa-vacio">No hay backorders con los filtros seleccionados.</td></tr>');
      return;
    }
    var h = BSA.h, n = BSA.num;
    $('#bsa-tbody').html(filas.map(function (b) {
      porId[b.id] = b;
      return '<tr' + (b.estado === 'anulado' ? ' class="bsa-fila-anulada"' : '') + '>' +
        '<td><strong>#' + b.id + '</strong></td>' +
        '<td>' + h(BSA.fecha(b.fecha_registro)) + '</td>' +
        '<td>' + h(b.libro) + '</td>' +
        '<td>' + h(b.isbn || '—') + '</td>' +
        '<td>' + BSA.pedidoCliente(b) + '</td>' +
        '<td class="bsa-num">' + n(b.cantidad_solicitada) + '</td>' +
        '<td class="bsa-num">' + n(b.cantidad_atendida) + '</td>' +
        '<td class="bsa-num"><strong>' + n(b.cantidad_pendiente) + '</strong></td>' +
        '<td>' + BSA.badgeEstado(b) + '</td>' +
        '<td>' + acciones(b) + '</td>' +
        '</tr>';
    }).join(''));
  }

  function cargar() {
    $('#bsa-tbody').html('<tr><td colspan="10" class="bsa-vacio">Cargando...</td></tr>');
    $.getJSON('php/backorders_sa_datos.php', $.extend(BSA.filtros(), { ocultar_atendidos: '1' }), function (resp) {
      if (!resp.success) { if (window.inkToast) window.inkToast(resp.message || 'No se pudo cargar.', 'error'); return; }
      render(resp);
    }).fail(function () { if (window.inkToast) window.inkToast('Error al conectar con el servidor.', 'error'); });
  }
  BSA.alFiltrar(cargar);
  cargar();

  $('#bsa-tbody').on('click', '.bsa-btn-atender', function () {
    var b = porId[$(this).data('id')];
    if (!b) return;
    var $f = $('#form-atender-bsa');
    $f[0].reset();
    $('#at-id').val(b.id); $('#at-id-txt').text(b.id);
    $('#at-tercero').text(b.tercero); $('#at-libro').text(b.libro);
    $('#at-solicitada').text(BSA.num(b.cantidad_solicitada));
    $('#at-atendida').text(BSA.num(b.cantidad_atendida));
    $('#at-pendiente').text(BSA.num(b.cantidad_pendiente));
    $('#at-cantidad').attr('max', b.cantidad_pendiente).val(b.cantidad_pendiente);
    $('#ModalAtenderBsa').modal('show');
  });
  $('#at-cantidad').on('input', function () {
    var max = parseInt($(this).attr('max'), 10), v = parseInt($(this).val(), 10);
    if (v > max) $(this).val(max);
  });
  $('#form-atender-bsa').on('submit', function (e) {
    e.preventDefault();
    var form = this;
    inkConfirm({
      title: '¿Atender el backorder #' + $('#at-id').val() + '?',
      text: 'Se registrarán ' + $('#at-cantidad').val() + ' unidades como atendidas' +
        (parseInt($('#at-cantidad').val(), 10) >= parseInt($('#at-cantidad').attr('max'), 10) ? ' y saldrá de esta lista (seguirá en el informe).' : '.'),
      type: 'success', btnOk: 'Sí, generar'
    }, function () { form.submit(); });
  });

  $('#bsa-tbody').on('click', '.bsa-btn-anular', function () {
    var b = porId[$(this).data('id')];
    if (!b) return;
    $('#an-id').val(b.id); $('#an-id-txt').text(b.id);
    $('#an-libro').text(b.libro); $('#an-tercero').text(b.tercero); $('#an-pendiente').text(BSA.num(b.cantidad_pendiente));
    $('#an-motivo').val('');
    $('#ModalAnularBsa').modal('show');
  });
  $('#form-anular-bsa').on('submit', function (e) {
    e.preventDefault();
    var form = this;
    if (!$('#an-motivo').val().trim()) { if (window.inkToast) window.inkToast('Escribe el motivo de la anulación.', 'error'); return; }
    inkConfirm({
      title: '¿Anular el backorder #' + $('#an-id').val() + '?',
      text: 'Lo pendiente dejará de estar por atender. El registro y su historial se conservan.',
      type: 'danger', btnOk: 'Sí, anular'
    }, function () { form.submit(); });
  });

  $('#bsa-tbody').on('click', '.bsa-btn-historial', function () {
    var b = porId[$(this).data('id')];
    if (b) BSA.mostrarHistorial(b);
  });
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
