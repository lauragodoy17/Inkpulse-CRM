<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$pedidos_ids = $_POST['pedidos'] ?? [];
$periodo_id  = intval($_POST['periodo'] ?? 0);

// --- Info del pedido / cliente (último pedido para el encabezado) ---
$pedido  = null;
$cliente = null;
foreach ($pedidos_ids as $pid) {
  $s = $bdd->prepare(
    "SELECT pe.fecha, pe.observaciones, pe.fecha_r, pe.cliente, pe.fac_rem, pe.dir_ent,
            z.codigo AS codzona, z.zona, c.colegio, u.nombres, u.apellidos, u.tipo,
            u.id AS uid, e.estado, pe.tipo AS petipo
     FROM pedidos pe
     JOIN colegios c       ON pe.id_colegio = c.id
     JOIN zonas z          ON z.codigo = c.cod_zona
     JOIN usuarios u       ON u.cod_zona = z.codigo
     JOIN estados_pedidos e ON e.id = pe.estado
     WHERE pe.id = ?"
  );
  $s->execute([$pid]);
  $pedido = $s->fetch();

  $sc = $bdd->prepare("SELECT cliente FROM clientes WHERE id = ?");
  $sc->execute([$pedido['cliente'] ?? 0]);
  $cliente = $sc->fetch();
}

// --- IDs únicos de libros entre todos los pedidos ---
$libros_a = [];
foreach ($pedidos_ids as $pid) {
  $sq = $bdd->prepare(
    "SELECT l.id AS libroid
     FROM pedidos pe
     JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
     JOIN libros l          ON l.id = lp.id_libro
     JOIN presupuestos p    ON p.id_colegio = pe.id_colegio
       AND p.id_libro = lp.id_libro AND pe.id_periodo = p.id_periodo
     WHERE pe.id = ? AND p.definido = 1 AND lp.cantidad != 0
     GROUP BY l.id, p.cod_area"
  );
  $sq->execute([$pid]);
  foreach ($sq->fetchAll() as $r) {
    $libros_a[] = $r['libroid'];
  }
}
$libros_a = array_unique($libros_a);

// --- Pre-procesar libros ---
$libros  = [];
$total_v = 0;
$total_c = 0;
$uid     = intval($pedido['uid'] ?? 0);

foreach ($libros_a as $libro_a) {
  $sq = $bdd->prepare(
    "SELECT pe.id, l.id AS libroid, l.id_grado, l.libro, l.precio, l.isbn,
            m.materia, lp.cantidad, p.cod_area, p.descuento_d, p.tasa_compra_d,
            lp.id AS lpid, lp.plataforma
     FROM pedidos pe
     JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
     JOIN libros l          ON l.id = lp.id_libro
     JOIN materias m        ON l.id_materia = m.id
     JOIN presupuestos p    ON p.id_colegio = pe.id_colegio
       AND p.id_libro = lp.id_libro AND pe.id_periodo = p.id_periodo
     WHERE lp.id_libro = ? AND p.id_periodo = ? AND pe.id_usuario = ?
       AND p.definido = 1 AND lp.cantidad != 0
     GROUP BY l.id, p.cod_area"
  );
  $sq->execute([$libro_a, $periodo_id, $uid]);
  $libro = $sq->fetch();
  if (!$libro) continue;

  // Grado
  if (($libro['cod_area'] ?? '') == '') {
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$libro['id_grado']]);
    $g = $sg->fetch();
  } else {
    $sa = $bdd->prepare("SELECT id_grado_otro FROM areas_objetivas WHERE codigo = ?");
    $sa->execute([$libro['cod_area']]);
    $ao = $sa->fetch();
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$ao['id_grado_otro'] ?? 0]);
    $g = $sg->fetch();
  }

  // Ubicación
  $su = $bdd->prepare(
    "SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion, lu.posicion
     FROM lugares l
     JOIN ubicaciones u         ON l.id = u.id_lugar
     JOIN libros_ubicaciones lu ON u.id = lu.ubicacion
     WHERE lu.id_libro = ?"
  );
  $su->execute([$libro['libroid']]);
  $ubi = '';
  foreach ($su->fetchAll() as $ub) {
    $ubi .= ($ub['id_tipo'] == 1)
      ? $ub['lugar'].$ub['piso'].' Pallet '.$ub['ubicacion'].' '.$ub['posicion'].', '
      : $ub['lugar'].' Bandeja '.$ub['ubicacion'].', ';
  }

  // Sumar cantidades a través de todos los pedidos
  $cant_sum = 0;
  foreach ($pedidos_ids as $pid) {
    $sqc = $bdd->prepare(
      "SELECT lp.cantidad, lp.cantidad_aprob
       FROM libros_pedidos lp JOIN pedidos p ON lp.cod_pedido = p.codigo
       WHERE p.id = ? AND lp.id_libro = ?"
    );
    $sqc->execute([$pid, $libro['libroid']]);
    foreach ($sqc->fetchAll() as $c) {
      $cant_sum += ($c['cantidad_aprob'] > 0) ? intval($c['cantidad_aprob']) : intval($c['cantidad']);
    }
  }

  $desc_pct = floatval($libro['descuento_d']) * 100;
  $p_fact   = floatval($libro['precio']) * (1 - floatval($libro['descuento_d']));
  $v_venta  = $p_fact * $cant_sum;
  $total_v += $v_venta;
  $total_c += $cant_sum;

  $libros[] = [
    'isbn'       => $libro['isbn'],
    'libro'      => $libro['libro'],
    'ubi'        => rtrim($ubi, ', '),
    'materia'    => $libro['materia'],
    'grado'      => $g['grado'] ?? '—',
    'precio'     => floatval($libro['precio']),
    'desc_pct'   => $desc_pct,
    'p_fact'     => $p_fact,
    'cantidad'   => $cant_sum,
    'v_venta'    => $v_venta,
    'plataforma' => intval($libro['plataforma'] ?? 0),
  ];
}

