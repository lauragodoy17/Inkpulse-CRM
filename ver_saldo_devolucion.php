<?php
/**
 * /ver_saldo_devolucion.php?id=N
 * Documento de saldo (SAL-n) de una devolución de venta directa (pedido de la usuaria 2026-10-19): lo que no
 * se devolvió de cada libro y pedido, por su precio final (precio − descuento). Ver
 * includes/devoluciones_vd_datos.php. Si la devolución se anuló, el saldo se muestra como anulado.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_vd_datos.php");

dm_validar_acceso();
dvd_asegurar_columnas($bdd);

$saldo = dvd_obtener_saldo($bdd, (int)($_GET['id'] ?? 0));
if (!$saldo) {
  header('Location: formatos_venta_directa.php?ink_status=error&ink_msg=' . urlencode('El documento de saldo no existe o no está en tu alcance.'));
  exit;
}
$pesos = fn($v) => '$ ' . number_format((float)$v, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Saldo <?= htmlspecialchars($saldo['numero']) ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .sal-doc { background:#fff; border-radius:16px; box-shadow:0 2px 12px rgba(15,23,42,.07); padding:28px 32px; }
    .sal-top { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; border-bottom:2px solid #0f766e; padding-bottom:16px; margin-bottom:18px; }
    .sal-titulo { font-size:1.5rem; font-weight:800; color:#0f172a; margin:0; }
    .sal-num { font-size:1.1rem; font-weight:800; color:#b45309; }
    .sal-anulado { display:inline-block; background:#fee2e2; color:#dc2626; border-radius:20px; padding:3px 12px; font-size:.8rem; font-weight:700; margin-left:8px; }
    .sal-datos { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:20px; }
    .sal-dato { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; }
    .sal-lbl { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
    .sal-val { font-size:.9rem; font-weight:700; color:#0f172a; }
    .sal-tabla { width:100%; font-size:.85rem; border-collapse:collapse; }
    .sal-tabla th { background:#0f766e; color:#fff; font-size:.75rem; font-weight:600; padding:9px 10px; text-align:left; }
    .sal-tabla td { padding:9px 10px; border-bottom:1px solid #f1f5f9; }
    .sal-tabla .r { text-align:right; white-space:nowrap; } .sal-tabla .c { text-align:center; }
    .sal-tabla tfoot td { font-weight:800; border-top:2px solid #0f766e; font-size:.95rem; }
    .sal-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:600; border:1.5px solid #0d9488; color:#0d9488; background:#fff; text-decoration:none; cursor:pointer; }
    .sal-btn:hover { background:#0d9488; color:#fff; text-decoration:none; }
    @media print {
      .left-side-bar, .header, .footer-wrap, .sal-acciones, .mobile-menu-overlay { display:none !important; }
      .main-container { padding:0 !important; margin:0 !important; }
      .sal-doc { box-shadow:none; padding:0; }
      a[href]:after { content:none !important; }
    }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="sal-acciones" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px;flex-wrap:wrap;">
        <a href="formatos_venta_directa.php" class="sal-btn"><i class="bi bi-arrow-left"></i> Formatos</a>
        <div style="display:flex;gap:8px;">
          <a href="devolucion_colegio.php?id_pedido=<?= (int)$saldo['id_devolucion'] ?>" class="sal-btn"><i class="bi bi-arrow-return-left"></i> Ver devolución #<?= (int)$saldo['id_devolucion'] ?></a>
          <button type="button" class="sal-btn" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
        </div>
      </div>

      <div class="sal-doc">
        <div class="sal-top">
          <div>
            <p class="sal-titulo">Saldo de devolución</p>
            <div style="font-size:.85rem;color:#64748b;margin-top:4px;">Unidades que no se devolvieron de los pedidos de venta, por su precio final (con descuento).</div>
          </div>
          <div style="text-align:right;">
            <div class="sal-num"><?= htmlspecialchars($saldo['numero']) ?><?= $saldo['anulado'] ? '<span class="sal-anulado">Anulado</span>' : '' ?></div>
            <div style="font-size:.82rem;color:#64748b;"><?= date('d/m/Y', strtotime($saldo['created_at'])) ?></div>
          </div>
        </div>
        <?php if ($saldo['anulado']): ?>
          <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;">
            <i class="bi bi-x-octagon"></i> La devolución #<?= (int)$saldo['id_devolucion'] ?> se anuló: este saldo ya no es válido y los pedidos volvieron a quedar abiertos.
          </div>
        <?php endif; ?>

        <div class="sal-datos">
          <div class="sal-dato"><div class="sal-lbl">Colegio</div><div class="sal-val"><?= htmlspecialchars($saldo['colegio']) ?></div></div>
          <div class="sal-dato"><div class="sal-lbl">Cliente</div><div class="sal-val"><?= htmlspecialchars($saldo['cliente_nombre'] ?? '—') ?></div></div>
          <div class="sal-dato"><div class="sal-lbl">Pedidos</div><div class="sal-val">
            <?php foreach (explode(',', $saldo['ids_pedidos']) as $i => $clave): if (!dvd_clave_valida($clave)) continue; ?>
              <?= $i ? ', ' : '' ?><a href="pedido_colegio.php?id_pedido=<?= (int)substr($clave, 1) ?>" target="_blank">#<?= (int)substr($clave, 1) ?></a>
            <?php endforeach; ?>
          </div></div>
          <div class="sal-dato"><div class="sal-lbl">Devolución</div><div class="sal-val">#<?= (int)$saldo['id_devolucion'] ?></div></div>
          <div class="sal-dato"><div class="sal-lbl">Generó</div><div class="sal-val"><?= htmlspecialchars($saldo['usuario'] ?? '—') ?></div></div>
        </div>

        <div class="table-responsive">
          <table class="sal-tabla">
            <thead>
              <tr><th>Pedido</th><th>Título</th><th>ISBN</th><th class="c">Unidades</th><th class="r">Precio final</th><th class="r">Subtotal</th></tr>
            </thead>
            <tbody>
              <?php foreach ($saldo['libros'] as $l): ?>
              <tr>
                <td>#<?= (int)$l['id_pedido'] ?></td>
                <td><?= htmlspecialchars($l['libro']) ?></td>
                <td><?= htmlspecialchars($l['isbn']) ?></td>
                <td class="c"><?= (int)$l['cantidad'] ?></td>
                <td class="r"><?= $pesos($l['precio_final']) ?></td>
                <td class="r"><?= $pesos($l['subtotal']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr><td colspan="3">Total</td><td class="c"><?= (int)$saldo['unidades'] ?></td><td></td><td class="r"><?= $pesos($saldo['total']) ?></td></tr>
            </tfoot>
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
<script src="src/ink-alerts.js"></script>
</body>
</html>
