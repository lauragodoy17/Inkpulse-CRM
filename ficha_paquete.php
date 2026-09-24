<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/paquetes_colegio.php");
require_once("includes/fichas_paquete_vista.php");

// Fichas técnicas de los paquetes de un colegio+periodo (o de uno solo con ?paquete=).
// Solo Administrador/Héctor Morales (puede_usar_tipo_adopcion()) y solo si el Tipo de
// adopción guardado es "Paquetes" o "Ambos" — datos_fichas_paquetes() devuelve null en otro caso.
if (!puede_usar_tipo_adopcion()) {
    header("Location: index.php");
    exit;
}

crear_tablas_paquetes($bdd);

$id_colegio = (int)($_GET['colegio'] ?? 0);
$id_periodo = (int)($_GET['periodo'] ?? 0);
$id_paquete = (int)($_GET['paquete'] ?? 0);

$fichas = datos_fichas_paquetes($bdd, $id_colegio, $id_periodo, $id_paquete);

$url_pdf = 'php/ficha_paquete_pdf.php?colegio=' . $id_colegio . '&periodo=' . $id_periodo . ($id_paquete > 0 ? '&paquete=' . $id_paquete : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Fichas técnicas de paquetes</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <?php fichas_paquete_estilos(); ?>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-7 col-sm-12">
            <div class="title"><h4>Fichas técnicas de paquetes</h4></div>
            <?php if ($fichas): ?>
            <div class="text-muted" style="font-size:.85rem;margin-top:4px;">
              <?= htmlspecialchars($fichas['colegio']) ?> · DANE <?= htmlspecialchars($fichas['dane']) ?> · Periodo <?= htmlspecialchars($fichas['periodo']) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($fichas && $fichas['paquetes']): ?>
          <div class="col-md-5 col-sm-12 text-md-right fp-actions" style="margin-top:8px;">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
            <a class="btn btn-primary btn-sm" id="fp-pdf" href="<?= htmlspecialchars($url_pdf) ?>" data-base="<?= htmlspecialchars($url_pdf) ?>"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$fichas): ?>
        <div class="sm-section"><div class="sm-section-body fp-empty">
          <i class="bi bi-info-circle" style="font-size:1.6rem;"></i>
          <p style="margin-top:8px;">Las fichas técnicas solo están disponibles cuando el Tipo de adopción guardado del colegio es <strong>"Paquetes"</strong> o <strong>"Ambos"</strong>.</p>
        </div></div>
      <?php elseif (!$fichas['paquetes']): ?>
        <div class="sm-section"><div class="sm-section-body fp-empty">
          <i class="bi bi-box-seam" style="font-size:1.6rem;"></i>
          <p style="margin-top:8px;">Este colegio todavía no tiene paquetes guardados. Guarda la pestaña de Adopciones para armarlos.</p>
        </div></div>
      <?php else: ?>

        <?php if (count($fichas['paquetes']) > 1): ?>
        <div class="fp-toolbar">
          <div class="fp-chips">
            <button type="button" class="fp-chip active" data-paquete="">Todos (<?= count($fichas['paquetes']) ?>)</button>
            <?php foreach ($fichas['paquetes'] as $p): ?>
              <button type="button" class="fp-chip" data-paquete="<?= $p['id_paquete'] ?>"><?= htmlspecialchars($p['grado']) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="fp-search">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="fp-buscar" placeholder="Buscar libro o ISBN...">
          </div>
        </div>
        <?php endif; ?>

        <div class="fp-grid">
          <?php foreach ($fichas['paquetes'] as $p) render_ficha_paquete($p, $fichas['colegio'], $fichas['periodo']); ?>
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
  $(function () {
    var paqueteSel = '';

    function aplicarFiltros() {
      var q = ($('#fp-buscar').val() || '').trim().toLowerCase();
      $('.fp-card').each(function () {
        var card = $(this);
        var visiblePorGrado = !paqueteSel || String(card.data('paquete')) === paqueteSel;
        var coincidencias = 0;
        card.find('.fp-book').each(function () {
          var ok = !q || String($(this).data('buscar')).indexOf(q) !== -1;
          $(this).toggleClass('fp-hidden', !ok);
          if (ok) coincidencias++;
        });
        card.toggleClass('fp-hidden', !visiblePorGrado || (q && coincidencias === 0));
      });
      // El PDF descarga lo que se está viendo: todos los paquetes o solo el grado elegido.
      var pdf = $('#fp-pdf');
      pdf.attr('href', pdf.data('base') + (paqueteSel && pdf.data('base').indexOf('&paquete=') === -1 ? '&paquete=' + paqueteSel : ''));
    }

    $('.fp-chip').on('click', function () {
      $('.fp-chip').removeClass('active');
      $(this).addClass('active');
      paqueteSel = String($(this).data('paquete') || '');
      aplicarFiltros();
    });
    $('#fp-buscar').on('input', aplicarFiltros);

  });
</script>
<?php fichas_paquete_script(); ?>
</body>
</html>
