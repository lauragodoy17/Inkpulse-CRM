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

$l = le_obtener_lista($bdd, intval($_GET['id'] ?? 0));
if (!$l) {
    header("Location: listas_empaque.php");
    exit;
}
$anulada = (int)$l['estado'] === 0;
function le_h($v) { return htmlspecialchars((string)$v); }
function le_n($v) { return number_format((float)$v, (float)$v == floor((float)$v) ? 0 : 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Lista de empaque <?= le_h($l['consecutivo_fmt']) ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .le-section { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(15,23,42,.08); margin-bottom:20px; overflow:hidden; }
    .le-section-head { padding:14px 24px; border-bottom:1px solid #e2e8f0; font-size:.9rem; font-weight:700; color:#0f172a; }
    .le-section-body { padding:18px 24px; }
    .le-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-size:.85rem; font-weight:700; background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border:none; cursor:pointer; text-decoration:none; }
    .le-btn:hover { opacity:.9; color:#fff; text-decoration:none; }
    .le-btn-outline { background:#fff; color:#1d4ed8; border:1.5px solid #1d4ed8; }
    .le-btn-outline:hover { color:#1d4ed8; }
    .le-btn-danger { background:#fff; color:#dc2626; border:1.5px solid #fecaca; }
    .le-btn-danger:hover { color:#dc2626; }
    .le-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px 20px; }
    .le-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
    .le-valor { font-size:.9rem; color:#0f172a; font-weight:600; word-break:break-word; }
    .le-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    .le-table th { background:#f8fafc; color:#64748b; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; }
    .le-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; }
    .le-table .num { text-align:right; }
    .le-table tfoot td { font-weight:700; background:#f8fafc; }
    .le-aviso { border-radius:10px; padding:11px 15px; font-size:.85rem; margin-bottom:16px; }
    .le-aviso-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .le-items-wo td { font-size:.78rem; background:#f8fafc; }
    .le-dif-pos { color:#b45309; font-weight:700; } .le-dif-neg { color:#dc2626; font-weight:700; } .le-dif-ok { color:#16a34a; font-weight:700; }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-7 col-sm-12">
            <div class="title"><h4>Lista de empaque <?= le_h($l['consecutivo_fmt']) ?></h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="listas_empaque.php">Listas de empaque</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= le_h($l['consecutivo_fmt']) ?></li>
              </ol>
            </nav>
          </div>
          <div class="col-md-5 col-sm-12 text-md-right" style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
            <a href="php/lista_empaque_pdf.php?id=<?= (int)$l['id'] ?>" target="_blank" rel="noopener" class="le-btn"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
            <?php if ($tipo_sesion === 1 && !$anulada): ?>
              <button type="button" class="le-btn le-btn-danger" id="le-anular"><i class="bi bi-x-octagon"></i> Anular</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($anulada): ?>
        <div class="le-aviso le-aviso-error"><i class="bi bi-x-octagon"></i> <strong>Lista anulada</strong> el <?= date('d/m/Y H:i', strtotime($l['fecha_anulacion'])) ?> por <?= le_h($l['usuario_anula']) ?>. Motivo: <?= le_h($l['motivo_anulacion']) ?>. Sus documentos de World Office quedaron libres para otra lista.</div>
      <?php endif; ?>

      <div class="le-section">
        <div class="le-section-head">Datos generales</div>
        <div class="le-section-body">
          <div class="le-grid">
            <div><div class="le-label">Generada</div><div class="le-valor"><?= date('d/m/Y H:i', strtotime($l['fecha'])) ?> · <?= le_h($l['usuario']) ?></div></div>
            <div><div class="le-label">Cliente / empresa</div><div class="le-valor"><?= le_h($l['cliente']) ?></div></div>
            <div><div class="le-label">Dirección de entrega</div><div class="le-valor"><?= le_h($l['direccion']) ?></div></div>
            <div><div class="le-label">Ciudad</div><div class="le-valor"><?= le_h($l['ciudad'] ?: '—') ?></div></div>
            <div><div class="le-label">Persona que recibe</div><div class="le-valor"><?= le_h(($l['persona_recibe'] ?? '') ?: '—') ?></div></div>
            <?php // Solo las listas anteriores al 2026-09-28 guardaban pedido, OP y colegio del CRM. ?>
            <?php if ($l['pedidos'] !== ''): ?>
            <div><div class="le-label"><?= strpos($l['pedidos'], ',') !== false ? 'Pedidos' : 'N.º de pedido' ?></div><div class="le-valor"><?= le_h($l['pedidos']) ?></div></div>
            <?php endif; ?>
            <?php if ($l['ops'] !== ''): ?>
            <div><div class="le-label">OP asociada</div><div class="le-valor"><?= le_h($l['ops']) ?></div></div>
            <?php endif; ?>
            <?php if ($l['colegio'] !== ''): ?>
            <div><div class="le-label">Colegio</div><div class="le-valor"><?= le_h($l['colegio']) ?></div></div>
            <?php endif; ?>
            <div><div class="le-label">Total</div><div class="le-valor"><?= le_n($l['total_unidades']) ?> unidades · <?= (int)$l['total_cajas'] ?> caja(s)</div></div>
            <div><div class="le-label">Peso neto</div><div class="le-valor"><?= $l['peso_neto'] !== null ? le_n($l['peso_neto']) . ' kg' : '—' ?></div></div>
            <div><div class="le-label">Empacado por</div><div class="le-valor"><?= le_h($l['empacado_por']) ?></div></div>
          </div>
        </div>
      </div>

      <div class="le-section">
        <div class="le-section-head">Documentos de World Office</div>
        <div class="le-section-body">
          <table class="le-table">
            <?php $porPedido = $l['pedidos'] !== ''; // listas anteriores al 2026-09-28: se muestra cómo se ligó al pedido ?>
            <thead><tr><th>Tipo</th><th>Documento</th><th>Fecha</th><?= $porPedido ? '<th>Cómo se relacionó</th>' : '' ?><th>Concepto</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($l['documentos'] as $i => $d): ?>
              <tr>
                <td><?= le_h($d['tipo_label']) ?></td>
                <td><strong><?= le_h($d['documento']) ?></strong></td>
                <td><?= $d['fecha_doc'] ? date('d/m/Y', strtotime($d['fecha_doc'])) : '—' ?></td>
                <?php if ($porPedido): ?>
                <td style="font-size:.8rem;<?= $d['origen'] === 'manual' ? ' color:#b91c1c;' : '' ?>">
                  <?= le_h($d['origen_label']) ?>
                </td>
                <?php endif; ?>
                <td style="font-size:.76rem; color:#64748b;"><?= le_h($d['concepto']) ?></td>
                <td style="white-space:nowrap;"><a href="javascript:;" class="le-ver-wo" data-id="<?= (int)$d['id_wo'] ?>" data-i="<?= $i ?>">Consultar en World Office</a></td>
              </tr>
              <tr class="le-items-wo" data-fila="<?= $i ?>" style="display:none;"><td colspan="<?= $porPedido ? 6 : 5 ?>"></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="le-label" style="margin-top:10px; text-transform:none; font-weight:500;">"Consultar en World Office" muestra cómo está el documento hoy en World Office; la lista de empaque conserva lo que había cuando se generó.</p>
        </div>
      </div>

      <div class="le-section">
        <div class="le-section-head">Detalle de las cajas</div>
        <div class="le-section-body">
          <table class="le-table">
            <thead><tr><th>N.º de caja</th><th>Referencia</th><th>Producto</th><th class="num">Cantidad</th></tr></thead>
            <tbody>
            <?php foreach ($l['cajas'] as $c): ?>
              <?php foreach ($c['items'] as $it): ?>
                <tr><td><strong><?= (int)$c['numero'] ?></strong></td><td><?= le_h($it['codigo']) ?></td><td><?= le_h($it['descripcion']) ?></td><td class="num"><?= le_n($it['cantidad']) ?></td></tr>
              <?php endforeach; ?>
              <tr style="background:#f1f5f9;"><td colspan="3" class="num" style="font-size:.78rem; font-weight:700;">Total caja <?= (int)$c['numero'] ?><?= $c['peso'] !== null ? ' · Peso ' . le_n($c['peso']) . ' kg' : '' ?></td><td class="num"><strong><?= le_n($c['unidades']) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="3" class="num">TOTAL</td><td class="num"><?= le_n($l['total_unidades']) ?></td></tr></tfoot>
          </table>
        </div>
      </div>

      <?php if ($l['cruce']): ?>
      <div class="le-section">
        <div class="le-section-head">Solicitado vs. despachado (al momento de generar la lista)</div>
        <div class="le-section-body">
          <table class="le-table">
            <thead><tr><th>Referencia</th><th>Producto</th><th class="num">Solicitado</th><th class="num">Despachado</th><th class="num">Diferencia</th></tr></thead>
            <tbody>
            <?php foreach ($l['cruce'] as $c): $dif = (float)$c['diferencia']; ?>
              <tr>
                <td><?= le_h($c['codigo']) ?></td><td><?= le_h($c['descripcion']) ?></td>
                <td class="num"><?= le_n($c['solicitada']) ?></td><td class="num"><?= le_n($c['despachada']) ?></td>
                <td class="num <?= $dif == 0 ? 'le-dif-ok' : ($dif > 0 ? 'le-dif-pos' : 'le-dif-neg') ?>"><?= $dif == 0 ? '✓' : ($dif > 0 ? '+' : '') . le_n($dif) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

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
  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  $('.le-ver-wo').on('click', function () {
    var $fila = $('tr[data-fila="' + $(this).data('i') + '"]');
    if ($fila.is(':visible')) { $fila.hide(); return; }
    $fila.show().find('td').html('Consultando World Office…');
    $.getJSON('ajax/wo_ver_items_documento.php', { id_wo: $(this).data('id') }).done(function (r) {
      if (!r.ok) { $fila.find('td').text(r.error || 'No se pudo consultar.'); return; }
      $fila.find('td').html('<table style="width:100%">' + r.items.map(function (it) {
        return '<tr><td style="width:160px">' + h(it.codigo) + '</td><td>' + h(it.descripcion) + '</td><td style="text-align:right">' + h(it.cantidad) + '</td></tr>';
      }).join('') + '</table>');
    }).fail(function () { $fila.find('td').text('No se pudo consultar World Office.'); });
  });

  $('#le-anular').on('click', function () {
    var motivo = window.prompt('Motivo de la anulación de la lista <?= le_h($l['consecutivo_fmt']) ?>:');
    if (motivo === null) return;
    if (!motivo.trim()) { inkToast('Escribe el motivo de la anulación.', 'error'); return; }
    $.post('php/lista_empaque_anular.php', { id: <?= (int)$l['id'] ?>, motivo: motivo }, null, 'json').done(function (r) {
      if (r.ok) location.reload(); else inkToast(h(r.error), 'error');
    }).fail(function () { inkToast('No se pudo anular la lista.', 'error'); });
  });
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
