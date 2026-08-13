<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$id_colegio = intval($_GET['id_colegio']);
$periodo_id = intval($_GET['periodo']);

$req_col = $bdd->prepare("SELECT colegio FROM colegios WHERE id='".$id_colegio."'");
$req_col->execute();
$colegio_row = $req_col->fetch();

// Cliente definido en la pestaña Adopciones para este colegio/período (editable aquí también),
// igual que en solicitar_pedido.php. clientes tiene ~10.000 filas: se resuelve solo el ya
// seleccionado y el resto se busca por AJAX (ajax/buscar_clientes.php).
$stmt_cliente_adop = $bdd->prepare("SELECT cliente FROM recursos WHERE id_colegio=? AND id_periodo=?");
$stmt_cliente_adop->execute([$id_colegio, $periodo_id]);
$rec_cliente = $stmt_cliente_adop->fetch();
$cliente_predet = !empty($rec_cliente['cliente']) ? $rec_cliente['cliente'] : '';

$cliente_sel = null;
if ($cliente_predet !== '') {
    $stmt_cl_sel = $bdd->prepare("SELECT id, cliente FROM clientes WHERE id=?");
    $stmt_cl_sel->execute([$cliente_predet]);
    $cliente_sel = $stmt_cl_sel->fetch();
}

$sql_libros = "SELECT l.id, l.id_grado, l.libro, p.tasa_compra, p.tasa_compra_d, m.materia, p.cod_area
               FROM libros l
               JOIN presupuestos p ON l.id=p.id_libro
               JOIN materias m ON l.id_materia=m.id
               WHERE p.id_colegio='".$id_colegio."' AND p.id_periodo='".$periodo_id."' AND p.definido='1'";
$req_libros = $bdd->prepare($sql_libros);
$req_libros->execute();
$libros = $req_libros->fetchAll();

