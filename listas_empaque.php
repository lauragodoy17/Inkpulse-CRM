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

$f = [
    'consecutivo' => trim($_GET['consecutivo'] ?? ''),
    'cliente'     => trim($_GET['cliente'] ?? ''),
    'desde'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '',
    'hasta'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '',
];
$listas = le_historial($bdd, $f);
$hayFiltro = implode('', $f) !== '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Listas de empaque</title>
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
    .le-section-body { padding:20px 24px; }
    .le-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-size:.85rem; font-weight:700; background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border:none; cursor:pointer; text-decoration:none; }
    .le-btn:hover { opacity:.9; color:#fff; text-decoration:none; }
    .le-btn-outline { background:#fff; color:#1d4ed8; border:1.5px solid #1d4ed8; }
    .le-btn-outline:hover { color:#1d4ed8; }
    .le-btn-sm { padding:4px 10px; font-size:.76rem; }
    .le-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
    .le-table { width:100%; font-size:.82rem; border-collapse:collapse; }
    .le-table th { background:#f8fafc; color:#64748b; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; padding:9px 10px; border-bottom:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
    .le-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .le-table .num { text-align:right; }
    .le-badge { display:inline-block; font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:999px; }
    .le-badge-ok { background:#dcfce7; color:#15803d; }
    .le-badge-anulada { background:#fee2e2; color:#b91c1c; }
    tr.le-anulada td { color:#94a3b8; }
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
            <div class="title"><h4>Listas de empaque</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item active" aria-current="page">Historial</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <a href="lista_empaque.php" class="le-btn"><i class="bi bi-plus-lg"></i> Nueva lista</a>
          </div>
        </div>
      </div>

      <div class="le-section">
        <div class="le-section-body">
          <form method="get" class="row align-items-end">
            <div class="col-md-2 form-group"><label class="le-label">N.º de lista</label><input type="text" name="consecutivo" class="form-control form-control-sm" value="<?= htmlspecialchars($f['consecutivo']) ?>" placeholder="Ej. 001"></div>
            <div class="col-md-5 form-group"><label class="le-label">Cliente</label><input type="text" name="cliente" class="form-control form-control-sm" value="<?= htmlspecialchars($f['cliente']) ?>"></div>
            <div class="col-md-2 form-group"><label class="le-label">Desde</label><input type="date" name="desde" class="form-control form-control-sm" value="<?= htmlspecialchars($f['desde']) ?>"></div>
            <div class="col-md-2 form-group"><label class="le-label">Hasta</label><input type="date" name="hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($f['hasta']) ?>"></div>
            <div class="col-md-1 form-group" style="display:flex; gap:6px;">
              <button type="submit" class="le-btn le-btn-sm" title="Buscar"><i class="bi bi-search"></i></button>
              <?php if ($hayFiltro): ?><a href="listas_empaque.php" class="le-btn le-btn-outline le-btn-sm" title="Limpiar"><i class="bi bi-x-lg"></i></a><?php endif; ?>
            </div>
          </form>

          <div class="table-responsive">
            <table class="le-table">
              <thead><tr>
                <th>N.º lista</th><th>Fecha</th><th>Cliente</th><th>Documentos WO</th>
                <th class="num">Unid.</th><th class="num">Cajas</th><th>Estado</th><th>Generó</th><th></th>
              </tr></thead>
              <tbody>
              <?php if (!$listas): ?>
                <tr><td colspan="9" style="text-align:center; color:#64748b; padding:26px;"><?= $hayFiltro ? 'No hay listas de empaque con esos filtros.' : 'Todavía no se ha generado ninguna lista de empaque.' ?></td></tr>
              <?php endif; ?>
              <?php foreach ($listas as $l): $anulada = (int)$l['estado'] === 0; ?>
                <tr class="<?= $anulada ? 'le-anulada' : '' ?>">
                  <td><strong><?= le_formatear_consecutivo($l['consecutivo']) ?></strong></td>
                  <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($l['fecha'])) ?></td>
                  <td><?= htmlspecialchars($l['cliente']) ?></td>
                  <td><?= htmlspecialchars($l['documentos'] ?? '') ?></td>
                  <td class="num"><?= number_format((int)$l['total_unidades'], 0, ',', '.') ?></td>
                  <td class="num"><?= (int)$l['total_cajas'] ?></td>
                  <td><span class="le-badge <?= $anulada ? 'le-badge-anulada' : 'le-badge-ok' ?>"><?= $anulada ? 'Anulada' : 'Activa' ?></span></td>
                  <td><?= htmlspecialchars($l['usuario'] ?? '') ?></td>
                  <td style="white-space:nowrap;">
                    <a href="lista_empaque_ver.php?id=<?= (int)$l['id'] ?>" class="le-btn le-btn-outline le-btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
                    <a href="php/lista_empaque_pdf.php?id=<?= (int)$l['id'] ?>" target="_blank" rel="noopener" class="le-btn le-btn-sm" title="Descargar PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($listas) >= 300): ?><p class="le-label" style="margin-top:10px;">Se muestran las 300 más recientes. Usa los filtros para acotar.</p><?php endif; ?>
        </div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
</body>
</html>
