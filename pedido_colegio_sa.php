<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$id_pedido = intval($_GET['id_pedido_dist'] ?? $_GET['id_pedido'] ?? 0);
$tp        = intval($_GET['tp'] ?? 2);

$tp_cfg = [
  2 => ['label'=>'Pendiente',  'badge'=>'yellow', 'icon'=>'bi-hourglass-split',   'accent'=>'#b45309'],
  3 => ['label'=>'Aprobado',   'badge'=>'green',  'icon'=>'bi-check-circle-fill', 'accent'=>'#16a34a'],
  4 => ['label'=>'Entregado',  'badge'=>'blue',   'icon'=>'bi-truck',             'accent'=>'#2563eb'],
  5 => ['label'=>'Anulado',    'badge'=>'red',    'icon'=>'bi-x-circle-fill',     'accent'=>'#b91c1c'],
];
$ac = $tp_cfg[$tp] ?? $tp_cfg[2];

// Pedido
$stmt = $bdd->prepare(
  "SELECT pe.fecha, pe.observaciones, pe.fecha_r, pe.colegio, pe.archivo, pe.codigo,
          pe.estado, pe.fac_rem, pe.verify, u.nombres, u.apellidos
   FROM pedidos2 pe
   JOIN usuarios u ON u.id=pe.id_usuario
   WHERE pe.id=?"
);
$stmt->execute([$id_pedido]);
$pedido = $stmt->fetch();

// Libros
$stmt2 = $bdd->prepare(
  "SELECT pe.id, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn,
          m.materia, lp.cantidad, lp.descuento, lp.cantidad_aprob, lp.descuento_aprob, lp.id as lpid
   FROM pedidos2 pe
   JOIN libros_pedidos2 lp ON lp.cod_pedido=pe.codigo
   JOIN libros l           ON l.id=lp.id_libro
   JOIN materias m         ON l.id_materia=m.id
   WHERE pe.id=? AND lp.cantidad!=0
   ORDER BY lp.id"
);
$stmt2->execute([$id_pedido]);
$libros_raw = $stmt2->fetchAll();

// OP directo
$stmt3 = $bdd->prepare("SELECT id FROM ordenes_pedidos WHERE id_pedido_dist=? AND estado!=4");
$stmt3->execute([$id_pedido]);
$op   = $stmt3->rowCount();
$n_op = $stmt3->fetch();
$op_agp = 0;

// Pre-procesar libros
$libros  = [];
$total_v = 0;
$total_c = 0;
foreach ($libros_raw as $lb) {
  $desc        = floatval($lb['descuento']);
  $precio_fact = $lb['precio'] - ($lb['precio'] * ($desc / 100));
  $v_venta     = $precio_fact * $lb['cantidad'];
  $total_v    += $v_venta;
  $total_c    += $lb['cantidad'];

  $sg = $bdd->prepare("SELECT grado FROM grados WHERE id=?");
  $sg->execute([$lb['id_grado']]);
  $g = $sg->fetch();

  $libros[] = array_merge($lb, [
    'grado'       => $g['grado'] ?? '—',
    'desc_val'    => $desc,
    'precio_fact' => $precio_fact,
    'v_venta'     => $v_venta,
  ]);
}

