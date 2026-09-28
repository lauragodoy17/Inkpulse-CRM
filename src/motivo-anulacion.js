/**
 * /src/motivo-anulacion.js
 * Ventana para confirmar una anulación/rechazo pidiendo el MOTIVO (obligatorio, máx. 300
 * caracteres, con contador). Reemplaza al inkConfirm simple en pedidos, pedidos sin adopción,
 * muestreos y devoluciones.
 *
 * Uso:
 *   inkMotivoAnulacion({ title: '¿Anular este pedido?', btnOk: 'Sí, anular' }, function (motivo) {
 *     window.location = 'php/accion_pedidos.php?rechazar=12&motivo=' + encodeURIComponent(motivo);
 *   });
 */
(function () {
  var MAX = 300;
  var css =
    '.ima-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px;}' +
    '.ima-modal{background:#fff;border-radius:16px;max-width:460px;width:100%;padding:24px 24px 20px;box-shadow:0 20px 50px rgba(15,23,42,.25);font-family:inherit;}' +
    '.ima-icon{width:52px;height:52px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 12px;}' +
    '.ima-title{font-size:1.05rem;font-weight:700;color:#0f172a;text-align:center;margin:0 0 6px;}' +
    '.ima-text{font-size:.85rem;color:#64748b;text-align:center;margin:0 0 14px;}' +
    '.ima-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#475569;margin-bottom:4px;display:block;}' +
    '.ima-textarea{width:100%;min-height:90px;border:1.5px solid #cbd5e1;border-radius:10px;padding:9px 11px;font-size:.88rem;resize:vertical;font-family:inherit;}' +
    '.ima-textarea:focus{outline:none;border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12);}' +
    '.ima-textarea.ima-error{border-color:#dc2626;background:#fef2f2;}' +
    '.ima-pie{display:flex;justify-content:space-between;gap:10px;margin-top:4px;font-size:.74rem;}' +
    '.ima-msg{color:#dc2626;font-weight:600;}' +
    '.ima-cont{color:#64748b;white-space:nowrap;}.ima-cont.cerca{color:#b45309;font-weight:600;}.ima-cont.limite{color:#dc2626;font-weight:700;}' +
    '.ima-btns{display:flex;gap:10px;justify-content:center;margin-top:18px;}' +
    '.ima-btns button{border:none;border-radius:9px;padding:9px 20px;font-size:.86rem;font-weight:700;cursor:pointer;}' +
    '.ima-cancel{background:#f1f5f9;color:#334155;}.ima-ok{background:#dc2626;color:#fff;}.ima-ok:hover{background:#b91c1c;}';
  var s = document.createElement('style');
  s.textContent = css;
  (document.head || document.documentElement).appendChild(s);

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }

  window.inkMotivoAnulacion = function (opts, onOk) {
    opts = opts || {};
    var ov = document.createElement('div');
    ov.className = 'ima-overlay';
    ov.innerHTML =
      '<div class="ima-modal" role="dialog" aria-modal="true">' +
        '<div class="ima-icon"><i class="bi bi-x-octagon"></i></div>' +
        '<p class="ima-title">' + esc(opts.title || '¿Anular?') + '</p>' +
        '<p class="ima-text">' + esc(opts.text || 'Esta acción no se puede deshacer.') + '</p>' +
        '<label class="ima-label" for="_ima_motivo">' + esc(opts.label || 'Motivo de la anulación') + ' *</label>' +
        '<textarea class="ima-textarea" id="_ima_motivo" maxlength="' + MAX + '" placeholder="' + esc(opts.placeholder || 'Escribe por qué se anula…') + '"></textarea>' +
        '<div class="ima-pie"><span class="ima-msg" id="_ima_msg"></span><span class="ima-cont" id="_ima_cont">0 / ' + MAX + ' caracteres</span></div>' +
        '<div class="ima-btns"><button type="button" class="ima-cancel" id="_ima_cancel">Cancelar</button>' +
        '<button type="button" class="ima-ok" id="_ima_ok">' + esc(opts.btnOk || 'Sí, anular') + '</button></div>' +
      '</div>';
    document.body.appendChild(ov);

    var ta = ov.querySelector('#_ima_motivo'), cont = ov.querySelector('#_ima_cont'), msg = ov.querySelector('#_ima_msg');
    function contar() {
      var n = ta.value.length;
      cont.textContent = n + ' / ' + MAX + ' caracteres' + (n >= MAX ? ' — llegaste al límite' : '');
      cont.className = 'ima-cont' + (n >= MAX ? ' limite' : (n >= MAX * 0.9 ? ' cerca' : ''));
      if (ta.value.trim()) { ta.classList.remove('ima-error'); msg.textContent = ''; }
    }
    function cerrar() { ov.remove(); document.removeEventListener('keydown', teclas); }
    function confirmar() {
      var motivo = ta.value.replace(/\s+/g, ' ').trim();
      if (!motivo) { ta.classList.add('ima-error'); msg.textContent = 'Escribe el motivo para poder anular.'; ta.focus(); return; }
      var ok = ov.querySelector('#_ima_ok');
      ok.disabled = true; ok.textContent = 'Procesando…';   // evita doble clic
      cerrar();
      onOk(motivo);
    }
    function teclas(e) { if (e.key === 'Escape') cerrar(); }

    ta.addEventListener('input', contar);
    ov.querySelector('#_ima_cancel').onclick = cerrar;
    ov.querySelector('#_ima_ok').onclick = confirmar;
    ov.addEventListener('click', function (e) { if (e.target === ov) cerrar(); });
    document.addEventListener('keydown', teclas);
    setTimeout(function () { ta.focus(); }, 30);
  };
})();
