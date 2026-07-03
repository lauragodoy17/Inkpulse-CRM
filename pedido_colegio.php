<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$id_pedido = intval($_GET['id_pedido'] ?? 0);
$tp        = intval($_GET['tp'] ?? 2);

$tp_cfg = [
  2 => ['label'=>'Pendiente',  'badge'=>'yellow', 'icon'=>'bi-hourglass-split',   'accent'=>'#b45309'],
  3 => ['label'=>'Aprobado',   'badge'=>'green',  'icon'=>'bi-check-circle-fill', 'accent'=>'#16a34a'],
  4 => ['label'=>'Entregado',  'badge'=>'blue',   'icon'=>'bi-truck',             'accent'=>'#2563eb'],
  5 => ['label'=>'Anulado',    'badge'=>'red',    'icon'=>'bi-x-circle-fill',     'accent'=>'#b91c1c'],
];
$ac = $tp_cfg[$tp] ?? $tp_cfg[2];

// Pedido principal
$stmt = $bdd->prepare(
  "SELECT pe.id, pe.fecha, pe.observaciones, pe.fecha_r, pe.cliente, pe.fac_rem, pe.dir_ent, pe.tipo AS petipo,
          z.codigo AS codzona, z.zona,
          c.colegio, c.sub_zona, c.responsable,
          cal.calendario,
          u.nombres, u.apellidos, u.tipo,
          e.estado, e.id AS eid
   FROM pedidos pe
   JOIN colegios c  ON pe.id_colegio = c.id
   JOIN zonas z     ON z.codigo = c.cod_zona
   JOIN usuarios u  ON u.cod_zona = z.codigo
   JOIN estados_pedidos e ON e.id = pe.estado
   LEFT JOIN calendarios cal ON c.id_calendario = cal.id
   WHERE pe.id = ?"
);
$stmt->execute([$id_pedido]);
$pedido = $stmt->fetch();

// Cliente
$stmt2 = $bdd->prepare("SELECT cliente FROM clientes WHERE id = ?");
$stmt2->execute([$pedido['cliente'] ?? 0]);
$cliente = $stmt2->fetch();

// Sub_zona
$sub_zona_nombre = '—';
if (intval($pedido['tipo'] ?? 0) != 3) {
  $stmt3 = $bdd->prepare("SELECT sub_zona FROM sub_zonas WHERE id = ?");
  $stmt3->execute([$pedido['sub_zona'] ?? 0]);
  $sz = $stmt3->fetch();
  $sub_zona_nombre = $sz['sub_zona'] ?? '—';
}

if (intval($pedido['tipo'] ?? 0) == 3) {
  $parts     = explode("/", $pedido['zona'] ?? '');
  $d_empresa = htmlspecialchars(trim($parts[0] ?? ''));
  $d_zona    = htmlspecialchars(trim($parts[1] ?? ''));
  $d_resp    = htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')));
} else {
  $d_empresa = htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')));
  $d_zona    = htmlspecialchars($sub_zona_nombre);
  $d_resp    = htmlspecialchars($pedido['responsable'] ?? '—');
}

// Libros
$stmt4 = $bdd->prepare(
  "SELECT l.id AS libroid, l.id_grado, l.libro, l.precio, l.isbn,
          m.materia, lp.cantidad, p.cod_area, p.descuento, p.descuento_d,
          lp.cantidad_aprob, lp.descuento_aprob, lp.id AS lpid, lp.plataforma
   FROM pedidos pe
   LEFT JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
   LEFT JOIN libros l           ON l.id = lp.id_libro
   LEFT JOIN materias m         ON l.id_materia = m.id
   LEFT JOIN presupuestos p     ON p.id_colegio = pe.id_colegio
     AND p.id_libro = lp.id_libro
     AND COALESCE(lp.cod_area,'') = COALESCE(p.cod_area,'')
     AND pe.id_periodo = p.id_periodo
   WHERE pe.id = ? AND p.definido = 1 AND lp.cantidad != 0
   GROUP BY l.id, p.cod_area"
);
$stmt4->execute([$id_pedido]);
$libros_raw = $stmt4->fetchAll();

