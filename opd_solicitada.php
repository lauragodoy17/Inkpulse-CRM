<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$opd = intval($_GET['opd'] ?? 0);

$req_pedido = $bdd->prepare(
    "SELECT o.observaciones, o.fecha, o.descripcion, o.conse, o.año,
            c.cliente, o.cliente as cid, o.adjunto, u.nombres, u.apellidos,
            o.fecha_ent_s, o.estado, o.solicitante, o.fecha_cumplida, o.fecha_ent_s
     FROM ordenes_produccion o
     LEFT JOIN clientes c ON o.cliente = c.id
     JOIN usuarios u ON u.id = o.usuario
     WHERE o.id = ?"
);
$req_pedido->execute([$opd]);
$pedido = $req_pedido->fetch();

if (!$pedido) {
    header('Location: ver_opds.php');
    exit;
}

$req_libros = $bdd->prepare("SELECT * FROM libros_opd WHERE opid = ?");
$req_libros->execute([$opd]);
$libros = $req_libros->fetchAll();

$req_imp = $bdd->prepare("SELECT * FROM impresoras_taller WHERE act = 1");
$req_imp->execute();
$impresoras = $req_imp->fetchAll();

$req_cli = $bdd->query("SELECT id, cliente FROM clientes ORDER BY cliente");
$clientes = $req_cli->fetchAll();

$desc_map    = [1 => 'Libro estudiante', 2 => 'Guía', 3 => 'Otro'];
$descripcion = $desc_map[$pedido['descripcion']] ?? 'Otro';
$cumplida    = $pedido['estado'] == 4;
$en_proceso  = $pedido['estado'] == 2;

