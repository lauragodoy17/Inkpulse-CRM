<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/paquetes_colegio.php");
require_once("includes/fichas_paquete_vista.php");

// Todo lo relacionado con "Tipo de adopción" (incluido este reporte) es exclusivo
// de Administrador (tipo=1) y Héctor Morales (id=69) — ver puede_usar_tipo_adopcion().
if (!puede_usar_tipo_adopcion()) {
    header("Location: index.php");
    exit;
}

crear_tablas_paquetes($bdd);

// Usuarios "eureka": promotores (tipo=3) y Héctor Morales (id=69).
$sqlUsuarios = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor
                FROM usuarios
                WHERE (tipo = 3 OR id = 69) AND act = 1
                ORDER BY nombres";
$usuarios = $bdd->query($sqlUsuarios)->fetchAll(PDO::FETCH_ASSOC);

$periodos = $bdd->query("SELECT id, periodo FROM periodos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Reporte en pantalla (sin Excel): usuario puntual → sus colegios con sus fichas;
// "Todos" → agrupado por persona, cada una con sus colegios y fichas.
$sel_usuario = $_GET['usuario'] ?? '';
$sel_periodo = (int)($_GET['periodo'] ?? 0);
$consultado  = $sel_usuario !== '' && $sel_periodo > 0;
$es_todos    = $sel_usuario === '0';

$reporte = $consultado ? obtener_reporte_fichas_paquetes($bdd, $sel_periodo, (int)$sel_usuario) : [];

$tot_colegios = 0; $tot_paquetes = 0; $tot_libros = 0;
foreach ($reporte as $persona) {
    $tot_colegios += count($persona['colegios']);
    foreach ($persona['colegios'] as $c) {
        $tot_paquetes += count($c['fichas']['paquetes']);
        foreach ($c['fichas']['paquetes'] as $p) $tot_libros += count($p['libros']);
    }
}

$nombre_periodo = '';
foreach ($periodos as $p) if ((int)$p['id'] === $sel_periodo) $nombre_periodo = $p['periodo'];

function iniciales_rp($nombre) {
    $partes = preg_split('/\s+/', trim($nombre));
    return mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1, 'UTF-8') . mb_substr($partes[1] ?? '', 0, 1, 'UTF-8'), 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Reporte Paquetes</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <?php fichas_paquete_estilos(); ?>
  <style>
    .rp-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:18px; }
    .rp-kpi { background:#fff; border:1px solid var(--fp-line); border-radius:12px; padding:14px 18px; display:flex; align-items:center; gap:12px; }
    .rp-kpi i { font-size:1.3rem; color:var(--fp-accent); background:#eef0ff; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
    .rp-kpi small { display:block; color:var(--fp-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .rp-kpi strong { font-size:1.25rem; color:var(--fp-ink); }

    .rp-tools { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:14px; }

    .rp-persona { margin-bottom:22px; }
    .rp-persona-head { display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--fp-dark); color:#fff; border-radius:12px; cursor:pointer; user-select:none; }
    .rp-avatar { width:38px; height:38px; border-radius:50%; background:var(--fp-accent); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; flex:0 0 auto; }
    .rp-persona-head .rp-nombre { font-weight:700; font-size:.98rem; flex:1 1 auto; color:#fff; }
    .rp-persona-head .rp-meta { font-size:.78rem; opacity:.8; white-space:nowrap; }
    .rp-persona-body { padding:12px 0 0 18px; border-left:2px solid #dbe1ff; margin-left:18px; }
    .rp-persona.cerrado .rp-persona-body { display:none; }
    .rp-chev { transition:transform .15s; }
    .cerrado > .rp-persona-head .rp-chev, .cerrado > .rp-colegio-head .rp-chev { transform:rotate(-90deg); }

    .rp-colegio { background:#fff; border:1px solid var(--fp-line); border-radius:12px; margin-bottom:12px; overflow:hidden; }
    .rp-colegio-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; padding:12px 16px; cursor:pointer; user-select:none; }
    .rp-colegio-head .rp-chev { color:var(--fp-muted); }
    .rp-colegio-titulo { flex:1 1 260px; }
    .rp-colegio-titulo strong { display:block; font-size:.92rem; color:var(--fp-ink); }
    .rp-colegio-titulo span { font-size:.76rem; color:var(--fp-muted); }
    .rp-badge { font-size:.72rem; border-radius:999px; padding:3px 10px; font-weight:600; white-space:nowrap; background:#eef0ff; color:var(--fp-accent); }
    .rp-badge.ambos { background:#ecfdf5; color:#047857; }
    .rp-colegio-head .btn { font-size:.76rem; padding:3px 10px; }
    .rp-colegio-body { padding:16px; background:var(--fp-soft); border-top:1px solid var(--fp-line); }
    .rp-colegio.cerrado .rp-colegio-body { display:none; }

    @media (max-width: 768px) { .rp-kpis { grid-template-columns:repeat(2, 1fr); } .rp-persona-body { padding-left:8px; margin-left:6px; } }
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
            <div class="title"><h4>Reporte Paquetes</h4></div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-box-seam"></i></span>
          <span class="sm-section-title">Paquetes por colegio y fichas técnicas</span>
        </div>
        <div class="sm-section-body">
          <form action="reporte_paquetes.php" method="GET">
            <div class="row">
              <div class="col-md-5 col-12 mb-3">
                <label class="control-label">Usuario <small style="color:red;">*</small></label>
                <select name="usuario" class="form-control custom-select2" required>
                  <option value="">Seleccionar</option>
                  <option value="0" <?= $sel_usuario === '0' ? 'selected' : '' ?>>Todos</option>
                  <?php foreach ($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $sel_usuario === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['promotor']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-12 mb-3">
                <label class="control-label">Periodo <small style="color:red;">*</small></label>
                <select name="periodo" class="form-control" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($periodos as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $sel_periodo === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['periodo']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="sm-footer">
              <button class="btn btn-primary"><i class="bi bi-search"></i> Ver reporte</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($consultado): ?>

        <?php if (!$reporte): ?>
          <div class="sm-section"><div class="sm-section-body fp-empty">
            <i class="bi bi-box-seam" style="font-size:1.6rem;"></i>
            <p style="margin-top:8px;">No hay colegios con paquetes guardados para este filtro en el periodo <?= htmlspecialchars($nombre_periodo) ?>.</p>
          </div></div>
        <?php else: ?>

          <div class="rp-kpis">
            <div class="rp-kpi"><i class="bi bi-people"></i><div><small>Personas</small><strong><?= count($reporte) ?></strong></div></div>
            <div class="rp-kpi"><i class="bi bi-building"></i><div><small>Colegios</small><strong><?= $tot_colegios ?></strong></div></div>
            <div class="rp-kpi"><i class="bi bi-box-seam"></i><div><small>Paquetes</small><strong><?= $tot_paquetes ?></strong></div></div>
            <div class="rp-kpi"><i class="bi bi-book"></i><div><small>Libros en paquetes</small><strong><?= $tot_libros ?></strong></div></div>
          </div>

          <div class="rp-tools">
            <div class="fp-search">
              <i class="bi bi-search"></i>
              <input type="text" class="form-control" id="rp-buscar" placeholder="Buscar colegio o DANE...">
            </div>
            <span>
              <button type="button" class="btn btn-link btn-sm rp-expandir" data-abrir="1">Expandir todo</button>
              <button type="button" class="btn btn-link btn-sm rp-expandir" data-abrir="0">Contraer todo</button>
            </span>
          </div>

          <?php foreach ($reporte as $persona):
            $n_paq_persona = 0;
            foreach ($persona['colegios'] as $c) $n_paq_persona += count($c['fichas']['paquetes']);
          ?>
          <div class="rp-persona">
            <?php if ($es_todos): ?>
            <div class="rp-persona-head">
              <div class="rp-avatar"><?= htmlspecialchars(iniciales_rp($persona['usuario'])) ?></div>
              <div class="rp-nombre"><?= htmlspecialchars($persona['usuario']) ?></div>
              <div class="rp-meta"><?= count($persona['colegios']) ?> colegio(s) · <?= $n_paq_persona ?> paquete(s)</div>
              <i class="bi bi-chevron-down rp-chev"></i>
            </div>
            <div class="rp-persona-body">
            <?php else: ?>
            <div>
            <?php endif; ?>

              <?php foreach ($persona['colegios'] as $c):
                $url_ficha = 'ficha_paquete.php?colegio=' . $c['id_colegio'] . '&periodo=' . $sel_periodo;
                $url_pdf   = 'php/ficha_paquete_pdf.php?colegio=' . $c['id_colegio'] . '&periodo=' . $sel_periodo;
              ?>
              <div class="rp-colegio<?= $es_todos ? ' cerrado' : '' ?>" data-buscar="<?= htmlspecialchars(mb_strtolower($c['colegio'] . ' ' . $c['dane'], 'UTF-8')) ?>">
                <div class="rp-colegio-head">
                  <i class="bi bi-chevron-down rp-chev"></i>
                  <div class="rp-colegio-titulo">
                    <strong><?= htmlspecialchars($c['colegio']) ?></strong>
                    <span>DANE <?= htmlspecialchars($c['dane']) ?><?= $c['zona'] !== '' ? ' · Zona ' . htmlspecialchars($c['zona']) : '' ?></span>
                  </div>
                  <span class="rp-badge<?= $c['tipo_adop'] === 3 ? ' ambos' : '' ?>"><?= $c['tipo_adop'] === 3 ? 'Ambos' : 'Paquetes' ?></span>
                  <span class="rp-badge"><?= count($c['fichas']['paquetes']) ?> paquete(s)</span>
                  <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($url_ficha) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir fichas</a>
                  <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($url_pdf) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                </div>
                <div class="rp-colegio-body">
                  <div class="fp-grid">
                    <?php foreach ($c['fichas']['paquetes'] as $p) render_ficha_paquete($p, $c['colegio'], $c['fichas']['periodo']); ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>

            </div>
          </div>
          <?php endforeach; ?>

        <?php endif; ?>
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
  $(function () {
    // Abrir/cerrar persona y colegio (los botones dentro del encabezado no cuentan).
    $(document).on('click', '.rp-persona-head', function () {
      $(this).closest('.rp-persona').toggleClass('cerrado');
    });
    $(document).on('click', '.rp-colegio-head', function (e) {
      if ($(e.target).closest('a, button').length) return;
      $(this).closest('.rp-colegio').toggleClass('cerrado');
    });
    $('.rp-expandir').on('click', function () {
      var abrir = $(this).data('abrir') == 1;
      $('.rp-persona, .rp-colegio').toggleClass('cerrado', !abrir);
    });

    // Búsqueda por colegio/DANE: oculta colegios (y personas sin coincidencias) y abre los que coinciden.
    $('#rp-buscar').on('input', function () {
      var q = $(this).val().trim().toLowerCase();
      $('.rp-colegio').each(function () {
        var ok = !q || String($(this).data('buscar')).indexOf(q) !== -1;
        $(this).toggleClass('fp-hidden', !ok);
        if (q && ok) $(this).removeClass('cerrado');
      });
      $('.rp-persona').each(function () {
        var visibles = $(this).find('.rp-colegio:not(.fp-hidden)').length;
        $(this).toggleClass('fp-hidden', visibles === 0);
        if (q && visibles) $(this).removeClass('cerrado');
      });
    });
  });
</script>
<?php fichas_paquete_script(); ?>
</body>
</html>
