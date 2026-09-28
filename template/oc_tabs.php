<?php
/**
 * /template/oc_tabs.php
 * Pestañas del módulo Órdenes de compra y estilos de sus insignias de estado (compartidos por
 * ordenes_compra.php, orden_compra.php y oc_backorders.php). Se incluye dentro del <body>.
 */
$oc_pagina = basename($_SERVER['PHP_SELF']);
?>
<style>
  .oc-tabs { display:flex; gap:6px; margin:0 0 18px; border-bottom:2px solid #e2e8f0; }
  .oc-tabs a { padding:9px 16px; font-size:.86rem; font-weight:600; color:#64748b; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; }
  .oc-tabs a:hover { color:#1d4ed8; text-decoration:none; }
  .oc-tabs a.activa { color:#1d4ed8; border-bottom-color:#1d4ed8; }
  .oc-est { display:inline-block; border-radius:20px; padding:2px 10px; font-size:11px; font-weight:700; white-space:nowrap; }
  .oc-est-pendiente  { background:#fef3c7; color:#92400e; }
  .oc-est-parcial    { background:#dbeafe; color:#1d4ed8; }
  .oc-est-completa, .oc-est-completado { background:#dcfce7; color:#15803d; }
  .oc-est-excedentes, .oc-est-excedente { background:#fee2e2; color:#b91c1c; }
</style>
<div class="oc-tabs">
  <a href="ordenes_compra.php" class="<?= in_array($oc_pagina, ['ordenes_compra.php', 'orden_compra.php'], true) ? 'activa' : '' ?>"><i class="bi bi-cart-check"></i> Órdenes de compra</a>
  <a href="oc_backorders.php" class="<?= $oc_pagina === 'oc_backorders.php' ? 'activa' : '' ?>"><i class="bi bi-hourglass-split"></i> Backorders</a>
</div>