$total_cantidad_arr = $total_entregas_arr = $total_clicks_arr = $total_valor_arr = [];
$ent1 = $ent2 = $ent3 = [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - OPD <?= htmlspecialchars($pedido['año'] . ' - ' . $opd) ?></title>
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
    }
    input[type=number] { -moz-appearance: textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

    /* ── Info cards (igual que pedido_colegio) ── */
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

    /* Badge de estado */
    .pc-badge        { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
    .pc-badge-green  { background: #dcfce7; color: #15803d; }
    .pc-badge-yellow { background: #fef3c7; color: #b45309; }
    .pc-badge-blue   { background: #dbeafe; color: #1d4ed8; }

    /* Fila de campos editables */
    .edit-row {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;
    }
    .edit-row-label {
      font-size: .7rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px;
    }
    .req { color: #dc2626; }

    /* ── Tabla de materiales ── */
    #opd-mat-table thead th {
      background: #3730a3; color: #fff; font-size: .76rem; font-weight: 600;
      white-space: nowrap; padding: 10px 10px; border: none;
    }
    #opd-mat-table tbody td {
      font-size: .82rem; padding: 8px 10px; border-bottom: 1px solid #e2e8f0;
      vertical-align: middle; color: #1e293b;
    }
    #opd-mat-table tbody tr:nth-child(even) { background: #f5f3ff; }
    #opd-mat-table tfoot td { font-weight: 700; font-size: .82rem; background: #f8fafc; padding: 10px; border-top: 2px solid #e2e8f0; }
    .dc { width: 72px !important; }
    .btn-del { background: #fee2e2; border: none; color: #dc2626; border-radius: 6px; padding: 4px 9px; cursor: pointer; font-size: .78rem; transition: background .15s; }
    .btn-del:hover { background: #fca5a5; }

    /* Agregar material */
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
    .mat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .mat-header .mat-title { margin-bottom: 0; }
    .btn-remove-mat {
      display: inline-flex; align-items: center; gap: 4px;
      background: #fee2e2; color: #dc2626; border: none;
      border-radius: 6px; padding: 4px 10px; font-size: .76rem;
      font-weight: 600; cursor: pointer; transition: background .15s;
    }
    .btn-remove-mat:hover { background: #fca5a5; }

    /* Observaciones */
    .mc-obs-wrap { background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 6px rgba(15,23,42,.08); margin-bottom: 20px; }
    .mc-obs-label { font-size: .78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: 6px; margin: 0 0 10px; }
    .mc-obs-label i { color: #6366f1; }
    .mc-obs-wrap textarea { width: 100%; border-radius: 8px; border: 1.5px solid #d1d5db; padding: 10px 14px; font-size: .85rem; background: #f9fafb; color: #1e293b; resize: vertical; outline: none; transition: border-color .15s; min-height: 110px; }
    .mc-obs-wrap textarea:focus { border-color: #6366f1; background: #fff; }

    /* Nota de entrega */
    .delivery-note { background: #f8fafc; border-left: 3px solid #c7d2fe; border-radius: 4px; padding: 10px 14px; margin-bottom: 10px; font-size: .82rem; color: #475569; }
    .delivery-note strong { color: #1e293b; }

    /* Acciones */
    .mc-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; padding-bottom: 10px; }
    .mc-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 22px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: opacity .15s, transform .1s; }
    .mc-btn:hover { opacity: .88; transform: translateY(-1px); text-decoration: none; }
    .mc-btn-gray  { background: #f1f5f9; color: #475569 !important; border: 1.5px solid #cbd5e1; }
    .mc-btn-teal  { background: #0d9488; color: #fff !important; }
    .mc-btn-blue  { background: #2563eb; color: #fff !important; }
    .mc-btn-amber { background: #d97706; color: #fff !important; }
    .mc-btn-green { background: #16a34a; color: #fff !important; }

    @media (max-width: 767px) {
      .opd-table-wrap { overflow-x: auto; }
      #opd-mat-table  { min-width: 960px; }
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
                <i class="bi bi-file-earmark-text mr-2" style="color:#6d28d9"></i>
                OPD <?= htmlspecialchars($pedido['año'] . ' - ' . $opd) ?>
              </h4>
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="ver_opds.php">OPD's</a></li>
                  <li class="breadcrumb-item active" aria-current="page">OPD <?= htmlspecialchars($pedido['año'] . ' - ' . $opd) ?></li>
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

      <!-- Tarjetas informativas (estilo pedido_colegio) -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-receipt"></i></div>
          <div>
            <p class="mc-card-label">OPD #</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['año'] . ' - ' . $opd) ?></p>
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
        <?php if ($en_proceso && !empty($pedido['fecha_ent_s'] ?? '')): ?>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-calendar2-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha de entrega</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_ent_s']) ?></p>
          </div>
        </div>
        <?php endif; ?>
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
          <div class="mc-card-icon orange"><i class="bi bi-calendar-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha entrega solicitada</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_ent_s']) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <p class="mc-card-label">Cliente</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['cliente']) ?></p>
          </div>
        </div>
        <?php if ($pedido['adjunto']): ?>
        <div class="mc-card mc-card-full">
          <div class="mc-card-icon blue"><i class="bi bi-paperclip"></i></div>
          <div>
            <p class="mc-card-label">Archivo adjunto</p>
            <p class="mc-card-val">
              <a href="adjuntos_opd/<?= htmlspecialchars($pedido['adjunto']) ?>" target="_blank" style="color:#2563eb">
                <?= htmlspecialchars($pedido['adjunto']) ?>
              </a>
            </p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <form method="POST" action="php/mod_opd.php" id="form_pedido">

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
                <div class="edit-row-label">Cliente <span class="req">*</span></div>
                <select class="form-control" name="persona" id="persona" style="width:100%" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($clientes as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= $c['id'] == $pedido['cid'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['cliente']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
          <input type="hidden" name="fecha_ent_s" value="<?= htmlspecialchars($pedido['fecha_ent_s']) ?>">
          <input type="hidden" name="persona"     value="<?= htmlspecialchars($pedido['cid']) ?>">
        <?php endif; ?>

        <!-- Tabla de materiales -->
        <div class="modern-card" style="margin-bottom: 20px">
          <div style="padding: 16px 20px 6px">
            <p style="font-size:.78rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin:0 0 0; padding-bottom:8px; border-bottom:2px solid #e2e8f0">Materiales</p>
          </div>
          <div class="opd-table-wrap px-2 pb-2" style="overflow-x:auto; max-height:480px; overflow-y:auto">
            <table class="table table-sm" id="opd-mat-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Título</th>
                  <th>Cantidad</th>
                  <th># Click</th>
                  <th>Impresora</th>
                  <th>Entrega 1</th>
                  <th>Entrega 2</th>
                  <th>Entrega 3</th>
                  <th>Total entregas</th>
                  <th>Total clicks</th>
                  <th>Valor</th>
                  <?php if ($_SESSION['tipo'] != 8): ?>
                  <th class="d-print-none">Acciones</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 1;
                foreach ($libros as $libro):
                  $lid = $libro['id'];

                  $r1 = $bdd->prepare("SELECT cant_entregada, observacion_entrega, fecha FROM entregas_opd e JOIN libros_opd l ON e.id_libro_opd=l.id WHERE l.opid=? AND l.id=? LIMIT 1");
                  $r1->execute([$opd, $lid]); $ent1 = $r1->fetch() ?: [];

                  $r2 = $bdd->prepare("SELECT cant_entregada, observacion_entrega, fecha FROM entregas_opd e JOIN libros_opd l ON e.id_libro_opd=l.id WHERE l.opid=? AND l.id=? LIMIT 1,2");
                  $r2->execute([$opd, $lid]); $ent2 = $r2->fetch() ?: [];

                  $r3 = $bdd->prepare("SELECT cant_entregada, observacion_entrega, fecha FROM entregas_opd e JOIN libros_opd l ON e.id_libro_opd=l.id WHERE l.opid=? AND l.id=? LIMIT 2,3");
                  $r3->execute([$opd, $lid]); $ent3 = $r3->fetch() ?: [];

                  $total_cantidad_arr[] = $libro['cantidad'];
                  $total_entr  = ($ent1['cant_entregada'] ?? 0) + ($ent2['cant_entregada'] ?? 0) + ($ent3['cant_entregada'] ?? 0);
                  $total_click = $total_entr * $libro['click'];
                  $valor       = $total_click * $libro['valor_click'];
                  $total_entregas_arr[] = $total_entr;
                  $total_clicks_arr[]   = $total_click;
                  $total_valor_arr[]    = $valor;
                ?>
                <tr id="<?= $lid ?>">
                  <td><?= $i ?></td>
                  <td><?= htmlspecialchars($libro['libro']) ?></td>
                  <td>
                    <?php if ($_SESSION['tipo'] != 8): ?>
                      <input type="number" class="form-control dc" min="0" max="5000" id="cantidad<?= $lid ?>" name="cantidad" value="<?= $libro['cantidad'] ?>">
                    <?php else: ?>
                      <?= $libro['cantidad'] ?>
                    <?php endif; ?>
                  </td>
                  <td><input type="number" class="form-control dc" min="0" id="click<?= $lid ?>" name="click" value="<?= $libro['click'] ?>"></td>
                  <td>
                    <select name="impresora" class="form-control" id="impresora<?= $lid ?>">
                      <option value="">Seleccione</option>
                      <?php foreach ($impresoras as $imp): ?>
                      <option value="<?= $imp['id'] ?>" data-valor="<?= $imp['valor_click'] ?>" <?= $libro['impresora'] == $imp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($imp['impresora']) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><?= ($ent1['cant_entregada'] ?? '') !== '' ? $ent1['cant_entregada'] : '<input type="number" class="form-control dc" min="0" max="5000" id="entrega1'.$lid.'">' ?></td>
                  <td><?= ($ent2['cant_entregada'] ?? '') !== '' ? $ent2['cant_entregada'] : '<input type="number" class="form-control dc" min="0" max="5000" id="entrega2'.$lid.'">' ?></td>
                  <td><?= ($ent3['cant_entregada'] ?? '') !== '' ? $ent3['cant_entregada'] : '<input type="number" class="form-control dc" min="0" max="5000" id="entrega3'.$lid.'">' ?></td>
                  <td><?= $total_entr ?></td>
                  <td><?= $total_click ?></td>
                  <td>$ <?= number_format($valor, 0, ',', '.') ?></td>
                  <?php if ($_SESSION['tipo'] != 8): ?>
                  <td class="d-print-none" style="text-align:center">
                    <button type="button" class="btn-del" data-lid="<?= $lid ?>" title="Eliminar">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                  <?php endif; ?>

                  <input type="hidden" name="lpid[]"        value="<?= $lid ?>" id="lpid<?= $lid ?>">
                  <input type="hidden" name="lib_p[]"       id="l<?= $lid ?>"           value="<?= $lid ?>/<?= $libro['cantidad'] ?>">
                  <input type="hidden" name="i_click[]"     id="i_click<?= $lid ?>"     value="<?= $lid ?>/<?= $libro['click'] ?>">
                  <input type="hidden" name="i_impresora[]" id="i_impresora<?= $lid ?>" value="<?= $lid ?>/<?= $libro['impresora'] ?>/<?= $libro['valor_click'] ?>">
                  <input type="hidden" name="entrega1[]"    id="ent1<?= $lid ?>">
                  <input type="hidden" name="entrega2[]"    id="ent2<?= $lid ?>">
                  <input type="hidden" name="entrega3[]"    id="ent3<?= $lid ?>">
                </tr>
                <?php $i++; endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td></td>
                  <td>Total</td>
                  <td><?= array_sum($total_cantidad_arr) ?></td>
                  <td></td><td></td><td></td><td></td><td></td>
                  <td><?= array_sum($total_entregas_arr) ?></td>
                  <td><?= array_sum($total_clicks_arr) ?></td>
                  <td>$ <?= number_format(array_sum($total_valor_arr), 0, ',', '.') ?></td>
                  <?php if ($_SESSION['tipo'] != 8): ?><td class="d-print-none"></td><?php endif; ?>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Agregar materiales -->
        <?php for ($j = 1; $j < 100; $j++): ?>
        <div id="agg_l<?= $j ?>" class="material-block d-none d-print-none">
          <div class="mat-header">
            <p class="mat-title">Material adicional #<?= $j ?></p>
            <button type="button" class="btn-remove-mat" data-idx="<?= $j ?>">
              <i class="bi bi-x-circle"></i> Eliminar
            </button>
          </div>
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label for="titulo<?= $j ?>">Título <span class="req">*</span></label>
                <input type="text" class="form-control" name="titulo" id="titulo<?= $j ?>">
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
                <label for="encaratulado<?= $j ?>">Encaratulado</label>
                <input type="text" class="form-control" name="encaratulado" id="encaratulado<?= $j ?>">
              </div>
            </div>
          </div>
          <input type="hidden" name="libro_e[]" id="libro_e<?= $j ?>">
        </div>
        <?php endfor; ?>

        <a id="agregar_libro" class="add-material-btn d-print-none mb-4 d-inline-flex">
          <i class="bi bi-plus-circle"></i> Agregar material
        </a>

        <!-- Observaciones -->
        <div class="mc-obs-wrap">
          <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
          <textarea name="observaciones" id="observaciones"
            <?= $_SESSION['tipo'] == 8 ? 'readonly' : '' ?>
            placeholder="Tipo de insumo"><?= htmlspecialchars($pedido['observaciones']) ?></textarea>
        </div>

        <input type="hidden" name="opd" value="<?= $opd ?>">

        <!-- Botones de acción -->
        <div class="mc-actions d-print-none">
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <?php if ($_SESSION['tipo'] != 2): ?>
            <button type="button" class="mc-btn mc-btn-amber" id="entregar">
              <i class="bi bi-box-seam"></i> Entregar
            </button>
          <?php endif; ?>
          <button type="button" class="mc-btn mc-btn-blue" id="modificar">
            <i class="bi bi-floppy"></i> Guardar cambios
          </button>
          <?php if (in_array($pedido['estado'], [0, 2])): ?>
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
  $('#persona').select2({
    placeholder: 'Seleccionar cliente',
    allowClear: true,
    width: '100%',
    language: { noResults: function () { return 'Sin resultados'; } }
  });

  $('#entregar').on('click', function () { $('#form_pedido').attr('action', 'php/entregar_opd.php').submit(); });
  $('#cumplida').on('click', function () { $('#form_pedido').attr('action', 'php/cumplir_opd.php').submit(); });
  $('#modificar').on('click', function () { $('#form_pedido').submit(); });
  $('#imprimir').on('click', function () { window.print(); });

  // Eliminar fila de material existente
  $(document).on('click', '.btn-del', function () {
    var lid = $(this).data('lid');
    $('#' + lid).remove();
    $('#lpid' + lid).remove();
  });

  // Actualizar hidden inputs al modificar campos de la tabla
  $(document).on('keyup', 'input[id^="cantidad"]', function () {
    var lid = this.id.slice('cantidad'.length);
    $('#l' + lid).val(lid + '/' + $(this).val());
  });
  $(document).on('keyup', 'input[id^="click"]', function () {
    var lid = this.id.slice('click'.length);
    $('#i_click' + lid).val(lid + '/' + $(this).val());
  });
  $(document).on('change', 'select[id^="impresora"]', function () {
    var lid = this.id.slice('impresora'.length);
    $('#i_impresora' + lid).val(lid + '/' + $(this).val() + '/' + $(this).find('option:selected').data('valor'));
  });
  $(document).on('keyup', 'input[id^="entrega1"]', function () {
    var lid = this.id.slice('entrega1'.length);
    $('#ent1' + lid).val(lid + '/' + $(this).val());
  });
  $(document).on('keyup', 'input[id^="entrega2"]', function () {
    var lid = this.id.slice('entrega2'.length);
    $('#ent2' + lid).val(lid + '/' + $(this).val());
  });
  $(document).on('keyup', 'input[id^="entrega3"]', function () {
    var lid = this.id.slice('entrega3'.length);
    $('#ent3' + lid).val(lid + '/' + $(this).val());
  });

  var m = 1;
  $('#agregar_libro').on('click', function () {
    if (m >= 99) { $(this).addClass('d-none'); return; }
    $('#agg_l' + m).removeClass('d-none');
    (function (idx) {
      $('#titulo' + idx + ', #cantidad_n' + idx + ', #encaratulado' + idx).on('keyup', function () {
        $('#libro_e' + idx).val($('#titulo' + idx).val() + '/' + $('#cantidad_n' + idx).val() + '/' + $('#encaratulado' + idx).val());
      });
    })(m);
    m++;
  });

  $(document).on('click', '.btn-remove-mat', function () {
    var idx = $(this).data('idx');
    $('#agg_l'        + idx).addClass('d-none');
    $('#titulo'       + idx).val('');
    $('#cantidad_n'   + idx).val('');
    $('#encaratulado' + idx).val('');
    $('#libro_e'      + idx).val('');
  });
});
</script>
</body>
</html>
