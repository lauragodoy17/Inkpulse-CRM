<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1) {
    header("Location: index.php");
    exit;
}

$departamentos = $bdd->query("SELECT id, departamento FROM departamentos ORDER BY departamento")->fetchAll();

$promotores_disponibles = $bdd->query(
    "SELECT id, nombres, apellidos FROM usuarios WHERE tipo=3 AND act=1 AND (cod_zona IS NULL OR cod_zona = '') ORDER BY nombres, apellidos"
)->fetchAll();
$promotores_todos = $bdd->query("SELECT id, nombres, apellidos FROM usuarios WHERE tipo=3 AND act=1 ORDER BY nombres, apellidos")->fetchAll();
$distribuidores_usuarios = $bdd->query("SELECT id, nombres, apellidos FROM usuarios WHERE tipo=6 AND act=1 ORDER BY nombres, apellidos")->fetchAll();

$empresas_distribuidoras = $bdd->query(
    "SELECT id, codigo, zona FROM zonas WHERE zona NOT LIKE '%Eureka%' AND zona NOT LIKE '%ALEJANDRO%' ORDER BY zona"
)->fetchAll();

$zonas_eureka = $bdd->query(
    "SELECT z.id, z.codigo, z.zona, z.departamento AS departamento_id, d.departamento AS departamento_nombre,
            GROUP_CONCAT(DISTINCT CONCAT(u.nombres, ' ', u.apellidos) ORDER BY u.nombres SEPARATOR ', ') AS responsables,
            MIN(u.id) AS responsable_id
     FROM zonas z
     LEFT JOIN departamentos d ON d.id = z.departamento
     LEFT JOIN usuarios u ON u.cod_zona = z.codigo AND u.tipo = 3
     WHERE z.zona LIKE 'EUREKA/%'
     GROUP BY z.id
     ORDER BY z.zona"
)->fetchAll();

$empresas_con_responsables = $bdd->query(
    "SELECT z.id, z.codigo, z.zona, z.departamento AS departamento_id,
            GROUP_CONCAT(DISTINCT CONCAT(u.nombres, ' ', u.apellidos) ORDER BY u.nombres SEPARATOR ', ') AS responsables,
            MIN(u.id) AS responsable_id
     FROM zonas z
     LEFT JOIN usuarios u ON u.cod_zona = z.codigo AND u.tipo = 6
     WHERE z.zona NOT LIKE '%Eureka%' AND z.zona NOT LIKE '%ALEJANDRO%'
     GROUP BY z.id
     ORDER BY z.zona"
)->fetchAll();

$sub_zonas_todas = $bdd->query(
    "SELECT sz.id, sz.cod_zona, sz.sub_zona, sz.departamento AS departamento_id, d.departamento AS departamento_nombre
     FROM sub_zonas sz
     LEFT JOIN departamentos d ON d.id = sz.departamento
     ORDER BY sz.sub_zona"
)->fetchAll();

$sub_zonas_por_empresa = [];
foreach ($sub_zonas_todas as $sz) {
    $sub_zonas_por_empresa[$sz["cod_zona"]][] = $sz;
}

