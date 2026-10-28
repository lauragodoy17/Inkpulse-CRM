<?php
  require_once("../php/aut.php");
  include("../conexion/bdd.php");
  require_once("../includes/mercado_editorial_datos.php");

  $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

  $colegio_m = mercado_colegio($bdd, (int)$_GET['colegio']);
  $periodo_m = mercado_periodo($bdd, (int)$_GET['periodo']);
  if (!$colegio_m || !$periodo_m) {
    echo '<div class="alert alert-warning m-4">No se encontró el colegio o el periodo.</div>';
    exit;
  }

  $puede_editar = mercado_puede_editar($colegio_m);
  $abierto      = mercado_periodo_abierto($periodo_m);
  $filas_m      = mercado_filas($bdd, $colegio_m['id'], $periodo_m);

  $editoriales_m = $bdd->query("SELECT id, editorial FROM editoriales WHERE act=1 ORDER BY editorial")->fetchAll(PDO::FETCH_ASSOC);
  $materias_m    = $bdd->query("SELECT id, materia FROM materias ORDER BY materia")->fetchAll(PDO::FETCH_ASSOC);
  $grados_m      = $bdd->query("SELECT id, grado FROM grados WHERE id NOT IN (15,16) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $tipos_m       = $bdd->query("SELECT id, tipo FROM tipos_libro WHERE id > 0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

  $total_venta = array_sum(array_column($filas_m, 'venta'));
  $total_ejemplares = array_sum(array_column($filas_m, 'ejemplares'));
  $n_editoriales = count(array_unique(array_column($filas_m, 'editorial')));

  $opciones = function ($lista, $campo) use ($h) {
    $html = '<option value="">Seleccione</option>';
    foreach ($lista as $o) $html .= '<option value="'.(int)$o['id'].'">'.$h($o[$campo]).'</option>';
    return $html;
  };
?>

<style>
  .mer-wrap { padding: 24px; }
  .mer-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
  .mer-title { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0 0 2px 0; }
  .mer-title i { color:#6c63ff; margin-right:6px; }
  .mer-subtitle { font-size:0.82rem; color:#718096; margin:0; }
  .mer-actions { display:flex; gap:8px; flex-wrap:wrap; }

  .mer-aviso { background:#fff3cd; border-left:4px solid #ffc107; border-radius:6px; padding:10px 14px; font-size:0.84rem; color:#856404; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

  .mer-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:14px; margin-bottom:22px; }
  .mer-card { background:#fff; border-radius:10px; padding:16px 18px; box-shadow:0 1px 6px rgba(15,23,42,.08); display:flex; align-items:center; gap:14px; }
  .mer-card-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
  .mer-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
  .mer-card-icon.purple { background:#ede9fe; color:#6d28d9; }
  .mer-card-icon.orange { background:#ffedd5; color:#c2410c; }
  .mer-card-icon.green  { background:#dcfce7; color:#15803d; }
  .mer-card-label { font-size:0.73rem; color:#64748b; margin:0 0 2px 0; font-weight:600; letter-spacing:.03em; text-transform:uppercase; }
  .mer-card-val   { font-size:1.25rem; font-weight:800; color:#0f172a; margin:0; }

  /* ── Modal añadir libros: mismas reglas que ajax/tab_presup.php ── */
  .lb-modal .modal-content  { border:none; border-radius:12px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.15); }
  .lb-modal .modal-header   { background:#fff; padding:18px 24px 14px; border-bottom:1px solid #e9ecef; }
  .lb-modal .modal-title    { font-size:16px; font-weight:700; color:#111827; margin:0 0 3px; }
  .lb-modal .modal-subtitle { font-size:12.5px; color:#6b7280; margin:0; }
  .lb-modal .close          { color:#9ca3af; opacity:1; text-shadow:none; font-size:1.3rem; margin-top:-4px; }
  .lb-modal .close:hover    { color:#374151; }
  .lb-modal .modal-body     { padding:20px 24px; background:#f9fafb; max-height:65vh; overflow-y:auto; }
  .lb-modal .modal-footer   { border-top:1px solid #e9ecef; padding:14px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center; }
  .lb-book-item { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:10px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
  .lb-book-num { font-size:12px; font-weight:700; color:#4f46e5; margin:0 0 14px; display:flex; align-items:center; gap:5px; }
  .lb-book-num::before { content:''; display:inline-block; width:3px; height:14px; background:#4f46e5; border-radius:2px; }
  .lb-modal .form-group { margin-bottom:0; }
  .lb-modal .form-group label { font-size:12px; font-weight:600; color:#374151; margin-bottom:5px; display:block; }
  .lb-modal .form-control { border-radius:7px; font-size:13px; border:1px solid #d1d5db; padding:7px 10px; background:#fff; color:#111827; transition:border-color .15s, box-shadow .15s; }
  .lb-modal .form-control:focus { border-color:#4f46e5; background:#fff; box-shadow:0 0 0 3px rgba(79,70,229,.1); outline:none; }
  .lb-req { color:#ef4444; }
  .lb-add-btn { display:inline-flex; align-items:center; gap:5px; color:#4f46e5; font-size:13px; font-weight:600; cursor:pointer; border:none; background:none; padding:0; text-decoration:none; }
  .lb-add-btn:hover { text-decoration:underline; color:#4f46e5; }
  .lb-modal .btn-primary { background:#4f46e5; border-color:#4f46e5; border-radius:8px; padding:8px 22px; font-weight:600; font-size:13.5px; }
  .lb-modal .btn-primary:hover { background:#4338ca; border-color:#4338ca; }

  .mer-item-head { display:flex; justify-content:space-between; align-items:flex-start; }
  .mer-btn-quitar { border:none; background:none; color:#9ca3af; padding:0 4px; margin-top:-4px; font-size:0.95rem; }
  .mer-btn-quitar:hover { color:#dc2626; }
  .lb-modal .select2-container { width:100% !important; }
  .lb-modal .select2-container .select2-selection--single { height:calc(1.5em + 14px + 2px); border:1px solid #d1d5db; border-radius:7px; }
  .lb-modal .select2-container .select2-selection__rendered { line-height:calc(1.5em + 14px); font-size:13px; padding-left:10px; }
  .lb-modal .select2-container .select2-selection__arrow { height:100%; }

  #tabla-mer { border-collapse:separate; border-spacing:0; width:100%; font-size:0.82rem; border-radius:10px; overflow:hidden; box-shadow:0 1px 8px rgba(15,23,42,.09); }
  #tabla-mer thead th { background:#f8fafc; color:#374151; font-weight:600; padding:9px 10px; white-space:nowrap; border:none; border-bottom:2px solid #e2e8f0; font-size:0.78rem; letter-spacing:.03em; }
  #tabla-mer tbody tr { background:#fff; transition:background .12s; }
  #tabla-mer tbody tr:hover { background:#f1f5fd; }
  #tabla-mer tbody td { padding:6px 9px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
  #tabla-mer .num { text-align:right; white-space:nowrap; }
  #tabla-mer .cen { text-align:center; }
  #tabla-mer tfoot td { padding:9px; font-weight:700; background:#f0f4f8; border-top:2px solid #e2e8f0; }
  .mer-libro { font-weight:600; color:#1e293b; }
  .mer-libro small { display:block; font-weight:400; color:#94a3b8; }
  .mer-badge { display:inline-block; font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:20px; background:#eef2ff; color:#4338ca; }
  .mer-input { width:64px; border:1px solid #cbd5e0; border-radius:5px; padding:4px 5px; text-align:right; font-size:0.82rem; outline:none; transition:border-color .15s, box-shadow .15s; }
  .mer-input.ancho { width:90px; }
  .mer-input:focus { border-color:#667eea; box-shadow:0 0 0 2px rgba(102,126,234,.18); }
  .mer-btn-del { border:none; background:none; color:#94a3b8; padding:2px 6px; border-radius:5px; }
  .mer-btn-del:hover { color:#dc2626; background:#fef2f2; }
  .mer-vacio { text-align:center; color:#94a3b8; padding:28px !important; }
  .mer-footer { display:flex; justify-content:center; margin-top:20px; }
</style>

<div class="mer-wrap">

  <div class="mer-header">
    <div>
      <h5 class="mer-title"><i class="bi bi-shop"></i> Mercado editorial</h5>
      <p class="mer-subtitle">Títulos de otras editoriales que usa el colegio en el periodo <?= $h($periodo_m['periodo']) ?> y su venta potencial</p>
    </div>
    <div class="mer-actions">
      <?php if ($puede_editar): ?>
      <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal_mercado">
        <i class="bi bi-plus-circle"></i> Añadir libros
      </a>
      <?php endif; ?>
      <a class="btn btn-success btn-sm" href="php/mercado_excel.php?cole=<?= (int)$colegio_m['id'] ?>&periodo=<?= (int)$periodo_m['id'] ?>">
        <i class="bi bi-file-earmark-excel"></i> Exportar Excel
      </a>
    </div>
  </div>

  <?php if ($puede_editar && !$abierto): ?>
  <div class="mer-aviso">
    <i class="bi bi-lock-fill"></i>
    El periodo cerró el <?= $h($periodo_m['f_cierre']) ?>: puedes añadir títulos, pero ya no se pueden modificar tasas, precios ni descuentos.
  </div>
  <?php endif; ?>

  <div class="mer-cards">
    <div class="mer-card">
      <div class="mer-card-icon blue"><i class="bi bi-book"></i></div>
      <div><p class="mer-card-label">Títulos</p><p class="mer-card-val"><?= count($filas_m) ?></p></div>
    </div>
    <div class="mer-card">
      <div class="mer-card-icon purple"><i class="bi bi-building"></i></div>
      <div><p class="mer-card-label">Editoriales</p><p class="mer-card-val"><?= $n_editoriales ?></p></div>
    </div>
    <div class="mer-card">
      <div class="mer-card-icon orange"><i class="bi bi-stack"></i></div>
      <div><p class="mer-card-label">Ejemplares potenciales</p><p class="mer-card-val" id="mer-card-ejemplares"><?= number_format($total_ejemplares, 0, ',', '.') ?></p></div>
    </div>
    <div class="mer-card">
      <div class="mer-card-icon green"><i class="bi bi-cash-stack"></i></div>
      <div><p class="mer-card-label">Venta potencial</p><p class="mer-card-val" id="mer-card-venta">$<?= number_format($total_venta, 0, ',', '.') ?></p></div>
    </div>
  </div>


  <?php $editable = $puede_editar && $abierto; ?>
  <form action="php/modificar_mercado.php" method="POST" id="form-mer-tabla">
    <div class="table-responsive">
      <table id="tabla-mer">
        <thead>
          <tr>
            <th>Editorial</th>
            <th>Título</th>
            <th>Materia</th>
            <th>Grado</th>
            <th class="cen">Alumnos</th>
            <th class="cen">Tasa compra</th>
            <th class="num">PVP</th>
            <th class="cen">Descuento</th>
            <th class="num">Precio neto</th>
            <th class="cen">Ejemplares</th>
            <th class="num">Venta potencial</th>
            <?php if ($puede_editar): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$filas_m): ?>
          <tr><td colspan="12" class="mer-vacio"><i class="bi bi-inbox"></i> Aún no hay títulos registrados para este colegio en el periodo.</td></tr>
          <?php endif; ?>
          <?php foreach ($filas_m as $f): $id = (int)$f['id']; ?>
          <tr class="mer-fila" data-alumnos="<?= $f['alumnos'] ?>">
            <td><?= $h($f['editorial']) ?></td>
            <td class="mer-libro"><?= $h($f['libro']) ?>
              <small><span class="mer-badge"><?= $h($f['tipo']) ?></span><?= $f['vigencia'] !== '' ? ' · Vigencia: '.$h($f['vigencia']) : '' ?></small>
            </td>
            <td><?= $h($f['materia']) ?></td>
            <td><?= $h($f['grado']) ?></td>
            <td class="cen"><?= $f['alumnos'] ?></td>
            <?php if ($editable): ?>
            <td class="cen"><input type="text" class="mer-input mer-tasa" name="items[<?= $id ?>][tasa]" value="<?= $f['tasa'] + 0 ?>" inputmode="decimal"> %</td>
            <td class="num"><input type="text" class="mer-input ancho mer-precio" name="items[<?= $id ?>][precio]" value="<?= $f['precio'] + 0 ?>" inputmode="numeric"></td>
            <td class="cen"><input type="text" class="mer-input mer-desc" name="items[<?= $id ?>][descuento]" value="<?= $f['desc'] + 0 ?>" inputmode="decimal"> %</td>
            <?php else: ?>
            <td class="cen"><?= $f['tasa'] + 0 ?> %</td>
            <td class="num">$<?= number_format($f['precio'], 0, ',', '.') ?></td>
            <td class="cen"><?= $f['desc'] + 0 ?> %</td>
            <?php endif; ?>
            <td class="num mer-neto">$<?= number_format($f['neto'], 0, ',', '.') ?></td>
            <td class="cen mer-ejemplares"><?= $f['ejemplares'] ?></td>
            <td class="num mer-venta" data-venta="<?= $f['venta'] ?>">$<?= number_format($f['venta'], 0, ',', '.') ?></td>
            <?php if ($puede_editar): ?>
            <td class="cen"><button type="button" class="mer-btn-del" data-id="<?= $id ?>" data-libro="<?= $h($f['libro']) ?>" title="Eliminar"><i class="bi bi-trash"></i></button></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($filas_m): ?>
        <tfoot>
          <tr>
            <td colspan="9">Total</td>
            <td class="cen" id="mer-total-ejemplares"><?= number_format($total_ejemplares, 0, ',', '.') ?></td>
            <td class="num" id="mer-total-venta">$<?= number_format($total_venta, 0, ',', '.') ?></td>
            <?php if ($puede_editar): ?><td></td><?php endif; ?>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <input type="hidden" name="id_colegio" value="<?= (int)$colegio_m['id'] ?>">
    <input type="hidden" name="periodo"    value="<?= (int)$periodo_m['id'] ?>">

    <?php if ($editable && $filas_m): ?>
    <div class="mer-footer">
      <button type="submit" class="btn btn-primary px-5"><i class="bi bi-floppy"></i> Guardar cambios</button>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($puede_editar): ?>
  <!-- Modal añadir libros (mismo diseño que el de ajax/tab_presup.php) -->
  <div class="modal fade lb-modal" id="modal_mercado" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">

        <div class="modal-header">
          <div>
            <h5 class="modal-title">Añadir libros</h5>
            <p class="modal-subtitle">Completa la información del libro de otra editorial que quieres añadir al mercado editorial</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
        </div>

        <form action="php/mercado_editorial.php" method="POST" id="form-mer-nuevo">
        <div class="modal-body">
          <div id="mer-nuevos">
            <div class="lb-book-item mer-nuevo-item">
              <div class="mer-item-head">
                <p class="lb-book-num">Libro #<span class="mer-item-num">1</span></p>
                <button type="button" class="mer-btn-quitar d-none" title="Quitar libro"><i class="bi bi-x-lg"></i></button>
              </div>
              <div class="row">
                <div class="col-lg-3 col-6 mb-3">
                  <div class="form-group">
                    <label>Editorial <span class="lb-req">*</span></label>
                    <select name="editorial[]" class="form-control mer-editorial" required><?= $opciones($editoriales_m, 'editorial') ?></select>
                  </div>
                </div>
                <div class="col-lg-3 col-6 mb-3">
                  <div class="form-group">
                    <label>Tipo <span class="lb-req">*</span></label>
                    <select name="tipo_libro[]" class="form-control" required><?= $opciones($tipos_m, 'tipo') ?></select>
                  </div>
                </div>
                <div class="col-lg-3 col-6 mb-3">
                  <div class="form-group">
                    <label>Materia <span class="lb-req">*</span></label>
                    <select name="materia[]" class="form-control" required><?= $opciones($materias_m, 'materia') ?></select>
                  </div>
                </div>
                <div class="col-lg-3 col-6 mb-3">
                  <div class="form-group">
                    <label>Grado <span class="lb-req">*</span></label>
                    <select name="grado[]" class="form-control" required><?= $opciones($grados_m, 'grado') ?></select>
                  </div>
                </div>
                <div class="col-lg-9 col-12">
                  <div class="form-group">
                    <label>Título <span class="lb-req">*</span></label>
                    <input type="text" name="libro[]" class="form-control" maxlength="200" required>
                  </div>
                </div>
                <div class="col-lg-3 col-12">
                  <div class="form-group">
                    <label>Vigencia</label>
                    <input type="text" name="vigencia[]" class="form-control" maxlength="50" placeholder="Ej. 2 años">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <input type="hidden" name="id_colegio" value="<?= (int)$colegio_m['id'] ?>">
          <input type="hidden" name="periodo"    value="<?= (int)$periodo_m['id'] ?>">
        </div><!-- /.modal-body -->

        <div class="modal-footer">
          <button type="button" class="lb-add-btn" id="mer-agregar-fila">
            <i class="bi bi-plus-circle"></i> Añadir otro libro
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy"></i> Guardar libros
          </button>
        </div>
        </form>

      </div>
    </div>
  </div>

  <form action="php/eliminar_mercado.php" method="POST" id="form-mer-eliminar" class="d-none">
    <input type="hidden" name="id" id="mer-eliminar-id">
    <input type="hidden" name="id_colegio" value="<?= (int)$colegio_m['id'] ?>">
    <input type="hidden" name="periodo"    value="<?= (int)$periodo_m['id'] ?>">
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var $wrap = $('.mer-wrap');
  function num(v) { return parseFloat(String(v).replace(',', '.')) || 0; }
  function pesos(v) { return '$' + Math.round(v).toLocaleString('es-CO'); }

  // ── Recalcular fila y totales ─────────────────────────────────────────
  function recalcular() {
    var totVenta = 0, totEjem = 0;
    $wrap.find('tr.mer-fila').each(function () {
      var $tr = $(this), $tasa = $tr.find('.mer-tasa');
      if ($tasa.length) {
        var precio = num($tr.find('.mer-precio').val());
        var neto   = precio - precio * num($tr.find('.mer-desc').val()) / 100;
        var ejem   = Math.floor(($tr.data('alumnos') || 0) * num($tasa.val()) / 100);
        $tr.find('.mer-neto').text(pesos(neto));
        $tr.find('.mer-ejemplares').text(ejem);
        $tr.find('.mer-venta').data('venta', neto * ejem).text(pesos(neto * ejem));
      }
      totVenta += num($tr.find('.mer-venta').data('venta'));
      totEjem  += parseInt($tr.find('.mer-ejemplares').text(), 10) || 0;
    });
    $('#mer-total-venta, #mer-card-venta').text(pesos(totVenta));
    $('#mer-total-ejemplares, #mer-card-ejemplares').text(totEjem.toLocaleString('es-CO'));
  }

  $wrap.on('input', '.mer-input', function () {
    $(this).val($(this).val().replace(/[^0-9.,]/g, ''));
    recalcular();
  });
  $wrap.on('focus', '.mer-input', function () { $(this).select(); });

  // ── Filas para añadir títulos ─────────────────────────────────────────
  // La plantilla se toma antes de iniciar select2 para clonar el <select> limpio.
  // dropdownParent: dentro de un modal el buscador de select2 no recibe el foco si cuelga del <body>.
  var $modal = $('#modal_mercado');
  function iniciarEditorial($item) {
    $item.find('select.mer-editorial').select2({ width: '100%', placeholder: 'Seleccione', dropdownParent: $modal });
  }
  function renumerar() {
    $('#mer-nuevos .mer-nuevo-item').each(function (i) { $(this).find('.mer-item-num').text(i + 1); });
  }
  var $plantilla = $('#mer-nuevos .mer-nuevo-item').first().clone();
  iniciarEditorial($('#mer-nuevos .mer-nuevo-item').first());
  $('#mer-agregar-fila').on('click', function () {
    var $item = $plantilla.clone();
    $item.find('.mer-btn-quitar').removeClass('d-none');
    $('#mer-nuevos').append($item);
    iniciarEditorial($item);
    renumerar();
    $modal.find('.modal-body').animate({ scrollTop: $modal.find('.modal-body')[0].scrollHeight }, 200);
  });
  $('#mer-nuevos').on('click', '.mer-btn-quitar', function () {
    $(this).closest('.mer-nuevo-item').remove();
    renumerar();
  });

  // ── Evitar doble envío ────────────────────────────────────────────────
  $('#form-mer-nuevo, #form-mer-tabla').on('submit', function () {
    $(this).find('button[type="submit"]').prop('disabled', true)
      .html('<span class="spinner-border spinner-border-sm"></span> Guardando...');
  });

  // ── Eliminar título ───────────────────────────────────────────────────
  $wrap.on('click', '.mer-btn-del', function () {
    if (!confirm('¿Eliminar "' + $(this).data('libro') + '" del mercado editorial?')) return;
    $('#mer-eliminar-id').val($(this).data('id'));
    $('#form-mer-eliminar').submit();
  });
})();
</script>
