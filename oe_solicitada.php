<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

if (($_SESSION["autentificado"] ?? '') === "SI" && !in_array($_SESSION["tipo"] ?? null, [1, 2])) {
    header("Location: index.php");
    exit;
}

$oe = intval($_GET['oe'] ?? 0);

$req_pedido = $bdd->prepare(
    "SELECT o.*, u.nombres, u.apellidos
     FROM ordenes_externas o
     JOIN usuarios u ON u.id = o.usuario
     WHERE o.id = ?"
);
$req_pedido->execute([$oe]);
$pedido = $req_pedido->fetch();

if (!$pedido) {
    header('Location: ver_ordenes_externas.php');
    exit;
}

$req_mat = $bdd->prepare("SELECT * FROM materiales_oe WHERE oeid = ?");
$req_mat->execute([$oe]);
$materiales = $req_mat->fetchAll();

$proveedores = [
    'Disonex Zona Franca',
    'Carvajal Soluciones de Comunicación',
    'Multi-impresos',
    'Xpress Estudio Gráfico',
    'Panamericana Formas e Impresos',
    'DIDICOM',
    'EEE Taller de producción',
];

$desc_map    = [1 => 'Libro estudiante', 2 => 'Guía', 3 => 'Otro'];
$descripcion = $pedido['descripcion'] == 3
    ? ($pedido['descripcion_otro'] !== '' ? $pedido['descripcion_otro'] : 'Otro')
    : ($desc_map[$pedido['descripcion']] ?? 'Otro');
$cumplida    = $pedido['estado'] == 4;
$en_proceso  = $pedido['estado'] == 2;

// Cargar entregas por material y determinar el número de columnas a mostrar (mín. 4)
$entregas_por_material = [];
$max_entregas = 0;
foreach ($materiales as $mat) {
    $req_ent = $bdd->prepare("SELECT cant_entregada, fecha FROM entregas_oe WHERE id_material_oe = ? ORDER BY id");
    $req_ent->execute([$mat['id']]);
    $ents = $req_ent->fetchAll();
    $entregas_por_material[$mat['id']] = $ents;
    $max_entregas = max($max_entregas, count($ents));
}
$num_cols = max(4, $max_entregas);

