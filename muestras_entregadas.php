<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if ($_SESSION["tipo"] == 1) {
  $sql = "SELECT p.id, p.tipo as ptipo, z.zona, u.nombres, u.apellidos, u.tipo, p.fecha, c.colegio, e.estado, c.sub_zona, c.responsable, cal.calendario
          FROM muestreos_e p
          JOIN colegios c ON p.id_colegio=c.id
          JOIN zonas z ON z.codigo=c.cod_zona
          JOIN usuarios u ON u.cod_zona=z.codigo
          JOIN estados_pedidos e ON e.id=p.estado
          LEFT JOIN calendarios cal ON c.id_calendario=cal.id
          WHERE p.estado=1
          GROUP BY p.id";
} else {
  $sql = "SELECT p.id, p.tipo as ptipo, z.zona, u.nombres, u.apellidos, u.tipo, p.fecha, c.colegio, e.estado, c.sub_zona, c.responsable, cal.calendario
          FROM muestreos_e p
          JOIN colegios c ON p.id_colegio=c.id
          JOIN zonas z ON z.codigo=c.cod_zona
          JOIN usuarios u ON u.cod_zona=z.codigo
          JOIN estados_pedidos e ON e.id=p.estado
          LEFT JOIN calendarios cal ON c.id_calendario=cal.id
          WHERE (p.id_usuario='".$_SESSION["id"]."' OR c.cod_zona='".$_SESSION["zona"]."') AND p.estado=1
          GROUP BY p.id";
}

$req = $bdd->prepare($sql);
$req->execute();
$pedidos = $req->fetchAll();
$total   = count($pedidos);

$sub_zonas_map = [];
foreach ($bdd->query("SELECT id, sub_zona FROM sub_zonas")->fetchAll() as $sz)
  $sub_zonas_map[$sz['id']] = $sz['sub_zona'];

