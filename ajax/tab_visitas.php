<?php
require_once("../php/aut.php");
require_once('../conexion/bdd.php');

$id_colegio    = intval($_GET["colegio"]       ?? 0);
$periodo       = intval($_GET["periodo"]       ?? 0);
$id_calendario = intval($_GET["id_calendario"] ?? 0);
$anio_sel      = intval($_GET["anio_texto"]    ?? 0);

// Años con visitas reales para este colegio (para el dropdown)
$stmt_years = $bdd->prepare(
    "SELECT DISTINCT YEAR(start) AS anio FROM plan_trabajo
     WHERE id_colegio = ? AND start IS NOT NULL ORDER BY anio DESC"
);
$stmt_years->execute([$id_colegio]);
$years_list = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

// Año por defecto: el más reciente con visitas; si no hay ninguno, el año actual
if ($anio_sel === 0) {
    $anio_sel = !empty($years_list) ? intval($years_list[0]) : intval(date('Y'));
}

// Gestión y seguimiento (pedido por la usuaria 2026-10-16): ver includes/seguimientos_colegio.php.
// Va antes de la consulta de visitas porque esta trae la próxima visita registrada al ejecutarla.
require_once('../includes/seguimientos_colegio.php');
seg_asegurar_tabla($bdd);

$sql = "SELECT
            p.id,
            p.start            AS fecha_plan,
            p.resultado,
            p.otro_objetivo,
            p.otro_lugar,
            COALESCE(NULLIF(p.otro_objetivo,''), o.objetivo) AS objetivo_display,
            v.observaciones,
            v.efectiva,
            v.fecha            AS fecha_ejecutada,
            (SELECT sg.proxima_fecha FROM seguimientos_colegio sg WHERE sg.id_plan_trabajo = p.id ORDER BY sg.id DESC LIMIT 1) AS proxima_visita,
            z.zona,
            CONCAT(u.nombres,' ',u.apellidos) AS promotor,
            t.nombre AS prof_nombre, t.apellido AS prof_apellido, c.cargo AS prof_cargo
        FROM plan_trabajo p
        LEFT JOIN objetivos o ON o.id = p.id_objetivo
        LEFT JOIN visitas v   ON v.id_plan_trabajo = p.id
        LEFT JOIN usuarios u  ON u.id = p.id_promotor
        JOIN colegios col     ON col.id = p.id_colegio
        JOIN zonas z          ON z.codigo = col.cod_zona
        LEFT JOIN trabajadores_colegios t ON t.id = p.id_profesor
        LEFT JOIN cargos c    ON c.id = t.cargo
        WHERE p.id_colegio = :id_colegio
          AND YEAR(p.start)  = :anio_sel
        ORDER BY p.start DESC";

