<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && ($_SESSION["tipo"] ?? null) != 1) {
    header("Location: index.php");
    exit;
}

if (isset($_GET["tipo"]) && $_GET["tipo"] !== '') {
    $sql = "SELECT u.*, t.tipo as tipouser, p.pais FROM usuarios u JOIN tipos_usuario t ON u.tipo = t.id JOIN paises p ON u.id_pais = p.id WHERE u.tipo = :tipo ORDER BY u.act DESC, u.nombres";
    $req = $bdd->prepare($sql);
    $req->execute([':tipo' => $_GET["tipo"]]);
} else {
    $sql = "SELECT u.*, t.tipo as tipouser, p.pais FROM usuarios u JOIN tipos_usuario t ON u.tipo = t.id JOIN paises p ON u.id_pais = p.id ORDER BY u.act DESC, u.nombres";
    $req = $bdd->prepare($sql);
    $req->execute();
}
$usuarios = $req->fetchAll();

$total_usuarios = count($usuarios);
$total_promotores = $bdd->query("SELECT COUNT(*) FROM usuarios WHERE tipo=3")->fetchColumn();

$tipos_todos = $bdd->query("SELECT id, tipo FROM tipos_usuario ORDER BY id")->fetchAll();

$sql_paises = "SELECT id, pais FROM paises ORDER BY id";
$req_paises = $bdd->prepare($sql_paises);
$req_paises->execute();
$paises = $req_paises->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Usuarios</title>
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
    #usuarios-table input[type="text"],
    #usuarios-table input[type="email"],
    #usuarios-table input[type="password"],
    #usuarios-table select {
      width: 100%;
      min-width: 90px;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      padding: 4px 8px;
      font-size: .8rem;
    }
    #usuarios-table_wrapper { overflow-x: auto; }
    #usuarios-table { min-width: 1200px; }

    /* Botón de guardar (acciones) */
    .btn-save-usuario {
      width: 34px; height: 34px; padding: 0; border: none; border-radius: 10px;
      display: inline-flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg,#4f46e5,#4338ca);
      color: #fff; font-size: .95rem; cursor: pointer;
      box-shadow: 0 3px 8px rgba(67,56,202,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-save-usuario:hover {
      background: linear-gradient(135deg,#4338ca,#3730a3);
      box-shadow: 0 5px 14px rgba(67,56,202,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-save-usuario:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(67,56,202,.3); }
    .btn-save-usuario:focus { outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,.25); }

    /* Botón de eliminar/desactivar (acciones) */
    .acciones-usuario { display: flex; align-items: center; gap: 6px; }
    .btn-delete-usuario {
      width: 34px; height: 34px; padding: 0; border: none; border-radius: 10px;
      display: inline-flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg,#ef4444,#dc2626);
      color: #fff; font-size: .95rem; cursor: pointer;
      box-shadow: 0 3px 8px rgba(220,38,38,.28);
      transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .btn-delete-usuario:hover {
      background: linear-gradient(135deg,#dc2626,#b91c1c);
      box-shadow: 0 5px 14px rgba(220,38,38,.4);
      transform: translateY(-1px);
      color: #fff;
    }
    .btn-delete-usuario:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(220,38,38,.3); }
    .btn-delete-usuario:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }
    .btn-delete-usuario[disabled] { opacity: .4; cursor: not-allowed; box-shadow: none; }

    /* Modal: Crear usuario */
    #ModalCrearUsuario .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(15,23,42,.18); }
    #ModalCrearUsuario .ml-header {
      padding: 22px 24px 18px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 14px;
    }
    #ModalCrearUsuario .ml-icon-badge {
      width: 44px; height: 44px; border-radius: 11px; flex-shrink: 0;
      background: linear-gradient(135deg,#7c3aed,#4f46e5);
      display: flex; align-items: center; justify-content: center;
    }
    #ModalCrearUsuario .ml-icon-badge i { color: #fff; font-size: 1.2rem; }
    #ModalCrearUsuario .ml-title { margin: 0; font-size: .98rem; font-weight: 700; color: #0f172a; }
    #ModalCrearUsuario .ml-subtitle { margin: 2px 0 0; font-size: .76rem; color: #64748b; }
    #ModalCrearUsuario .close {
      font-size: 1.3rem; color: #94a3b8; background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
    }
    #ModalCrearUsuario .ml-body { padding: 22px 24px 4px; }
    #ModalCrearUsuario .ml-row { display: flex; gap: 14px; }
    #ModalCrearUsuario .ml-row > .ml-field { flex: 1 1 0; min-width: 0; }
    #ModalCrearUsuario .ml-field { margin-bottom: 18px; }
    #ModalCrearUsuario .ml-label {
      font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .05em; display: block; margin-bottom: 8px;
    }
    #ModalCrearUsuario .ml-label .req { color: #ef4444; margin-left: 2px; }
    #ModalCrearUsuario .ml-input, #ModalCrearUsuario .ml-select {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none; font-family: inherit;
    }
    #ModalCrearUsuario .ml-input:focus, #ModalCrearUsuario .ml-select:focus { border-color: #7c3aed; background: #fff; }
    #ModalCrearUsuario .ml-select {
      appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px;
      background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%2364748b\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E');
      background-repeat: no-repeat; background-position: right 14px center;
    }
    #ModalCrearUsuario .ml-footer {
      padding: 18px 24px 22px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0;
    }
    #ModalCrearUsuario .ml-btn-cancel {
      padding: 9px 20px; border-radius: 8px; border: 1.5px solid #d1d5db; background: #fff;
      color: #64748b; font-size: .875rem; font-weight: 600; cursor: pointer;
    }
    #ModalCrearUsuario .ml-btn-submit {
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
            <div class="title"><h4>Usuarios</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                <li class="breadcrumb-item active" aria-current="page">Usuarios</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-6 col-sm-12 text-md-right">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#ModalCrearUsuario">
              <i class="bi bi-person-plus mr-1"></i> Crear usuario
            </button>
          </div>
        </div>
      </div>

      <!-- Tarjetas de estadística -->
      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-people"></i></div>
            <div class="stat-info-modern">
              <h3><?= $total_usuarios ?></h3>
              <p class="stat-label">Total usuarios</p>
              <span class="stat-sub">Registrados en el sistema</span>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sgreen"><i class="bi bi-person-badge"></i></div>
            <div class="stat-info-modern">
              <h3><?= $total_promotores ?></h3>
              <p class="stat-label">Promotores</p>
              <span class="stat-sub"><a href="usuarios.php?tipo=3">Ver solo promotores</a></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="usuarios-search" placeholder="Buscar por nombre, apellido, correo o tipo...">
        </div>
        <?php if (isset($_GET["tipo"]) && $_GET["tipo"] !== ''): ?>
          <a href="usuarios.php" class="ft-btn ft-clear"><i class="bi bi-x-circle"></i> Quitar filtro</a>
        <?php endif; ?>
      </div>

      <!-- Tabla -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista de usuarios</h5>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="usuarios-table">
            <thead>
              <tr>
                <th>Nombres</th>
                <th>Apellidos</th>
                <th>Teléfono</th>
                <th>Email</th>
                <th>Clave</th>
                <th>Tipo</th>
                <th>País</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($usuarios as $usuario):
                $form_id = "form-usuario-" . $usuario["id"];
              ?>
              <tr>
                <td><input type="text" name="nombres" value="<?= htmlspecialchars($usuario["nombres"]) ?>" form="<?= $form_id ?>" required></td>
                <td><input type="text" name="apellidos" value="<?= htmlspecialchars($usuario["apellidos"]) ?>" form="<?= $form_id ?>" required></td>
                <td><input type="text" name="telefono" value="<?= htmlspecialchars($usuario["telefono"]) ?>" form="<?= $form_id ?>"></td>
                <td><input type="email" name="correo" value="<?= htmlspecialchars($usuario["correo"]) ?>" form="<?= $form_id ?>" required></td>
                <td><input type="password" name="clave" placeholder="Sin cambios" minlength="6" form="<?= $form_id ?>" autocomplete="new-password"></td>
                <td>
                  <select name="tipo" form="<?= $form_id ?>" required>
                    <?php foreach ($tipos_todos as $tipo): ?>
                      <option value="<?= $tipo["id"] ?>" <?= $tipo["id"] == $usuario["tipo"] ? 'selected' : '' ?>><?= htmlspecialchars($tipo["tipo"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select name="pais" form="<?= $form_id ?>" required>
                    <?php foreach ($paises as $pais): ?>
                      <option value="<?= $pais["id"] ?>" <?= $pais["id"] == $usuario["id_pais"] ? 'selected' : '' ?>><?= htmlspecialchars($pais["pais"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select name="act" form="<?= $form_id ?>" required>
                    <option value="1" <?= $usuario["act"] == 1 ? 'selected' : '' ?>>Activo</option>
                    <option value="0" <?= $usuario["act"] == 0 ? 'selected' : '' ?>>Inactivo</option>
                  </select>
                </td>
                <td>
                  <input type="hidden" name="id_usuario" value="<?= $usuario["id"] ?>" form="<?= $form_id ?>">
                  <div class="acciones-usuario">
                    <button type="submit" class="btn-save-usuario" form="<?= $form_id ?>" title="Guardar cambios">
                      <i class="bi bi-check-lg"></i>
                    </button>
                    <?php $es_uno_mismo = ($usuario["id"] == $_SESSION["id"]); ?>
                    <button type="button" class="btn-delete-usuario" data-form="<?= $form_id ?>"
                      data-nombre="<?= htmlspecialchars($usuario["nombres"] . ' ' . $usuario["apellidos"], ENT_QUOTES) ?>"
                      title="<?= $es_uno_mismo ? 'No puedes desactivar tu propia cuenta' : 'Desactivar usuario' ?>"
                      <?= $es_uno_mismo ? 'disabled' : '' ?>>
                      <i class="bi bi-person-dash"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php foreach ($usuarios as $usuario): ?>
        <form id="form-usuario-<?= $usuario["id"] ?>" method="POST" action="php/modificar_usuarios.php"></form>
      <?php endforeach; ?>

      <!-- Modal: Crear usuario -->
      <div class="modal fade" id="ModalCrearUsuario" tabindex="-1" role="dialog" aria-labelledby="ModalCrearUsuarioLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <form method="POST" action="php/crear_usuario.php">

              <div class="ml-header">
                <div class="ml-icon-badge"><i class="bi bi-person-plus-fill"></i></div>
                <div style="flex:1;">
                  <h5 class="ml-title" id="ModalCrearUsuarioLabel">Crear usuario</h5>
                  <p class="ml-subtitle">Registra un nuevo usuario en el sistema</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>

              <div class="ml-body">

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="nombres">Nombres<span class="req">*</span></label>
                    <input type="text" name="nombres" id="nombres" class="ml-input" required>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="apellidos">Apellidos<span class="req">*</span></label>
                    <input type="text" name="apellidos" id="apellidos" class="ml-input" required>
                  </div>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="telefono">Teléfono</label>
                    <input type="text" name="telefono" id="telefono" class="ml-input">
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="correo">Email<span class="req">*</span></label>
                    <input type="email" name="correo" id="correo" class="ml-input" required>
                  </div>
                </div>

                <div class="ml-field">
                  <label class="ml-label" for="clave">Clave<span class="req">*</span></label>
                  <input type="password" name="clave" id="clave" class="ml-input" minlength="6" autocomplete="new-password" required>
                </div>

                <div class="ml-row">
                  <div class="ml-field">
                    <label class="ml-label" for="tipo">Tipo<span class="req">*</span></label>
                    <select name="tipo" id="tipo" class="ml-select" required>
                      <option value="">Seleccione</option>
                      <?php foreach ($tipos_todos as $tipo): ?>
                        <option value="<?= $tipo["id"] ?>"><?= htmlspecialchars($tipo["tipo"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ml-field">
                    <label class="ml-label" for="pais">País<span class="req">*</span></label>
                    <select name="pais" id="pais" class="ml-select" required>
                      <?php foreach ($paises as $pais): ?>
                        <option value="<?= $pais["id"] ?>" <?= $pais["id"] == 1 ? 'selected' : '' ?>><?= htmlspecialchars($pais["pais"]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

              </div>
              <div class="ml-footer">
                <button type="button" class="ml-btn-cancel" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="ml-btn-submit"><i class="bi bi-check-lg mr-1"></i> Crear usuario</button>
              </div>
            </form>
          </div>
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
<script>
$(document).ready(function () {
  var table = $('#usuarios-table').DataTable({
    responsive: false,
    autoWidth: false,
    dom: '<"top"l>rt<"bottom"ip>',
    columnDefs: [
      {
        targets: '_all',
        render: function (data, type, row) {
          if (type === 'filter' || type === 'sort') {
            var $wrap = $('<div>').html(data);
            var $field = $wrap.find('input, select');
            if ($field.length) {
              return $field.is('select') ? $field.find('option:selected').text() : $field.val();
            }
          }
          return data;
        }
      }
    ],
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

  $('#usuarios-search').on('keyup', function () {
    table.search(this.value).draw();
  });

  $('#usuarios-table').on('click', '.btn-delete-usuario', function () {
    if ($(this).is('[disabled]')) return;
    var formId = $(this).data('form');
    var nombre = $(this).data('nombre');
    inkConfirm({
      title: '¿Desactivar este usuario?',
      text: '"' + nombre + '" no podrá iniciar sesión ni aparecerá en los listados activos. Podrás reactivarlo más adelante si lo necesitas.',
      btnOk: 'Desactivar'
    }, function () {
      var form = document.getElementById(formId);
      form.action = 'php/eliminar_usuario.php';
      form.submit();
    });
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