$mostrar_tipo = ($_SESSION['tipo'] == 3 || $_SESSION['zona'] == '5656');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Devolución de venta</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <style>
    @media print {
      .mc-actions, .d-print-none, .left-side-bar, .header { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 9px; }
      #sd-table thead, #sd-table tfoot { display: table-row-group !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
      textarea { overflow:visible !important; white-space:pre-wrap !important; }
    }

    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    .dc { width: 70px !important; }

    .mc-table-wrap { border-radius:10px; overflow-x:auto; box-shadow:0 2px 10px rgba(15,23,42,.09); margin-bottom:24px; }
    #sd-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    #sd-table thead th {
      background:#f8fafc; color:#374151; font-weight:600;
      padding:11px 12px; text-align:left; border:none;
      border-bottom:2px solid #e2e8f0; white-space:nowrap; font-size:.79rem;
    }
    #sd-table tbody tr              { background:#fff; }
    #sd-table tbody tr:nth-child(even) { background:#f8fafc; }
    #sd-table tbody tr:hover        { background:#eff6ff; }
    #sd-table tbody td { padding:9px 12px; border-bottom:1px solid #e2e8f0; color:#1e293b; vertical-align:middle; }
    #sd-table tfoot td { padding:10px 12px; background:#f8fafc; color:#374151; font-weight:700; font-size:.83rem; border:none; border-top:2px solid #e2e8f0; }

    .mc-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 10px 22px; border-radius: 8px; font-size: .9rem; font-weight: 700;
      border: none; cursor: pointer; text-decoration: none;
      transition: opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity: .88; transform: translateY(-1px); }
    .mc-btn-blue  { background: linear-gradient(135deg, #1d4ed8, #2563eb); color: #fff; }
    .mc-btn:hover { color: #fff; }
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
            <div class="title"><h4>Devolución de venta</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">
                  <a href="colegios_devols.php?periodo=<?= $periodo_id ?>">Devoluciones de venta</a>
                </li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($colegio_row['colegio'] ?? '') ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <form action="php/devolucion.php" method="POST">
        <input type="hidden" name="id_colegio" value="<?= $id_colegio ?>">
        <input type="hidden" name="periodo"    value="<?= $periodo_id ?>">

        <!-- Datos del formulario -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-person-lines-fill mr-2"></i> Datos de la devolución</h5>
          </div>
          <div class="px-4 py-3">
            <div class="row">
              <div class="col-md-4 col-12">
                <div class="form-group">
                  <label class="control-label">Cliente <small style="color:red;">*</small></label>
                  <select class="form-control select2" name="cliente" id="cliente" style="width:100%;" required>
                    <option value="">Seleccionar</option>
                    <?php if ($cliente_sel): ?>
                    <option value="<?= $cliente_sel['id'] ?>" selected><?= htmlspecialchars($cliente_sel['cliente']) ?></option>
                    <?php endif; ?>
                  </select>
                </div>
              </div>
              <?php if ($mostrar_tipo): ?>
              <div class="col-md-4 col-12">
                <div class="form-group">
                  <label class="control-label">Tipo <small style="color:red;">*</small></label>
                  <select name="tipo" id="tipo" class="form-control" required>
                    <option value="">Seleccionar</option>
                    <option value="1">Libros sueltos</option>
                    <option value="2">Paquetes</option>
                  </select>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Tabla de libros -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-book mr-2"></i> Libros del colegio</h5>
          </div>
          <div class="mc-table-wrap">
            <table id="sd-table">
              <thead>
                <tr>
                  <th>Título</th>
                  <th>Materia</th>
                  <th>Grado</th>
                  <th>Compradores activos</th>
                  <th>Cantidad <small style="color:#fca5a5;">*</small></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($libros as $libro):
                  $sql_go = "SELECT id_grado_otro FROM areas_objetivas WHERE codigo='".$libro['cod_area']."'";
                  $req_go = $bdd->prepare($sql_go);
                  $req_go->execute();
                  $go = $req_go->fetch();

                  if (empty($go['id_grado_otro'])) {
                    $id_grado_real = $libro['id_grado'];
                  } else {
                    $id_grado_real = $go['id_grado_otro'];
                  }

                  $req_grado = $bdd->prepare("SELECT grado FROM grados WHERE id='".$id_grado_real."'");
                  $req_grado->execute();
                  $grado = $req_grado->fetch();

                  $req_alm = $bdd->prepare("SELECT SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_grado='".$id_grado_real."' AND id_periodo='".$periodo_id."' AND id_colegio='".$id_colegio."'");
                  $req_alm->execute();
                  $alm = $req_alm->fetch();

                  $tasa     = ($libro['tasa_compra_d'] != 0.00) ? $libro['tasa_compra_d'] : $libro['tasa_compra'];
                  $comp_act = floor(($alm['alumnos'] ?? 0) * $tasa);
                  $key      = !empty($libro['cod_area']) ? $libro['cod_area'] : $libro['id'];
                ?>
                <tr>
                  <td><?= htmlspecialchars($libro['libro']) ?></td>
                  <td><?= htmlspecialchars($libro['materia']) ?></td>
                  <td><?= htmlspecialchars($grado['grado'] ?? '—') ?></td>
                  <td id="act<?= $key ?>"><?= $comp_act ?></td>
                  <td>
                    <input type="number" id="cantidad<?= $key ?>" class="form-control dc" value="0" required>
                  </td>
                  <input type="hidden" name="libro[]" id="libro<?= $key ?>">
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Observaciones -->
        <div class="modern-card mb-3">
          <div class="card-head">
            <h5><i class="bi bi-chat-text mr-2"></i> Observaciones</h5>
          </div>
          <div class="px-4 py-3">
            <textarea name="observaciones" id="observaciones" class="form-control" rows="4"
              placeholder="Sin observaciones..."></textarea>
          </div>
        </div>

        <div style="margin-bottom:24px;">
          <button type="submit" id="solicitar" class="mc-btn mc-btn-blue">
            <i class="bi bi-send"></i> Solicitar devolución
          </button>
        </div>

      </form>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script>
$(document).ready(function () {
  $('#cliente').select2({
    placeholder: 'Seleccionar cliente',
    allowClear: true,
    width: '100%',
    minimumInputLength: 2,
    ajax: {
      url: 'ajax/buscar_clientes.php',
      dataType: 'json',
      delay: 300,
      data: function (params) { return { q: params.term }; },
      processResults: function (data) { return { results: data }; }
    },
    language: {
      inputTooShort: function () { return 'Escribe al menos 2 letras para buscar...'; },
      noResults:  function () { return 'Sin resultados'; },
      searching:  function () { return 'Buscando...'; }
    }
  });
});

<?php foreach ($libros as $libro):
  $key = !empty($libro['cod_area']) ? $libro['cod_area'] : $libro['id'];
  $lib_id = $libro['id'];
?>
$('#cantidad<?= $key ?>').on('keyup', function(){
  var cant = $(this).val();
  if (parseInt(cant) > parseInt($('#act<?= $key ?>').text())) {
    alert('La cantidad solicitada no puede ser mayor a los compradores activos');
    $(this).val('0');
    $('#libro<?= $key ?>').val('');
  } else {
    <?php if (!empty($libro['cod_area'])): ?>
    $('#libro<?= $key ?>').val(<?= $lib_id ?> + '/' + cant + '/' + '<?= $key ?>');
    <?php else: ?>
    $('#libro<?= $key ?>').val(<?= $lib_id ?> + '/' + cant);
    <?php endif; ?>
  }
});
<?php endforeach; ?>
window.addEventListener('beforeprint', function () {
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta._ph = ta.style.height;
    ta.style.setProperty('height', ta.scrollHeight + 'px', 'important');
  });
});
window.addEventListener('afterprint', function () {
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta.style.height = ta._ph || '';
  });
});
</script>
</body>
</html>
