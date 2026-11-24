<?php
/**
 * /solicitudes_venta_directa.php
 * Menú Venta directa → Solicitudes (2026-11-24, la usuaria): pedidos de los colegios con canal "Venta directa - Eureka",
 * por colegio, con sus fechas de venta y su formato. El pedido no recibe consecutivo de formato al solicitarse; el formato
 * se asigna solo el día de la venta (o después si la fecha ya pasó) con los pedidos Entregados. Lógica en
 * includes/solicitudes_vd_datos.php. Respeta el alcance por zona del usuario.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/solicitudes_vd_datos.php");

dm_validar_acceso();
fvd_asegurar_tablas($bdd);
sobrecosto_asegurar_columna($bdd);
adopcion_vd_asegurar($bdd);

$periodos = svd_periodos($bdd);
$ids_periodos = array_map('intval', array_column($periodos, 'id'));
$periodo_sel = isset($_GET['periodo']) && in_array((int)$_GET['periodo'], $ids_periodos, true) ? (int)$_GET['periodo'] : ($ids_periodos[0] ?? 0);

// Consecutivo de las ventas que ya llegaron (hoy o antes) y aún no lo tienen: se reserva vacío.
$reservados = $periodo_sel ? svd_asignar_pendientes($bdd, $periodo_sel) : [];
$colegios = $periodo_sel ? svd_listar($bdd, $periodo_sel) : [];
// Primero los que venden hoy, luego las próximas ventas por fecha, luego los que ya vendieron y al final los sin fecha.
$hoy = date('Y-m-d');
usort($colegios, function ($a, $b) {
    $ka = $a['proxima'] ?? ($a['jornadas'] ? '9998' : '9999');
    $kb = $b['proxima'] ?? ($b['jornadas'] ? '9998' : '9999');
    return strcmp($ka, $kb) ?: strcasecmp($a['colegio'], $b['colegio']);
});

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fecha_txt = fn($f) => date('d/m/Y', strtotime($f));
$clase_estado = [1 => 'sv-e-pend', 2 => 'sv-e-aprob', 4 => 'sv-e-entr', 5 => 'sv-e-proc', 6 => 'sv-e-proc', 7 => 'sv-e-proc'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Solicitudes de venta directa</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .sv-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; box-shadow:0 1px 3px rgba(15,23,42,.05); }
    .sv-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .sv-filtros select, .sv-filtros input { height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:.86rem; background:#fff; }
    .sv-filtros input { flex:1 1 240px; }
    .sv-aviso { display:flex; gap:10px; align-items:flex-start; border-radius:10px; padding:10px 14px; font-size:.84rem; margin-bottom:12px; }
    .sv-aviso.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .sv-aviso.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .sv-aviso.info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
    .sv-sub { font-size:.74rem; color:#94a3b8; }
    .sv-e { font-size:.66rem; font-weight:700; border-radius:999px; padding:2px 8px; }
    .sv-e-pend { background:#fef3c7; color:#b45309; } .sv-e-aprob { background:#e0f2fe; color:#0369a1; } .sv-e-entr { background:#dcfce7; color:#15803d; } .sv-e-proc { background:#ede9fe; color:#6d28d9; }
    /* Comparativo en unidades (2026-11-24) */
    .sv-comp-tit { font-size:.82rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f172a; margin:4px 0 8px; display:flex; gap:8px; align-items:center; }
    .sv-comp { width:100%; border-collapse:collapse; font-size:.86rem; }
    .sv-comp th { background:#f1f5f9; color:#334155; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; font-weight:800; padding:8px 12px; border:1px solid #e2e8f0; text-align:center; }
    .sv-comp th:first-child { text-align:left; }
    .sv-comp td { padding:8px 12px; border:1px solid #e2e8f0; }
    .sv-comp td.n { text-align:center; font-weight:700; width:14%; }
    .sv-comp td[data-k="remisionado"] { color:#1d4ed8; } .sv-comp td[data-k="devuelto"] { color:#b45309; } .sv-comp td[data-k="vendido"] { color:#15803d; }
    .sv-comp tr.sv-comp-grupo td { background:#eef2ff; color:#312e81; font-weight:800; font-size:.8rem; }
    .sv-comp tr.sv-comp-sub td { background:#f8fafc; font-weight:800; font-size:.8rem; border-top:2px solid #cbd5e1; }
    .sv-comp .sv-comp-err { font-weight:500; color:#b91c1c; font-size:.74rem; margin-left:8px; }
    .sv-comp td.vacio { color:#94a3b8; font-weight:500; }
    .sv-comp .sv-nota { display:block; font-size:.68rem; font-weight:500; color:#b45309; }
    .sv-comp td.err { color:#b91c1c; font-weight:500; font-size:.76rem; text-align:left; }
    .sv-cargando { display:inline-block; width:14px; height:14px; border:2px solid #cbd5e1; border-top-color:#1d4ed8; border-radius:50%; animation:svGira .8s linear infinite; vertical-align:middle; }
    @keyframes svGira { to { transform:rotate(360deg); } }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="page-header">
        <div class="title"><h4>Solicitudes de venta directa</h4></div>
        <div class="sv-sub" style="font-size:.84rem;margin-top:4px;">Pedidos de los colegios con canal <strong>Venta directa - Eureka</strong>. El consecutivo de cada venta se reserva solo el día de la venta; los pedidos entran a ese formato al registrar la devolución eligiendo la venta.</div>
      </div>

      <?php foreach ($reservados as $c): ?>
      <div class="sv-aviso ok"><i class="bi bi-check-circle"></i><div>Venta del <?= $h($fecha_txt($c['fecha'])) ?> en <strong><?= $h($c['colegio']) ?></strong>: se reservó el <a href="ver_formato_venta_directa.php?id=<?= (int)$c['id'] ?>"><?= $h(fvd_numero($c['consecutivo'])) ?></a>.</div></div>
      <?php endforeach; ?>

      <div class="sv-card">
        <form method="get" class="sv-filtros">
          <select name="periodo" onchange="this.form.submit()">
            <?php foreach ($periodos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $periodo_sel ? 'selected' : '' ?>>Período <?= $h($p['periodo']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" id="sv-buscar" placeholder="Buscar colegio o número de pedido...">
        </form>

        <?php if (!$colegios): ?>
        <div class="sv-aviso info"><i class="bi bi-info-circle"></i><div>No hay pedidos de colegios con canal Venta directa - Eureka en este período.</div></div>
        <?php else: ?>
        <?php /* Comparativo en unidades (2026-11-24): solicitado, remisionado (WO), devuelto y vendido por colegio. Se llena
                 por colegio con ajax/solicitudes_vd_unidades.php porque lo remisionado se consulta en World Office. */ ?>
        <div class="sv-comp-tit"><i class="bi bi-bar-chart-steps"></i> Comparativo en unidades</div>
        <div class="table-responsive" style="margin-bottom:22px;">
          <table class="sv-comp" id="sv-comp">
            <thead><tr><th>Pedido</th><th>Estado</th><th>Solicitado</th><th>Remisionado</th><th>Devuelto</th><th>Vendido</th></tr></thead>
            <?php foreach ($colegios as $col):
              $buscar_c = mb_strtolower($col['colegio'] . ' ' . implode(' ', array_map(fn($p) => '#' . $p['id'], $col['pedidos']))); ?>
            <tbody class="sv-comp-col" data-colegio="<?= (int)$col['id_colegio'] ?>" data-buscar="<?= $h($buscar_c) ?>">
              <tr class="sv-comp-grupo"><td colspan="6"><i class="bi bi-building"></i> <?= $h($col['colegio']) ?> <span class="sv-comp-err"></span></td></tr>
              <?php foreach ($col['pedidos'] as $p): ?>
              <tr data-pedido="<?= (int)$p['id'] ?>">
                <td><a href="pedido_colegio.php?id_pedido=<?= (int)$p['id'] ?>" target="_blank">#<?= (int)$p['id'] ?></a></td>
                <td><span class="sv-e <?= $clase_estado[$p['estado']] ?? 'sv-e-proc' ?>"><?= $h($p['estado_txt'] ?: 'Estado ' . $p['estado']) ?></span></td>
                <td class="n" data-k="solicitado"><span class="sv-cargando"></span></td>
                <td class="n" data-k="remisionado"><span class="sv-cargando"></span></td>
                <td class="n" data-k="devuelto"><span class="sv-cargando"></span></td>
                <td class="n" data-k="vendido"><span class="sv-cargando"></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (count($col['pedidos']) > 1): ?>
              <tr class="sv-comp-sub"><td colspan="2">Subtotal <?= $h($col['colegio']) ?></td><td class="n" data-s="solicitado"></td><td class="n" data-s="remisionado"></td><td class="n" data-s="devuelto"></td><td class="n" data-s="vendido"></td></tr>
              <?php endif; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
          <div class="sv-sub" style="margin-top:6px;">Unidades de libros (un paquete cuenta cada libro). Remisionado: lo despachado en World Office (remisiones CEUR y facturas FVE); solo en pedidos entregados. Vendido = remisionado − devuelto.</div>
        </div>
        <?php endif; ?>
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
$('#sv-buscar').on('input', function () {
  var q = $.trim(this.value).toLowerCase();
  $('#sv-comp tbody.sv-comp-col').each(function () { $(this).toggle(!q || String($(this).data('buscar')).indexOf(q) >= 0); });
});

// Comparativo por pedido: un colegio a la vez (cada uno consulta World Office); subtotal por colegio.
(function () {
  var grupos = $('#sv-comp tbody.sv-comp-col').toArray();
  var fmt = function (n) { return Number(n || 0).toLocaleString('es-CO'); };
  var K = ['solicitado', 'remisionado', 'devuelto', 'vendido'];
  function error($g, msg) {
    $g.find('.sv-cargando').closest('td').text('—').addClass('vacio');
    $g.find('.sv-comp-err').html('<i class="bi bi-exclamation-triangle"></i> ' + $('<div>').text(msg).html());
  }
  function siguiente() {
    var g = grupos.shift();
    if (!g) return;
    var $g = $(g);
    $.getJSON('ajax/solicitudes_vd_unidades.php', { periodo: <?= (int)$periodo_sel ?>, colegio: $g.data('colegio') }).done(function (r) {
      if (!r || !r.ok) { error($g, (r && r.error) || 'No se pudo consultar.'); return; }
      $.each(r.pedidos, function (id, x) {
        var $tr = $g.find('tr[data-pedido="' + id + '"]');
        K.forEach(function (k) {
          var $td = $tr.find('[data-k="' + k + '"]');
          if (x[k] === null) { $td.text('—').addClass('vacio').attr('title', 'El pedido aún no está entregado'); return; }
          $td.text(fmt(x[k]));
          if (k === 'remisionado' && x.sin_remision) $td.append('<small class="sv-nota">sin remisión en WO</small>');
        });
      });
      K.forEach(function (k) { $g.find('[data-s="' + k + '"]').text(fmt(r.total[k])); });
    }).fail(function () { error($g, 'No se pudo consultar.'); }).always(siguiente);
  }
  siguiente();
})();
</script>
</body>
</html>
