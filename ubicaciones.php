<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1 && ($_SESSION["id"] ?? null) != 21) {
    header("Location: index.php");
    exit;
}

$lugares = $bdd->query(
    "SELECT l.id, l.id_tipo, l.corredor, l.lugar,
            COUNT(DISTINCT CASE WHEN u.piso IS NOT NULL AND u.piso <> '' THEN u.piso END) AS total_pisos,
            COUNT(DISTINCT u.id) AS total_slots,
            COUNT(DISTINCT lu.id_libro) AS total_libros
     FROM lugares l
     LEFT JOIN ubicaciones u ON u.id_lugar = l.id AND u.act = 1
     LEFT JOIN libros_ubicaciones lu ON lu.ubicacion = u.id
     WHERE l.act = 1
     GROUP BY l.id, l.id_tipo, l.corredor, l.lugar
     ORDER BY l.id_tipo, l.corredor, l.lugar"
)->fetchAll();

$total_racks = $bdd->query("SELECT COUNT(*) FROM lugares WHERE act=1 AND id_tipo=1")->fetchColumn();
$total_estantes = $bdd->query("SELECT COUNT(*) FROM lugares WHERE act=1 AND id_tipo<>1")->fetchColumn();
$total_corredores = $bdd->query("SELECT COUNT(DISTINCT corredor) FROM lugares WHERE act=1")->fetchColumn();
$total_libros_ubicados = $bdd->query("SELECT COUNT(DISTINCT id_libro) FROM libros_ubicaciones")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Ubicaciones</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    #ubicaciones-table_wrapper { overflow-x: auto; }
    #ubicaciones-table { min-width: 760px; }

    .badge-tipo-ubicacion {
      display: inline-block; padding: 3px 10px; border-radius: 20px;
      font-size: .72rem; font-weight: 700; letter-spacing: .02em;
    }
    .badge-tipo-rack    { background: #ede9fe; color: #5b21b6; }
    .badge-tipo-estante { background: #e0f2fe; color: #0369a1; }

    .lugar-nombre { display: flex; align-items: center; gap: 10px; }
    .lugar-icono {
      width: 34px; height: 34px; border-radius: 9px; flex: 0 0 auto;
      display: flex; align-items: center; justify-content: center; font-size: .95rem;
    }
    .lugar-icono.rack    { background: #ede9fe; color: #5b21b6; }
    .lugar-icono.estante { background: #e0f2fe; color: #0369a1; }

    .sin-libros-cell { color: #94a3b8; font-style: italic; font-size: .8rem; }

    .btn-ver-ubicacion {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: 9px; border: none;
      color: #fff; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none;
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-ver-ubicacion:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }

    .btn-buscar-libro {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 18px; border-radius: 9px; border: none;
      color: #fff; font-size: .82rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      box-shadow: 0 3px 8px rgba(79,70,229,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-buscar-libro:hover {
      box-shadow: 0 5px 14px rgba(79,70,229,.4);
      transform: translateY(-1px);
      color: #fff;
    }

    /* Modal: Buscar libro */
    #ModalBuscarLibro .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalBuscarLibro .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalBuscarLibro .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalBuscarLibro .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalBuscarLibro .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalBuscarLibro .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalBuscarLibro .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalBuscarLibro .ml-body { padding: 20px 24px 24px; }
    #ModalBuscarLibro .ml-search-wrap { position: relative; margin-bottom: 16px; }
    #ModalBuscarLibro .ml-search-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8;
    }
    #buscar-libro-input {
      width: 100%; padding: 11px 14px 11px 40px; border: 1.5px solid #d1d5db; border-radius: 9px;
      font-size: .9rem; color: #1e293b; background: #f9fafb; outline: none;
    }
    #buscar-libro-input:focus { border-color: #7c3aed; background: #fff; }

    .bl-resultados { max-height: 400px; overflow-y: auto; }
    .bl-item {
      border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 10px;
    }
    .bl-item:last-child { margin-bottom: 0; }
    .bl-item-titulo { font-weight: 700; font-size: .88rem; color: #0f172a; margin-bottom: 2px; }
    .bl-item-isbn { font-size: .72rem; color: #94a3b8; margin-bottom: 10px; }
    .bl-ubicacion-row {
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
      background: #f8fafc; border-radius: 9px; padding: 8px 12px; margin-bottom: 6px;
    }
    .bl-ubicacion-row:last-child { margin-bottom: 0; }
    .bl-ubicacion-info { font-size: .78rem; color: #334155; display: flex; align-items: center; gap: 6px; }
    .bl-ubicacion-info i { color: #4f46e5; }
    .bl-ubicacion-pos {
      font-size: .68rem; font-weight: 700; color: #4f46e5; background: #ede9fe;
      padding: 2px 8px; border-radius: 12px; margin-left: 4px;
    }
    .bl-sin-ubicacion { font-size: .78rem; color: #94a3b8; font-style: italic; }
    .bl-btn-ver {
      display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
      padding: 5px 12px; border-radius: 7px; border: none; color: #fff;
      font-size: .72rem; font-weight: 600; cursor: pointer; text-decoration: none;
      background: linear-gradient(135deg,#4f46e5,#4338ca);
    }
    .bl-btn-ver:hover { color: #fff; opacity: .92; }
    .bl-estado { text-align: center; color: #94a3b8; padding: 30px 10px; font-size: .84rem; }
    .bl-estado i { font-size: 1.8rem; display: block; margin-bottom: 8px; color: #cbd5e1; }

    /* Modal: Crear ubicación */
    #ModalCrearUbicacion .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearUbicacion .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearUbicacion .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearUbicacion .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearUbicacion .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearUbicacion .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearUbicacion .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearUbicacion .ml-body { padding: 22px 24px 4px; }
    #ModalCrearUbicacion .ml-row { display: flex; gap: 14px; }
    #ModalCrearUbicacion .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearUbicacion .ml-field { margin-bottom: 18px; }
    #ModalCrearUbicacion .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearUbicacion .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearUbicacion .ml-input, #ModalCrearUbicacion .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearUbicacion .ml-input:focus, #ModalCrearUbicacion .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearUbicacion .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearUbicacion .ml-help { font-size: .74rem; color: #64748b; margin: -8px 0 18px; }
    #ModalCrearUbicacion .ml-help strong { color: #4f46e5; }
    #ModalCrearUbicacion .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearUbicacion .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearUbicacion .ml-btn-submit {
      padding: 9px 22px; border-radius: 8px; border: none; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); box-shadow: 0 4px 12px rgba(79,70,229,.3);
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
            <div class="title"><h4>Ubicaciones</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Ubicaciones</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary mr-2" data-toggle="modal" data-target="#ModalCrearUbicacion">
              <i class="bi bi-plus-circle mr-1"></i> Nueva ubicación
            </button>
            <button type="button" class="btn-buscar-libro" data-toggle="modal" data-target="#ModalBuscarLibro">
              <i class="bi bi-search"></i> Buscar libro
            </button>
          </div>
        </div>
      </div>

      <!-- Modal: Crear ubicación -->
      <div class="modal fade" id="ModalCrearUbicacion" tabindex="-1" role="dialog" aria-labelledby="ModalCrearUbicacionLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_ubicacion.php">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-plus-lg"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearUbicacionLabel">Nueva ubicación</h5>
                  <p class="ml-subtitle">Crea un rack o estante y genera sus espacios automáticamente</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-field">
                  <label class="ml-label" for="crear_id_tipo">Tipo de ubicación<span class="req">*</span></label>
                  <select name="id_tipo" id="crear_id_tipo" class="ml-select" required>
                    <option value="1">Rack</option>
                    <option value="2">Estante</option>
                  </select>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="crear_corredor">Corredor<span class="req">*</span></label>
                    <input type="text" name="corredor" id="crear_corredor" class="ml-input" required placeholder="Ej: 1">
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="crear_lugar">Nombre<span class="req">*</span></label>
                    <input type="text" name="lugar" id="crear_lugar" class="ml-input" required placeholder="Ej: Rack 11">
                  </div>
                </div>

                <div class="ml-row" id="campos-crear-rack">
                  <div class="ml-field">
                    <label class="ml-label" for="crear_num_pisos">Pisos<span class="req">*</span></label>
                    <input type="number" name="num_pisos" id="crear_num_pisos" class="ml-input" min="1" max="26" value="3" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="crear_num_pallets">Pallets por piso<span class="req">*</span></label>
                    <input type="number" name="num_pallets" id="crear_num_pallets" class="ml-input" min="1" max="50" value="2" required>
                  </div>
                </div>

                <div class="ml-field d-none" id="campo-crear-estante">
                  <label class="ml-label" for="crear_num_bandejas">Bandejas<span class="req">*</span></label>
                  <input type="number" name="num_bandejas" id="crear_num_bandejas" class="ml-input" min="1" max="26" value="6">
                </div>

                <p class="ml-help" id="crear-ubicacion-preview"></p>

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear ubicación</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal: Buscar libro -->
      <div class="modal fade" id="ModalBuscarLibro" tabindex="-1" role="dialog" aria-labelledby="ModalBuscarLibroLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">

            <div class="ml-header">
              <div class="ml-icon-badge"><i class="bi bi-search"></i></div>
              <div style="flex:1;">
                <h5 class="ml-title" id="ModalBuscarLibroLabel">Buscar libro</h5>
                <p class="ml-subtitle">Encuentra en qué rack o estante está un libro</p>
              </div>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>

            <div class="ml-body">
              <div class="ml-search-wrap">
                <i class="bi bi-search ml-search-icon"></i>
                <input type="text" id="buscar-libro-input" placeholder="Escribe el título o el ISBN...">
              </div>
              <div class="bl-resultados" id="bl-resultados">
                <div class="bl-estado">
                  <i class="bi bi-journal-text"></i>
                  Escribe al menos 2 caracteres para buscar
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-columns-gap"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_racks, 0, ',', '.') ?></h3>
              <p class="stat-label">Racks</p>
              <span class="stat-sub">Con piso y pallet</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern spurple"><i class="bi bi-archive"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_estantes, 0, ',', '.') ?></h3>
              <p class="stat-label">Estantes</p>
              <span class="stat-sub">Con bandejas</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sorange"><i class="bi bi-signpost-split"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_corredores, 0, ',', '.') ?></h3>
              <p class="stat-label">Corredores</p>
              <span class="stat-sub">En bodega</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sgreen"><i class="bi bi-book"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_libros_ubicados, 0, ',', '.') ?></h3>
              <p class="stat-label">Libros ubicados</p>
              <span class="stat-sub">Con posición asignada</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="ubicaciones-search" placeholder="Buscar por rack, estante o corredor...">
        </div>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-geo-alt mr-2"></i> Racks y estantes</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="ubicaciones-table">
            <thead>
              <tr>
                <th>Ubicación</th>
                <th>Tipo</th>
                <th>Corredor</th>
                <th class="text-center">Pisos</th>
                <th class="text-center">Espacios</th>
                <th class="text-center">Libros</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lugares as $l): $esRack = $l["id_tipo"] == 1; ?>
              <tr>
                <td>
                  <div class="lugar-nombre">
                    <span class="lugar-icono <?= $esRack ? 'rack' : 'estante' ?>">
                      <i class="bi <?= $esRack ? 'bi-columns-gap' : 'bi-archive' ?>"></i>
                    </span>
                    <strong><?= htmlspecialchars($l["lugar"]) ?></strong>
                  </div>
                </td>
                <td>
                  <span class="badge-tipo-ubicacion <?= $esRack ? 'badge-tipo-rack' : 'badge-tipo-estante' ?>">
                    <?= $esRack ? 'Rack' : 'Estante' ?>
                  </span>
                </td>
                <td>Corredor <?= htmlspecialchars($l["corredor"]) ?></td>
                <td class="text-center"><?= $esRack ? number_format($l["total_pisos"], 0, ',', '.') : '—' ?></td>
                <td class="text-center"><?= number_format($l["total_slots"], 0, ',', '.') ?></td>
                <td class="text-center">
                  <?php if ($l["total_libros"] > 0): ?>
                    <?= number_format($l["total_libros"], 0, ',', '.') ?>
                  <?php else: ?>
                    <span class="sin-libros-cell">Sin libros</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <a href="ubicacion_detalle.php?id=<?= $l['id'] ?>" class="btn-ver-ubicacion">
                    <i class="bi bi-eye"></i> Ver
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
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
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script src="src/ink-alerts.js"></script>
<script>
$(document).ready(function () {

  // ── Crear ubicación: alternar campos según Rack / Estante ──
  var $crearTipo = $('#crear_id_tipo');
  var $camposRack = $('#campos-crear-rack');
  var $campoEstante = $('#campo-crear-estante');
  var $preview = $('#crear-ubicacion-preview');

  function actualizarPreviewUbicacion() {
    var esRack = $crearTipo.val() == 1;
    if (esRack) {
      var pisos = parseInt($('#crear_num_pisos').val()) || 0;
      var pallets = parseInt($('#crear_num_pallets').val()) || 0;
      $preview.html('Se crearán <strong>' + pisos + ' pisos</strong> × <strong>' + pallets + ' pallets</strong> = <strong>' + (pisos * pallets) + ' espacios</strong>.');
    } else {
      var bandejas = parseInt($('#crear_num_bandejas').val()) || 0;
      $preview.html('Se crearán <strong>' + bandejas + ' bandejas</strong>.');
    }
  }

  $crearTipo.on('change', function () {
    var esRack = $(this).val() == 1;
    $camposRack.toggleClass('d-none', !esRack);
    $campoEstante.toggleClass('d-none', esRack);
    $('#crear_num_pisos, #crear_num_pallets').prop('required', esRack);
    $('#crear_num_bandejas').prop('required', !esRack);
    actualizarPreviewUbicacion();
  });

  $('#crear_num_pisos, #crear_num_pallets, #crear_num_bandejas').on('input', actualizarPreviewUbicacion);

  $('#ModalCrearUbicacion').on('shown.bs.modal', actualizarPreviewUbicacion);
  $('#ModalCrearUbicacion').on('hidden.bs.modal', function () {
    this.querySelector('form').reset();
    $camposRack.removeClass('d-none');
    $campoEstante.addClass('d-none');
  });

  var table = $('#ubicaciones-table').DataTable({
    responsive: false,
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    language: {
      lengthMenu:   "Mostrar _MENU_ registros",
      zeroRecords:  "No se encontraron resultados",
      emptyTable:   "No hay ubicaciones registradas",
      info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty:    "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      search:       "Buscar:",
      paginate: { first: "«", previous: "‹", next: "›", last: "»" }
    }
  });

  $('#ubicaciones-search').on('keyup', function () {
    table.search(this.value).draw();
  });

  // ── Buscar libro y ver su ubicación ──
  var $blInput = $('#buscar-libro-input');
  var $blResultados = $('#bl-resultados');
  var blTimer = null;

  function estadoBusqueda(icono, texto) {
    $blResultados.html(
      '<div class="bl-estado"><i class="bi ' + icono + '"></i>' + texto + '</div>'
    );
  }

  function renderResultados(libros) {
    if (!libros.length) {
      estadoBusqueda('bi-emoji-frown', 'No se encontraron libros con ese título o ISBN');
      return;
    }
    var html = '';
    libros.forEach(function (libro) {
      html += '<div class="bl-item">';
      html += '<div class="bl-item-titulo">' + $('<div>').text(libro.libro).html() + '</div>';
      html += '<div class="bl-item-isbn">ISBN: ' + $('<div>').text(libro.isbn).html() + '</div>';

      if (!libro.ubicaciones.length) {
        html += '<div class="bl-sin-ubicacion"><i class="bi bi-exclamation-circle mr-1"></i>Sin ubicación asignada</div>';
      } else {
        libro.ubicaciones.forEach(function (u) {
          var esRack = u.id_tipo == 1;
          var desc = esRack
            ? u.lugar + ' · Piso ' + u.piso + ' · Pallet ' + u.slot
            : u.lugar + ' · Bandeja ' + u.slot;
          html += '<div class="bl-ubicacion-row">';
          html += '<span class="bl-ubicacion-info"><i class="bi ' + (esRack ? 'bi-columns-gap' : 'bi-archive') + '"></i>' + $('<div>').text(desc).html();
          if (u.posicion) html += '<span class="bl-ubicacion-pos">Pos. ' + $('<div>').text(u.posicion).html() + '</span>';
          html += '</span>';
          html += '<a class="bl-btn-ver" href="ubicacion_detalle.php?id=' + u.lugar_id + '&slot=' + u.ubicacion_id + '"><i class="bi bi-geo-alt"></i> Ver</a>';
          html += '</div>';
        });
      }
      html += '</div>';
    });
    $blResultados.html(html);
  }

  $blInput.on('keyup', function () {
    var q = $(this).val().trim();
    clearTimeout(blTimer);

    if (q.length < 2) {
      estadoBusqueda('bi-journal-text', 'Escribe al menos 2 caracteres para buscar');
      return;
    }

    blTimer = setTimeout(function () {
      estadoBusqueda('bi-hourglass-split', 'Buscando...');
      $.getJSON('php/buscar_libro_ubicacion.php', { q: q }, function (libros) {
        renderResultados(libros);
      }).fail(function () {
        estadoBusqueda('bi-exclamation-triangle', 'Ocurrió un error al buscar');
      });
    }, 300);
  });

  $('#ModalBuscarLibro').on('hidden.bs.modal', function () {
    $blInput.val('');
    estadoBusqueda('bi-journal-text', 'Escribe al menos 2 caracteres para buscar');
  });

  $('#ModalBuscarLibro').on('shown.bs.modal', function () {
    $blInput.trigger('focus');
  });
});
</script>
</body>
</html>
