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

    /* Sección "Buscar libros nuevos en World Office" */
    .ln-details { margin-bottom: 20px; }
    .ln-summary { cursor: pointer; list-style: none; }
    .ln-summary::-webkit-details-marker { display: none; }
    .ln-summary h5 { display: inline; }
    .ln-body { padding: 20px; }
    .ln-aviso { font-size: .82rem; color: #64748b; margin: 0 0 16px; }
    .ln-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 22px; border-radius: 8px; font-size: .875rem; font-weight: 700;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); color: #fff; border: none; cursor: pointer;
    }
    .ln-btn:disabled { opacity: .5; cursor: not-allowed; }
    .ln-btn-stop { background: #fff; color: #dc2626; border: 1.5px solid #fecaca; }
    .ln-progreso-wrap { margin-top: 16px; background: #f1f5f9; border-radius: 8px; height: 8px; overflow: hidden; }
    .ln-progreso { height: 100%; background: linear-gradient(135deg,#7c3aed,#4f46e5); width: 0%; transition: width .2s; }
    .ln-progreso-txt { font-size: .78rem; color: #64748b; margin-top: 6px; }
    .ln-estado { font-size: .82rem; color: #dc2626; margin-top: 8px; display: none; }
    .ln-estado.visible { display: block; }
    .ln-buscador { position: relative; max-width: 340px; margin-top: 16px; }
    .ln-buscador input {
      width: 100%; padding: 9px 12px 9px 34px; border: 1px solid #e2e8f0;
      border-radius: 8px; font-size: .85rem; box-sizing: border-box;
    }
    .ln-buscador i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .85rem; }
    .ln-tabla-wrap { max-height: 420px; overflow-y: auto; border: 1px solid #f1f5f9; border-radius: 10px; margin-top: 18px; }
    table.ln-tabla { width: 100%; border-collapse: collapse; }
    table.ln-tabla th, table.ln-tabla td { text-align: left; padding: 9px 12px; font-size: .82rem; border-bottom: 1px solid #f1f5f9; }
    table.ln-tabla th { background: #0f172a; color: #fff; font-weight: 600; position: sticky; top: 0; }
    table.ln-tabla tr:hover td { background: #f9fafb; }
    .ln-vacio { text-align: center; color: #9ca3af; padding: 20px; font-size: .85rem; }

    .ln-form-excel { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .ln-form-excel input[type=file] { font-size: .82rem; }
    .ln-excel-resumen { margin-top: 16px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; font-size: .85rem; color: #374151; }
    .ln-fila-error td { background: #fef2f2; }
    .ln-fila-aplicada td { opacity: .45; text-decoration: line-through; }
    .ln-fila-aplicada select, .ln-fila-aplicada input { pointer-events: none; }
    .ln-badge-estado { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: .74rem; font-weight: 700; white-space: nowrap; background: #f1f5f9; color: #64748b; }
    .ln-badge-estado.ok { background: #dcfce7; color: #166534; }
    .ln-badge-estado.error { background: #fee2e2; color: #991b1b; }
    .ln-badge-nuevo { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: .72rem; font-weight: 700; background: #ede9fe; color: #6d28d9; }

    /* Toolbar de la tabla "Buscar libros nuevos" (buscador + acción masiva) */
    .ln-toolbar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 16px; }
    .ln-toolbar .ln-buscador { margin-top: 0; }
    .ln-fila-agregada td { opacity: .4; }
    .ln-fila-agregada .ln-check-fila { cursor: not-allowed; }

    /* Campos editables inline en la tabla de "Actualizar precios y materia" */
    .ln-input-mini, .ln-select-mini {
      width: 100%; min-width: 90px; padding: 5px 8px; border: 1.5px solid #d1d5db; border-radius: 6px;
      font-size: .8rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    .ln-input-mini:focus, .ln-select-mini:focus { border-color: #7c3aed; background: #fff; }
    .ln-btn-quitar-crear {
      border: none; background: none; color: #94a3b8; font-size: 1rem; line-height: 1; cursor: pointer;
      padding: 0 2px; vertical-align: middle; margin-left: 4px;
    }
    .ln-btn-quitar-crear:hover { color: #dc2626; }
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

      <?php if ($puede_crear_eliminar): ?>
      <!-- Libros nuevos en World Office (no están todavía en el catálogo local) -->
      <details class="modern-card ln-details">
        <summary class="card-head ln-summary"><h5><i class="bi bi-search mr-2"></i> Buscar libros nuevos en World Office</h5></summary>
        <div class="ln-body">

          <button type="button" class="ln-btn" id="ln-btn-iniciar"><i class="bi bi-arrow-repeat"></i> Buscar libros nuevos</button>
          <button type="button" class="ln-btn ln-btn-stop" id="ln-btn-detener" style="display:none;"><i class="bi bi-stop-circle"></i> Detener</button>

          <div class="ln-progreso-wrap"><div class="ln-progreso" id="ln-progreso"></div></div>
          <p class="ln-progreso-txt" id="ln-progreso-txt">Sin iniciar.</p>
          <div class="ln-estado" id="ln-estado"></div>

          <div class="ln-toolbar">
            <div class="ln-buscador">
              <i class="bi bi-search"></i>
              <input type="text" id="ln-buscar" placeholder="Buscar por ISBN o título...">
            </div>
            <button type="button" class="ln-btn" id="ln-btn-agregar-seleccionados" disabled>
              <i class="bi bi-arrow-down-circle"></i> Agregar seleccionados (<span id="ln-count-seleccionados">0</span>)
            </button>
          </div>

          <div class="ln-tabla-wrap">
            <table class="ln-tabla">
              <thead>
                <tr>
                  <th style="width:32px;"><input type="checkbox" id="ln-check-all"></th>
                  <th>ISBN</th>
                  <th>Título (WO)</th>
                  <th>Editorial (WO)</th>
                </tr>
              </thead>
              <tbody id="ln-tbody"></tbody>
            </table>
          </div>
          <p class="ln-vacio" id="ln-vacio">Dale a "Buscar libros nuevos" para ver resultados aquí.</p>
        </div>
      </details>

      <!-- Actualizar precios y materia desde Excel -->
      <details class="modern-card ln-details" id="ln-details-excel">
        <summary class="card-head ln-summary"><h5><i class="bi bi-file-earmark-spreadsheet mr-2"></i> Actualizar precios y materia desde Excel</h5></summary>
        <div class="ln-body">
          <form id="ln-form-excel" class="ln-form-excel">
            <input type="file" id="ln-excel-archivo" name="archivo" accept=".xlsx,.xls,.csv" required>
            <button type="submit" class="ln-btn" id="ln-btn-previsualizar"><i class="bi bi-eye"></i> Previsualizar</button>
          </form>

          <div class="ln-estado" id="ln-excel-estado"></div>

          <div id="ln-excel-resumen" class="ln-excel-resumen" style="display:none;">
            <span id="ln-excel-resumen-txt"></span>
            <button type="button" class="ln-btn" id="ln-btn-aplicar" style="display:none;"><i class="bi bi-check2-circle"></i> Aplicar cambios</button>
          </div>

          <div class="ln-tabla-wrap" id="ln-excel-tabla-wrap" style="display:none;">
            <table class="ln-tabla">
              <thead>
                <tr>
                  <th>Fila</th>
                  <th>ISBN</th>
                  <th>Libro</th>
                  <th>Materia</th>
                  <th>Grado</th>
                  <th>Precio</th>
                  <th>Presupuesto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody id="ln-excel-tbody"></tbody>
            </table>
          </div>
        </div>
      </details>

      <!-- Actualizar precios CRM -->
      <details class="modern-card ln-details" id="ln-details-crm">
        <summary class="card-head ln-summary"><h5><i class="bi bi-currency-dollar mr-2"></i> Actualizar precios CRM</h5></summary>
        <div class="ln-body">

          <form id="ln-form-crm" class="ln-form-excel">
            <input type="file" id="ln-crm-archivo" name="archivo" accept=".xlsx,.xls,.csv" required>
            <button type="submit" class="ln-btn" id="ln-btn-crm-previsualizar"><i class="bi bi-eye"></i> Previsualizar</button>
          </form>

          <div class="ln-estado" id="ln-crm-estado"></div>

          <div id="ln-crm-resumen" class="ln-excel-resumen" style="display:none;">
            <span id="ln-crm-resumen-txt"></span>
            <button type="button" class="ln-btn" id="ln-btn-crm-aplicar" style="display:none;"><i class="bi bi-check2-circle"></i> Aplicar cambios (<span id="ln-crm-count-seleccionados">0</span>)</button>
          </div>

          <div class="ln-buscador" id="ln-crm-buscador-wrap" style="display:none;">
            <i class="bi bi-search"></i>
            <input type="text" id="ln-crm-buscar" placeholder="Buscar por ISBN o título...">
          </div>

          <div class="ln-tabla-wrap" id="ln-crm-tabla-wrap" style="display:none;">
            <table class="ln-tabla">
              <thead>
                <tr>
                  <th style="width:32px;"><input type="checkbox" id="ln-crm-check-all"></th>
                  <th>Fila</th>
                  <th>ISBN</th>
                  <th>Libro</th>
                  <th>Precio actual → nuevo</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody id="ln-crm-tbody"></tbody>
            </table>
          </div>
        </div>
      </details>
      <?php endif; ?>

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

                <input type="hidden" name="id_wo" id="crear_id_wo" value="">

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
  var MATERIAS = <?= json_encode($materias) ?>;
  var GRADOS = <?= json_encode($grados) ?>;

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

  var table = window.libroTable = $('#libros-table').DataTable({
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
<script>
(function () {
  var btnIniciar = document.getElementById('ln-btn-iniciar');
  if (!btnIniciar) return; // sección no renderizada (usuario sin permiso de crear/eliminar)

  var POR_PAGINA = 50;
  var corriendo = false;
  var pagina = 0;
  var totalPaginas = null;
  var idCorrida = 0; // se incrementa cada vez que se inicia una búsqueda, para poder
                      // descartar respuestas de una corrida anterior que llegan tarde
                      // (ej. si se detiene y se vuelve a iniciar antes de que responda)

  var btnDetener = document.getElementById('ln-btn-detener');
  var progreso = document.getElementById('ln-progreso');
  var progresoTxt = document.getElementById('ln-progreso-txt');
  var estado = document.getElementById('ln-estado');
  var tbody = document.getElementById('ln-tbody');
  var vacio = document.getElementById('ln-vacio');
  var buscar = document.getElementById('ln-buscar');
  buscar.addEventListener('input', aplicarBusquedaLn);

  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function agregarFilas(lista) {
    vacio.style.display = 'none';
    lista.forEach(function (n) {
      var tr = document.createElement('tr');
      tr.dataset.buscar = (n.isbn + ' ' + n.descripcion).toLowerCase();
      tr.innerHTML =
        '<td><input type="checkbox" class="ln-check-fila" data-isbn="' + h(n.isbn) + '" data-titulo="' + h(n.descripcion) + '" data-idwo="' + h(n.id) + '"></td>' +
        '<td>' + h(n.isbn) + '</td>' +
        '<td>' + h(n.descripcion) + '</td>' +
        '<td>' + h(n.editorial) + '</td>';
      tbody.appendChild(tr);
    });
    aplicarBusquedaLn();
  }

  function aplicarBusquedaLn() {
    var texto = buscar.value.trim().toLowerCase();
    Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
      tr.style.display = (!texto || tr.dataset.buscar.indexOf(texto) !== -1) ? '' : 'none';
    });
  }

  // ── Selección de filas para llevarlas a "Actualizar precios y materia" ──
  var checkAll = document.getElementById('ln-check-all');
  var btnAgregarSeleccionados = document.getElementById('ln-btn-agregar-seleccionados');
  var spanCountSeleccionados = document.getElementById('ln-count-seleccionados');

  function actualizarContadorSeleccionados() {
    var n = tbody.querySelectorAll('.ln-check-fila:checked').length;
    spanCountSeleccionados.textContent = n;
    btnAgregarSeleccionados.disabled = n === 0;
  }

  tbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('ln-check-fila')) actualizarContadorSeleccionados();
  });

  checkAll.addEventListener('change', function () {
    Array.prototype.forEach.call(tbody.querySelectorAll('.ln-check-fila:not(:disabled)'), function (chk) {
      var tr = chk.closest('tr');
      if (tr.style.display !== 'none') chk.checked = checkAll.checked;
    });
    actualizarContadorSeleccionados();
  });

  btnAgregarSeleccionados.addEventListener('click', function () {
    var checks = tbody.querySelectorAll('.ln-check-fila:checked');
    if (!checks.length) return;
    var agregados = 0;
    Array.prototype.forEach.call(checks, function (chk) {
      var tr = chk.closest('tr');
      window.agregarOActualizarCreacion({
        isbn: chk.dataset.isbn,
        libro: chk.dataset.titulo,
        idWo: chk.dataset.idwo,
      });
      tr.classList.add('ln-fila-agregada');
      chk.disabled = true;
      chk.checked = false;
      agregados++;
    });
    checkAll.checked = false;
    actualizarContadorSeleccionados();
    window.renderTablaExcel();

    var detallesExcel = document.getElementById('ln-details-excel');
    if (detallesExcel) detallesExcel.open = true;

    if (window.inkToast) inkToast(agregados + ' libro(s) agregado(s) a "Actualizar precios y materia".', 'ok');
  });

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'ln-estado visible';
  }

  function siguientePagina(miCorrida) {
    if (!corriendo || miCorrida !== idCorrida) return;
    fetch('php/comparar_libros.php?pagina=' + pagina + '&porPagina=' + POR_PAGINA)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (miCorrida !== idCorrida) return; // esta corrida ya no es la vigente: se ignora la respuesta

        if (!data.success) {
          mostrarError('Error consultando World Office: ' + (data.message || 'desconocido'));
          detener();
          return;
        }
        totalPaginas = data.totalPaginas;
        agregarFilas(data.nuevos);

        var pct = totalPaginas ? Math.min(100, Math.round(((pagina + 1) / totalPaginas) * 100)) : 0;
        progreso.style.width = pct + '%';
        progresoTxt.textContent = 'Página ' + (pagina + 1) + ' de ' + (totalPaginas ?? '?') + ' — ' + tbody.children.length + ' libro(s) nuevo(s) encontrados hasta ahora';

        pagina++;
        if (corriendo && miCorrida === idCorrida && totalPaginas != null && pagina < totalPaginas) {
          siguientePagina(miCorrida);
        } else {
          detener();
          progreso.style.width = '100%';
          if (tbody.children.length === 0) {
            vacio.style.display = '';
            vacio.textContent = 'No se encontraron libros nuevos: todo el inventario de World Office ya está en el catálogo.';
          }
          progresoTxt.textContent = 'Búsqueda completa — ' + tbody.children.length + ' libro(s) nuevo(s) encontrados.';
        }
      })
      .catch(function (err) {
        if (miCorrida !== idCorrida) return;
        mostrarError('Error de red: ' + err.message);
        detener();
      });
  }

  function iniciar() {
    idCorrida++;
    var miCorrida = idCorrida;
    corriendo = true;
    pagina = 0; totalPaginas = null;
    tbody.innerHTML = '';
    vacio.style.display = 'none';
    estado.className = 'ln-estado';
    buscar.value = '';
    btnIniciar.disabled = true;
    btnDetener.style.display = '';
    checkAll.checked = false;
    actualizarContadorSeleccionados();
    siguientePagina(miCorrida);
  }

  function detener() {
    corriendo = false;
    btnIniciar.disabled = false;
    btnDetener.style.display = 'none';
  }

  btnIniciar.addEventListener('click', function (e) {
    e.preventDefault();
    iniciar();
  });
  btnDetener.addEventListener('click', function (e) {
    e.preventDefault();
    detener();
  });

  // ── Actualizar precios y materia desde Excel (+ creación de libros nuevos) ──
  var formExcel = document.getElementById('ln-form-excel');
  var btnPrevisualizar = document.getElementById('ln-btn-previsualizar');
  var btnAplicar = document.getElementById('ln-btn-aplicar');
  var excelEstado = document.getElementById('ln-excel-estado');
  var excelResumen = document.getElementById('ln-excel-resumen');
  var excelResumenTxt = document.getElementById('ln-excel-resumen-txt');
  var excelTablaWrap = document.getElementById('ln-excel-tabla-wrap');
  var excelTbody = document.getElementById('ln-excel-tbody');

  // filasValidas: filas del último Excel previsualizado que actualizan un libro
  // que ya existe (tienen idLibro). filasErrorYActualizar: todas las filas del
  // último Excel que NO son "faltante en catálogo" (actualizaciones + errores
  // puros), se repintan enteras en cada previsualización. filasCrear: mapa por
  // ISBN de libros que todavía no existen — persiste entre previsualizaciones,
  // se alimenta tanto del Excel (filas con ISBN no encontrado) como de
  // "Agregar seleccionados" en la tabla de World Office de arriba.
  var filasValidas = [];
  var filasErrorYActualizar = [];
  var filasCrear = {};

  function fmtMoneda(v) {
    return v == null ? '—' : Number(v).toLocaleString('es-CO');
  }

  function fmtPresupuesto(v) {
    return v === 1 ? 'Sí' : (v === 0 ? 'No' : '—');
  }

  function mostrarErrorExcel(msg) {
    excelEstado.textContent = msg;
    excelEstado.className = 'ln-estado visible';
  }

  function opcionesMateria(seleccionadoId) {
    var out = '<option value="">Seleccione</option>';
    MATERIAS.forEach(function (m) {
      out += '<option value="' + m.id + '"' + (String(seleccionadoId) === String(m.id) ? ' selected' : '') + '>' + h(m.materia) + '</option>';
    });
    return out;
  }

  function opcionesGrado(seleccionadoId) {
    var out = '<option value="">Seleccione</option>';
    GRADOS.forEach(function (g) {
      out += '<option value="' + g.id + '"' + (String(seleccionadoId) === String(g.id) ? ' selected' : '') + '>' + h(g.grado) + '</option>';
    });
    return out;
  }

  function creacionCompleta(f) {
    return !!(f.libro && f.idMateria && f.idGrado && f.precio !== '' && f.presupuesto !== '');
  }

  // Agrega un libro nuevo (por ISBN) a filasCrear, o completa los campos
  // vacíos de uno que ya estaba (sin pisar lo que el usuario ya haya editado).
  window.agregarOActualizarCreacion = function (datos) {
    var isbn = datos.isbn;
    if (!isbn) return;
    var existente = filasCrear[isbn];
    if (existente) {
      if (!existente.libro && datos.libro) existente.libro = datos.libro;
      if (!existente.idMateria && datos.idMateria) existente.idMateria = datos.idMateria;
      if ((existente.precio === '' || existente.precio == null) && datos.precio != null && datos.precio !== '') existente.precio = datos.precio;
      if ((existente.presupuesto === '' || existente.presupuesto == null) && datos.presupuesto) existente.presupuesto = datos.presupuesto;
      if (!existente.idWo && datos.idWo) existente.idWo = datos.idWo;
    } else {
      filasCrear[isbn] = {
        isbn: isbn,
        libro: datos.libro || '',
        idMateria: datos.idMateria || '',
        idGrado: '',
        precio: (datos.precio != null && datos.precio !== '') ? datos.precio : '',
        presupuesto: datos.presupuesto || '',
        idWo: datos.idWo || '',
      };
    }
  };

  // f.materiaSeleccionada arranca en la materia actual del libro (si existe)
  // y queda editable, porque el Excel de World Office (Código/Descripción/
  // Estado/Precio 1) no trae materia.
  function renderFilaActualizarOError(f) {
    var tr = document.createElement('tr');
    var esActualizable = f.ok && f.idLibro;
    tr.className = f.ok ? '' : 'ln-fila-error';
    tr.dataset.fila = f.fila;
    var estadoHtml = f.ok
      ? '<span class="ln-badge-estado ok">OK</span>'
      : '<span class="ln-badge-estado error">' + h(f.errores.join('; ')) + '</span>';

    var materiaHtml = esActualizable
      ? '<select class="ln-actualizar-input ln-select-mini" data-campo="materiaSeleccionada">' + opcionesMateria(f.materiaSeleccionada) + '</select>'
      : h(f.materiaActualNombre || '—');

    tr.innerHTML =
      '<td>' + h(f.fila) + '</td>' +
      '<td>' + h(f.isbn) + '</td>' +
      '<td>' + h(f.libroActual || f.libroNuevo || '—') + '</td>' +
      '<td>' + materiaHtml + '</td>' +
      '<td>—</td>' +
      '<td>' + fmtMoneda(f.precioActual) + ' → ' + fmtMoneda(f.precioNuevo) + '</td>' +
      '<td>' + fmtPresupuesto(f.presupuestoActual) + ' → ' + fmtPresupuesto(f.presupuestoNuevo) + '</td>' +
      '<td>' + estadoHtml + '</td>';
    excelTbody.appendChild(tr);
  }

  function renderFilaCrear(f) {
    var tr = document.createElement('tr');
    tr.className = 'ln-fila-crear';
    tr.dataset.isbn = f.isbn;
    var completa = creacionCompleta(f);
    tr.innerHTML =
      '<td><span class="ln-badge-nuevo">Nuevo</span></td>' +
      '<td>' + h(f.isbn) + '</td>' +
      '<td><input type="text" class="ln-crear-input ln-input-mini" data-campo="libro" value="' + h(f.libro) + '" placeholder="Título"></td>' +
      '<td><select class="ln-crear-input ln-select-mini" data-campo="idMateria">' + opcionesMateria(f.idMateria) + '</select></td>' +
      '<td><select class="ln-crear-input ln-select-mini" data-campo="idGrado">' + opcionesGrado(f.idGrado) + '</select></td>' +
      '<td><input type="number" step="any" class="ln-crear-input ln-input-mini" data-campo="precio" value="' + h(f.precio) + '" placeholder="Precio"></td>' +
      '<td><select class="ln-crear-input ln-select-mini" data-campo="presupuesto">' +
        '<option value=""' + (f.presupuesto === '' ? ' selected' : '') + '>Seleccione</option>' +
        '<option value="1"' + (f.presupuesto === '1' ? ' selected' : '') + '>Sí</option>' +
        '<option value="0"' + (f.presupuesto === '0' ? ' selected' : '') + '>No</option>' +
      '</select></td>' +
      '<td>' +
        '<span class="ln-badge-estado ' + (completa ? 'ok' : 'error') + '">' + (completa ? 'Listo para crear' : 'Falta completar') + '</span>' +
        '<button type="button" class="ln-btn-quitar-crear" title="Quitar">&times;</button>' +
      '</td>';
    excelTbody.appendChild(tr);
  }

  window.renderTablaExcel = function () {
    excelTbody.innerHTML = '';
    filasErrorYActualizar.forEach(renderFilaActualizarOError);
    Object.keys(filasCrear).forEach(function (isbn) { renderFilaCrear(filasCrear[isbn]); });
    actualizarResumenYBoton();
  };

  function actualizacionesListas() {
    return filasValidas.filter(function (f) { return f.materiaSeleccionada; }).length;
  }

  function actualizarResumenYBoton() {
    var nCreaciones = Object.keys(filasCrear).length;
    var nCreacionesListas = Object.keys(filasCrear).filter(function (isbn) { return creacionCompleta(filasCrear[isbn]); }).length;
    var nActualizacionesListas = actualizacionesListas();

    var partes = [];
    if (filasErrorYActualizar.length) partes.push(nActualizacionesListas + ' actualización(es) lista(s)');
    if (nCreaciones) partes.push(nCreacionesListas + ' de ' + nCreaciones + ' libro(s) nuevo(s) listos para crear');

    excelResumenTxt.textContent = partes.join(' — ');
    excelResumen.style.display = partes.length ? 'flex' : 'none';
    btnAplicar.style.display = (nActualizacionesListas || nCreacionesListas) ? '' : 'none';
    excelTablaWrap.style.display = (filasErrorYActualizar.length || nCreaciones) ? '' : 'none';
  }

  formExcel.addEventListener('submit', function (e) {
    e.preventDefault();
    var archivo = document.getElementById('ln-excel-archivo').files[0];
    if (!archivo) return;

    excelEstado.className = 'ln-estado';
    excelEstado.textContent = '';
    var textoOriginalBtn = btnPrevisualizar.innerHTML;
    btnPrevisualizar.disabled = true;
    btnPrevisualizar.innerHTML = '<i class="bi bi-arrow-repeat"></i> Procesando...';

    var datos = new FormData();
    datos.append('archivo', archivo);
    // Si ya hay libros en cola en esta tabla (normalmente los que se trajeron
    // con "Agregar seleccionados" arriba), el Excel se filtra a esos ISBN
    // nada más — no se procesa el archivo completo.
    datos.append('isbns', JSON.stringify(Object.keys(filasCrear)));

    fetch('php/previsualizar_excel_libros.php', { method: 'POST', body: datos })
      .then(function (r) {
        return r.text().then(function (texto) {
          try {
            return JSON.parse(texto);
          } catch (e2) {
            // El servidor no devolvió JSON (típicamente un error fatal de PHP
            // que imprimió HTML): se muestra el arranque de esa respuesta para
            // poder diagnosticarlo, en vez de quedar en un "Error de red" ciego.
            throw new Error('Respuesta inesperada del servidor: ' + texto.slice(0, 300));
          }
        });
      })
      .then(function (data) {
        btnPrevisualizar.disabled = false;
        btnPrevisualizar.innerHTML = textoOriginalBtn;
        if (!data.success) {
          mostrarErrorExcel(data.message || 'No se pudo procesar el archivo');
          return;
        }

        filasErrorYActualizar = data.filas.filter(function (f) { return !f.faltanteEnCatalogo; }).map(function (f) {
          f.materiaSeleccionada = f.materiaActualId || '';
          return f;
        });
        filasValidas = filasErrorYActualizar.filter(function (f) { return f.ok; });

        data.filas.filter(function (f) { return f.faltanteEnCatalogo; }).forEach(function (f) {
          window.agregarOActualizarCreacion({
            isbn: f.isbn,
            libro: f.libroNuevo || '',
            precio: f.precioNuevo,
            presupuesto: (f.presupuestoNuevo === 0 || f.presupuestoNuevo === 1) ? String(f.presupuestoNuevo) : '',
          });
        });

        if (data.filtroActivo && data.isbnsNoEncontrados && data.isbnsNoEncontrados.length) {
          mostrarErrorExcel(data.isbnsNoEncontrados.length + ' de los libros en cola no se encontraron en este archivo (por ISBN): ' + data.isbnsNoEncontrados.join(', '));
        }

        window.renderTablaExcel();
      })
      .catch(function (err) {
        btnPrevisualizar.disabled = false;
        btnPrevisualizar.innerHTML = textoOriginalBtn;
        mostrarErrorExcel('Error: ' + err.message);
      });
  });

  excelTbody.addEventListener('input', function (e) {
    if (e.target.classList.contains('ln-crear-input')) actualizarCampoCreacion(e.target);
  });
  excelTbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('ln-crear-input')) actualizarCampoCreacion(e.target);
    if (e.target.classList.contains('ln-actualizar-input')) actualizarCampoActualizar(e.target);
  });

  function actualizarCampoCreacion(input) {
    var tr = input.closest('tr');
    var f = filasCrear[tr.dataset.isbn];
    if (!f) return;
    f[input.dataset.campo] = input.value;

    var completa = creacionCompleta(f);
    var badge = tr.querySelector('.ln-badge-estado');
    badge.className = 'ln-badge-estado ' + (completa ? 'ok' : 'error');
    badge.textContent = completa ? 'Listo para crear' : 'Falta completar';
    actualizarResumenYBoton();
  }

  function actualizarCampoActualizar(select) {
    var tr = select.closest('tr');
    var fila = tr.dataset.fila;
    var f = filasErrorYActualizar.find(function (x) { return String(x.fila) === String(fila); });
    if (!f) return;
    f[select.dataset.campo] = select.value;
    actualizarResumenYBoton();
  }

  excelTbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.ln-btn-quitar-crear');
    if (!btn) return;
    var tr = btn.closest('tr');
    delete filasCrear[tr.dataset.isbn];
    tr.remove();
    actualizarResumenYBoton();
  });

  btnAplicar.addEventListener('click', function () {
    var filas = filasValidas
      .filter(function (f) { return f.materiaSeleccionada; })
      .map(function (f) {
        return {
          fila: f.fila, idLibro: f.idLibro, isbn: f.isbn,
          materiaNuevaId: f.materiaSeleccionada, precioNuevo: f.precioNuevo, presupuestoNuevo: f.presupuestoNuevo,
        };
      });

    var nuevas = Object.keys(filasCrear)
      .map(function (isbn) { return filasCrear[isbn]; })
      .filter(creacionCompleta)
      .map(function (f) {
        return {
          isbn: f.isbn, libro: f.libro, id_materia: f.idMateria, id_grado: f.idGrado,
          precio: f.precio, presupuesto: f.presupuesto, id_wo: f.idWo,
        };
      });

    if (!filas.length && !nuevas.length) return;
    btnAplicar.disabled = true;

    fetch('php/aplicar_excel_libros.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filas: filas, nuevas: nuevas })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btnAplicar.disabled = false;
        if (!data.success) {
          mostrarErrorExcel('Error al aplicar: ' + (data.message || 'desconocido'));
          return;
        }

        data.actualizados.forEach(function (item) {
          var tr = excelTbody.querySelector('tr[data-fila="' + item.fila + '"]');
          if (tr) tr.classList.add('ln-fila-aplicada');
        });
        var filasActualizadasIds = data.actualizados.map(function (item) { return String(item.fila); });
        filasValidas = filasValidas.filter(function (f) { return filasActualizadasIds.indexOf(String(f.fila)) === -1; });

        data.creados.forEach(function (item) {
          var tr = excelTbody.querySelector('tr[data-isbn="' + item.isbn + '"]');
          if (tr) tr.classList.add('ln-fila-aplicada');
          delete filasCrear[item.isbn];
        });

        var msg = data.actualizados.length + ' libro(s) actualizado(s), ' + data.creados.length + ' libro(s) creado(s).';
        if (data.omitidos.length) msg += ' ' + data.omitidos.length + ' no se pudieron aplicar (revisá la vista previa).';
        excelResumenTxt.textContent = msg;

        var nCreacionesListas = Object.keys(filasCrear).filter(function (isbn) { return creacionCompleta(filasCrear[isbn]); }).length;
        btnAplicar.style.display = (actualizacionesListas() || nCreacionesListas) ? '' : 'none';

        if (window.libroTable) window.libroTable.ajax.reload(null, false);
      })
      .catch(function (err) {
        btnAplicar.disabled = false;
        mostrarErrorExcel('Error de red: ' + err.message);
      });
  });
})();
</script>
<script>
(function () {
  var formCrm = document.getElementById('ln-form-crm');
  if (!formCrm) return; // sección no renderizada (usuario sin permiso de crear/eliminar)

  var btnPrevisualizar = document.getElementById('ln-btn-crm-previsualizar');
  var btnAplicar = document.getElementById('ln-btn-crm-aplicar');
  var estado = document.getElementById('ln-crm-estado');
  var resumen = document.getElementById('ln-crm-resumen');
  var resumenTxt = document.getElementById('ln-crm-resumen-txt');
  var tablaWrap = document.getElementById('ln-crm-tabla-wrap');
  var tbody = document.getElementById('ln-crm-tbody');
  var periodoNombreEl = document.getElementById('ln-crm-periodo-nombre');
  var checkAll = document.getElementById('ln-crm-check-all');
  var buscadorWrap = document.getElementById('ln-crm-buscador-wrap');
  var buscar = document.getElementById('ln-crm-buscar');

  var filasValidas = [];

  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtMoneda(v) {
    return v == null ? '—' : Number(v).toLocaleString('es-CO');
  }

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'ln-estado visible';
  }

  function pintarPreview(filas) {
    tbody.innerHTML = '';
    filas.forEach(function (f) {
      var tr = document.createElement('tr');
      tr.className = f.ok ? '' : 'ln-fila-error';
      tr.dataset.fila = f.fila;
      tr.dataset.buscar = (String(f.isbn) + ' ' + String(f.libro || '')).toLowerCase();

      var estadoHtml;
      if (!f.ok) {
        estadoHtml = '<span class="ln-badge-estado error">' + h(f.errores.join('; ')) + '</span>';
      } else if (f.sinCambio) {
        estadoHtml = '<span class="ln-badge-estado">Sin cambio</span>';
      } else {
        estadoHtml = '<span class="ln-badge-estado ok">OK</span>';
      }

      // Solo las filas válidas (ok) se pueden aplicar, así que solo esas
      // llevan checkbox — las de error quedan sin selector, de solo lectura.
      var checkHtml = f.ok
        ? '<input type="checkbox" class="ln-crm-check-fila" data-fila="' + h(f.fila) + '" checked>'
        : '';

      tr.innerHTML =
        '<td>' + checkHtml + '</td>' +
        '<td>' + h(f.fila) + '</td>' +
        '<td>' + h(f.isbn) + '</td>' +
        '<td>' + h(f.libro || '—') + '</td>' +
        '<td>' + fmtMoneda(f.precioActual) + ' → ' + fmtMoneda(f.precioNuevo) + '</td>' +
        '<td>' + estadoHtml + '</td>';
      tbody.appendChild(tr);
    });
    tablaWrap.style.display = '';
    buscadorWrap.style.display = '';
    buscar.value = '';
    checkAll.checked = true;
    actualizarResumenSeleccion();
  }

  function filasSeleccionadas() {
    var idsMarcados = {};
    Array.prototype.forEach.call(tbody.querySelectorAll('.ln-crm-check-fila:checked'), function (chk) {
      idsMarcados[chk.dataset.fila] = true;
    });
    return filasValidas.filter(function (f) { return idsMarcados[String(f.fila)]; });
  }

  function actualizarResumenSeleccion() {
    var n = filasSeleccionadas().length;
    btnAplicar.style.display = n ? '' : 'none';
    var span = document.getElementById('ln-crm-count-seleccionados');
    if (span) span.textContent = n;
  }

  function aplicarBusquedaCrm() {
    var texto = buscar.value.trim().toLowerCase();
    Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
      tr.style.display = (!texto || (tr.dataset.buscar || '').indexOf(texto) !== -1) ? '' : 'none';
    });
  }

  buscar.addEventListener('input', aplicarBusquedaCrm);

  tbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('ln-crm-check-fila')) actualizarResumenSeleccion();
  });

  checkAll.addEventListener('change', function () {
    Array.prototype.forEach.call(tbody.querySelectorAll('.ln-crm-check-fila'), function (chk) {
      var tr = chk.closest('tr');
      if (tr.style.display !== 'none') chk.checked = checkAll.checked;
    });
    actualizarResumenSeleccion();
  });

  formCrm.addEventListener('submit', function (e) {
    e.preventDefault();
    var archivo = document.getElementById('ln-crm-archivo').files[0];
    if (!archivo) return;

    estado.className = 'ln-estado';
    estado.textContent = '';
    resumen.style.display = 'none';
    btnAplicar.style.display = 'none';
    var textoOriginalBtn = btnPrevisualizar.innerHTML;
    btnPrevisualizar.disabled = true;
    btnPrevisualizar.innerHTML = '<i class="bi bi-arrow-repeat"></i> Procesando...';
    filasValidas = [];

    var datos = new FormData();
    datos.append('archivo', archivo);

    fetch('php/previsualizar_excel_precios_crm.php', { method: 'POST', body: datos })
      .then(function (r) {
        return r.text().then(function (texto) {
          try {
            return JSON.parse(texto);
          } catch (e2) {
            throw new Error('Respuesta inesperada del servidor: ' + texto.slice(0, 300));
          }
        });
      })
      .then(function (data) {
        btnPrevisualizar.disabled = false;
        btnPrevisualizar.innerHTML = textoOriginalBtn;
        if (!data.success) {
          mostrarError(data.message || 'No se pudo procesar el archivo');
          return;
        }

        if (data.ultimoPeriodo && periodoNombreEl) {
          periodoNombreEl.textContent = 'período ' + data.ultimoPeriodo.periodo;
        }

        filasValidas = data.filas.filter(function (f) { return f.ok; });
        pintarPreview(data.filas); // ya con filasValidas listo, porque calcula el contador de seleccionados

        resumenTxt.textContent = data.totalFilas + ' fila(s) leídas — ' + data.totalValidas + ' válida(s), ' + data.totalErrores + ' con error.';
        resumen.style.display = 'flex';
      })
      .catch(function (err) {
        btnPrevisualizar.disabled = false;
        btnPrevisualizar.innerHTML = textoOriginalBtn;
        mostrarError('Error: ' + err.message);
      });
  });

  btnAplicar.addEventListener('click', function () {
    var seleccionadas = filasSeleccionadas();
    if (!seleccionadas.length) return;
    btnAplicar.disabled = true;

    fetch('php/aplicar_excel_precios_crm.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filas: seleccionadas })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btnAplicar.disabled = false;
        if (!data.success) {
          mostrarError('Error al aplicar: ' + (data.message || 'desconocido'));
          return;
        }

        var totalFilasPresupuesto = 0;
        var idsAplicados = {};
        data.actualizados.forEach(function (item) {
          var tr = tbody.querySelector('tr[data-fila="' + item.fila + '"]');
          if (tr) tr.classList.add('ln-fila-aplicada');
          idsAplicados[String(item.fila)] = true;
          totalFilasPresupuesto += item.filasPresupuestoActualizadas || 0;
        });

        var msg = data.actualizados.length + ' libro(s) actualizado(s) en el catálogo, ' + totalFilasPresupuesto + ' fila(s) de presupuesto actualizadas en el período ' + data.idUltimoPeriodo + '.';
        if (data.omitidos.length) msg += ' ' + data.omitidos.length + ' no se pudieron aplicar (revisá la vista previa).';
        resumenTxt.textContent = msg;

        // Las filas que sí se aplicaron salen de filasValidas y quedan
        // destildadas/deshabilitadas para no volver a mandarlas por error.
        filasValidas = filasValidas.filter(function (f) { return !idsAplicados[String(f.fila)]; });
        Array.prototype.forEach.call(tbody.querySelectorAll('.ln-crm-check-fila'), function (chk) {
          if (idsAplicados[chk.dataset.fila]) { chk.checked = false; chk.disabled = true; }
        });
        actualizarResumenSeleccion();

        if (window.libroTable) window.libroTable.ajax.reload(null, false);
      })
      .catch(function (err) {
        btnAplicar.disabled = false;
        mostrarError('Error de red: ' + err.message);
      });
  });
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
