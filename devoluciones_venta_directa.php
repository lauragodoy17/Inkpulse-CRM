<?php
/**
 * /devoluciones_venta_directa.php
 * "Legalización venta directa" (menú Venta directa, aparte de Devoluciones desde 2026-10-21), rehecha el 2026-10-19 a pedido de la usuaria: es un
 * consolidado de pedidos de venta del colegio. Desde 2026-10-21 el paso 1 vuelve a ser la lista de pedidos
 * Entregados (como devoluciones de muestras) y se MARCAN los pedidos (todos del mismo colegio) que entran;
 * con ellos se traen los títulos sumados de sus documentos de World Office para registrar UNA sola
 * devolución con tope. Al registrarla los pedidos quedan completos y lo que no se devolvió queda
 * en un documento de saldo (SAL-n) valorizado. Lógica en includes/devoluciones_vd_datos.php.
 * Desde 2026-10-21 solo salen colegios con canal de venta "Venta directa" y la legalización empieza por el
 * FORMATO DE VENTA DIRECTA (formato_venta_directa.php): "Continuar" abre el formato. Desde 2026-10-22 el
 * formato es un flujo por etapas y la devolución (con su saldo) se genera en su paso 3: esta página solo
 * elige los pedidos. ?formato=N (enlaces viejos) redirige al formato.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/formatos_vd_datos.php");
require_once("includes/periodos_fechas.php");

dm_validar_acceso();
dm_asegurar_columnas($bdd);
fvd_asegurar_tablas($bdd);

// Enlaces viejos (?formato=N): la devolución ahora se genera en el paso 3 del formato (2026-10-22).
if (isset($_GET['formato'])) {
  header('Location: formato_venta_directa.php?id=' . (int)$_GET['formato']);
  exit;
}
$formato_pendiente = fvd_pendientes_por_pedido($bdd);

['periodos' => $periodos, 'periodoActivo' => $periodo_activo] = obtener_periodo_activo($bdd);
// Período: el elegido o el activo hoy. 0 = todos los períodos.
$id_periodo = isset($_GET['periodo']) ? (int)$_GET['periodo'] : (int)$periodo_activo;

$pedidos = dvd_listar_pedidos($bdd, $id_periodo ?: null);
$colegios = [];
$con_saldo = (int)$bdd->query("SELECT COUNT(*) FROM saldos_devolucion")->fetchColumn();
$completados = 0;
foreach ($pedidos as $p) {
  $colegios[$p['id_colegio']] = $p['colegio'];
  if ($p['completado']) $completados++;
}
asort($colegios, SORT_NATURAL | SORT_FLAG_CASE);
$colegio_inicial = isset($_GET['colegio']) ? (string)(int)$_GET['colegio'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Legalización venta directa</title>
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

    /* Selección de pedidos */
    .dm-chk { width:18px; height:18px; cursor:pointer; accent-color:var(--dm-azul); vertical-align:middle; }
    .dm-table tbody tr.dm-marcado td { background:#eef2ff; }
    .dm-table tbody tr.dm-marcado td:first-child { box-shadow:inset 3px 0 0 var(--dm-azul); }

    /* Vista previa del saldo (antes de registrar) */
    .dm-prev-fondo { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:9980; display:flex; align-items:center; justify-content:center; padding:16px; }
    .dm-prev { background:#fff; border-radius:16px; width:900px; max-width:100%; max-height:calc(100vh - 32px); display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(15,23,42,.25); }
    .dm-prev-head { padding:18px 24px; border-bottom:1px solid var(--dm-borde); display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .dm-prev-head h5 { margin:0; font-weight:800; color:var(--dm-tinta); font-size:1.05rem; }
    .dm-prev-num { display:inline-block; background:#fef3c7; color:#b45309; border-radius:8px; padding:2px 10px; font-weight:800; font-size:.85rem; margin-left:6px; }
    .dm-prev-body { padding:16px 24px; overflow:auto; }
    .dm-prev-pie { padding:14px 24px; border-top:1px solid var(--dm-borde); display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .dm-prev .r { text-align:right; white-space:nowrap; }
    .dm-prev tfoot td { font-weight:800; border-top:2px solid #b45309; border-bottom:none; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="dm-hero">
        <h4><i class="bi bi-arrow-return-left"></i> Legalización venta directa</h4>
        <p>Solo colegios con canal de venta <strong>Venta directa - Eureka</strong>. Marca los pedidos entregados del colegio y continúa: se abre el <strong>formato de venta directa</strong>, que avanza por etapas: solicitud, entrega de bodega con firmas, devolución, cierre del inventario, recepción en bodega y cierre de dinero. Se traen los títulos sumados de sus documentos de World Office (si un pedido no tiene documento en WO, se toma lo solicitado en el CRM). Al generar la devolución los pedidos quedan completos y lo que no se devuelva queda en un documento de saldo valorizado con el precio final (con descuento) de la factura o remisión de World Office.</p>
        <div class="dm-flujo">
          <span>Pedidos de venta entregados</span><i class="bi bi-chevron-right"></i>
          <span>Selección (mismo colegio)</span><i class="bi bi-chevron-right"></i>
          <span>Formato de venta directa</span><i class="bi bi-chevron-right"></i>
          <span>Documentos en WO</span><i class="bi bi-chevron-right"></i>
          <span>Títulos sumados</span><i class="bi bi-chevron-right"></i>
          <span>Devolución</span><i class="bi bi-chevron-right"></i>
          <span>Saldo SAL-n</span>
        </div>
      </div>

      <div class="dm-stats">
        <div class="dm-stat"><div class="dm-stat-ico azul"><i class="bi bi-cart-check"></i></div><div><div class="dm-stat-num"><?= number_format(count($pedidos), 0, ',', '.') ?></div><div class="dm-stat-lbl">Pedidos de venta entregados</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-building"></i></div><div><div class="dm-stat-num"><?= number_format(count($colegios), 0, ',', '.') ?></div><div class="dm-stat-lbl">Colegios</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-check2-circle"></i></div><div><div class="dm-stat-num"><?= number_format($completados, 0, ',', '.') ?></div><div class="dm-stat-lbl">Completos</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico ambar"><i class="bi bi-receipt"></i></div><div><div class="dm-stat-num"><?= number_format($con_saldo, 0, ',', '.') ?></div><div class="dm-stat-lbl">Documentos de saldo</div></div></div>
      </div>

      <!-- Paso 1 -->
      <div class="dm-section">
        <div class="dm-section-head">
          <h5 class="dm-section-title"><span class="dm-paso">1</span> Elige los pedidos de venta</h5>
          <span class="dm-pill dm-pill-gris" id="dm-contador"><?= count($pedidos) ?> pedidos</span>
        </div>
        <div class="dm-section-body">
          <div class="dm-toolbar">
            <div class="dm-campo" style="min-width:300px;flex:1;max-width:420px;">
              <label for="f_colegio">Colegio</label>
              <select id="f_colegio" class="custom-select2" style="width:100%;">
                <option value="">Todos los colegios</option>
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
            <div class="dm-campo">
              <label for="f_estado">Estado</label>
              <select id="f_estado" class="form-control" style="height:38px;border-radius:10px;font-size:.88rem;min-width:160px;">
                <option value="pendiente" selected>Por legalizar</option>
                <option value="completado">Completos</option>
              </select>
            </div>
            <div class="dm-campo">
              <label for="f_buscar">Buscar</label>
              <div class="dm-buscar"><i class="bi bi-search"></i><input type="text" id="f_buscar" placeholder="Pedido, OP, asesor..."></div>
            </div>
            <button type="button" class="dm-limpiar" id="f_limpiar"><i class="bi bi-x-circle"></i> Limpiar</button>
          </div>

          <table class="dm-table" id="dm-tabla">
            <thead>
              <tr>
                <th style="width:34px;"></th>
                <th>Pedido</th>
                <th>Colegio</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>OP</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pedidos as $p): ?>
                <tr data-clave="<?= htmlspecialchars($p['clave']) ?>" data-colegio="<?= (int)$p['id_colegio'] ?>" data-estado="<?= $p['completado'] ? 'completado' : 'pendiente' ?>">
                  <?php $fp = $p['completado'] ? null : ($formato_pendiente[$p['clave']] ?? null); ?>
                  <td><?php if (!$p['completado'] && !$fp): ?><input type="checkbox" class="dm-chk" value="<?= htmlspecialchars($p['clave']) ?>" title="Incluir en el formato de venta directa"><?php endif; ?></td>
                  <td data-order="<?= (int)$p['id'] ?>"><a class="dm-ped" href="pedido_colegio.php?id_pedido=<?= (int)$p['id'] ?>" target="_blank">#<?= (int)$p['id'] ?></a></td>
                  <td data-search="<?= htmlspecialchars($p['colegio'] . ' ' . ($p['solicitante'] ?? '')) ?>">
                    <div class="dm-cole"><?= htmlspecialchars($p['colegio']) ?></div>
                    <?php if (trim((string)$p['solicitante']) !== ''): ?><div class="dm-sub"><i class="bi bi-person"></i><?= htmlspecialchars($p['solicitante']) ?></div><?php endif; ?>
                  </td>
                  <td data-order="<?= htmlspecialchars((string)$p['fecha']) ?>" style="white-space:nowrap;"><?= $p['fecha'] ? htmlspecialchars(date('d/m/Y', strtotime($p['fecha']))) : '' ?></td>
                  <td><?= $p['completado']
                    ? '<span class="dm-pill dm-pill-verde"><i class="bi bi-check2-circle"></i> Completo</span>'
                    : ($fp ? '<span class="dm-pill dm-pill-ambar"><i class="bi bi-clipboard-check"></i> Formato No. ' . (int)$fp['consecutivo'] . '</span>'
                           : '<span class="dm-pill dm-pill-azul"><i class="bi bi-truck"></i> Entregado</span>') ?></td>
                  <td><?= $p['ops'] ? '<div class="dm-op">OP ' . htmlspecialchars($p['ops']) . '</div>' : '<span class="dm-pill dm-pill-gris">Sin OP</span>' ?></td>
                  <td style="text-align:right;"><?php if ($p['completado']): ?>
                    <a class="dm-btn dm-btn-light" href="devolucion_colegio.php?id_pedido=<?= (int)$p['id_devolucion'] ?>" target="_blank"><i class="bi bi-eye"></i> Ver #<?= (int)$p['id_devolucion'] ?></a>
                  <?php elseif ($fp): ?>
                    <a class="dm-btn dm-btn-light" href="formato_venta_directa.php?id=<?= (int)$fp['id'] ?>"><i class="bi bi-clipboard-check"></i> Continuar formato</a>
                  <?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="dm-seleccion d-none" id="dm-resumen-sel">
            <div class="dm-seleccion-txt"><strong id="dm-n-sel">0</strong> <span id="dm-n-sel-txt">pedidos seleccionados</span> <span id="dm-n-sel-det"></span></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button type="button" class="dm-btn dm-btn-light" id="dm-quitar-sel"><i class="bi bi-x-circle"></i> Quitar selección</button>
              <button type="button" class="dm-btn" id="dm-continuar">Continuar con el formato <i class="bi bi-arrow-right"></i></button>
            </div>
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
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/ink-alerts.js"></script>
<script>
(function () {
  var MAX_PEDIDOS = <?= DVD_MAX_PEDIDOS ?>;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  var tabla = $('#dm-tabla').DataTable({
    order: [[1, 'desc']],
    pageLength: 15,
    lengthMenu: [15, 30, 50, 100],
    dom: 't<"d-flex justify-content-between align-items-center flex-wrap"ilp>',
    columnDefs: [{ orderable: false, targets: [0, 4, 6] }],
    language: {
      lengthMenu: 'Mostrar _MENU_', zeroRecords: 'No hay pedidos que coincidan con el filtro',
      emptyTable: 'No hay pedidos de venta entregados', info: '_START_–_END_ de _TOTAL_ pedidos',
      infoEmpty: 'Sin pedidos', infoFiltered: '(de _MAX_)', paginate: { first: '«', previous: '‹', next: '›', last: '»' }
    },
    drawCallback: function () {
      var info = this.api().page.info();
      $('#dm-contador').text(info.recordsDisplay + ' pedido' + (info.recordsDisplay === 1 ? '' : 's'));
    }
  });

  // Filtros por colegio (id exacto) y estado: los completos solo salen al elegir "Completos".
  var colegioFiltro = $('#f_colegio').val() || '', estadoFiltro = 'pendiente';
  $.fn.dataTable.ext.search.push(function (settings, data, idx) {
    if (settings.nTable.id !== 'dm-tabla') return true;
    var $tr = $(tabla.row(idx).node());
    return (!colegioFiltro || String($tr.data('colegio')) === colegioFiltro) && $tr.attr('data-estado') === estadoFiltro;
  });
  tabla.draw();
  $('#f_estado').on('change', function () { estadoFiltro = $(this).val() || 'pendiente'; tabla.draw(); });
  $('#f_colegio').on('change', function () { colegioFiltro = $(this).val() || ''; tabla.draw(); });
  $('#f_buscar').on('keyup', function () { tabla.search(this.value).draw(); });
  $('#f_limpiar').on('click', function () {
    $('#f_buscar').val(''); colegioFiltro = ''; $('#f_colegio').val('').trigger('change.select2');
    estadoFiltro = 'pendiente'; $('#f_estado').val('pendiente');
    tabla.search('').draw();
  });
  $('#f_periodo').on('change', function () {
    var col = $('#f_colegio').val() || '';
    window.location = 'devoluciones_venta_directa.php?periodo=' + $(this).val() + (col ? '&colegio=' + col : '');
  });

  // Pedidos marcados (se conservan al cambiar de página o de filtro): todos del mismo colegio.
  var seleccion = {}, colegioSel = null;
  function marcados() { return Object.keys(seleccion); }
  function pintarSeleccion() {
    var n = marcados().length;
    $('#dm-resumen-sel').toggleClass('d-none', !n);
    $('#dm-n-sel').text(n);
    $('#dm-n-sel-txt').text(n === 1 ? 'pedido seleccionado' : 'pedidos seleccionados');
    $('#dm-n-sel-det').text(n ? '· ' + $('#f_colegio option[value="' + colegioSel + '"]').text() : '');
  }
  $('#dm-tabla').on('change', '.dm-chk', function () {
    var $tr = $(this).closest('tr'), clave = this.value, col = String($tr.data('colegio'));
    if (this.checked) {
      if (colegioSel !== null && col !== colegioSel) {
        this.checked = false;
        inkToast('Todos los pedidos de una legalización deben ser del mismo colegio.', 'warn');
        return;
      }
      if (marcados().length >= MAX_PEDIDOS) {
        this.checked = false;
        inkToast('Puedes incluir hasta ' + MAX_PEDIDOS + ' pedidos a la vez.', 'warn');
        return;
      }
      seleccion[clave] = true; colegioSel = col;
    } else {
      delete seleccion[clave];
      if (!marcados().length) colegioSel = null;
    }
    $tr.toggleClass('dm-marcado', this.checked);
    pintarSeleccion();
  });
  $('#dm-quitar-sel').on('click', function () {
    seleccion = {}; colegioSel = null;
    tabla.$('.dm-chk').prop('checked', false);
    tabla.$('tr').removeClass('dm-marcado');
    pintarSeleccion();
  });
  // Se continúa en el formato de venta directa (solicitud → entrega → devolución → cierres).
  $('#dm-continuar').on('click', function () {
    var claves = marcados();
    if (!claves.length) return;
    $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Consultando World Office...');
    window.location = 'formato_venta_directa.php?' + $.param({ claves: claves });
  });
})();
</script>
</body>
</html>
