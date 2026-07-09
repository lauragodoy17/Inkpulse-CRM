<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1 && ($_SESSION["id"] ?? null) != 21) {
    header("Location: index.php");
    exit;
}

$id_lugar = (int)($_GET['id'] ?? 0);

$stmt = $bdd->prepare("SELECT id, id_tipo, corredor, lugar FROM lugares WHERE id = :id AND act = 1");
$stmt->execute([':id' => $id_lugar]);
$lugar = $stmt->fetch();

if (!$lugar) {
    header("Location: ubicaciones.php");
    exit;
}

$esRack = $lugar['id_tipo'] == 1;

$stmt = $bdd->prepare("SELECT id, piso, ubicacion FROM ubicaciones WHERE id_lugar = :id AND act = 1 ORDER BY piso, ubicacion");
$stmt->execute([':id' => $id_lugar]);
$ubicaciones = $stmt->fetchAll();

$libros_por_ubicacion = [];
if ($ubicaciones) {
    $ids = array_column($ubicaciones, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $bdd->prepare(
        "SELECT lu.ubicacion, lu.posicion, l.id, l.libro, l.isbn
         FROM libros_ubicaciones lu
         JOIN libros l ON l.id = lu.id_libro
         WHERE lu.ubicacion IN ($in)
         ORDER BY l.libro"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $libros_por_ubicacion[$row['ubicacion']][] = $row;
    }
}

$pisos = [];
if ($esRack) {
    foreach ($ubicaciones as $u) {
        $piso = ($u['piso'] !== null && $u['piso'] !== '') ? $u['piso'] : 'Sin piso';
        $pisos[$piso][] = $u;
    }
} else {
    if ($ubicaciones) $pisos['__todas__'] = $ubicaciones;
}

$total_espacios = count($ubicaciones);
$total_libros_lugar = 0;
foreach ($libros_por_ubicacion as $lista) $total_libros_lugar += count($lista);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - <?= htmlspecialchars($lugar['lugar']) ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .btn-volver-ubicaciones {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 9px; border: 1.5px solid #d1d5db; background: #fff;
      color: #475569; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none;
      transition: border-color .15s ease, color .15s ease, background .15s ease;
    }
    .btn-volver-ubicaciones:hover { border-color: #4f46e5; color: #4f46e5; background: #f5f4ff; }

    /* Encabezado del lugar */
    .lugar-head-card {
      background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;
      box-shadow: 0 2px 12px rgba(15,23,42,.06);
      padding: 22px 26px; margin-bottom: 22px;
      display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
    }
    .lugar-head-icon {
      width: 58px; height: 58px; border-radius: 14px; flex: 0 0 auto;
      display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #fff;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      box-shadow: 0 6px 16px rgba(79,70,229,.28);
    }
    .lugar-head-icon.estante { background: linear-gradient(135deg,#0ea5e9,#0369a1); box-shadow: 0 6px 16px rgba(3,105,161,.28); }
    .lugar-head-info { flex: 1 1 260px; min-width: 200px; }
    .lugar-head-info h4 { margin: 0 0 4px; font-weight: 800; color: #0f172a; }
    .lugar-head-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: .8rem; color: #64748b; }
    .badge-tipo-ubicacion {
      display: inline-block; padding: 3px 10px; border-radius: 20px;
      font-size: .72rem; font-weight: 700; letter-spacing: .02em;
    }
    .badge-tipo-rack    { background: #ede9fe; color: #5b21b6; }
    .badge-tipo-estante { background: #e0f2fe; color: #0369a1; }

    .lugar-head-stats { display: flex; gap: 10px; flex-wrap: wrap; }
    .lugar-stat-chip {
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
      padding: 10px 18px; text-align: center; min-width: 92px;
    }
    .lugar-stat-chip strong { display: block; font-size: 1.15rem; color: #1e293b; line-height: 1.2; }
    .lugar-stat-chip span { font-size: .68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }

    /* Corredor pill */
    .corredor-banner { text-align: center; margin-bottom: 22px; }
    .corredor-banner .pill {
      display: inline-block; padding: 7px 22px; border-radius: 20px;
      background: #f1f5f9; color: #334155; font-weight: 700; font-size: .85rem; letter-spacing: .02em;
    }

    /* Columnas de piso (Rack) */
    .rack-pisos-wrap { display: flex; gap: 20px; overflow-x: auto; padding-bottom: 12px; }
    .rack-piso-col {
      flex: 0 0 300px; background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;
      box-shadow: 0 2px 10px rgba(15,23,42,.06); overflow: hidden;
      transition: box-shadow .2s ease, transform .2s ease;
    }
    .rack-piso-col:hover { box-shadow: 0 8px 22px rgba(15,23,42,.1); transform: translateY(-2px); }
    .rack-piso-head {
      background: linear-gradient(135deg,#4f46e5,#4338ca); color: #fff;
      padding: 14px 18px; font-weight: 700; font-size: .92rem;
      display: flex; align-items: center; gap: 8px;
    }
    .rack-piso-body { padding: 16px; }

    /* Grid de estantes (sin piso) */
    .estante-grid { display: flex; flex-wrap: wrap; gap: 20px; }
    .estante-grid .rack-slot-box { flex: 0 0 300px; margin-bottom: 0; }
    .estante-grid .rack-slot-title {
      background: linear-gradient(135deg,#0ea5e9,#0369a1); color: #fff;
      margin: -14px -16px 12px; padding: 12px 16px; border-radius: 10px 10px 0 0;
    }

    /* Cajas de pallet / bandeja */
    .rack-slot-box {
      background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
      padding: 14px 16px; margin-bottom: 12px;
    }
    .rack-slot-box:last-child { margin-bottom: 0; }
    .rack-slot-title {
      font-size: .82rem; font-weight: 700; color: #1e293b; margin-bottom: 8px;
      display: flex; align-items: center; gap: 6px;
    }
    .rack-slot-title .count-badge {
      margin-left: auto; background: #e0e7ff; color: #4338ca; font-size: .68rem; font-weight: 700;
      padding: 2px 9px; border-radius: 20px;
    }
    .rack-slot-empty { color: #94a3b8; font-style: italic; font-size: .78rem; padding: 4px 0; }
    .rack-libro {
      display: flex; align-items: flex-start; flex-wrap: wrap; gap: 4px 8px; padding: 7px 0;
      border-top: 1px dashed #e2e8f0; font-size: .8rem; color: #334155;
    }
    .rack-libro:first-of-type { border-top: none; }
    .rack-libro i { color: #94a3b8; margin-top: 2px; flex: 0 0 auto; }
    .rack-libro-titulo { flex: 1 1 140px; min-width: 0; word-break: break-word; }
    .rack-libro-pos {
      font-size: .66rem; font-weight: 700; color: #4f46e5; background: #ede9fe;
      padding: 2px 8px; border-radius: 12px; white-space: normal;
      max-width: 100%; word-break: break-word; margin-left: auto;
    }

    .sin-espacios { text-align: center; color: #94a3b8; padding: 60px 20px; }
    .sin-espacios i { font-size: 2.4rem; display: block; margin-bottom: 10px; color: #cbd5e1; }

    .rack-slot-box.slot-resaltado {
      border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.25), 0 6px 16px rgba(124,58,237,.2);
      animation: slotPulso 1.4s ease-in-out 2;
    }
    @keyframes slotPulso {
      0%, 100% { box-shadow: 0 0 0 3px rgba(124,58,237,.25), 0 6px 16px rgba(124,58,237,.2); }
      50% { box-shadow: 0 0 0 6px rgba(124,58,237,.15), 0 6px 16px rgba(124,58,237,.15); }
    }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <!-- Encabezado -->
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4><?= htmlspecialchars($lugar['lugar']) ?></h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item"><a href="ubicaciones.php">Ubicaciones</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($lugar['lugar']) ?></li>
              </ol>
            </nav>
          </div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <a href="ubicaciones.php" class="btn-volver-ubicaciones">
              <i class="bi bi-arrow-left"></i> Volver a Ubicaciones
            </a>
          </div>
        </div>
      </div>

      <!-- Tarjeta de encabezado del lugar -->
      <div class="lugar-head-card">
        <div class="lugar-head-icon <?= $esRack ? '' : 'estante' ?>">
          <i class="bi <?= $esRack ? 'bi-columns-gap' : 'bi-archive' ?>"></i>
        </div>
        <div class="lugar-head-info">
          <h4><?= htmlspecialchars($lugar['lugar']) ?></h4>
          <div class="lugar-head-meta">
            <span class="badge-tipo-ubicacion <?= $esRack ? 'badge-tipo-rack' : 'badge-tipo-estante' ?>">
              <?= $esRack ? 'Rack' : 'Estante' ?>
            </span>
            <span><i class="bi bi-signpost-split mr-1"></i>Corredor <?= htmlspecialchars($lugar['corredor']) ?></span>
          </div>
        </div>
        <div class="lugar-head-stats">
          <?php if ($esRack): ?>
          <div class="lugar-stat-chip">
            <strong><?= count($pisos) ?></strong>
            <span>Pisos</span>
          </div>
          <?php endif; ?>
          <div class="lugar-stat-chip">
            <strong><?= $total_espacios ?></strong>
            <span><?= $esRack ? 'Pallets' : 'Bandejas' ?></span>
          </div>
          <div class="lugar-stat-chip">
            <strong><?= $total_libros_lugar ?></strong>
            <span>Libros</span>
          </div>
        </div>
      </div>

      <?php if (!$total_espacios): ?>
        <div class="sin-espacios">
          <i class="bi bi-inboxes"></i>
          Esta ubicación todavía no tiene pisos, pallets o bandejas configuradas.
        </div>
      <?php elseif ($esRack): ?>

        <div class="corredor-banner"><span class="pill">Corredor: <?= htmlspecialchars($lugar['corredor']) ?></span></div>

        <div class="rack-pisos-wrap">
          <?php foreach ($pisos as $piso => $slots): ?>
            <div class="rack-piso-col">
              <div class="rack-piso-head">
                <i class="bi bi-layers"></i> <?= htmlspecialchars($lugar['lugar']) ?> Piso <?= htmlspecialchars($piso) ?>
              </div>
              <div class="rack-piso-body">
                <?php foreach ($slots as $slot):
                  $libros = $libros_por_ubicacion[$slot['id']] ?? [];
                ?>
                  <div class="rack-slot-box" data-ubicacion-id="<?= $slot['id'] ?>">
                    <div class="rack-slot-title">
                      <i class="bi bi-box-seam"></i> Pallet <?= htmlspecialchars($slot['ubicacion']) ?>
                      <?php if ($libros): ?><span class="count-badge"><?= count($libros) ?></span><?php endif; ?>
                    </div>
                    <?php if ($libros): ?>
                      <?php foreach ($libros as $lb): ?>
                        <div class="rack-libro">
                          <i class="bi bi-journal-text"></i>
                          <span class="rack-libro-titulo"><?= htmlspecialchars($lb['libro']) ?></span>
                          <?php if ($lb['posicion'] !== null && $lb['posicion'] !== ''): ?>
                            <span class="rack-libro-pos">Pos. <?= htmlspecialchars($lb['posicion']) ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="rack-slot-empty">Sin libros asignados</div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>

        <div class="corredor-banner"><span class="pill">Corredor: <?= htmlspecialchars($lugar['corredor']) ?></span></div>

        <div class="estante-grid">
          <?php foreach ($ubicaciones as $slot):
            $libros = $libros_por_ubicacion[$slot['id']] ?? [];
          ?>
            <div class="rack-slot-box" data-ubicacion-id="<?= $slot['id'] ?>">
              <div class="rack-slot-title">
                <i class="bi bi-box-seam"></i> Bandeja <?= htmlspecialchars($slot['ubicacion']) ?>
                <?php if ($libros): ?><span class="count-badge"><?= count($libros) ?></span><?php endif; ?>
              </div>
              <?php if ($libros): ?>
                <?php foreach ($libros as $lb): ?>
                  <div class="rack-libro">
                    <i class="bi bi-journal-text"></i>
                    <span class="rack-libro-titulo"><?= htmlspecialchars($lb['libro']) ?></span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="rack-slot-empty">Sin libros asignados</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
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
  var params = new URLSearchParams(window.location.search);
  var slotId = params.get('slot');
  if (!slotId) return;

  var slot = document.querySelector('.rack-slot-box[data-ubicacion-id="' + slotId + '"]');
  if (!slot) return;

  slot.classList.add('slot-resaltado');
  slot.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
})();
</script>
</body>
</html>
