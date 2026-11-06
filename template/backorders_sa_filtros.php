<?php
/**
 * Filtros de "Backorders sin adopción" (libro, tercero, estado, rango de fechas de registro). Ver
 * src/backorders_sa.js. Con $bsa_ocultar_atendidos (vista "Administrar") no se ofrece el estado
 * Atendido, porque esa lista no los muestra (están en el informe).
 */
$bsa_ocultar_atendidos = !empty($bsa_ocultar_atendidos);
?>
<div class="bsa-grid-filtros" id="bsa-filtros">
  <div>
    <label class="bsa-label">Libro</label>
    <input type="text" class="form-control form-control-sm" data-filtro="libro" placeholder="Título o ISBN">
  </div>
  <div>
    <label class="bsa-label">Pedido / Cliente</label>
    <input type="text" class="form-control form-control-sm" data-filtro="tercero" placeholder="# pedido, nombre o identificación">
  </div>
  <div>
    <label class="bsa-label">Estado</label>
    <select class="form-control form-control-sm" data-filtro="estado">
      <option value="">Todos</option>
      <?php foreach (BSA_ESTADOS as $clave => $label): if ($bsa_ocultar_atendidos && $clave === 'atendido') continue; ?>
        <option value="<?= $clave ?>"><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="bsa-label">Desde</label>
    <input type="date" class="form-control form-control-sm" data-filtro="desde">
  </div>
  <div>
    <label class="bsa-label">Hasta</label>
    <input type="date" class="form-control form-control-sm" data-filtro="hasta">
  </div>
  <div style="display:flex; gap:6px;">
    <button type="button" class="bsa-btn bsa-btn-sm" id="bsa-btn-filtrar"><i class="bi bi-funnel"></i> Filtrar</button>
    <button type="button" class="bsa-btn bsa-btn-sm bsa-btn-outline" id="bsa-btn-limpiar" title="Limpiar filtros"><i class="bi bi-x-lg"></i></button>
  </div>
</div>
