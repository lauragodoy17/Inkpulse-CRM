/*
 * Selectores de fecha y hora del bloque "Venta directa - Eureka" de Adopciones (ajax/tab_adopciones.php, 2026-11-10).
 *
 * Marcado de cada campo:
 *   <div class="vd-inp vd-pick" data-tipo="fecha|hora">
 *     <i ...></i><input type="text" class="vd-pick-txt" readonly><input type="hidden" name="..." value="Y-m-d | HH:MM">
 *   </div>
 * El valor real va en el input oculto (es el que se envía); el de texto solo lo muestra en español.
 * En un campo de hora con data-rol="fin", las horas anteriores al "desde" de la misma fila salen deshabilitadas.
 */
(function ($) {
  if (window.vdPickers) return; // la pestaña se recarga por AJAX: se monta una sola vez

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
  var MINUTOS = ['00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55'];

  var $pop = $('<div class="vdp-pop" role="dialog" aria-modal="false"></div>').hide().appendTo('body');
  var $campo = null; // .vd-pick abierto
  var vista = null;  // {anio, mes} del calendario

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseFecha(v) { return /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? new Date(v + 'T12:00:00') : null; }
  function hora12(h, m) {
    var suf = h < 12 ? 'a. m.' : 'p. m.';
    var h12 = h % 12 === 0 ? 12 : h % 12;
    return h12 + (m === undefined ? '' : ':' + m) + ' ' + suf;
  }

  function texto(tipo, v) {
    if (!v) return '';
    if (tipo === 'fecha') {
      var d = parseFecha(v);
      if (!d) return '';
      var s = d.toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).replace(/\./g, '');
      return s.charAt(0).toUpperCase() + s.slice(1);
    }
    var p = v.split(':');
    return hora12(parseInt(p[0], 10), p[1]);
  }

  /** Copia el valor oculto al texto visible (también sirve para las filas nuevas o vaciadas). */
  function sincronizar($c) {
    $c.each(function () {
      var $x = $(this);
      $x.find('.vd-pick-txt').val(texto($x.data('tipo'), $x.find('input[type=hidden]').val()));
    });
  }

  function fijar(v, cerrarDespues) {
    if (!$campo) return;
    var $h = $campo.find('input[type=hidden]');
    $h.val(v);
    sincronizar($campo);
    $h.trigger('change');
    $campo.removeClass('vd-falta');
    if (cerrarDespues) cerrar(true);
  }

  function cerrar(devolverFoco) {
    if (!$campo) return;
    var $txt = $campo.find('.vd-pick-txt');
    $campo.removeClass('vd-abierto');
    $campo = null;
    $pop.hide().empty();
    if (devolverFoco) $txt.trigger('focus');
  }

  function posicionar() {
    if (!$campo) return;
    var o = $campo.offset(), h = $campo.outerHeight(), w = $pop.outerWidth(), ph = $pop.outerHeight();
    var left = Math.max(8, Math.min(o.left, $(window).scrollLeft() + $(window).width() - w - 8));
    var top = o.top + h + 6;
    if (top + ph > $(window).scrollTop() + $(window).height() && o.top - ph - 6 > $(window).scrollTop()) top = o.top - ph - 6;
    $pop.css({ left: left, top: top });
  }

  // ── Calendario ─────────────────────────────────────────────
  function pintarCalendario() {
    var sel = parseFecha($campo.find('input[type=hidden]').val());
    var hoy = ymd(new Date());
    var primero = new Date(vista.anio, vista.mes, 1, 12);
    var inicio = (primero.getDay() + 6) % 7; // lunes = 0
    var diasMes = new Date(vista.anio, vista.mes + 1, 0).getDate();

    var html = '<div class="vdp-cal">'
      + '<div class="vdp-cab">'
      + '<button type="button" class="vdp-nav" data-mes="-1" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></button>'
      + '<span class="vdp-titulo">' + MESES[vista.mes] + ' ' + vista.anio + '</span>'
      + '<button type="button" class="vdp-nav" data-mes="1" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></button>'
      + '</div><div class="vdp-sem">';
    DIAS.forEach(function (d, i) { html += '<span' + (i > 4 ? ' class="vdp-finde"' : '') + '>' + d + '</span>'; });
    html += '</div><div class="vdp-dias">';
    for (var i = 0; i < inicio; i++) html += '<span></span>';
    for (var d = 1; d <= diasMes; d++) {
      var f = vista.anio + '-' + pad(vista.mes + 1) + '-' + pad(d);
      var cls = 'vdp-dia';
      if (f === hoy) cls += ' vdp-hoy';
      if (sel && f === ymd(sel)) cls += ' vdp-sel';
      if (f < hoy) cls += ' vdp-pasado';
      if ((inicio + d - 1) % 7 > 4) cls += ' vdp-finde';
      html += '<button type="button" class="' + cls + '" data-fecha="' + f + '">' + d + '</button>';
    }
    html += '</div><div class="vdp-pie">'
      + '<button type="button" class="vdp-link" data-accion="borrar">Borrar</button>'
      + '<button type="button" class="vdp-link vdp-link-fuerte" data-accion="hoy">Hoy</button>'
      + '</div></div>';
    $pop.html(html);
  }

  // ── Hora ───────────────────────────────────────────────────
  function limiteDesde() {
    // Para "Hasta": la hora "Desde" de la misma fila (minutos totales) o null.
    if ($campo.data('rol') !== 'fin') return null;
    var v = $campo.closest('.vd-jornada').find('.vd-pick[data-rol=ini] input[type=hidden]').val();
    if (!/^\d{2}:\d{2}$/.test(v || '')) return null;
    return parseInt(v.substr(0, 2), 10) * 60 + parseInt(v.substr(3, 2), 10);
  }

  function pintarHora() {
    var v = $campo.find('input[type=hidden]').val();
    var hSel = /^\d{2}:\d{2}$/.test(v || '') ? parseInt(v.substr(0, 2), 10) : null;
    var mSel = hSel !== null ? v.substr(3, 2) : null;
    var lim = limiteDesde();

    var html = '<div class="vdp-hora"><div class="vdp-col"><div class="vdp-col-tit">Hora</div><div class="vdp-horas">';
    for (var h = 0; h < 24; h++) {
      var dis = lim !== null && h * 60 + 55 <= lim;
      html += '<button type="button" class="vdp-h' + (h === hSel ? ' vdp-sel' : '') + '" data-h="' + h + '"' + (dis ? ' disabled' : '') + '>' + hora12(h) + '</button>';
    }
    html += '</div></div><div class="vdp-col"><div class="vdp-col-tit">Minutos</div><div class="vdp-mins">';
    MINUTOS.forEach(function (m) {
      var dis = hSel === null || (lim !== null && hSel * 60 + parseInt(m, 10) <= lim);
      html += '<button type="button" class="vdp-m' + (m === mSel ? ' vdp-sel' : '') + '" data-m="' + m + '"' + (dis ? ' disabled' : '') + '>:' + m + '</button>';
    });
    html += '</div>' + (hSel === null ? '<p class="vdp-ayuda">Primero elige la hora</p>' : '') + '</div></div>'
      + '<div class="vdp-pie"><button type="button" class="vdp-link" data-accion="borrar">Borrar</button>'
      + '<button type="button" class="vdp-link vdp-link-fuerte" data-accion="listo">Listo</button></div>';
    $pop.html(html);
    var $s = $pop.find('.vdp-h.vdp-sel');
    var $lista = $pop.find('.vdp-horas');
    var $ancla = $s.length ? $s : $pop.find('.vdp-h:not(:disabled)').eq(lim === null ? 7 : 0); // sin valor: arranca en 7 a. m.
    if ($ancla.length) $lista.scrollTop($ancla.position().top + $lista.scrollTop() - 6);
  }

  function abrir($c) {
    if ($campo && $campo.is($c)) return;
    cerrar(false);
    $campo = $c.addClass('vd-abierto');
    $pop.show(); // visible antes de pintar: la lista de horas necesita medidas para desplazarse a la hora elegida
    if ($c.data('tipo') === 'fecha') {
      var d = parseFecha($c.find('input[type=hidden]').val()) || new Date();
      vista = { anio: d.getFullYear(), mes: d.getMonth() };
      $pop.attr('data-tipo', 'fecha');
      pintarCalendario();
    } else {
      $pop.attr('data-tipo', 'hora');
      pintarHora();
    }
    $pop.show();
    posicionar();
  }

  // ── Eventos ────────────────────────────────────────────────
  $(document).on('click.vdp', '.vd-pick .vd-pick-txt', function () {
    if (!this.disabled) abrir($(this).closest('.vd-pick'));
  });
  $(document).on('keydown.vdp', '.vd-pick .vd-pick-txt', function (e) {
    if (this.disabled) return;
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); abrir($(this).closest('.vd-pick')); }
    else if (e.key === 'Escape') cerrar(true);
    else if ((e.key === 'Backspace' || e.key === 'Delete') && !$campo) {
      $campo = $(this).closest('.vd-pick'); fijar('', false); $campo = null;
    }
  });
  $pop.on('mousedown', function (e) { e.preventDefault(); }); // no quitar el foco del campo
  $pop.on('click', '.vdp-nav', function () {
    vista.mes += parseInt($(this).data('mes'), 10);
    if (vista.mes < 0) { vista.mes = 11; vista.anio--; }
    if (vista.mes > 11) { vista.mes = 0; vista.anio++; }
    pintarCalendario();
  });
  $pop.on('click', '.vdp-dia', function () { fijar($(this).data('fecha'), true); });
  $pop.on('click', '.vdp-h', function () {
    var h = parseInt($(this).data('h'), 10);
    var v = $campo.find('input[type=hidden]').val();
    var m = /^\d{2}:\d{2}$/.test(v || '') ? v.substr(3, 2) : '00';
    var lim = limiteDesde();
    if (lim !== null && h * 60 + parseInt(m, 10) <= lim) { // el minuto anterior ya no sirve: el primero válido
      m = MINUTOS.filter(function (x) { return h * 60 + parseInt(x, 10) > lim; })[0] || '00';
    }
    fijar(pad(h) + ':' + m, false);
    var top = $pop.find('.vdp-horas').scrollTop();
    pintarHora();
    $pop.find('.vdp-horas').scrollTop(top);
  });
  $pop.on('click', '.vdp-m', function () {
    var v = $campo.find('input[type=hidden]').val();
    fijar(v.substr(0, 2) + ':' + $(this).data('m'), true);
  });
  $pop.on('click', '[data-accion]', function () {
    var a = $(this).data('accion');
    if (a === 'borrar') fijar('', true);
    else if (a === 'hoy') fijar(ymd(new Date()), true);
    else cerrar(true);
  });
  $(document).on('mousedown.vdp', function (e) {
    if (!$campo) return;
    if ($(e.target).closest('.vdp-pop').length || $(e.target).closest('.vd-pick').is($campo)) return;
    cerrar(false);
  });
  $(document).on('keydown.vdpEsc', function (e) { if (e.key === 'Escape' && $campo) cerrar(true); });
  $(window).on('resize.vdp scroll.vdp', posicionar);

  window.vdPickers = {
    sincronizar: sincronizar,
    cerrar: cerrar,
    /** Campos obligatorios vacíos dentro de $raiz (los marca en rojo). */
    faltantes: function ($raiz) {
      var $f = $raiz.find('.vd-pick').filter(function () {
        var $h = $(this).find('input[type=hidden]');
        return !$h.prop('disabled') && $(this).is('[data-requerido]') && !$h.val();
      });
      $raiz.find('.vd-pick').removeClass('vd-falta');
      $f.addClass('vd-falta');
      return $f;
    }
  };
})(jQuery);