// OP directo
$stmt5 = $bdd->prepare("SELECT id, año FROM ordenes_pedidos WHERE id_pedido = ? AND estado != 4");
$stmt5->execute([$id_pedido]);
$op   = $stmt5->rowCount();
$n_op = $stmt5->fetch();

// OP agrupado
$stmt6 = $bdd->prepare("SELECT op FROM op_pedidos_agrupados WHERE id_pedido = ?");
$stmt6->execute([$id_pedido]);
$op_agp   = $stmt6->rowCount();
$n_op_agp = $stmt6->fetch();

// Pre-procesar libros
$libros  = [];
$total_v = 0;
$total_c = 0;
foreach ($libros_raw as $lb) {
  if (($lb['cod_area'] ?? '') == '') {
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$lb['id_grado']]);
    $g = $sg->fetch();
  } else {
    $sa = $bdd->prepare("SELECT id_grado_otro FROM areas_objetivas WHERE codigo = ?");
    $sa->execute([$lb['cod_area']]);
    $ao = $sa->fetch();
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$ao['id_grado_otro'] ?? 0]);
    $g = $sg->fetch();
  }
  $su = $bdd->prepare(
    "SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion, lu.posicion
     FROM lugares l
     JOIN ubicaciones u      ON l.id = u.id_lugar
     JOIN libros_ubicaciones lu ON u.id = lu.ubicacion
     WHERE lu.id_libro = ?"
  );
  $su->execute([$lb['libroid']]);
  $ubs = $su->fetchAll();
  $ubi = '';
  foreach ($ubs as $ub)
    $ubi .= ($ub['id_tipo'] == 1)
      ? $ub['lugar'].$ub['piso'].' Pallet '.$ub['ubicacion'].' '.$ub['posicion'].', '
      : $ub['lugar'].' Bandeja '.$ub['ubicacion'].', ';

  $desc_val    = ($lb['descuento_d'] === '0.0000') ? floatval($lb['descuento']) : floatval($lb['descuento_d']);
  $precio_fact = floatval($lb['precio']) - (floatval($lb['precio']) * $desc_val);
  $v_venta     = $precio_fact * intval($lb['cantidad']);
  $total_v    += $v_venta;
  $total_c    += intval($lb['cantidad']);

  $libros[] = array_merge($lb, [
    'grado'       => $g['grado'] ?? '—',
    'ubi'         => rtrim($ubi, ', '),
    'desc_pct'    => $desc_val * 100,
    'precio_fact' => $precio_fact,
    'v_venta'     => $v_venta,
  ]);
}

$show_plataforma  = (intval($pedido['tipo'] ?? 0) == 3 || ($pedido['codzona'] ?? '') == '5656');
$show_tipo_pedido = (intval($pedido['tipo'] ?? 0) == 3 || ($pedido['codzona'] ?? '') == '5656' || intval($pedido['tipo'] ?? 0) == 10);
$can_act = ($_SESSION['tipo'] == 1 || $_SESSION['tipo'] == 2 || $_SESSION['id'] == 21 || $_SESSION['tipo'] == 10);
$rechazar_label = (intval($pedido['eid'] ?? 0) == 1) ? 'Rechazar' : 'Anular';

