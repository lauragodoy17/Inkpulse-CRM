/**
 * Calendario de fechas en español para "Gestión y seguimiento" (ajax/tab_visitas.php) y "Ejecutar visita"
 * (evento.php, visita_detallado_semanal.php). Usa el air-datepicker de la plantilla (vendors/scripts/core.js),
 * así que se carga después de core.js; los estilos están en src/seg-calendario.css.
 *
 *   segCalendario('#campo_txt', '#campo_oculto', { minDate: ..., maxDate: ... })
 * El campo visible muestra dd/mm/aaaa y el oculto recibe aaaa-mm-dd (lo que se envía al servidor).
 */
(function ($) {
  var ES = {
    days: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
    daysShort: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
    daysMin: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá'],
    months: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
    monthsShort: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
    today: 'Hoy', clear: 'Limpiar', dateFormat: 'dd/mm/yyyy', timeFormat: 'hh:ii', firstDay: 1
  };

  function fechaDesdeHoy(dias) {
    var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + dias); return d;
  }

  function segCalendario(txt, oculto, opc) {
    var $t = $(txt);
    if (!$t.length || !$.fn.datepicker) return null;
    $t.on('keydown paste', function (ev) { if (ev.key !== 'Tab') ev.preventDefault(); });
    var dp = $t.datepicker($.extend({
      language: ES, classes: 'seg-dp', autoClose: true, position: 'bottom left', offset: 8,
      altField: oculto, altFieldDateFormat: 'yyyy-mm-dd', navTitles: { days: 'MM <i>yyyy</i>' },
      onSelect: function (fd) { if (!fd) $(oculto).val(''); }
    }, opc)).data('datepicker');
    // El calendario se dibuja fuera del modal con coordenadas de la página; con el modal abierto la
    // página no se desplaza y lo que pasa del borde inferior queda cortado. Se ubica respecto a la
    // pantalla: abajo si cabe, si no arriba, y siempre dentro de la ventana.
    dp.setPosition = function () {
      var r = this.$el[0].getBoundingClientRect(),
          h = this.$datepicker.outerHeight(), w = this.$datepicker.outerWidth(),
          vh = window.innerHeight, vw = window.innerWidth, m = 8,
          abajo = vh - r.bottom >= h + m * 2 || vh - r.bottom >= r.top,
          top = abajo ? r.bottom + m : r.top - h - m;
      this._setPositionClasses((abajo ? 'bottom' : 'top') + ' left');
      this.$datepicker.addClass(this.opts.classes).css({
        position: 'fixed',
        top: Math.max(m, Math.min(top, vh - h - m)),
        left: Math.max(m, Math.min(r.left, vw - w - m))
      });
    };
    // Si el modal se desplaza o cambia la ventana, sigue a su campo; al cerrar el modal se cierra.
    var $modal = $t.closest('.modal');
    $modal.on('scroll', function () { if (dp.visible) dp.setPosition(); });
    $modal.on('hide.bs.modal', function () { dp.hide(); });
    $(window).on('resize', function () { if (dp.visible) dp.setPosition(); });
    return dp;
  }

  window.segCalendario = segCalendario;
  window.segFechaDesdeHoy = fechaDesdeHoy;
})(jQuery);
