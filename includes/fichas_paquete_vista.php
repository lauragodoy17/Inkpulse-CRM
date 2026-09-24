<?php
/**
 * Vista compartida de las fichas técnicas de paquetes (tarjeta visual), usada por
 * ficha_paquete.php y reporte_paquetes.php. Los datos salen de datos_fichas_paquetes()
 * (includes/paquetes_colegio.php).
 */

function fmt_cop_ficha($v) {
    $v = (float)$v;
    $dec = (abs($v - round($v)) >= 0.005) ? 2 : 0;
    return '$' . number_format($v, $dec, ',', '.');
}

/** Estilos de las tarjetas (van en el <head>). */
function fichas_paquete_estilos() {
?>
  <style>
    :root { --fp-accent:#4361ee; --fp-dark:#181f48; --fp-head-from:#115e59; --fp-head-to:#0d9488; --fp-ink:#202342; --fp-muted:#64748b; --fp-line:#e2e8f0; --fp-soft:#f8fafc; }
    .fp-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:18px; }
    .fp-chips { display:flex; flex-wrap:wrap; gap:6px; flex:1 1 auto; }
    .fp-chip { border:1px solid var(--fp-line); background:#fff; color:var(--fp-ink); border-radius:999px; padding:5px 13px; font-size:.8rem; font-weight:500; cursor:pointer; transition:all .15s; }
    .fp-chip:hover { border-color:var(--fp-accent); color:var(--fp-accent); }
    .fp-chip.active { background:var(--fp-accent); border-color:var(--fp-accent); color:#fff; }
    .fp-search { position:relative; min-width:240px; }
    .fp-search input { padding-left:32px; height:36px; font-size:.84rem; border-radius:8px; }
    .fp-search i { position:absolute; left:11px; top:10px; color:var(--fp-muted); font-size:.85rem; }

    .fp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(460px, 1fr)); gap:20px; }
    .fp-card { background:#fff; border:1px solid var(--fp-line); border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); display:flex; flex-direction:column; transition:box-shadow .2s, transform .2s; }
    .fp-card:hover { box-shadow:0 8px 24px rgba(24,31,72,.10); transform:translateY(-2px); }
    .fp-head { background:linear-gradient(135deg, var(--fp-head-from) 0%, var(--fp-head-to) 100%); color:#fff; padding:18px 20px 16px; position:relative; }
    .fp-kicker { font-size:.68rem; letter-spacing:.12em; text-transform:uppercase; opacity:.75; font-weight:600; }
    .fp-name { font-size:1.05rem; font-weight:700; color:#fff; margin:4px 0 10px; line-height:1.3; }
    .fp-grade { position:absolute; right:18px; top:16px; background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.3); border-radius:10px; padding:6px 12px; text-align:center; }
    .fp-grade small { display:block; font-size:.62rem; text-transform:uppercase; letter-spacing:.08em; opacity:.8; }
    .fp-grade strong { font-size:.95rem; }
    .fp-name { padding-right:120px; }
    .fp-tags { display:flex; flex-wrap:wrap; gap:6px; }
    .fp-tag { background:rgba(255,255,255,.14); border-radius:6px; padding:3px 9px; font-size:.74rem; }
    .fp-tag i { margin-right:4px; opacity:.85; }

    .fp-stats { display:grid; grid-template-columns:repeat(3, 1fr); border-bottom:1px solid var(--fp-line); }
    .fp-stat { padding:12px 16px; border-right:1px solid var(--fp-line); }
    .fp-stat:last-child { border-right:0; }
    .fp-stat small { display:block; color:var(--fp-muted); font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .fp-stat strong { font-size:.98rem; color:var(--fp-ink); }

    .fp-books { padding:6px 0; flex:1 1 auto; }
    .fp-book { display:grid; grid-template-columns:28px 1fr auto; gap:12px; align-items:center; padding:10px 20px; border-bottom:1px dashed var(--fp-line); }
    .fp-book:last-child { border-bottom:0; }
    .fp-num { width:26px; height:26px; border-radius:50%; background:#eef0ff; color:var(--fp-accent); font-size:.75rem; font-weight:700; display:flex; align-items:center; justify-content:center; }
    .fp-title { font-size:.85rem; font-weight:600; color:var(--fp-ink); line-height:1.3; }
    .fp-isbn { display:inline-flex; align-items:center; gap:6px; margin-top:4px; font-family:ui-monospace, Consolas, monospace; font-size:.74rem; color:#334155; background:var(--fp-soft); border:1px solid var(--fp-line); border-radius:5px; padding:1px 7px; }
    .fp-copy { border:0; background:none; color:var(--fp-muted); padding:0; cursor:pointer; font-size:.78rem; }
    .fp-copy:hover { color:var(--fp-accent); }
    .fp-bar { height:4px; border-radius:4px; background:#eef0ff; margin-top:6px; overflow:hidden; }
    .fp-bar span { display:block; height:100%; background:var(--fp-accent); border-radius:4px; }
    .fp-price { font-size:.88rem; font-weight:700; color:var(--fp-ink); text-align:right; white-space:nowrap; }
    .fp-price small { display:block; font-size:.68rem; color:var(--fp-muted); font-weight:500; }

    .fp-total { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; background:var(--fp-soft); border-top:1px solid var(--fp-line); }
    .fp-total span { font-size:.78rem; color:var(--fp-muted); text-transform:uppercase; letter-spacing:.06em; font-weight:700; }
    .fp-total strong { font-size:1.35rem; color:var(--fp-accent); }
    .fp-total .fp-total-note { display:block; font-size:.72rem; color:var(--fp-muted); text-transform:none; letter-spacing:0; font-weight:500; }
    .fp-empty { text-align:center; padding:40px 20px; color:var(--fp-muted); }
    .fp-hidden { display:none !important; }

    @media (max-width: 560px) {
      .fp-grid { grid-template-columns:1fr; }
      .fp-name { padding-right:0; }
      .fp-grade { position:static; display:inline-block; margin-bottom:8px; }
      .fp-stats { grid-template-columns:1fr 1fr; }
    }
    @media print {
      .left-side-bar, .header, .footer-wrap, .fp-toolbar, .page-header .fp-actions, .mobile-menu-overlay { display:none !important; }
      .main-container { padding:0 !important; margin:0 !important; }
      body { background:#fff; }
      .fp-grid { display:block; }
      .fp-card { box-shadow:none; transform:none; break-inside:avoid; page-break-inside:avoid; margin-bottom:18px; }
      .fp-head { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .fp-copy { display:none; }
    }
  </style>
<?php
}

/** Una tarjeta de ficha técnica: $p = un elemento de datos_fichas_paquetes()['paquetes']. */
function render_ficha_paquete(array $p, $colegio, $periodo) {
    $max_precio = $p['libros'] ? max(array_column($p['libros'], 'precio')) : 0;
?>
          <div class="fp-card" data-paquete="<?= $p['id_paquete'] ?>">
            <div class="fp-head">
              <div class="fp-kicker">Ficha técnica</div>
              <div class="fp-grade"><small>Grado</small><strong><?= htmlspecialchars($p['grado']) ?></strong></div>
              <h5 class="fp-name"><?= htmlspecialchars($p['nombre']) ?></h5>
              <div class="fp-tags">
                <span class="fp-tag"><i class="bi bi-building"></i><?= htmlspecialchars($colegio) ?></span>
                <span class="fp-tag"><i class="bi bi-upc"></i><?= htmlspecialchars($p['codigo']) ?></span>
                <span class="fp-tag"><i class="bi bi-calendar3"></i><?= htmlspecialchars($periodo) ?></span>
              </div>
            </div>

            <div class="fp-stats">
              <div class="fp-stat"><small>Libros</small><strong><?= count($p['libros']) ?></strong></div>
              <div class="fp-stat"><small>Suma de libros</small><strong><?= fmt_cop_ficha($p['precio_neto_sumado']) ?></strong></div>
              <div class="fp-stat"><small>Precio redondeado</small><strong><?= $p['precio_redondeado'] !== null ? fmt_cop_ficha($p['precio_redondeado']) : '<span class="text-muted" style="font-weight:500;">Sin definir</span>' ?></strong></div>
            </div>

            <div class="fp-books">
              <?php foreach ($p['libros'] as $i => $l): ?>
              <div class="fp-book" data-buscar="<?= htmlspecialchars(mb_strtolower($l['libro'] . ' ' . $l['isbn'], 'UTF-8')) ?>">
                <div class="fp-num"><?= $i + 1 ?></div>
                <div>
                  <div class="fp-title"><?= htmlspecialchars($l['libro']) ?></div>
                  <span class="fp-isbn">ISBN <?= $l['isbn'] !== '' ? htmlspecialchars($l['isbn']) : '—' ?>
                    <?php if ($l['isbn'] !== ''): ?><button type="button" class="fp-copy" data-isbn="<?= htmlspecialchars($l['isbn']) ?>" title="Copiar ISBN"><i class="bi bi-clipboard"></i></button><?php endif; ?>
                  </span>
                  <div class="fp-bar"><span style="width:<?= $max_precio > 0 ? round($l['precio'] / $max_precio * 100) : 0 ?>%"></span></div>
                </div>
                <div class="fp-price"><?= fmt_cop_ficha($l['precio']) ?><small>precio del libro</small></div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="fp-total">
              <div>
                <span>Precio total del paquete</span>
                <span class="fp-total-note"><?= $p['precio_redondeado'] !== null ? 'Precio redondeado' : 'Suma de los libros' ?></span>
              </div>
              <strong><?= fmt_cop_ficha($p['precio_final']) ?></strong>
            </div>
          </div>
<?php
}

/** JS de las tarjetas (copiar ISBN); va después de cargar jQuery. */
function fichas_paquete_script() {
?>
<script>
  $(document).on('click', '.fp-copy', function () {
    var btn = $(this), isbn = String(btn.data('isbn'));
    var hecho = function () {
      btn.html('<i class="bi bi-check2"></i>');
      setTimeout(function () { btn.html('<i class="bi bi-clipboard"></i>'); }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(isbn).then(hecho, function () { window.prompt('ISBN', isbn); });
    } else {
      window.prompt('ISBN', isbn);
    }
  });
</script>
<?php
}
