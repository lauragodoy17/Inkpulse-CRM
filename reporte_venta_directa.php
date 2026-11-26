<?php
/**
 * /reporte_venta_directa.php
 * Menú Venta directa → Reporte (2026-11-10): colegios cuya adopción tiene el canal de venta "Venta directa - Eureka"
 * (recursos.id_canal o id_canal2), con sus fechas de venta y contacto, y por cada uno el informe de adopción en Excel
 * (el mismo botón "Exportar Excel" de Adopciones en colegio.php: php/adopcion_excel.php).
 * Respeta el alcance por zona del usuario (dm_filtro_alcance_sql).
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_muestras_datos.php"); // dm_validar_acceso, dm_filtro_alcance_sql
require_once("includes/sobrecosto_canal.php");
require_once("includes/adopcion_vd_datos.php");

dm_validar_acceso();
sobrecosto_asegurar_columna($bdd);
adopcion_vd_asegurar($bdd);

$canal_eureka = canal_andrea_id($bdd);
[$alcance, $params_alcance] = dm_filtro_alcance_sql();
$cond_canal = sql_adopcion_tiene_canal([$canal_eureka]);

// Períodos que tienen al menos un colegio con el canal (para el filtro). Por defecto, el más reciente.
$st = $bdd->prepare("SELECT DISTINCT pe.id, pe.periodo FROM recursos r JOIN colegios c ON c.id = r.id_colegio JOIN periodos pe ON pe.id = r.id_periodo
                     WHERE $cond_canal $alcance ORDER BY pe.id DESC");
$st->execute($params_alcance);
$periodos = $st->fetchAll(PDO::FETCH_ASSOC);
$ids_periodos = array_map('intval', array_column($periodos, 'id'));
$periodo_sel = isset($_GET['periodo']) ? (int)$_GET['periodo'] : ($ids_periodos[0] ?? 0); // 0 = todos

$filtro_per = '';
$params = $params_alcance;
if ($periodo_sel > 0) {
    $filtro_per = ' AND r.id_periodo = ?';
    $params[] = $periodo_sel;
}
$st = $bdd->prepare("SELECT c.id AS id_colegio, c.codigo, c.dane, c.colegio, c.ciudad, r.id_periodo, pe.periodo, r.fecha AS fecha_adopcion,
                            r.vd_contacto_nombre, r.vd_contacto_celular, r.vd_contacto_correo,
                            COALESCE(NULLIF(TRIM(z.zona), ''), c.cod_zona) AS zona,
                            (SELECT COUNT(*) FROM presupuestos p WHERE p.id_colegio = c.id AND p.id_periodo = r.id_periodo AND p.definido = 1) AS titulos,
                            -- Asesor: dueño de la zona del colegio (misma regla que dashboard_asesores_stats.php): la zona
                            -- de las adopciones del período si es una sola, si no la zona del colegio.
                            (SELECT UPPER(TRIM(CONCAT(o.nombres, ' ', o.apellidos))) FROM usuarios o
                              WHERE o.cod_zona <> '' AND o.act = 1 AND (o.tipo IN (3, 6) OR o.id = 69)
                                AND o.cod_zona = COALESCE((SELECT MIN(pz.cod_zona) FROM presupuestos pz
                                                            WHERE pz.id_colegio = c.id AND pz.id_periodo = r.id_periodo AND pz.cod_zona <> ''
                                                            HAVING COUNT(DISTINCT pz.cod_zona) = 1), c.cod_zona)
                              ORDER BY o.id LIMIT 1) AS asesor
                     FROM recursos r
                     JOIN colegios c ON c.id = r.id_colegio
                     JOIN periodos pe ON pe.id = r.id_periodo
                     LEFT JOIN zonas z ON z.codigo = c.cod_zona
                     WHERE $cond_canal $alcance $filtro_per
                     ORDER BY pe.id DESC, c.colegio");
$st->execute($params);
$colegios = $st->fetchAll(PDO::FETCH_ASSOC);

// Fechas de venta de todos los colegios listados (una sola consulta).
$jornadas = [];
if ($colegios) {
    $pares = [];
    $pp = [];
    foreach ($colegios as $c) { $pares[] = '(?, ?)'; $pp[] = (int)$c['id_colegio']; $pp[] = (int)$c['id_periodo']; }
    $st = $bdd->prepare("SELECT id, id_colegio, id_periodo, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf
                         FROM adopcion_vd_jornadas WHERE (id_colegio, id_periodo) IN (" . implode(',', $pares) . ") AND cancelada = 0 ORDER BY fecha, hora_inicio");
    $st->execute($pp);
    $filas_j = $st->fetchAll(PDO::FETCH_ASSOC);
    $atendidas = adopcion_vd_atendidas($bdd, $filas_j); // fechas con formato de venta directa registrado
    foreach ($filas_j as $j) {
        $j['formato'] = $atendidas[$j['id']] ?? null;
        $jornadas[$j['id_colegio'] . '-' . $j['id_periodo']][] = $j;
    }
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$hora12 = function ($h) {
    [$hh, $mm] = array_map('intval', explode(':', $h));
    return ($hh % 12 === 0 ? 12 : $hh % 12) . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT) . ($hh < 12 ? ' a. m.' : ' p. m.');
};
$dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Reporte venta directa</title>
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
    #rvd-table thead th { background:#1d4ed8 !important; color:#fff !important; font-weight:600; font-size:.80rem; padding:11px 12px; white-space:nowrap; border:none; }
    #rvd-table tbody td { font-size:.84rem; vertical-align:middle; }
    #rvd-table tbody tr:nth-child(even) td { background:#f5f8ff; }
    .rvd-colegio { font-weight:600; color:#0f172a; }
    .rvd-sub { display:block; font-size:.74rem; color:#64748b; }
    /* Fechas de venta: verde = atendida (tiene formato de venta directa registrado), morado = pendiente o futura. */
    .rvd-fecha { display:inline-flex; align-items:center; gap:6px; background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff; border-radius:8px; padding:2px 8px; margin:2px 0; font-size:.76rem; font-weight:600; white-space:nowrap; }
    .rvd-fecha.rvd-pasada { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .rvd-fecha small { font-weight:500; color:inherit; opacity:.8; }
    .rvd-sin { font-size:.76rem; color:#b45309; background:#fef3c7; border-radius:8px; padding:2px 8px; white-space:nowrap; }
    .rvd-asesor { display:inline-flex; align-items:center; gap:6px; font-size:.78rem; font-weight:600; color:#334155; white-space:nowrap; }
    .rvd-asesor i { color:#1d4ed8; }
    .rvd-acciones { display:flex; flex-direction:column; gap:6px; min-width:170px; }
    .rvd-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; transition:background .15s, color .15s, box-shadow .15s, transform .15s; }
    .rvd-btn i { font-size:14px; }
    .rvd-btn:hover { text-decoration:none; transform:translateY(-1px); }
    .rvd-btn-ver { background:#eff6ff; color:#1d4ed8; border:1.5px solid #bfdbfe; }
    .rvd-btn-ver:hover { background:#1d4ed8; color:#fff; border-color:#1d4ed8; box-shadow:0 4px 10px rgba(29,78,216,.25); }
    .rvd-btn-xls { background:#f0fdf4; color:#15803d; border:1.5px solid #bbf7d0; }
    .rvd-btn-xls:hover { background:#16a34a; color:#fff; border-color:#16a34a; box-shadow:0 4px 10px rgba(22,163,74,.25); }
    .rvd-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .rvd-filtros select { height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:.86rem; min-width:160px; }
    .rvd-filtros .ft-search { flex:1 1 260px; }
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
            <div class="title"><h4>Reporte venta directa</h4></div>
            <small class="text-muted">Colegios cuya adopción tiene el canal de venta <strong>Venta directa - Eureka</strong>.</small>
          </div>
        </div>
      </div>

      <div class="filter-toolbar">
        <form method="GET" class="rvd-filtros" id="rvd-form">
          <select name="periodo" onchange="this.form.submit()" aria-label="Período">
            <option value="0"<?= $periodo_sel === 0 ? ' selected' : '' ?>>Todos los períodos</option>
            <?php foreach ($periodos as $p): ?>
              <option value="<?= (int)$p['id'] ?>"<?= $periodo_sel === (int)$p['id'] ? ' selected' : '' ?>>Período <?= $e($p['periodo']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ft-search">
            <i class="bi bi-search ft-search-icon"></i>
            <input type="text" id="rvd-search" placeholder="Buscar por colegio, DANE, ciudad, zona, asesor, contacto...">
          </div>
        </form>
      </div>

      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-shop mr-2"></i> Colegios con venta directa</h5>
          <span class="lm-count-badge" id="rvd-count"><?= count($colegios) ?> colegios</span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="rvd-table">
            <thead><tr><th>Colegio</th><th>Ciudad</th><th>Zona</th><th>Asesor</th><th>Período</th><th>Títulos adoptados</th><th>Fechas de venta</th><th>Contacto del colegio</th><th>Acciones</th></tr></thead>
            <tbody>
              <?php foreach ($colegios as $c):
                $js = $jornadas[$c['id_colegio'] . '-' . $c['id_periodo']] ?? [];
                $proxima = '';
                foreach ($js as $j) { if ($j['fecha'] >= $hoy) { $proxima = $j['fecha']; break; } } ?>
              <tr>
                <td><span class="rvd-colegio"><?= $e($c['colegio']) ?></span><span class="rvd-sub">DANE <?= $e($c['dane']) ?></span></td>
                <td><?= $e($c['ciudad']) ?></td>
                <td><?= $e($c['zona']) ?></td>
                <td><?php if ($c['asesor']): ?><span class="rvd-asesor"><i class="bi bi-person-badge"></i> <?= $e($c['asesor']) ?></span><?php else: ?><span class="rvd-sub">Sin asesor asignado</span><?php endif; ?></td>
                <td data-order="<?= (int)$c['id_periodo'] ?>"><?= $e($c['periodo']) ?></td>
                <td style="text-align:center"><?= (int)$c['titulos'] ?></td>
                <td data-order="<?= $e($proxima ?: ($js ? '9' . $js[0]['fecha'] : '99')) ?>">
                  <?php if (!$js): ?><span class="rvd-sin"><i class="bi bi-exclamation-circle"></i> Sin fechas</span>
                  <?php else: foreach ($js as $j): $ts = strtotime($j['fecha']); ?>
                    <div><span class="rvd-fecha<?= $j['formato'] ? ' rvd-pasada' : '' ?>" title="<?= $j['formato'] ? 'Atendida · Formato venta directa No. ' . (int)$j['formato']['consecutivo'] : 'Pendiente: sin formato de venta directa' ?>"><i class="bi <?= $j['formato'] ? 'bi-check-circle-fill' : 'bi-clock' ?>"></i> <?= $dias[(int)date('w', $ts)] ?> <?= date('d/m/Y', $ts) ?> <small><?= $hora12($j['hi']) ?> – <?= $hora12($j['hf']) ?></small></span></div>
                  <?php endforeach; endif; ?>
                </td>
                <td>
                  <?php if ($c['vd_contacto_nombre']): ?>
                    <?= $e($c['vd_contacto_nombre']) ?>
                    <span class="rvd-sub"><i class="bi bi-phone"></i> <?= $e($c['vd_contacto_celular']) ?></span>
                    <?php if ($c['vd_contacto_correo']): ?><span class="rvd-sub"><i class="bi bi-envelope"></i> <?= $e($c['vd_contacto_correo']) ?></span><?php endif; ?>
                  <?php else: ?><span class="rvd-sub">Sin registrar</span><?php endif; ?>
                </td>
                <td>
                  <div class="rvd-acciones">
                    <a class="rvd-btn rvd-btn-ver" href="colegio.php?codigo=<?= urlencode((string)$c['codigo']) ?>&periodo=<?= (int)$c['id_periodo'] ?>&tab=adopciones" title="Ver la adopción del colegio"><i class="bi bi-eye"></i> Ver adopción</a>
                    <a class="rvd-btn rvd-btn-xls" href="php/adopcion_excel.php?cole=<?= (int)$c['id_colegio'] ?>&periodo=<?= (int)$c['id_periodo'] ?>" title="Descargar el informe de adopción en Excel"><i class="bi bi-file-earmark-excel"></i> Reporte de adopción</a>
                  </div>
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
<script src="src/ink-alerts.js"></script>
<script>
$(function () {
  var t = $('#rvd-table').DataTable({
    autoWidth: false, order: [[4, 'desc'], [0, 'asc']], columnDefs: [{ orderable: false, targets: [7, 8] }],
    language: {
      lengthMenu: 'Mostrar _MENU_ registros', zeroRecords: 'No se encontraron resultados', emptyTable: 'No hay colegios con el canal Venta directa - Eureka',
      info: 'Mostrando _START_ a _END_ de _TOTAL_ colegios', infoEmpty: 'Sin registros disponibles', infoFiltered: '(filtrado de _MAX_ colegios)',
      search: '', paginate: { first: '«', previous: '‹', next: '›', last: '»' }
    },
    initComplete: function () { $('.dataTables_filter').hide(); },
    drawCallback: function () { $('#rvd-count').text(this.api().page.info().recordsDisplay + ' colegios'); }
  });
  $('#rvd-search').on('keyup', function () { t.search(this.value).draw(); });
  $('#rvd-form').on('submit', function () { $('#rvd-search').prop('disabled', true); }); // la búsqueda no va en la URL
});
</script>
</body>
</html>