$zonas_uniq = [];
foreach ($pedidos as $p) {
  $tipo_p = intval($p['tipo'] ?? 0);
  $parts  = explode("/", $p['zona'] ?? '');
  $z      = ($tipo_p == 3 || $tipo_p == 1) ? trim($parts[1] ?? $parts[0] ?? '') : trim($p['zona'] ?? '');
  if ($z && !in_array($z, $zonas_uniq)) $zonas_uniq[] = $z;
}
sort($zonas_uniq);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Muestras legalizadas</title>
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
    .lm-count-badge { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .ft-date-wrap   { display:flex; align-items:center; gap:6px; }
    .ft-date-label  { font-size:12px; color:#64748b; font-weight:600; white-space:nowrap; margin:0; }
    #lm-table thead th {
      background: #0d9488 !important;
      color: #fff !important;
      font-weight: 600; font-size: 0.80rem;
      padding: 11px 12px; white-space: nowrap; border: none;
    }
    #lm-table tbody tr:nth-child(even) td { background: #f0fdfa; }
    #lm-table tbody tr:hover td           { background: #ccfbf1 !important; }
    #lm-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #lm-table tbody tr:hover              { border-left-color: #0d9488; }
    .lm-btn-ver {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid #0d9488; color: #0d9488; background: transparent;
      text-decoration: none; white-space: nowrap; transition: background .15s, color .15s;
    }
    .lm-btn-ver:hover { background: #0d9488; color: #fff; text-decoration: none; }
    .lm-btn-del {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 5px 10px; border-radius: 7px; font-size: 12px; font-weight: 600;
      border: 1.5px solid #dc2626; color: #dc2626; background: transparent;
      cursor: pointer; white-space: nowrap; transition: background .15s, color .15s;
      margin-left: 4px;
    }
    .lm-btn-del:hover { background: #dc2626; color: #fff; }
    @page { margin: 15px; size: landscape; }
    @media print {
      a, .left-side-bar, .header, .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 8px; }
      .main-container, .pd-ltr-20, .table-responsive { overflow: visible !important; }
      #lm-table { width: 100% !important; table-layout: auto !important; font-size: 7.5px !important; }
      #lm-table th, #lm-table td { padding: 3px 4px !important; }
      #lm-table thead th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      #lm-table thead, #lm-table tfoot { display: table-row-group !important; }
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
            <div class="title">
              <h4>
                Muestras legalizadas
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;
                  padding:3px 12px;border-radius:20px;margin-left:10px;vertical-align:middle;
                  background:#ccfbf1;color:#0d9488;">
                  <i class="bi bi-box-seam"></i> Legalizadas
                </span>
              </h4>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6">
          <div class="stat-card-modern">
            <div class="stat-icon-modern" style="background:#ccfbf1;color:#0d9488">
              <i class="bi bi-box-seam"></i>
            </div>
            <div class="stat-info-modern">
              <h3><?= $total ?></h3>
              <p class="stat-label">Legalizadas</p>
              <span class="stat-sub">Total de registros</span>
            </div>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="lm-search" placeholder="Buscar por colegio, zona, responsable...">
        </div>
        <?php if (!empty($zonas_uniq)): ?>
        <select class="ft-select" id="lm-zona">
          <option value="">Todas las zonas</option>
          <?php foreach ($zonas_uniq as $z): ?>
          <option value="<?= htmlspecialchars($z) ?>"><?= htmlspecialchars($z) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Desde</span>
          <input type="date" class="ft-select" id="lm-fecha-desde" style="min-width:140px">
        </div>
        <div class="ft-date-wrap">
          <span class="ft-date-label">Hasta</span>
          <input type="date" class="ft-select" id="lm-fecha-hasta" style="min-width:140px">
        </div>
        <button class="ft-btn ft-apply" id="lm-btn-apply"><i class="bi bi-funnel"></i> Filtrar</button>
        <button class="ft-btn ft-clear" id="lm-btn-clear"><i class="bi bi-x-circle"></i> Limpiar</button>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-list-ul mr-2"></i> Lista — Muestras legalizadas</h5>
          <span class="lm-count-badge" style="background:#ccfbf1;color:#0d9488"><?= $total ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="lm-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Empresa</th>
                <th>Zona</th>
                <th>Responsable</th>
                <th>Colegio</th>
                <th>Tipo</th>
                <th>Calendario</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pedidos as $p):
                $tipo_p = intval($p['tipo'] ?? 0);
                if ($tipo_p == 3 || $tipo_p == 1) {
                  $parts   = explode("/", $p['zona'] ?? '');
                  $empresa = htmlspecialchars(trim($parts[0] ?? ''));
                  $n_zona  = htmlspecialchars(trim($parts[1] ?? ''));
                  $resp    = htmlspecialchars(trim(($p['nombres'] ?? '').' '.($p['apellidos'] ?? '')));
                  $zona_d  = trim($parts[1] ?? $parts[0] ?? '');
                } else {
                  $empresa = htmlspecialchars($p['zona'] ?? '');
                  $n_zona  = htmlspecialchars($sub_zonas_map[$p['sub_zona']] ?? '—');
                  $resp    = htmlspecialchars($p['responsable'] ?? '—');
                  $zona_d  = $p['zona'] ?? '';
                }
                $fecha_d = date('d/m/Y', strtotime($p['fecha']));
                $fecha_r = substr($p['fecha'], 0, 10);

                if ($p["ptipo"]==1){
                  $p["ptipo"]="Docente";
                } elseif ($p["ptipo"]==2) {
                    $p["ptipo"]="Estudiante";
                }else{
                  $p["ptipo"]="";
                }
              ?>
              <tr data-date="<?= $fecha_r ?>" data-zona="<?= htmlspecialchars($zona_d) ?>">
                <td><?= $p['id'] ?></td>
                <td><?= $fecha_d ?></td>
                <td><?= $empresa ?></td>
                <td><?= $n_zona ?></td>
                <td><?= $resp ?></td>
                <td><?= htmlspecialchars($p['colegio']) ?></td>
                <td><?= htmlspecialchars($p['ptipo'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['calendario'] ?? '—') ?></td>
                <td style="white-space:nowrap">
                  <a href="muestreo_colegio_resto.php?id_muestras_e=<?= $p['id'] ?>" class="lm-btn-ver">
                    <i class="bi bi-eye"></i> Ver
                  </a>
                  <button class="lm-btn-del eliminar" data-id="<?= $p['id'] ?>">
                    <i class="bi bi-trash"></i>
                  </button>
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
    if (settings.nTable.id !== 'lm-table') return true;
    var zona  = $('#lm-zona').val();
    var desde = $('#lm-fecha-desde').val();
    var hasta = $('#lm-fecha-hasta').val();
    if (zona && table) {
      var rowZona = $(table.row(dataIndex).node()).data('zona') || '';
      if (rowZona !== zona) return false;
    }
    if ((desde || hasta) && table) {
      var raw = $(table.row(dataIndex).node()).data('date') || '';
      if (desde && raw < desde) return false;
      if (hasta && raw > hasta) return false;
    }
    return true;
  });

  table = $('#lm-table').DataTable({
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

  $('#lm-search').on('keyup', function () { table.search(this.value).draw(); });
  $('#lm-btn-apply').on('click', function () { table.draw(); });
  $('#lm-fecha-desde, #lm-fecha-hasta').on('change', function () { table.draw(); });
  $('#lm-btn-clear').on('click', function () {
    $('#lm-search').val('');
    $('#lm-zona').val('');
    $('#lm-fecha-desde, #lm-fecha-hasta').val('');
    table.search('').draw();
  });

  $(document).on('click', '.eliminar', function () {
    var cod = $(this).data('id');
    inkConfirm({
      title: '¿Eliminar estas muestras?',
      text:  'Esta acción no se puede deshacer.',
      type:  'danger',
      btnOk: 'Sí, eliminar'
    }, function () {
      window.location = 'php/eliminar_muestras_e.php?codigo=' + cod;
    });
  });
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
