<?php
/**
 * /saldos_colegio.php
 * "Saldos de colegio" (menú Devoluciones → Ventas, pedido de la usuaria 2026-10-26): se elige colegio y período y
 * muestra el consolidado por título con la fórmula de la usuaria: saldo = remisionado − devoluciones de corte −
 * devoluciones físicas (lo solicitado no cuenta), valorizado con el precio de la adopción (precio del libro − descuento del
 * presupuesto). Solo consulta. Lógica en dvv_saldo_colegio().
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_ventas_datos.php");
require_once("includes/periodos_fechas.php");

dm_validar_acceso();
dvv_asegurar_columnas($bdd);

['periodos' => $periodos, 'periodoActivo' => $periodo_activo] = obtener_periodo_activo($bdd);
$id_periodo = isset($_GET['periodo']) ? (int)$_GET['periodo'] : (int)$periodo_activo;
// Colegios con pedidos de venta Entregados en el período (los de canal Venta directa no salen).
$colegios = [];
foreach (dvv_listar_pedidos($bdd, $id_periodo ?: null) as $p) $colegios[$p['id_colegio']] = $p['colegio'];
asort($colegios, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Saldos de colegio</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
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
  <style>
    .dm-tit-table td.r, .dm-tit-table th.r { text-align:right; white-space:nowrap; }
    .dm-tit-table tfoot td { background:#f8fafc; font-weight:800; border-top:2px solid var(--dm-borde); }
    .dm-falta { font-weight:800; color:#b45309; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="dm-hero">
        <h4><i class="bi bi-cash-stack"></i> Saldos de colegio</h4>
        <p>Elige el colegio y el período: se consolidan todos sus pedidos de venta entregados y por cada título se calcula el saldo = remisionado − devoluciones de corte − devoluciones físicas, valorizado con el precio de la adopción (precio del libro menos el descuento adoptado).</p>
        <div class="dm-flujo">
          <span>Remisionado (CEUR + FVE)</span><i class="bi bi-dash"></i>
          <span>Devoluciones de corte</span><i class="bi bi-dash"></i>
          <span>Devoluciones físicas</span><i class="bi bi-chevron-right"></i>
          <span>Saldo × precio de la adopción</span>
        </div>
      </div>

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
                  <option value="<?= (int)$id_col ?>"><?= htmlspecialchars($nombre) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dm-campo">
              <label for="f_periodo">Período <span style="color:#dc2626;">*</span></label>
              <select id="f_periodo" class="form-control" style="height:38px;border-radius:10px;font-size:.88rem;min-width:150px;">
                <?php foreach ($periodos as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $id_periodo ? ' selected' : '' ?>><?= htmlspecialchars($p['periodo']) ?><?= (int)$p['id'] === (int)$periodo_activo ? ' (actual)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="dm-vacio" id="dm-sin-colegio"><i class="bi bi-building"></i><?= $colegios ? 'Elige un colegio para ver su saldo.' : 'No hay colegios con pedidos de venta entregados en este período.' ?></div>
        </div>
      </div>

      <div class="dm-section d-none" id="dm-paso2">
        <div class="dm-section-head">
          <h5 class="dm-section-title"><span class="dm-paso">2</span> <span id="dm-paso2-titulo">Saldo</span></h5>
          <button type="button" class="dm-btn dm-btn-light" id="dm-recargar" style="padding:6px 12px;"><i class="bi bi-arrow-clockwise"></i> Volver a consultar World Office</button>
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
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var pesos = function (v) { return v == null ? '—' : '$ ' + Math.round(v).toLocaleString('es-CO'); };
  function dato(icono, label, val) {
    return '<div class="dm-dato"><i class="bi ' + icono + '"></i><div><div class="dm-dato-lbl">' + esc(label) + '</div><div class="dm-dato-val">' + esc(val) + '</div></div></div>';
  }
  function aviso(tipo, icono, html) { return '<div class="dm-aviso ' + tipo + '"><i class="bi ' + icono + '"></i><div>' + html + '</div></div>'; }

  var consulta = null;
  function cargar() {
    var col = $('#f_colegio').val() || '';
    $('#dm-sin-colegio').toggleClass('d-none', !!col);
    if (consulta) consulta.abort();
    if (!col) { $('#dm-paso2').addClass('d-none'); return; }
    $('#dm-paso2').removeClass('d-none');
    $('#dm-paso2-titulo').text('Saldo — ' + $('#f_colegio option:selected').text());
    $('#dm-detalle').html('<div class="dm-cargando"><div class="dm-spinner"></div>Buscando los pedidos, remisiones y facturas en World Office...</div>');
    var xhr = consulta = $.getJSON('ajax/saldos_colegio_detalle.php', { id_colegio: col, id_periodo: $('#f_periodo').val() });
    xhr.done(pintar)
      .fail(function (x, estado) { if (estado !== 'abort') $('#dm-detalle').html(aviso('err', 'bi-x-octagon', 'No se pudo consultar el saldo. Intenta de nuevo.')); })
      .always(function () { if (consulta === xhr) consulta = null; });
  }
  $('#f_colegio').on('change', cargar);
  // El período recarga la página (la lista de colegios sale del servidor).
  $('#f_periodo').on('change', function () { window.location.href = 'saldos_colegio.php?periodo=' + encodeURIComponent($(this).val()); });
  $('#dm-recargar').on('click', cargar);

  function pintar(r) {
    if (!r.ok) { $('#dm-detalle').html(aviso('err', 'bi-x-octagon', esc(r.error))); return; }
    var t = r.totales, docs = [];
    r.pedidos.forEach(function (p) { p.documentos.forEach(function (d) { if (docs.indexOf(d) < 0) docs.push(d); }); });
    var h = '<div class="dm-resumen">'
      + dato('bi-building', 'Colegio', r.colegio)
      + dato('bi-person-badge', 'Cliente', r.cliente || '—')
      + dato('bi-box-seam', 'Pedidos de venta', r.pedidos.map(function (p) { return '#' + p.id; }).join(', '))
      + dato('bi-receipt', 'Remisiones y facturas WO', docs.join(', ') || '—')
      + dato('bi-hourglass-split', 'Saldo', t.saldo + ' unidad' + (Math.abs(t.saldo) === 1 ? '' : 'es'))
      + dato('bi-cash-stack', 'Valor del saldo', pesos(t.valor))
      + '</div>';
    if (r.omitidos) h += aviso('warn', 'bi-exclamation-triangle', 'El colegio tiene ' + (r.pedidos.length + r.omitidos) + ' pedidos: se tomaron los ' + r.pedidos.length + ' más recientes.');
    if (r.sin_precio) h += aviso('warn', 'bi-tag', r.sin_precio + ' título' + (r.sin_precio === 1 ? '' : 's') + ' con saldo no ' + (r.sin_precio === 1 ? 'está adoptado' : 'están adoptados') + ' en el período: sin precio de adopción, no suma al valor.');
    if (!r.titulos.length) { $('#dm-detalle').html(h + '<div class="dm-vacio"><i class="bi bi-search"></i>No hay títulos para este colegio.</div>'); return; }

    var n = function (v) { return '<span class="dm-num">' + v + '</span>'; };
    h += '<div class="table-responsive"><table class="dm-tit-table"><thead><tr>'
      + '<th>Título</th><th class="c">Remisionado</th><th class="c">Dev. de corte</th><th class="c">Dev. físicas</th>'
      + '<th class="c">Saldo</th><th class="r">Precio adopción</th><th class="c">Desc.</th><th class="r">Precio neto</th><th class="r">Valor saldo</th>'
      + '</tr></thead><tbody>';
    r.titulos.forEach(function (x) {
      h += '<tr' + (x.saldo === 0 ? ' class="dm-agotado"' : '') + '>'
        + '<td><div class="dm-libro">' + esc(x.descripcion) + '</div><div class="dm-isbn">' + esc(x.codigo) + '</div>'
        + (!x.adoptado ? '<div class="dm-sub" style="color:#b45309;"><i class="bi bi-exclamation-circle"></i> No adoptado en el período</div>' : '') + '</td>'
        + '<td class="c">' + n(x.remisionada) + '</td>'
        + '<td class="c">' + n(x.dev_corte) + '</td><td class="c">' + n(x.dev_fisica) + '</td>'
        + '<td class="c"><span class="dm-falta"' + (x.saldo < 0 ? ' style="color:#b91c1c;"' : '') + '>' + x.saldo + '</span></td>'
        + '<td class="r">' + pesos(x.precio) + '</td>'
        + '<td class="c">' + (x.descuento == null ? '—' : Math.round(x.descuento * 1000) / 10 + ' %') + '</td>'
        + '<td class="r">' + pesos(x.precio_final) + '</td>'
        + '<td class="r"><strong>' + (x.valor == null ? '—' : pesos(x.valor)) + '</strong></td>'
        + '</tr>';
    });
    h += '</tbody><tfoot><tr><td>Total (' + r.titulos.length + ' títulos)</td><td class="c">' + t.remisionada + '</td>'
      + '<td class="c">' + t.dev_corte + '</td><td class="c">' + t.dev_fisica + '</td>'
      + '<td class="c"><span class="dm-falta">' + t.saldo + '</span></td><td></td><td></td><td></td><td class="r">' + pesos(t.valor) + '</td></tr></tfoot></table></div>';
    $('#dm-detalle').html(h);
  }
})();
</script>
</body>
</html>
