<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1 && ($_SESSION["id"] ?? null) != 21) {
    header("Location: index.php");
    exit;
}

$puede_gestionar = ($_SESSION["tipo"] == 1 || $_SESSION["id"] == 21);
// Carlos Puentes (id=21) solo puede consultar y actualizar la ubicación en bodega de un
// libro: no puede crear, eliminar, ni cambiar isbn/título/materia/grado/precio/presupuesto.
$puede_crear_eliminar = ($_SESSION["tipo"] == 1);
$es_solo_ubicacion = ($_SESSION["id"] == 21 && $_SESSION["tipo"] != 1);
// Asociar libros a una serie es exclusivo de los usuarios id=1, 93 y 94.
$puede_asociar = in_array($_SESSION["id"], [1, 93, 94]);

$total_libros = $bdd->query("SELECT COUNT(*) FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id")->fetchColumn();
$total_activos = $bdd->query("SELECT COUNT(*) FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id WHERE l.presupuesto=1")->fetchColumn();

$sql_materias = "SELECT id, materia FROM materias ORDER BY materia";
$req_materias = $bdd->prepare($sql_materias);
$req_materias->execute();
$materias = $req_materias->fetchAll();

$sql_grados = "SELECT id, grado FROM grados ORDER BY id";
$req_grados = $bdd->prepare($sql_grados);
$req_grados->execute();
$grados = $req_grados->fetchAll();

