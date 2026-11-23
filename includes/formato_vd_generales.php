<?php
/**
 * /includes/formato_vd_generales.php
 * Datos generales del formato de venta directa: se diligencian en la solicitud (paso 1) y se muestran otra
 * vez, ya llenos, en la entrega de bodega (paso 2) para completarlos. Lo incluye formato_venta_directa.php
 * (usa $colegio, $val, $hora y $fijo de esa página); se leen con fvd_leer_generales(). Los datos que vienen de los
 * pedidos para Andrea Díaz salen bloqueados ($fijo).
 */
?>
<div class="fv-grid">
  <div class="fv-campo"><label>Colegio</label><input type="text" value="<?= htmlspecialchars($colegio) ?>" readonly style="background:#f8fafc;font-weight:700;"></div>
  <div class="fv-campo"><label>Fecha de venta <span class="fv-req">*</span></label><input type="date" name="fecha_venta" value="<?= $val('fecha_venta') ?>" required<?= $fijo('fecha_venta') ?>></div>
  <div class="fv-campo"><label>Hora de venta (desde)</label><input type="time" name="hora_inicio" value="<?= $hora('hora_inicio') ?>"<?= $fijo('hora_inicio') ?>></div>
  <div class="fv-campo"><label>Hora de venta (hasta)</label><input type="time" name="hora_fin" value="<?= $hora('hora_fin') ?>"<?= $fijo('hora_fin') ?>></div>
  <div class="fv-campo"><label>Responsable del colegio</label><input type="text" name="contacto_colegio" maxlength="150" value="<?= $val('contacto_colegio') ?>"<?= $fijo('contacto_colegio') ?>></div>
  <div class="fv-campo"><label>Teléfonos de contacto</label><input type="text" name="tel_contacto" maxlength="40" value="<?= $val('tel_contacto') ?>"<?= $fijo('tel_contacto') ?>></div>
  <div class="fv-campo" style="grid-column:span 2;"><label>Dirección / ubicación de la venta</label><input type="text" name="direccion" maxlength="255" value="<?= $val('direccion') ?>"<?= $fijo('direccion') ?>></div>
  <div class="fv-campo" style="grid-column:1 / -1;"><label>Observaciones</label><textarea name="observaciones" rows="2" maxlength="2000"<?= $fijo('observaciones') ?>><?= $val('observaciones') ?></textarea></div>
</div>
