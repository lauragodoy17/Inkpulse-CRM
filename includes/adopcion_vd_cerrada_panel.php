<?php
/**
 * Fechas de venta con la adopción CERRADA (pedido de la usuaria 2026-11-25). Lo incluye ajax/tab_adopciones.php en lugar
 * del bloque editable "Venta directa - Eureka" cuando la adopción está cerrada y tiene ese canal.
 *
 * Con la adopción cerrada lo único que se puede cambiar son las fechas de venta: agregar una fecha y reprogramar o
 * cancelar las que siguen programadas (php/calendario_vd_accion.php, el mismo de calendario_venta_directa.php). Cada
 * fecha muestra su estado:
 *  - Programada: aún sin formato de venta directa.
 *  - Ejecutada: con su consecutivo (Formato venta directa No. N), reservado el día de la venta
 *    (includes/solicitudes_vd_datos.php). Al abrir el panel se reservan los de las fechas que ya llegaron.
 *  - Cancelada: adopcion_vd_jornadas.cancelada = 1.
 * También el responsable (contacto) del colegio: nombre, celular y correo (accion=contacto del mismo endpoint).
 *
 * Espera: $bdd, $_GET['colegio'], $gp_periodo['id'].
 */
require_once __DIR__ . '/adopcion_vd_datos.php';
require_once __DIR__ . '/solicitudes_vd_datos.php';

$vdc_col = (int)$_GET['colegio'];
$vdc_per = (int)$gp_periodo['id'];
try {
    svd_asignar_colegio($bdd, $vdc_col, $vdc_per);
} catch (Throwable $e) {
    error_log('Reserva de consecutivos (adopción cerrada): ' . $e->getMessage());
}
$vdc_filas = adopcion_vd_estados($bdd, $vdc_col, $vdc_per);
$vdc_contacto = adopcion_vd_leer($bdd, $vdc_col, $vdc_per)['contacto'];
// Mismas restricciones que php/calendario_vd_accion.php (dm_validar_acceso).
$vdc_puede = !in_array((int)($_SESSION['tipo'] ?? 0), [4, 5, 8], true);
$vdc_hoy = date('Y-m-d');
$vdc_e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$vdc_h12 = function ($h) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', (string)$h, $m)) return '';
    $hh = (int)$m[1];
    return (($hh % 12) ?: 12) . ':' . $m[2] . ($hh < 12 ? ' a. m.' : ' p. m.');
};
$vdc_meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$vdc_dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$vdc_cuenta = ['programada' => 0, 'ejecutada' => 0, 'cancelada' => 0];
foreach ($vdc_filas as $j) $vdc_cuenta[$j['estado']]++;

