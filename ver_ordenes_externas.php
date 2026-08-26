<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && !in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: index.php");
    exit;
}

$sql = "SELECT o.id as oeid, o.fecha, o.estado, o.anio, o.proveedor, o.solicitante, o.empresa_solicitante,
        CONCAT(u.nombres,' ',u.apellidos) AS usuario
        FROM ordenes_externas o
        JOIN usuarios u ON u.id = o.usuario
        WHERE o.estado != 3
        ORDER BY o.id DESC";

$req = $bdd->prepare($sql);
$req->execute();
$ops   = $req->fetchAll();
$total = count($ops);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Órdenes Externas</title>
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
    #oe-table thead th { background: #3730a3; color: #fff; font-size: .78rem; font-weight: 600; white-space: nowrap; padding: 10px 12px; border: none; }
    #oe-table tbody tr:nth-child(even) { background: #f5f3ff; }
    #oe-table tbody tr:hover { background: #ede9fe; }
    #oe-table tbody td { font-size: .82rem; padding: 9px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; color: #1e293b; }
    .oe-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 600; }
    .oe-badge-green { background: #dcfce7; color: #166534; }
    .oe-badge-red   { background: #fee2e2; color: #991b1b; }
    .oe-link { color: #6d28d9; font-weight: 600; text-decoration: none; }
    .oe-link:hover { text-decoration: underline; }
    .ft-date-range { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .ft-date-label { font-size: .78rem; font-weight: 600; color: #64748b; white-space: nowrap; }
    @media (max-width: 575px) {
      #oe-table_wrapper { overflow-x: auto; }
      #oe-table { min-width: 760px; }
      #oe-table td, #oe-table th { display: table-cell !important; }
    }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-sm-12">
            <div class="title">
              <h4><i class="bi bi-truck mr-2" style="color:#6d28d9"></i>Órdenes Externas</h4>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern sblue"><i class="bi bi-truck"></i></div>
            <div class="stat-info-modern">
              <h3><?= number_format($total) ?></h3>
              <p class="stat-label">Total Órdenes Externas</p>
              <span class="stat-sub">Órdenes activas</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="oe-search" placeholder="Buscar por orden, proveedor o usuario...">
        </div>
        <select class="ft-select" id="oe-filter-cumplida">
          <option value="">Todas</option>
          <option value="si">Cumplidas</option>
          <option value="no">Pendientes</option>
        </select>
        <div class="ft-date-range">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="oe-filter-desde">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="oe-filter-hasta">
        </div>
        <button class="ft-btn ft-apply" id="oe-btn-apply">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
        <button class="ft-btn ft-clear" id="oe-btn-clear">
          <i class="bi bi-x-circle"></i> Limpiar
        </button>
      </div>

      <div class="modern-card">
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="oe-table">
            <thead>
              <tr>
                <th>Orden #</th>
                <th>Usuario</th>
                <th>Fecha</th>
                <th>Solicitante</th>
                <th>Empresa</th>
                <th>Proveedor</th>
                <th>Cumplida</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ops as $op):
                $cumplida = $op['estado'] == 4;
                $fecha_raw = substr($op['fecha'], 0, 10);
              ?>
              <tr data-fecha="<?= $fecha_raw ?>" data-cumplida="<?= $cumplida ? 'si' : 'no' ?>">
                <td><a href="oe_solicitada.php?oe=<?= $op['oeid'] ?>" class="oe-link"><?= htmlspecialchars($op['anio'] . ' - ' . $op['oeid']) ?></a></td>
                <td><?= htmlspecialchars($op['usuario']) ?></td>
                <td><?= htmlspecialchars($op['fecha']) ?></td>
                <td><?= htmlspecialchars($op['solicitante']) ?></td>
                <td><?= htmlspecialchars($op['empresa_solicitante']) ?></td>
                <td><?= htmlspecialchars($op['proveedor']) ?></td>
                <td>
                  <?php if ($cumplida): ?>
                    <span class="oe-badge oe-badge-green"><i class="bi bi-check-circle mr-1"></i>Sí</span>
                  <?php else: ?>
                    <span class="oe-badge oe-badge-red"><i class="bi bi-clock mr-1"></i>No</span>
                  <?php endif; ?>
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
<script>
$(document).ready(function () {
  $.fn.dataTable.ext.errMode = 'none';

  var table = $('#oe-table').dataTable({
    responsive: { details: false },
    autoWidth:  false,
    order:      [[2, 'desc']],
    dom:        '<"top"l>rt<"bottom"ip>',
    language: {
      lengthMenu:   "Mostrar _MENU_ registros",
      zeroRecords:  "No se encontraron resultados",
      emptyTable:   "No hay información para mostrar",
      info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty:    "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      paginate:     { first: "«", previous: "‹", next: "›", last: "»" }
    },
    initComplete: function () {
      var api = this.api();
      $('#oe-search').on('keyup', function () {
        var val = this.value;
        if (val.length >= 1 || val.length === 0) api.search(val).draw();
      });
    }
  });

  $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'oe-table') return true;
    var cumplida = $('#oe-filter-cumplida').val();
    var desde    = $('#oe-filter-desde').val();
    var hasta    = $('#oe-filter-hasta').val();
    var $row     = $(table.api().row(dataIndex).node());
    if (cumplida && $row.data('cumplida') !== cumplida) return false;
    if (desde || hasta) {
      var fecha = $row.data('fecha') || '';
      if (desde && fecha < desde) return false;
      if (hasta && fecha > hasta) return false;
    }
    return true;
  });

  $('#oe-filter-cumplida').on('change', function () {
    table.api().draw();
  });

  $('#oe-btn-apply').on('click', function () {
    table.api().draw();
  });

  $('#oe-btn-clear').on('click', function () {
    $('#oe-search').val('');
    $('#oe-filter-cumplida').val('');
    $('#oe-filter-desde, #oe-filter-hasta').val('');
    table.api().search('').draw();
  });
});
</script>
</body>
</html>