$total_zonas_eureka = count($zonas_eureka);
$total_empresas_dist = count($empresas_con_responsables);
$total_sub_zonas = count($sub_zonas_todas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Zonas</title>
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
    #zonas-table_wrapper { overflow-x: auto; }
    #zonas-table { min-width: 900px; }

    .badge-tipo-zona {
      display: inline-block; padding: 3px 10px; border-radius: 20px;
      font-size: .72rem; font-weight: 700; letter-spacing: .02em;
    }
    .badge-tipo-eureka { background: #ede9fe; color: #5b21b6; }
    .badge-tipo-distribuidor { background: #fff7ed; color: #9a3412; }
    .sin-responsable { color: #94a3b8; font-style: italic; font-size: .8rem; }
    .sin-zonas { color: #94a3b8; font-style: italic; font-size: .8rem; }

    /* Columna de acciones */
    .acciones-zona { display: inline-flex; align-items: center; gap: 6px; }
    .btn-editar-zona, .btn-eliminar-zona {
      width: 32px; height: 32px; padding: 0; border: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: .88rem; cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-editar-zona {
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
    }
    .btn-editar-zona:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-editar-zona:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(67,56,202,.3); }
    .btn-editar-zona:focus { outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,.25); }

    .btn-eliminar-zona {
      background: linear-gradient(135deg,#ef4444,#dc2626);
      box-shadow: 0 3px 8px rgba(220,38,38,.28);
    }
    .btn-eliminar-zona:hover {
      background: linear-gradient(135deg,#dc2626,#b91c1c);
      box-shadow: 0 5px 14px rgba(220,38,38,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-eliminar-zona:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(220,38,38,.3); }
    .btn-eliminar-zona:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }

    /* Modal: Crear zona / Editar zona */
    #ModalCrearZona .modal-content, #ModalEditarZona .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearZona .ml-header, #ModalEditarZona .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearZona .ml-icon-badge, #ModalEditarZona .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearZona .ml-icon-badge i, #ModalEditarZona .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearZona .ml-title, #ModalEditarZona .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearZona .ml-subtitle, #ModalEditarZona .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearZona .close, #ModalEditarZona .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearZona .ml-body, #ModalEditarZona .ml-body { padding: 22px 24px 4px; }
    #ModalCrearZona .ml-row, #ModalEditarZona .ml-row { display: flex; gap: 14px; }
    #ModalCrearZona .ml-row > .ml-field, #ModalEditarZona .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearZona .ml-field, #ModalEditarZona .ml-field { margin-bottom: 18px; }
    #ModalCrearZona .ml-label, #ModalEditarZona .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearZona .ml-label .req, #ModalEditarZona .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearZona .ml-input, #ModalCrearZona .ml-select,
    #ModalEditarZona .ml-input, #ModalEditarZona .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearZona .ml-input:focus, #ModalCrearZona .ml-select:focus,
    #ModalEditarZona .ml-input:focus, #ModalEditarZona .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearZona .ml-select, #ModalEditarZona .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearZona .ml-footer, #ModalEditarZona .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearZona .ml-btn-cancel, #ModalEditarZona .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearZona .ml-btn-submit, #ModalEditarZona .ml-btn-submit {
      padding: 9px 22px; border-radius: 8px; border: none; color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer;
      background: linear-gradient(135deg,#7c3aed,#4f46e5); box-shadow: 0 4px 12px rgba(79,70,229,.3);
    }
    #ModalCrearZona .ml-hint { font-size: .74rem; color: #94a3b8; margin-top: 6px; }
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
            <div class="title"><h4>Zonas</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Zonas</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-6 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#ModalCrearZona">
              <i class="bi bi-geo-alt mr-1"></i> Crear zona
            </button>
          </div>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-building"></i></div>
            <div class="stat-info-modern">
              <h3><?= $total_zonas_eureka ?></h3>
              <p class="stat-label">Zonas Eureka</p>
              <span class="stat-sub">Zonas propias con promotor</span>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sorange"><i class="bi bi-truck"></i></div>
            <div class="stat-info-modern">
              <h3><?= $total_empresas_dist ?></h3>
              <p class="stat-label">Empresas distribuidoras</p>
              <span class="stat-sub">Registradas en el sistema</span>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sgreen"><i class="bi bi-geo-alt"></i></div>
            <div class="stat-info-modern">
              <h3><?= $total_sub_zonas ?></h3>
              <p class="stat-label">Sub-zonas registradas</p>
              <span class="stat-sub">De todas las empresas distribuidoras</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="zonas-search" placeholder="Buscar por empresa, zona, departamento o responsable...">
        </div>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de zonas</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="zonas-table">
            <thead>
              <tr>
                <th>Empresa</th>
                <th>Zona</th>
                <th>Departamento</th>
                <th>Responsable</th>
                <th>Tipo</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($zonas_eureka as $z):
                $nombre_zona = trim(substr($z["zona"], strpos($z["zona"], "/") + 1));
              ?>
              <tr>
                <td>EUREKA</td>
                <td><?= htmlspecialchars($nombre_zona) ?></td>
                <td><?= htmlspecialchars($z["departamento_nombre"] ?? '') ?></td>
                <td><?= $z["responsables"] ? htmlspecialchars($z["responsables"]) : '<span class="sin-responsable">Sin promotor asignado</span>' ?></td>
                <td><span class="badge-tipo-zona badge-tipo-eureka">Eureka</span></td>
                <td class="text-center">
                  <div class="acciones-zona">
                    <button type="button" class="btn-editar-zona"
                      data-tipo="eureka"
                      data-id="<?= $z["id"] ?>"
                      data-codigo="<?= htmlspecialchars($z["codigo"]) ?>"
                      data-nombre="<?= htmlspecialchars($nombre_zona) ?>"
                      data-departamento="<?= (int) ($z["departamento_id"] ?? 0) ?>"
                      data-responsable="<?= (int) ($z["responsable_id"] ?? 0) ?>"
                      title="Editar zona">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <button type="button" class="btn-eliminar-zona"
                      data-tipo="eureka"
                      data-id="<?= $z["id"] ?>"
                      data-codigo="<?= htmlspecialchars($z["codigo"]) ?>"
                      data-nombre="<?= htmlspecialchars($nombre_zona) ?>"
                      title="Eliminar zona">
                      <i class="bi bi-trash3"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>

              <?php foreach ($empresas_con_responsables as $emp):
                $subzonas = $sub_zonas_por_empresa[$emp["codigo"]] ?? [];
                if (empty($subzonas)):
              ?>
                <tr>
                  <td><?= htmlspecialchars($emp["zona"]) ?></td>
                  <td><span class="sin-zonas">Sin zonas registradas</span></td>
                  <td>—</td>
                  <td><?= $emp["responsables"] ? htmlspecialchars($emp["responsables"]) : '<span class="sin-responsable">Sin responsable asignado</span>' ?></td>
                  <td><span class="badge-tipo-zona badge-tipo-distribuidor">Distribuidor</span></td>
                  <td class="text-center">
                    <div class="acciones-zona">
                      <button type="button" class="btn-editar-zona"
                        data-tipo="empresa"
                        data-id="<?= $emp["id"] ?>"
                        data-codigo="<?= htmlspecialchars($emp["codigo"]) ?>"
                        data-nombre="<?= htmlspecialchars($emp["zona"]) ?>"
                        data-departamento="<?= (int) ($emp["departamento_id"] ?? 0) ?>"
                        data-responsable="<?= (int) ($emp["responsable_id"] ?? 0) ?>"
                        title="Editar empresa">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button type="button" class="btn-eliminar-zona"
                        data-tipo="empresa"
                        data-id="<?= $emp["id"] ?>"
                        data-codigo="<?= htmlspecialchars($emp["codigo"]) ?>"
                        data-nombre="<?= htmlspecialchars($emp["zona"]) ?>"
                        title="Eliminar empresa">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php else:
                foreach ($subzonas as $sz):
              ?>
                <tr>
                  <td><?= htmlspecialchars($emp["zona"]) ?></td>
                  <td><?= htmlspecialchars($sz["sub_zona"]) ?></td>
                  <td><?= htmlspecialchars($sz["departamento_nombre"] ?? '') ?></td>
                  <td><?= $emp["responsables"] ? htmlspecialchars($emp["responsables"]) : '<span class="sin-responsable">Sin responsable asignado</span>' ?></td>
                  <td><span class="badge-tipo-zona badge-tipo-distribuidor">Distribuidor</span></td>
                  <td class="text-center">
                    <div class="acciones-zona">
                      <button type="button" class="btn-editar-zona"
                        data-tipo="subzona"
                        data-id="<?= $sz["id"] ?>"
                        data-codigo="<?= htmlspecialchars($emp["codigo"]) ?>"
                        data-nombre="<?= htmlspecialchars($sz["sub_zona"]) ?>"
                        data-departamento="<?= (int) ($sz["departamento_id"] ?? 0) ?>"
                        data-responsable="<?= (int) ($emp["responsable_id"] ?? 0) ?>"
                        data-empresa="<?= htmlspecialchars($emp["zona"]) ?>"
                        data-empresa-id="<?= $emp["id"] ?>"
                        title="Editar zona">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button type="button" class="btn-eliminar-zona"
                        data-tipo="subzona"
                        data-id="<?= $sz["id"] ?>"
                        data-codigo="<?= htmlspecialchars($emp["codigo"]) ?>"
                        data-nombre="<?= htmlspecialchars($sz["sub_zona"]) ?>"
                        title="Eliminar zona">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal: Crear zona -->
      <div class="modal fade" id="ModalCrearZona" tabindex="-1" role="dialog" aria-labelledby="ModalCrearZonaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_zona.php" id="form-crear-zona">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-geo-alt-fill"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearZonaLabel">Crear zona</h5>
                  <p class="ml-subtitle">Registra una zona de Eureka o de una empresa distribuidora</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-field">
                  <label class="ml-label" for="zona_empresa">Empresa<span class="req">*</span></label>
                  <select id="zona_empresa" class="ml-select" required>
                    <option value="">Seleccione</option>
                    <option value="eureka">EUREKA</option>
                    <?php foreach ($empresas_distribuidoras as $emp): ?>
                      <option value="<?= htmlspecialchars($emp["codigo"]) ?>"><?= htmlspecialchars($emp["zona"]) ?></option>
                    <?php endforeach; ?>
                    <option value="nuevo">+ Registrar nueva empresa</option>
                  </select>
                </div>

                <div class="ml-field d-none" id="campo-nombre-empresa">
                  <label class="ml-label" for="nombre_empresa">Nombre de la empresa<span class="req">*</span></label>
                  <input type="text" name="nombre_empresa" id="nombre_empresa" class="ml-input" placeholder="Ej: DISTRIBUCIONES DEL NORTE SAS">
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="nombre_zona" id="label-nombre-zona">Nombre de la zona<span class="req">*</span></label>
                    <input type="text" name="nombre_zona" id="nombre_zona" class="ml-input" placeholder="Ej: Suba" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="departamento">Departamento<span class="req">*</span></label>
                    <select name="departamento" id="departamento" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($departamentos as $dep): ?>
                        <option value="<?= $dep["id"] ?>"><?= htmlspecialchars($dep["departamento"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="responsable" id="label-responsable">Responsable<span class="req">*</span></label>
                  <select name="responsable" id="responsable" class="ml-select" required disabled>
                    <option value="">Primero seleccione una empresa</option>
                  </select>
                  <p class="ml-hint">Este usuario quedará asociado como responsable de la zona.</p>
                </div>

                <input type="hidden" name="tipo_empresa" id="tipo_empresa" value="">
                <input type="hidden" name="empresa_codigo" id="empresa_codigo" value="">

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear zona</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal: Editar zona -->
      <div class="modal fade" id="ModalEditarZona" tabindex="-1" role="dialog" aria-labelledby="ModalEditarZonaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/editar_zona.php" id="form-editar-zona">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-pencil-square"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalEditarZonaLabel">Editar zona</h5>
                  <p class="ml-subtitle" id="editar-zona-subtitle">Actualiza los datos de la zona</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-field d-none" id="campo-editar-nombre-empresa">
                  <label class="ml-label" for="editar_nombre_empresa">Nombre de la empresa<span class="req">*</span></label>
                  <input type="text" name="nombre_empresa" id="editar_nombre_empresa" class="ml-input">
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="editar_nombre_zona" id="editar-label-nombre-zona">Nombre de la zona<span class="req">*</span></label>
                    <input type="text" name="nombre_zona" id="editar_nombre_zona" class="ml-input" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="editar_departamento">Departamento<span class="req">*</span></label>
                    <select name="departamento" id="editar_departamento" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($departamentos as $dep): ?>
                        <option value="<?= $dep["id"] ?>"><?= htmlspecialchars($dep["departamento"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="editar_responsable" id="editar-label-responsable">Responsable<span class="req">*</span></label>
                  <select name="responsable" id="editar_responsable" class="ml-select" required>
                    <option value="">Seleccione</option>
                  </select>
                </div>

                <input type="hidden" name="tipo" id="editar_tipo" value="">
                <input type="hidden" name="id" id="editar_id" value="">
                <input type="hidden" name="codigo" id="editar_codigo" value="">
                <input type="hidden" name="empresa_id" id="editar_empresa_id" value="">

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Guardar cambios</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Formulario oculto: Eliminar zona -->
      <form method="POST" action="php/eliminar_zona.php" id="form-eliminar-zona" class="d-none">
        <input type="hidden" name="tipo" id="eliminar_tipo" value="">
        <input type="hidden" name="id" id="eliminar_id" value="">
        <input type="hidden" name="codigo" id="eliminar_codigo" value="">
      </form>

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
<script>
  var PROMOTORES_DISPONIBLES = <?= json_encode(array_map(function ($p) {
    return ["id" => $p["id"], "nombre" => trim($p["nombres"] . " " . $p["apellidos"])];
  }, $promotores_disponibles)) ?>;
  var PROMOTORES_TODOS = <?= json_encode(array_map(function ($p) {
    return ["id" => $p["id"], "nombre" => trim($p["nombres"] . " " . $p["apellidos"])];
  }, $promotores_todos)) ?>;
  var DISTRIBUIDORES = <?= json_encode(array_map(function ($d) {
    return ["id" => $d["id"], "nombre" => trim($d["nombres"] . " " . $d["apellidos"])];
  }, $distribuidores_usuarios)) ?>;

$(document).ready(function () {
  var table = $('#zonas-table').DataTable({
    responsive: false,
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    language: {
      lengthMenu:   "Mostrar _MENU_ registros",
      zeroRecords:  "No se encontraron resultados",
      emptyTable:   "No hay información para mostrar",
      info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty:    "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      search:       "Buscar:",
      paginate: { first: "«", previous: "‹", next: "›", last: "»" }
    }
  });

  $('#zonas-search').on('keyup', function () {
    table.search(this.value).draw();
  });

  function llenarResponsables(lista, placeholder) {
    var $sel = $('#responsable');
    $sel.prop('disabled', false).empty();
    $sel.append($('<option>').val('').text(placeholder));
    lista.forEach(function (u) {
      $sel.append($('<option>').val(u.id).text(u.nombre));
    });
  }

  $('#zona_empresa').on('change', function () {
    var val = $(this).val();

    $('#campo-nombre-empresa').addClass('d-none');
    $('#nombre_empresa').prop('required', false);

    if (val === '') {
      $('#tipo_empresa').val('');
      $('#empresa_codigo').val('');
      $('#responsable').prop('disabled', true).empty()
        .append($('<option>').val('').text('Primero seleccione una empresa'));
      return;
    }

    if (val === 'eureka') {
      $('#tipo_empresa').val('eureka');
      $('#empresa_codigo').val('');
      $('#label-nombre-zona').html('Nombre de la nueva zona<span class="req">*</span>');
      $('#label-responsable').html('Promotor<span class="req">*</span>');
      llenarResponsables(PROMOTORES_DISPONIBLES, 'Seleccione un promotor');
    } else if (val === 'nuevo') {
      $('#tipo_empresa').val('distribuidor_nuevo');
      $('#empresa_codigo').val('');
      $('#campo-nombre-empresa').removeClass('d-none');
      $('#nombre_empresa').prop('required', true);
      $('#label-nombre-zona').html('Nombre de la primera zona<span class="req">*</span>');
      $('#label-responsable').html('Responsable<span class="req">*</span>');
      llenarResponsables(DISTRIBUIDORES, 'Seleccione un responsable');
    } else {
      $('#tipo_empresa').val('distribuidor_existente');
      $('#empresa_codigo').val(val);
      $('#label-nombre-zona').html('Nombre de la nueva zona<span class="req">*</span>');
      $('#label-responsable').html('Responsable<span class="req">*</span>');
      llenarResponsables(DISTRIBUIDORES, 'Seleccione un responsable');
    }
  });

  $('#ModalCrearZona').on('hidden.bs.modal', function () {
    $('#form-crear-zona')[0].reset();
    $('#campo-nombre-empresa').addClass('d-none');
    $('#nombre_empresa').prop('required', false);
    $('#tipo_empresa').val('');
    $('#empresa_codigo').val('');
    $('#responsable').prop('disabled', true).empty()
      .append($('<option>').val('').text('Primero seleccione una empresa'));
  });

  function llenarResponsablesEditar(lista, selectedId) {
    var $sel = $('#editar_responsable');
    $sel.empty();
    $sel.append($('<option>').val('').text('Seleccione'));
    lista.forEach(function (u) {
      $sel.append($('<option>').val(u.id).text(u.nombre));
    });
    $sel.val(selectedId || '');
  }

  $(document).on('click', '.btn-editar-zona', function () {
    var $btn = $(this);
    var tipo = $btn.data('tipo');
    var responsableId = $btn.data('responsable') || '';

    $('#editar_tipo').val(tipo);
    $('#editar_id').val($btn.data('id'));
    $('#editar_codigo').val($btn.data('codigo'));
    $('#editar_nombre_zona').val($btn.data('nombre'));
    $('#editar_departamento').val($btn.data('departamento') || '');

    $('#campo-editar-nombre-empresa').addClass('d-none');
    $('#editar_nombre_empresa').val('').prop('required', false);
    $('#editar_empresa_id').val('');

    if (tipo === 'eureka') {
      $('#editar-zona-subtitle').text('Actualiza los datos de la zona Eureka');
      $('#editar-label-nombre-zona').html('Nombre de la zona<span class="req">*</span>');
      $('#editar-label-responsable').html('Promotor<span class="req">*</span>');
      llenarResponsablesEditar(PROMOTORES_TODOS, responsableId);
    } else if (tipo === 'empresa') {
      $('#editar-zona-subtitle').text('Actualiza los datos de la empresa distribuidora');
      $('#editar-label-nombre-zona').html('Nombre de la empresa<span class="req">*</span>');
      $('#editar-label-responsable').html('Responsable<span class="req">*</span>');
      llenarResponsablesEditar(DISTRIBUIDORES, responsableId);
    } else {
      $('#editar-zona-subtitle').text('Actualiza los datos de la zona del distribuidor');
      $('#editar-label-nombre-zona').html('Nombre de la zona<span class="req">*</span>');
      $('#editar-label-responsable').html('Responsable<span class="req">*</span>');
      llenarResponsablesEditar(DISTRIBUIDORES, responsableId);

      $('#campo-editar-nombre-empresa').removeClass('d-none');
      $('#editar_nombre_empresa').val($btn.data('empresa')).prop('required', true);
      $('#editar_empresa_id').val($btn.data('empresa-id'));
    }

    $('#ModalEditarZona').modal('show');
  });

  $('#ModalEditarZona').on('hidden.bs.modal', function () {
    $('#form-editar-zona')[0].reset();
    $('#campo-editar-nombre-empresa').addClass('d-none');
    $('#editar_nombre_empresa').prop('required', false);
  });

  $(document).on('click', '.btn-eliminar-zona', function () {
    var $btn = $(this);
    var tipo = $btn.data('tipo');
    var nombre = $btn.data('nombre');

    var textos = {
      eureka:  { title: '¿Eliminar esta zona Eureka?', text: 'Se eliminará la zona "' + nombre + '". Si tiene colegios asignados no podrá eliminarse.' },
      empresa: { title: '¿Eliminar esta empresa distribuidora?', text: 'Se eliminará "' + nombre + '" y ya no aparecerá en el listado. Si tiene colegios asignados no podrá eliminarse.' },
      subzona: { title: '¿Eliminar esta zona?', text: 'Se eliminará la zona "' + nombre + '". Si tiene colegios asignados no podrá eliminarse.' }
    };
    var t = textos[tipo] || textos.subzona;

    inkConfirm({
      title: t.title,
      text: t.text,
      btnOk: 'Eliminar'
    }, function () {
      $('#eliminar_tipo').val(tipo);
      $('#eliminar_id').val($btn.data('id'));
      $('#eliminar_codigo').val($btn.data('codigo'));
      $('#form-eliminar-zona')[0].submit();
    });
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