$is_admin  = ($_SESSION['tipo'] == 1 || $_SESSION['tipo'] == 2);
$is_viewer = ($_SESSION['id'] == 21);
$can_edit  = ($is_admin || $is_viewer || ($pedido['verify'] ?? 1) == 0);

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
  <?php if ($tp==2): ?>
    <title>Inkpulse - Pedido SA pendiente #<?= $id_pedido ?></title>
  <?php elseif ($tp==3): ?>
    <title>Inkpulse - Pedido SA aprobado #<?= $id_pedido ?></title>
  <?php elseif ($tp==4): ?>
    <title>Inkpulse - Pedido SA entregado #<?= $id_pedido ?></title>
  <?php else: ?>
    <title>Inkpulse - Pedido SA anulado #<?= $id_pedido ?></title>
  <?php endif; ?>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }

    @page { margin: 10mm; size: portrait; }
    @media print {
      .mc-actions, .breadcrumb, .d-print-none, .left-side-bar, .header { display:none !important; }
      a[href]:after { content:none !important; }
      body { font-size:8px; }
      .mc-obs-wrap textarea { height:auto !important; min-height:0 !important; overflow:visible !important; white-space:pre-wrap !important; page-break-inside:avoid; }
      #psa-table td input[type="number"] { border:none !important; background:transparent !important; width:auto !important; }
      #psa-table thead, #psa-table tfoot { display: table-row-group !important; }
      #psa-table thead th { white-space: normal !important; }
      #psa-table thead th, #psa-table tbody td, #psa-table tfoot td { padding: 3px 4px !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      #psa-table { width:100% !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
    }

    /* Info cards */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 1px; background: #e2e8f0; border: 1px solid #e2e8f0;
      border-radius: 10px; overflow: hidden; margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(15,23,42,.06);
    }
    .mc-card { background:#fff; display:flex; align-items:center; gap:9px; padding:9px 13px; }
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

    /* Status badge */
    .pc-badge        { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; }
    .pc-badge-yellow { background:#fef3c7; color:#b45309; }
    .pc-badge-green  { background:#dcfce7; color:#15803d; }
    .pc-badge-blue   { background:#dbeafe; color:#1d4ed8; }
    .pc-badge-red    { background:#fee2e2; color:#dc2626; }

    /* Vista previa notice */
    .mc-notice {
      display:flex; align-items:flex-start; gap:10px;
      background:#fffbeb; border:1px solid #fcd34d; border-radius:8px;
      padding:12px 16px; margin-bottom:16px; font-size:.85rem; color:#92400e;
    }
    .mc-notice i { flex-shrink:0; margin-top:1px; font-size:1rem; }

    /* Table */
    .mc-table-wrap { border-radius:10px; overflow-x:auto; box-shadow:0 2px 10px rgba(15,23,42,.09); margin-bottom:24px; }
    #psa-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    #psa-table thead th {
      background:#f8fafc; color:#374151; font-weight:600;
      padding:11px 12px; text-align:left; border:none;
      border-bottom:2px solid #e2e8f0; white-space:nowrap; font-size:.79rem;
    }
    #psa-table tbody tr              { background:#fff; }
    #psa-table tbody tr:nth-child(even) { background:#f8fafc; }
    #psa-table tbody tr:hover        { background:#eff6ff; }
    #psa-table tbody td { padding:9px 12px; border-bottom:1px solid #e2e8f0; color:#1e293b; vertical-align:middle; }
    #psa-table tbody td input[type="number"] {
      width:65px; padding:4px 8px; border:1.5px solid #d1d5db;
      border-radius:6px; font-size:.82rem; text-align:center;
      background:#f9fafb; outline:none; transition:border-color .15s;
    }
    #psa-table tbody td input[type="number"]:focus { border-color:#4361ee; background:#fff; }
    #psa-table tfoot td { padding:10px 12px; background:#f8fafc; color:#374151; font-weight:700; font-size:.83rem; border:none; border-top:2px solid #e2e8f0; }

    /* Observations */
    .mc-obs-wrap { background:#fff; border-radius:10px; padding:16px 20px; box-shadow:0 1px 6px rgba(15,23,42,.08); margin-bottom:20px; }
    .mc-obs-label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; display:flex; align-items:center; gap:6px; margin:0 0 10px; }
    .mc-obs-label i { color:#6366f1; }
    .mc-obs-wrap textarea { width:100%; border-radius:8px; border:1.5px solid #d1d5db; padding:10px 14px; font-size:.85rem; background:#f9fafb; color:#1e293b; resize:vertical; outline:none; transition:border-color .15s; min-height:100px; }
    .mc-obs-wrap textarea:focus { border-color:#6366f1; background:#fff; }

    /* Agregar libro */
    .mc-add-btn {
      display:inline-flex; align-items:center; gap:6px;
      padding:7px 16px; border-radius:7px; font-size:.85rem; font-weight:600;
      border:1.5px solid #6366f1; color:#6366f1; background:transparent;
      cursor:pointer; margin-bottom:20px; transition:background .15s, color .15s;
    }
    .mc-add-btn:hover { background:#6366f1; color:#fff; }

    .libro-block { border-top:1px solid #e2e8f0; margin-top:16px; padding-top:16px; }
    .libro-num { font-size:.78rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
    .libro-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .libro-header .libro-num { margin-bottom:0; }
    .btn-remove-book {
      display:inline-flex; align-items:center; gap:4px;
      background:#fee2e2; color:#dc2626; border:none;
      border-radius:6px; padding:4px 10px; font-size:.76rem;
      font-weight:600; cursor:pointer; transition:background .15s;
    }
    .btn-remove-book:hover { background:#fca5a5; }
    .btn-save-book {
      display:inline-flex; align-items:center; gap:4px;
      background:#dcfce7; color:#15803d; border:none;
      border-radius:6px; padding:4px 10px; font-size:.76rem;
      font-weight:600; cursor:pointer; transition:background .15s;
    }
    .btn-save-book:hover { background:#bbf7d0; }

    /* Actions */
    .mc-actions { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:4px; padding-bottom:10px; }
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 22px; border-radius:8px; font-size:14px; font-weight:600;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; }
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
                Pedido sin adopción <?= $ac['label'] ?>
              </h4>
            </div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="lista_pedidos_sa.php?tp=<?= $tp ?>">Pedidos SA</a></li>
                <li class="breadcrumb-item active"><?= $ac['label'] ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <center id="impre"></center>

      <?php if (($pedido['estado'] ?? '') == 1 && ($pedido['verify'] ?? 1) == 0): ?>
      <div class="mc-notice">
        <i class="bi bi-info-circle-fill"></i>
        <span><strong>Vista previa:</strong> Antes de solicitar el pedido puede agregar libros, eliminar libros y modificar las observaciones.</span>
      </div>
      <?php endif; ?>

      <!-- Info cards -->
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
          <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
          <div>
            <p class="mc-card-label">Fecha</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
          <div>
            <p class="mc-card-label">Distribuidor</p>
            <p class="mc-card-val"><?= htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? ''))) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon amber"><i class="bi bi-calendar-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha de recogida</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_r'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-file-earmark-text"></i></div>
          <div>
            <p class="mc-card-label">Documento</p>
            <p class="mc-card-val"><?= (intval($pedido['fac_rem'] ?? 0) == 1) ? 'Factura' : 'Remisión' ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= $ac['badge'] === 'yellow' ? 'amber' : $ac['badge'] ?>"><i class="bi bi-flag-fill"></i></div>
          <div>
            <p class="mc-card-label">Estado</p>
            <p class="mc-card-val"><span class="pc-badge pc-badge-<?= $ac['badge'] ?>"><?= $ac['label'] ?></span></p>
          </div>
        </div>
        <?php if ($op): ?>
        <div class="mc-card">
          <div class="mc-card-icon amber"><i class="bi bi-file-earmark-arrow-up"></i></div>
          <div>
            <p class="mc-card-label">Orden de Pedido</p>
            <p class="mc-card-val">
              <a href="op_pendiente.php?op=<?= $n_op['id'] ?>" target="_blank" style="color:#2563eb;">
                OP #<?= $n_op['id'] ?>
              </a>
            </p>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($pedido['archivo'])): ?>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-paperclip"></i></div>
          <div>
            <p class="mc-card-label">Archivo adjunto</p>
            <p class="mc-card-val">
              <a href="adjuntos_dist/<?= htmlspecialchars($pedido['archivo']) ?>" target="_blank" style="color:#2563eb;">
                <?= htmlspecialchars($pedido['archivo']) ?>
              </a>
            </p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Tabla + observaciones + acciones dentro del form -->
      <form method="POST" action="php/aprobar_pedido_sa.php" id="form_pedido">
        <input type="hidden" name="pedido"  value="<?= $id_pedido ?>">
        <input type="hidden" name="codigo"  value="<?= htmlspecialchars($pedido['codigo'] ?? '') ?>">
        <input type="hidden" name="salida"  value="pendiente">
        <input type="hidden" name="tp"      value="<?= $tp ?>">

        <div class="mc-table-wrap">
          <table id="psa-table">
            <thead>
              <tr>
                <th>#</th>
                <th class="print-col-isbn<?= $ph_isbn ?>">ISBN</th>
                <th>Título</th>
                <th>Materia</th>
                <th>Grado</th>
                <th>PVP</th>
                <th>Descuento</th>
                <th>Precio facturación</th>
                <th>Cantidad</th>
                <th>Valor venta</th>
                <?php if ($is_admin || $is_viewer): ?>
                <th class="print-col-descaprob<?= $ph_desc_aprob ?>">Descuento aprobado</th>
                <th class="print-col-cantaprob<?= $ph_cant_aprob ?>">Cantidad aprobada</th>
                <?php endif; ?>
                <?php if ($can_edit): ?><th class="d-print-none"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; foreach ($libros as $lb): ?>
              <tr id="<?= $lb['lpid'] ?>">
                <td><?= $i++ ?></td>
                <td class="print-col-isbn<?= $ph_isbn ?>"><?= htmlspecialchars($lb['isbn']) ?></td>
                <td><?= htmlspecialchars($lb['libro']) ?></td>
                <td><?= htmlspecialchars($lb['materia']) ?></td>
                <td><?= htmlspecialchars($lb['grado']) ?></td>
                <td>$ <?= number_format($lb['precio'], 0, ',', '.') ?></td>
                <td><?= number_format($lb['desc_val'], 0) ?> %</td>
                <td>$ <?= number_format($lb['precio_fact'], 0, ',', '.') ?></td>
                <td style="text-align:center"><?= intval($lb['cantidad']) ?></td>
                <td>$ <?= number_format($lb['v_venta'], 0, ',', '.') ?></td>
                <?php if ($is_admin || $is_viewer): ?>
                <td class="print-col-descaprob<?= $ph_desc_aprob ?>" style="text-align:center">
                  <input type="number" id="d<?= $lb['lpid'] ?>" name="cantidad_a"
                         class="ap-desc-input" data-lpid="<?= $lb['lpid'] ?>"
                         value="<?= htmlspecialchars($lb['descuento_aprob']) ?>">
                </td>
                <td class="print-col-cantaprob<?= $ph_cant_aprob ?>" style="text-align:center">
                  <input type="number" id="c<?= $lb['lpid'] ?>" name="cantidad_a"
                         class="ap-qty-input" data-lpid="<?= $lb['lpid'] ?>"
                         value="<?= htmlspecialchars($lb['cantidad_aprob']) ?>">
                </td>
                <?php endif; ?>
                <?php if ($can_edit): ?>
                <td class="d-print-none" style="text-align:center">
                  <button type="button" class="btn btn-danger btn-xs elim-libro" data-lpid="<?= $lb['lpid'] ?>">
                    <i class="fa fa-trash"></i>
                  </button>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td><td class="print-col-isbn<?= $ph_isbn ?>"></td><td></td><td></td><td></td><td></td><td></td>
                <td style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:center;font-weight:700"><?= $total_c ?></td>
                <td style="font-weight:700">$ <?= number_format($total_v, 0, ',', '.') ?></td>
                <?php if ($is_admin || $is_viewer): ?><td class="print-col-descaprob<?= $ph_desc_aprob ?>"></td><td class="print-col-cantaprob<?= $ph_cant_aprob ?>"></td><?php endif; ?>
                <?php if ($can_edit): ?><td></td><?php endif; ?>
              </tr>
            </tfoot>
          </table>
          <!-- lib_p[] hidden inputs fuera del table -->
          <div class="d-none">
            <?php foreach ($libros as $lb): ?>
            <input type="hidden" name="lpid[]" value="<?= $lb['lpid'] ?>">
            <input type="hidden" name="lib_p[]" id="l<?= $lb['lpid'] ?>">
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($can_edit): ?>
        <!-- Agregar libro -->
        <button type="button" id="agregar_libro" class="mc-add-btn d-print-none">
          <i class="bi bi-plus-circle"></i> Agregar libro
        </button>
        <?php for ($i = 1; $i < 100; $i++): ?>
        <div id="agg_l<?= $i ?>" class="d-none libro-block mb-3">
          <div class="libro-header">
            <p class="libro-num">Libro #<?= $i ?>:</p>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn-save-book"><i class="bi bi-floppy"></i> Guardar</button>
              <button type="button" class="btn-remove-book" data-idx="<?= $i ?>">
                <i class="bi bi-x-circle"></i> Cancelar
              </button>
            </div>
          </div>
          <div class="row">
            <div class="form-group col-sm-3">
              <label id="l_materia<?= $i ?>" for="materia<?= $i ?>" class="control-label">Materia <small style="color:red;">*</small></label>
              <select name="materia[]" id="materia<?= $i ?>" class="form-control">
                <option value="">Seleccionar</option>
                <?php
                  $req_m = $bdd->prepare("SELECT id, materia FROM materias");
                  $req_m->execute();
                  foreach ($req_m->fetchAll() as $mat):
                ?>
                <option value="<?= $mat['id'] ?>"><?= htmlspecialchars($mat['materia']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-sm-3">
              <label id="l_libro<?= $i ?>" for="libro<?= $i ?>" class="control-label">Libro <small style="color:red;">*</small></label>
              <select name="libro" id="libro<?= $i ?>" class="form-control"></select>
            </div>
            <div class="form-group col-sm-3">
              <label id="l_descuento<?= $i ?>" for="descuento<?= $i ?>" class="control-label">Descuento % <small style="color:red;">*</small></label>
              <input type="number" class="form-control" name="descuento" id="descuento<?= $i ?>">
            </div>
            <div class="form-group col-sm-3">
              <label id="l_cantidad<?= $i ?>" for="cantidad<?= $i ?>" class="control-label">Cantidad <small style="color:red;">*</small></label>
              <input type="number" class="form-control" name="cantidad" id="cantidad<?= $i ?>">
            </div>
          </div>
          <input type="hidden" name="libro_e[]" id="libro_e<?= $i ?>">
        </div>
        <?php endfor; ?>
        <?php endif; ?>

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
          <?php if ($is_admin || $is_viewer): ?>
            <?php if (($pedido['estado'] ?? '') == 1): ?>
              <button type="submit" class="mc-btn mc-btn-green">
                <i class="bi bi-check-circle"></i> Aprobar
              </button>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Rechazar
              </button>
            <?php elseif (($pedido['estado'] ?? '') == 2): ?>
              <?php if ($op == 0 && $op_agp == 0): ?>
              <a href="solicitar_op.php?id_pedido_dist=<?= $id_pedido ?>" target="_blank" class="mc-btn mc-btn-amber">
                <i class="bi bi-file-earmark-plus"></i> Solicitar OP
              </a>
              <?php endif; ?>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Anular
              </button>
              <button type="button" id="modificar" class="mc-btn mc-btn-blue">
                <i class="bi bi-pencil"></i> Guardar cambios
              </button>
              <button type="button" id="entregar" class="mc-btn mc-btn-green">
                <i class="bi bi-truck"></i> Entregar
              </button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (($pedido['verify'] ?? 1) == 0): ?>
          <button type="button" id="confirmar" class="mc-btn mc-btn-green">
            <i class="bi bi-check2-circle"></i> Confirmar
          </button>
          <?php endif; ?>
        </div>

      </form>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="src/ink-alerts.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script>
function buildLibpVal(lpid) {
  return $('#c'+lpid).val() + '/' + lpid + '/' + $('#d'+lpid).val();
}
$(document).on('input keyup', '.ap-qty-input, .ap-desc-input', function () {
  var lpid = $(this).data('lpid');
  $('#l'+lpid).val(buildLibpVal(lpid));
});
$('#form_pedido').on('submit', function () {
  $('.ap-qty-input').each(function () {
    var lpid = $(this).data('lpid');
    $('#l'+lpid).val(buildLibpVal(lpid));
  });
});
$(document).on('click', '.elim-libro', function () {
  $('#' + $(this).data('lpid')).remove();
});

$('#imprimir').on('click', function () { window.print(); });

function syncColumnasVaciasImpresion() {
  var hayIsbn = Array.from(document.querySelectorAll('#psa-table tbody td.print-col-isbn'))
    .some(function (td) { return td.textContent.trim() !== ''; });
  document.querySelectorAll('#psa-table .print-col-isbn').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayIsbn);
  });

  var hayDescAprob = Array.from(document.querySelectorAll('#psa-table .ap-desc-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#psa-table .print-col-descaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayDescAprob);
  });

  var hayCantAprob = Array.from(document.querySelectorAll('#psa-table .ap-qty-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#psa-table .print-col-cantaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayCantAprob);
  });
}

window.addEventListener('beforeprint', function () {
  syncColumnasVaciasImpresion();
  $.ajax({ url:'ajax/fecha_impre2.php', type:'POST', data:'feid=<?= date("Y-m-d H:i:s") ?>/<?= $id_pedido ?>' });
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

$('#rechazar').on('click', function () {
  inkConfirm({
    type:  'danger',
    title: '<?= ($pedido["estado"] ?? "") == 1 ? "¿Rechazar pedido?" : "¿Anular pedido?" ?>',
    text:  'Esta acción no se puede deshacer.',
    btnOk: '<?= ($pedido["estado"] ?? "") == 1 ? "Sí, rechazar" : "Sí, anular" ?>'
  }, function () {
    window.location = 'php/accion_pedidos_sa.php?rechazar=<?= $id_pedido ?>';
  });
});

$('#entregar').on('click', function () {
  window.location = 'php/accion_pedidos_sa.php?entregado=<?= $id_pedido ?>';
});

$('#modificar').on('click', function () {
  $('input[name="salida"]').val('guardar');
  $('#form_pedido').attr('action', 'php/mod_pedido_sa.php').submit();
});

$('#confirmar').on('click', function () {
  $('input[name="salida"]').val('confirmar');
  $('#form_pedido').attr('action', 'php/mod_pedido_sa.php').submit();
});

var m = 1;
$('#agregar_libro').on('click', function () {
  if (m > 98) { $(this).addClass('d-none'); }
  $('#agg_l'+m).removeClass('d-none');
  m++;

  <?php for ($i = 1; $i < 100; $i++): ?>
  $('#materia<?= $i ?>').on('change', function () {
    $.ajax({
      url: 'ajax/buscar_l_eureka2.php', type: 'POST', data: 'mat_gra='+$(this).val(), dataType: 'html',
      success: function (resp) {
        $('#libro<?= $i ?>').html(resp);
        var l=$('#libro<?= $i ?>').val(), c=$('#cantidad<?= $i ?>').val(), d=$('#descuento<?= $i ?>').val();
        $('#libro_e<?= $i ?>').val(l+'/'+c+'/'+d);
      }
    });
  });
  $('#cantidad<?= $i ?>').keyup(function () {
    var l=$('#libro<?= $i ?>').val(), c=$(this).val(), d=$('#descuento<?= $i ?>').val();
    $('#libro_e<?= $i ?>').val(l+'/'+c+'/'+d);
  });
  $('#libro<?= $i ?>').on('change', function () {
    var l=$(this).val(), c=$('#cantidad<?= $i ?>').val(), d=$('#descuento<?= $i ?>').val();
    $('#libro_e<?= $i ?>').val(l+'/'+c+'/'+d);
  });
  <?php endfor; ?>
});

$(document).on('click', '.btn-remove-book', function () {
  var idx = $(this).data('idx');
  $('#agg_l'      + idx).addClass('d-none');
  $('#materia'    + idx).val('');
  $('#libro'      + idx).html('');
  $('#descuento'  + idx).val('');
  $('#cantidad'   + idx).val('');
  $('#libro_e'    + idx).val('');
  $('#ls_pri_sec' + idx).html('');
  $('#l_cantidad' + idx).removeClass('d-none');
  $('#cantidad'   + idx).removeClass('d-none');
  $('#l_descuento'+ idx).removeClass('d-none');
  $('#descuento'  + idx).removeClass('d-none');
});

$(document).on('click', '.btn-save-book', function () {
  $('input[name="salida"]').val('guardar');
  $('#form_pedido').attr('action', 'php/mod_pedido_sa.php').submit();
});
</script>
</body>
</html>