$lugares_bodega = $bdd->query("SELECT id, id_tipo, corredor, lugar FROM lugares WHERE act=1 ORDER BY id_tipo, corredor, lugar")->fetchAll();
$ubicaciones_bodega = $bdd->query("SELECT id, id_lugar, piso, ubicacion FROM ubicaciones WHERE act=1 ORDER BY id_lugar, piso, ubicacion")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Libros</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    @media (max-width: 575px) {
      #libros-table_wrapper { overflow-x: auto; }
      #libros-table { min-width: 1050px; }
    }

    /* Columna de acciones (mismo estilo que zonas.php) */
    .acciones-libro { display: inline-flex; align-items: center; gap: 6px; }
    .btn-editar-libro, .btn-eliminar-libro {
      width: 32px; height: 32px; padding: 0; border: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: .88rem; cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-editar-libro {
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
    }
    .btn-editar-libro:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-editar-libro:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(67,56,202,.3); }
    .btn-editar-libro:focus { outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,.25); }

    .btn-eliminar-libro {
      background: linear-gradient(135deg,#ef4444,#dc2626);
      box-shadow: 0 3px 8px rgba(220,38,38,.28);
    }
    .btn-eliminar-libro:hover {
      background: linear-gradient(135deg,#dc2626,#b91c1c);
      box-shadow: 0 5px 14px rgba(220,38,38,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-eliminar-libro:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(220,38,38,.3); }
    .btn-eliminar-libro:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }

    /* Badges de asociación (serie padre/hijo) */
    .badge-serie {
      display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; font-weight: 600;
      padding: 4px 8px; border-radius: 6px; white-space: nowrap;
    }
    .badge-serie-padre { background: #e0e7ff; color: #3730a3; }
    .badge-serie-hijo { background: #dcfce7; color: #15803d; max-width: 140px; overflow: hidden; text-overflow: ellipsis; }
    .badge-serie-libre { background: #f1f5f9; color: #64748b; }
    .btn-asociar-serie {
      margin-left: 4px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px;
      font-size: .7rem; padding: 3px 7px; cursor: pointer; color: #4338ca;
    }
    .btn-asociar-serie:hover { background: #eef2ff; border-color: #a5b4fc; }

    /* Modal: Asociar a serie */
    #ModalAsociarSerie .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalAsociarSerie .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalAsociarSerie .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalAsociarSerie .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalAsociarSerie .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalAsociarSerie .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalAsociarSerie .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalAsociarSerie .ml-body { padding: 22px 24px 4px; }
    #ModalAsociarSerie .ml-field { margin-bottom: 18px; }
    #ModalAsociarSerie .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalAsociarSerie .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalAsociarSerie .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalAsociarSerie .ml-btn-submit {
      padding: 9px 22px; border-radius: 8px; border: none; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); box-shadow: 0 4px 12px rgba(79,70,229,.3);
    }
    #select-libro-padre + .select2-container { width: 100% !important; }
    #editar_lugar + .select2-container { width: 100% !important; }
    .select2-dropdown { border:1.5px solid #d1d5db; border-radius:8px; box-shadow:0 4px 20px rgba(15,23,42,.10); }
    .select2-search--dropdown { padding:8px; }
    .select2-search--dropdown .select2-search__field { border:1.5px solid #d1d5db; border-radius:7px; padding:7px 12px; font-size:.85rem; outline:none; }
    .select2-search--dropdown .select2-search__field:focus { border-color:#2563eb; }
    .select2-results__option { font-size:.875rem; padding:8px 14px; }
    .select2-results__option[aria-selected=true] { background:#e0e7ff !important; color:#1e293b !important; }
    .select2-results__option--highlighted[aria-selected] { background:#2563eb !important; color:#fff !important; }

    /* Modal: Crear libro / Editar libro */
    #ModalCrearLibro .modal-content, #ModalEditarLibro .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearLibro .ml-header, #ModalEditarLibro .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearLibro .ml-icon-badge, #ModalEditarLibro .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearLibro .ml-icon-badge i, #ModalEditarLibro .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearLibro .ml-title, #ModalEditarLibro .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearLibro .ml-subtitle, #ModalEditarLibro .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearLibro .close, #ModalEditarLibro .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearLibro .ml-body, #ModalEditarLibro .ml-body { padding: 22px 24px 4px; }
    #ModalCrearLibro .ml-row, #ModalEditarLibro .ml-row { display: flex; gap: 14px; }
    #ModalCrearLibro .ml-row > .ml-field, #ModalEditarLibro .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearLibro .ml-field, #ModalEditarLibro .ml-field { margin-bottom: 18px; }
    #ModalCrearLibro .ml-label, #ModalEditarLibro .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearLibro .ml-label .req, #ModalEditarLibro .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearLibro .ml-input, #ModalCrearLibro .ml-select,
    #ModalEditarLibro .ml-input, #ModalEditarLibro .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearLibro .ml-input:focus, #ModalCrearLibro .ml-select:focus,
    #ModalEditarLibro .ml-input:focus, #ModalEditarLibro .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearLibro .ml-select, #ModalEditarLibro .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearLibro .ml-footer, #ModalEditarLibro .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearLibro .ml-btn-cancel, #ModalEditarLibro .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearLibro .ml-btn-submit, #ModalEditarLibro .ml-btn-submit {
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
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Libros</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Libros</li>
              </ol>
            </nav>
          </div>
          <?php if ($puede_crear_eliminar): ?>
          <div class="col-md-6 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#ModalCrearLibro">
              <i class="bi bi-plus-circle mr-1"></i> Crear libro
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-book"></i></div>
            <div class="stat-info-modern">
              <h3 id="lb-stat-total"><?= number_format($total_libros, 0, ',', '.') ?></h3>
              <p class="stat-label">Total libros</p>
              <span class="stat-sub">En el catálogo</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sgreen"><i class="bi bi-check-circle"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total_activos, 0, ',', '.') ?></h3>
              <p class="stat-label">Activos en presupuesto</p>
              <span class="stat-sub">Disponibles para presupuestar</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="libros-search" placeholder="Buscar por ISBN, título, materia o grado (mín. 4 caracteres)...">
        </div>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de libros</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="libros-table">
            <thead>
              <tr>
                <th>ISBN</th>
                <th>Título</th>
                <th>Ubicación</th>
                <th>Materia</th>
                <th>Grado</th>
                <th>Precio $</th>
                <th>Activo</th>
                <th>Asociación</th>
                <th>Acciones</th>
              </tr>
            </thead>
          </table>
        </div>
      </div>

      <!-- Modal: Crear libro -->
      <?php if ($puede_crear_eliminar): ?>
      <div class="modal fade" id="ModalCrearLibro" tabindex="-1" role="dialog" aria-labelledby="ModalCrearLibroLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_libro.php">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-journal-plus"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearLibroLabel">Crear libro</h5>
                  <p class="ml-subtitle">Agrega un nuevo título al catálogo</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="isbn">ISBN<span class="req">*</span></label>
                    <input type="text" name="isbn" id="isbn" class="ml-input" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="precio">Precio $</label>
                    <input type="number" name="precio" id="precio" class="ml-input" step="any">
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="libro">Título<span class="req">*</span></label>
                  <input type="text" name="libro" id="libro" class="ml-input" required>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="materia">Materia<span class="req">*</span></label>
                    <select name="materia" id="materia" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($materias as $materia): ?>
                        <option value="<?= $materia["id"] ?>"><?= htmlspecialchars($materia["materia"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="grado">Grado<span class="req">*</span></label>
                    <select name="grado" id="grado" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado["id"] ?>"><?= htmlspecialchars($grado["grado"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="presupuesto">¿Activo en presupuesto?<span class="req">*</span></label>
                  <select name="presupuesto" id="presupuesto" class="ml-select" required>
                    <option value="">Seleccione</option>
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                  </select>
                </div>

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear libro</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Modal: Editar libro -->
      <?php if ($puede_gestionar): ?>
      <div class="modal fade" id="ModalEditarLibro" tabindex="-1" role="dialog" aria-labelledby="ModalEditarLibroLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form id="form-editar-libro">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-pencil-square"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalEditarLibroLabel">Editar libro</h5>
                  <p class="ml-subtitle"><?= $es_solo_ubicacion ? 'Solo puedes actualizar la ubicación en bodega' : 'Actualiza los datos del título' ?></p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <?php $solo_ubi_attr = $es_solo_ubicacion ? 'disabled' : ''; ?>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_isbn">ISBN<span class="req">*</span></label>
                    <input type="text" name="isbn" id="editar_isbn" class="ml-input" <?= $solo_ubi_attr ?> <?= $es_solo_ubicacion ? '' : 'required' ?>>
                  </div>
                  <div class="ml-field" id="campo-editar-precio">
                    <label class="ml-label" for="editar_precio">Precio $</label>
                    <input type="number" name="precio" id="editar_precio" class="ml-input" step="any" <?= $solo_ubi_attr ?>>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="editar_libro">Título<span class="req">*</span></label>
                  <input type="text" name="libro" id="editar_libro" class="ml-input" <?= $solo_ubi_attr ?> <?= $es_solo_ubicacion ? '' : 'required' ?>>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_materia">Materia<span class="req">*</span></label>
                    <select name="materia" id="editar_materia" class="ml-select" <?= $solo_ubi_attr ?> <?= $es_solo_ubicacion ? '' : 'required' ?>>
                      <option value="">Seleccione</option>
                      <?php foreach ($materias as $materia): ?>
                        <option value="<?= $materia["id"] ?>"><?= htmlspecialchars($materia["materia"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="editar_grado">Grado<span class="req">*</span></label>
                    <select name="grado" id="editar_grado" class="ml-select" <?= $solo_ubi_attr ?> <?= $es_solo_ubicacion ? '' : 'required' ?>>
                      <option value="">Seleccione</option>
                      <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado["id"] ?>"><?= htmlspecialchars($grado["grado"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="editar_presupuesto">¿Activo en presupuesto?<span class="req">*</span></label>
                  <select name="presupuesto" id="editar_presupuesto" class="ml-select" <?= $solo_ubi_attr ?> <?= $es_solo_ubicacion ? '' : 'required' ?>>
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                  </select>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="editar_lugar">Ubicación en bodega</label>
                  <select id="editar_lugar" class="ml-select">
                    <option value="">Sin ubicación asignada</option>
                  </select>
                </div>

                <div class="ml-row d-none" id="fila-ubicacion-detalle">
                  <div class="ml-field d-none" id="campo-editar-piso">
                    <label class="ml-label" for="editar_piso">Piso</label>
                    <select id="editar_piso" class="ml-select"></select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" id="label-editar-slot" for="editar_slot">Posición</label>
                    <select id="editar_slot" class="ml-select"></select>
                  </div>
                </div>

                <div class="ml-field d-none" id="campo-editar-posicion">
                  <label class="ml-label" for="editar_posicion">Posición dentro del pallet</label>
                  <input type="text" id="editar_posicion" class="ml-input" placeholder="Ej: 3">
                </div>

                <input type="hidden" name="id_libro" id="editar_id_libro" value="">

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Guardar cambios</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Modal: Asociar a serie -->
      <?php if ($puede_asociar): ?>
      <div class="modal fade" id="ModalAsociarSerie" tabindex="-1" role="dialog" aria-labelledby="ModalAsociarSerieLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form id="form-asociar-serie">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-diagram-3"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalAsociarSerieLabel">Asociar a una serie</h5>
                  <p class="ml-subtitle">Libro: <strong id="asociar-libro-nombre"></strong></p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">
                <div class="ml-field">
                  <label class="ml-label" for="select-libro-padre">Libro padre</label>
                  <select id="select-libro-padre" class="ml-select" style="width:100%"></select>
                  <small class="text-muted">Deja el campo vacío y guarda para quitar la asociación.</small>
                </div>
              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Guardar</button>
              </div>
            </form>
          </div>
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
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
  var LUGARES_BODEGA = <?= json_encode($lugares_bodega) ?>;
  var UBICACIONES_BODEGA = <?= json_encode($ubicaciones_bodega) ?>;
  var SOLO_UBICACION = <?= json_encode($es_solo_ubicacion) ?>;

$(document).ready(function () {
  $.fn.dataTable.ext.errMode = 'none';

  // ── Ubicación en bodega: selects en cascada (Lugar → Piso → Pallet/Bandeja) ──
  var $editarLugar = $('#editar_lugar');
  LUGARES_BODEGA.forEach(function (l) {
    $editarLugar.append($('<option>').val(l.id).data('tipo', l.id_tipo).text(l.lugar));
  });
  $editarLugar.select2({
    dropdownParent: $('#ModalEditarLibro .modal-content'),
    width: 'resolve'
  });

  function ubicacionesDe(idLugar) {
    return UBICACIONES_BODEGA.filter(function (u) { return u.id_lugar == idLugar; });
  }

  function poblarSlot(idLugar, piso) {
    var $slot = $('#editar_slot');
    $slot.empty();
    ubicacionesDe(idLugar).filter(function (u) {
      return piso === null ? true : u.piso === piso;
    }).forEach(function (u) {
      $slot.append($('<option>').val(u.id).text(u.ubicacion));
    });
  }

  $editarLugar.on('change', function () {
    var idLugar = $(this).val();
    var tipo = $(this).find(':selected').data('tipo');

    if (!idLugar) {
      $('#fila-ubicacion-detalle').addClass('d-none');
      $('#campo-editar-posicion').addClass('d-none');
      $('#editar_piso').empty();
      $('#editar_slot').empty();
      return;
    }

    $('#fila-ubicacion-detalle').removeClass('d-none');

    var pisos = [];
    ubicacionesDe(idLugar).forEach(function (u) {
      if (u.piso && pisos.indexOf(u.piso) === -1) pisos.push(u.piso);
    });

    if (tipo == 1 && pisos.length) {
      // Rack: tiene piso y posición dentro del pallet
      $('#campo-editar-piso').removeClass('d-none');
      $('#label-editar-slot').text('Pallet');
      $('#campo-editar-posicion').removeClass('d-none');

      var $piso = $('#editar_piso').empty();
      pisos.forEach(function (p) { $piso.append($('<option>').val(p).text(p)); });
      poblarSlot(idLugar, pisos[0]);
    } else {
      // Estante: sin piso ni posición dentro del pallet
      $('#campo-editar-piso').addClass('d-none');
      $('#label-editar-slot').text('Bandeja');
      $('#campo-editar-posicion').addClass('d-none');
      $('#editar_posicion').val('');

      poblarSlot(idLugar, '');
    }
  });

  $('#editar_piso').on('change', function () {
    poblarSlot($editarLugar.val(), $(this).val());
  });

  var table = $('#libros-table').DataTable({
    processing: true,
    serverSide: true,
    responsive: { details: false },
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    ajax: {
      url: "php/libros_tabla.php"
    },
    columns: [
      { data: "isbn", width: "10%" },
      { data: "libro", width: "18%" },
      { data: "ubicacion", orderable: false, width: "13%" },
      { data: "materia", width: "12%" },
      { data: "grado", width: "11%" },
      { data: "precio", width: "8%" },
      { data: "presupuesto", width: "7%" },
      { data: "asociacion", orderable: false, width: "13%" },
      { data: "acciones", orderable: false, width: "8%" },
    ],
    language: {
      lengthMenu:   "Mostrar _MENU_ registros",
      zeroRecords:  "No se encontraron resultados",
      emptyTable:   "No hay información para mostrar",
      info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty:    "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      search:       "Buscar:",
      processing:   '<div class="dt-loading"><i class="bi bi-arrow-repeat"></i> Cargando...</div>',
      paginate: { first: "«", previous: "‹", next: "›", last: "»" }
    },
    initComplete: function () {
      $('#lb-stat-total').text(this.api().page.info().recordsTotal.toLocaleString('es-CO'));
    }
  });

  $('#libros-search').on('keyup', function () {
    var val = this.value;
    if (val.length >= 4 || val.length === 0) {
      table.search(val).draw();
    }
  });

  $('#libros-table').on('click', '.btn-editar-libro', function () {
    var $btn = $(this);
    var esSerie = $btn.data('serie') == 1;

    $('#editar_id_libro').val($btn.data('id'));
    $('#editar_isbn').val($btn.data('isbn'));
    $('#editar_libro').val($btn.data('libro'));
    $('#editar_materia').val($btn.data('materia'));
    $('#editar_grado').val($btn.data('grado'));
    $('#editar_precio').val($btn.data('precio'));
    $('#editar_presupuesto').val($btn.data('presupuesto'));

    $('#campo-editar-precio').toggleClass('d-none', esSerie);

    // Ubicación actual (si tiene)
    var idLugar = $btn.data('lugar') || '';
    var piso = $btn.data('piso') || '';
    var idUbicacion = $btn.data('ubicacion-id') || '';
    var posicion = $btn.data('ubicacion-posicion') || '';

    $editarLugar.val(idLugar).trigger('change');
    if (idLugar) {
      if (piso) $('#editar_piso').val(piso).trigger('change');
      $('#editar_slot').val(idUbicacion);
      $('#editar_posicion').val(posicion);
    }

    $('#ModalEditarLibro').modal('show');
  });

  $('#form-editar-libro').on('submit', function (e) {
    e.preventDefault();
    var payload = {
      id_libro:    $('#editar_id_libro').val(),
      isbn:        $('#editar_isbn').val(),
      libro:       $('#editar_libro').val(),
      materia:     $('#editar_materia').val(),
      grado:       $('#editar_grado').val(),
      precio:      $('#editar_precio').val() || 0,
      presupuesto: $('#editar_presupuesto').val(),
    };
    var payloadUbicacion = {
      id_libro:     payload.id_libro,
      id_ubicacion: $editarLugar.val() ? $('#editar_slot').val() : '',
      posicion:     $('#editar_posicion').val() || '',
    };

    // Carlos Puentes (SOLO_UBICACION) solo puede tocar la ubicación en bodega:
    // no se llama a modificar_libro.php para no intentar cambiar isbn/título/etc.
    var llamadas = [$.post('php/guardar_ubicacion_libro.php', payloadUbicacion, null, 'json')];
    if (!SOLO_UBICACION) {
      llamadas.unshift($.post('php/modificar_libro.php', payload, null, 'json'));
    }

    $.when.apply($, llamadas).done(function () {
      var respuestas = llamadas.length === 1
        ? [arguments[0]]
        : Array.prototype.slice.call(arguments).map(function (a) { return a[0]; });
      var ok = respuestas.every(function (r) { return r.success; });
      var msg = respuestas.map(function (r) { return r.message; }).filter(Boolean)[0];
      inkToast(msg, ok ? 'ok' : 'error');
      if (ok) {
        $('#ModalEditarLibro').modal('hide');
        table.ajax.reload(null, false);
      }
    });
  });

  $('#libros-table').on('click', '.btn-eliminar-libro', function () {
    var id = $(this).data('id');
    var titulo = $(this).data('titulo');
    inkConfirm({
      title: '¿Eliminar este libro?',
      text: 'Se eliminará "' + titulo + '" del catálogo. Esta acción no se puede deshacer.',
      btnOk: 'Eliminar'
    }, function () {
      $.post('php/eliminar_libro.php', { id_libro: id }, function (resp) {
        inkToast(resp.message, resp.success ? 'ok' : 'error');
        if (resp.success) table.ajax.reload(null, false);
      }, 'json');
    });
  });

  var currentLibroId = null;
  var currentMateriaId = null;

  $('#select-libro-padre').select2({
    dropdownParent: $('#ModalAsociarSerie'),
    placeholder: 'Buscar libro de esa materia (Primaria/Secundaria)...',
    minimumInputLength: 0,
    allowClear: true,
    ajax: {
      url: 'php/buscar_libros_padre.php',
      dataType: 'json',
      delay: 300,
      data: function (params) {
        return { q: params.term || '', excluir: currentLibroId, materia: currentMateriaId };
      },
      processResults: function (data) {
        return { results: data.results };
      }
    }
  });

  $('#libros-table').on('click', '.btn-asociar-serie', function () {
    var $btn = $(this);
    currentLibroId = $btn.data('id');
    currentMateriaId = $btn.data('materia');
    $('#asociar-libro-nombre').text($btn.data('libro'));

    $('#select-libro-padre').empty().val(null).trigger('change');
    var padreId = $btn.data('padre-id');
    if (padreId) {
      var opt = new Option($btn.data('padre-nombre'), padreId, true, true);
      $('#select-libro-padre').append(opt).trigger('change');
    }
    $('#ModalAsociarSerie').modal('show');
  });

  $('#form-asociar-serie').on('submit', function (e) {
    e.preventDefault();
    var idPadre = $('#select-libro-padre').val() || 0;
    $.post('php/asociar_libro_serie.php', { id_libro: currentLibroId, id_padre: idPadre }, function (resp) {
      inkToast(resp.message, resp.success ? 'ok' : 'error');
      if (resp.success) {
        $('#ModalAsociarSerie').modal('hide');
        table.ajax.reload(null, false);
      }
    }, 'json');
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
