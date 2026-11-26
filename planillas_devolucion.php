<?php
/**
 * /planillas_devolucion.php
 * Menú Venta directa → Planillas de devolución (pedido de la usuaria 2026-11-25): se elige un pedido de venta directa y
 * se genera su planilla de devolución (PDF para que bodega cuente lo devuelto, con firmas). Cada planilla queda en el
 * histórico con su consecutivo, se le puede adjuntar un archivo (la planilla firmada) y se puede anular con motivo.
 * Lógica en includes/planillas_devolucion_datos.php; acciones en php/planillas_devolucion_accion.php.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/planillas_devolucion_datos.php");

dm_validar_acceso();
pdv_asegurar($bdd);

$periodos = pdv_periodos($bdd);
$ids_periodos = array_map('intval', array_column($periodos, 'id'));
$id_pedido_sel = (int)($_GET['id_pedido'] ?? 0);
$periodo_sel = isset($_GET['periodo']) && in_array((int)$_GET['periodo'], $ids_periodos, true) ? (int)$_GET['periodo'] : 0;
if (!$periodo_sel && $id_pedido_sel) {
    $st = $bdd->prepare("SELECT id_periodo FROM pedidos WHERE id = ?");
    $st->execute([$id_pedido_sel]);
    $p_per = (int)$st->fetchColumn();
    if (in_array($p_per, $ids_periodos, true)) $periodo_sel = $p_per;
}
if (!$periodo_sel) $periodo_sel = $ids_periodos[0] ?? 0;

$pedidos = $periodo_sel ? pdv_pedidos($bdd, $periodo_sel) : [];
$planillas = $periodo_sel ? pdv_listar($bdd, $periodo_sel) : [];
$por_colegio = [];
foreach ($pedidos as $p) $por_colegio[$p['colegio']][] = $p;
$activas = count(array_filter($planillas, fn($f) => (int)$f['estado'] === PDV_ESTADO_ACTIVA));
$con_adjunto = count(array_filter($planillas, fn($f) => (int)$f['estado'] === PDV_ESTADO_ACTIVA && $f['adjunto']));

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Planillas de devolución</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .pd-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; box-shadow:0 1px 3px rgba(15,23,42,.05); margin-bottom:16px; }
    .pd-tit { font-size:.82rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f172a; margin:0 0 12px; display:flex; gap:8px; align-items:center; }
    .pd-sub { font-size:.78rem; color:#64748b; }
    .pd-gen { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .pd-gen select, .pd-filtros select, .pd-filtros input { height:40px; border:1px solid #cbd5e1; border-radius:9px; padding:0 10px; font-size:.88rem; background:#fff; }
    .pd-gen select { flex:1 1 380px; }
    .pd-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px; }
    .pd-filtros input { flex:1 1 260px; }
    .pd-btn { display:inline-flex; align-items:center; gap:6px; height:40px; padding:0 16px; border-radius:9px; border:1px solid transparent; font-size:.86rem; font-weight:700; cursor:pointer; text-decoration:none !important; }
    .pd-btn-ok { background:#4f46e5; color:#fff; } .pd-btn-ok:hover { background:#4338ca; color:#fff; }
    .pd-btn:disabled { opacity:.6; cursor:wait; }
    .pd-mini { display:inline-flex; align-items:center; gap:4px; padding:4px 9px; border-radius:7px; font-size:.76rem; font-weight:700; border:1px solid #c7d2fe; background:#fff; color:#3730a3; cursor:pointer; text-decoration:none !important; white-space:nowrap; }
    .pd-mini:hover { background:#eef2ff; color:#3730a3; }
    .pd-mini.rojo { border-color:#fecaca; color:#b91c1c; } .pd-mini.rojo:hover { background:#fef2f2; }
    .pd-mini.verde { border-color:#bbf7d0; color:#166534; } .pd-mini.verde:hover { background:#f0fdf4; }
    .pd-resumen { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .pd-chip { padding:4px 11px; border-radius:999px; font-size:.76rem; font-weight:700; background:#f1f5f9; color:#334155; }
    .pd-chip.azul { background:#e0e7ff; color:#3730a3; } .pd-chip.verde { background:#dcfce7; color:#166534; } .pd-chip.ambar { background:#fef3c7; color:#92400e; }
    .pd-tabla { width:100%; border-collapse:collapse; font-size:.85rem; }
    .pd-tabla th { background:#f1f5f9; color:#334155; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:800; padding:8px 10px; border:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
    .pd-tabla td { padding:8px 10px; border:1px solid #e2e8f0; vertical-align:middle; }
    .pd-tabla td small { display:block; color:#64748b; font-size:.72rem; }
    .pd-num { font-weight:800; color:#4338ca; white-space:nowrap; }
    .pd-anulada td { background:#f8fafc; color:#94a3b8; }
    .pd-anulada .pd-num { color:#94a3b8; text-decoration:line-through; }
    .pd-est { font-size:.68rem; font-weight:800; border-radius:999px; padding:2px 8px; white-space:nowrap; }
    .pd-est.ok { background:#dcfce7; color:#166534; } .pd-est.pend { background:#fef3c7; color:#92400e; } .pd-est.anul { background:#fee2e2; color:#991b1b; }
    .pd-acc { display:flex; gap:6px; flex-wrap:wrap; }
    .pd-vacio { padding:18px; text-align:center; color:#64748b; font-size:.88rem; }
    .pd-aviso { display:flex; gap:10px; align-items:flex-start; border-radius:10px; padding:10px 14px; font-size:.84rem; margin-top:10px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="page-header">
        <div class="title"><h4>Planillas de devolución</h4></div>
        <div class="pd-sub" style="font-size:.84rem;margin-top:4px;">Planilla para que bodega cuente la devolución de un pedido de venta directa: títulos remisionados en World Office, casillas para lo contado y firmas del responsable de venta y del auxiliar de bodega.</div>
      </div>

      <div class="pd-card">
        <form method="get" class="pd-filtros" style="margin-bottom:14px;">
          <select name="periodo" onchange="this.form.submit()">
            <?php if (!$periodos): ?><option value="">Sin períodos</option><?php endif; ?>
            <?php foreach ($periodos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $periodo_sel ? 'selected' : '' ?>>Período <?= $h($p['periodo']) ?></option><?php endforeach; ?>
          </select>
        </form>
        <div class="pd-tit"><i class="bi bi-file-earmark-plus"></i> Generar planilla</div>
        <?php if (!$pedidos): ?>
          <div class="pd-vacio">No hay pedidos de venta directa en este período.</div>
        <?php else: ?>
        <div class="pd-gen">
          <select id="pd-pedido">
            <option value="">Elegir pedido...</option>
            <?php foreach ($por_colegio as $colegio => $lista): ?>
            <optgroup label="<?= $h($colegio) ?>">
              <?php foreach ($lista as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-planillas="<?= (int)$p['planillas'] ?>" <?= (int)$p['id'] === $id_pedido_sel ? 'selected' : '' ?>>
                Pedido #<?= (int)$p['id'] ?> · <?= $h($p['estado_txt']) ?> · <?= date('d/m/Y', strtotime($p['fecha'])) ?><?= (int)$p['planillas'] ? ' · ' . (int)$p['planillas'] . ' planilla' . ((int)$p['planillas'] === 1 ? '' : 's') : '' ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
          <button type="button" class="pd-btn pd-btn-ok" id="pd-generar"><i class="bi bi-file-earmark-pdf"></i> Generar planilla</button>
        </div>
        <div class="pd-sub" style="margin-top:8px;">Los títulos y unidades se toman de las remisiones <strong>CEUR</strong> del pedido en World Office; si no tiene ninguna, de lo <strong>solicitado en el pedido</strong>. Quedan guardados al generar la planilla: al descargarla de nuevo sale igual.</div>
        <?php endif; ?>
      </div>

      <div class="pd-card">
        <div class="pd-tit"><i class="bi bi-clock-history"></i> Histórico de planillas</div>
        <div class="pd-resumen">
          <span class="pd-chip azul"><?= $activas ?> planilla<?= $activas === 1 ? '' : 's' ?></span>
          <span class="pd-chip verde"><?= $con_adjunto ?> con archivo adjunto</span>
          <span class="pd-chip ambar"><?= $activas - $con_adjunto ?> sin adjunto</span>
        </div>
        <div class="pd-filtros"><input type="text" id="pd-buscar" placeholder="Buscar por No., pedido, colegio o responsable..."></div>
        <?php if (!$planillas): ?>
          <div class="pd-vacio">Aún no se han generado planillas en este período.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="pd-tabla" id="pd-tabla">
            <thead><tr><th>No.</th><th>Pedido</th><th>Colegio</th><th>Fecha de venta</th><th>Responsable de venta</th><th>Unid.</th><th>Generada</th><th>Archivo adjunto</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($planillas as $f):
              $activa = (int)$f['estado'] === PDV_ESTADO_ACTIVA;
              $buscar = mb_strtolower(pdv_numero($f['id']) . ' #' . $f['id_pedido'] . ' ' . $f['colegio'] . ' ' . $f['responsable']); ?>
            <tr class="<?= $activa ? '' : 'pd-anulada' ?>" data-id="<?= (int)$f['id'] ?>" data-buscar="<?= $h($buscar) ?>">
              <td><span class="pd-num"><?= $h(pdv_numero($f['id'])) ?></span>
                <?php if (!$activa): ?><small><span class="pd-est anul">Anulada</span></small><?php endif; ?></td>
              <td><a href="pedido_colegio.php?id_pedido=<?= (int)$f['id_pedido'] ?>" target="_blank">#<?= (int)$f['id_pedido'] ?></a></td>
              <td><?= $h($f['colegio']) ?><small><?= $f['origen'] === 'pedido' ? 'Títulos solicitados en el pedido (sin remisión CEUR)' : $h($f['remisiones']) ?></small></td>
              <td><?= $f['fecha_venta'] ? date('d/m/Y', strtotime($f['fecha_venta'])) : '—' ?><?php if ($f['horario']): ?><small><?= $h($f['horario']) ?></small><?php endif; ?></td>
              <td><?= $f['responsable'] ? $h($f['responsable']) : '<span class="pd-sub">Sin registrar</span>' ?></td>
              <td style="text-align:center;font-weight:700"><?= $h(pdv_num($f['total_unidades'])) ?></td>
              <td style="white-space:nowrap"><?= date('d/m/Y g:i a', strtotime($f['created_at'])) ?><small><?= $h(trim($f['usuario'])) ?></small></td>
              <td>
                <?php if ($f['adjunto']): ?>
                  <a href="adjuntos/planillas_devolucion/<?= rawurlencode($f['adjunto']) ?>" target="_blank" class="pd-mini verde" title="<?= $h($f['adjunto_nombre']) ?>"><i class="bi bi-paperclip"></i> Ver archivo</a>
                  <small><?= date('d/m/Y g:i a', strtotime($f['adjunto_at'])) ?><?= trim((string)$f['usuario_adjunto']) !== '' ? ' · ' . $h(trim($f['usuario_adjunto'])) : '' ?></small>
                <?php elseif ($activa): ?>
                  <span class="pd-est pend">Sin adjunto</span>
                <?php else: ?>—<?php endif; ?>
                <?php if (!$activa): ?><small>Anulada el <?= date('d/m/Y', strtotime($f['anulada_at'])) ?><?= trim((string)$f['usuario_anula']) !== '' ? ' por ' . $h(trim($f['usuario_anula'])) : '' ?>: <?= $h($f['motivo_anulacion']) ?></small><?php endif; ?>
              </td>
              <td>
                <div class="pd-acc">
                  <a href="php/planilla_devolucion.php?id=<?= (int)$f['id'] ?>" target="_blank" class="pd-mini"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                  <?php if ($activa): ?>
                  <button type="button" class="pd-mini pd-adjuntar"><i class="bi bi-upload"></i> <?= $f['adjunto'] ? 'Reemplazar' : 'Adjuntar' ?></button>
                  <button type="button" class="pd-mini rojo pd-anular"><i class="bi bi-x-circle"></i> Anular</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <div class="pd-aviso"><i class="bi bi-info-circle"></i><div>Adjunta la planilla firmada (PDF, foto, Word o Excel, hasta 15 MB). Si la reemplazas, el archivo anterior queda guardado en el servidor.</div></div>
      </div>
      <input type="file" id="pd-archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" style="display:none">
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
  var avisar = function (m, t) { if (typeof window.inkToast === 'function') window.inkToast(m, t); else alert(m); };
  var recargar = function () { window.location.href = 'planillas_devolucion.php?periodo=<?= (int)$periodo_sel ?>'; };

  // Generar: la pestaña del PDF se abre en el clic (si se abre después de la respuesta, el navegador la bloquea).
  $('#pd-generar').on('click', function () {
    var $sel = $('#pd-pedido'), id = $sel.val(), $b = $(this);
    if (!id) { avisar('Elige el pedido.', 'warn'); $sel.focus(); return; }
    var hacer = function () {
      var ventana = window.open('', '_blank');
      if (ventana) ventana.document.write('<p style="font-family:Arial;padding:30px">Generando la planilla de devolución del pedido #' + id + '...</p>');
      $b.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Consultando World Office...');
      $.post('php/planillas_devolucion_accion.php', { accion: 'generar', id_pedido: id }, null, 'json').done(function (r) {
        if (r && r.ok) {
          if (ventana) ventana.location.href = r.url; else window.open(r.url, '_blank');
          avisar('Se generó la planilla ' + r.numero + '.', 'success');
          setTimeout(recargar, 900);
        } else {
          if (ventana) ventana.close();
          avisar((r && r.error) || 'No se pudo generar la planilla.', 'error');
          $b.prop('disabled', false).html('<i class="bi bi-file-earmark-pdf"></i> Generar planilla');
        }
      }).fail(function () {
        if (ventana) ventana.close();
        avisar('No se pudo generar la planilla. Revisa la conexión.', 'error');
        $b.prop('disabled', false).html('<i class="bi bi-file-earmark-pdf"></i> Generar planilla');
      });
    };
    var n = parseInt($sel.find('option:selected').data('planillas'), 10) || 0;
    if (!n) { hacer(); return; }
    var opts = { type: 'warning', title: 'El pedido #' + id + ' ya tiene ' + n + ' planilla' + (n === 1 ? '' : 's'), text: '¿Generar otra? Las anteriores siguen en el histórico (puedes anularlas).', btnOk: 'Sí, generar otra' };
    if (window.inkConfirm) window.inkConfirm(opts, hacer); else if (confirm(opts.title + '. ' + opts.text)) hacer();
  });

  // Adjuntar / reemplazar archivo.
  var idAdjuntar = null;
  $('#pd-tabla').on('click', '.pd-adjuntar', function () {
    idAdjuntar = $(this).closest('tr').data('id');
    $('#pd-archivo').val('').trigger('click');
  });
  $('#pd-archivo').on('change', function () {
    if (!this.files.length || !idAdjuntar) return;
    var archivo = this.files[0];
    if (archivo.size > 15 * 1024 * 1024) { avisar('El archivo pasa de 15 MB.', 'warn'); return; }
    var datos = new FormData();
    datos.append('accion', 'adjuntar');
    datos.append('id', idAdjuntar);
    datos.append('archivo', archivo);
    var $b = $('#pd-tabla tr[data-id="' + idAdjuntar + '"] .pd-adjuntar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Subiendo...');
    $.ajax({ url: 'php/planillas_devolucion_accion.php', type: 'POST', data: datos, processData: false, contentType: false, dataType: 'json' })
      .done(function (r) {
        if (r && r.ok) { avisar(r.msg, 'success'); setTimeout(recargar, 700); }
        else { avisar((r && r.error) || 'No se pudo adjuntar.', 'error'); $b.prop('disabled', false).html('<i class="bi bi-upload"></i> Adjuntar'); }
      })
      .fail(function () { avisar('No se pudo adjuntar. Revisa la conexión.', 'error'); $b.prop('disabled', false).html('<i class="bi bi-upload"></i> Adjuntar'); });
  });

  // Anular con motivo (la planilla sigue en el histórico).
  $('#pd-tabla').on('click', '.pd-anular', function () {
    var $tr = $(this).closest('tr'), num = $tr.find('.pd-num').text();
    var motivo = window.prompt('Motivo para anular la planilla ' + num + ':', '');
    if (motivo === null) return;
    if (!$.trim(motivo)) { avisar('Escribe el motivo de la anulación.', 'warn'); return; }
    $.post('php/planillas_devolucion_accion.php', { accion: 'anular', id: $tr.data('id'), motivo: motivo }, null, 'json')
      .done(function (r) { if (r && r.ok) { avisar(r.msg, 'success'); setTimeout(recargar, 700); } else avisar((r && r.error) || 'No se pudo anular.', 'error'); })
      .fail(function () { avisar('No se pudo anular. Revisa la conexión.', 'error'); });
  });

  // Buscar en el histórico.
  $('#pd-buscar').on('input', function () {
    var q = $.trim(this.value).toLowerCase();
    $('#pd-tabla tbody tr').each(function () { $(this).toggle(!q || String($(this).data('buscar')).indexOf(q) !== -1); });
  });
})();
</script>
</body>
</html>
