<?php
/**
 * /legalizaciones_muestras.php
 * Módulo "Legalizar muestras": reemplaza el formulario manual solicitar_muestreo.php?tp=2 (pedido
 * por el usuario 2026-10-07). Mismo flujo que devoluciones_muestras.php: primero se elige el colegio (y
 * el período) y se cargan de una vez todos sus pedidos de muestras Entregados por legalizar, con lo
 * realmente despachado en sus remisiones RE22 de World Office sumado por título, para registrar UNA sola
 * legalización con tope (despachado − legalizado; independiente de las devoluciones). Varios pedidos en
 * una legalización: pedido por la usuaria 2026-10-27, igual que las devoluciones.
 * Lógica en includes/legalizaciones_muestras_datos.php; el paso 2 usa el mismo
 * ajax/devoluciones_muestras_detalle.php que las devoluciones.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/legalizaciones_muestras_datos.php");
require_once("includes/periodos_fechas.php");

dm_validar_acceso();
dm_asegurar_columnas($bdd);

$id_inicial = (int)($_GET['id_muestreo'] ?? 0);
['periodos' => $periodos, 'periodoActivo' => $periodo_activo] = obtener_periodo_activo($bdd);
// Período: el elegido; si se llega con ?id_muestreo, el de ese pedido; si no, el activo hoy. 0 = todos.
if (isset($_GET['periodo'])) {
  $id_periodo = (int)$_GET['periodo'];
} elseif ($id_inicial > 0) {
  $st = $bdd->prepare("SELECT id_periodo FROM muestreos WHERE id = ?");
  $st->execute([$id_inicial]);
  $id_periodo = (int)($st->fetchColumn() ?: $periodo_activo);
} else {
  $id_periodo = (int)$periodo_activo;
}

$muestreos = dm_marcar_completados($bdd, dm_listar_muestreos_entregados($bdd, $id_periodo ?: null), 'legalizacion');
$colegios = [];
$con_legalizacion = 0;
$completados = 0;
$colegio_inicial = '';
foreach ($muestreos as $m) {
  $colegios[$m['id_colegio']] = $m['colegio'];
  if ((int)$m['n_legalizaciones'] > 0) $con_legalizacion++;
  if ($m['completado']) $completados++;
  if ((int)$m['id'] === $id_inicial) $colegio_inicial = (string)$m['id_colegio'];
}
asort($colegios, SORT_NATURAL | SORT_FLAG_CASE);
// Pedidos por legalizar por colegio (los más recientes primero): se cargan todos al elegir el colegio.
$pendientes_por_colegio = [];
foreach ($muestreos as $m) if (!$m['completado']) $pendientes_por_colegio[(int)$m['id_colegio']][] = (int)$m['id'];
foreach ($pendientes_por_colegio as &$ids_col) rsort($ids_col);
unset($ids_col);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Legalizar muestras</title>
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
    .dm-campos { display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-top:18px; }
    .dm-campos textarea, .dm-campos input[type=file] { border-radius:10px; font-size:.86rem; }
    @media (max-width:768px) { .dm-campos { grid-template-columns:1fr; } .dm-buscar input { width:100%; } }

    .dm-cargando { text-align:center; padding:50px 10px; color:var(--dm-gris); }
    .dm-spinner { width:38px; height:38px; border:3px solid #dbeafe; border-top-color:var(--dm-azul); border-radius:50%; animation:dm-giro .8s linear infinite; margin:0 auto 12px; }
    .dm-spinner-sm { display:inline-block; width:14px; height:14px; border-width:2px; margin:0; vertical-align:-2px; border-color:rgba(255,255,255,.4); border-top-color:#fff; }
    @keyframes dm-giro { to { transform:rotate(360deg); } }
    .dm-vacio { text-align:center; padding:46px 10px; color:#94a3b8; }
    .dm-vacio i { font-size:2.2rem; display:block; margin-bottom:8px; color:#cbd5e1; }
    /* Selección de pedidos */
    .dm-check { width:17px; height:17px; accent-color:var(--dm-azul); cursor:pointer; vertical-align:middle; }
    .dm-check:disabled { cursor:not-allowed; opacity:.4; }
    .dm-table tbody tr.dm-marcado td { background:#eff6ff; }
    .dm-seleccion { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; background:#f8fafc; border:1px solid var(--dm-borde); border-radius:12px; padding:12px 16px; margin-top:16px; }
    .dm-seleccion-txt { font-size:.86rem; color:var(--dm-gris); font-weight:600; }
    .dm-seleccion-txt strong { color:var(--dm-azul); font-size:1.05rem; }
    .dm-ped-card { border:1px solid var(--dm-borde); border-radius:12px; padding:12px 14px; background:#fff; }
    .dm-ped-card.err { border-color:#fecaca; background:#fffafa; }
    .dm-ped-card.vacio { border-color:#fde68a; background:#fffdf5; }
    .dm-ped-card .dm-doc-top { margin-bottom:8px; }
    .dm-ped-docs { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
    .dm-reparto { font-size:.72rem; color:#94a3b8; margin-top:3px; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="dm-hero">
        <h4><i class="bi bi-patch-check"></i> Legalizar muestras</h4>
        <p>Elige el colegio y se cargan de una vez todos sus pedidos de muestras por legalizar, con los títulos sumados de sus remisiones de muestras (RE22) de World Office menos lo ya legalizado (si un pedido no tiene remisión en WO, se toma lo solicitado en el CRM). Registra una sola legalización con todos.</p>
        <div class="dm-flujo">
          <span>Colegio</span><i class="bi bi-chevron-right"></i>
          <span>Pedidos entregados</span><i class="bi bi-chevron-right"></i>
          <span>Remisiones RE22 en WO</span><i class="bi bi-chevron-right"></i>
          <span>Títulos sumados</span><i class="bi bi-chevron-right"></i>
          <span>Legalización</span>
        </div>
      </div>

      <div class="dm-stats">
        <div class="dm-stat"><div class="dm-stat-ico azul"><i class="bi bi-box-seam"></i></div><div><div class="dm-stat-num"><?= number_format(count($muestreos), 0, ',', '.') ?></div><div class="dm-stat-lbl">Pedidos entregados</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-building"></i></div><div><div class="dm-stat-num"><?= number_format(count($colegios), 0, ',', '.') ?></div><div class="dm-stat-lbl">Colegios</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico ambar"><i class="bi bi-patch-check"></i></div><div><div class="dm-stat-num"><?= number_format($con_legalizacion, 0, ',', '.') ?></div><div class="dm-stat-lbl">Con legalizaciones registradas</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-check2-circle"></i></div><div><div class="dm-stat-num"><?= number_format($completados, 0, ',', '.') ?></div><div class="dm-stat-lbl">Completados</div></div></div>
      </div>

      <!-- Paso 1 -->
      <div class="dm-section">
        <div class="dm-section-head">
          <h5 class="dm-section-title"><span class="dm-paso">1</span> Elige el colegio</h5>
        </div>
        <div class="dm-section-body">
          <div class="dm-toolbar">
            <div class="dm-campo" style="min-width:300px;flex:1;max-width:460px;">
              <label for="f_colegio">Colegio <span style="color:#dc2626;">*</span></label>
              <select id="f_colegio" class="custom-select2" style="width:100%;">
                <option value="">Elige un colegio...</option>
                <?php foreach ($colegios as $id_col => $nombre): ?>
                  <option value="<?= (int)$id_col ?>"<?= (string)$id_col === $colegio_inicial ? ' selected' : '' ?>><?= htmlspecialchars($nombre) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dm-campo">
              <label for="f_periodo">Período</label>
              <select id="f_periodo" class="form-control" style="height:38px;border-radius:10px;font-size:.88rem;min-width:150px;">
                <?php foreach ($periodos as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $id_periodo ? ' selected' : '' ?>><?= htmlspecialchars($p['periodo']) ?><?= (int)$p['id'] === (int)$periodo_activo ? ' (actual)' : '' ?></option>
                <?php endforeach; ?>
                <option value="0"<?= $id_periodo === 0 ? ' selected' : '' ?>>Todos los períodos</option>
              </select>
            </div>
          </div>

          <div class="dm-vacio" id="dm-sin-colegio"><i class="bi bi-building"></i>Elige un colegio para cargar todos sus pedidos de muestras por legalizar<?= $id_periodo ? ' del período' : '' ?>.</div>
          <div class="dm-vacio d-none" id="dm-sin-pendientes"><i class="bi bi-check2-circle"></i>Este colegio no tiene pedidos de muestras por legalizar<?= $id_periodo ? ' en el período' : '' ?>.</div>

          <div class="dm-seleccion d-none" id="dm-resumen-sel">
            <div class="dm-seleccion-txt"><strong id="dm-n-sel">0</strong> <span id="dm-n-sel-txt">pedidos por legalizar</span></div>
            <button type="button" class="dm-btn dm-btn-light" id="dm-buscar-wo"><i class="bi bi-arrow-clockwise"></i> Volver a consultar World Office</button>
          </div>
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
<script src="src/ink-alerts.js"></script>
<script>
(function () {
  var MAX_PEDIDOS = <?= DM_MAX_PEDIDOS_DEVOLUCION ?>;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  // Pedidos por legalizar de cada colegio, los más recientes primero. Al elegir el colegio se cargan TODOS
  // de una vez (igual que devoluciones_muestras.php).
  var PENDIENTES = <?= json_encode($pendientes_por_colegio ?: new stdClass()) ?>;
  var idsColegio = [];
  function aplicarColegio(cargarTodo) {
    var col = $('#f_colegio').val() || '', todos = PENDIENTES[col] || [];
    idsColegio = todos.slice(0, MAX_PEDIDOS);
    $('#dm-sin-colegio').toggleClass('d-none', !!col);
    $('#dm-sin-pendientes').toggleClass('d-none', !col || todos.length > 0);
    $('#dm-resumen-sel').toggleClass('d-none', !idsColegio.length);
    $('#dm-n-sel').text(idsColegio.length);
    $('#dm-n-sel-txt').text(idsColegio.length === 1 ? 'pedido por legalizar' : 'pedidos por legalizar');
    if (consulta) consulta.abort();
    $('#dm-paso2').addClass('d-none');
    if (todos.length > MAX_PEDIDOS) inkToast('Se cargaron los ' + MAX_PEDIDOS + ' pedidos más recientes de ' + todos.length + ' (máximo por legalización).', 'warn');
    if (idsColegio.length && cargarTodo !== false) cargar(idsColegio, []);
  }
  $('#f_colegio').on('change', function () { aplicarColegio(); });
  // El período filtra los pedidos en el servidor: se recarga la página con el colegio elegido.
  $('#f_periodo').on('change', function () {
    window.location.href = 'legalizaciones_muestras.php?periodo=' + encodeURIComponent($(this).val());
  });
  $('#dm-cerrar').on('click', function () { $('#dm-paso2').addClass('d-none'); });

  function dato(icono, label, val) {
    return '<div class="dm-dato"><i class="bi ' + icono + '"></i><div><div class="dm-dato-lbl">' + esc(label) + '</div><div class="dm-dato-val">' + esc(val) + '</div></div></div>';
  }
  function aviso(tipo, icono, html) { return '<div class="dm-aviso ' + tipo + '"><i class="bi ' + icono + '"></i><div>' + html + '</div></div>'; }
  function lnkPedido(id) { return '<a class="dm-ped" href="muestreo_colegio_resto.php?id_pedido=' + id + '&tp=4" target="_blank">#' + id + '</a>'; }

  // Una sola consulta a la vez: una nueva cancela la anterior. crmIds = pedidos que se cargan con lo
  // solicitado en el CRM.
  var consulta = null, crmIds = [];
  function cargar(ids, crm) {
    crmIds = (crm || []).filter(function (id) { return ids.indexOf(id) >= 0; });
    if (consulta) consulta.abort();
    $('#dm-paso2').removeClass('d-none');
    $('#dm-paso2-titulo').text(ids.length === 1 ? 'Títulos del pedido #' + ids[0] : 'Títulos de ' + ids.length + ' pedidos (sumados)');
    $('#dm-detalle').html('<div class="dm-cargando"><div class="dm-spinner"></div>Buscando las OP y las remisiones RE22 en World Office'
      + (ids.length > 1 ? ' de ' + ids.length + ' pedidos' : '') + '...</div>');
    $('html, body').animate({ scrollTop: $('#dm-paso2').offset().top - 80 }, 300);

    var xhr = consulta = $.getJSON('ajax/devoluciones_muestras_detalle.php', { modo: 'legalizacion', ids: ids, crm: crmIds });
    xhr.done(function (r) { pintar(r); })
      .fail(function (x, estado) {
        if (estado === 'abort') return;
        $('#dm-detalle').html(aviso('err', 'bi-x-octagon', 'No se pudo consultar los pedidos. Intenta de nuevo.'));
      })
      .always(function () { if (consulta === xhr) consulta = null; });
  }

  function tarjetaPedido(p) {
    var cls = p.error ? ' err' : (p.sin_documentos ? ' vacio' : '');
    var h = '<div class="dm-ped-card' + cls + '"><div class="dm-doc-top">' + lnkPedido(p.id)
      + (p.fuente === 'crm' ? '<span class="dm-pill dm-pill-ambar">Solicitud CRM</span>' : '')
      + (p.cierre ? '<span class="dm-pill dm-pill-verde"><i class="bi bi-check2-circle"></i> Completado</span>' : '')
      + '<span class="dm-doc-fecha"><i class="bi bi-calendar3"></i> ' + esc(p.fecha) + '</span></div>'
      + '<div class="dm-sub" style="margin:0;"><i class="bi bi-file-earmark-check"></i> ' + (p.ops ? 'OP ' + esc(p.ops) : 'Sin OP')
      + (p.solicitante ? ' · <i class="bi bi-person"></i> ' + esc(p.solicitante) : '') + '</div>';
    if (p.documentos.length) {
      h += '<div class="dm-ped-docs">';
      p.documentos.forEach(function (d) {
        h += '<span class="dm-pill dm-pill-rem" title="' + esc(d.fecha + ' · ' + d.concepto) + '"><i class="bi bi-receipt"></i> ' + esc(d.documento) + '</span>';
      });
      h += '</div>';
    }
    if (p.error) h += '<div class="dm-sub" style="color:#b91c1c;margin-top:8px;"><i class="bi bi-x-octagon"></i> ' + esc(p.error) + '</div>';
    if (p.sin_documentos) {
      h += '<div class="dm-sub" style="color:#92400e;margin-top:8px;"><i class="bi bi-exclamation-triangle"></i> '
        + (p.sin_op ? 'No tiene OP asociada' : 'World Office no trajo ninguna remisión RE22 con su OP')
        + ': se cargó lo solicitado en el CRM.</div>';
    }
    if (p.cierre) h += '<div class="dm-sub" style="margin-top:8px;">Cerrado con la <a href="muestreo_colegio_resto.php?id_muestras_e=' + p.cierre.id + '" target="_blank">legalización completa #' + p.cierre.id + '</a>: no suma disponible.</div>';
    return h + '</div>';
  }

  function pintar(r) {
    if (!r.ok) { $('#dm-detalle').html(aviso('err', 'bi-x-octagon', esc(r.error))); return; }
    var pedidos = r.pedidos, varios = pedidos.length > 1;
    var unidades = pedidos.reduce(function (s, p) { return s + p.unidades; }, 0);
    var h = '<div class="dm-resumen">'
      + dato('bi-building', 'Colegio', r.colegio)
      + dato('bi-person-badge', 'Cliente', r.cliente || '—')
      + dato('bi-box-seam', varios ? 'Pedidos de muestras' : 'Pedido de muestras', pedidos.map(function (p) { return '#' + p.id; }).join(', '))
      + dato('bi-stack', 'Unidades despachadas / solicitadas', unidades)
      + '</div>';

    h += '<div class="dm-subtitulo"><i class="bi bi-receipt"></i> Pedidos y documentos de World Office</div><div class="dm-docs">';
    pedidos.forEach(function (p) { h += tarjetaPedido(p); });
    h += '</div>';
    pedidos.forEach(function (p) {
      p.documentos.forEach(function (d) {
        if (d.ped_distinto) h += aviso('warn', 'bi-exclamation-triangle', 'El concepto de <strong>' + esc(d.documento) + '</strong> dice PED ' + esc(d.ped) + ' y el pedido es el #' + p.id + '. Se tomó porque la OP coincide; revísalo antes de guardar.');
      });
    });
    var deCrm = pedidos.filter(function (p) { return p.fuente === 'crm'; }).map(function (p) { return p.id; });
    if (deCrm.length) {
      h += aviso('warn', 'bi-info-circle', 'World Office no trajo la remisión RE22 de ' + deCrm.map(function (id) { return '#' + id; }).join(', ')
        + ': de ' + (deCrm.length === 1 ? 'ese pedido' : 'esos pedidos') + ' se toman los libros y las cantidades <strong>solicitados en el CRM</strong>. Revisa que coincidan con lo que se entregó antes de guardar.');
    }
    var excluidos = pedidos.filter(function (p) { return r.incluidos.indexOf(p.id) < 0; });
    if (excluidos.length && r.incluidos.length) {
      h += aviso('warn', 'bi-dash-circle', 'No entra' + (excluidos.length > 1 ? 'n' : '') + ' en esta legalización: ' + excluidos.map(function (p) { return '#' + p.id; }).join(', ')
        + ' (sin títulos por legalizar, completado o sin libros).');
    }

    if (!r.titulos.length) {
      $('#dm-detalle').html(h + '<div class="dm-vacio"><i class="bi bi-search"></i>No hay títulos para legalizar en ' + (varios ? 'estos pedidos' : 'este pedido') + '.</div>');
      return;
    }

    var fuentes = {};
    pedidos.forEach(function (p) { if (p.unidades) fuentes[p.fuente] = true; });
    var colBase = fuentes.auto && fuentes.crm ? 'Despachada / solicitada' : (fuentes.crm ? 'Solicitada' : 'Despachada');
    var hayDisponible = r.titulos.some(function (t) { return t.id_libro && t.disponible > 0; });
    h += '<div class="dm-subtitulo" style="justify-content:space-between;"><span><i class="bi bi-book"></i> Títulos ' + (varios ? 'sumados de todos los pedidos' : '') + ' (' + r.titulos.length + ')</span>'
      + (hayDisponible ? '<button type="button" class="dm-btn dm-btn-light" id="dm-todo" style="padding:5px 12px;font-size:.75rem;"><i class="bi bi-check2-all"></i> Legalizar todo lo disponible</button>' : '')
      + '</div>';

    h += '<form id="dm-form" method="POST" action="php/legalizaciones_muestras_guardar.php" enctype="multipart/form-data">';
    r.incluidos.forEach(function (id) {
      h += '<input type="hidden" name="ids[]" value="' + id + '">';
      if (deCrm.indexOf(id) >= 0) h += '<input type="hidden" name="crm[]" value="' + id + '">';
    });
    h += '<div class="table-responsive"><table class="dm-tit-table"><thead><tr>'
      + '<th>Título</th><th class="c" title="E = estudiante, D = docente">Tipo</th><th>Documentos</th><th class="c">' + colBase + '</th><th class="c">Ya legalizada</th><th class="c">Disponible</th><th class="c">A legalizar</th>'
      + '</tr></thead><tbody>';
    r.titulos.forEach(function (t) {
      var sinLibro = !t.id_libro, editable = !sinLibro && t.disponible > 0;
      var pct = t.base > 0 ? Math.min(100, Math.round(t.legalizada * 100 / t.base)) : 0;
      // Con varios pedidos, de cuál sale el saldo (se descuenta del más antiguo primero).
      var reparto = varios && t.pedidos.length > 1
        ? '<div class="dm-reparto">' + t.pedidos.map(function (p) { return '#' + p.id + ': ' + p.disponible; }).join(' · ') + '</div>' : '';
      h += '<tr' + (!editable ? ' class="dm-agotado"' : '') + '>'
        + '<td><div class="dm-libro">' + esc(t.descripcion) + '</div><div class="dm-isbn">' + esc(t.codigo) + '</div>'
        + (sinLibro ? '<div class="dm-sub" style="color:#dc2626;"><i class="bi bi-exclamation-circle"></i> No está en el catálogo del CRM</div>' : '') + '</td>'
        + '<td class="c">' + (t.tipo_ed ? '<span class="dm-pill ' + (t.tipo_ed === 'E' ? 'dm-pill-azul' : 'dm-pill-fv') + '" title="' + (t.tipo_ed === 'E' ? 'Estudiante' : 'Docente') + '">' + t.tipo_ed + '</span>' : '—') + '</td>'
        + '<td><span class="dm-pill dm-pill-gris" style="white-space:normal;">' + esc(t.documentos) + '</span></td>'
        + '<td class="c"><span class="dm-num">' + t.base + '</span>' + (varios && t.pedidos.length > 1 ? '<div class="dm-num-sub">' + t.pedidos.length + ' pedidos</div>' : '') + '</td>'
        + '<td class="c"><span class="dm-num">' + t.legalizada + '</span><div class="dm-barra" title="' + pct + '% legalizado"><span style="width:' + pct + '%"></span></div></td>'
        + '<td class="c">' + (t.disponible > 0 ? '<span class="dm-pill dm-pill-verde">' + t.disponible + '</span>' : '<span class="dm-pill dm-pill-gris">0</span>') + reparto + '</td>'
        + '<td class="c">' + (editable
            ? '<div class="dm-stepper"><button type="button" class="dm-menos" tabindex="-1">−</button>'
              + '<input type="number" class="dm-input" name="cantidad[' + t.id_libro + ']" min="0" max="' + t.disponible + '" step="1" value="0" data-max="' + t.disponible + '" data-titulo="' + esc(t.descripcion) + '">'
              + '<button type="button" class="dm-mas" tabindex="-1">+</button></div><span class="dm-max">máx. ' + t.disponible + '</span>'
            : '<span class="dm-sub" style="justify-content:center;">' + (sinLibro ? 'No disponible' : 'Ya legalizado') + '</span>')
        + '</td></tr>';
    });
    h += '</tbody></table></div>';

    if (!hayDisponible) {
      h += aviso('ok', 'bi-check-circle', '<strong>Completado.</strong> No queda nada por legalizar en ' + (varios ? 'estos pedidos' : 'este pedido')
        + (r.titulos.some(function (t) { return !t.id_libro; }) ? ' (o los títulos pendientes no están en el catálogo del CRM).' : '.')) + '</form>';
      $('#dm-detalle').html(h);
      return;
    }
    // Sin "tipo de legalización" (quitado a pedido de la usuaria 2026-10-27, igual que en devoluciones): toda
    // legalización es parcial y el pedido queda Completado solo cuando ya no le quedan unidades por legalizar.
    h += '<div class="dm-campos"><div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Observaciones</label>'
      + '<textarea name="observaciones" class="form-control" rows="2" maxlength="300" placeholder="Opcional: a quién se entregaron, etc."></textarea></div>'
      + '<div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Soporte adjunto</label><input type="file" name="archivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>'
      + '</div>'
      + '<div class="dm-pie"><div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;"><div class="dm-total"><strong id="dm-total">0</strong><span id="dm-total-txt">unidades a legalizar</span></div></div>'
      + '<button type="submit" class="dm-btn dm-btn-lg" id="dm-enviar" disabled><i class="bi bi-check-circle"></i> Legalizar muestras</button></div></form>';
    $('#dm-detalle').html(h);
  }

  function recalcular() {
    var total = 0, titulos = 0;
    $('.dm-input').each(function () {
      var n = parseInt($(this).val(), 10) || 0;
      $(this).closest('.dm-stepper').toggleClass('activo', n > 0);
      if (n > 0) { total += n; titulos++; }
    });
    $('#dm-total').text(total);
    $('#dm-total-txt').text((total === 1 ? 'unidad' : 'unidades') + ' a legalizar' + (titulos ? ' · ' + titulos + ' título' + (titulos === 1 ? '' : 's') : ''));
    $('#dm-enviar').prop('disabled', total === 0);
  }

  // Nunca más de lo disponible sumado (también se valida en el servidor).
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
    if (total === 0) { inkToast('Ingresa al menos una cantidad a legalizar.', 'warn'); return; }
    var n = $(form).find('input[name="ids[]"]').length, deQue = n > 1 ? 'de ' + n + ' pedidos de muestras en una sola legalización' : 'de este pedido de muestras';
    var texto = 'Se legalizarán ' + total + ' unidad(es) ' + deQue + '. Podrás seguir registrando legalizaciones mientras queden unidades.';
    inkConfirm({ type: 'info', title: '¿Registrar la legalización?', text: texto, btnOk: 'Legalizar' }, function () {
      $('#dm-enviar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
      form.submit();
    });
  });

  $('#dm-buscar-wo').on('click', function () { if (idsColegio.length) cargar(idsColegio, []); });

  // Con ?id_muestreo solo se carga ese pedido; si no, todos los del colegio elegido.
  aplicarColegio(<?= ($id_inicial > 0 && $colegio_inicial !== '') ? 'false' : '' ?>);
  <?php if ($id_inicial > 0 && $colegio_inicial !== ''): ?>
  cargar([<?= $id_inicial ?>], []);
  <?php endif; ?>
})();
</script>
</body>
</html>
