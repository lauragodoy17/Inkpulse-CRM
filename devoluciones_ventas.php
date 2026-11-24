<?php
/**
 * /devoluciones_ventas.php
 * "Devolución de ventas" manejada igual que devoluciones de muestras (pedido de la usuaria 2026-10-26):
 * reemplaza en el menú el formulario manual colegios_devols.php → solicitar_devol.php. Se elige el colegio
 * (y el período), se cargan de una vez todos sus pedidos de venta Entregados por devolver y se busca en
 * World Office lo despachado en sus remisiones CEUR y facturas FVE, sumado por título, para registrar UNA
 * sola devolución con tope. Lógica en includes/devoluciones_ventas_datos.php.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_ventas_datos.php");
require_once("includes/periodos_fechas.php");

dm_validar_acceso();
dvv_asegurar_columnas($bdd);

$id_inicial = (int)($_GET['id_pedido'] ?? 0);
['periodos' => $periodos, 'periodoActivo' => $periodo_activo] = obtener_periodo_activo($bdd);
// Período: el elegido; si se llega con ?id_pedido, el de ese pedido; si no, el activo hoy. 0 = todos.
if (isset($_GET['periodo'])) {
  $id_periodo = (int)$_GET['periodo'];
} elseif ($id_inicial > 0) {
  $st = $bdd->prepare("SELECT id_periodo FROM pedidos WHERE id = ?");
  $st->execute([$id_inicial]);
  $id_periodo = (int)($st->fetchColumn() ?: $periodo_activo);
} else {
  $id_periodo = (int)$periodo_activo;
}

// ?vd=1: menú Venta directa → Devolución de venta directa (mismo flujo, solo pedidos de venta directa).
$modo_vd = dvv_modo_vd();
$pedidos = dvv_listar_pedidos($bdd, $id_periodo ?: null);
$colegios = [];
$con_devolucion = 0;
$completados = 0;
$colegio_inicial = '';
foreach ($pedidos as $p) {
  $colegios[$p['id_colegio']] = $p['colegio'];
  if ((int)$p['n_devoluciones'] > 0) $con_devolucion++;
  if ($p['completado']) $completados++;
  if ((int)$p['id'] === $id_inicial) $colegio_inicial = (string)$p['id_colegio'];
}
asort($colegios, SORT_NATURAL | SORT_FLAG_CASE);
// Pedidos por devolver por colegio (los más recientes primero): se cargan todos al elegir el colegio.
$pendientes_por_colegio = [];
foreach ($pedidos as $p) if (!$p['completado']) $pendientes_por_colegio[(int)$p['id_colegio']][] = $p['clave'];
$token = bin2hex(random_bytes(16));
// ?tipo=corte|normal deja elegido el tipo de devolución (p. ej. al volver de un error al guardar).
$tipo_inicial = isset($_GET['tipo']) ? dvv_tipo_devolucion((string)$_GET['tipo']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - <?= $modo_vd ? 'Devolución de venta directa' : 'Devoluciones de venta' ?></title>
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
    .dm-grado { display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; background:#eef2ff; color:#4338ca; font-size:.7rem; font-weight:700; vertical-align:middle; white-space:nowrap; } /* grado de la adopción (2026-11-13) */
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
    /* Venta directa: remisiones a devolver, paquetes y libros sueltos */
    .dm-docs-sel { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:8px; }
    .dm-doc-op { display:flex; align-items:center; gap:10px; border:1.5px solid var(--dm-borde); border-radius:10px; padding:10px 12px; cursor:pointer; margin:0; background:#fff; transition:border-color .12s, background .12s; }
    .dm-doc-op:hover { border-color:#93c5fd; }
    .dm-doc-op.on { border-color:var(--dm-azul2); background:#eff6ff; }
    /* Venta (consecutivo) de la devolución de venta directa (2026-11-24) */
    .dm-ventas { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .dm-venta { display:inline-flex; align-items:center; gap:10px; border:1.5px solid var(--dm-borde); border-radius:10px; padding:10px 14px; cursor:pointer; margin:0; background:#fff; font-size:.86rem; }
    .dm-venta:hover { border-color:#93c5fd; }
    .dm-venta.on { border-color:var(--dm-azul2); background:#eff6ff; }
    .dm-venta small { color:#64748b; }
    .dm-doc-op-txt { font-size:.78rem; color:var(--dm-gris); }
    .vd-add { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .vd-add-sel { flex:1 1 320px; max-width:560px; min-width:0; }
    .vd-quitar { border:none; background:#fee2e2; color:#b91c1c; width:30px; height:30px; border-radius:8px; cursor:pointer; }
    .dm-stepper.vd-exceso { border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.12); }
    /* Paquetes abiertos: cuántos se abrieron, qué libro vuelve suelto y a qué precio se vendió cada libro */
    .vd-ab { border:1px solid #fde68a; border-radius:12px; background:#fffdf5; margin-bottom:12px; overflow:hidden; }
    .vd-ab-tit { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; background:#fef3c7; padding:10px 14px; }
    .vd-ab-nombre { font-weight:800; color:#92400e; font-size:.9rem; }
    .vd-ab-der { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .vd-ab-lbl { font-size:.72rem; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:.03em; }
    .vd-ab table { width:100%; }
    .vd-ab th { font-size:.7rem; font-weight:700; color:var(--dm-gris); text-transform:uppercase; letter-spacing:.03em; padding:8px 10px; border-bottom:1px solid #fde68a; }
    .vd-ab td { padding:8px 10px; font-size:.84rem; border-top:1px solid #fef3c7; vertical-align:middle; }
    .vd-ab .c { text-align:center; } .vd-ab .r { text-align:right; }
    .vd-ab tr.vd-ab-vuelve td { background:#fff7ed; }
    .vd-ab-in { height:34px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-weight:700; font-size:.88rem; color:var(--dm-tinta); }
    .vd-ab-in:focus { border-color:var(--dm-azul2); box-shadow:0 0 0 3px rgba(37,99,235,.15); outline:none; }
    .vd-ab-in.vd-sp-mal { border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.12); }
    .vd-ab-desc { width:80px; } .vd-ab-precio { width:130px; }
    .vd-ab-vend { font-weight:800; color:var(--dm-tinta); }
    .vd-sp-ref { font-size:.72rem; color:#94a3b8; font-weight:600; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="dm-hero">
        <h4><i class="bi bi-arrow-return-left"></i> <?= $modo_vd ? 'Devolución de venta directa' : 'Devoluciones de venta' ?></h4>
        <p><?php if ($modo_vd): ?>Solo colegios con canal de venta <strong>Venta directa - Eureka</strong>. <?php endif; ?>Elige el colegio y se cargan de una vez todos sus pedidos de venta entregados por devolver, con los títulos sumados de sus remisiones CEUR y facturas FVE de World Office (si un pedido no tiene esos documentos en WO, se toma lo solicitado en el CRM). Registra una sola devolución con todos.</p>
        <div class="dm-flujo">
          <span>Colegio</span><i class="bi bi-chevron-right"></i>
          <span>Pedidos entregados</span><i class="bi bi-chevron-right"></i>
          <span>Remisiones CEUR / Facturas FVE en WO</span><i class="bi bi-chevron-right"></i>
          <span>Títulos sumados</span><i class="bi bi-chevron-right"></i>
          <span>Devolución</span>
        </div>
      </div>

      <div class="dm-stats">
        <div class="dm-stat"><div class="dm-stat-ico azul"><i class="bi bi-box-seam"></i></div><div><div class="dm-stat-num"><?= number_format(count($pedidos), 0, ',', '.') ?></div><div class="dm-stat-lbl">Pedidos entregados</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico verde"><i class="bi bi-building"></i></div><div><div class="dm-stat-num"><?= number_format(count($colegios), 0, ',', '.') ?></div><div class="dm-stat-lbl">Colegios</div></div></div>
        <div class="dm-stat"><div class="dm-stat-ico ambar"><i class="bi bi-arrow-counterclockwise"></i></div><div><div class="dm-stat-num"><?= number_format($con_devolucion, 0, ',', '.') ?></div><div class="dm-stat-lbl">Con devoluciones registradas</div></div></div>
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
            <!-- Tipo de devolución (2026-10-26): aparece al elegir el colegio; se carga al elegirlo. -->
            <div class="dm-campo d-none" id="dm-campo-tipo">
              <label for="f_tipo">Tipo de devolución <span style="color:#dc2626;">*</span></label>
              <select id="f_tipo" class="form-control" style="height:38px;border-radius:10px;font-size:.88rem;min-width:170px;">
                <option value="">Elige el tipo...</option>
                <?php foreach (DVV_TIPOS_DEVOLUCION as $valor => $etiqueta): ?>
                  <option value="<?= $valor ?>"<?= $tipo_inicial === $valor ? ' selected' : '' ?>><?= htmlspecialchars($etiqueta) ?></option>
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

          <div class="dm-vacio" id="dm-sin-colegio"><i class="bi bi-building"></i>Elige un colegio para cargar todos sus pedidos de venta por devolver<?= $id_periodo ? ' del período' : '' ?>.</div>
          <div class="dm-vacio d-none" id="dm-sin-tipo"><i class="bi bi-ui-radios"></i>Elige el tipo de devolución: <strong>Física</strong> (remisiones CEUR y facturas FVE de los pedidos menos lo ya devuelto de ellos) o <strong>De corte</strong> (lo remisionado menos todas las devoluciones del colegio).</div>
          <div class="dm-vacio d-none" id="dm-sin-pendientes"><i class="bi bi-check2-circle"></i>Este colegio no tiene pedidos de venta por devolver<?= $id_periodo ? ' en el período' : '' ?>.</div>

          <div class="dm-seleccion d-none" id="dm-resumen-sel">
            <div class="dm-seleccion-txt"><strong id="dm-n-sel">0</strong> <span id="dm-n-sel-txt">pedidos por devolver</span></div>
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
  var MAX_PEDIDOS = <?= DVD_MAX_PEDIDOS ?>;
  var TOKEN = <?= json_encode($token) ?>;
  var MODO_VD = <?= $modo_vd ? "'&vd=1'" : "''" ?>; // modo Devolución de venta directa
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var num = function (c) { return parseInt(String(c).slice(1), 10); }; // 'v210' -> 210

  // Pedidos por devolver de cada colegio (claves 'v210'), los más recientes primero. Al elegir el colegio se
  // cargan TODOS de una vez, igual que en devoluciones de muestras.
  var PENDIENTES = <?= json_encode($pendientes_por_colegio ?: new stdClass()) ?>;
  var clavesColegio = [];
  function aplicarColegio(cargarTodo) {
    var col = $('#f_colegio').val() || '', todos = PENDIENTES[col] || [], tipo = $('#f_tipo').val() || '';
    clavesColegio = todos.slice(0, MAX_PEDIDOS);
    $('#dm-campo-tipo').toggleClass('d-none', !col);
    $('#dm-sin-colegio').toggleClass('d-none', !!col);
    $('#dm-sin-pendientes').toggleClass('d-none', !col || todos.length > 0);
    $('#dm-sin-tipo').toggleClass('d-none', !col || !todos.length || !!tipo);
    $('#dm-resumen-sel').toggleClass('d-none', !clavesColegio.length || !tipo);
    $('#dm-n-sel').text(clavesColegio.length);
    $('#dm-n-sel-txt').text(clavesColegio.length === 1 ? 'pedido por devolver' : 'pedidos por devolver');
    if (consulta) consulta.abort();
    $('#dm-paso2').addClass('d-none');
    if (!tipo) return;
    if (todos.length > MAX_PEDIDOS) inkToast('Se cargaron los ' + MAX_PEDIDOS + ' pedidos más recientes de ' + todos.length + ' (máximo por devolución).', 'warn');
    if (clavesColegio.length && cargarTodo !== false) cargar(clavesColegio);
  }
  $('#f_colegio, #f_tipo').on('change', function () { aplicarColegio(); });
  function tipoActual() { return $('#f_tipo').val() === 'corte' ? 'corte' : 'normal'; }
  // El período recarga la página (la lista de colegios y pedidos sale del servidor).
  $('#f_periodo').on('change', function () { window.location.href = 'devoluciones_ventas.php?periodo=' + encodeURIComponent($(this).val()) + MODO_VD; });
  $('#dm-cerrar').on('click', function () { $('#dm-paso2').addClass('d-none'); });

  function dato(icono, label, val) {
    return '<div class="dm-dato"><i class="bi ' + icono + '"></i><div><div class="dm-dato-lbl">' + esc(label) + '</div><div class="dm-dato-val">' + esc(val) + '</div></div></div>';
  }
  function aviso(tipo, icono, html) { return '<div class="dm-aviso ' + tipo + '"><i class="bi ' + icono + '"></i><div>' + html + '</div></div>'; }
  function lnkPedido(id) { return '<a class="dm-ped" href="pedido_colegio.php?id_pedido=' + id + '&tp=4" target="_blank">#' + id + '</a>'; }

  // Una sola consulta a la vez: una nueva cancela la anterior. CORTE = el detalle pintado es de corte.
  var consulta = null, CORTE = false;
  function cargar(claves, docs) {
    if (consulta) consulta.abort();
    $('#dm-paso2').removeClass('d-none');
    $('#dm-paso2-titulo').text((tipoActual() === 'corte' ? 'Devolución de corte — ' : 'Devolución física — ')
      + (claves.length === 1 ? 'títulos del pedido #' + num(claves[0]) : 'títulos de ' + claves.length + ' pedidos (sumados)'));
    $('#dm-detalle').html('<div class="dm-cargando"><div class="dm-spinner"></div>Buscando las OP, remisiones y facturas en World Office'
      + (claves.length > 1 ? ' de ' + claves.length + ' pedidos' : '') + '...</div>');
    $('html, body').animate({ scrollTop: $('#dm-paso2').offset().top - 80 }, 300);

    var params = { claves: claves, tipo: tipoActual(), vd: MODO_VD ? 1 : 0 };
    if (docs) { params.sel = 1; params.docs = docs; } // venta directa: solo las remisiones elegidas
    var xhr = consulta = $.getJSON('ajax/devoluciones_ventas_detalle.php', params);
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
        h += '<span class="dm-pill ' + (d.tipo === 'FV' ? 'dm-pill-fv' : 'dm-pill-rem') + '" title="' + esc(d.fecha + ' · ' + d.concepto) + '"><i class="bi bi-receipt"></i> ' + esc(d.documento) + '</span>';
      });
      h += '</div>';
    }
    if (p.compartidos && p.compartidos.length) h += '<div class="dm-sub" style="margin-top:8px;"><i class="bi bi-link-45deg"></i> Ya contado: ' + esc(p.compartidos.join(', ')) + '</div>';
    if (p.error) h += '<div class="dm-sub" style="color:#b91c1c;margin-top:8px;"><i class="bi bi-x-octagon"></i> ' + esc(p.error) + '</div>';
    if (p.sin_documentos) {
      h += '<div class="dm-sub" style="color:#92400e;margin-top:8px;"><i class="bi bi-exclamation-triangle"></i> '
        + (p.sin_op ? 'No tiene OP asociada' : (CORTE ? 'World Office no trajo ninguna remisión CEUR con su OP' : 'World Office no trajo ninguna remisión CEUR ni factura FVE con su OP'))
        + (CORTE ? ': no tiene nada remisionado para el corte.' : ': se cargó lo solicitado en el CRM.') + '</div>';
    }
    if (p.cierre) h += p.cierre.tipo === 'vd'
      ? '<div class="dm-sub" style="margin-top:8px;">Cerrado con sus ' + MAX_DEVOL_VD + ' devoluciones de venta directa (' + lnksDevol(p.cierre.ids) + '): en venta directa se registran máximo ' + MAX_DEVOL_VD + ' devoluciones por pedido (si se anula una, el pedido se vuelve a abrir).</div>'
      : '<div class="dm-sub" style="margin-top:8px;">Cerrado con la <a href="devolucion_colegio.php?id_pedido=' + p.cierre.id + '" target="_blank">legalización completa #' + p.cierre.id + '</a>: no suma disponible.</div>';
    return h + '</div>';
  }

  // ── Venta directa (colegio con canal Venta directa - Eureka, 2026-11-10): primero se eligen las remisiones /
  // facturas a devolver; con ellas se devuelven paquetes completos y libros sueltos (dvv_vd_estructura). ──
  var SEL_DOCS = null; // documentos elegidos (ids de WO o 'crm:v210') de la última consulta
  // Venta directa: máximo de devoluciones por pedido (DVV_MAX_DEVOL_VD); la segunda solo con lo que quedó sin devolver.
  var MAX_DEVOL_VD = <?= (int)DVV_MAX_DEVOL_VD ?>, VD_N_PREVIAS = 0;
  function lnksDevol(ids) {
    return (ids || []).map(function (id) { return '<a href="devolucion_colegio.php?id_pedido=' + id + '" target="_blank">#' + id + '</a>'; }).join(', ');
  }
  // Aviso de las devoluciones de venta directa ya registradas de los pedidos incluidos.
  function avisoPreviasVd(r) {
    var ids = {}, n = 0;
    r.pedidos.forEach(function (p) {
      if (r.incluidos.indexOf(p.clave) < 0) return;
      var dv = p.devoluciones_vd || [];
      n = Math.max(n, dv.length);
      dv.forEach(function (d) { ids[d.id] = true; });
    });
    VD_N_PREVIAS = n;
    if (!n) return '';
    return aviso('warn', 'bi-arrow-repeat', '<strong>Devolución ' + (n + 1) + ' de ' + MAX_DEVOL_VD + '.</strong> Ya se registró la devolución '
      + lnksDevol(Object.keys(ids)) + '; las cantidades disponibles son solo las unidades que quedaron sin devolver.'
      + (n + 1 >= MAX_DEVOL_VD ? ' Después de esta, los pedidos quedan cerrados.' : ''));
  }
  function resumenVd(r, soloIncluidos) {
    var pedidos = soloIncluidos ? r.pedidos.filter(function (p) { return r.incluidos.indexOf(p.clave) >= 0; }) : r.pedidos;
    return '<div class="dm-resumen">'
      + dato('bi-building', 'Colegio', r.colegio)
      + dato('bi-person-badge', 'Cliente', r.cliente || '—')
      + dato('bi-box-seam', pedidos.length > 1 ? 'Pedidos de venta' : 'Pedido de venta', pedidos.map(function (p) { return '#' + p.id; }).join(', '))
      + dato('bi-shop', 'Canal', 'Venta directa - Eureka')
      + '</div>';
  }
  function pintarSeleccion(r) {
    var h = resumenVd(r) + '<div class="dm-subtitulo"><i class="bi bi-receipt"></i> Elige las remisiones que quieres devolver</div>'
      + '<div class="dm-docs-sel">', n = 0;
    r.pedidos.forEach(function (p) {
      p.documentos.forEach(function (d) {
        var marcado = SEL_DOCS ? SEL_DOCS.indexOf(String(d.id)) >= 0 : false;
        h += '<label class="dm-doc-op' + (marcado ? ' on' : '') + '"><input type="checkbox" class="dm-check dm-doc-chk" value="' + esc(d.id) + '"' + (marcado ? ' checked' : '') + '>'
          + '<span class="dm-pill ' + (d.tipo === 'FV' ? 'dm-pill-fv' : 'dm-pill-rem') + '"><i class="bi bi-receipt"></i> ' + esc(d.documento) + '</span>'
          + '<span class="dm-doc-op-txt">' + (d.tipo === 'FV' ? 'Factura' : 'Remisión') + ' · ' + esc(d.fecha || '') + ' · Pedido ' + lnkPedido(p.id) + '</span></label>';
        n++;
      });
      if (p.fuente === 'crm') {
        var v = 'crm:' + p.clave, m = SEL_DOCS ? SEL_DOCS.indexOf(v) >= 0 : false;
        h += '<label class="dm-doc-op' + (m ? ' on' : '') + '"><input type="checkbox" class="dm-check dm-doc-chk" value="' + esc(v) + '"' + (m ? ' checked' : '') + '>'
          + '<span class="dm-pill dm-pill-ambar">Solicitud CRM</span><span class="dm-doc-op-txt">Pedido ' + lnkPedido(p.id) + ' sin remisión en World Office: se toma lo solicitado</span></label>';
        n++;
      }
    });
    h += '</div>';
    if (!n) h += aviso('warn', 'bi-info-circle', 'Los pedidos de este colegio no tienen remisiones ni facturas por devolver.');
    else h += '<div class="dm-seleccion" style="margin-top:14px;"><div class="dm-seleccion-txt"><strong id="dm-n-docs">0</strong> <span id="dm-n-docs-txt">remisiones elegidas</span></div>'
      + '<button type="button" class="dm-btn" id="dm-cargar-docs" disabled><i class="bi bi-arrow-right-circle"></i> Continuar con las remisiones elegidas</button></div>';
    $('#dm-paso2-titulo').text('Devolución de venta directa — elige las remisiones');
    $('#dm-detalle').html(h);
    contarDocs();
  }
  function contarDocs() {
    var n = $('.dm-doc-chk:checked').length;
    $('#dm-n-docs').text(n); $('#dm-n-docs-txt').text(n === 1 ? 'remisión elegida' : 'remisiones elegidas');
    $('#dm-cargar-docs').prop('disabled', !n);
    $('.dm-doc-chk').each(function () { $(this).closest('.dm-doc-op').toggleClass('on', this.checked); });
  }
  $(document).on('change', '.dm-doc-chk', contarDocs);
  $(document).on('click', '#dm-cargar-docs', function () {
    SEL_DOCS = $('.dm-doc-chk:checked').map(function () { return String(this.value); }).get();
    cargar(clavesColegio, SEL_DOCS);
  });
  $(document).on('click', '#dm-cambiar-docs', function () { cargar(clavesColegio); });

  // VD_LIM_PAQ / VD_LIM_SUE: tope por parte de cada título (lo pedido en paquete y lo pedido suelto; dvv_vd_estructura).
  // Un título sin tope de parte usa solo su disponible (VD_DISP).
  var VD_DISP = {}, VD_NOMBRE = {}, VD_PAQ = {}, VD_LIM_PAQ = {}, VD_LIM_SUE = {}, VD_PRECIOS = {}, VD_GRADO = {};
  // Grado del libro en la adopción del colegio (2026-11-13): etiqueta junto al título.
  function tagGrado(g) { return g ? ' <span class="dm-grado">' + esc(g) + '</span>' : ''; }
  var VD_LIB_PAQ = []; // títulos de los paquetes que se pueden devolver sueltos (vd.libros_paquete)
  function limParte(parte, l) {
    var lim = parte === 'sue' ? VD_LIM_SUE : VD_LIM_PAQ;
    return lim[l] !== undefined ? Math.min(lim[l], VD_DISP[l] || 0) : (VD_DISP[l] || 0);
  }
  // A qué venta (consecutivo del formato) corresponde la devolución (2026-11-24): el consecutivo se reserva el día de la
  // venta sin pedidos y aquí se elige; los pedidos de la devolución quedan en ese formato. Si ya están en un formato, va a ese.
  function fechaCorta(f) { var p = String(f || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ''; }
  function selectorVenta(v) {
    if (!v) return '';
    if (v.error) return aviso('err', 'bi-x-octagon', esc(v.error));
    var h = '<div class="dm-subtitulo"><i class="bi bi-clipboard-check"></i> ¿A qué venta corresponde?</div><div class="dm-ventas">';
    if (v.fijo) {
      return h + '<label class="dm-venta on"><input type="hidden" name="id_formato" value="' + v.fijo.id + '"><i class="bi bi-lock-fill"></i>'
        + '<span><strong>Formato No. ' + v.fijo.consecutivo + '</strong> · venta ' + esc(fechaCorta(v.fijo.fecha_venta)) + '<br><small>Sus pedidos ya están en este formato.</small></span></label></div>';
    }
    if (!v.reservados.length) {
      return h + '<div class="dm-sub" style="margin:0 0 12px;"><i class="bi bi-info-circle"></i> Este colegio no tiene ventas con consecutivo reservado: al registrar la devolución se crea un formato nuevo.</div></div>';
    }
    v.reservados.forEach(function (f, i) {
      var sel = i === v.reservados.length - 1; // la venta más reciente
      h += '<label class="dm-venta' + (sel ? ' on' : '') + '"><input type="radio" class="dm-check" name="id_formato" value="' + f.id + '"' + (sel ? ' checked' : '') + '>'
        + '<span><strong>Formato No. ' + f.consecutivo + '</strong> · venta ' + esc(fechaCorta(f.fecha_venta)) + '</span></label>';
    });
    return h + '</div><div class="dm-sub" style="margin:-4px 0 14px;">Los pedidos de esta devolución quedarán en el formato elegido.</div>';
  }
  $(document).on('change', '.dm-venta input[type=radio]', function () { $('.dm-venta').removeClass('on'); $(this).closest('.dm-venta').addClass('on'); });

  function pintarVD(r) {
    VD_DISP = {}; VD_NOMBRE = {}; VD_PAQ = {}; VD_GRADO = {};
    r.titulos.forEach(function (t) { if (t.id_libro) { VD_DISP[t.id_libro] = t.disponible; VD_NOMBRE[t.id_libro] = t.descripcion; VD_GRADO[t.id_libro] = t.grado || ''; } });
    var vd = r.vd || { paquetes: [], sueltos: [], libros_paquete: [] };
    vd.paquetes.forEach(function (p) { VD_PAQ[p.id] = p; });
    VD_LIM_PAQ = vd.limite_paquete || {}; VD_LIM_SUE = vd.limite_suelto || {}; VD_PRECIOS = vd.precios || {}; VD_LIB_PAQ = vd.libros_paquete || [];
    var elegidos = [];
    r.pedidos.forEach(function (p) {
      p.documentos.forEach(function (d) { if (d.elegido) elegidos.push(d.documento); });
      if (p.fuente === 'crm' && p.crm_elegido) elegidos.push('Solicitud CRM #' + p.id);
    });
    var h = resumenVd(r, true) + '<div class="dm-seleccion" style="margin:0 0 16px;"><div class="dm-seleccion-txt"><i class="bi bi-receipt"></i> Remisiones: <strong style="font-size:.9rem;">' + esc(elegidos.join(', ')) + '</strong></div>'
      + '<button type="button" class="dm-btn dm-btn-light" id="dm-cambiar-docs"><i class="bi bi-pencil"></i> Cambiar remisiones</button></div>';
    h += avisoPreviasVd(r);
    var hayAlgo = vd.paquetes.some(function (p) { return p.max > 0; }) || vd.sueltos.length || vd.libros_paquete.length;
    if (!hayAlgo) { $('#dm-detalle').html(h + aviso('ok', 'bi-check-circle', '<strong>Nada por devolver</strong> en las remisiones elegidas.')); return; }

    h += '<form id="dm-form-vd" method="POST" action="php/devoluciones_ventas_guardar.php"><input type="hidden" name="token" value="' + esc(TOKEN) + '">'
      + '<input type="hidden" name="modo_vd" value="1"><input type="hidden" name="tipo_devolucion" value="normal">' + (MODO_VD ? '<input type="hidden" name="vd" value="1">' : '');
    r.incluidos.forEach(function (c) { h += '<input type="hidden" name="claves[]" value="' + esc(c) + '">'; });
    (SEL_DOCS || []).forEach(function (d) { h += '<input type="hidden" name="docs[]" value="' + esc(d) + '">'; });
    h += selectorVenta(r.ventas);
    var stepper = function (name, max, attrs) {
      return '<div class="dm-stepper"><button type="button" class="dm-menos" tabindex="-1">−</button>'
        + '<input type="number" class="dm-input vd-n" name="' + name + '" min="0" max="' + max + '" step="1" value="0" data-max="' + max + '" ' + attrs + '>'
        + '<button type="button" class="dm-mas" tabindex="-1">+</button></div>';
    };
    if (vd.paquetes.length) {
      h += '<div class="dm-subtitulo"><i class="bi bi-box-seam"></i> Paquetes completos</div><div class="table-responsive"><table class="dm-tit-table"><thead><tr><th>Paquete</th><th class="c">Paquetes completos disponibles</th><th class="c">A devolver</th></tr></thead><tbody>';
      vd.paquetes.forEach(function (p) {
        h += '<tr class="vd-fila' + (p.max > 0 ? '' : ' dm-agotado') + '"><td><div class="dm-libro">' + esc(p.paquete) + tagGrado(p.grado) + '</div><div class="dm-isbn">Cada paquete devuelve 1 unidad de: ' + esc(p.titulos.join(' + ')) + '</div></td>'
          + '<td class="c">' + (p.max > 0 ? '<span class="dm-pill dm-pill-verde vd-disp">' + p.max + '</span>' : '<span class="dm-pill dm-pill-gris">0</span>') + '</td>'
          + '<td class="c">' + (p.max > 0 ? stepper('paquete[' + p.id + ']', p.max, 'data-paq="' + p.id + '" data-titulo="' + esc(p.paquete) + '"') + '<span class="dm-max vd-queda"></span>' : '<span class="dm-sub" style="justify-content:center;">No alcanza</span>') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    if (vd.sueltos.length) {
      h += '<div class="dm-subtitulo"><i class="bi bi-book"></i> Libros sueltos remisionados</div><div class="table-responsive"><table class="dm-tit-table"><thead><tr><th>Título</th><th class="c">Disponible</th><th class="c">A devolver</th></tr></thead><tbody>';
      vd.sueltos.forEach(function (l) {
        var maxS = limParte('sue', l);
        h += '<tr class="vd-fila"><td><div class="dm-libro">' + esc(VD_NOMBRE[l]) + tagGrado(VD_GRADO[l]) + '</div></td><td class="c"><span class="dm-pill dm-pill-verde vd-disp">' + maxS + '</span></td>'
          + '<td class="c">' + stepper('suelto[' + l + ']', maxS, 'data-parte="sue" data-libro="' + l + '" data-titulo="' + esc(VD_NOMBRE[l]) + '"') + '<span class="dm-max vd-queda"></span></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    // 2026-11-19 (la usuaria): ya no hay "Libros sueltos de paquetes abiertos"; solo lo remisionado en WO.
    h += '<div class="dm-campos"><div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Observaciones</label>'
      + '<textarea name="observaciones" class="form-control" rows="2" maxlength="1000" placeholder="Opcional: estado de los libros, quién entrega, etc."></textarea></div><div></div></div>'
      + '<div class="dm-pie"><div class="dm-total"><strong id="vd-total">0</strong><span id="vd-total-txt">libros a devolver</span></div>'
      + '<button type="submit" class="dm-btn dm-btn-lg" id="vd-enviar" disabled><i class="bi bi-send"></i> Registrar devolución</button></div></form>';
    $('#dm-paso2-titulo').text('Devolución de venta directa — paquetes y libros sueltos');
    $('#dm-detalle').html(h);
    recalcVD();
  }
  // Unidades por libro: paquetes (1 de cada título) + sueltos + sueltos de paquete ≤ disponible del libro; además
  // paquetes + sueltos de paquete ≤ parte paquete del título y sueltos ≤ parte suelta (VD_LIM_PAQ / VD_LIM_SUE).
  function usoVD() {
    var uso = { total: {}, paq: {}, sue: {} };
    $('#dm-form-vd .vd-n').each(function () {
      var n = parseInt($(this).val(), 10) || 0, paq = $(this).data('paq'), l = $(this).data('libro'), parte = paq ? 'paq' : $(this).data('parte');
      if (!n) return;
      (paq ? VD_PAQ[paq].libros : [l]).forEach(function (x) {
        uso.total[x] = (uso.total[x] || 0) + n;
        uso[parte][x] = (uso[parte][x] || 0) + n;
      });
    });
    return uso;
  }
  function recalcVD() {
    $('.vd-ab-n').each(function () { if (!(parseInt(this.value, 10) >= 1)) this.value = 1; }); // un paquete abierto es al menos 1
    // Cada libro que vuelve suelto sale de un paquete abierto distinto: el otro libro de ese paquete se vendió (2026-11-13).
    // Los paquetes abiertos = la suma de los libros que vuelven sueltos (se pueden subir a mano si se abrió alguno más,
    // pero no bajar de esa suma).
    $('.vd-ab').each(function () {
      var p = $(this).data('paq'), $n = $(this).find('.vd-ab-n'), sumaV = 0;
      $(this).find('.vd-n').each(function () { sumaV += parseInt(this.value, 10) || 0; });
      var tope = Math.max(1, (VD_PAQ[p].max_abiertos || 0) - completosDe(p)), ant = $n.data('suma');
      var nAb = parseInt($n.val(), 10) || 0;
      // Sigue la suma mientras no se haya escrito a mano un número mayor.
      if (nAb < sumaV || ant === undefined || nAb === ant) nAb = Math.max(1, sumaV);
      $n.val(Math.min(nAb, tope)).data('suma', Math.max(1, sumaV));
    });
    var uso = usoVD(), malo = false, total = 0;
    Object.keys(uso.total).forEach(function (l) { total += uso.total[l]; });
    $('#dm-form-vd .vd-n').each(function () {
      var $in = $(this), n = parseInt($in.val(), 10) || 0, paq = $in.data('paq'), libros = paq ? VD_PAQ[paq].libros : [$in.data('libro')];
      var parte = paq ? 'paq' : $in.data('parte');
      // Lo que le queda a este campo: lo menor entre el disponible del título y el tope de su parte.
      var restante = function (l) { return Math.min((VD_DISP[l] || 0) - (uso.total[l] || 0), limParte(parte, l) - (uso[parte][l] || 0)); };
      var exceso = libros.filter(function (l) { return restante(l) < 0; });
      var queda = Math.min.apply(null, libros.map(function (l) { return restante(l) + n; }));
      // El máximo de cada campo depende de lo que ya usan los demás (p. ej. 2 paquetes de 9° dejan 0 de sus libros
      // como sueltos de paquete): se actualiza el tope del stepper (ajustar() usa data-max) y la píldora Disponible.
      var maxCampo = Math.max(0, queda), ab = $in.data('ab');
      // Paquetes abiertos: entre todos los libros que vuelven sueltos no se pasa de los paquetes que quedan por abrir.
      if (ab) {
        var otros = 0;
        $in.closest('.vd-ab').find('.vd-n').not($in).each(function () { otros += parseInt(this.value, 10) || 0; });
        var topeAb = Math.max(0, (VD_PAQ[ab].max_abiertos || 0) - completosDe(ab) - otros);
        if (n > topeAb) { exceso = exceso.length ? exceso : [$in.data('libro')]; malo = true; }
        maxCampo = Math.min(maxCampo, topeAb);
      }
      if (paq && $('.vd-ab[data-paq="' + paq + '"]').length) maxCampo = Math.min(maxCampo, Math.max(0, (VD_PAQ[paq].max_abiertos || 0) - abiertosDe(paq)));
      $in.data('max', maxCampo).attr('max', maxCampo);
      $in.closest('tr').find('.vd-disp').text(maxCampo).toggleClass('dm-pill-verde', maxCampo > 0).toggleClass('dm-pill-gris', maxCampo <= 0);
      $in.closest('.dm-stepper').toggleClass('activo', n > 0).toggleClass('vd-exceso', n > 0 && exceso.length > 0);
      $in.closest('td').find('.vd-queda').text(exceso.length && n > 0 ? 'Pasa lo disponible de ' + exceso.map(function (l) { return VD_NOMBRE[l]; }).join(', ') : 'máx. ' + Math.max(0, queda));
      if (n > 0 && exceso.length) malo = true;
    });
    $('#vd-total').text(total);
    $('#vd-total-txt').text((total === 1 ? 'libro' : 'libros') + ' a devolver');
    $('#vd-enviar').prop('disabled', total === 0 || malo);
    recalcAbiertos();
  }
  $(document).on('input change', '#dm-form-vd .vd-n', function () {
    var v = $(this).val(), n = v === '' ? 0 : Number(v);
    if (!Number.isInteger(n) || n < 0) $(this).val(0);
    recalcVD();
  });
  // ── Paquetes abiertos (2026-11-13): por paquete, cuántos se abrieron (abierto[P]); por libro, cuántos vuelven sueltos
  // (suelto_paq[P][L]), descuento y precio (sp_desc / sp_precio[P][L]). Vendidos = abiertos − vuelven. ──
  function fmtPesos(n) { return n === null || n === undefined || isNaN(n) ? '' : '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 2 }); }
  function numCampo(v) { v = String(v === undefined || v === null ? '' : v).trim().replace(',', '.'); return /^\d+(\.\d{1,2})?$/.test(v) ? parseFloat(v) : null; }
  function valCampo(n) { return n === null || n === undefined ? '' : String(Math.round(n * 100) / 100); }
  function abiertosDe(p) { return parseInt($('.vd-ab-n[data-ab="' + p + '"]').val(), 10) || 0; }
  function completosDe(p) { return parseInt($('#dm-form-vd .vd-n[data-paq="' + p + '"]').val(), 10) || 0; }
  function bloqueAbierto(p) {
    var h = '<div class="vd-ab" data-paq="' + p.id + '"><div class="vd-ab-tit"><div><div class="vd-ab-nombre"><i class="bi bi-box2"></i> ' + esc(p.paquete) + ' abierto</div>'
      + '<div class="vd-sp-ref">' + (p.precio ? 'Precio del paquete: ' + fmtPesos(p.precio) + ' (no se cobra en los abiertos)' : 'Paquete sin precio') + '</div></div>'
      + '<div class="vd-ab-der"><span class="vd-ab-lbl">Paquetes abiertos</span><div class="dm-stepper activo"><button type="button" class="dm-menos" tabindex="-1">−</button>'
      + '<input type="number" class="dm-input vd-ab-n" name="abierto[' + p.id + ']" min="1" step="1" value="1" data-ab="' + p.id + '" data-max="' + p.max_abiertos + '" data-titulo="' + esc(p.paquete) + ' (abiertos)"><button type="button" class="dm-mas" tabindex="-1">+</button></div>'
      + '<span class="dm-max vd-ab-queda" style="margin:0;"></span>'
      + '<button type="button" class="vd-quitar vd-ab-quitar" title="Quitar paquete abierto"><i class="bi bi-x-lg"></i></button></div></div>'
      + '<div class="vd-sp-ref vd-ab-cuenta" style="margin:0 0 8px;"></div>'
      + '<div class="table-responsive"><table><thead><tr><th>Libro</th><th class="c">Vuelve suelto</th><th class="c">Vendidos</th><th class="c">Descuento %</th><th class="r">Precio</th></tr></thead><tbody>';
    p.libros.forEach(function (l, i) {
      var ref = VD_PRECIOS[l] || {}, desc = ref.descuento !== null && ref.descuento !== undefined ? ref.descuento : null;
      var precio = ref.venta ? ref.venta : (ref.lista && desc !== null ? ref.lista * (1 - desc / 100) : (ref.lista || null));
      var nombre = VD_NOMBRE[l] || p.titulos[i] || ('Libro #' + l), puede = VD_LIB_PAQ.indexOf(l) >= 0;
      h += '<tr data-libro="' + l + '"><td><div class="dm-libro">' + esc(nombre) + tagGrado(VD_GRADO[l]) + '</div>'
        + '<div class="vd-sp-ref">' + (ref.lista ? 'Precio del libro: ' + fmtPesos(ref.lista) : 'Sin precio de lista') + (ref.venta ? ' · Precio venta padre: ' + fmtPesos(ref.venta) : '') + '</div></td>'
        + '<td class="c">' + (puede
            ? '<div class="dm-stepper"><button type="button" class="dm-menos" tabindex="-1">−</button><input type="number" class="dm-input vd-n" name="suelto_paq[' + p.id + '][' + l + ']" min="0" step="1" value="0" data-max="0" data-parte="paq" data-ab="' + p.id + '" data-libro="' + l + '" data-titulo="' + esc(nombre) + '"><button type="button" class="dm-mas" tabindex="-1">+</button></div><span class="dm-max vd-queda"></span>'
            : '<span class="dm-sub" style="justify-content:center;">No disponible</span>') + '</td>'
        + '<td class="c"><span class="vd-ab-vend">1</span></td>'
        + '<td class="c"><input type="text" inputmode="decimal" class="vd-ab-in vd-ab-desc" name="sp_desc[' + p.id + '][' + l + ']" value="' + esc(valCampo(desc)) + '" placeholder="0" data-libro="' + l + '"></td>'
        + '<td class="r"><input type="text" inputmode="decimal" class="vd-ab-in vd-ab-precio" name="sp_precio[' + p.id + '][' + l + ']" value="' + esc(valCampo(precio)) + '" placeholder="Precio"></td></tr>';
    });
    return h + '</tbody></table></div></div>';
  }
  // Vendidos por libro, tope de los paquetes abiertos y precios obligatorios (los libros con vendidos > 0).
  function recalcAbiertos() {
    $('.vd-ab').each(function () {
      var p = $(this).data('paq'), $n = $(this).find('.vd-ab-n'), nAb = parseInt($n.val(), 10) || 0;
      var tope = Math.max(1, (VD_PAQ[p].max_abiertos || 0) - completosDe(p));
      $n.data('max', tope).attr('max', tope);
      $(this).find('.vd-ab-queda').text('máx. ' + tope);
      var comp = completosDe(p);
      $(this).find('.vd-ab-cuenta').html('Paquetes por legalizar: <strong>' + (VD_PAQ[p].max_abiertos || 0) + '</strong> − ' + comp + ' completos − ' + nAb
        + ' abiertos = <strong>' + Math.max(0, (VD_PAQ[p].max_abiertos || 0) - comp - nAb) + '</strong> vendidos completos. Cada libro que vuelve suelto es un paquete abierto: el otro libro de ese paquete se vendió.');
      $(this).find('tbody tr').each(function () {
        var vuelve = parseInt($(this).find('.vd-n').val(), 10) || 0, vend = Math.max(0, nAb - vuelve);
        $(this).toggleClass('vd-ab-vuelve', vuelve > 0);
        $(this).find('.vd-ab-vend').text(vend);
        var $pr = $(this).find('.vd-ab-precio'), $d = $(this).find('.vd-ab-desc');
        var vP = String($pr.val()).trim(), nP = numCampo(vP), vD = String($d.val()).trim(), nD = numCampo(vD);
        $pr.toggleClass('vd-sp-mal', (vP !== '' && nP === null) || (vend > 0 && !nP));
        $d.toggleClass('vd-sp-mal', vD !== '' && (nD === null || nD > 100));
      });
    });
  }
  // Al cambiar el descuento se recalcula el precio desde el precio del libro (si se conoce); el precio se puede ajustar a mano.
  $(document).on('input', '.vd-ab-desc', function () {
    var l = $(this).data('libro'), ref = VD_PRECIOS[l] || {}, d = numCampo($(this).val());
    if (ref.lista && d !== null && d <= 100) $(this).closest('tr').find('.vd-ab-precio').val(valCampo(ref.lista * (1 - d / 100)));
    recalcAbiertos();
  });
  $(document).on('input', '.vd-ab-precio', recalcAbiertos);
  $(document).on('input change', '#dm-form-vd .vd-ab-n', function () {
    var n = Number($(this).val()), max = parseInt($(this).data('max'), 10) || 1;
    if (!Number.isInteger(n) || n < 1) $(this).val(1); else if (n > max) $(this).val(max);
    recalcVD();
  });

  $(document).on('click', '#vd-sp-agregar', function () {
    var v = String($('#vd-sp-libro').val() || '');
    if (!v) { inkToast('Elige el libro que vuelve suelto.', 'warn'); return; }
    var p = parseInt(v.split(':')[0], 10), l = parseInt(v.split(':')[1], 10), paq = VD_PAQ[p];
    if (!paq) return;
    if (!$('.vd-ab[data-paq="' + p + '"]').length) {
      if ((paq.max_abiertos || 0) - completosDe(p) < 1) {
        inkToast('Del "' + paq.paquete + '" ya no quedan paquetes por abrir: los paquetes completos elegidos los usan todos.', 'warn');
        return;
      }
      $('#vd-ab-lista').append(bloqueAbierto(paq));
    }
    recalcVD();
    var $in = $('.vd-ab[data-paq="' + p + '"] .vd-n[data-libro="' + l + '"]');
    if (!(parseInt($in.data('max'), 10) > 0)) {
      inkToast('Ya no quedan unidades de "' + VD_NOMBRE[l] + '" en paquete: los paquetes completos elegidos ya las usan.', 'warn');
    } else if (!(parseInt($in.val(), 10) > 0)) {
      $in.val(1);
    }
    $('#vd-sp-libro').val('').trigger('change');
    recalcVD();
  });
  $(document).on('click', '.vd-ab-quitar', function () {
    $(this).closest('.vd-ab').remove();
    recalcVD();
  });
  // Después del manejador general de los steppers (que suma/resta y aplica el máximo): si no, se recalcula con el valor anterior.
  $(document).on('click', '#dm-form-vd .dm-mas, #dm-form-vd .dm-menos', function () { setTimeout(recalcVD, 0); });
  $(document).on('submit', '#dm-form-vd', function (e) {
    e.preventDefault();
    var form = this, total = parseInt($('#vd-total').text(), 10) || 0;
    if (!total) { inkToast('Ingresa al menos un paquete o libro a devolver.', 'warn'); return; }
    if ($('.vd-exceso').length) { inkToast('Hay libros que pasan lo disponible en las remisiones elegidas.', 'error'); return; }
    if ($(form).find('.dm-venta input[type=radio]').length && !$(form).find('.dm-venta input[type=radio]:checked').length) { inkToast('Elige a qué venta (formato) corresponde la devolución.', 'warn'); return; }
    recalcAbiertos();
    var $mal = $('.vd-ab .vd-sp-mal');
    if ($mal.length) { inkToast('Revisa los paquetes abiertos: cada libro vendido necesita su precio y el descuento va de 0 a 100 (máx. 2 decimales).', 'error'); $mal.first().focus(); return; }
    var abiertos = 0; $('.vd-ab-n').each(function () { abiertos += parseInt($(this).val(), 10) || 0; });
    var paqs = 0; $('#dm-form-vd .vd-n[data-paq]').each(function () { paqs += parseInt($(this).val(), 10) || 0; });
    inkConfirm({ type: 'info', title: '¿Registrar la devolución?', text: 'Se registrará una devolución de ' + total + ' libro(s)' + (paqs ? ' (' + paqs + ' paquete(s) completo(s) incluidos)' : '') + (abiertos ? ', con ' + abiertos + ' paquete(s) abierto(s) cuyos demás libros se venden sueltos a los precios puestos,' : '') + ' de las remisiones elegidas. ' + (VD_N_PREVIAS + 1 >= MAX_DEVOL_VD
      ? 'En venta directa es la ÚLTIMA devolución (' + MAX_DEVOL_VD + ' de ' + MAX_DEVOL_VD + '): sus unidades se descuentan en el formato de venta directa de la primera (bajan las vendidas y el total) y los pedidos quedan cerrados.'
      : 'En venta directa es la devolución ' + (VD_N_PREVIAS + 1) + ' de ' + MAX_DEVOL_VD + ': se genera el formato de venta directa y después podrás registrar otra solo con las unidades que queden sin devolver (se descontará en ese mismo formato).'), btnOk: 'Registrar' }, function () {
      $('#vd-enviar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
      form.submit();
    });
  });

  function pintar(r) {
    if (!r.ok) { $('#dm-detalle').html(aviso('err', 'bi-x-octagon', esc(r.error))); return; }
    if (r.es_vd && !r.con_seleccion) { pintarSeleccion(r); return; }
    if (r.es_vd) { pintarVD(r); return; }
    CORTE = r.tipo_devolucion === 'corte';
    var pedidos = r.pedidos, varios = pedidos.length > 1;
    var unidades = pedidos.reduce(function (s, p) { return s + p.unidades; }, 0);
    var h = '<div class="dm-resumen">'
      + dato('bi-building', 'Colegio', r.colegio)
      + dato('bi-person-badge', 'Cliente', r.cliente || '—')
      + dato('bi-box-seam', varios ? 'Pedidos de venta' : 'Pedido de venta', pedidos.map(function (p) { return '#' + p.id; }).join(', '))
      + dato('bi-stack', 'Unidades despachadas / solicitadas', unidades)
      + '</div>';

    h += '<div class="dm-subtitulo"><i class="bi bi-receipt"></i> Pedidos y documentos de World Office</div><div class="dm-docs">';
    pedidos.forEach(function (p) { h += tarjetaPedido(p); });
    h += '</div>';
    if (r.clientes_distintos) {
      h += aviso('warn', 'bi-person-exclamation', 'Los pedidos tienen clientes distintos (' + esc(pedidos.map(function (p) { return '#' + p.id + ': ' + (p.cliente || '—'); }).join(' · '))
        + '). La devolución queda a nombre de <strong>' + esc(r.cliente || '—') + '</strong>, el cliente del pedido más antiguo.');
    }
    pedidos.forEach(function (p) {
      p.documentos.forEach(function (d) {
        if (d.ped && parseInt(d.ped, 10) !== p.id) h += aviso('warn', 'bi-exclamation-triangle', 'El concepto de <strong>' + esc(d.documento) + '</strong> dice PED ' + esc(d.ped) + ' y el pedido es el #' + p.id + '. Se tomó porque la OP coincide; revísalo antes de guardar.');
      });
    });
    var deCrm = pedidos.filter(function (p) { return p.fuente === 'crm'; }).map(function (p) { return p.id; });
    if (deCrm.length) {
      h += aviso('warn', 'bi-info-circle', 'World Office no trajo remisión CEUR ni factura FVE de ' + deCrm.map(function (id) { return '#' + id; }).join(', ')
        + ': de ' + (deCrm.length === 1 ? 'ese pedido' : 'esos pedidos') + ' se toman los libros y las cantidades <strong>solicitados en el CRM</strong>. Revisa que coincidan con lo que se entregó antes de guardar.');
    }
    var excluidos = pedidos.filter(function (p) { return r.incluidos.indexOf(p.clave) < 0; });
    if (excluidos.length && r.incluidos.length) {
      h += aviso('warn', 'bi-dash-circle', 'No entra' + (excluidos.length > 1 ? 'n' : '') + ' en esta devolución: ' + excluidos.map(function (p) { return '#' + p.id; }).join(', ')
        + ' (sin títulos por devolver o completado).');
    }

    if (!r.titulos.length) {
      $('#dm-detalle').html(h + '<div class="dm-vacio"><i class="bi bi-search"></i>No hay títulos para devolver en ' + (varios ? 'estos pedidos' : 'este pedido') + '.</div>');
      return;
    }

    if (CORTE) {
      h += aviso('warn', 'bi-scissors', '<strong>Devolución de corte:</strong> el disponible de cada título es lo <strong>remisionado</strong> (remisiones CEUR de estos pedidos) menos <strong>todas</strong> las devoluciones de venta ya registradas del colegio en el período (normales, de corte y anteriores), sin contar las anuladas.');
    }
    var fuentes = {};
    pedidos.forEach(function (p) { if (p.unidades) fuentes[p.fuente] = true; });
    var colBase = CORTE ? 'Remisionada' : (fuentes.auto && fuentes.crm ? 'Despachada / solicitada' : (fuentes.crm ? 'Solicitada' : 'Despachada'));
    var colDevuelta = CORTE ? 'Devuelta (todas las del colegio)' : 'Ya devuelta';
    var hayDisponible = r.titulos.some(function (t) { return t.id_libro && t.disponible > 0; });
    h += '<div class="dm-subtitulo" style="justify-content:space-between;"><span><i class="bi bi-book"></i> Títulos ' + (varios ? 'sumados de todos los pedidos' : '') + ' (' + r.titulos.length + ')</span>'
      + (hayDisponible ? '<button type="button" class="dm-btn dm-btn-light" id="dm-todo" style="padding:5px 12px;font-size:.75rem;"><i class="bi bi-check2-all"></i> Devolver todo lo disponible</button>' : '')
      + '</div>';

    h += '<form id="dm-form" method="POST" action="php/devoluciones_ventas_guardar.php"><input type="hidden" name="token" value="' + esc(TOKEN) + '">' + (MODO_VD ? '<input type="hidden" name="vd" value="1">' : '')
      + '<input type="hidden" name="tipo_devolucion" value="' + (CORTE ? 'corte' : 'normal') + '">';
    r.incluidos.forEach(function (c) { h += '<input type="hidden" name="claves[]" value="' + esc(c) + '">'; });
    h += '<div class="table-responsive"><table class="dm-tit-table"><thead><tr>'
      + '<th>Título</th><th>Documentos</th><th class="c">' + colBase + '</th><th class="c">' + colDevuelta + '</th><th class="c">Disponible</th><th class="c">A devolver</th>'
      + '</tr></thead><tbody>';
    r.titulos.forEach(function (t) {
      var sinLibro = !t.id_libro, editable = !sinLibro && t.disponible > 0;
      var pct = t.base > 0 ? Math.min(100, Math.round(t.devuelta * 100 / t.base)) : 0;
      // Con varios pedidos, de cuál sale el saldo (se descuenta del más antiguo primero).
      // En el corte el disponible es del colegio, no por pedido: no se muestra el desglose.
      var reparto = !CORTE && varios && t.pedidos.length > 1
        ? '<div class="dm-reparto">' + t.pedidos.map(function (p) { return '#' + p.id + ': ' + p.disponible; }).join(' · ') + '</div>' : '';
      h += '<tr' + (!editable ? ' class="dm-agotado"' : '') + '>'
        + '<td><div class="dm-libro">' + esc(t.descripcion) + tagGrado(t.grado) + '</div><div class="dm-isbn">' + esc(t.codigo) + '</div>'
        + (sinLibro ? '<div class="dm-sub" style="color:#dc2626;"><i class="bi bi-exclamation-circle"></i> No está en el catálogo del CRM</div>' : '') + '</td>'
        + '<td><span class="dm-pill dm-pill-gris" style="white-space:normal;">' + esc(t.documentos) + '</span></td>'
        + '<td class="c"><span class="dm-num">' + t.base + '</span>' + (varios && t.pedidos.length > 1 ? '<div class="dm-num-sub">' + t.pedidos.length + ' pedidos</div>' : '') + '</td>'
        + '<td class="c"><span class="dm-num">' + t.devuelta + '</span><div class="dm-barra" title="' + pct + '% devuelto"><span style="width:' + pct + '%"></span></div></td>'
        + '<td class="c">' + (t.disponible > 0 ? '<span class="dm-pill dm-pill-verde">' + t.disponible + '</span>' : '<span class="dm-pill dm-pill-gris">0</span>') + reparto + '</td>'
        + '<td class="c">' + (editable
            ? '<div class="dm-stepper"><button type="button" class="dm-menos" tabindex="-1">−</button>'
              + '<input type="number" class="dm-input" name="cantidad[' + t.id_libro + ']" min="0" max="' + t.disponible + '" step="1" value="0" data-max="' + t.disponible + '" data-disp="' + t.disponible + '" data-libro="' + t.id_libro + '" data-titulo="' + esc(t.descripcion) + '">'
              + '<button type="button" class="dm-mas" tabindex="-1">+</button></div><span class="dm-max dm-tit-max">máx. ' + t.disponible + '</span>'
            : '<span class="dm-sub" style="justify-content:center;">' + (sinLibro ? 'No disponible' : 'Ya devuelto') + '</span>')
        + '</td></tr>';
    });
    h += '</tbody></table></div>';

    if (!hayDisponible) {
      h += aviso('ok', 'bi-check-circle', '<strong>Completado.</strong> No queda nada por devolver en ' + (varios ? 'estos pedidos' : 'este pedido')
        + (r.titulos.some(function (t) { return !t.id_libro; }) ? ' (o los títulos pendientes no están en el catálogo del CRM).' : '.')) + '</form>';
      $('#dm-detalle').html(h);
      return;
    }
    // Toda devolución es parcial: el pedido queda Completado cuando ya no le quedan unidades por devolver.
    h += '<div class="dm-campos"><div><label class="dm-dato-lbl" style="display:block;margin-bottom:6px;">Observaciones</label>'
      + '<textarea name="observaciones" class="form-control" rows="2" maxlength="1000" placeholder="Opcional: estado de los libros, quién entrega, etc."></textarea></div><div></div></div>'
      + '<div class="dm-pie"><div class="dm-total"><strong id="dm-total">0</strong><span id="dm-total-txt">unidades a devolver</span></div>'
      + '<button type="submit" class="dm-btn dm-btn-lg" id="dm-enviar" disabled><i class="bi bi-send"></i> Registrar devolución</button></div></form>';
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
    $('#dm-total-txt').text((total === 1 ? 'unidad' : 'unidades') + ' a devolver'
      + (titulos ? ' · ' + titulos + ' título' + (titulos === 1 ? '' : 's') : ''));
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
    if (total === 0) { inkToast('Ingresa al menos una cantidad a devolver.', 'warn'); return; }
    var n = $(form).find('input[name="claves[]"]').length, deQue = n > 1 ? 'de ' + n + ' pedidos de venta' : 'de este pedido de venta';
    var texto = 'Se registrará una devolución ' + (CORTE ? 'DE CORTE' : 'FÍSICA') + ' de ' + total + ' unidad(es) ' + deQue
      + '. Podrás seguir registrando devoluciones mientras queden unidades.';
    inkConfirm({ type: 'info', title: '¿Registrar la devolución?', text: texto, btnOk: 'Registrar' }, function () {
      $('#dm-enviar').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
      form.submit();
    });
  });

  $('#dm-buscar-wo').on('click', function () { if (clavesColegio.length) cargar(clavesColegio); });

  // Con ?id_pedido solo se carga ese pedido; si no, todos los del colegio elegido.
  aplicarColegio(<?= ($id_inicial > 0 && $colegio_inicial !== '') ? 'false' : '' ?>);
  <?php if ($id_inicial > 0 && $colegio_inicial !== ''): ?>
  cargar(['v<?= $id_inicial ?>']);
  <?php endif; ?>
})();
</script>
</body>
</html>
