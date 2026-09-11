<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$sql = "SELECT p.id, p.tipo as ptipo, z.zona, u.nombres, u.apellidos, u.tipo, p.fecha,
               c.colegio, e.estado AS estado_nombre, p.estado AS estado_id,
               c.sub_zona, c.responsable
        FROM muestreos p
        JOIN colegios c  ON p.id_colegio = c.id
        JOIN zonas z     ON z.codigo = c.cod_zona
        JOIN usuarios u  ON u.cod_zona = z.codigo
        JOIN estados_pedidos e ON e.id = p.estado
        WHERE p.id_usuario='".$_SESSION["id"]."' OR c.cod_zona='".$_SESSION["zona"]."'
        GROUP BY p.id";
$req = $bdd->prepare($sql);
$req->execute();
$pedidos = $req->fetchAll();
$total   = count($pedidos);

$sub_zonas_map = [];
foreach ($bdd->query("SELECT id, sub_zona FROM sub_zonas")->fetchAll() as $sz)
  $sub_zonas_map[$sz['id']] = $sz['sub_zona'];

$estados_uniq = [];
foreach ($pedidos as $p) {
  $e = $p['estado_nombre'] ?? '';
  if ($e && !in_array($e, $estados_uniq)) $estados_uniq[] = $e;
}
sort($estados_uniq);

// estado_id → badge class
$estado_badge = [
  1 => 'eb-1', // Pendiente  → amarillo
  2 => 'eb-2', // Aprobado   → verde
  3 => 'eb-3', // Anulado    → rojo
  4 => 'eb-4', // Despachado → azul
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Ver muestreo</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .ft-date-wrap    { display:flex; align-items:center; gap:6px; }
    .ft-date-label   { font-size:12px; color:#64748b; font-weight:600; white-space:nowrap; margin:0; }
    .lm-count-badge  { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .estado-badge    { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
    .lm-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid #4361ee; color: #4361ee; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: #4361ee; color: #fff; text-decoration: none; }
    .eb-1 { background:#fef3c7; color:#b45309; }
    .eb-2 { background:#dcfce7; color:#15803d; }
    .eb-3 { background:#fee2e2; color:#dc2626; }
    .eb-4 { background:#dbeafe; color:#1d4ed8; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #vm-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #vm-table th, #vm-table td { padding: 3px 4px !important; }
      #vm-table thead th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #vm-table thead, #vm-table tfoot { display: table-row-group !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
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
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Ver muestreo</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Muestreo</li>
                <li class="breadcrumb-item active">Ver</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#eef2ff;color:#4361ee">
              <i class="bi bi-box-seam"></i>
            </div>
            <div class="stat-info-modern">
              <h3><?= $total ?></h3>
              <p class="stat-label">Mis muestreos</p>
              <span class="stat-sub">Total de solicitudes</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="vm-search" placeholder="Buscar por colegio, zona, responsable...">
        </div>
        <?php if (!empty($estados_uniq)): ?>
        <select class="ft-select" id="vm-estado">
          <option value="">Todos los estados</option>
          <?php foreach ($estados_uniq as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="vm-fecha-desde" style="min-width:140px">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="vm-fecha-hasta" style="min-width:140px">
        </div>
        <button class="ft-btn ft-apply" id="vm-btn-apply"><i class="bi bi-funnel"></i> Filtrar</button>
        <button class="ft-btn ft-clear" id="vm-btn-clear"><i class="bi bi-x-circle"></i> Limpiar</button>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-box-seam mr-2"></i> Mis muestreos</h5>
          <span class="lm-count-badge"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="vm-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Empresa</th>
                <th>Zona</th>
                <th>Responsable</th>
                <th>Colegio</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pedidos as $p):
                $tipo_p = intval($p['tipo'] ?? 0);
                if ($tipo_p == 3) {
                  $parts   = explode("/", $p['zona'] ?? '');
                  $empresa = htmlspecialchars(trim($parts[0] ?? ''));
                  $n_zona  = htmlspecialchars(trim($parts[1] ?? ''));
                  $resp    = htmlspecialchars(trim(($p['nombres'] ?? '').' '.($p['apellidos'] ?? '')));
                } else {
                  $empresa = htmlspecialchars($p['zona'] ?? '');
                  $n_zona  = htmlspecialchars($sub_zonas_map[$p['sub_zona']] ?? '—');
                  $resp    = htmlspecialchars($p['responsable'] ?? '—');
                }
                $fecha_d  = date('d/m/Y', strtotime($p['fecha']));
                $fecha_r  = substr($p['fecha'], 0, 10);
                $e_id     = intval($p['estado_id']);
                $e_cls    = $estado_badge[$e_id] ?? 'eb-1';
                $e_nombre = htmlspecialchars($p['estado_nombre'] ?? '');

                if ($p["ptipo"]==1){
                  $p["ptipo"]="Docente";
                } elseif ($p["ptipo"]==2) {
                    $p["ptipo"]="Estudiante";
                }else{
                  $p["ptipo"]="";
                }
              ?>
              <tr data-date="<?= $fecha_r ?>" data-estado="<?= htmlspecialchars($p['estado_nombre'] ?? '') ?>">
                <td><?= $p['id'] ?></td>
                <td><?= $fecha_d ?></td>
                <td><?= $empresa ?></td>
                <td><?= $n_zona ?></td>
                <td><?= $resp ?></td>
                <td><?= htmlspecialchars($p['colegio']) ?></td>
                <td><?= htmlspecialchars($p['ptipo']) ?></td>
                <td><span class="estado-badge <?= $e_cls ?>"><?= $e_nombre ?></span></td>
                <td>
                  <a href="muestreo_colegio_estado.php?id_pedido=<?= $p['id'] ?>" class="lm-btn-ver">
                    <i class="bi bi-eye"></i> Ver detalle
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
<script>
$(document).ready(function () {
  var table;

  $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'vm-table') return true;
    var estado = $('#vm-estado').val();
    var desde  = $('#vm-fecha-desde').val();
    var hasta  = $('#vm-fecha-hasta').val();
    if (estado && table) {
      var rowEstado = $(table.row(dataIndex).node()).data('estado') || '';
      if (rowEstado !== estado) return false;
    }
    if ((desde || hasta) && table) {
      var raw = $(table.row(dataIndex).node()).data('date') || '';
      if (desde && raw < desde) return false;
      if (hasta && raw > hasta) return false;
    }
    return true;
  });

  table = $('#vm-table').DataTable({
    autoWidth:  false,
    order:      [[0, 'desc']],
    language: {
      lengthMenu:   'Mostrar _MENU_ registros',
      zeroRecords:  'No se encontraron resultados',
      emptyTable:   'No hay información para mostrar',
      info:         'Mostrando _START_ a _END_ de _TOTAL_ registros',
      infoEmpty:    'Sin registros disponibles',
      infoFiltered: '(filtrado de _MAX_ registros)',
      search:       '',
      paginate: { first:'«', previous:'‹', next:'›', last:'»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); }
  });

  $('#vm-search').on('keyup', function () { table.search(this.value).draw(); });
  $('#vm-btn-apply').on('click', function () { table.draw(); });
  $('#vm-fecha-desde, #vm-fecha-hasta').on('change', function () { table.draw(); });
  $('#vm-btn-clear').on('click', function () {
    $('#vm-search').val('');
    $('#vm-estado').val('');
    $('#vm-fecha-desde, #vm-fecha-hasta').val('');
    table.search('').draw();
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