/** Campos de fecha y horario (src/vd-pickers.js), sin name: se envían por AJAX. */
$vdc_campos = function ($j) use ($vdc_e) {
    return '<div class="vd-f vd-f-fecha"><label>Fecha</label>
              <div class="vd-inp vd-pick" data-tipo="fecha" data-requerido><i class="bi bi-calendar3"></i><input type="text" class="vd-pick-txt" readonly placeholder="Elegir fecha"><input type="hidden" class="vdc-fecha" value="' . $vdc_e($j['fecha']) . '"><i class="bi bi-chevron-down vd-caret"></i></div></div>
            <div class="vd-f"><label>Desde</label>
              <div class="vd-inp vd-pick" data-tipo="hora" data-rol="ini" data-requerido><i class="bi bi-clock"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"><input type="hidden" class="vdc-ini" value="' . $vdc_e($j['hora_inicio']) . '"><i class="bi bi-chevron-down vd-caret"></i></div></div>
            <span class="vd-flecha" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
            <div class="vd-f"><label>Hasta</label>
              <div class="vd-inp vd-pick" data-tipo="hora" data-rol="fin" data-requerido><i class="bi bi-clock-history"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"><input type="hidden" class="vdc-fin" value="' . $vdc_e($j['hora_fin']) . '"><i class="bi bi-chevron-down vd-caret"></i></div></div>
            <span class="vd-duracion"></span>';
};
?>
<style>
  .vdc-resumen { display: flex; flex-wrap: wrap; gap: 6px; margin: -2px 0 12px; }
  .vdc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: .74rem; font-weight: 600; line-height: 1.5; white-space: nowrap; }
  .vdc-chip-programada { background: #e0e7ff; color: #3730a3; }
  .vdc-chip-ejecutada { background: #dcfce7; color: #166534; }
  .vdc-chip-cancelada { background: #fee2e2; color: #991b1b; }
  .vdc-chip a { color: inherit; text-decoration: underline; }
  .vdc-aviso { font-size: .8rem; color: #475569; background: #fff; border: 1px dashed #c7d2fe; border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; }
  .vdc-fila { flex-wrap: wrap; }
  .vdc-fila .vd-campos { flex: 1 1 320px; }
  .vdc-texto { flex: 1 1 260px; font-size: .88rem; color: #1e293b; }
  .vdc-texto small { display: block; color: #64748b; font-size: .74rem; margin-top: 2px; }
  .vdc-lado { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; margin-left: auto; }
  .vdc-acciones { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
  .vdc-btn { border: 1px solid #c7d2fe; background: #fff; color: #3730a3; border-radius: 8px; padding: 4px 10px; font-size: .78rem; font-weight: 600; cursor: pointer; }
  .vdc-btn:hover:not(:disabled) { background: #eef2ff; }
  .vdc-btn:disabled { opacity: .5; cursor: not-allowed; }
  .vdc-btn-ok { background: #4f46e5; border-color: #4f46e5; color: #fff; }
  .vdc-btn-ok:hover:not(:disabled) { background: #4338ca; }
  .vdc-btn-quitar { border-color: #fecaca; color: #b91c1c; }
  .vdc-btn-quitar:hover:not(:disabled) { background: #fef2f2; }
  .vdc-ejecutada { border-color: #bbf7d0; }
  .vdc-cancelada { background: #f8fafc; border-style: dashed; box-shadow: none; }
  .vdc-cancelada .vd-dia, .vdc-cancelada .vdc-texto > span { opacity: .55; }
  .vdc-cancelada .vdc-texto > span { text-decoration: line-through; }
  .vdc-pasada { font-size: .72rem; color: #92400e; }
  .vdc-vacio { font-size: .84rem; color: #64748b; padding: 6px 2px 10px; }
  .vdc-contacto { margin-top: 16px; padding-top: 14px; border-top: 1px dashed #c7d2fe; }
  .vdc-contacto-campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; align-items: end; }
  .vdc-contacto-val { font-size: .88rem; color: #1e293b; }
</style>
<div class="col-12" id="vdc_panel">
  <div class="vd-panel">
    <div class="vd-tit"><i class="bi bi-shop"></i> Venta directa - Eureka · Fechas de venta</div>
    <div class="vdc-aviso"><i class="bi bi-unlock"></i> La adopción está cerrada, pero las <strong>fechas de venta</strong> se pueden agregar, reprogramar o cancelar mientras no se hayan ejecutado.</div>
    <div class="vdc-resumen">
      <span class="vdc-chip vdc-chip-programada"><i class="bi bi-calendar-event"></i> <?= $vdc_cuenta['programada'] ?> programada<?= $vdc_cuenta['programada'] === 1 ? '' : 's' ?></span>
      <span class="vdc-chip vdc-chip-ejecutada"><i class="bi bi-check-circle"></i> <?= $vdc_cuenta['ejecutada'] ?> ejecutada<?= $vdc_cuenta['ejecutada'] === 1 ? '' : 's' ?></span>
      <span class="vdc-chip vdc-chip-cancelada"><i class="bi bi-x-circle"></i> <?= $vdc_cuenta['cancelada'] ?> cancelada<?= $vdc_cuenta['cancelada'] === 1 ? '' : 's' ?></span>
    </div>
    <div id="vdc_lista">
      <?php if (!$vdc_filas): ?>
        <div class="vdc-vacio" id="vdc_vacio">El colegio no tiene fechas de venta registradas.</div>
      <?php endif; ?>
      <?php foreach ($vdc_filas as $j):
        $ts = strtotime($j['fecha']);
        $editable = $vdc_puede && $j['estado'] === 'programada';
      ?>
      <div class="vd-jornada vdc-fila vdc-<?= $j['estado'] ?>" data-id="<?= (int)$j['id'] ?>"
           data-orig="<?= $vdc_e($j['fecha'] . ' ' . $j['hora_inicio'] . ' ' . $j['hora_fin']) ?>">
        <div class="vd-dia" aria-hidden="true"><span class="vd-dia-sem"><?= $vdc_dias[(int)date('w', $ts)] ?></span><strong class="vd-dia-num"><?= (int)date('j', $ts) ?></strong><span class="vd-dia-mes"><?= $vdc_meses[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) ?></span></div>
        <?php if ($editable): ?>
          <div class="vd-campos"><?= $vdc_campos($j) ?></div>
        <?php else: ?>
          <div class="vdc-texto">
            <span><?= date('d/m/Y', $ts) ?> · <?= $vdc_h12($j['hora_inicio']) ?> a <?= $vdc_h12($j['hora_fin']) ?></span>
            <?php if ($j['estado'] === 'cancelada' && $j['cancelada_at']): ?>
              <small>Cancelada el <?= date('d/m/Y h:i a', strtotime($j['cancelada_at'])) ?><?= trim($j['cancelo']) !== '' ? ' por ' . $vdc_e($j['cancelo']) : '' ?></small>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="vdc-lado">
          <?php if ($j['estado'] === 'ejecutada'): ?>
            <span class="vdc-chip vdc-chip-ejecutada"><i class="bi bi-check-circle-fill"></i> Ejecutada ·
              <a href="ver_formato_venta_directa.php?id=<?= (int)$j['formato']['id'] ?>" target="_blank" rel="noopener">Formato venta directa No. <?= (int)$j['formato']['consecutivo'] ?></a></span>
          <?php elseif ($j['estado'] === 'cancelada'): ?>
            <span class="vdc-chip vdc-chip-cancelada"><i class="bi bi-x-circle-fill"></i> Cancelada</span>
          <?php else: ?>
            <span class="vdc-chip vdc-chip-programada"><i class="bi bi-calendar-event"></i> Programada</span>
            <?php if ($j['fecha'] <= $vdc_hoy): ?>
              <span class="vdc-pasada" title="El consecutivo se reserva el día de la venta solo si el colegio tiene al menos un pedido no anulado."><i class="bi bi-exclamation-triangle"></i> Llegó la fecha y aún no tiene consecutivo (sin pedidos)</span>
            <?php endif; ?>
            <?php if ($editable): ?>
              <div class="vdc-acciones">
                <button type="button" class="vdc-btn vdc-btn-ok vdc-guardar" style="display:none"><i class="bi bi-check2"></i> Guardar cambios</button>
                <button type="button" class="vdc-btn vdc-btn-quitar vdc-cancelar"><i class="bi bi-x-circle"></i> Cancelar venta</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($vdc_puede): ?>
      <button type="button" class="vd-agregar" id="vdc_agregar"><i class="bi bi-plus-circle"></i> Agregar fecha de venta</button>
    <?php endif; ?>
    <div class="vdc-contacto" id="vdc_contacto"
         data-orig="<?= $vdc_e(json_encode([$vdc_contacto['nombre'], $vdc_contacto['celular'], $vdc_contacto['correo']])) ?>">
      <div class="vd-sub"><i class="bi bi-person-lines-fill"></i> Responsable del colegio</div>
      <?php if ($vdc_puede): ?>
      <div class="vdc-contacto-campos">
        <div class="vd-f"><label>Nombre <span class="vd-ob">*</span></label>
          <div class="vd-inp"><i class="bi bi-person"></i><input type="text" id="vdc_c_nombre" maxlength="150" placeholder="Nombre y apellido" value="<?= $vdc_e($vdc_contacto['nombre']) ?>"></div></div>
        <div class="vd-f"><label>Celular <span class="vd-ob">*</span></label>
          <div class="vd-inp"><i class="bi bi-phone"></i><input type="tel" id="vdc_c_celular" maxlength="17" inputmode="tel" placeholder="300 000 0000" value="<?= $vdc_e($vdc_contacto['celular']) ?>"></div></div>
        <div class="vd-f"><label>Correo <small>(opcional)</small></label>
          <div class="vd-inp"><i class="bi bi-envelope"></i><input type="email" id="vdc_c_correo" maxlength="150" placeholder="correo@colegio.edu.co" value="<?= $vdc_e($vdc_contacto['correo']) ?>"></div></div>
        <div class="vdc-acciones"><button type="button" class="vdc-btn vdc-btn-ok" id="vdc_c_guardar" style="display:none"><i class="bi bi-check2"></i> Guardar responsable</button></div>
      </div>
      <?php else: ?>
      <div class="vdc-contacto-val">
        <?= $vdc_contacto['nombre'] !== '' ? $vdc_e($vdc_contacto['nombre']) . ($vdc_contacto['celular'] !== '' ? ' · ' . $vdc_e($vdc_contacto['celular']) : '') . ($vdc_contacto['correo'] !== '' ? ' · ' . $vdc_e($vdc_contacto['correo']) : '') : 'Sin responsable registrado.' ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($vdc_puede): ?>
<template id="vdc_plantilla">
  <div class="vd-jornada vdc-fila vdc-nueva">
    <div class="vd-dia vd-dia-vacio" aria-hidden="true"><span class="vd-dia-sem">Día</span><strong class="vd-dia-num">--</strong><span class="vd-dia-mes">&nbsp;</span></div>
    <div class="vd-campos"><?= $vdc_campos(['fecha' => '', 'hora_inicio' => '', 'hora_fin' => '']) ?></div>
    <div class="vdc-lado">
      <span class="vdc-chip vdc-chip-programada"><i class="bi bi-plus-circle"></i> Nueva</span>
      <div class="vdc-acciones">
        <button type="button" class="vdc-btn vdc-btn-ok vdc-agendar"><i class="bi bi-calendar-plus"></i> Agendar</button>
        <button type="button" class="vdc-btn vdc-descartar"><i class="bi bi-x"></i> Descartar</button>
      </div>
    </div>
  </div>
</template>
<script src="src/vd-pickers.js?v=20261110"></script>
<script>
  (function () {
    var colegio = <?= $vdc_col ?>, periodo = <?= $vdc_per ?>;
    var urlTab = <?= json_encode('ajax/tab_adopciones.php?' . ($_SERVER['QUERY_STRING'] ?? '')) ?>;
    var $panel = $('#vdc_panel');
    var avisar = function (msg, tipo) { if (typeof window.inkToast === 'function') window.inkToast(msg, tipo); else alert(msg); };
    var val = function ($f, cls) { return $f.find('.' + cls).val() || ''; };

    // Día de la semana, duración y aviso si "hasta" no es posterior a "desde" (como en la adopción abierta).
    function pintar($f) {
      var f = val($f, 'vdc-fecha'), ini = val($f, 'vdc-ini'), fin = val($f, 'vdc-fin');
      var d = f ? new Date(f + 'T12:00:00') : null, ok = d && !isNaN(d);
      $f.find('.vd-dia').toggleClass('vd-dia-vacio', !ok);
      $f.find('.vd-dia-sem').text(ok ? d.toLocaleDateString('es-CO', { weekday: 'short' }).replace('.', '') : 'Día');
      $f.find('.vd-dia-num').text(ok ? d.getDate() : '--');
      $f.find('.vd-dia-mes').html(ok ? ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][d.getMonth()] + ' ' + d.getFullYear() : '&nbsp;');
      var $dur = $f.find('.vd-duracion').removeClass('vd-dur-error').text('');
      $f.removeClass('vd-error');
      if (ini && fin) {
        var m = (parseInt(fin.substr(0, 2), 10) * 60 + parseInt(fin.substr(3, 2), 10)) - (parseInt(ini.substr(0, 2), 10) * 60 + parseInt(ini.substr(3, 2), 10));
        if (m > 0) $dur.text((Math.floor(m / 60) ? Math.floor(m / 60) + ' h' : '') + (m % 60 ? ' ' + (m % 60) + ' min' : ''));
        else { $dur.addClass('vd-dur-error').html('<i class="bi bi-exclamation-triangle"></i> Revisa el horario'); $f.addClass('vd-error'); }
      }
      // Fila existente: "Guardar cambios" solo si cambió algo.
      if ($f.data('orig') !== undefined) $f.find('.vdc-guardar').toggle([f, ini, fin].join(' ') !== String($f.data('orig')));
    }

    function datos($f) {
      if (window.vdPickers.faltantes($f).length) { avisar('Completa la fecha y el horario de la venta.', 'warn'); return null; }
      if ($f.hasClass('vd-error')) { avisar('La hora "hasta" debe ser posterior a la hora "desde".', 'warn'); return null; }
      return { fecha: val($f, 'vdc-fecha'), hora_ini: val($f, 'vdc-ini'), hora_fin: val($f, 'vdc-fin') };
    }

    function enviar($btn, post) {
      var $botones = $panel.find('button').prop('disabled', true);
      post.origen = 'adopcion';
      $.post('php/calendario_vd_accion.php', post, null, 'json').done(function (r) {
        if (r && r.ok) {
          avisar(r.msg || 'Listo.', 'success');
          // Se recarga la pestaña para ver el nuevo estado.
          $('#adopciones').load(urlTab, function () {
            var el = document.getElementById('vdc_panel');
            if (el) el.scrollIntoView({ block: 'center' });
          });
        } else {
          avisar((r && r.error) || 'No se pudo guardar.', 'error');
          $botones.prop('disabled', false);
        }
      }).fail(function () {
        avisar('No se pudo guardar. Revisa la conexión.', 'error');
        $botones.prop('disabled', false);
      });
    }

    window.vdPickers.sincronizar($panel.find('.vd-pick'));
    $panel.find('.vdc-fila').has('.vd-campos').each(function () { pintar($(this)); });
    $panel.on('input change', '.vdc-fila input', function () {
      var $f = $(this).closest('.vdc-fila');
      // Si "Desde" queda igual o después de "Hasta", se vacía "Hasta" para elegirla de nuevo.
      if ($(this).hasClass('vdc-ini')) {
        var $fin = $f.find('.vdc-fin');
        if ($fin.val() && this.value && $fin.val() <= this.value) { $fin.val(''); window.vdPickers.sincronizar($fin.closest('.vd-pick')); }
      }
      pintar($f);
    });

    $('#vdc_agregar').on('click', function () {
      var $f = $($('#vdc_plantilla').html());
      $('#vdc_vacio').remove();
      $('#vdc_lista').append($f);
      pintar($f);
      $f.find('.vd-pick-txt').first().trigger('click').trigger('focus');
    });
    $panel.on('click', '.vdc-descartar', function () {
      window.vdPickers.cerrar(false);
      $(this).closest('.vdc-fila').remove();
    });
    $panel.on('click', '.vdc-agendar', function () {
      var d = datos($(this).closest('.vdc-fila'));
      if (!d) return;
      var c = contactoPost();
      if (!c.contacto_nombre || !c.contacto_celular) {
        avisar('Para agendar la venta registra el responsable del colegio (nombre y celular).', 'warn');
        $('#vdc_contacto')[0].scrollIntoView({ block: 'center' });
        $(c.contacto_nombre ? '#vdc_c_celular' : '#vdc_c_nombre').focus();
        return;
      }
      enviar($(this), $.extend({ accion: 'agendar', id_colegio: colegio, id_periodo: periodo }, d, c));
    });
    $panel.on('click', '.vdc-guardar', function () {
      var $f = $(this).closest('.vdc-fila'), d = datos($f);
      if (d) enviar($(this), $.extend({ accion: 'reprogramar', id: $f.data('id') }, d));
    });
    // Responsable del colegio: "Guardar responsable" solo si cambió algo.
    var $c = $('#vdc_contacto');
    // Al agendar una fecha también va el responsable (obligatorio, 2026-11-25): se guarda en la adopción.
    var contactoPost = function () { var v = contactoActual(); return { contacto_nombre: v[0], contacto_celular: v[1], contacto_correo: v[2] }; };
    var contactoActual = function () { return [$.trim($('#vdc_c_nombre').val()), $.trim($('#vdc_c_celular').val()), $.trim($('#vdc_c_correo').val())]; };
    $c.on('input', 'input', function () { $('#vdc_c_guardar').toggle(JSON.stringify(contactoActual()) !== JSON.stringify($c.data('orig'))); });
    $('#vdc_c_guardar').on('click', function () {
      var v = contactoActual();
      if (!v[0]) { avisar('Escribe el nombre del responsable del colegio.', 'warn'); $('#vdc_c_nombre').focus(); return; }
      if (!v[1]) { avisar('Escribe el celular del responsable del colegio.', 'warn'); $('#vdc_c_celular').focus(); return; }
      enviar($(this), $.extend({ accion: 'contacto', id_colegio: colegio, id_periodo: periodo }, contactoPost()));
    });

    $panel.on('click', '.vdc-cancelar', function () {
      window.vdPickers.cerrar(false);
      var $f = $(this).closest('.vdc-fila'), $btn = $(this);
      var txt = $f.find('.vd-dia-num').text() + ' ' + $f.find('.vd-dia-mes').text();
      var opts = { type: 'danger', title: '¿Cancelar la venta del ' + txt + '?', text: 'La fecha queda como cancelada en la adopción y sale del calendario.', btnOk: 'Sí, cancelar venta' };
      var hacer = function () { enviar($btn, { accion: 'eliminar', id: $f.data('id') }); };
      if (window.inkConfirm) window.inkConfirm(opts, hacer);
      else if (confirm(opts.title + ' ' + opts.text)) hacer();
    });
  })();
</script>
<?php endif; ?>
