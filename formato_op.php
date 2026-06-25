<?php
  require_once("php/aut.php");
  require_once('conexion/bdd.php');

  $sql = "SELECT o.id as opid, o.op_per, o.fecha, o.n_doc, o.solicitante, o.valor, o.guia,
                 o.fecha_entrega, o.archivo, o.observaciones, o.estado, o.transportista,
                 o.obs_envio, o.adjunto_envio, o.fecha_anu, o.motivo_anu, o.ciudad_destino,
                 o.usuario_anu, o.id_pedido, o.id_pedido_dist, o.id_muestreo,
                 o.id_devol_c, o.id_devol_p, o.id_devol_v, o.fecha_at, o.usuario_at,
                 o.año, t.tipo, t.descrip, c.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario
          FROM ordenes_pedidos o
          JOIN tipo_doc t ON o.tipo_doc = t.id
          JOIN clientes c ON c.id = o.cliente
          JOIN usuarios u ON u.id = o.usuario
          WHERE o.id = '".$_GET["op"]."'";
  $req = $bdd->prepare($sql); $req->execute();
  $op  = $req->fetch();

  // Config de estado
  $estado_cfg = [
    1 => ['label'=>'Pendiente', 'bg'=>'#dbeafe', 'color'=>'#1d4ed8'],
    2 => ['label'=>'Atendida',  'bg'=>'#dcfce7', 'color'=>'#15803d'],
    4 => ['label'=>'Anulada',   'bg'=>'#fee2e2', 'color'=>'#dc2626'],
  ];
  $st = $estado_cfg[$op['estado']] ?? ['label'=>'—','bg'=>'#f1f5f9','color'=>'#64748b'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
  <title>OP <?= htmlspecialchars($op['año'].'-'.$op['opid']) ?></title>
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #f1f5f9; font-family: 'Inter', sans-serif; color: #1e293b; margin: 0; padding: 0; }

    .op-container { max-width: 960px; margin: 30px auto; padding: 0 20px 40px; }

    /* ── Header ─────────────────────────────────────────────── */
    .op-header {
      background: #fff; border-radius: 14px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04);
      padding: 22px 28px; margin-bottom: 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 16px;
    }
    .op-header-left { display: flex; align-items: center; gap: 16px; }
    .op-header-icon {
      width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
      background: linear-gradient(135deg, #4361ee 0%, #6d28d9 100%);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; color: #fff;
    }
    .op-title { font-size: 1.3rem; font-weight: 800; color: #0f172a; margin: 0; }
    .op-status {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 16px; border-radius: 20px; font-size: .82rem; font-weight: 700;
      background: <?= $st['bg'] ?>; color: <?= $st['color'] ?>;
      flex-shrink: 0;
    }

    /* ── Meta cards ─────────────────────────────────────────── */
    .op-meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 14px; margin-bottom: 18px;
    }
    .op-meta-card {
      background: #fff; border-radius: 12px; padding: 14px 18px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04);
      display: flex; align-items: flex-start; gap: 12px;
    }
    .op-meta-icon {
      width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0; margin-top: 2px;
      display: flex; align-items: center; justify-content: center; font-size: 1rem;
    }
    .op-meta-icon.orange { background:#ffedd5; color:#c2410c; }
    .op-meta-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .op-meta-icon.teal   { background:#ccfbf1; color:#0d9488; }
    .op-meta-icon.purple { background:#ede9fe; color:#6d28d9; }
    .op-meta-icon.green  { background:#dcfce7; color:#15803d; }
    .op-meta-label { font-size:.68rem; color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin:0 0 4px; }
    .op-meta-val   { font-size:.88rem; font-weight:700; color:#0f172a; margin:0; word-break:break-word; line-height:1.35; }

    /* ── Two-column body ────────────────────────────────────── */
    .op-body { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
    @media (max-width: 650px) { .op-body { grid-template-columns: 1fr; } }

    .op-card {
      background: #fff; border-radius: 14px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04);
      overflow: hidden;
    }
    .op-card-head {
      display: flex; align-items: center; gap: 10px;
      padding: 14px 20px; border-bottom: 2px solid #c7d2fe;
      font-size: .88rem; font-weight: 700; color: #1e3a8a;
      background: #eef2ff; border-left: 4px solid #4361ee;
      print-color-adjust: exact; -webkit-print-color-adjust: exact;
    }
    .op-card-head i { font-size: 1rem; color: #4361ee; }

    .op-field {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 10px 20px; border-bottom: 1px solid #cbd5e1;
    }
    .op-field:last-child { border-bottom: none; }
    .op-field-icon {
      width: 30px; height: 30px; border-radius: 7px; flex-shrink: 0; margin-top: 1px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
      background: #f1f5f9; color: #64748b;
    }
    .op-field-body { min-width: 0; }
    .op-field-label { font-size:.68rem; color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin:0 0 2px; }
    .op-field-val   { font-size:.875rem; color:#0f172a; font-weight:500; margin:0; word-break:break-word; line-height:1.4; }
    .op-field-val a { color:#4361ee; font-weight:600; text-decoration:none; }
    .op-field-val a:hover { text-decoration:underline; }
    .op-field-val.muted { color:#94a3b8; }

    /* ── Extra sections (atendida / anulada) ────────────────── */
    .op-extra {
      background: #fff; border-radius: 14px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04);
      overflow: hidden; margin-bottom: 18px;
    }
    .op-extra-head {
      display: flex; align-items: center; gap: 10px;
      padding: 14px 20px; border-bottom: 2px solid #e2e8f0;
      font-size: .88rem; font-weight: 700; color: #0f172a;
      background: #f8fafc; border-left: 4px solid currentColor;
      print-color-adjust: exact; -webkit-print-color-adjust: exact;
    }
    .op-extra-head i { font-size: 1rem; }
    .op-extra-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0;
    }

    /* ── Actions ────────────────────────────────────────────── */
    .op-actions {
      display: flex; justify-content: flex-end; gap: 12px; margin-top: 6px;
    }
    .op-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 10px 24px; border-radius: 9px; font-size: .875rem; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none; transition: opacity .15s;
    }
    .op-btn:hover { opacity: .88; text-decoration: none; }
    .op-btn-print { background: #0d9488; color: #fff; border: none; }
    .op-btn-back  { background: linear-gradient(135deg,#4361ee,#6d28d9); color:#fff; }

    /* ── Print ──────────────────────────────────────────────── */
    @media print {
      body { background: #fff; }
      .op-container { margin: 0; padding: 0; max-width: 100%; }
      .op-actions { display: none !important; }
      a[href]:after { content: none !important; }
      .op-header { box-shadow: none; border: 1px solid #e2e8f0; }
      .op-meta-card, .op-card, .op-extra { box-shadow: none; border: 1px solid #cbd5e1; }
      .op-card-head {
        background: #eef2ff !important; border-left: 4px solid #4361ee !important;
        border-bottom: 2px solid #c7d2fe !important; color: #1e3a8a !important;
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
      }
      .op-field { border-bottom: 1px solid #cbd5e1 !important; }
      .op-extra-head {
        background: #f8fafc !important; border-left: 4px solid currentColor !important;
        border-bottom: 2px solid #e2e8f0 !important;
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
      }
      .op-field-label { color: #1e40af !important; }
    }
  </style>
</head>
<body>

<div class="op-container">

  <!-- Header -->
  <div class="op-header">
    <div class="op-header-left">
      <div class="op-header-icon"><i class="bi bi-file-earmark-text"></i></div>
      <h1 class="op-title">Orden de pedido # <?= htmlspecialchars($op['año'].' - '.$op['opid']) ?></h1>
    </div>
    <span class="op-status">
      <?php if ($op['estado']==1): ?><i class="bi bi-clock"></i>
      <?php elseif ($op['estado']==2): ?><i class="bi bi-check-circle-fill"></i>
      <?php elseif ($op['estado']==4): ?><i class="bi bi-x-circle-fill"></i>
      <?php endif; ?>
      <?= $st['label'] ?>
    </span>
  </div>

  <!-- Meta cards -->
  <div class="op-meta">
    <div class="op-meta-card">
      <div class="op-meta-icon orange"><i class="bi bi-calendar3"></i></div>
      <div><p class="op-meta-label">Fecha y hora</p><p class="op-meta-val"><?= htmlspecialchars($op['fecha']) ?></p></div>
    </div>
    <div class="op-meta-card">
      <div class="op-meta-icon blue"><i class="bi bi-file-text"></i></div>
      <div><p class="op-meta-label">Tipo de documento</p><p class="op-meta-val"><?= htmlspecialchars($op['tipo'].' ('.$op['descrip'].')') ?></p></div>
    </div>
    <div class="op-meta-card">
      <div class="op-meta-icon teal"><i class="bi bi-person"></i></div>
      <div><p class="op-meta-label">Usuario</p><p class="op-meta-val"><?= htmlspecialchars($op['usuario']) ?></p></div>
    </div>
    <div class="op-meta-card">
      <div class="op-meta-icon purple"><i class="bi bi-hash"></i></div>
      <div><p class="op-meta-label">Número de documento</p><p class="op-meta-val"><?= htmlspecialchars($op['n_doc']) ?></p></div>
    </div>
    <?php if (!empty($op['doc_alterno'])): ?>
    <div class="op-meta-card">
      <div class="op-meta-icon green"><i class="bi bi-card-text"></i></div>
      <div><p class="op-meta-label">Documento alterno</p><p class="op-meta-val"><?= htmlspecialchars($op['doc_alterno']) ?></p></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Two-column body -->
  <div class="op-body">

    <!-- Información del cliente -->
    <div class="op-card">
      <div class="op-card-head"><i class="bi bi-person-vcard"></i> Información del cliente</div>

      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-building"></i></div>
        <div class="op-field-body"><p class="op-field-label">Cliente</p><p class="op-field-val"><?= htmlspecialchars($op['cliente']) ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-credit-card"></i></div>
        <div class="op-field-body"><p class="op-field-label">Identificación</p><p class="op-field-val"><?= htmlspecialchars($op['documento']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-geo-alt"></i></div>
        <div class="op-field-body"><p class="op-field-label">Ciudad</p><p class="op-field-val"><?= htmlspecialchars($op['ciudad']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-house"></i></div>
        <div class="op-field-body"><p class="op-field-label">Dirección</p><p class="op-field-val"><?= htmlspecialchars($op['direccion']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-telephone"></i></div>
        <div class="op-field-body"><p class="op-field-label">Teléfono</p><p class="op-field-val"><?= htmlspecialchars($op['telefonos']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-person-check"></i></div>
        <div class="op-field-body"><p class="op-field-label">Contacto</p><p class="op-field-val"><?= htmlspecialchars($op['solicitante']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-send"></i></div>
        <div class="op-field-body"><p class="op-field-label">Ciudad destino</p><p class="op-field-val"><?= htmlspecialchars($op['ciudad_destino']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>

      <?php if ($op['id_pedido'] == 0): ?>
      <div class="op-field d-print-none">
        <div class="op-field-icon"><i class="bi bi-paperclip"></i></div>
        <div class="op-field-body">
          <p class="op-field-label">Archivo adjunto</p>
          <p class="op-field-val">
            <?php
              if (!empty($op['archivo'])) {
                $partes  = explode("_", $op['archivo'], 2);
                $archivo = $partes[1] ?? $op['archivo'];
                echo '<a href="adjuntos/'.htmlspecialchars($op['archivo']).'" target="_blank" download="archivo">'.htmlspecialchars($archivo).'</a>';
              } else {
                echo '<span class="muted">—</span>';
              }
            ?>
          </p>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Información de la solicitud -->
    <div class="op-card">
      <div class="op-card-head"><i class="bi bi-clipboard-check"></i> Información de la solicitud</div>

      <?php
        // Pedido de venta
        if ($op['id_pedido'] != 0) {
          $r = $bdd->prepare("SELECT estado FROM pedidos WHERE id='".$op['id_pedido']."'");
          $r->execute(); $pv = $r->fetch();
          $url_pv = ($pv['estado']==2)
            ? 'pedido_colegio_aprobado.php?id_pedido='.$op['id_pedido']
            : 'pedido_colegio_entregado.php?id_pedido='.$op['id_pedido'];
      ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-bag-check"></i></div>
        <div class="op-field-body"><p class="op-field-label">Pedido de venta</p>
          <p class="op-field-val"><a href="<?= $url_pv ?>" target="_blank">#<?= $op['id_pedido'] ?></a></p></div>
      </div>
      <?php } ?>

      <?php
        // Pedido distribuidor
        if ($op['id_pedido_dist'] != 0) {
          $r = $bdd->prepare("SELECT estado FROM pedidos2 WHERE id='".$op['id_pedido_dist']."'");
          $r->execute(); $pd = $r->fetch();
          $url_pd = ($pd['estado']==2)
            ? 'pedido_colegio_aprobado2.php?id_pedido='.$op['id_pedido_dist']
            : 'pedido_colegio_entregado2.php?id_pedido='.$op['id_pedido_dist'];
      ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-truck"></i></div>
        <div class="op-field-body"><p class="op-field-label">Pedido distribuidor</p>
          <p class="op-field-val"><a href="<?= $url_pd ?>" target="_blank">#<?= $op['id_pedido_dist'] ?></a></p></div>
      </div>
      <?php } ?>

      <?php
        // Muestreo
        if ($op['id_muestreo'] != 0) {
          $r = $bdd->prepare("SELECT estado FROM muestreos WHERE id='".$op['id_muestreo']."'");
          $r->execute(); $ms = $r->fetch();
          $tp_ms = ($ms['estado']==2) ? 3 : 4;
      ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-box-seam"></i></div>
        <div class="op-field-body"><p class="op-field-label">Muestreo</p>
          <p class="op-field-val"><a href="muestreo_colegio_resto.php?id_pedido=<?= $op['id_muestreo'] ?>&tp=<?= $tp_ms ?>" target="_blank">#<?= $op['id_muestreo'] ?></a></p></div>
      </div>
      <?php } ?>

      <?php if ($op['id_devol_c'] != 0): ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-arrow-return-left"></i></div>
        <div class="op-field-body"><p class="op-field-label">Devolución de cliente</p>
          <p class="op-field-val"><a href="vista_devol.php?id_pedido=<?= $op['id_devol_c'] ?>&tipo=1" target="_blank">#<?= $op['id_devol_c'] ?></a></p></div>
      </div>
      <?php endif; ?>

      <?php if ($op['id_devol_p'] != 0): ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-arrow-return-left"></i></div>
        <div class="op-field-body"><p class="op-field-label">Devolución de proveedor</p>
          <p class="op-field-val"><a href="vista_devol.php?id_pedido=<?= $op['id_devol_p'] ?>&tipo=2" target="_blank">#<?= $op['id_devol_p'] ?></a></p></div>
      </div>
      <?php endif; ?>

      <?php if ($op['id_devol_v'] != 0): ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-arrow-return-left"></i></div>
        <div class="op-field-body"><p class="op-field-label">Devolución de venta</p>
          <p class="op-field-val"><a href="vista_devol.php?id_pedido=<?= $op['id_devol_v'] ?>&tipo=2" target="_blank">#<?= $op['id_devol_v'] ?></a></p></div>
      </div>
      <?php endif; ?>

      <?php
        // Pedidos agrupados
        if ($op['id_pedido']==0 && $op['id_pedido_dist']==0 && $op['id_muestreo']==0
          && $op['id_devol_c']==0 && $op['id_devol_p']==0 && $op['id_devol_v']==0) {
          $r_ag = $bdd->prepare("SELECT id_pedido FROM op_pedidos_agrupados WHERE op='".$op['opid']."'");
          $r_ag->execute(); $agps = $r_ag->fetchAll();
          if (!empty($agps)):
      ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-collection"></i></div>
        <div class="op-field-body"><p class="op-field-label">Pedidos de venta agrupados</p>
          <p class="op-field-val">
            <?php foreach ($agps as $agp): ?>
              <a href="pedido_colegio_aprobado.php?id_pedido=<?= $agp['id_pedido'] ?>" target="_blank">#<?= $agp['id_pedido'] ?></a>&nbsp;
            <?php endforeach; ?>
          </p>
        </div>
      </div>
      <?php endif; } ?>

      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-chat-text"></i></div>
        <div class="op-field-body"><p class="op-field-label">Observaciones</p>
          <p class="op-field-val"><?= !empty($op['observaciones']) ? htmlspecialchars($op['observaciones']) : '<span class="muted">—</span>' ?></p></div>
      </div>
    </div>

  </div><!-- /.op-body -->

  <?php if ($op['estado'] == 2):
    $r_at = $bdd->prepare("SELECT transportista, n_doc, guia, fecha_entrega, valor, obs_envio, adjunto_envio, fecha_at, usuario_at FROM op_atendidas WHERE opid='".$_GET['op']."'");
    $r_at->execute(); $ats = $r_at->fetchAll();
    foreach ($ats as $at):
      $r_usr = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) AS usr_aten FROM usuarios WHERE id='".$at['usuario_at']."'");
      $r_usr->execute(); $aten = $r_usr->fetch();
  ?>
  <div class="op-extra">
    <div class="op-extra-head" style="color:#15803d"><i class="bi bi-check-circle-fill" style="color:#15803d"></i> Información de despacho</div>
    <div class="op-extra-grid">
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-calendar-check"></i></div>
        <div class="op-field-body"><p class="op-field-label">Fecha atendida</p><p class="op-field-val"><?= htmlspecialchars($at['fecha_at']) ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-person"></i></div>
        <div class="op-field-body"><p class="op-field-label">Usuario atendido</p><p class="op-field-val"><?= htmlspecialchars($aten['usr_aten'] ?? '—') ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-truck"></i></div>
        <div class="op-field-body"><p class="op-field-label">Entregado a</p><p class="op-field-val"><?= htmlspecialchars($at['transportista']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-upc-scan"></i></div>
        <div class="op-field-body"><p class="op-field-label">Guía</p><p class="op-field-val"><?= htmlspecialchars($at['guia']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-currency-dollar"></i></div>
        <div class="op-field-body"><p class="op-field-label">Valor</p><p class="op-field-val"><?= htmlspecialchars($at['valor']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-calendar3"></i></div>
        <div class="op-field-body"><p class="op-field-label">Fecha de despacho</p><p class="op-field-val"><?= htmlspecialchars($at['fecha_entrega']) ?: '<span class="muted">—</span>' ?></p></div>
      </div>
      <?php if (!empty($at['obs_envio'])): ?>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-chat-text"></i></div>
        <div class="op-field-body"><p class="op-field-label">Observaciones de despacho</p><p class="op-field-val"><?= htmlspecialchars($at['obs_envio']) ?></p></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($at['adjunto_envio'])): ?>
      <div class="op-field d-print-none">
        <div class="op-field-icon"><i class="bi bi-paperclip"></i></div>
        <div class="op-field-body">
          <p class="op-field-label">Adjunto soporte de entrega</p>
          <p class="op-field-val">
            <?php
              $p_env  = explode("_", $at['adjunto_envio'], 2);
              $f_env  = $p_env[1] ?? $at['adjunto_envio'];
              echo '<a href="adjuntos/envio/'.htmlspecialchars($at['adjunto_envio']).'" target="_blank" download="'.htmlspecialchars($f_env).'">'.htmlspecialchars($f_env).'</a>';
            ?>
          </p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php if ($op['estado'] == 4):
    $r_anu = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) AS usr_anu FROM usuarios WHERE id='".$op['usuario_anu']."'");
    $r_anu->execute(); $anu = $r_anu->fetch();
  ?>
  <div class="op-extra">
    <div class="op-extra-head" style="color:#dc2626"><i class="bi bi-x-circle-fill" style="color:#dc2626"></i> Información de anulación</div>
    <div class="op-extra-grid">
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-person"></i></div>
        <div class="op-field-body"><p class="op-field-label">Usuario anulación</p><p class="op-field-val"><?= htmlspecialchars($anu['usr_anu'] ?? '—') ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-calendar-x"></i></div>
        <div class="op-field-body"><p class="op-field-label">Fecha de anulación</p><p class="op-field-val"><?= htmlspecialchars($op['fecha_anu']) ?></p></div>
      </div>
      <div class="op-field">
        <div class="op-field-icon"><i class="bi bi-chat-square-text"></i></div>
        <div class="op-field-body"><p class="op-field-label">Motivo de anulación</p><p class="op-field-val"><?= htmlspecialchars($op['motivo_anu']) ?></p></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Botones -->
  <div class="op-actions d-print-none">
    <button class="op-btn op-btn-print" onclick="window.print()">
      <i class="bi bi-printer"></i> Imprimir
    </button>
    <a href="lista_op.php?tp=2" class="op-btn op-btn-back">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
  </div>

</div>

<script src="assets/js/jquery-2.1.4.min.js"></script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