$total_cantidad_arr = $total_entregas_arr = $total_valor_arr = [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Orden Externa <?= htmlspecialchars($pedido['anio'] . ' - ' . $oe) ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    @page { margin: 30px; }
    @media print {
      .d-print-none { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 9px; }
      .mc-cards { box-shadow: none; }
      .oe-table-wrap {
        max-height: none !important;
        overflow: visible !important;
      }
      #oe-mat-table { min-width: 0 !important; }
      #oe-mat-table thead { display: table-header-group; }
      #oe-mat-table tr { page-break-inside: avoid; }
    }
    input[type=number] { -moz-appearance: textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

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
      padding: 10px 13px;
    }
    .mc-card-full {
      grid-column: 1 / -1;
      background: #f8fafc;
    }
    .mc-card-icon {
      width: 30px; height: 30px; border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; flex-shrink: 0;
    }
    .mc-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
    .mc-card-icon.green  { background: #dcfce7; color: #15803d; }
    .mc-card-icon.orange { background: #ffedd5; color: #c2410c; }
    .mc-card-icon.purple { background: #ede9fe; color: #6d28d9; }
    .mc-card-icon.teal   { background: #ccfbf1; color: #0d9488; }
    .mc-card-icon.amber  { background: #fef3c7; color: #b45309; }
    .mc-card-icon.red    { background: #fee2e2; color: #dc2626; }
    .mc-card-label { font-size: .63rem; color: #94a3b8; margin: 0 0 1px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .mc-card-val   { font-size: .82rem; font-weight: 600; color: #0f172a; margin: 0; }

    .pc-badge        { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
    .pc-badge-green  { background: #dcfce7; color: #15803d; }
    .pc-badge-yellow { background: #fef3c7; color: #b45309; }
    .pc-badge-blue   { background: #dbeafe; color: #1d4ed8; }

    .edit-row {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;
    }
    .edit-row-label {
      font-size: .7rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px;
    }
    .req { color: #dc2626; }

    #oe-mat-table thead th {
      background: #3730a3; color: #fff; font-size: .76rem; font-weight: 600;
      white-space: nowrap; padding: 10px 10px; border: none;
    }
    #oe-mat-table tbody td {
      font-size: .82rem; padding: 8px 10px; border-bottom: 1px solid #e2e8f0;
      vertical-align: middle; color: #1e293b;
    }
    #oe-mat-table tbody tr:nth-child(even) { background: #f5f3ff; }
    #oe-mat-table tfoot td { font-weight: 700; font-size: .82rem; background: #f8fafc; padding: 10px; border-top: 2px solid #e2e8f0; }
    .dc { width: 72px !important; }
    .oe-entrega-td { min-width: 100px; }
    .oe-entrega-td input { margin-bottom: 3px; }
    .oe-entrega-td input:last-child { margin-bottom: 0; }
    .entrega-fecha-input { font-size: .72rem !important; padding: 2px 4px !important; height: auto !important; }
    .btn-del { background: #fee2e2; border: none; color: #dc2626; border-radius: 6px; padding: 4px 9px; cursor: pointer; font-size: .78rem; transition: background .15s; }
    .btn-del:hover { background: #fca5a5; }

    .material-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; }
    .material-block .mat-title { font-size: .78rem; font-weight: 700; color: #7c3aed; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px; }
    .add-material-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: #f1f5f9; color: #475569;
      border: 1.5px dashed #94a3b8; border-radius: 8px;
      padding: 8px 18px; font-size: .84rem; font-weight: 600;
      cursor: pointer; transition: background .15s; text-decoration: none;
    }
    .add-material-btn:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }
    .add-col-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: #ede9fe; color: #6d28d9;
      border: 1.5px dashed #a78bfa; border-radius: 8px;
      padding: 6px 14px; font-size: .8rem; font-weight: 600;
      cursor: pointer; transition: background .15s; margin: 10px 0 4px 20px;
    }
    .add-col-btn:hover { background: #ddd6fe; }
    .mat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .mat-header .mat-title { margin-bottom: 0; }
    .btn-remove-mat {
      display: inline-flex; align-items: center; gap: 4px;
      background: #fee2e2; color: #dc2626; border: none;
      border-radius: 6px; padding: 4px 10px; font-size: .76rem;
      font-weight: 600; cursor: pointer; transition: background .15s;
    }
    .btn-remove-mat:hover { background: #fca5a5; }

    .mc-obs-wrap { background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 6px rgba(15,23,42,.08); margin-bottom: 20px; }
    .mc-obs-label { font-size: .78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: 6px; margin: 0 0 10px; }
    .mc-obs-label i { color: #6366f1; }
    .mc-obs-wrap textarea { width: 100%; border-radius: 8px; border: 1.5px solid #d1d5db; padding: 10px 14px; font-size: .85rem; background: #f9fafb; color: #1e293b; resize: vertical; outline: none; transition: border-color .15s; min-height: 110px; }
    .mc-obs-wrap textarea:focus { border-color: #6366f1; background: #fff; }

    .mc-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; padding-bottom: 10px; }
    .mc-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 22px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: opacity .15s, transform .1s; }
    .mc-btn:hover { opacity: .88; transform: translateY(-1px); text-decoration: none; }
    .mc-btn-gray  { background: #f1f5f9; color: #475569 !important; border: 1.5px solid #cbd5e1; }
    .mc-btn-teal  { background: #0d9488; color: #fff !important; }
    .mc-btn-blue  { background: #2563eb; color: #fff !important; }
    .mc-btn-amber { background: #d97706; color: #fff !important; }
    .mc-btn-green { background: #16a34a; color: #fff !important; }

    @media (max-width: 767px) {
      .oe-table-wrap { overflow-x: auto; }
      #oe-mat-table  { min-width: 760px; }
    }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <!-- Encabezado -->
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-sm-12 d-flex align-items-center" style="gap:12px; flex-wrap:wrap">
            <div class="title">
              <h4>
                <i class="bi bi-truck mr-2" style="color:#6d28d9"></i>
                Orden Externa <?= htmlspecialchars($pedido['anio'] . ' - ' . $oe) ?>
              </h4>
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="ver_ordenes_externas.php">Órdenes Externas</a></li>
                  <li class="breadcrumb-item active" aria-current="page">Orden <?= htmlspecialchars($pedido['anio'] . ' - ' . $oe) ?></li>
                </ol>
              </nav>
            </div>
            <?php if ($cumplida): ?>
              <span class="pc-badge pc-badge-green d-print-none"><i class="bi bi-check-circle-fill"></i> Cumplida <?= htmlspecialchars($pedido['fecha_cumplida']) ?></span>
            <?php elseif ($en_proceso): ?>
              <span class="pc-badge pc-badge-blue d-print-none"><i class="bi bi-box-seam"></i> En proceso de entrega</span>
            <?php else: ?>
              <span class="pc-badge pc-badge-yellow d-print-none"><i class="bi bi-clock"></i> Pendiente</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Tarjetas informativas -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-receipt"></i></div>
          <div>
            <p class="mc-card-label">Orden #</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['anio'] . ' - ' . $oe) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
          <div>
            <p class="mc-card-label">Fecha creación</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-layers"></i></div>
          <div>
            <p class="mc-card-label">Descripción</p>
            <p class="mc-card-val"><?= htmlspecialchars($descripcion) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= $cumplida ? 'green' : ($en_proceso ? 'blue' : 'amber') ?>"><i class="bi bi-flag-fill"></i></div>
          <div>
            <p class="mc-card-label">Estado</p>
            <p class="mc-card-val">
              <?php if ($cumplida): ?>
                <span class="pc-badge pc-badge-green">Cumplida</span>
              <?php elseif ($en_proceso): ?>
                <span class="pc-badge pc-badge-blue">En proceso</span>
              <?php else: ?>
                <span class="pc-badge pc-badge-yellow">Pendiente</span>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
          <div>
            <p class="mc-card-label">Usuario</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['nombres'] . ' ' . $pedido['apellidos']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-person-badge"></i></div>
          <div>
            <p class="mc-card-label">Solicitante</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['solicitante']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-building"></i></div>
          <div>
            <p class="mc-card-label">Empresa solicitante</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['empresa_solicitante']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha entrega solicitada</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_ent_s']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-truck"></i></div>
          <div>
            <p class="mc-card-label">Proveedor</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['proveedor']) ?></p>
          </div>
        </div>
        <?php if ($pedido['adjunto']): ?>
        <div class="mc-card mc-card-full">
          <div class="mc-card-icon blue"><i class="bi bi-paperclip"></i></div>
          <div>
            <p class="mc-card-label">Archivo adjunto</p>
            <p class="mc-card-val">
              <a href="adjuntos_oe/<?= htmlspecialchars($pedido['adjunto']) ?>" target="_blank" style="color:#2563eb">
                <?= htmlspecialchars($pedido['adjunto']) ?>
              </a>
            </p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <form method="POST" action="php/mod_oe.php" id="form_pedido">

        <!-- Campos editables (solo non-tipo8) -->
        <?php if ($_SESSION['tipo'] != 8): ?>
        <div class="edit-row d-print-none">
          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="form-group">
                <div class="edit-row-label">Fecha entrega solicitada <span class="req">*</span></div>
                <div class="input-group">
                  <input type="text" class="form-control date-picker" name="fecha_ent_s" id="fecha_ent_s"
                         data-date-format="yyyy-mm-dd" required autocomplete="off"
                         value="<?= htmlspecialchars($pedido['fecha_ent_s']) ?>">
                  <span class="input-group-addon"><i class="fa fa-calendar bigger-110"></i></span>
                </div>
              </div>
            </div>
            <div class="col-md-4 col-sm-6">
              <div class="form-group">
                <div class="edit-row-label">Proveedor <span class="req">*</span></div>
                <select class="form-control" name="proveedor" id="proveedor" style="width:100%" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($proveedores as $p): ?>
                  <option value="<?= htmlspecialchars($p) ?>" <?= $p === $pedido['proveedor'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
          <input type="hidden" name="fecha_ent_s" value="<?= htmlspecialchars($pedido['fecha_ent_s']) ?>">
          <input type="hidden" name="proveedor"    value="<?= htmlspecialchars($pedido['proveedor']) ?>">
        <?php endif; ?>

        <!-- Tabla de materiales -->
        <div class="modern-card" style="margin-bottom: 20px">
          <div style="padding: 16px 20px 6px">
            <p style="font-size:.78rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin:0 0 0; padding-bottom:8px; border-bottom:2px solid #e2e8f0">Materiales</p>
          </div>
          <div class="oe-table-wrap px-2 pb-2" style="overflow-x:auto; max-height:480px; overflow-y:auto">
            <table class="table table-sm" id="oe-mat-table">
              <thead>
                <tr id="oe-thead-row">
                  <th>#</th>
                  <th>Título</th>
                  <th>Cantidad</th>
                  <th>Costo unit.</th>
                  <th>Valor</th>
                  <?php for ($c = 1; $c <= $num_cols; $c++): ?>
                  <th class="oe-entrega-th">Entrega <?= $c ?></th>
                  <?php endfor; ?>
                  <th id="th-total-entregado">Total entregado</th>
                  <?php if ($_SESSION['tipo'] != 8): ?>
                  <th class="d-print-none">Acciones</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 1;
                foreach ($materiales as $mat):
                  $mid  = $mat['id'];
                  $ents = $entregas_por_material[$mid];
                  $total_entr = array_sum(array_column($ents, 'cant_entregada'));
                  $valor = $mat['cantidad'] * $mat['costo'];

                  $total_cantidad_arr[] = $mat['cantidad'];
                  $total_entregas_arr[] = $total_entr;
                  $total_valor_arr[]    = $valor;
                ?>
                <tr id="<?= $mid ?>" data-mid="<?= $mid ?>">
                  <td><?= $i ?></td>
                  <td><?= htmlspecialchars($mat['material']) ?></td>
                  <td>
                    <?php if ($_SESSION['tipo'] != 8): ?>
                      <input type="number" class="form-control dc" min="0" id="cantidad<?= $mid ?>" name="cantidad" value="<?= $mat['cantidad'] ?>">
                    <?php else: ?>
                      <?= $mat['cantidad'] ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($_SESSION['tipo'] != 8): ?>
                      <input type="number" step="0.01" class="form-control dc" min="0" id="costo<?= $mid ?>" name="costo" value="<?= $mat['costo'] ?>">
                    <?php else: ?>
                      $ <?= number_format($mat['costo'], 0, ',', '.') ?>
                    <?php endif; ?>
                  </td>
                  <td class="td-valor" id="valor<?= $mid ?>">$ <?= number_format($valor, 0, ',', '.') ?></td>
                  <?php for ($c = 1; $c <= $num_cols; $c++): ?>
                    <td class="oe-entrega-td">
                    <?php if (isset($ents[$c - 1])): ?>
                      <?= htmlspecialchars($ents[$c - 1]['cant_entregada']) ?>
                      <br><small class="text-muted"><?= htmlspecialchars(substr($ents[$c - 1]['fecha'], 0, 10)) ?></small>
                    <?php elseif ($_SESSION['tipo'] != 8): ?>
                      <input type="number" class="form-control dc entrega-input" min="0" data-col="<?= $c ?>" id="entrega<?= $c ?>_<?= $mid ?>" placeholder="Cant.">
                      <input type="date" class="form-control dc entrega-fecha-input" data-col="<?= $c ?>" id="entregafecha<?= $c ?>_<?= $mid ?>">
                      <input type="hidden" name="entrega[<?= $c ?>][]" id="ent<?= $c ?>_<?= $mid ?>">
                    <?php endif; ?>
                    </td>
                  <?php endfor; ?>
                  <td class="td-total-entregado"><?= $total_entr ?></td>
                  <?php if ($_SESSION['tipo'] != 8): ?>
                  <td class="d-print-none" style="text-align:center">
                    <button type="button" class="btn-del" data-mid="<?= $mid ?>" title="Eliminar">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                  <?php endif; ?>

                  <input type="hidden" name="mpid[]" value="<?= $mid ?>" id="mpid<?= $mid ?>">
                  <input type="hidden" name="mat_p[]" id="m<?= $mid ?>" value="<?= $mid ?>/<?= $mat['cantidad'] ?>/<?= $mat['costo'] ?>">
                </tr>
                <?php $i++; endforeach; ?>
              </tbody>
              <tfoot>
                <tr id="oe-tfoot-row">
                  <td></td>
                  <td>Total</td>
                  <td><?= array_sum($total_cantidad_arr) ?></td>
                  <td></td>
                  <td class="td-valor">$ <?= number_format(array_sum($total_valor_arr), 0, ',', '.') ?></td>
                  <?php for ($c = 1; $c <= $num_cols; $c++): ?><td class="oe-entrega-td"></td><?php endfor; ?>
                  <td class="td-total-entregado"><?= array_sum($total_entregas_arr) ?></td>
                  <?php if ($_SESSION['tipo'] != 8): ?><td class="d-print-none"></td><?php endif; ?>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php if ($_SESSION['tipo'] != 8): ?>
          <a id="agregar_col_entrega" class="add-col-btn d-print-none">
            <i class="bi bi-plus-circle"></i> Agregar columna de entrega
          </a>
          <?php endif; ?>
        </div>

        <!-- Agregar materiales -->
        <?php if ($_SESSION['tipo'] != 8): ?>
        <?php for ($j = 1; $j < 100; $j++): ?>
        <div id="agg_l<?= $j ?>" class="material-block d-none d-print-none">
          <div class="mat-header">
            <p class="mat-title">Material adicional #<?= $j ?></p>
            <button type="button" class="btn-remove-mat" data-idx="<?= $j ?>">
              <i class="bi bi-x-circle"></i> Eliminar
            </button>
          </div>
          <div class="row">
            <div class="col-md-5">
              <div class="form-group">
                <label for="titulo<?= $j ?>">Título <span class="req">*</span></label>
                <select class="form-control titulo-select" name="titulo" id="titulo<?= $j ?>" style="width:100%"></select>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label for="cantidad_n<?= $j ?>">Cantidad <span class="req">*</span></label>
                <input type="number" class="form-control" name="cantidad_n" id="cantidad_n<?= $j ?>">
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label for="costo_n<?= $j ?>">Costo unitario <span class="req">*</span></label>
                <input type="number" step="0.01" min="0" class="form-control" name="costo_n" id="costo_n<?= $j ?>">
              </div>
            </div>
          </div>
          <input type="hidden" name="material_e[]" id="material_e<?= $j ?>">
        </div>
        <?php endfor; ?>

        <a id="agregar_material" class="add-material-btn d-print-none mb-4 d-inline-flex">
          <i class="bi bi-plus-circle"></i> Agregar material
        </a>
        <?php endif; ?>

        <!-- Observaciones -->
        <div class="mc-obs-wrap">
          <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
          <textarea name="observaciones" id="observaciones"
            <?= $_SESSION['tipo'] == 8 ? 'readonly' : '' ?>
            placeholder="Observaciones"><?= htmlspecialchars($pedido['observaciones']) ?></textarea>
        </div>

        <input type="hidden" name="oe" value="<?= $oe ?>">

        <!-- Botones de acción -->
        <div class="mc-actions d-print-none">
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <?php if ($_SESSION['tipo'] != 8): ?>
            <button type="button" class="mc-btn mc-btn-amber" id="entregar">
              <i class="bi bi-box-seam"></i> Entregar
            </button>
          <?php endif; ?>
          <?php if ($_SESSION['tipo'] != 8): ?>
          <button type="button" class="mc-btn mc-btn-blue" id="modificar">
            <i class="bi bi-floppy"></i> Guardar cambios
          </button>
          <?php endif; ?>
          <?php if ($_SESSION['tipo'] != 8 && in_array($pedido['estado'], [0, 2])): ?>
            <button type="button" class="mc-btn mc-btn-green" id="cumplida">
              <i class="bi bi-check-circle"></i> Cumplida
            </button>
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
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
  $('#proveedor').select2({
    placeholder: 'Seleccionar proveedor',
    allowClear: true,
    width: '100%',
    language: { noResults: function () { return 'Sin resultados'; } }
  });

  function initTituloSelect($el) {
    $el.select2({
      placeholder: 'Escriba para buscar un libro...',
      allowClear: true,
      width: '100%',
      minimumInputLength: 2,
      tags: true,
      language: {
        noResults: function () { return 'Sin resultados'; },
        searching: function () { return 'Buscando...'; },
        inputTooShort: function () { return 'Escriba al menos 2 letras para buscar'; }
      },
      ajax: {
        url: 'php/buscar_libros_oe.php',
        dataType: 'json',
        delay: 300,
        data: function (params) { return { q: params.term }; },
        processResults: function (data) { return { results: data }; },
        cache: true
      }
    });
  }

  $('#entregar').on('click', function () { $('#form_pedido').attr('action', 'php/entregar_oe.php').submit(); });
  $('#cumplida').on('click', function () { $('#form_pedido').attr('action', 'php/cumplir_oe.php').submit(); });
  $('#modificar').on('click', function () { $('#form_pedido').attr('action', 'php/mod_oe.php').submit(); });
  $('#imprimir').on('click', function () { window.print(); });

  // Eliminar fila de material existente
  $(document).on('click', '.btn-del', function () {
    var mid = $(this).data('mid');
    $('#' + mid).remove();
    $('#mpid' + mid).remove();
  });

  // Actualizar hidden inputs y recalcular el valor al modificar cantidad/costo
  function fmtMoney(n) {
    return '$ ' + (isNaN(n) ? 0 : Math.round(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function updateValorFila(mid) {
    var cant  = parseFloat($('#cantidad' + mid).val()) || 0;
    var costo = parseFloat($('#costo'    + mid).val()) || 0;
    $('#m' + mid).val(mid + '/' + cant + '/' + costo);
    $('#valor' + mid).text(fmtMoney(cant * costo));

    var total = 0;
    $('#oe-mat-table tbody tr').each(function () {
      var m = $(this).data('mid');
      var c = parseFloat($('#cantidad' + m).val()) || 0;
      var p = parseFloat($('#costo'    + m).val()) || 0;
      total += c * p;
    });
    $('#oe-tfoot-row .td-valor').text(fmtMoney(total));
  }

  $(document).on('keyup', 'input[id^="cantidad"]', function () {
    var mid = this.id.slice('cantidad'.length);
    updateValorFila(mid);
  });

  $(document).on('keyup', 'input[id^="costo"]', function () {
    var mid = this.id.slice('costo'.length);
    updateValorFila(mid);
  });

  function syncEntrega(col, mid) {
    var qty   = $('#entrega' + col + '_' + mid).val();
    var fecha = $('#entregafecha' + col + '_' + mid).val();
    $('#ent' + col + '_' + mid).val(mid + '/' + qty + '/' + fecha);
  }

  $(document).on('keyup change', '.entrega-input, .entrega-fecha-input', function () {
    var col = $(this).data('col');
    var mid = $(this).closest('tr').data('mid');
    syncEntrega(col, mid);
  });

  // Agregar columna de entrega dinámica
  var numCols = <?= $num_cols ?>;
  $('#agregar_col_entrega').on('click', function () {
    numCols++;
    $('#th-total-entregado').before('<th class="oe-entrega-th">Entrega ' + numCols + '</th>');
    $('#oe-tfoot-row .td-total-entregado').before('<td class="oe-entrega-td"></td>');

    $('#oe-mat-table tbody tr').each(function () {
      var mid = $(this).data('mid');
      var cell = '<td class="oe-entrega-td">' +
        '<input type="number" class="form-control dc entrega-input" min="0" data-col="' + numCols + '" id="entrega' + numCols + '_' + mid + '" placeholder="Cant.">' +
        '<input type="date" class="form-control dc entrega-fecha-input" data-col="' + numCols + '" id="entregafecha' + numCols + '_' + mid + '">' +
        '<input type="hidden" name="entrega[' + numCols + '][]" id="ent' + numCols + '_' + mid + '">' +
        '</td>';
      $(this).find('.td-total-entregado').before(cell);
    });
  });

  var m = 1;
  $('#agregar_material').on('click', function () {
    if (m >= 99) { $(this).addClass('d-none'); return; }
    $('#agg_l' + m).removeClass('d-none');
    (function (idx) {
      initTituloSelect($('#titulo' + idx));
      $('#titulo' + idx).on('change', function () {
        $('#material_e' + idx).val($('#titulo' + idx).val() + '/' + $('#cantidad_n' + idx).val() + '/' + $('#costo_n' + idx).val());
      });
      $('#cantidad_n' + idx + ', #costo_n' + idx).on('keyup', function () {
        $('#material_e' + idx).val($('#titulo' + idx).val() + '/' + $('#cantidad_n' + idx).val() + '/' + $('#costo_n' + idx).val());
      });
    })(m);
    m++;
  });

  $(document).on('click', '.btn-remove-mat', function () {
    var idx = $(this).data('idx');
    $('#agg_l'        + idx).addClass('d-none');
    $('#titulo'       + idx).val(null).trigger('change');
    $('#cantidad_n'   + idx).val('');
    $('#costo_n'      + idx).val('');
    $('#material_e'   + idx).val('');
  });
});
</script>
</body>
</html>
