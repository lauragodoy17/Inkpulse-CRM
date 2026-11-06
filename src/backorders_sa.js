/**
 * Funciones compartidas de "Backorders sin adopción" (backorders_sa.php y backorders_sa_informe.php):
 * formato, filtros, resumen de totales y el modal de historial de un backorder.
 */
(function () {
  var BSA = {};

  BSA.h = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  BSA.num = function (v) { return (parseFloat(v) || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 }); };
  BSA.fecha = function (f) {
    if (!f) return '—';
    var p = String(f).split(/[- :]/);
    return p.length >= 5 ? p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4] : f;
  };
  // "Pedido #N" (enlace al pedido sin adopción) + cliente e identificación. Los backorders
  // registrados antes de 2026-10-20 no tienen pedido: solo el cliente.
  BSA.pedidoCliente = function (b) {
    return (b.id_pedido2 ? '<strong>Pedido ' + BSA.linkPedido(b.id_pedido2, b.estado_pedido2) + '</strong><br>' : '') +
      BSA.h(b.tercero) + (b.tercero_identificacion ? '<span class="bsa-sub">' + BSA.h(b.tercero_identificacion) + '</span>' : '');
  };
  BSA.badgeEstado = function (b) {
    return '<span class="bsa-badge bsa-badge-' + b.estado + '">' + BSA.h(b.estado_label) + '</span>';
  };
  // Vista de pedido_colegio_sa.php (parámetro tp) según el estado del pedido sin adopción.
  BSA.linkPedido = function (id, estado) {
    if (!id) return '—';
    var tp = ({ 1: 2, 2: 3, 3: 5, 4: 4, 5: 6, 6: 7, 7: 8 })[estado] || 2;
    return '<a href="pedido_colegio_sa.php?id_pedido=' + id + '&tp=' + tp + '" target="_blank">#' + id + '</a>';
  };
  BSA.idiomaSelect2 = {
    inputTooShort: function (a) { return 'Escribe al menos ' + (a.minimum - (a.input || '').length) + ' letra(s) más...'; },
    noResults: function () { return 'Sin resultados'; },
    searching: function () { return 'Buscando...'; }
  };

  BSA.resumenHtml = function (t) {
    t = t || {};
    var pe = t.por_estado || {};
    return '<div class="bsa-card bsa-card-cnt"><div class="num">' + BSA.num(t.backorders) + '</div><div class="lbl">Backorders</div></div>' +
      '<div class="bsa-card bsa-card-sol"><div class="num">' + BSA.num(t.solicitada) + '</div><div class="lbl">Total solicitado</div></div>' +
      '<div class="bsa-card bsa-card-at"><div class="num">' + BSA.num(t.atendida) + '</div><div class="lbl">Total atendido</div></div>' +
      '<div class="bsa-card bsa-card-pen"><div class="num">' + BSA.num(t.pendiente) + '</div><div class="lbl">Total pendiente</div></div>' +
      '<div class="bsa-card bsa-card-cnt" style="font-size:.78rem; color:#475569;">' +
        'Pendientes: <strong>' + (pe.pendiente || 0) + '</strong><br>' +
        'Atendidos: <strong>' + (pe.atendido || 0) + '</strong> · Anulados: <strong>' + (pe.anulado || 0) + '</strong></div>';
  };

  BSA.filtros = function () {
    var f = {};
    $('#bsa-filtros [data-filtro]').each(function () { f[$(this).data('filtro')] = $(this).val() || ''; });
    return f;
  };
  BSA.alFiltrar = function (cb) {
    $('#bsa-btn-filtrar').on('click', cb);
    $('#bsa-filtros input[type=text]').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); cb(); } });
    $('#bsa-filtros select, #bsa-filtros input[type=date]').on('change', cb);
    $('#bsa-btn-limpiar').on('click', function () { $('#bsa-filtros [data-filtro]').val(''); cb(); });
  };

  BSA.atencionesHtml = function (b) {
    if (!b.atenciones.length) return '<p style="font-size:.83rem; color:#64748b;">Aún no se ha atendido.</p>';
    return '<div class="table-responsive"><table class="bsa-tabla"><thead><tr>' +
      '<th>Fecha</th><th class="bsa-num">Cantidad</th><th class="bsa-num">Pendiente antes</th><th>Observaciones</th><th>Atendido por</th>' +
      '</tr></thead><tbody>' + b.atenciones.map(function (a) {
        return '<tr' + (a.pedido_anulado ? ' class="bsa-fila-anulada"' : '') + '>' +
          '<td>' + BSA.h(BSA.fecha(a.fecha)) + '</td>' +
          '<td class="bsa-num">' + BSA.num(a.cantidad) + '</td>' +
          '<td class="bsa-num">' + BSA.num(a.pendiente_antes) + '</td>' +
          '<td>' + BSA.h(a.observaciones || '—') +
            (a.id_pedido2 ? '<span class="bsa-sub">Pedido ' + BSA.linkPedido(a.id_pedido2, a.estado_pedido_id) + (a.pedido_anulado ? ' anulado: no cuenta como atendido' : '') + '</span>' : '') + '</td>' +
          '<td>' + BSA.h(a.usuario) + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  };

  BSA.historialHtml = function (b) {
    var nombres = { creado: 'Registrado', atencion: 'Atención', anulado: 'Anulado' };
    return b.historial.map(function (ev) {
      return '<div class="bsa-hist-item bsa-hist-' + BSA.h(ev.accion) + '">' +
        '<div><strong>' + BSA.h(nombres[ev.accion] || ev.accion) + '</strong> — ' + BSA.h(ev.detalle) + '</div>' +
        '<div class="meta">' + BSA.h(BSA.fecha(ev.fecha)) + ' · ' + BSA.h(ev.usuario) + '</div></div>';
    }).join('') || '<p style="font-size:.83rem; color:#64748b;">Sin movimientos.</p>';
  };

  BSA.mostrarHistorial = function (b) {
    $('#hi-id').text(b.id);
    var h = BSA.h, n = BSA.num;
    $('#hi-body').html(
      '<div class="bsa-grid-2" style="font-size:.85rem;">' +
        '<div><span class="bsa-label">Libro</span>' + h(b.libro) + (b.isbn ? '<span class="bsa-sub">ISBN ' + h(b.isbn) + '</span>' : '') + '</div>' +
        '<div><span class="bsa-label">Pedido / Cliente</span>' + BSA.pedidoCliente(b) + '</div>' +
      '</div>' +
      '<div class="bsa-resumen">' +
        '<div class="bsa-card bsa-card-sol"><div class="num">' + n(b.cantidad_solicitada) + '</div><div class="lbl">Solicitada</div></div>' +
        '<div class="bsa-card bsa-card-at"><div class="num">' + n(b.cantidad_atendida) + '</div><div class="lbl">Atendida</div></div>' +
        '<div class="bsa-card bsa-card-pen"><div class="num">' + n(b.cantidad_pendiente) + '</div><div class="lbl">Pendiente</div></div>' +
        '<div class="bsa-card bsa-card-cnt"><div class="num" style="font-size:1rem; padding-top:6px;">' + BSA.badgeEstado(b) + '</div><div class="lbl">Estado</div></div>' +
      '</div>' +
      (b.observaciones ? '<p style="font-size:.83rem;"><span class="bsa-label">Observaciones</span>' + h(b.observaciones) + '</p>' : '') +
      (b.estado === 'anulado' ? '<p class="bsa-aviso-azul" style="background:#fef2f2; border-color:#fecaca; color:#991b1b;">Anulado el ' + h(BSA.fecha(b.fecha_anulacion)) + ' por ' + h(b.usuario_anulacion || '—') + '. Motivo: ' + h(b.motivo_anulacion) + '</p>' : '') +
      '<p class="bsa-label" style="margin-top:6px;">Atenciones</p>' + BSA.atencionesHtml(b) +
      '<p class="bsa-label" style="margin-top:16px;">Historial</p>' + BSA.historialHtml(b)
    );
    $('#ModalHistorialBsa').modal('show');
  };

  window.BSA = BSA;
})();
