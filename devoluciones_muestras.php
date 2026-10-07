<?php
/**
 * /devoluciones_muestras.php
 * Módulo "Devoluciones" de muestras: reemplaza el formulario manual devol_muestras_sa.php?tp=1
 * (pedido por el usuario 2026-10-05). Lista los pedidos de muestras (muestreos) Entregados, y al
 * elegir uno trae de World Office los títulos y cantidades realmente despachados para devolverlos
 * con tope. Lógica en includes/devoluciones_muestras_datos.php.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_muestras_datos.php");

dm_validar_acceso();
dm_asegurar_columnas($bdd);

$muestreos = dm_marcar_completados($bdd, dm_listar_muestreos_entregados($bdd), 'devolucion');
$colegios = [];
$con_devolucion = 0;
$completados = 0;
foreach ($muestreos as $m) {
  $colegios[$m['id_colegio']] = $m['colegio'];
  if ((int)$m['n_devoluciones'] > 0) $con_devolucion++;
  if ($m['completado']) $completados++;
}
asort($colegios, SORT_NATURAL | SORT_FLAG_CASE);
$puede_adjuntar = in_array((int)$_SESSION['tipo'], [1, 2], true);
$id_inicial = (int)($_GET['id_muestreo'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Devoluciones de muestras</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    :root { --dm-azul:#1d4ed8; --dm-azul2:#2563eb; --dm-borde:#e2e8f0; --dm-gris:#64748b; --dm-tinta:#0f172a; }

    /* Encabezado */
    .dm-hero { background:linear-gradient(135deg,#1e3a8a 0%,#1d4ed8 55%,#3b82f6 100%); border-radius:16px; padding:22px 26px; color:#fff; margin-bottom:20px; position:relative; overflow:hidden; box-shadow:0 10px 30px rgba(29,78,216,.25); }
    .dm-hero::after { content:''; position:absolute; right:-60px; top:-60px; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.08); }
    .dm-hero h4 { color:#fff; font-weight:800; margin:0; font-size:1.35rem; display:flex; align-items:center; gap:10px; }
    .dm-hero p { color:rgba(255,255,255,.85); margin:6px 0 0; font-size:.88rem; max-width:720px; }
    .dm-flujo { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; position:relative; z-index:1; }
    .dm-flujo span { background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.22); border-radius:999px; padding:3px 11px; font-size:.74rem; font-weight:600; }
    .dm-flujo i { opacity:.7; font-size:.7rem; align-self:center; }
    .dm-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
    .dm-stat { background:#fff; border-radius:14px; padding:14px 18px; box-shadow:0 2px 10px rgba(15,23,42,.06); display:flex; align-items:center; gap:14px; }
    .dm-stat-ico { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0; }
    .dm-stat-ico.azul { background:#dbeafe; color:#1d4ed8; } .dm-stat-ico.verde { background:#dcfce7; color:#15803d; } .dm-stat-ico.ambar { background:#fef3c7; color:#b45309; }
    .dm-stat-num { font-size:1.35rem; font-weight:800; color:var(--dm-tinta); line-height:1.1; }
    .dm-stat-lbl { font-size:.74rem; color:var(--dm-gris); font-weight:600; }

    /* Secciones */
    .dm-section { background:#fff; border-radius:16px; box-shadow:0 2px 12px rgba(15,23,42,.07); margin-bottom:20px; overflow:hidden; }
    .dm-section-head { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:16px 24px; border-bottom:1px solid var(--dm-borde); }
    .dm-section-title { font-size:.98rem; font-weight:700; color:var(--dm-tinta); margin:0; display:flex; align-items:center; gap:10px; }
    .dm-paso { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,var(--dm-azul),var(--dm-azul2)); color:#fff; font-size:.8rem; font-weight:800; }
    .dm-section-body { padding:20px 24px; }

    /* Filtros */
    .dm-toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
    .dm-campo label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--dm-gris); margin:0 0 5px; }
    .dm-buscar { position:relative; }
    .dm-buscar i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; }
    .dm-buscar input { padding-left:34px; height:38px; border-radius:10px; border:1px solid #cbd5e1; font-size:.88rem; width:260px; }
    .dm-buscar input:focus, .dm-input:focus { border-color:var(--dm-azul2); box-shadow:0 0 0 3px rgba(37,99,235,.15); outline:none; }
    .select2-container .select2-selection--single { height:38px !important; border:1px solid #cbd5e1 !important; border-radius:10px !important; }
    .select2-container .select2-selection__rendered { line-height:36px !important; font-size:.88rem; }
    .select2-container .select2-selection__arrow { height:36px !important; }
    .dm-limpiar { background:none; border:none; color:var(--dm-azul); font-size:.82rem; font-weight:700; cursor:pointer; padding:0 4px; height:38px; }

    /* Tabla de pedidos */
    .dm-table { width:100% !important; font-size:.85rem; border-collapse:separate; border-spacing:0; }
    .dm-table thead th { background:#f8fafc; color:var(--dm-gris); font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; font-weight:800; padding:10px 12px; border-bottom:1px solid var(--dm-borde) !important; border-top:none !important; white-space:nowrap; }
    .dm-table tbody td { padding:12px; border-bottom:1px solid #f1f5f9; border-top:none !important; vertical-align:middle; }
    .dm-table tbody tr { transition:background .12s; }
    .dm-table tbody tr:hover td { background:#f8faff; }
    .dm-table tbody tr.dm-activo td { background:#eef2ff; }
    .dm-table tbody tr.dm-activo td:first-child { box-shadow:inset 3px 0 0 var(--dm-azul); }
    .dm-ped { font-weight:800; color:var(--dm-azul); font-size:.92rem; }
    .dm-ped:hover { text-decoration:underline; }
    .dm-cole { font-weight:600; color:var(--dm-tinta); }
    .dm-sub { font-size:.75rem; color:#94a3b8; margin-top:2px; display:flex; align-items:center; gap:4px; }
    .dm-pill { display:inline-flex; align-items:center; gap:5px; font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
    .dm-pill-azul { background:#dbeafe; color:#1d4ed8; } .dm-pill-verde { background:#dcfce7; color:#15803d; }
    .dm-pill-gris { background:#f1f5f9; color:#64748b; } .dm-pill-ambar { background:#fef3c7; color:#b45309; }
    .dm-pill-rem { background:#dcfce7; color:#15803d; } .dm-pill-fv { background:#ede9fe; color:#6d28d9; }
    .dm-op { font-weight:700; color:#334155; white-space:nowrap; }
    .dm-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 15px; border-radius:9px; font-size:.8rem; font-weight:700; background:linear-gradient(135deg,var(--dm-azul),var(--dm-azul2)); color:#fff; border:none; cursor:pointer; white-space:nowrap; box-shadow:0 2px 6px rgba(29,78,216,.25); transition:transform .1s, box-shadow .15s; }
    .dm-btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(29,78,216,.3); color:#fff; }
    .dm-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }
    .dm-btn-lg { padding:11px 24px; font-size:.9rem; border-radius:11px; }
    .dm-btn-light { background:#fff; color:var(--dm-azul); border:1.5px solid #bfdbfe; box-shadow:none; }
    .dm-btn-light:hover { color:var(--dm-azul); background:#eff6ff; box-shadow:none; }
    .dataTables_wrapper .dataTables_info { font-size:.8rem; color:var(--dm-gris); padding-top:14px !important; }
    .dataTables_wrapper .dataTables_paginate { padding-top:10px !important; }
    .dataTables_wrapper .page-link { border-radius:8px !important; margin:0 2px; border:none; color:#475569; font-size:.82rem; }
    .dataTables_wrapper .page-item.active .page-link { background:var(--dm-azul); }
    .dataTables_wrapper .dataTables_length { font-size:.8rem; color:var(--dm-gris); }

    /* Detalle */
    .dm-resumen { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:18px; }
    .dm-dato { background:#f8fafc; border:1px solid var(--dm-borde); border-radius:12px; padding:12px 14px; display:flex; gap:12px; align-items:center; }
    .dm-dato i { width:34px; height:34px; border-radius:10px; background:#fff; color:var(--dm-azul); display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(15,23,42,.08); flex-shrink:0; }
    .dm-dato-lbl { font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
    .dm-dato-val { font-size:.88rem; font-weight:700; color:var(--dm-tinta); word-break:break-word; }
    .dm-subtitulo { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--dm-gris); margin:4px 0 10px; display:flex; align-items:center; gap:8px; }
    .dm-docs { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:12px; margin-bottom:20px; }
    .dm-doc { border:1px solid var(--dm-borde); border-radius:12px; padding:12px 14px; background:#fff; position:relative; }
    .dm-doc::before { content:''; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:0 3px 3px 0; background:var(--dm-azul2); }
    .dm-doc-top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px; }
    .dm-doc-num { font-weight:800; color:var(--dm-tinta); font-size:.95rem; }
    .dm-doc-fecha { margin-left:auto; font-size:.75rem; color:#94a3b8; }
    .dm-doc-concepto { font-size:.77rem; color:var(--dm-gris); line-height:1.45; }
    .dm-aviso { border-radius:12px; padding:12px 16px; font-size:.85rem; margin-bottom:14px; display:flex; gap:10px; align-items:flex-start; }
    .dm-aviso i { font-size:1rem; margin-top:1px; }
    .dm-aviso.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .dm-aviso.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .dm-aviso.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }

    .dm-tit-table { width:100%; font-size:.85rem; border-collapse:separate; border-spacing:0; }
    .dm-tit-table th { background:#f8fafc; color:var(--dm-gris); font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; font-weight:800; padding:10px 12px; border-bottom:1px solid var(--dm-borde); white-space:nowrap; }
    .dm-tit-table td { padding:12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .dm-tit-table tr.dm-agotado td { opacity:.55; }
    .dm-tit-table .c { text-align:center; }
    .dm-libro { font-weight:700; color:var(--dm-tinta); }
    .dm-isbn { font-size:.74rem; color:#94a3b8; font-family:ui-monospace,Consolas,monospace; }
    .dm-barra { height:6px; border-radius:999px; background:#e2e8f0; overflow:hidden; width:110px; margin:6px auto 0; }
    .dm-barra span { display:block; height:100%; background:linear-gradient(90deg,#f59e0b,#f97316); border-radius:999px; }
    .dm-num { font-weight:800; font-size:.95rem; color:var(--dm-tinta); }
    .dm-num-sub { font-size:.7rem; color:#94a3b8; font-weight:600; }
    .dm-stepper { display:inline-flex; align-items:center; border:1px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#fff; }
    .dm-stepper button { width:32px; height:34px; border:none; background:#f8fafc; color:#334155; font-weight:800; cursor:pointer; font-size:1rem; }
    .dm-stepper button:hover { background:#e2e8f0; }
    .dm-input { width:56px; height:34px; border:none; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; font-weight:800; font-size:.92rem; color:var(--dm-tinta); -moz-appearance:textfield; }
    .dm-input::-webkit-inner-spin-button, .dm-input::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    .dm-stepper.activo { border-color:var(--dm-azul2); box-shadow:0 0 0 3px rgba(37,99,235,.12); }
    .dm-stepper.activo .dm-input { color:var(--dm-azul); }
    .dm-max { display:block; font-size:.7rem; color:#94a3b8; margin-top:4px; font-weight:600; }

    .dm-pie { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; background:#f8fafc; border-top:1px solid var(--dm-borde); margin:20px -24px -20px; padding:16px 24px; }
    .dm-total { display:flex; align-items:baseline; gap:8px; }
    .dm-total strong { font-size:1.5rem; font-weight:800; color:var(--dm-azul); }
    .dm-total span { font-size:.82rem; color:var(--dm-gris); font-weight:600; }
    .dm-cierre { margin-top:18px; }
    .dm-cierre .dm-dato-lbl { margin-bottom:8px; }
    .dm-cierre-opts { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .dm-opc { display:flex; gap:10px; align-items:flex-start; border:1.5px solid var(--dm-borde); border-radius:12px; padding:12px 14px; cursor:pointer; margin:0; transition:border-color .15s, background .15s; }
    .dm-opc:hover { border-color:#bfdbfe; }
    .dm-opc input { margin-top:3px; accent-color:var(--dm-azul); }
    .dm-opc strong { display:block; font-size:.88rem; color:var(--dm-tinta); }
    .dm-opc small { display:block; font-size:.76rem; color:var(--dm-gris); margin-top:2px; line-height:1.4; }
    .dm-opc:has(input:checked) { border-color:var(--dm-azul2); background:#eff6ff; }
    @media (max-width:768px) { .dm-cierre-opts { grid-template-columns:1fr; } }
    .dm-campos { display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-top:18px; }
    .dm-campos textarea, .dm-campos input[type=file] { border-radius:10px; font-size:.86rem; }
    @media (max-width:768px) { .dm-campos { grid-template-columns:1fr; } .dm-buscar input { width:100%; } }

    .dm-cargando { text-align:center; padding:50px 10px; color:var(--dm-gris); }
    .dm-spinner { width:38px; height:38px; border:3px solid #dbeafe; border-top-color:var(--dm-azul); border-radius:50%; animation:dm-giro .8s linear infinite; margin:0 auto 12px; }
    @keyframes dm-giro { to { transform:rotate(360deg); } }
    .dm-vacio { text-align:center; padding:46px 10px; color:#94a3b8; }
    .dm-vacio i { font-size:2.2rem; display:block; margin-bottom:8px; color:#cbd5e1; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="dm-hero">
        <h4><i class="bi bi-arrow-return-left"></i> Devoluciones de muestras</h4>
        <p>Elige un pedido de muestras entregado y registra lo que el colegio devuelve. Los títulos y las cantidades salen de lo que realmente se despachó en World Office.</p>
        <div class="dm-flujo">
          <span>Pedido entregado</span><i class="bi bi-chevron-right"></i>
          <span>OP</span><i class="bi bi-chevron-right"></i>
          <span>Factura / remisión en WO</span><i class="bi bi-chevron-right"></i>
          <span>Títulos despachados</span><i class="bi bi-chevron-right"></i>
          <span>Devolución</span>
        </div>
      </div>

      <div class="dm-stats">
        <div class="dm-stat"><div class="dm-stat-ico azul"><i class="bi bi-box-seam"></i></div><div><div class="dm-stat-num"><?= number_format(count($muestreos), 0, ',', '.') ?></div><div class="dm-stat-lbl">Pedidos entregados</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-building"></i></div><div><div class="dm-stat-num"><?= number_format(count($colegios), 0, ',', '.') ?></div><div class="dm-stat-lbl">Colegios</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico ambar"><i class="bi bi-arrow-counterclockwise"></i></div><div><div class="dm-stat-num"><?= number_format($con_devolucion, 0, ',', '.') ?></div><div class="dm-stat-lbl">Con devoluciones registradas</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-check2-circle"></i></div><div><div class="dm-stat-num"><?= number_format($completados, 0, ',', '.') ?></div><div class="dm-stat-lbl">Completados</div></div></div>
      </div>

      <!-- Paso 1 -->
      <div class="dm-section">
        <div class="dm-section-head">
          <h5 class="dm-section-title"><span class="dm-paso">1</span> Elige el pedido de muestras</h5>
          <span class="dm-pill dm-pill-gris" id="dm-contador"><?= count($muestreos) ?> pedidos</span>
        </div>
        <div class="dm-section-body">
          <div class="dm-toolbar">
            <div class="dm-campo" style="min-width:300px;flex:1;max-width:420px;">
              <label for="f_colegio">Colegio</label>
              <select id="f_colegio" class="custom-select2" style="width:100%;">
                <option value="">Todos los colegios</option>
                <?php foreach ($colegios as $nombre): ?>
                  <option value="<?= htmlspecialchars($nombre) ?>"><?= htmlspecialchars($nombre) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dm-campo">
              <label for="f_estado">Estado</label>
              <select id="f_estado" class="form-control" style="height:38px;border-radius:10px;font-size:.88rem;min-width:160px;">
                <option value="pendiente" selected>Por devolver</option>
                <option value="completado">Completados</option>
              </select>
            </div>
            <div class="dm-campo">
              <label for="f_buscar">Buscar</label>
              <div class="dm-buscar"><i class="bi bi-search"></i><input type="text" id="f_buscar" placeholder="<?= $puede_adjuntar ? 'Pedido, OP, asesor...' : 'Pedido, OP' ?>"></div>
            </div>
            <button type="button" class="dm-limpiar" id="f_limpiar"><i class="bi bi-x-circle"></i> Limpiar</button>
          </div>

          <table class="dm-table" id="dm-tabla">
            <thead>
              <tr>
                <th>Pedido</th>
                <th>Colegio</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>OP</th>
                <th>Devoluciones</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($muestreos as $m): $nd = (int)$m['n_devoluciones']; ?>
                <tr data-id="<?= (int)$m['id'] ?>" data-estado="<?= $m['completado'] ? 'completado' : 'pendiente' ?>">
                  <td data-order="<?= (int)$m['id'] ?>"><a class="dm-ped" href="muestreo_colegio.php?id_muestreo=<?= (int)$m['id'] ?>" target="_blank">#<?= (int)$m['id'] ?></a></td>
                  <td data-search="<?= htmlspecialchars($m['colegio'] . ' ' . ($m['solicitante'] ?? '')) ?>" data-filter-colegio="<?= htmlspecialchars($m['colegio']) ?>">
                    <div class="dm-cole"><?= htmlspecialchars($m['colegio']) ?></div>
                    <?php if (!empty($m['solicitante'])): ?><div class="dm-sub"><i class="bi bi-person"></i><?= htmlspecialchars($m['solicitante']) ?></div><?php endif; ?>
                  </td>
                  <td data-order="<?= htmlspecialchars($m['fecha']) ?>" style="white-space:nowrap;"><?= htmlspecialchars(date('d/m/Y', strtotime($m['fecha']))) ?></td>
                  <td><?= $m['completado']
                    ? '<span class="dm-pill dm-pill-verde" title="Ya se devolvió todo lo despachado"><i class="bi bi-check2-circle"></i> Completado</span>'
                    : '<span class="dm-pill dm-pill-azul"><i class="bi bi-truck"></i> Entregado</span>' ?></td>
                  <td>
                    <?php if ($m['ops']): ?>
                      <div class="dm-op">OP <?= htmlspecialchars($m['ops']) ?></div>
                      <?php if (!empty($m['docs_op'])): ?><div class="dm-sub" title="Documento registrado en la OP al despacharla"><i class="bi bi-receipt"></i><?= htmlspecialchars($m['docs_op']) ?></div><?php endif; ?>
                    <?php else: ?>
                      <span class="dm-pill dm-pill-gris">Sin OP</span>
                    <?php endif; ?>
                  </td>
                  <td data-order="<?= $nd ?>"><?= $nd > 0 ? '<span class="dm-pill dm-pill-ambar">' . $nd . ' registrada' . ($nd > 1 ? 's' : '') . '</span>' : '<span class="dm-pill dm-pill-gris">Ninguna</span>' ?></td>
                  <td style="text-align:right;"><?php if ($m['completado']): ?>
                    <button type="button" class="dm-btn dm-btn-light dm-btn-sel" data-id="<?= (int)$m['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
                  <?php else: ?>
                    <button type="button" class="dm-btn dm-btn-sel" data-id="<?= (int)$m['id'] ?>">Devolver <i class="bi bi-arrow-right"></i></button>
                  <?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Paso 2 -->
      <div class="dm-section d-none" id="dm-paso2">
        <div class="dm-section-head">
          <h5 class="dm-section-title"><span class="dm-paso">2</span> <span id="dm-paso2-titulo">Títulos despachados</span></h5>
          <button type="button" class="dm-btn dm-btn-light" id="dm-cerrar" style="padding:6px 12px;"><i class="bi bi-x-lg"></i> Cerrar</button>
        </div>
        <div class="dm-section-body" id="dm-detalle"></div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/ink-alerts.js"></script>
<script>
(function () {
  var PUEDE_ADJUNTAR = <?= $puede_adjuntar ? 'true' : 'false' ?>;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  var tabla = $('#dm-tabla').DataTable({
    order: [[0, 'desc']],
    pageLength: 15,
    lengthMenu: [15, 30, 50, 100],
    dom: 't<"d-flex justify-content-between align-items-center flex-wrap"ilp>',
    columnDefs: [{ orderable: false, targets: [3, 6] }],
    language: {
      lengthMenu: 'Mostrar _MENU_', zeroRecords: 'No hay pedidos que coincidan con el filtro',
      emptyTable: 'No hay pedidos de muestras entregados', info: '_START_–_END_ de _TOTAL_ pedidos',
      infoEmpty: 'Sin pedidos', infoFiltered: '(de _MAX_)', paginate: { first: '«', previous: '‹', next: '›', last: '»' }
    },
    drawCallback: function () {
      var info = this.api().page.info();
      $('#dm-contador').text(info.recordsDisplay + ' pedido' + (info.recordsDisplay === 1 ? '' : 's'));
    }
  });

  // Filtro por colegio exacto (no por coincidencia parcial: "San José" no debe traer "San José de...").
  var colegioFiltro = '';
  $.fn.dataTable.ext.search.push(function (settings, data, idx) {
    if (settings.nTable.id !== 'dm-tabla' || !colegioFiltro) return true;
    return $(tabla.row(idx).node()).find('td').eq(1).attr('data-filter-colegio') === colegioFiltro;
  });
  // Los completados no salen en la lista principal; solo al elegir "Completados" (pedido por el
  // usuario 2026-10-07).
  var estadoFiltro = 'pendiente';
  $.fn.dataTable.ext.search.push(function (settings, data, idx) {
    if (settings.nTable.id !== 'dm-tabla' || !estadoFiltro) return true;
    return $(tabla.row(idx).node()).attr('data-estado') === estadoFiltro;
  });
  tabla.draw(); // aplicar el filtro de estado desde la primera carga
  $('#f_estado').on('change', function () { estadoFiltro = $(this).val() || 'pendiente'; tabla.draw(); });
  $('#f_colegio').on('change', function () { colegioFiltro = $(this).val() || ''; tabla.draw(); });
  $('#f_buscar').on('keyup', function () { tabla.search(this.value).draw(); });
  $('#f_limpiar').on('click', function () {
    $('#f_buscar').val(''); colegioFiltro = ''; $('#f_colegio').val('').trigger('change.select2');
    estadoFiltro = 'pendiente'; $('#f_estado').val('pendiente');
    tabla.search('').draw();
  });
  $('#dm-cerrar').on('click', function () {
    $('#dm-paso2').addClass('d-none'); $('#dm-tabla tr').removeClass('dm-activo');
  });

  function dato(icono, label, val) {
    return '<div class="dm-dato"><i class="bi ' + icono + '"></i><div><div class="dm-dato-lbl">' + esc(label) + '</div><div class="dm-dato-val">' + esc(val) + '</div></div></div>';
  }
  function aviso(tipo, icono, html) { return '<div class="dm-aviso ' + tipo + '"><i class="bi ' + icono + '"></i><div>' + html + '</div></div>'; }

  function cargar(id) {
    $('#dm-tabla tr').removeClass('dm-activo');
    $('#dm-tabla tr[data-id="' + id + '"]').addClass('dm-activo');
    $('#dm-paso2').removeClass('d-none');
    $('#dm-paso2-titulo').text('Títulos despachados del pedido #' + id);
    $('#dm-detalle').html('<div class="dm-cargando"><div class="dm-spinner"></div>Buscando la OP y lo despachado en World Office...</div>');
    $('html, body').animate({ scrollTop: $('#dm-paso2').offset().top - 80 }, 300);

    $.getJSON('ajax/devoluciones_muestras_detalle.php', { id_muestreo: id })
      .done(function (r) { pintar(id, r); })
      .fail(function () { $('#dm-detalle').html(aviso('err', 'bi-x-octagon', 'No se pudo consultar el pedido. Intenta de nuevo.')); });
  }

  function pintar(id, r) {
    var h = '';
    if (r.muestreo) {
      var ops = (r.ops || []).map(function (o) { return 'OP ' + o.anio + '-' + o.id; }).join(', ') || '—';
      h += '<div class="dm-resumen">'
        + dato('bi-box-seam', 'Pedido de muestras', '#' + r.muestreo.id)
        + dato('bi-building', 'Colegio', r.muestreo.colegio)
        + dato('bi-calendar3', 'Fecha del pedido', r.muestreo.fecha)
        + dato('bi-person', 'Solicitado por', r.muestreo.solicitante || '—')
        + dato('bi-person-badge', 'Cliente', r.muestreo.cliente || '—')
        + dato('bi-file-earmark-check', 'OP', ops)
        + '</div>';
    }
    if (!r.ok) { $('#dm-detalle').html(h + aviso('err', 'bi-x-octagon', esc(r.error))); return; }
    if (!r.documentos.length) {
      $('#dm-detalle').html(h + '<div class="dm-vacio"><i class="bi bi-search"></i>No se encontró en World Office ninguna factura o remisión con la OP de este pedido en el concepto.<br>Sin documento no hay títulos despachados para devolver.</div>');
      return;
    }

    h += '<div class="dm-subtitulo"><i class="bi bi-receipt"></i> Documentos de World Office</div><div class="dm-docs">';
    var avisos = '';
    r.documentos.forEach(function (d) {
      h += '<div class="dm-doc"><div class="dm-doc-top">'
        + '<span class="dm-pill ' + (d.tipo === 'FV' ? 'dm-pill-fv' : 'dm-pill-rem') + '">' + (d.tipo === 'FV' ? 'Factura' : 'Remisión') + '</span>'
        + '<span class="dm-doc-num">' + esc(d.documento) + '</span>'
        + '<span class="dm-doc-fecha"><i class="bi bi-calendar3"></i> ' + esc(d.fecha) + '</span></div>'
        + '<div class="dm-doc-concepto">' + esc(d.concepto) + '</div></div>';
      if (d.ped !== null && d.ped != id) {
        avisos += aviso('warn', 'bi-exclamation-triangle', 'El concepto de <strong>' + esc(d.documento) + '</strong> dice PED ' + esc(d.ped) + ' y este pedido es el #' + id + '. Se tomó porque la OP coincide; revísalo antes de guardar.');
      }
    });
    h += '</div>' + avisos;

    // Cerrado con una devolución "completa": ya no se registra nada aunque queden unidades.
    var cerrado = !!r.cierre;
    var hayDisponible = !cerrado && r.titulos.some(function (t) { return t.id_libro && t.disponible > 0; });
    h += '<div class="dm-subtitulo" style="justify-content:space-between;"><span><i class="bi bi-book"></i> Títulos despachados (' + r.titulos.length + ')</span>'
      + (hayDisponible ? '<button type="button" class="dm-btn dm-btn-light" id="dm-todo" style="padding:5px 12px;font-size:.75rem;"><i class="bi bi-check2-all"></i> Devolver todo lo disponible</button>' : '')
      + '</div>';

    h += '<form id="dm-form" method="POST" action="php/devoluciones_muestras_guardar.php" enctype="multipart/form-data">'
      + '<input type="hidden" name="id_muestreo" value="' + id + '">'
      + '<div class="table-responsive"><table class="dm-tit-table"><thead><tr>'
      + '<th>Título</th><th class="c" title="E = estudiante, D = docente">Tipo</th><th>Documento</th><th class="c">Despachada</th><th class="c">Ya devuelta</th><th class="c">Disponible</th><th class="c">A devolver</th>'
      + '</tr></thead><tbody>';
    r.titulos.forEach(function (t) {
      var sinLibro = !t.id_libro, editable = !cerrado && !sinLibro && t.disponible > 0;
      var pct = t.despachada > 0 ? Math.min(100, Math.round(t.devuelta * 100 / t.despachada)) : 0;
      h += '<tr' + (!editable ? ' class="dm-agotado"' : '') + '>'
        + '<td><div class="dm-libro">' + esc(t.descripcion) + '</div><div class="dm-isbn">' + esc(t.codigo) + '</div>'
        + (sinLibro ? '<div class="dm-sub" style="color:#dc2626;"><i class="bi bi-exclamation-circle"></i> No está en el catálogo del CRM</div>' : '') + '</td>'
        + '<td class="c">' + (t.tipo_ed ? '<span class="dm-pill ' + (t.tipo_ed === 'E' ? 'dm-pill-azul' : 'dm-pill-fv') + '" title="' + (t.tipo_ed === 'E' ? 'Estudiante' : 'Docente') + '">' + t.tipo_ed + '</span>' : '—') + '</td>'
        + '<td><span class="dm-pill dm-pill-gris">' + esc(t.documentos) + '</span></td>'
        + '<td class="c"><span class="dm-num">' + t.despachada + '</span></td>'
        + '<td class="c"><span class="dm-num">' + t.devuelta + '</span><div class="dm-barra" title="' + pct + '% devuelto"><span style="width:' + pct + '%"></span></div></td>'
        + '<td class="c">' + (t.disponible > 0 ? '<span class="dm-pill dm-pill-verde">' + t.disponible + '</span>' : '<span class="dm-pill dm-pill-gris">0</span>') + '</td>'
        + '<td class="c">' + (editable
            ? '<div class="dm-stepper"><button type="button" class="dm-menos" tabindex="-1">−</button>'
              + '<input type="number" class="dm-input" name="cantidad[' + t.producto_wo + ']" min="0" max="' + t.disponible + '" step="1" value="0" data-max="' + t.disponible + '" data-tipo="' + (t.tipo_ed || '') + '" data-titulo="' + esc(t.descripcion) + '">'
              + '<button type="button" class="dm-mas" tabindex="-1">+</button></div><span class="dm-max">máx. ' + t.disponible + '</span>'
            : '<span class="dm-sub" style="justify-content:center;">' + (sinLibro ? 'No disponible' : (cerrado && t.disponible > 0 ? 'Cerrado' : 'Ya devuelto')) + '</span>')
        + '</td></tr>';
    });
    h += '</tbody></table></div>';

    if (!hayDisponible) {
      h += (cerrado
        ? aviso('ok', 'bi-check-circle', '<strong>Completado.</strong> Se cerró con la <a href="vista_devol.php?id_devol=' + r.cierre.id + '&tipo=1" target="_blank">devolución completa #' + r.cierre.id + '</a>'
            + (r.cierre.usuario ? ' de ' + esc(r.cierre.usuario) : '') + '. Ya no se pueden registrar más devoluciones de este pedido.')
        : aviso('ok', 'bi-check-circle', '<strong>Completado.</strong> Ya se devolvió todo lo despachado en este pedido' + (r.titulos.some(function (t) { return !t.id_libro; }) ? ' (o los títulos pendientes no están en el catálogo del CRM).' : '.')))
        + '</form>';
      $('#dm-detalle').html(h);
      return;
    }
    h += '<div class="dm-cierre"><div class="dm-dato-lbl">Tipo de devolución <span style="color:#dc2626;">*</span></div><div class="dm-cierre-opts">'
      + '<label class="dm-opc"><input type="radio" name="cierre" value="parcial"><span><strong>Devolución parcial</strong><small>El pedido sigue abierto para registrar más devoluciones hasta que no queden unidades.</small></span></label>'
      + '<label class="dm-opc"><input type="radio" name="cierre" value="completa"><span><strong>Devolución completa</strong><small>El pedido queda Completado y ya no se podrá devolver nada más, aunque queden unidades.</small></span></label>'
      + '</div></div>'
      + '<div class="dm-campos"><div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Observaciones</label>'
      + '<textarea name="observaciones" class="form-control" rows="2" maxlength="300" placeholder="Opcional: estado de los libros, quién entrega, etc."></textarea></div>'
      + (PUEDE_ADJUNTAR ? '<div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Soporte adjunto</label><input type="file" name="archivo" class="form-control"></div>' : '<div></div>')
      + '</div>'
      + '<div class="dm-pie"><div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;"><div class="dm-total"><strong id="dm-total">0</strong><span id="dm-total-txt">unidades a devolver</span></div>'
      + '<div id="dm-tipo-dev" class="dm-sub" style="font-size:.82rem;margin:0;"></div></div>'
      + '<button type="submit" class="dm-btn dm-btn-lg" id="dm-enviar" disabled><i class="bi bi-send"></i> Registrar devolución</button></div></form>';
    $('#dm-detalle').html(h);
  }

  // Tipo de la devolución según los títulos que se devuelven (igual que en el servidor): todos E =
  // Estudiante, todos D = Docente, mezcla = Ambos.
  function recalcular() {
    var total = 0, titulos = 0, tipos = {};
    $('.dm-input').each(function () {
      var n = parseInt($(this).val(), 10) || 0;
      $(this).closest('.dm-stepper').toggleClass('activo', n > 0);
      if (n > 0) { total += n; titulos++; tipos[$(this).data('tipo')] = true; }
    });
    var t = Object.keys(tipos);
    $('#dm-tipo-dev').html(t.length ? '<i class="bi bi-tag"></i> Tipo: <strong>' + (t.length > 1 ? 'Ambos' : (t[0] === 'E' ? 'Estudiante' : 'Docente')) + '</strong>' : '');
    $('#dm-total').text(total);
    $('#dm-total-txt').text((total === 1 ? 'unidad' : 'unidades') + ' a devolver' + (titulos ? ' · ' + titulos + ' título' + (titulos === 1 ? '' : 's') : ''));
    $('#dm-enviar').prop('disabled', total === 0);
  }

  // Nunca más de lo disponible (también se valida en el servidor).
  function ajustar($in) {
    var max = parseInt($in.data('max'), 10), v = $in.val(), n = v === '' ? 0 : Number(v);
    if (!Number.isInteger(n) || n < 0) n = 0;
    if (n > max) { n = max; inkToast('Máximo ' + max + ' unidad(es) para "' + $in.data('titulo') + '".', 'warn'); }
    if (String(n) !== v) $in.val(n);
    recalcular();
  }
  $(document).on('change blur', '.dm-input', function () { ajustar($(this)); });
  $(document).on('input', '.dm-input', function () {
    var max = parseInt($(this).data('max'), 10), n = Number($(this).val());
    if (n > max) ajustar($(this)); else recalcular();
  });
  $(document).on('click', '.dm-mas, .dm-menos', function () {
    var $in = $(this).siblings('.dm-input'), n = parseInt($in.val(), 10) || 0;
    $in.val(n + ($(this).hasClass('dm-mas') ? 1 : -1)); ajustar($in);
  });
  $(document).on('click', '#dm-todo', function () {
    $('.dm-input').each(function () { $(this).val($(this).data('max')); });
    recalcular();
  });

  $(document).on('submit', '#dm-form', function (e) {
    e.preventDefault();
    var form = this, total = 0, error = null;
    $('.dm-input').each(function () {
      var max = parseInt($(this).data('max'), 10), v = $(this).val(), n = v === '' ? 0 : Number(v);
      if (!Number.isInteger(n) || n < 0 || n > max) { error = 'Revisa la cantidad de "' + $(this).data('titulo') + '": debe estar entre 0 y ' + max + '.'; return false; }
      total += n;
    });
    if (error) { inkToast(error, 'error'); return; }
    if (total === 0) { inkToast('Ingresa al menos una cantidad a devolver.', 'warn'); return; }
    var cierre = $('input[name="cierre"]:checked').val();
    if (!cierre) { inkToast('Elige si la devolución es parcial o completa.', 'warn'); return; }
    var texto = 'Se devolverán ' + total + ' unidad(es) de este pedido de muestras.'
      + (cierre === 'completa' ? ' Es una devolución COMPLETA: el pedido quedará Completado y ya no se podrá devolver nada más.' : ' Es una devolución parcial: podrás seguir registrando devoluciones.');
    inkConfirm({ type: cierre === 'completa' ? 'warning' : 'info', title: '¿Registrar la devolución ' + cierre + '?', text: texto, btnOk: 'Registrar' }, function () {
      $('#dm-enviar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
      form.submit();
    });
  });

  $(document).on('click', '.dm-btn-sel', function () { cargar($(this).data('id')); });

  <?php if ($id_inicial > 0): ?>cargar(<?= $id_inicial ?>);<?php endif; ?>
})();
</script>
</body>
</html>
