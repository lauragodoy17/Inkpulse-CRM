<?php
  require_once("php/aut.php");
  require_once("conexion/bdd.php");

  $sql = "SELECT o.id as opid, o.op_per,o.fecha, o.n_doc, o.solicitante, o.valor, o.guia,
                 o.fecha_entrega, o.archivo, o.observaciones, o.estado, o.transportista,
                 o.obs_envio, o.guia, o.adjunto_envio, o.ciudad_destino,
                 o.id_pedido, o.id_pedido_dist, o.id_muestreo,
                 o.id_devol_c, o.id_devol_p, o.id_devol_v, o.fecha_at, o.usuario_at, o.año,
                 t.id as tid, t.tipo, t.descrip,
                 c.id as cid, c.cliente, c.documento, c.direccion, c.telefonos, c.ciudad,
                 CONCAT(u.nombres,' ',u.apellidos) AS usuario,
                 e.estado AS n_estado
          FROM ordenes_pedidos o
          JOIN tipo_doc t ON o.tipo_doc=t.id
          JOIN clientes c ON c.id=o.cliente
          JOIN usuarios u ON u.id=o.usuario
          JOIN estados_op e ON e.id=o.estado
          WHERE o.id='".$_GET["op"]."'";

  $req = $bdd->prepare($sql); $req->execute();
  $op  = $req->fetch();

  $estado_cfg = [
    1 => ['label'=>'Pendiente', 'bg'=>'#fef3c7', 'color'=>'#b45309', 'icon'=>'bi-clock'],
    2 => ['label'=>'Atendida',  'bg'=>'#dcfce7', 'color'=>'#15803d', 'icon'=>'bi-check-circle-fill'],
    4 => ['label'=>'Anulada',   'bg'=>'#fee2e2', 'color'=>'#dc2626', 'icon'=>'bi-x-circle-fill'],
  ];
  $es = $estado_cfg[$op['estado']] ?? ['label'=>$op['n_estado'],'bg'=>'#f1f5f9','color'=>'#64748b','icon'=>'bi-circle'];
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <title>Inkpulse - <?= $op['estado']!=2 ? 'OP pendiente' : 'OP atendida' ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />

    <style>
      *, *::before, *::after { box-sizing: border-box; }

      /* ── OP Header card ─────────────────────────────────────── */
      .op-hdr {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 14px; padding: 20px 24px;
      }
      .op-hdr-left  { display: flex; align-items: center; gap: 16px; }
      .op-hdr-icon  {
        width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
        background: linear-gradient(135deg,#4361ee,#6d28d9);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; color: #fff;
      }
      .op-hdr-title { font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
      .op-hdr-meta  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
      .op-estado-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 12px; border-radius: 20px; font-size: .78rem; font-weight: 700;
        background: <?= $es['bg'] ?>; color: <?= $es['color'] ?>;
      }
      .op-fecha-tag { font-size: .78rem; color: #64748b; }
      .op-hdr-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
      .op-usuario-tag {
        display: flex; align-items: center; gap: 6px;
        font-size: .8rem; font-weight: 600; color: #374151;
        background: #f1f5f9; padding: 5px 12px; border-radius: 8px;
      }
      .op-btn-print {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 8px; font-size: .78rem; font-weight: 600;
        background: #0d9488; color: #fff; border: none;
        text-decoration: none; transition: opacity .15s;
      }
      .op-btn-print:hover { opacity: .88; text-decoration: none; color: #fff; }

      /* ── Section cards ──────────────────────────────────────── */
      .op-card { background: #fff; border-radius: 14px; overflow: hidden; margin-bottom: 18px;
                 box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04); }
      .op-card-head {
        padding: 14px 20px; border-bottom: 2px solid #c7d2fe;
        font-size: .88rem; font-weight: 700; color: #1e3a8a;
        display: flex; align-items: center; gap: 8px;
        background: #eef2ff; border-left: 4px solid #4361ee;
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
      }
      .op-card-head i { font-size: 1rem; color: #4361ee; }
      .op-card-body { padding: 20px 24px; }

      /* ── Info grids ─────────────────────────────────────────── */
      .op-info-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
      .op-info-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
      @media (max-width: 860px) { .op-info-4 { grid-template-columns: 1fr 1fr; } }
      @media (max-width: 540px) { .op-info-2, .op-info-4 { grid-template-columns: 1fr; } }

      .op-field-label {
        font-size: .68rem; color: #1e40af; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px;
      }
      .op-val { font-size: .875rem; font-weight: 500; color: #0f172a; line-height: 1.4; word-break: break-word; }
      .op-val.muted { color: #94a3b8; }
      .op-val a { color: #4361ee; font-weight: 600; text-decoration: none; }
      .op-val a:hover { text-decoration: underline; }
      .op-obs-text { font-size: .875rem; color: #374151; line-height: 1.6; white-space: pre-wrap; }

      /* ── Form fields ────────────────────────────────────────── */
      .op-form-grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 16px; }
      @media (max-width: 900px) { .op-form-grid-5 { grid-template-columns: 1fr 1fr 1fr; } }
      @media (max-width: 560px) { .op-form-grid-5 { grid-template-columns: 1fr 1fr; } }

      .op-input, .op-select, .op-textarea {
        width: 100%; padding: 8px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px;
        font-size: .875rem; font-family: 'Inter', sans-serif; color: #0f172a;
        background: #fff; transition: border-color .2s; box-sizing: border-box;
      }
      .op-input:focus, .op-select:focus, .op-textarea:focus {
        outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.08);
      }
      .op-input[type=file] { padding: 6px 10px; cursor: pointer; }
      .op-textarea { resize: vertical; min-height: 90px; }

      .op-required { color: #ef4444; font-size: .75rem; margin-left: 2px; }

      /* Select2 override to match op-input */
      #cliente + .select2-container .select2-selection--single {
        height: auto !important; border: 1.5px solid #e2e8f0 !important;
        border-radius: 8px !important; padding: 7px 12px !important;
        font-size: .875rem; font-family: 'Inter', sans-serif; color: #0f172a;
      }
      #cliente + .select2-container .select2-selection__arrow { top: 7px !important; }
      #cliente + .select2-container--focus .select2-selection--single {
        border-color: #4361ee !important; box-shadow: 0 0 0 3px rgba(67,97,238,.08) !important;
      }
      #tipo_doc { max-width: 100%; }

      /* ── Despacho info rows ──────────────────────────────────── */
      .op-desp-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 12px; }
      @media (max-width: 860px) { .op-desp-grid { grid-template-columns: 1fr 1fr 1fr; } }
      @media (max-width: 560px) { .op-desp-grid { grid-template-columns: 1fr 1fr; } }

      /* ── Action buttons ──────────────────────────────────────── */
      .op-actions { display: flex; justify-content: center; gap: 14px; margin-top: 6px; flex-wrap: wrap; }
      .op-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 30px; border-radius: 10px; font-size: .9rem; font-weight: 700;
        border: none; cursor: pointer; text-decoration: none; transition: opacity .15s;
      }
      .op-btn:hover { opacity: .88; text-decoration: none; }
      .op-btn-atender { background: linear-gradient(135deg,#22c55e,#16a34a); color: #fff; }
      .op-btn-anular  { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; }

      input[type=number] { -moz-appearance:textfield; }
      input[type=number]::-webkit-inner-spin-button,
      input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    </style>
  </head>
  <body>
    <?php include("template/nav_side.php"); ?>
    <div class="main-container">
      <div class="pd-ltr-20 xs-pd-20-10">
        <div class="min-height-200px">

          <!-- Breadcrumb -->
          <div class="page-header">
            <div class="row">
              <div class="col-md-6 col-sm-12">
                <div class="title">
                  <h4><?= $op['estado']!=2 ? 'OP pendiente' : 'OP atendida' ?></h4>
                </div>
                <nav aria-label="breadcrumb" role="navigation">
                  <ol class="breadcrumb">
                    <?php
                      $tp_back = [1=>2, 2=>3, 4=>4];
                      $tp_link = $tp_back[$op['estado']] ?? 1;
                    ?>
                    <li class="breadcrumb-item"><a href="lista_op.php?tp=<?= $tp_link ?>">OP</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                      <?= $es['label'] ?>
                    </li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>

          <!-- OP Header -->
          <div class="op-card">
            <div class="op-hdr">
              <div class="op-hdr-left">
                <div class="op-hdr-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div>
                  <h2 class="op-hdr-title">OP # <?= htmlspecialchars($op['año'].' - '.$op['opid']) ?></h2>
                  <div class="op-hdr-meta">
                    <span class="op-estado-badge">
                      <i class="bi <?= $es['icon'] ?>"></i> <?= $es['label'] ?>
                    </span>
                    <span class="op-fecha-tag"><i class="bi bi-calendar3"></i> Fecha de creación: <?= htmlspecialchars($op['fecha']) ?></span>
                  </div>
                </div>
              </div>
              <div class="op-hdr-right">
                <div class="op-usuario-tag">
                  <i class="bi bi-person-circle"></i> <?= htmlspecialchars($op['usuario']) ?>
                </div>
                <a href="formato_op.php?op=<?= $op['opid'] ?>" target="_blank" class="op-btn-print">
                  <i class="bi bi-printer"></i> Imprimir
                </a>
              </div>
            </div>
          </div>

          <form action="php/procesar_op.php" method="POST" enctype="multipart/form-data">

          <!-- Información general -->
          <div class="op-card">
            <div class="op-card-head"><i class="bi bi-info-circle"></i> Información general</div>
            <div class="op-card-body">
              <div class="op-info-2">
                <!-- Tipo de documento -->
                <div>
                  <p class="op-field-label">Tipo de documento</p>
                  <?php if ($op['estado'] == 4): ?>
                    <p class="op-val"><?= htmlspecialchars($op['tipo']) ?></p>
                  <?php else: ?>
                    <select name="tipo_doc" id="tipo_doc" class="op-select">
                      <?php
                        $r = $bdd->prepare("SELECT id, tipo FROM tipo_doc");
                        $r->execute(); $tipos = $r->fetchAll();
                        foreach ($tipos as $tipo) {
                          $sel = ($op['tid']==$tipo['id']) ? 'selected' : '';
                          echo "<option value='{$tipo['id']}' $sel>{$tipo['tipo']}</option>";
                        }
                      ?>
                    </select>
                  <?php endif; ?>
                </div>

                <!-- Cliente -->
                <div>
                  <p class="op-field-label">Cliente</p>
                  <?php if ($op['estado'] == 4): ?>
                    <p class="op-val"><?= htmlspecialchars($op['cliente']) ?></p>
                  <?php else: ?>
                    <select name="cliente" id="cliente">
                      <?php
                        $r = $bdd->prepare("SELECT id, cliente FROM clientes");
                        $r->execute(); $clientes = $r->fetchAll();
                        foreach ($clientes as $cli) {
                          $sel = ($op['cid']==$cli['id']) ? 'selected' : '';
                          echo "<option value='{$cli['id']}' $sel>{$cli['cliente']}</option>";
                        }
                      ?>
                    </select>
                  <?php endif; ?>
                </div>
              </div>

              <div class="op-info-4">
                <!-- Contacto -->
                <div>
                  <p class="op-field-label">Contacto</p>
                  <p class="op-val"><?= !empty($op['solicitante']) ? htmlspecialchars($op['solicitante']) : '<span class="muted">—</span>' ?></p>
                </div>

                <!-- Ciudad destino -->
                <div>
                  <p class="op-field-label">Ciudad destino</p>
                  <p class="op-val"><?= !empty($op['ciudad_destino']) ? htmlspecialchars($op['ciudad_destino']) : '<span class="muted">—</span>' ?></p>
                </div>

                <!-- Archivo adjunto (solo cuando no hay pedido de venta) -->
                <?php if ($op['id_pedido'] == 0): ?>
                <div>
                  <p class="op-field-label">Archivo adjunto</p>
                  <p class="op-val">
                    <?php if (!empty($op['archivo'])): ?>
                      <a href="adjuntos/<?= htmlspecialchars($op['archivo']) ?>" target="_blank">
                        <i class="bi bi-paperclip"></i> <?= htmlspecialchars($op['archivo']) ?>
                      </a>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </p>
                </div>
                <?php endif; ?>

                <!-- Fuente / origen -->
                <?php
                  if ($op['id_pedido'] != 0) {
                    $r = $bdd->prepare("SELECT estado FROM pedidos WHERE id='".$op['id_pedido']."'");
                    $r->execute(); $pv = $r->fetch();
                    $tp = ($pv['estado']==2) ? 3 : 4;
                    echo '<div><p class="op-field-label">Pedido de venta</p><p class="op-val"><a href="pedido_colegio.php?id_pedido='.$op['id_pedido'].'&tp='.$tp.'" target="_blank">#'.$op['id_pedido'].'</a></p></div>';
                  }
                  if ($op['id_pedido_dist'] != 0) {
                    $r = $bdd->prepare("SELECT estado FROM pedidos2 WHERE id='".$op['id_pedido_dist']."'");
                    $r->execute(); $pd = $r->fetch();
                    $tp = ($pd['estado']==2) ? 3 : 4;
                    echo '<div><p class="op-field-label">Pedido sin adopción</p><p class="op-val"><a href="pedido_colegio_sa.php?id_pedido='.$op['id_pedido_dist'].'&tp='.$tp.'" target="_blank">#'.$op['id_pedido_dist'].'</a></p></div>';
                  }
                  if ($op['id_muestreo'] != 0) {
                    $r = $bdd->prepare("SELECT estado FROM muestreos WHERE id='".$op['id_muestreo']."'");
                    $r->execute(); $ms = $r->fetch();
                    $tp = ($ms['estado']==2) ? 3 : 4;
                    echo '<div><p class="op-field-label">Pedido de muestras</p><p class="op-val"><a href="muestreo_colegio_resto.php?id_pedido='.$op['id_muestreo'].'&tp='.$tp.'" target="_blank">#'.$op['id_muestreo'].'</a></p></div>';
                  }
                  if ($op['id_devol_c'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de muestra</p><p class="op-val"><a href="vista_devol.php?id_devol='.$op['id_devol_c'].'&tipo=1" target="_blank">#'.$op['id_devol_c'].'</a></p></div>';
                  }
                  if ($op['id_devol_p'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de proveedor</p><p class="op-val"><a href="vista_devol.php?id_devol='.$op['id_devol_p'].'&tipo=2" target="_blank">#'.$op['id_devol_p'].'</a></p></div>';
                  }
                  if ($op['id_devol_v'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de venta</p><p class="op-val"><a href="devolucion_colegio.php?id_pedido='.$op['id_devol_v'].'" target="_blank">#'.$op['id_devol_v'].'</a></p></div>';
                  }
                  if ($op['id_pedido']==0 && $op['id_pedido_dist']==0 && $op['id_muestreo']==0
                    && $op['id_devol_c']==0 && $op['id_devol_p']==0 && $op['id_devol_v']==0) {
                    $r_ag = $bdd->prepare("SELECT id_pedido FROM op_pedidos_agrupados WHERE op='".$op['opid']."'");
                    $r_ag->execute(); $agps = $r_ag->fetchAll();
                    if (!empty($agps)) {
                      echo '<div><p class="op-field-label">Pedidos agrupados</p><p class="op-val">';
                      foreach ($agps as $agp) {
                        echo '<a href="pedido_colegio_aprobado.php?id_pedido='.$agp['id_pedido'].'" target="_blank">#'.$agp['id_pedido'].'</a> ';
                      }
                      echo '</p></div>';
                    }
                  }
                ?>

                <!-- Si estado==2: mostrar quien y cuando atendió -->
                <?php if ($op['estado'] == 2):
                  $r = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) AS usr_aten FROM usuarios WHERE id='".$op['usuario_at']."'");
                  $r->execute(); $aten = $r->fetch();
                ?>
                <div>
                  <p class="op-field-label">Fecha de atención</p>
                  <p class="op-val"><?= htmlspecialchars($op['fecha_at']) ?></p>
                </div>
                <div>
                  <p class="op-field-label">Atendido por</p>
                  <p class="op-val"><?= htmlspecialchars($aten['usr_aten'] ?? '—') ?></p>
                </div>
                <?php endif; ?>

              </div>
            </div>
          </div>

          <!-- Observaciones -->
          <div class="op-card">
            <div class="op-card-head"><i class="bi bi-chat-text"></i> Observaciones</div>
            <div class="op-card-body">
              <?php if (!empty($op['observaciones'])): ?>
                <p class="op-obs-text"><?= htmlspecialchars($op['observaciones']) ?></p>
              <?php else: ?>
                <p class="op-val muted">Sin observaciones</p>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($op['estado'] != 4): ?>

            <?php if ($op['estado'] == 2):
              $r_at = $bdd->prepare("SELECT transportista, n_doc, guia, fecha_entrega, valor, obs_envio, adjunto_envio FROM op_atendidas WHERE opid='".$_GET['op']."'");
              $r_at->execute(); $ats = $r_at->fetchAll();
              foreach ($ats as $at):
            ?>
            <!-- Despacho registrado -->
            <div class="op-card">
              <div class="op-card-head" style="color:#15803d"><i class="bi bi-check-circle-fill" style="color:#15803d"></i> Despacho registrado</div>
              <div class="op-card-body">
                <div class="op-desp-grid" style="margin-bottom:12px">
                  <div>
                    <p class="op-field-label">Número de documento</p>
                    <p class="op-val"><?= htmlspecialchars($at['n_doc']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Entregado a</p>
                    <p class="op-val"><?= htmlspecialchars($at['transportista']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Guía</p>
                    <p class="op-val"><?= htmlspecialchars($at['guia']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Fecha de despacho</p>
                    <p class="op-val"><?= htmlspecialchars($at['fecha_entrega']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Valor despachado</p>
                    <p class="op-val"><?= htmlspecialchars($at['valor']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                </div>
                <?php if (!empty($at['obs_envio'])): ?>
                <div style="margin-bottom:12px">
                  <p class="op-field-label">Observaciones de despacho</p>
                  <p class="op-obs-text"><?= htmlspecialchars($at['obs_envio']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($at['adjunto_envio'])): ?>
                <div>
                  <p class="op-field-label">Adjunto soporte de entrega</p>
                  <p class="op-val">
                    <a href="adjuntos/envio/<?= htmlspecialchars($at['adjunto_envio']) ?>" target="_blank">
                      <i class="bi bi-paperclip"></i> <?= htmlspecialchars($at['adjunto_envio']) ?>
                    </a>
                  </p>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; endif; ?>

            <!-- Formulario de despacho -->
            <div class="op-card">
              <div class="op-card-head"><i class="bi bi-truck"></i> Registrar despacho</div>
              <div class="op-card-body">
                <div class="op-form-grid-5">
                  <div>
                    <p class="op-field-label">Número de documento <span class="op-required">*</span></p>
                    <input required type="text" name="n_doc" id="n_doc" placeholder="Documento del sistema" class="op-input" />
                  </div>
                  <div>
                    <p class="op-field-label">Entregado a <span class="op-required">*</span></p>
                    <input required type="text" name="transportista" id="transportista" placeholder="Entregado a" class="op-input" />
                  </div>
                  <div>
                    <p class="op-field-label">Guía</p>
                    <input type="text" name="guia" id="guia" placeholder="Guía" class="op-input" />
                  </div>
                  <div>
                    <p class="op-field-label">Fecha despacho <span class="op-required">*</span></p>
                    <input required type="text" data-date-format="yyyy-mm-dd" name="fecha_entrega" id="fecha_entrega" placeholder="Fecha despacho" class="op-input date-picker" />
                  </div>
                  <div>
                    <p class="op-field-label">Valor despachado <span class="op-required">*</span></p>
                    <input required type="text" name="valor" id="valor" placeholder="Valor despachado" class="op-input" />
                  </div>
                </div>

                <div style="margin-bottom:16px">
                  <p class="op-field-label">Observaciones de despacho</p>
                  <textarea name="obs_envio" id="obs_envio" class="op-textarea" placeholder="Observaciones del despacho..."></textarea>
                </div>

                <div>
                  <p class="op-field-label">Soporte de entrega <span class="op-required">*</span></p>
                  <input type="file" name="adjunto_envio" id="adjunto_envio" class="op-input" required="" />
                </div>
              </div>
            </div>

            <input type="hidden" name="op" value="<?= $op['opid'] ?>">

            <!-- Botones de acción -->
            <div class="op-actions">
              <button type="submit" class="op-btn op-btn-atender">
                <i class="bi bi-check-lg"></i> Atender
              </button>
              <?php if ($op['estado'] == 1): ?>
              <a class="op-btn op-btn-anular" data-toggle="modal" data-target="#ModalAnu">
                <i class="bi bi-x-lg"></i> Anular
              </a>
              <?php endif; ?>
            </div>

          <?php endif; ?>

          </form>

          <!-- Modal Anular -->
          <div class="modal fade" id="ModalAnu" tabindex="-1" role="dialog" aria-labelledby="modalAnuLabel">
            <div class="modal-dialog" role="document">
              <div class="modal-content" style="border-radius:12px; overflow:hidden;">
                <form class="form-horizontal" method="POST" id="form_anu" action="php/anular_op.php">
                  <div class="modal-header" style="background:#fff4f4; border-bottom:1px solid #fecaca;">
                    <h4 class="modal-title" id="modalAnuLabel" style="color:#dc2626; font-weight:700; font-size:1rem;">
                      <i class="bi bi-x-circle-fill"></i> Anular OP
                    </h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body" style="padding:20px;">
                    <div class="form-group">
                      <label class="op-field-label" for="motivo_anu">Motivo de anulación <span class="op-required">*</span></label>
                      <textarea name="motivo_anu" id="motivo_anu" class="op-textarea" required></textarea>
                      <input type="hidden" name="op" value="<?= $op['opid'] ?>">
                    </div>
                  </div>
                  <div class="modal-footer" style="background:#fafafa;">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="op-btn op-btn-anular" id="anular">
                      <i class="bi bi-x-lg"></i> Anular
                    </button>
                  </div>
                </form>
              </div>
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

    <script>
      $(document).ready(function(){
        $('#cliente').select2({
          placeholder: 'Seleccionar cliente',
          allowClear: true,
          minimumResultsForSearch: 0,
          width: '100%',
          language: {
            noResults: function(){ return 'Sin resultados'; },
            searching:  function(){ return 'Buscando...'; }
          }
        });
      });

      $('#materia').on('change',function(){
        var valor = $(this).val();
        var dataString = 'mat_gra='+valor;
        $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:dataString, dataType:"html",
          success:function(resp){ $("#libro").html(resp); },
          error:function(){ alert("error"); }
        });
      });

      $('#libro').on('change',function(){
        var cant=$('#cantidad').val(), libro=$('#libro').val();
        var grado=$('#libro option:selected').attr('data-grado');
        if (grado==15||grado==16) {
          $('#l_cantidad').addClass("d-none"); $('#cantidad').addClass("d-none");
          $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
            success:function(resp){ $("#ls_pri_sec").html('').append(resp); }
          });
        } else { $('#libro_e').val(libro+'/'+cant); }
      });

      $('#cantidad').keyup(function(){
        var cant=$('#cantidad').val(), libro=$('#libro').val();
        var grado=$('#libro option:selected').attr('data-grado');
        if (grado!=15||grado!=16) { $('#libro_e').val(libro+'/'+cant); }
      });

      var m=1;
      $("#agregar_libro").click(function(){
        if (m>98) { $("#agregar_libro").addClass("d-none"); }
        $("#agg_l"+m).removeClass("d-none"); m++;
        <?php for ($i=1; $i<100; $i++) { ?>
          $('#materia<?php echo $i; ?>').on('change',function(){
            var v=$(this).val();
            $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:'mat_gra='+v, dataType:"html",
              success:function(resp){ $("#libro<?php echo $i; ?>").html(resp); }
            });
          });
          $('#libro<?php echo $i; ?>').on('change',function(){
            var cant=$('#cantidad<?php echo $i; ?>').val(), libro=$('#libro<?php echo $i; ?>').val();
            var grado=$('#libro<?php echo $i; ?> option:selected').attr('data-grado');
            if (grado==15||grado==16) {
              $('#l_cantidad<?php echo $i; ?>').addClass("d-none"); $('#cantidad<?php echo $i; ?>').addClass("d-none");
              $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
                success:function(resp){ $("#ls_pri_sec<?php echo $i; ?>").html('').append(resp); }
              });
            } else { $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant); }
          });
          $('#cantidad<?php echo $i; ?>').keyup(function(){
            var cant=$('#cantidad<?php echo $i; ?>').val(), libro=$('#libro<?php echo $i; ?>').val();
            var grado=$('#libro option:selected').attr('data-grado');
            if (grado!=15||grado!=16) { $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant); }
          });
        <?php } ?>
      });
      $('form[action="php/procesar_op.php"]').on('submit', function(e){
        e.preventDefault();
        var form = this;
        inkConfirm({
          title: '¿Atender esta OP?',
          text:  'Se registrará el despacho y la OP quedará como atendida.',
          type:  'success',
          btnOk: 'Sí, atender'
        }, function(){ form.submit(); });
      });
    </script>
<script src="src/ink-alerts.js"></script>
  </body>
</html>