$stmt = $bdd->prepare($sql);
$stmt->execute([':id_colegio' => $id_colegio, ':anio_sel' => $anio_sel]);
$visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$seguimientos = seg_listar($bdd, $id_colegio);
$responsables = seg_responsables($bdd);
$asesor_col   = seg_asesor_colegio($bdd, $id_colegio);
$sub_activa   = ($_GET['sub'] ?? '') === 'seguimiento' ? 'seguimiento' : 'historial';
$hoy          = date('Y-m-d');
?>
<style>
.vis-table { width:100%; font-size:13px; border-collapse:collapse; }
.vis-table th { background:#f3f4f6; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; padding:10px 14px; border-bottom:2px solid #e5e7eb; white-space:nowrap; }
.vis-table td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.vis-table tr:last-child td { border-bottom:none; }
.vis-table tr:hover td { background:#fafafa; }
.vis-badge-ok  { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; background:#d1fae5; color:#059669; }
.vis-badge-no  { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; background:#f3f4f6; color:#6b7280; }
.vis-badge-ef  { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; background:#d1fae5; color:#059669; }
.vis-badge-nef { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; background:#fee2e2; color:#dc2626; }
.vis-empty { text-align:center; padding:50px 20px; color:#9ca3af; }
.vis-empty i { display:block; font-size:38px; margin-bottom:10px; color:#d1d5db; }
.vis-header { padding:14px 20px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; }
.vis-header-title { font-size:13px; font-weight:600; color:#374151; }
.vis-count { background:#eef0ff; color:#4361ee; font-size:11px; font-weight:700; padding:2px 9px; border-radius:12px; margin-left:8px; }
.vis-obs-preview { color:#6b7280; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; display:inline-block; vertical-align:middle; }

/* Modal detalle visita */
.vd-modal .modal-content  { border:none; border-radius:12px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.15); }
.vd-modal .modal-header   { background:#fff; padding:18px 24px 14px; border-bottom:1px solid #e9ecef; }
.vd-modal .modal-title    { font-size:16px; font-weight:700; color:#111827; margin:0 0 3px; }
.vd-modal .modal-subtitle { font-size:12.5px; color:#6b7280; margin:0; }
.vd-modal .close          { color:#9ca3af; opacity:1; text-shadow:none; font-size:1.3rem; margin-top:-4px; }
.vd-modal .close:hover    { color:#374151; }
.vd-modal .modal-body     { padding:22px 24px; background:#f9fafb; }
.vd-modal .modal-footer   { border-top:1px solid #e9ecef; padding:14px 24px; background:#fff; }
.vd-section               { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 20px; margin-bottom:14px; }
.vd-section:last-child    { margin-bottom:0; }
.vd-section-title         { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin-bottom:12px; }
.vd-row                   { display:flex; gap:24px; flex-wrap:wrap; }
.vd-field                 { flex:1; min-width:140px; }
.vd-field label           { font-size:11px; font-weight:600; color:#6b7280; display:block; margin-bottom:3px; }
.vd-field span            { font-size:13.5px; color:#111827; font-weight:500; }
.vd-obs-box               { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px 14px; font-size:13px; color:#374151; line-height:1.6; white-space:pre-wrap; word-break:break-word; min-height:60px; }
/* Gestión y seguimiento */
.vis-subtabs { display:flex; gap:6px; padding:12px 20px 0; border-bottom:1px solid #e5e7eb; background:#fff; }
.vis-subtab { border:none; background:none; padding:9px 14px; font-size:13px; font-weight:600; color:#6b7280; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; }
.vis-subtab:hover { color:#374151; }
.vis-subtab.activa { color:#4361ee; border-bottom-color:#4361ee; }
.seg-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.seg-badge.prog { background:#dbeafe; color:#1d4ed8; } .seg-badge.env { background:#d1fae5; color:#059669; }
.seg-badge.venc { background:#fef3c7; color:#b45309; } .seg-badge.err { background:#fee2e2; color:#dc2626; }
.seg-form label { font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
.seg-form .form-control { font-size:13px; border-radius:8px; }
.seg-ayuda { font-size:11.5px; color:#6b7280; margin-top:10px; }
</style>
<link rel="stylesheet" href="src/seg-calendario.css">

<div class="vis-subtabs">
  <button type="button" class="vis-subtab<?= $sub_activa === 'historial' ? ' activa' : '' ?>" data-panel="historial"><i class="bi bi-calendar-check"></i> Historial de visitas</button>
  <button type="button" class="vis-subtab<?= $sub_activa === 'seguimiento' ? ' activa' : '' ?>" data-panel="seguimiento"><i class="bi bi-journal-text"></i> Gestión y seguimiento <span class="vis-count"><?= count($seguimientos) ?></span></button>
</div>
<script>
$(document).off('click.vissub').on('click.vissub', '.vis-subtab', function () {
  var panel = $(this).data('panel');
  $('.vis-subtab').removeClass('activa'); $(this).addClass('activa');
  $('.vis-panel').addClass('d-none'); $('#vis-panel-' + panel).removeClass('d-none');
});
</script>

<div class="vis-panel<?= $sub_activa === 'historial' ? '' : ' d-none' ?>" id="vis-panel-historial">
<div class="vis-header">
  <span class="vis-header-title">
    <i class="bi bi-calendar-check text-primary"></i> Trazabilidad de visitas
    <span class="vis-count"><?= count($visitas) ?></span>
  </span>
  <?php if (!empty($years_list)): ?>
  <div style="display:flex;align-items:center;gap:8px">
    <label style="font-size:12px;color:#6b7280;margin:0">Año:</label>
    <select id="vis-periodo-filter" style="font-size:12px;padding:3px 8px;border:1px solid #d1d5db;border-radius:6px;color:#374151;background:#fff;cursor:pointer">
      <?php foreach ($years_list as $anio): ?>
        <option value="<?= $anio ?>" <?= ($anio == $anio_sel) ? 'selected' : '' ?>>
          <?= $anio ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var sel = document.getElementById('vis-periodo-filter');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var url = 'ajax/tab_visitas.php?colegio=<?= $id_colegio ?>&anio_texto=' + encodeURIComponent(this.value) + '&id_calendario=<?= $id_calendario ?>';
    var $tab = $('#visitas');
    $tab.html('<br><br><center style="font-size:30px;color:#E25906">Cargando...</center>');
    $tab.load(url);
  });
})();
</script>

<div class="table-responsive">
  <table class="vis-table">
    <thead>
      <tr>
        <th>Zona</th>
        <th>Fecha planificada</th>
        <th>Objetivo</th>
        <th>Resultado</th>
        <th>Promotor</th>
        <th>Observaciones</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($visitas)): ?>
      <tr>
        <td colspan="7" class="vis-empty">
          <i class="bi bi-calendar-x"></i>
          No hay visitas registradas para este colegio
        </td>
      </tr>
      <?php else: ?>
        <?php foreach ($visitas as $i => $v):
          $modal_id = 'vd_modal_' . $i;
          $fp = $v['fecha_plan'] ?? '';
          $fe = $v['fecha_ejecutada'] ?? '';
          $profesor_display = $v['prof_nombre']
            ? trim($v['prof_nombre'].' '.$v['prof_apellido']).' ('.$v['prof_cargo'].')'
            : 'Sin profesor asignado';
        ?>
        <tr>
          <td style="white-space:nowrap;color:#374151;font-weight:500">
            <?= htmlspecialchars($v['zona']) ?>
          </td>
          <td style="white-space:nowrap;color:#6b7280">
            <?= $fp ? date('d/m/Y H:i', strtotime($fp)) : '—' ?>
          </td>
          <td><?= $v['objetivo_display'] ? htmlspecialchars($v['objetivo_display']) : '<span style="color:#9ca3af">—</span>' ?></td>
          <td>
            <?php if ($v['resultado'] == 1): ?>
              <span class="vis-badge-ok"><i class="bi bi-check-circle-fill"></i> Ejecutada</span>
            <?php else: ?>
              <span class="vis-badge-no"><i class="bi bi-clock"></i> No ejecutada</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap"><?= htmlspecialchars($v['promotor'] ?? '—') ?></td>
          <td>
            <?php if (!empty($v['observaciones'])): ?>
              <span class="vis-obs-preview"><?= htmlspecialchars($v['observaciones']) ?></span>
            <?php else: ?>
              <span style="color:#9ca3af;font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-outline-primary btn-sm"
                    data-toggle="modal" data-target="#<?= $modal_id ?>"
                    style="font-size:12px;padding:3px 10px">
              <i class="bi bi-eye"></i> Ver detalle
            </button>
          </td>
        </tr>

        <!-- Modal detalle visita #<?= $i ?> -->
        <div class="modal fade vd-modal" id="<?= $modal_id ?>" tabindex="-1" role="dialog" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <h5 class="modal-title"><i class="bi bi-calendar-event"></i> Detalle de visita</h5>
                  <p class="modal-subtitle"><?= $fp ? date('d/m/Y H:i', strtotime($fp)) : '—' ?></p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
              </div>
              <div class="modal-body">

                <div class="vd-section">
                  <div class="vd-section-title">Información general</div>
                  <div class="vd-row">
                    <div class="vd-field">
                      <label>Zona</label>
                      <span><?= htmlspecialchars($v['zona']) ?></span>
                    </div>
                    <div class="vd-field">
                      <label>Promotor</label>
                      <span><?= htmlspecialchars($v['promotor'] ?? '—') ?></span>
                    </div>
                    <div class="vd-field">
                      <label>Profesor</label>
                      <span><?= htmlspecialchars($profesor_display) ?></span>
                    </div>
                  </div>
                  <div class="vd-row mt-3">
                    <div class="vd-field">
                      <label>Fecha planificada</label>
                      <span><?= $fp ? date('d/m/Y H:i', strtotime($fp)) : '—' ?></span>
                    </div>
                    <div class="vd-field">
                      <label>Fecha ejecutada</label>
                      <span><?= $fe ? date('d/m/Y H:i', strtotime($fe)) : '—' ?></span>
                    </div>
                  </div>
                </div>

                <div class="vd-section">
                  <div class="vd-section-title">Objetivo y resultado</div>
                  <div class="vd-row">
                    <div class="vd-field">
                      <label>Objetivo</label>
                      <span><?= $v['objetivo_display'] ? htmlspecialchars($v['objetivo_display']) : '—' ?></span>
                    </div>
                  </div>
                  <div class="vd-row mt-3">
                    <div class="vd-field">
                      <label>Resultado</label>
                      <span>
                        <?php if ($v['resultado'] == 1): ?>
                          <span class="vis-badge-ok"><i class="bi bi-check-circle-fill"></i> Ejecutada</span>
                        <?php else: ?>
                          <span class="vis-badge-no"><i class="bi bi-clock"></i> No ejecutada</span>
                        <?php endif; ?>
                      </span>
                    </div>
                    <?php if ($v['resultado'] == 1): ?>
                    <div class="vd-field">
                      <label>¿Efectiva?</label>
                      <span>
                        <?php if ($v['efectiva'] == 1): ?>
                          <span class="vis-badge-ef"><i class="bi bi-check2"></i> Sí</span>
                        <?php else: ?>
                          <span class="vis-badge-nef"><i class="bi bi-x"></i> No</span>
                        <?php endif; ?>
                      </span>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!empty($v['proxima_visita'])): ?>
                <div class="vd-section">
                  <div class="vd-section-title">Próxima visita</div>
                  <div class="vd-row">
                    <div class="vd-field">
                      <label>Fecha</label>
                      <span><?= date('d/m/Y', strtotime($v['proxima_visita'])) ?></span>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

                <div class="vd-section">
                  <div class="vd-section-title">Observaciones</div>
                  <div class="vd-obs-box">
                    <?php if (!empty($v['observaciones'])): ?>
                      <?= htmlspecialchars($v['observaciones']) ?>
                    <?php else: ?>
                      <span style="color:#9ca3af">Sin observaciones registradas</span>
                    <?php endif; ?>
                  </div>
                </div>

              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-dismiss="modal">Cerrar</button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div><!-- /vis-panel-historial -->

<div class="vis-panel<?= $sub_activa === 'seguimiento' ? '' : ' d-none' ?>" id="vis-panel-seguimiento">
  <div class="vis-header">
    <span class="vis-header-title">
      <i class="bi bi-journal-text text-primary"></i> Gestión y seguimiento
      <span class="vis-count"><?= count($seguimientos) ?></span>
    </span>
    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#seg_modal_nuevo" style="font-size:12px;padding:4px 12px">
      <i class="bi bi-plus-circle"></i> Nuevo seguimiento
    </button>
  </div>

  <div class="table-responsive">
    <table class="vis-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Seguimiento con</th>
          <th>Observación</th>
          <th>Próximo seguimiento</th>
          <th>Recordatorio</th>
          <th>Registrado por</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($seguimientos)): ?>
        <tr><td colspan="7" class="vis-empty"><i class="bi bi-journal-x"></i>No hay gestiones registradas para este colegio</td></tr>
        <?php else: foreach ($seguimientos as $sg):
          $sg_modal  = 'seg_modal_' . (int)$sg['id'];
          $tipo_prox = SEG_TIPOS_PROXIMO[$sg['proximo_tipo']] ?? 'Seguimiento';
          // Estado del recordatorio (se envía un día antes de la próxima fecha).
          if (!$sg['proxima_fecha']) {
            $rec = '<span style="color:#9ca3af;font-size:12px">—</span>';
          } elseif ($sg['recordatorio_enviado_at']) {
            $rec = '<span class="seg-badge env" title="' . htmlspecialchars('Enviado a ' . ($sg['recordatorio_para'] ?? '')) . '"><i class="bi bi-envelope-check"></i> Enviado ' . date('d/m/Y', strtotime($sg['recordatorio_enviado_at'])) . '</span>';
          } elseif ($sg['proxima_fecha'] < $hoy) {
            $rec = '<span class="seg-badge venc"' . ($sg['recordatorio_error'] ? ' title="' . htmlspecialchars($sg['recordatorio_error']) . '"' : '') . '><i class="bi bi-exclamation-circle"></i> No se envió</span>';
          } elseif ($sg['recordatorio_error']) {
            $rec = '<span class="seg-badge err" title="' . htmlspecialchars($sg['recordatorio_error']) . '"><i class="bi bi-x-circle"></i> Error al enviar</span>';
          } else {
            $rec = '<span class="seg-badge prog" title="Se enviará por correo este día"><i class="bi bi-clock"></i> ' . date('d/m/Y', strtotime($sg['proxima_fecha'] . ' -1 day')) . '</span>';
          }
        ?>
        <tr>
          <td style="white-space:nowrap;color:#374151;font-weight:500"><?= date('d/m/Y', strtotime($sg['fecha'])) ?></td>
          <td style="white-space:nowrap"><?= htmlspecialchars($sg['responsable'] ?: '—') ?></td>
          <td><span class="vis-obs-preview"><?= htmlspecialchars($sg['observacion']) ?></span></td>
          <td style="white-space:nowrap">
            <?php if ($sg['proxima_fecha']): ?>
              <?= date('d/m/Y', strtotime($sg['proxima_fecha'])) ?> <span style="color:#6b7280;font-size:11.5px">(<?= htmlspecialchars($tipo_prox) ?>)</span>
            <?php else: ?><span style="color:#9ca3af;font-size:12px">—</span><?php endif; ?>
          </td>
          <td><?= $rec ?></td>
          <td style="white-space:nowrap;color:#6b7280"><?= htmlspecialchars($sg['registrado_por'] ?: '—') ?></td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#<?= $sg_modal ?>" style="font-size:12px;padding:3px 10px">
              <i class="bi bi-eye"></i> Ver detalle
            </button>
          </td>
        </tr>

        <div class="modal fade vd-modal" id="<?= $sg_modal ?>" tabindex="-1" role="dialog" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <h5 class="modal-title"><i class="bi bi-journal-text"></i> Detalle de la gestión</h5>
                  <p class="modal-subtitle"><?= date('d/m/Y', strtotime($sg['fecha'])) ?></p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
              </div>
              <div class="modal-body">
                <div class="vd-section">
                  <div class="vd-section-title">Gestión</div>
                  <div class="vd-row">
                    <div class="vd-field"><label>Fecha</label><span><?= date('d/m/Y', strtotime($sg['fecha'])) ?></span></div>
                    <div class="vd-field"><label>Seguimiento con</label><span><?= htmlspecialchars($sg['responsable'] ?: '—') ?></span></div>
                  </div>
                  <div class="vd-row mt-3">
                    <div class="vd-field"><label>Registrado por</label><span><?= htmlspecialchars($sg['registrado_por'] ?: '—') ?></span></div>
                    <div class="vd-field"><label>Registrado el</label><span><?= date('d/m/Y H:i', strtotime($sg['created_at'])) ?></span></div>
                  </div>
                </div>
                <div class="vd-section">
                  <div class="vd-section-title">Próximo <?= htmlspecialchars(mb_strtolower($tipo_prox)) ?></div>
                  <div class="vd-row">
                    <div class="vd-field"><label>Fecha</label><span><?= $sg['proxima_fecha'] ? date('d/m/Y', strtotime($sg['proxima_fecha'])) : 'Sin programar' ?></span></div>
                    <div class="vd-field"><label>Recordatorio por correo</label><span><?= $rec ?></span></div>
                  </div>
                  <?php if ($sg['recordatorio_enviado_at'] && $sg['recordatorio_para']): ?>
                  <div class="vd-row mt-3"><div class="vd-field"><label>Enviado a</label><span style="font-size:12.5px"><?= htmlspecialchars($sg['recordatorio_para']) ?></span></div></div>
                  <?php endif; ?>
                </div>
                <div class="vd-section">
                  <div class="vd-section-title">Observación</div>
                  <div class="vd-obs-box"><?= htmlspecialchars($sg['observacion']) ?></div>
                </div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-dismiss="modal">Cerrar</button></div>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div><!-- /vis-panel-seguimiento -->

<!-- Modal nuevo seguimiento -->
<div class="modal fade vd-modal" id="seg_modal_nuevo" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:560px">
    <div class="modal-content">
      <form id="seg_form" class="seg-form" autocomplete="off">
        <input type="hidden" name="id_colegio" value="<?= $id_colegio ?>">
        <div class="modal-header">
          <div>
            <h5 class="modal-title"><i class="bi bi-journal-plus"></i> Nuevo seguimiento</h5>
            <p class="modal-subtitle">Registra la gestión y programa el próximo seguimiento o visita</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
        </div>
        <div class="modal-body">
          <div class="vd-section">
            <div class="row">
              <div class="col-sm-6 form-group">
                <label for="seg_fecha_txt">Fecha <span style="color:#dc2626">*</span></label>
                <div class="seg-fecha-wrap">
                  <input type="text" class="form-control" id="seg_fecha_txt" placeholder="dd/mm/aaaa" inputmode="none" required>
                  <i class="bi bi-calendar3"></i>
                </div>
                <input type="hidden" name="fecha" id="seg_fecha" value="<?= $hoy ?>">
              </div>
              <div class="col-sm-6 form-group">
                <label for="seg_responsable">Seguimiento con <span style="color:#dc2626">*</span></label>
                <select class="form-control" name="id_responsable" id="seg_responsable" required>
                  <option value="">Elige...</option>
                  <?php foreach ($responsables as $id_u => $nom_u): ?>
                    <option value="<?= (int)$id_u ?>"<?= (int)$id_u === (int)($_SESSION['id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars($nom_u) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label for="seg_observacion">Observación <span style="color:#dc2626">*</span></label>
              <textarea class="form-control" name="observacion" id="seg_observacion" rows="4" maxlength="<?= SEG_OBSERVACION_MAX ?>" required placeholder="¿Qué se gestionó? Acuerdos, pendientes, con quién se habló..."></textarea>
            </div>
            <div class="row">
              <div class="col-sm-6 form-group mb-0">
                <?php $seg_prox_opc = seg_proxima_opcional($_SESSION['id'] ?? 0, $_SESSION['tipo'] ?? 0); ?>
                <label for="seg_proxima_txt">Próximo seguimiento o visita<?= $seg_prox_opc ? '' : ' <span style="color:#dc2626">*</span>' ?></label>
                <div class="seg-fecha-wrap">
                  <input type="text" class="form-control" id="seg_proxima_txt" placeholder="dd/mm/aaaa" inputmode="none"<?= $seg_prox_opc ? '' : ' required' ?>>
                  <i class="bi bi-calendar3"></i>
                </div>
                <input type="hidden" name="proxima_fecha" id="seg_proxima" value="">
              </div>
              <div class="col-sm-6 form-group mb-0">
                <label for="seg_tipo">Tipo</label>
                <select class="form-control" name="proximo_tipo" id="seg_tipo">
                  <?php foreach (SEG_TIPOS_PROXIMO as $k_t => $t_t): ?><option value="<?= $k_t ?>"><?= $t_t ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="seg-ayuda"><i class="bi bi-envelope"></i>
              Un día antes se enviará un recordatorio por correo al asesor encargado del colegio<?= $asesor_col ? ' (' . htmlspecialchars($asesor_col['nombre']) . ')' : '' ?> y a la persona de «Seguimiento con».
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm" id="seg_guardar"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="src/seg-calendario.js"></script>
<script>
(function () {
  // Tras guardar se recarga la pestaña Visitas en "Gestión y seguimiento".
  function recargar() {
    var url = 'ajax/tab_visitas.php?colegio=<?= $id_colegio ?>&anio_texto=<?= $anio_sel ?>&id_calendario=<?= $id_calendario ?>&sub=seguimiento';
    $('#seg_fecha_txt, #seg_proxima_txt').each(function () { var dp = $(this).data('datepicker'); if (dp) dp.destroy(); });
    $('.modal-backdrop').remove(); $('body').removeClass('modal-open').css('padding-right', '');
    $('#visitas').html('<br><br><center style="font-size:30px;color:#E25906">Cargando...</center>').load(url);
  }

  // Calendarios (src/seg-calendario.js): se muestra dd/mm/aaaa y se envía aaaa-mm-dd en el campo oculto.
  var hoy = segFechaDesdeHoy(0);
  var dpFecha = segCalendario('#seg_fecha_txt', '#seg_fecha', { maxDate: hoy, todayButton: hoy });
  if (dpFecha) dpFecha.selectDate(hoy);
  segCalendario('#seg_proxima_txt', '#seg_proxima', { minDate: segFechaDesdeHoy(1), clearButton: true });

  $('#seg_form').on('submit', function (e) {
    e.preventDefault();
    if (!$('#seg_fecha').val()) { aviso('Elige la fecha de la gestión.', 'warn'); return; }
    if (!$('#seg_proxima').val() && !<?= $seg_prox_opc ? 'true' : 'false' ?>) { aviso('Elige la fecha del próximo seguimiento o visita.', 'warn'); return; }
    var $btn = $('#seg_guardar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
    $.post('php/seguimiento_colegio_guardar.php', $(this).serialize(), null, 'json')
      .done(function (r) {
        if (!r.ok) { aviso(r.error || 'No se pudo guardar el seguimiento.', 'error'); return; }
        aviso('Seguimiento registrado.' + (r.recordatorio ? ' ' + r.recordatorio : ''), 'ok');
        $('#seg_modal_nuevo').modal('hide');
        recargar();
      })
      .fail(function (x) { aviso((x.responseJSON && x.responseJSON.error) || 'No se pudo guardar el seguimiento. Intenta de nuevo.', 'error'); })
      .always(function () { $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Guardar'); });
  });
  function aviso(msg, tipo) { if (typeof inkToast === 'function') inkToast(msg, tipo); else alert(msg); }
})();
</script>
