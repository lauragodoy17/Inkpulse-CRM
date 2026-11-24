<?php
/**
 * /formatos_venta_directa.php
 * Lista de formatos de venta directa (menú Venta directa): consecutivo, colegio, pedidos, venta, caja y si
 * ya se legalizó. Ver includes/formatos_vd_datos.php.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/formatos_vd_datos.php");

dm_validar_acceso();
fvd_asegurar_tablas($bdd);

// 2026-11-24: al abrir la lista también se asignan los formatos de las ventas que ya llegaron (includes/solicitudes_vd_datos.php).
require_once("includes/solicitudes_vd_datos.php");
foreach (array_slice(svd_periodos($bdd), 0, 2) as $per_svd) svd_asignar_pendientes($bdd, (int)$per_svd['id']);
$formatos = fvd_listar($bdd);
$pesos = fn($v) => '$ ' . number_format((float)$v, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Formatos de venta directa</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .lm-count-badge { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    .lm-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; }
    .lm-badge-yellow { background:#fef3c7; color:#b45309; } .lm-badge-green { background:#dcfce7; color:#15803d; } .lm-badge-red { background:#fee2e2; color:#dc2626; }
    #fv-table thead th { background:#1d4ed8 !important; color:#fff !important; font-weight:600; font-size:.80rem; padding:11px 12px; white-space:nowrap; border:none; }
    #fv-table tbody tr:nth-child(even) td { background:#f5f8ff; }
    .lm-btn-ver { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:600;
      border:1.5px solid #1d4ed8; color:#1d4ed8; background:transparent; text-decoration:none; white-space:nowrap; }
    .lm-btn-ver:hover { background:#1d4ed8; color:#fff; text-decoration:none; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12"><div class="title"><h4>Formatos de venta directa</h4></div></div>
          <div class="col-md-4 col-sm-12 text-md-right">
            <a href="devoluciones_venta_directa.php" class="lm-btn-ver" style="padding:8px 14px;font-size:13px;"><i class="bi bi-plus-circle"></i> Nuevo formato</a>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <div class="ft-search">
          <i class="bi bi-search ft-search-icon"></i>
          <input type="text" id="fv-search" placeholder="Buscar por número, colegio, pedido, responsable...">
        </div>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-clipboard-check mr-2"></i> Formatos</h5>
          <span class="lm-count-badge" id="fv-count"><?= count($formatos) ?> registros</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="fv-table">
            <thead><tr><th>No.</th><th>Fecha venta</th><th>Colegio</th><th>Período</th><th>Pedidos</th><th>Responsable</th><th>Vendidas</th><th>Total venta</th><th>Diferencia caja</th><th>Estado</th><th>Registró</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($formatos as $f):
                $anulado = (int)$f['estado'] === FVD_ESTADO_ANULADO; ?>
              <tr>
                <td data-order="<?= (int)$f['consecutivo'] ?>"><strong><?= (int)$f['consecutivo'] ?></strong></td>
                <td data-order="<?= htmlspecialchars($f['fecha_venta']) ?>"><?= date('d/m/Y', strtotime($f['fecha_venta'])) ?></td>
                <td><?= htmlspecialchars($f['colegio']) ?></td>
                <td><?= htmlspecialchars($f['periodo'] ?? '') ?></td>
                <?php $reservado_f = trim((string)$f['ids_pedidos']) === ''; /* consecutivo reservado el día de la venta (2026-11-24) */ ?>
                <td style="white-space:nowrap"><?= $reservado_f ? '<span class="lm-badge" style="background:#e0e7ff;color:#3730a3;" title="Los pedidos entran al registrar la devolución eligiendo esta venta">Reservado · sin pedidos</span>' : htmlspecialchars(implode(', ', array_map(fn($c) => '#' . (int)substr($c, 1), explode(',', $f['ids_pedidos'])))) ?></td>
                <td><?= htmlspecialchars($f['responsable']) ?></td>
                <td style="text-align:center"><?= (int)$f['vendidas'] ?></td>
                <td style="white-space:nowrap"><?= $pesos($f['total_venta']) ?></td>
                <td style="white-space:nowrap;color:<?= round((float)$f['diferencia']) == 0 ? '#15803d' : '#dc2626' ?>"><?= $pesos($f['diferencia']) ?></td>
                <td><?php if ($anulado): ?><span class="lm-badge lm-badge-red">Anulado</span>
                  <?php elseif ((int)$f['etapa'] >= FVD_ETAPA_CERRADO): ?><span class="lm-badge lm-badge-green">Operación cerrada</span>
                  <?php else: ?><span class="lm-badge lm-badge-yellow">En curso</span><?php endif; ?>
                  <?php foreach (array_filter(explode(',', (string)($f['ids_devolucion'] ?? ''))) as $id_dev_f): ?><div><a href="devolucion_colegio.php?id_pedido=<?= (int)$id_dev_f ?>" style="font-size:11px;">Devolución #<?= (int)$id_dev_f ?></a></div><?php endforeach; ?></td>
                <td><?= htmlspecialchars($f['usuario'] ?? '') ?></td>
                <?php /* 2026-11-24 (la usuaria): se entra al formato (hoja en vivo: solicitado, remisionado, devuelto, vendido), no a las etapas. */ ?>
                <td style="white-space:nowrap"><a href="ver_formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="lm-btn-ver"><i class="bi bi-file-earmark-text"></i> Ver formato</a>
                  <?php if (!$anulado && !$reservado_f && (int)$f['etapa'] < FVD_ETAPA_CERRADO): ?><a href="formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="lm-btn-ver" title="Precios, auxiliares y cierre de dinero"><i class="bi bi-pencil-square"></i> Diligenciar</a><?php endif; ?></td>
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
<script src="src/ink-alerts.js"></script>
<script>
$(function () {
  var t = $('#fv-table').DataTable({
    autoWidth: false, order: [[0, 'desc']], columnDefs: [{ orderable: false, targets: -1 }],
    language: {
      lengthMenu: 'Mostrar _MENU_ registros', zeroRecords: 'No se encontraron resultados', emptyTable: 'No hay formatos registrados',
      info: 'Mostrando _START_ a _END_ de _TOTAL_ registros', infoEmpty: 'Sin registros disponibles', infoFiltered: '(filtrado de _MAX_ registros)',
      search: '', paginate: { first: '«', previous: '‹', next: '›', last: '»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); },
    drawCallback: function () { $('#fv-count').text(this.api().page.info().recordsDisplay + ' registros'); }
  });
  $('#fv-search').on('keyup', function () { t.search(this.value).draw(); });
});
</script>
</body>
</html>