$show_plataforma = (intval($pedido['petipo'] ?? 0) == 3 || ($pedido['codzona'] ?? '') == '5656');
$promotor        = htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')));
$pedidos_label   = implode(', ', array_map('intval', (array)$pedidos_ids));
$tipo_doc        = (intval($pedido['fac_rem'] ?? 0) == 1) ? 'Factura' : 'Remisión';
$tipo_pedido_str = '';
if ($show_plataforma) {
  $tipo_pedido_str = [1 => 'Libros sueltos', 2 => 'Paquete', 3 => 'Paquetes y libros sueltos'][intval($pedido['petipo'] ?? 0)] ?? 'Paquete';
}
$n_titulos = count($libros);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse — Pedido agrupado</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    /* Print */
    @page { margin: 15px; size: landscape; }
    @media print {
      .mc-actions, .breadcrumb, .d-print-none, .left-side-bar, .header { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 9px; }
      #ag-table thead, #ag-table tfoot { display: table-row-group !important; }
      .ag-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      #ag-table { width:100% !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
    }

    /* Info cards */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 14px;
      margin-bottom: 20px;
    }
    .mc-card {
      background: #fff; border-radius: 10px; padding: 14px 16px;
      box-shadow: 0 1px 6px rgba(15,23,42,.08);
      display: flex; align-items: center; gap: 14px;
    }
    .mc-card-icon {
      width: 42px; height: 42px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; flex-shrink: 0;
    }
    .mc-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
    .mc-card-icon.green  { background: #dcfce7; color: #15803d; }
    .mc-card-icon.orange { background: #ffedd5; color: #c2410c; }
    .mc-card-icon.purple { background: #ede9fe; color: #6d28d9; }
    .mc-card-icon.teal   { background: #ccfbf1; color: #0d9488; }
    .mc-card-label { font-size: .71rem; color: #64748b; margin: 0 0 2px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .mc-card-val   { font-size: .9rem; font-weight: 700; color: #0f172a; margin: 0; }

    /* Table */
    .ag-table-wrap { border-radius: 10px; overflow-x: auto; box-shadow: 0 2px 10px rgba(15,23,42,.09); margin-bottom: 24px; }
    #ag-table { width: 100%; font-size: .83rem; border-collapse: collapse; }
    #ag-table thead th {
      background: #f8fafc; color: #374151; font-weight: 600;
      font-size: .79rem; padding: 11px 12px;
      text-align: left; border: none; border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    #ag-table tbody tr              { background: #fff; }
    #ag-table tbody tr:nth-child(even) { background: #f8fafc; }
    #ag-table tbody tr:hover        { background: #eff6ff; }
    #ag-table tbody td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; color: #1e293b; vertical-align: middle; }
    #ag-table tfoot td {
      padding: 10px 12px; background: #f8fafc; color: #374151;
      font-weight: 700; font-size: .83rem; border: none; border-top: 2px solid #e2e8f0;
    }

    /* Actions */
    .mc-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 8px; padding-bottom: 16px; }
    .mc-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 22px; border-radius: 8px; font-size: 14px; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none;
      transition: opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity: .88; transform: translateY(-1px); text-decoration: none; }
    .mc-btn-teal  { background: #0d9488; color: #fff !important; }
    .mc-btn-amber { background: #d97706; color: #fff !important; }
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
            <div class="title"><h4>Pedido agrupado</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Pedidos</li>
                <li class="breadcrumb-item active">Agrupado</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <!-- Tarjetas de info -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-layers"></i></div>
          <div>
            <p class="mc-card-label"># Pedidos</p>
            <p class="mc-card-val"><?= $pedidos_label ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-person"></i></div>
          <div>
            <p class="mc-card-label">Promotor</p>
            <p class="mc-card-val"><?= $promotor ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
          <div>
            <p class="mc-card-label">Cliente</p>
            <p class="mc-card-val"><?= htmlspecialchars($cliente['cliente'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-file-text"></i></div>
          <div>
            <p class="mc-card-label">Documento</p>
            <p class="mc-card-val"><?= $tipo_doc ?></p>
          </div>
        </div>
        <?php if ($tipo_pedido_str): ?>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-tag"></i></div>
          <div>
            <p class="mc-card-label">Tipo de pedido</p>
            <p class="mc-card-val"><?= htmlspecialchars($tipo_pedido_str) ?></p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Tabla de libros -->
      <div class="modern-card">
        <div class="card-head">
          <h5><i class="bi bi-journals mr-2"></i> Libros agrupados</h5>
          <span style="font-size:12px;color:#64748b;background:#f1f5f9;border-radius:20px;padding:3px 10px;font-weight:500"><?= $n_titulos ?> títulos</span>
        </div>
        <div class="ag-table-wrap px-2 pb-2">
          <table id="ag-table">
            <thead>
              <tr>
                <th>ISBN</th>
                <th>Título</th>
                <th>Ubicación</th>
                <th class="d-print-none">Materia</th>
                <th>Grado</th>
                <th>PVP</th>
                <th>Descuento</th>
                <th>Precio facturación</th>
                <th>Cantidad</th>
                <th>Valor Venta</th>
                <?php if ($show_plataforma): ?><th>Plataforma</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($libros as $lb): ?>
              <tr>
                <td><?= htmlspecialchars($lb['isbn']) ?></td>
                <td><?= htmlspecialchars($lb['libro']) ?></td>
                <td><?= htmlspecialchars($lb['ubi']) ?></td>
                <td class="d-print-none"><?= htmlspecialchars($lb['materia']) ?></td>
                <td><?= htmlspecialchars($lb['grado']) ?></td>
                <td>$ <?= number_format($lb['precio'], 0, ',', '.') ?></td>
                <td><?= number_format($lb['desc_pct'], 1) ?> %</td>
                <td>$ <?= number_format($lb['p_fact'], 0, ',', '.') ?></td>
                <td><?= $lb['cantidad'] ?></td>
                <td>$ <?= number_format($lb['v_venta'], 0, ',', '.') ?></td>
                <?php if ($show_plataforma): ?>
                <td><?= $lb['plataforma'] ? 'Sí' : 'No' ?></td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td>
                <td></td>
                <td></td>
                <td class="d-print-none"></td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:center;font-weight:700"><?= $total_c ?></td>
                <td style="font-weight:700">$ <?= number_format($total_v, 0, ',', '.') ?></td>
                <?php if ($show_plataforma): ?><td></td><?php endif; ?>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Botones de acción -->
      <form action="solicitar_op.php" method="POST">
        <?php foreach ($pedidos_ids as $pid): ?>
          <input type="hidden" name="pedidos_agp[]" value="<?= intval($pid) ?>">
        <?php endforeach; ?>
        <div class="mc-actions d-print-none">
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <button type="submit" class="mc-btn mc-btn-amber">
            <i class="bi bi-box-seam"></i> Solicitar OP
          </button>
        </div>
      </form>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/ink-alerts.js"></script>
<script>
$('#imprimir').on('click', function () { window.print(); });
</script>
</body>
</html>