// Columnas vacías en todas las filas: se ocultan al imprimir para que el resto se ajuste
$col_isbn       = false;
$col_desc_aprob = false;
$col_cant_aprob = false;
foreach ($libros as $lb) {
  if (trim($lb['isbn'] ?? '') !== '')      $col_isbn       = true;
  if (floatval($lb['descuento_aprob'] ?? 0) > 0) $col_desc_aprob = true;
  if (floatval($lb['cantidad_aprob'] ?? 0) > 0)  $col_cant_aprob = true;
}
$ph_isbn       = $col_isbn       ? '' : ' d-print-none';
$ph_desc_aprob = $col_desc_aprob ? '' : ' d-print-none';
$ph_cant_aprob = $col_cant_aprob ? '' : ' d-print-none';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse — Pedido <?= $ac['label'] ?> #<?= $id_pedido ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32"  href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16"  href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    /* Spin buttons */
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }

    /* Print */
    @page { size: portrait; margin: 10mm; }
    @media print {
      .mc-actions, .breadcrumb, .d-print-none, .left-side-bar, .header { display:none !important; }
      a[href]:after { content:none !important; }
      body { zoom: 0.68; }
      .mc-obs-wrap textarea { height:auto !important; min-height:0 !important; overflow:visible !important; white-space:pre-wrap !important; page-break-inside:avoid; }
      #pc-table td input[type="number"] { border:none !important; background:transparent !important; width:auto !important; }
      #pc-table thead, #pc-table tfoot { display: table-row-group !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      #pc-table { width:100% !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
    }

    /* ── Info table ── */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 1px;
      background: #e2e8f0;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(15,23,42,.06);
    }
    @media (max-width: 767px) { .mc-cards { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 480px) { .mc-cards { grid-template-columns: repeat(2, 1fr); } }
    .mc-card {
      background: #fff;
      display: flex; align-items: center; gap: 9px;
      padding: 9px 13px;
    }
    .mc-card-link { text-decoration:none; color:inherit; display:flex; align-items:center; gap:9px; width:100%; }
    .mc-card-link:hover .mc-card-val-link { color:#2563eb; }
    .mc-card-icon {
      width:30px; height:30px; border-radius:7px;
      display:flex; align-items:center; justify-content:center;
      font-size:.85rem; flex-shrink:0;
    }
    .mc-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .mc-card-icon.green  { background:#dcfce7; color:#15803d; }
    .mc-card-icon.orange { background:#ffedd5; color:#c2410c; }
    .mc-card-icon.purple { background:#ede9fe; color:#6d28d9; }
    .mc-card-icon.teal   { background:#ccfbf1; color:#0d9488; }
    .mc-card-icon.amber  { background:#fef3c7; color:#b45309; }
    .mc-card-icon.red    { background:#fee2e2; color:#dc2626; }
    .mc-card-label { font-size:.63rem; color:#94a3b8; margin:0 0 1px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .mc-card-val   { font-size:.82rem; font-weight:600; color:#0f172a; margin:0; }
    .mc-card-val-link { font-size:.82rem; font-weight:600; color:#2563eb; margin:0; }
    /* Celda ancho completo (dirección) */
    .mc-card-full {
      grid-column: 1 / -1;
      background: #f8fafc;
    }
    .mc-card-full .mc-card-label { display:inline; margin:0 5px 0 0; }
    .mc-card-full .mc-card-label::after { content:':'; }
    .mc-card-full .mc-card-val   { display:inline; font-weight:500; font-size:.82rem; }

    /* Status badge */
    .pc-badge        { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; }
    .pc-badge-yellow { background:#fef3c7; color:#b45309; }
    .pc-badge-green  { background:#dcfce7; color:#15803d; }
    .pc-badge-blue   { background:#dbeafe; color:#1d4ed8; }
    .pc-badge-red    { background:#fee2e2; color:#dc2626; }

    /* ── Table ── */
    .mc-table-wrap { border-radius:10px; overflow-x:auto; box-shadow:0 2px 10px rgba(15,23,42,.09); margin-bottom:24px; }
    #pc-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    #pc-table thead th {
      background:#f8fafc; color:#374151; font-weight:600;
      padding:11px 12px; text-align:left; border:none;
      border-bottom:2px solid #e2e8f0; white-space:nowrap; font-size:.79rem;
    }
    #pc-table tbody tr              { background:#fff; }
    #pc-table tbody tr:nth-child(even) { background:#f8fafc; }
    #pc-table tbody tr:hover        { background:#eff6ff; }
    #pc-table tbody td { padding:9px 12px; border-bottom:1px solid #e2e8f0; color:#1e293b; vertical-align:middle; }
    #pc-table tbody td input[type="number"] {
      width:70px; padding:4px 8px; border:1.5px solid #d1d5db;
      border-radius:6px; font-size:.82rem; text-align:center;
      background:#f9fafb; outline:none; transition:border-color .15s;
    }
    #pc-table tbody td input[type="number"]:focus { border-color:#4361ee; background:#fff; }
    #pc-table tfoot td {
      padding:10px 12px; background:#f8fafc; color:#374151;
      font-weight:700; font-size:.83rem; border:none; border-top:2px solid #e2e8f0;
    }

    /* ── Observations ── */
    .mc-obs-wrap {
      background:#fff; border-radius:10px; padding:16px 20px;
      box-shadow:0 1px 6px rgba(15,23,42,.08); margin-bottom:20px;
    }
    .mc-obs-label {
      font-size:.78rem; font-weight:700; color:#374151;
      text-transform:uppercase; letter-spacing:.04em;
      display:flex; align-items:center; gap:6px; margin:0 0 10px;
    }
    .mc-obs-label i { color:#6366f1; }
    .mc-obs-wrap textarea {
      width:100%; border-radius:8px; border:1.5px solid #d1d5db;
      padding:10px 14px; font-size:.85rem; background:#f9fafb;
      color:#1e293b; resize:vertical; outline:none; transition:border-color .15s; min-height:120px;
    }
    .mc-obs-wrap textarea:focus { border-color:#6366f1; background:#fff; }

    /* ── Actions ── */
    .mc-actions { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:4px; padding-bottom:10px; }
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 22px; border-radius:8px; font-size:14px; font-weight:600;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; }
    .mc-btn-gray  { background:#f1f5f9; color:#475569 !important; border:1.5px solid #cbd5e1; }
    .mc-btn-teal  { background:#0d9488; color:#fff !important; }
    .mc-btn-green { background:#16a34a; color:#fff !important; }
    .mc-btn-red   { background:#dc2626; color:#fff !important; }
    .mc-btn-amber { background:#d97706; color:#fff !important; }
    .mc-btn-blue  { background:#2563eb; color:#fff !important; }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <!-- Header -->
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title">
              <h4>
                <i class="bi <?= $ac['icon'] ?>" style="color:<?= $ac['accent'] ?>;margin-right:6px"></i>
                Pedido <?= $ac['label'] ?>
              </h4>
            </div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="lista_pedidos.php?tp=<?= $tp ?>">Pedidos</a></li>
                <li class="breadcrumb-item active"><?= $ac['label'] ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <center id="impre"></center>

      <!-- Tarjetas informativas -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-receipt"></i></div>
          <div>
            <p class="mc-card-label"># Pedido</p>
            <p class="mc-card-val"><?= $id_pedido ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
          <div>
            <p class="mc-card-label">Colegio</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['colegio'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-calendar2-week"></i></div>
          <div>
            <p class="mc-card-label">Calendario</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['calendario'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
          <div>
            <p class="mc-card-label">Fecha</p>
            <p class="mc-card-val"><?= date('d/m/Y H:i', strtotime($pedido['fecha'] ?? 'now')) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= $ac['badge'] === 'yellow' ? 'amber' : $ac['badge'] ?>"><i class="bi bi-flag-fill"></i></div>
          <div>
            <p class="mc-card-label">Estado</p>
            <p class="mc-card-val">
              <span class="pc-badge pc-badge-<?= $ac['badge'] ?>"><?= htmlspecialchars($pedido['estado'] ?? '') ?></span>
            </p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
          <div>
            <p class="mc-card-label">Promotor / Empresa</p>
            <p class="mc-card-val"><?= $d_empresa ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-geo-alt-fill"></i></div>
          <div>
            <p class="mc-card-label">Zona</p>
            <p class="mc-card-val"><?= $d_zona ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-person-badge"></i></div>
          <div>
            <p class="mc-card-label">Responsable</p>
            <p class="mc-card-val"><?= $d_resp ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha de recogida</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_r'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <p class="mc-card-label">Cliente</p>
            <p class="mc-card-val"><?= htmlspecialchars($cliente['cliente'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-file-earmark-text"></i></div>
          <div>
            <p class="mc-card-label">Documento</p>
            <p class="mc-card-val"><?= (intval($pedido['fac_rem'] ?? 0) == 1) ? 'Factura' : 'Remisión' ?></p>
          </div>
        </div>
        <?php if ($show_tipo_pedido): ?>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-layers"></i></div>
          <div>
            <p class="mc-card-label">Tipo de pedido</p>
            <p class="mc-card-val"><?= (intval($pedido['petipo'] ?? 0) == 1) ? 'Libros sueltos' : 'Paquete' ?></p>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($op || $op_agp): ?>
        <div class="mc-card">
          <a href="op_pendiente.php?op=<?= $op ? $n_op['id'] : $n_op_agp['op'] ?>" target="_blank" class="mc-card-link">
            <div class="mc-card-icon amber"><i class="bi bi-file-earmark-arrow-up"></i></div>
            <div>
              <p class="mc-card-label">Orden de Pedido</p>
              <p class="mc-card-val-link">
                OP #<?= $op ? ($n_op['año'].'-'.$n_op['id']) : $n_op_agp['op'] ?>
              </p>
            </div>
          </a>
        </div>
        <?php endif; ?>
        <?php if (!empty($pedido['dir_ent'])): ?>
        <div class="mc-card mc-card-full">
          <div class="mc-card-icon blue"><i class="bi bi-house-door"></i></div>
          <div>
            <p class="mc-card-label">Dirección de entrega</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['dir_ent']) ?></p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Form: tabla + observaciones + acciones -->
      <form method="POST" action="php/aprobar_pedido.php" id="form_pedido">
        <input type="hidden" name="id_colegio" value="<?= htmlspecialchars($_GET['id_colegio'] ?? '') ?>">
        <input type="hidden" name="periodo"    value="<?= htmlspecialchars($_GET['periodo'] ?? '') ?>">
        <input type="hidden" name="tp"         value="<?= $tp ?>">
        <input type="hidden" name="pedido"     value="<?= $id_pedido ?>">

        <div class="mc-table-wrap">
          <table id="pc-table">
            <thead>
              <tr>
                <th>#</th>
                <th class="print-col-isbn<?= $ph_isbn ?>">ISBN</th>
                <th>Título</th>
                <th class="d-print-none">Materia</th>
                <th>Grado</th>
                <th>Precio de Venta</th>
                <th>Descuento</th>
                <th>Precio Facturación</th>
                <th>Cantidad</th>
                <th>Valor Venta</th>
                <?php if ($show_plataforma): ?><th>Plataforma</th><?php endif; ?>
                <th class="print-col-descaprob<?= $ph_desc_aprob ?>">Descuento Aprobado</th>
                <th class="print-col-cantaprob<?= $ph_cant_aprob ?>">Cantidad Aprobada</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; foreach ($libros as $lb): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td class="print-col-isbn<?= $ph_isbn ?>"><?= htmlspecialchars($lb['isbn']) ?></td>
                <td><?= htmlspecialchars($lb['libro']) ?></td>
                <td class="d-print-none"><?= htmlspecialchars($lb['materia']) ?></td>
                <td><?= htmlspecialchars($lb['grado']) ?></td>
                <td>$ <?= number_format($lb['precio'], 0, ',', '.') ?></td>
                <td><?= number_format($lb['desc_pct'], 0) ?> %</td>
                <td>$ <?= number_format($lb['precio_fact'], 0, ',', '.') ?></td>
                <td style="text-align:center"><?= intval($lb['cantidad']) ?></td>
                <td>$ <?= number_format($lb['v_venta'], 0, ',', '.') ?></td>
                <?php if ($show_plataforma): ?>
                <td style="text-align:center"><?= (intval($lb['plataforma']) == 1) ? 'Sí' : 'No' ?></td>
                <?php endif; ?>
                <td class="print-col-descaprob<?= $ph_desc_aprob ?>" style="text-align:center">
                  <input type="number" id="d<?= $lb['lpid'] ?>" class="ap-desc-input"
                         data-lpid="<?= $lb['lpid'] ?>" value="<?= htmlspecialchars($lb['descuento_aprob']) ?>">
                </td>
                <td class="print-col-cantaprob<?= $ph_cant_aprob ?>" style="text-align:center">
                  <input type="number" id="c<?= $lb['lpid'] ?>" class="ap-qty-input"
                         data-lpid="<?= $lb['lpid'] ?>" value="<?= htmlspecialchars($lb['cantidad_aprob']) ?>">
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td>
                <td class="print-col-isbn<?= $ph_isbn ?>"></td>
                <td></td>
                <td class="d-print-none"></td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:center;font-weight:700"><?= $total_c ?></td>
                <td style="font-weight:700">$ <?= number_format($total_v, 0, ',', '.') ?></td>
                <?php if ($show_plataforma): ?><td></td><?php endif; ?>
                <td class="print-col-descaprob<?= $ph_desc_aprob ?>"></td>
                <td class="print-col-cantaprob<?= $ph_cant_aprob ?>"></td>
              </tr>
            </tfoot>
          </table>
          <!-- Hidden lib_p[] dentro del form, fuera del table -->
          <div class="d-none">
            <?php foreach ($libros as $lb): ?>
            <input type="hidden" name="lib_p[]" id="l<?= $lb['lpid'] ?>">
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Observaciones -->
        <div class="mc-obs-wrap">
          <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
          <textarea name="observaciones" id="observaciones"><?= htmlspecialchars($pedido['observaciones'] ?? '') ?></textarea>
        </div>

        <!-- Acciones -->
        <div class="mc-actions d-print-none">
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <?php if ($can_act): ?>
            <?php if (intval($pedido['eid'] ?? 0) == 1): ?>
              <button type="submit" class="mc-btn mc-btn-green">
                <i class="bi bi-check-circle"></i> Aprobar
              </button>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Rechazar
              </button>
            <?php elseif (intval($pedido['eid'] ?? 0) == 2): ?>
              <?php if ($op == 0 && $op_agp == 0): ?>
              <a href="solicitar_op.php?id_pedido=<?= $id_pedido ?>" target="_blank" class="mc-btn mc-btn-amber">
                <i class="bi bi-file-earmark-plus"></i> Solicitar OP
              </a>
              <?php endif; ?>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Anular
              </button>
              <button type="button" id="entregar" class="mc-btn mc-btn-green">
                <i class="bi bi-truck"></i> Entregar
              </button>
              <button type="button" id="modificar" class="mc-btn mc-btn-blue">
                <i class="bi bi-pencil"></i> Guardar cambios
              </button>
            <?php endif; ?>
          <?php endif; ?>
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
<script>
function buildLibpVal(lpid) {
  return $('#c'+lpid).val() + '/' + lpid + '/' + $('#d'+lpid).val();
}
$(document).on('input', '.ap-qty-input, .ap-desc-input', function () {
  var lpid = $(this).data('lpid');
  $('#l'+lpid).val(buildLibpVal(lpid));
});
$('#form_pedido').on('submit', function () {
  $('.ap-qty-input').each(function () {
    var lpid = $(this).data('lpid');
    $('#l'+lpid).val(buildLibpVal(lpid));
  });
});

function syncColumnasVaciasImpresion() {
  var hayIsbn = Array.from(document.querySelectorAll('#pc-table tbody td.print-col-isbn'))
    .some(function (td) { return td.textContent.trim() !== ''; });
  document.querySelectorAll('#pc-table .print-col-isbn').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayIsbn);
  });

  var hayDescAprob = Array.from(document.querySelectorAll('#pc-table .ap-desc-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#pc-table .print-col-descaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayDescAprob);
  });

  var hayCantAprob = Array.from(document.querySelectorAll('#pc-table .ap-qty-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#pc-table .print-col-cantaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayCantAprob);
  });
}

window.addEventListener('beforeprint', function () {
  syncColumnasVaciasImpresion();
  $.ajax({ url:'ajax/fecha_impre.php', type:'POST', data:'feid=<?= date("Y-m-d H:i:s") ?>/<?= $id_pedido ?>' });
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta._ph = ta.style.height;
    ta.style.setProperty('height', ta.scrollHeight + 'px', 'important');
  });
});
window.addEventListener('afterprint', function () {
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta.style.height = ta._ph || '';
  });
});

$('#imprimir').on('click', function () { window.print(); });

$('#rechazar').on('click', function () {
  inkConfirm({
    title: '¿<?= $rechazar_label ?> este pedido?',
    text:  'Esta acción no se puede deshacer.',
    type:  'danger',
    btnOk: 'Sí, <?= strtolower($rechazar_label) ?>'
  }, function () {
    window.location = 'php/accion_pedidos.php?rechazar=<?= $id_pedido ?>';
  });
});
$('#entregar').on('click', function () {
  window.location = 'php/accion_pedidos.php?entregado=<?= $id_pedido ?>';
});
$('#modificar').on('click', function () {
  $('#form_pedido').attr('action', 'php/mod_pedido.php').submit();
});
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
