<?php
require_once("../php/aut.php");
require_once('../conexion/bdd.php');

$id_colegio    = intval($_GET["colegio"] ?? 0);
$periodo       = intval($_GET["periodo"] ?? 0);
$codigo        = htmlspecialchars($_GET["codigo"] ?? '');
$id_calendario = intval($_GET["id_calendario"] ?? 0);

$fecha_desde = trim($_GET["fecha_desde"] ?? '');
$fecha_hasta = trim($_GET["fecha_hasta"] ?? '');
$modulo_f    = trim($_GET["modulo"] ?? '');

$where  = "WHERE h.id_colegio = :id_colegio";
$params = [':id_colegio' => $id_colegio];

if (!empty($fecha_desde)) {
    $where .= " AND DATE(h.fecha) >= :fecha_desde";
    $params[':fecha_desde'] = $fecha_desde;
}
if (!empty($fecha_hasta)) {
    $where .= " AND DATE(h.fecha) <= :fecha_hasta";
    $params[':fecha_hasta'] = $fecha_hasta;
}
if (!empty($modulo_f)) {
    $where .= " AND h.modulo = :modulo";
    $params[':modulo'] = $modulo_f;
}

$sql = "SELECT h.id, h.modulo, h.campo, h.valor_anterior, h.valor_nuevo, h.fecha,
               CONCAT(u.nombres, ' ', u.apellidos) AS nombre_usuario
        FROM historial_colegios h
        JOIN usuarios u ON u.id = h.id_usuario
        $where
        ORDER BY h.fecha DESC
        LIMIT 500";

$stmt = $bdd->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
.hist-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:14px 20px 12px;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.hist-toolbar .form-group{margin:0;}
.hist-toolbar label{font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;}
.hist-toolbar .form-control{font-size:13px;height:34px;}
.hist-table{width:100%;font-size:13px;border-collapse:collapse;}
.hist-table th{background:#f3f4f6;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;padding:10px 14px;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
.hist-table td{padding:10px 14px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
.hist-table tr:last-child td{border-bottom:none;}
.hist-table tr:hover td{background:#fafafa;}
.hist-badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:#eff6ff;color:#2563eb;}
.hist-val-old{color:#ef4444;background:#fef2f2;padding:2px 7px;border-radius:4px;font-size:12px;display:inline-block;}
.hist-val-new{color:#16a34a;background:#f0fdf4;padding:2px 7px;border-radius:4px;font-size:12px;display:inline-block;}
.hist-empty{text-align:center;padding:50px 20px;color:#9ca3af;}
.hist-empty i{display:block;font-size:38px;margin-bottom:10px;color:#d1d5db;}
</style>

<div class="hist-toolbar">
  <div class="form-group">
    <label>Desde</label>
    <input type="date" class="form-control" id="hist_fecha_desde" value="<?= htmlspecialchars($fecha_desde) ?>">
  </div>
  <div class="form-group">
    <label>Hasta</label>
    <input type="date" class="form-control" id="hist_fecha_hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
  </div>
  <div class="form-group">
    <label>Módulo</label>
    <select class="form-control" id="hist_modulo" style="min-width:190px">
      <option value="">Todos los módulos</option>
      <?php
      $modulos_lista = ['Información básica','Información de contacto','Población','Presupuesto','Adopciones','Venta directa','Atenciones al cliente','Adjuntos'];
      foreach ($modulos_lista as $m) {
          $sel = ($modulo_f === $m) ? 'selected' : '';
          echo '<option value="'.htmlspecialchars($m).'" '.$sel.'>'.htmlspecialchars($m).'</option>';
      }
      ?>
    </select>
  </div>
  <div class="form-group" style="display:flex;gap:6px">
    <button class="btn btn-primary btn-sm" id="btn_hist_filtrar" style="height:34px">
      <i class="bi bi-search"></i> Filtrar
    </button>
    <button class="btn btn-outline-secondary btn-sm" id="btn_hist_limpiar" style="height:34px">
      <i class="bi bi-x-circle"></i> Limpiar
    </button>
  </div>
</div>

<div class="table-responsive">
  <table class="hist-table">
    <thead>
      <tr>
        <th>Fecha y hora</th>
        <th>Usuario</th>
        <th>Módulo</th>
        <th>Campo</th>
        <th>Valor anterior</th>
        <th>Valor nuevo</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($registros)): ?>
      <tr>
        <td colspan="6" class="hist-empty">
          <i class="bi bi-clock-history"></i>
          <?= (!empty($fecha_desde) || !empty($fecha_hasta) || !empty($modulo_f))
              ? 'No hay registros para los filtros seleccionados'
              : 'Aún no hay registros de cambios para este colegio' ?>
        </td>
      </tr>
      <?php else: ?>
        <?php foreach ($registros as $r): ?>
        <tr>
          <td style="white-space:nowrap;color:#6b7280"><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></td>
          <td><?= htmlspecialchars($r['nombre_usuario']) ?></td>
          <td><span class="hist-badge"><?= htmlspecialchars($r['modulo']) ?></span></td>
          <td><?= htmlspecialchars($r['campo']) ?></td>
          <td>
            <?php if ($r['valor_anterior'] !== '' && $r['valor_anterior'] !== null): ?>
              <span class="hist-val-old"><?= htmlspecialchars($r['valor_anterior']) ?></span>
            <?php else: ?>
              <span style="color:#9ca3af;font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['valor_nuevo'] !== '' && $r['valor_nuevo'] !== null): ?>
              <span class="hist-val-new"><?= htmlspecialchars($r['valor_nuevo']) ?></span>
            <?php else: ?>
              <span style="color:#9ca3af;font-size:12px">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
(function () {
  var base  = 'ajax/tab_historial.php';
  var fixed = {
    colegio:       <?= $id_colegio ?>,
    periodo:       <?= $periodo ?>,
    codigo:        <?= json_encode($codigo) ?>,
    id_calendario: <?= $id_calendario ?>
  };

  function reloadHist() {
    var p = $.extend({}, fixed, {
      fecha_desde: $('#hist_fecha_desde').val(),
      fecha_hasta: $('#hist_fecha_hasta').val(),
      modulo:      $('#hist_modulo').val()
    });
    $('#historial').load(base + '?' + $.param(p));
  }

  $('#btn_hist_filtrar').on('click', reloadHist);

  $('#btn_hist_limpiar').on('click', function () {
    $('#hist_fecha_desde').val('');
    $('#hist_fecha_hasta').val('');
    $('#hist_modulo').val('');
    reloadHist();
  });
})();
</script>
