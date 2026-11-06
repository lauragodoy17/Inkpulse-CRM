<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}
$es_admin = $tipo_sesion === 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Backorders</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <style>
    .bo-section {
      background: #fff; border-radius: 14px;
      box-shadow: 0 2px 10px rgba(15,23,42,.08);
      margin-bottom: 20px; overflow: hidden;
    }
    .bo-section-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px; border-bottom: 1px solid #e2e8f0;
    }
    .bo-num {
      width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; font-size: .85rem; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .bo-section-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .bo-section-body  { padding: 24px; }

    .bo-aviso {
      background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
      border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin: 0 0 18px;
    }
    .bo-aviso.warn { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

    .bo-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 26px; border-radius: 8px; font-size: .95rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer;
      transition: opacity .15s, transform .1s; text-decoration: none;
    }
    .bo-btn:hover { opacity: .9; transform: translateY(-1px); color: #fff; text-decoration: none; }
    .bo-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .bo-btn-stop { background: #fff; color: #dc2626; border: 1.5px solid #fecaca; }
    .bo-btn-stop:hover { background: #fef2f2; color: #dc2626; }
    .bo-btn-outline { background: #fff; color: #1d4ed8; border: 1.5px solid #1d4ed8; }
    .bo-btn-outline:hover { background: #eff6ff; color: #1d4ed8; }

    .bo-progreso-wrap { margin-top: 16px; background: #f1f5f9; border-radius: 8px; height: 8px; overflow: hidden; }
    .bo-progreso { height: 100%; background: linear-gradient(135deg, #1d4ed8, #2563eb); width: 0%; transition: width .2s; }
    .bo-progreso-txt { font-size: .78rem; color: #64748b; margin-top: 6px; }
    .bo-estado { font-size: .82rem; color: #64748b; margin-top: 10px; display: none; }
    .bo-estado.visible { display: block; }
    .bo-estado.error { color: #dc2626; }

    .bo-filtro { margin-bottom: 16px; max-width: 340px; }
    .bo-filtro input { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: .85rem; }

    #bo-usuario + .select2-container .select2-selection--single,
    #bo-cliente + .select2-container .select2-selection--single {
      height: 31px !important; border: 1px solid #ced4da !important; border-radius: 4px !important;
      font-size: .875rem; padding: 2px 4px;
    }
    #bo-usuario + .select2-container .select2-selection__rendered,
    #bo-cliente + .select2-container .select2-selection__rendered { line-height: 27px !important; }
    #bo-usuario + .select2-container .select2-selection__arrow,
    #bo-cliente + .select2-container .select2-selection__arrow { height: 29px !important; }

    table.bo-tabla { border-collapse: collapse; font-size: .8rem; white-space: nowrap; }
    table.bo-tabla th, table.bo-tabla td { border: 1px solid #e2e8f0; padding: 6px 10px; text-align: center; }
    table.bo-tabla thead th.bo-label { text-align: left; background: #eef2ff; color: #1e3a8a; font-weight: 700; position: sticky; left: 0; z-index: 2; }
    table.bo-tabla thead td { background: #f8fafc; font-weight: 600; }
    table.bo-tabla tbody th { text-align: left; position: sticky; left: 0; background: #fff; z-index: 1; font-weight: 500; }
    table.bo-tabla tbody td { font-variant-numeric: tabular-nums; }
    table.bo-tabla tbody td.bo-val { color: #b45309; font-weight: 700; background: #fffbeb; }
    table.bo-tabla td .bo-sub { display: block; font-size: .68rem; font-weight: 500; color: #94a3b8; white-space: nowrap; }
    table.bo-tabla tfoot td .bo-sub, table.bo-tabla td.bo-col-total .bo-sub { color: #64748b; }
    table.bo-tabla tfoot td, table.bo-tabla tfoot th { font-weight: 700; background: #f1f5f9; }
    table.bo-tabla th.bo-col-total, table.bo-tabla td.bo-col-total { background: #eef2ff; font-weight: 700; }
    table.bo-tabla td.bo-col-existencias, table.bo-tabla thead td.bo-col-existencias {
      background: #f8fafc; font-weight: 700; white-space: nowrap;
    }
    table.bo-tabla td.bo-col-isbn { background: #f8fafc; color: #475569; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .bo-existencia-bajo { color: #dc2626; font-weight: 700; }
    .bo-existencia-ok   { color: #15803d; font-weight: 700; }
    .bo-existencia-na   { color: #94a3b8; }
    .bo-pedido-link { color: #4361ee; text-decoration: none; font-weight: 600; }
    .bo-pedido-link:hover { text-decoration: underline; }
    .bo-vacio { color: #64748b; font-size: .85rem; padding: 20px 0; text-align: center; }

    .bo-sinverificar-item { border-bottom: 1px solid #f1f5f9; padding: 10px 0; font-size: .8rem; }
    .bo-sinverificar-item .concepto { color: #64748b; margin-top: 2px; }
    .bo-sinverificar-item .colegio-hint { color: #92400e; margin-top: 2px; }
    .bo-sinverificar-asignar { display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
    .bo-sinverificar-asignar .sv-select { min-width: 280px; }
    .bo-sinverificar-asignar .select2-container .select2-selection--single { font-size: .78rem; height: 32px; padding: 2px 4px; border-radius: 6px; border: 1px solid #e2e8f0; }
    .bo-sinverificar-asignar .select2-container .select2-selection--single .select2-selection__rendered { line-height: 28px; }
    .bo-sinverificar-asignar .select2-container .select2-selection--single .select2-selection__arrow { height: 30px; }
    .bo-sinverificar-asignar .resultado.ok { color: #15803d; }
    .bo-sinverificar-asignar .resultado.err { color: #dc2626; }

    /* ── Backorder general de libros (por título) ───────────────────── */
    table.bt-tabla { width: 100%; border-collapse: collapse; font-size: .84rem; }
    table.bt-tabla th, table.bt-tabla td { border: 1px solid #e2e8f0; padding: 8px 12px; }
    table.bt-tabla thead th {
      background: #eef2ff; color: #1e3a8a; font-weight: 700; text-align: left;
      position: sticky; top: 0; z-index: 1;
    }
    table.bt-tabla thead th.bt-num { text-align: center; }
    table.bt-tabla tbody td.bt-num { text-align: center; font-variant-numeric: tabular-nums; }
    tr.bt-fila-titulo { background: #f8fafc; }
    tr.bt-fila-titulo td { font-weight: 700; color: #0f172a; }
    tr.bt-fila-usuario td { color: #475569; }
    tr.bt-fila-usuario td.bt-usuario { padding-left: 30px; }
    tr.bt-fila-usuario td.bt-usuario::before { content: "↳ "; color: #cbd5e1; }

    .bt-badge-cump {
      display: inline-block; min-width: 54px; padding: 3px 10px; border-radius: 20px;
      font-size: .78rem; font-weight: 700; text-align: center;
    }
    .bt-cump-bajo  { background: #fee2e2; color: #991b1b; }
    .bt-cump-medio { background: #fef9c3; color: #854d0e; }
    .bt-cump-alto  { background: #dcfce7; color: #166534; }
    .bt-cump-na    { background: #f1f5f9; color: #64748b; }

    /* ── Cumplimiento de despachos por cliente ──────────────────────── */
    .bc-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .bc-kpi { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; background: #f8fafc; }
    .bc-kpi-label { font-size: .72rem; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
    .bc-kpi-valor { font-size: 1.35rem; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; }
    .bc-filtros { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; align-items: end; }
    .bc-filtros label { font-size: .75rem; color: #64748b; margin-bottom: 4px; }
    table.bt-tabla td.bc-cliente { min-width: 220px; }
    table.bt-tabla td .bo-sub { display: block; font-size: .7rem; color: #94a3b8; font-weight: 500; }
    .bc-barra-wrap { display: flex; align-items: center; gap: 8px; min-width: 190px; }
    .bc-barra { flex: 1; height: 10px; border-radius: 6px; background: #f1f5f9; overflow: hidden; }
    .bc-barra > div { height: 100%; border-radius: 6px; }
    .bc-barra .bt-cump-bajo  { background: #ef4444; }
    .bc-barra .bt-cump-medio { background: #eab308; }
    .bc-barra .bt-cump-alto  { background: #22c55e; }
    .bc-excedente { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 20px; background: #fef3c7; color: #92400e; font-size: .7rem; font-weight: 700; }
    table.bt-tabla thead th.bc-ordenable { cursor: pointer; user-select: none; }
    table.bt-tabla thead th.bc-ordenable:hover { background: #e0e7ff; }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Backorders</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item active" aria-current="page">Backorders</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <?php $numSeccion = 0; ?>
      <?php if ($es_admin): ?>
      <div class="bo-section">
        <div class="bo-section-head">
          <div class="bo-num"><?= ++$numSeccion ?></div>
          <p class="bo-section-title">Actualizar desde World Office</p>
        </div>
        <div class="bo-section-body">
          <button type="button" class="bo-btn" id="btn-iniciar"><i class="bi bi-arrow-repeat"></i> Actualizar desde World Office</button>
          <div class="bo-progreso-wrap"><div class="bo-progreso" id="bo-progreso"></div></div>
          <p class="bo-progreso-txt" id="bo-progreso-txt">Sin iniciar.</p>
          <div class="bo-estado" id="bo-estado"></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="bo-section">
        <div class="bo-section-body" style="padding:16px 24px; display:flex; align-items:end; gap:16px; flex-wrap:wrap;">
          <div>
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Período</label>
              <select id="bo-periodo" class="form-control form-control-sm" style="min-width:160px;"></select>
          </div>
          <p style="font-size:.8rem; color:#64748b; margin:0 0 6px;">El período aplica a todos los apartados del informe.</p>
        </div>
      </div>

      <div class="bo-section" id="seccion-clientes">
        <div class="bo-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="bo-num"><?= ++$numSeccion ?></div>
            <p class="bo-section-title">Cumplimiento de despachos por cliente</p>
          </div>
          <a href="php/backorders_clientes_excel.php" id="bc-link-excel" class="bo-btn" style="padding:8px 18px;">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
          </a>
        </div>
        <div class="bo-section-body">
          <!-- Tarjetas de indicadores ocultas (pedido por la usuaria 2026-10-29).
          <div class="bc-kpis">
            <div class="bc-kpi"><div class="bc-kpi-label">Clientes</div><div class="bc-kpi-valor" id="bc-kpi-clientes">—</div></div>
            <div class="bc-kpi"><div class="bc-kpi-label">Unidades solicitadas</div><div class="bc-kpi-valor" id="bc-kpi-solicitada">—</div></div>
            <div class="bc-kpi"><div class="bc-kpi-label">Remisionado / facturado</div><div class="bc-kpi-valor" id="bc-kpi-remisionada">—</div></div>
            <div class="bc-kpi"><div class="bc-kpi-label">Unidades pendientes</div><div class="bc-kpi-valor" id="bc-kpi-pendiente">—</div></div>
            <div class="bc-kpi"><div class="bc-kpi-label">Cumplimiento global</div><div class="bc-kpi-valor" id="bc-kpi-cumplimiento">—</div></div>
          </div>
          -->
          <div class="bc-filtros">
            <div class="bo-filtro" style="margin-bottom:0; flex:1; min-width:220px;">
              <label class="d-block">Cliente</label>
              <input type="text" id="bc-buscar" placeholder="Buscar por cliente...">
            </div>
            <div>
              <label class="d-block">Ordenar por</label>
              <select id="bc-orden" class="form-control form-control-sm" style="min-width:190px;">
                <option value="pct_asc">Menor % de cumplimiento</option>
                <option value="pct_desc">Mayor % de cumplimiento</option>
                <option value="pendiente_desc">Más unidades pendientes</option>
                <option value="cliente">Cliente (A-Z)</option>
              </select>
            </div>
          </div>
          <div class="table-responsive" style="max-height:72vh; overflow:auto;">
            <table class="bt-tabla" id="tabla-bc">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th class="bt-num" title="Pedidos con al menos un libro sin despachar completo — los mismos que salen en la Relación de pendientes">Pedidos pendientes</th>
                  <th class="bt-num">Solicitadas</th>
                  <th class="bt-num" title="Cantidades de las remisiones (REM) y facturas (FV) relacionadas">Remisionado / Facturado</th>
                  <th class="bt-num">Pendientes</th>
                  <th class="bc-ordenable" id="bc-th-pct" title="Ordenar por % de cumplimiento">% Cumplimiento <i class="bi bi-arrow-down-up"></i></th>
                </tr>
              </thead>
              <tbody id="bc-tbody"></tbody>
            </table>
          </div>
          <div class="bo-vacio" id="bc-vacio" style="display:none;">No hay clientes para mostrar.</div>
        </div>
      </div>

      <div class="bo-section" id="seccion-colegios">
        <div class="bo-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="bo-num"><?= ++$numSeccion ?></div>
            <p class="bo-section-title">Backorders por colegio</p>
          </div>
          <a href="php/backorders_colegios_excel.php" id="bcol-link-excel" class="bo-btn" style="padding:8px 18px;">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
          </a>
        </div>
        <div class="bo-section-body">
          <div class="bo-filtro" style="max-width:340px;">
            <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Buscar</label>
            <input type="text" id="bcol-buscar" placeholder="Buscar por colegio o cliente...">
          </div>
          <div class="table-responsive" style="max-height:72vh; overflow:auto;">
            <table class="bt-tabla" id="tabla-bcol">
              <thead>
                <tr>
                  <th>ISBN</th>
                  <th>Colegio / Libro</th>
                  <th class="bt-num">Solicitado</th>
                  <th class="bt-num" title="Cantidades de las remisiones (REM) y facturas (FV) de los pedidos del colegio">Remisionado / Facturado</th>
                  <th class="bt-num">Pendiente</th>
                  <th class="bt-num">% Cumplimiento</th>
                </tr>
              </thead>
              <tbody id="bcol-tbody"></tbody>
            </table>
          </div>
          <div class="bo-vacio" id="bcol-vacio" style="display:none;">No hay backorders pendientes para mostrar.</div>
        </div>
      </div>

      <div class="bo-section" id="seccion-titulos">
        <div class="bo-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="bo-num"><?= ++$numSeccion ?></div>
            <p class="bo-section-title">Backorder general de libros</p>
          </div>
          <a href="php/backorders_titulos_excel.php" id="bt-link-excel" class="bo-btn" style="padding:8px 18px;">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
          </a>
        </div>
        <div class="bo-section-body">
          <div class="bo-filtro" style="max-width:340px;">
            <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Buscar</label>
            <input type="text" id="bt-buscar" placeholder="Buscar por título, ISBN o usuario...">
          </div>
          <div class="table-responsive" style="max-height:72vh; overflow:auto;">
            <table class="bt-tabla" id="tabla-bt">
              <thead>
                <tr>
                  <th>ISBN</th>
                  <th>Título / Usuario</th>
                  <th class="bt-num" title="Existencia en bodega General de World Office">Existencias</th>
                  <th class="bt-num">Solicitado</th>
                  <th class="bt-num" title="Cantidades de las remisiones (REM) y facturas (FV) relacionadas">Remisionado / Facturado</th>
                  <th class="bt-num">Pendiente</th>
                  <th class="bt-num">% Cumplimiento</th>
                </tr>
              </thead>
              <tbody id="bt-tbody"></tbody>
            </table>
          </div>
          <div class="bo-vacio" id="bt-vacio" style="display:none;">No hay backorders pendientes para mostrar.</div>
        </div>
      </div>

      <div class="bo-section" id="bo-panel-sin-verificar">
        <div class="bo-section-head">
          <div class="bo-num"><?= ++$numSeccion ?></div>
          <p class="bo-section-title">Documentos pendientes de cruce o identificación</p>
        </div>
        <div class="bo-section-body">
          <div id="bo-lista-sin-verificar"></div>
        </div>
      </div>

      <div class="bo-section">
        <div class="bo-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="bo-num"><?= ++$numSeccion ?></div>
            <p class="bo-section-title">Detalle de backorders pendientes y trazabilidad de cantidades por atender</p>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="php/backorders_excel.php" id="bo-link-excel" class="bo-btn" style="padding:8px 18px;">
              <i class="bi bi-file-earmark-excel"></i> Descargar Excel
            </a>
          </div>
        </div>
        <div class="bo-section-body">
          <div id="bo-sin-sync" class="bo-aviso warn" style="display:none;"></div>
          <div id="bo-docs-anulados" class="bo-aviso warn" style="display:none;"></div>
          <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; align-items:end;">
            <div style="min-width:220px;">
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Usuario</label>
              <select id="bo-usuario" class="form-control form-control-sm" style="width:100%;">
                <option value="">Todos</option>
              </select>
            </div>
            <div style="min-width:240px;">
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Cliente (World Office)</label>
              <select id="bo-cliente" class="form-control form-control-sm" style="width:100%;">
                <option value="">Todos</option>
              </select>
            </div>
            <div class="bo-filtro" style="margin-bottom:0; flex:1;">
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Buscar</label>
              <input type="text" id="bo-buscar" placeholder="Buscar por colegio, cliente, # pedido, documento, título o ISBN...">
            </div>
          </div>
          <div class="table-responsive" style="max-height:70vh; overflow:auto;">
            <table class="bo-tabla" id="tabla-backorders">
              <thead></thead>
              <tbody></tbody>
              <tfoot></tfoot>
            </table>
          </div>
          <div class="bo-vacio" id="bo-vacio" style="display:none;">No hay backorders pendientes para mostrar.</div>
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
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
(function () {
  var esAdmin = <?= $es_admin ? 'true' : 'false' ?>;
  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function num(v) { v = parseFloat(v) || 0; return v % 1 === 0 ? v.toString() : v.toFixed(2); }
  function claseCumplimiento(pct) {
    if (pct < 50) return 'bt-cump-bajo';
    if (pct < 80) return 'bt-cump-medio';
    return 'bt-cump-alto';
  }
  function badgeCumplimiento(pct) {
    return '<span class="bt-badge-cump ' + claseCumplimiento(pct) + '">' + num(pct) + '%</span>';
  }
  // % de cumplimiento = despachado en World Office ÷ solicitado (sin documento asociado = 0%),
  // mismo criterio que porcentaje_cumplimiento_backorder() en includes/backorders_datos.php.
  function calcularCumplimiento(solicitada, despachada) {
    solicitada = parseFloat(solicitada) || 0;
    despachada = parseFloat(despachada) || 0;
    if (solicitada <= 0) return 100;
    return Math.min(100, Math.max(0, (despachada / solicitada) * 100));
  }

  // Existencia real en bodega General de World Office para ese título — mismo umbral de
  // "stock bajo" (rojo) que reporte_stock.php, para decidir de un vistazo qué tan urgente es.
  // Se usa en la Relación de pendientes y en el Backorder general de libros.
  function celdaExistencia(f) {
    if (f.existencia === null || f.existencia === undefined) return '<span class="bo-existencia-na">—</span>';
    var clase = f.stock_bajo ? 'bo-existencia-bajo' : 'bo-existencia-ok';
    return '<span class="' + clase + '">' + num(f.existencia) + '</span>';
  }

  var ultimaData = null;

  function render(data) {
    ultimaData = data;
    var columnas = data.columnas || [];
    var filas = data.filas || [];
    var $thead = $('#tabla-backorders thead');
    var $tbody = $('#tabla-backorders tbody');
    var $tfoot = $('#tabla-backorders tfoot');

    if (!columnas.length || !filas.length) {
      $thead.html(''); $tbody.html(''); $tfoot.html('');
      $('#bo-vacio').show();
    } else {
      $('#bo-vacio').hide();

      function filaHeader(etiqueta, valores, totalContent) {
        return '<tr><th class="bo-label">' + h(etiqueta) + '</th><td class="bo-col-isbn"></td><td class="bo-col-existencias"></td>' +
          valores.join('') + '<td class="bo-col-total">' + (totalContent || '') + '</td></tr>';
      }
      // Consecutivo BK: vacío mientras el backorder no tenga REM/FV confirmada en World Office.
      // Una columna por COLEGIO (todos sus pedidos del período). El colegio abre su detalle y cada
      // pedido su ficha.
      var urlColegio = function (c) { return 'reporte_backorders_pedido.php?id_colegio=' + c.id_colegio + '&id_periodo=' + c.id_periodo; };
      var consecutivos = columnas.map(function (c) { return '<td data-col="' + c.clave + '"' + (c.consecutivo ? ' style="font-weight:800; color:#1e3a8a;"' : '') + '>' + h(c.consecutivo || '') + '</td>'; });
      var docs = columnas.map(function (c) { return '<td data-col="' + c.clave + '">' + h(c.documento || '—') + '</td>'; });
      var clientes = columnas.map(function (c) { return '<td data-col="' + c.clave + '">' + h(c.cliente || '—') + '</td>'; });
      var colegios = columnas.map(function (c) { return '<td data-col="' + c.clave + '"><a class="bo-pedido-link" href="' + urlColegio(c) + '" target="_blank">' + h(c.colegio || '—') + '</a></td>'; });
      // Los pedidos nuevos generados para atender un backorder no salen aparte (van con su original);
      // "+N pedido(s) nuevo(s)" abre el detalle del colegio con su trazabilidad.
      var pedidos = columnas.map(function (c) {
        var generados = c.pedidos_generados || [];
        return '<td data-col="' + c.clave + '">' + (c.pedidos || []).map(function (id) {
            return '<a class="bo-pedido-link" href="pedido_colegio.php?id_pedido=' + id + '&tp=3" target="_blank">#' + id + '</a>';
          }).join(', ') +
          (generados.length
            ? '<span class="bo-sub"><a href="' + urlColegio(c) + '" target="_blank" title="Pedidos nuevos: #' + generados.join(', #') + '">+' + generados.length + ' pedido' + (generados.length === 1 ? '' : 's') + ' nuevo' + (generados.length === 1 ? '' : 's') + '</a></span>'
            : '') +
          '</td>';
      });
      var ops = columnas.map(function (c) {
        var ids = c.op_ids || [];
        var html = ids.length
          ? ids.map(function (id) { return '<a href="op_pendiente.php?op=' + encodeURIComponent(id) + '#pedidos-relacionados" target="_blank">' + h(id) + '</a>'; }).join(', ')
          : '—';
        return '<td data-col="' + c.clave + '">' + html + '</td>';
      });
      // % de cumplimiento POR PEDIDO (despachado ÷ solicitado de todo el pedido) — misma idea que
      // "Backorder general de libros" pero a nivel de pedido en vez de título, para priorizar aquí
      // mismo qué pedido urge más sin tener que bajar a esa otra sección.
      var cumplimientos = columnas.map(function (c) {
        return '<td data-col="' + c.clave + '">' + badgeCumplimiento(calcularCumplimiento(c.total_solicitada, c.total_despachada)) + '</td>';
      });
      var totalSolicitadaGeneral = columnas.reduce(function (s, c) { return s + (parseFloat(c.total_solicitada) || 0); }, 0);
      var totalDespachadaGeneral = columnas.reduce(function (s, c) { return s + (parseFloat(c.total_despachada) || 0); }, 0);

      $thead.html(
        filaHeader('CONSECUTIVO →', consecutivos) +
        filaHeader('DOCUMENTO →', docs) +
        filaHeader('CLIENTE →', clientes) +
        filaHeader('COLEGIO →', colegios) +
        filaHeader('# PEDIDO →', pedidos) +
        filaHeader('# OP →', ops) +
        filaHeader('% CUMPLIMIENTO →', cumplimientos, badgeCumplimiento(calcularCumplimiento(totalSolicitadaGeneral, totalDespachadaGeneral))) +
        '<tr><th class="bo-label">↓ TÍTULO ↓</th><td class="bo-col-isbn">ISBN</td><td class="bo-col-existencias">EXISTENCIAS</td>' +
        columnas.map(function () { return '<td></td>'; }).join('') +
        '<td class="bo-col-total">TOTAL</td></tr>'
      );

      // Cada celda muestra lo pendiente (grande, en negrita) y lo que se solicitó
      // originalmente en el pedido (chico, debajo) — antes solo se veía lo pendiente
      // y no había forma de saber si era poco porque el pedido era pequeño o porque
      // ya casi todo se había despachado.
      function celdaValor(pendiente, solicitada) {
        return num(pendiente) + '<span class="bo-sub">de ' + num(solicitada) + ' solicitado' + (solicitada == 1 ? '' : 's') + '</span>';
      }

      var filasHtml = filas.map(function (f) {
        var celdas = columnas.map(function (c) {
          var v = f.valores[c.clave];
          return v ? '<td class="bo-val" data-col="' + c.clave + '">' + celdaValor(v.pendiente, v.solicitada) + '</td>' : '<td data-col="' + c.clave + '"></td>';
        }).join('');
        // data-titulo incluye el ISBN para que el buscador también encuentre por ISBN.
        return '<tr data-titulo="' + h((f.titulo + ' ' + (f.isbn || '')).toLowerCase()) + '"><th>' + h(f.titulo) + '</th>' +
          '<td class="bo-col-isbn">' + h(f.isbn || '—') + '</td>' +
          '<td class="bo-col-existencias">' + celdaExistencia(f) + '</td>' +
          celdas + '<td class="bo-col-total">' + celdaValor(f.total, f.total_solicitada) + '</td></tr>';
      });
      $tbody.html(filasHtml.join(''));

      var totalGeneral = columnas.reduce(function (s, c) { return s + (parseFloat(c.total) || 0); }, 0);
      var totalGeneralSolicitada = columnas.reduce(function (s, c) { return s + (parseFloat(c.total_solicitada) || 0); }, 0);
      var totalesCols = columnas.map(function (c) { return '<td data-col="' + c.clave + '">' + celdaValor(c.total, c.total_solicitada) + '</td>'; }).join('');
      $tfoot.html('<tr><th class="bo-label">TOTAL</th><td class="bo-col-isbn"></td><td class="bo-col-existencias"></td>' + totalesCols + '<td class="bo-col-total">' + celdaValor(totalGeneral, totalGeneralSolicitada) + '</td></tr>');
    }

    var sinSync = data.sinSincronizar || [];
    if (sinSync.length) {
      $('#bo-sin-sync').show().text(
        sinSync.length + ' documento(s) referenciados por alguna orden de pedido aún no se han sincronizado desde World Office ' +
        '(' + sinSync.map(function (s) { return s.tipo + ' ' + s.numero; }).slice(0, 8).join(', ') + (sinSync.length > 8 ? '...' : '') + '). ' +
        'El backorder de esos pedidos puede estar sobreestimado hasta que se actualicen.'
      );
    } else {
      $('#bo-sin-sync').hide();
    }

    // REM/FV asociadas a una OP que World Office anuló: no cuentan como despacho, así que lo que
    // traían vuelve a quedar pendiente. Se avisa para revisar la OP (¿se reemplazó por otro documento?).
    var anulados = data.docsAnulados || [];
    if (anulados.length) {
      $('#bo-docs-anulados').show().html(
        '<strong>' + anulados.length + ' documento(s) anulado(s) en World Office</strong> están asociados a pedidos de este período ' +
        'y no se cuentan como despachados: ' +
        anulados.map(function (d) {
          return h(d.tipo + ' ' + d.numero) + ' (anulada)' +
            (d.ops.length ? ' (OP ' + d.ops.map(function (op) { return '<a href="op_pendiente.php?op=' + encodeURIComponent(op) + '" target="_blank">#' + h(op) + '</a>'; }).join(', ') + ')' : '') +
            ' — pedido ' + d.pedidos.map(function (p) { return '#' + h(p); }).join(', ');
        }).join('; ') +
        '. Revisa si la OP quedó con el documento que la reemplaza.'
      );
    } else {
      $('#bo-docs-anulados').hide();
    }
  }

  function renderSinVerificar(lista) {
    lista = lista || [];
    var $panel = $('#bo-panel-sin-verificar');
    var $lista = $('#bo-lista-sin-verificar');
    if (!lista.length) { $lista.html('<div class="bo-vacio">No hay documentos pendientes de cruce.</div>'); return; }

    $lista.html(lista.map(function (d) {
      return '<div class="bo-sinverificar-item" data-id-wo="' + h(d.id_wo) + '" data-tipo="' + h(d.tipo_documento) + '">' +
        '<strong>' + h(d.tipo_documento) + ' ' + h(d.numero) + '</strong> — ' + h(d.fecha) +
        (d.cliente ? ' — Cliente: ' + h(d.cliente) : '') +
        '<div class="concepto">Referencia en el concepto: ' + (d.ref_op ? 'OP ' + h(d.ref_op) : 'sin OP') + ' · ' + (d.ref_pedido ? 'PED ' + h(d.ref_pedido) : 'sin pedido') + '</div>' +
        (d.motivo ? '<div class="colegio-hint"><strong>Motivo:</strong> ' + h(d.motivo) + '</div>' : '') +
        (d.colegio_extraido ? '<div class="colegio-hint">Posible colegio (texto del concepto): ' + h(d.colegio_extraido) + '</div>' : '') +
        '<div class="concepto">' + h(d.concepto) + '</div>' +
        (esAdmin ? (
          '<div class="bo-sinverificar-asignar">' +
          '<select class="sv-select"><option></option></select>' +
          '<button type="button" class="bo-btn bo-btn-asignar-sv" style="padding:6px 16px; font-size:.8rem;">Asignar pedido</button>' +
          '<span class="resultado"></span>' +
          '</div>'
        ) : '') +
        '</div>';
    }).join(''));
    $panel.show();

    if (esAdmin) {
      $('.sv-select').select2({
        placeholder: 'Escribe para buscar un pedido por colegio, cliente o # de pedido...',
        allowClear: true,
        width: '100%',
        // minimumInputLength: 0 para que al abrir el desplegable ya traiga resultados (sin tener
        // que escribir primero) y siga permitiendo buscar escribiendo — pedido 2026-09-18.
        minimumInputLength: 0,
        ajax: {
          url: 'php/backorders_buscar_pedido.php',
          dataType: 'json',
          delay: 250,
          data: function (params) { return { q: params.term || '' }; },
          processResults: function (data) {
            return { results: (data || []).map(function (p) {
              return { id: p.id, text: '#' + p.id + ' — ' + p.colegio + (p.cliente ? ' — ' + p.cliente : '') };
            }) };
          }
        },
        language: {
          noResults: function () { return 'Sin resultados'; },
          searching: function () { return 'Buscando...'; }
        }
      });
    }
  }

  $('#bo-lista-sin-verificar').on('click', '.bo-btn-asignar-sv', function () {
    var $btn = $(this);
    var $item = $btn.closest('.bo-sinverificar-item');
    var $select = $item.find('.sv-select');
    var $resultado = $item.find('.resultado');
    var idWo = $item.data('id-wo');
    var tipoDocumento = $item.data('tipo');
    var idPedido = $select.val();
    if (!idPedido) { $resultado.attr('class', 'resultado err').text('Selecciona un pedido.'); return; }
    $btn.prop('disabled', true);
    $resultado.attr('class', 'resultado').text('Guardando...');
    $.post('php/backorders_wo_asignar_pedido.php', { id_wo: idWo, tipo_documento: tipoDocumento, id_pedido: idPedido })
      .done(function (resp) {
        if (resp && resp.success) {
          $resultado.attr('class', 'resultado ok').text('Asignado.');
          if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
          // A diferencia de la asignación por colegio (informativa), asignar un pedido SÍ cambia el
          // backorder de ese pedido — hay que recargar la tabla completa, no solo quitar la fila de
          // este panel.
          cargarTabla();
        } else {
          $btn.prop('disabled', false);
          $resultado.attr('class', 'resultado err').text((resp && resp.message) || 'Error al asignar.');
        }
      })
      .fail(function () {
        $btn.prop('disabled', false);
        $resultado.attr('class', 'resultado err').text('Error de red.');
      });
  });

  // Filtro "Cliente" (catálogo World Office, tabla `clientes`) — select2 con búsqueda AJAX porque
  // a diferencia de "Usuario" (unas pocas decenas, se cargan todas de una) la lista de clientes
  // puede crecer bastante, así que se busca por texto igual que en reporte_backorders_pedido.php.
  $('#bo-cliente').select2({
    placeholder: 'Todos',
    allowClear: true,
    width: '100%',
    // minimumInputLength: 0 para que al abrir el desplegable ya traiga resultados (sin tener que
    // escribir primero) y siga permitiendo buscar escribiendo — pedido por el usuario 2026-09-18.
    minimumInputLength: 0,
    ajax: {
      url: 'php/backorders_buscar_cliente.php',
      dataType: 'json',
      delay: 250,
      // Se manda el período seleccionado arriba para que el filtro solo traiga clientes con algo
      // pendiente DENTRO del rango de fechas de su calendario — pedido por el usuario 2026-09-18.
      data: function (params) { return { q: params.term || '', id_periodo: $('#bo-periodo').val() }; },
      processResults: function (data) {
        return { results: (data || []).map(function (c) { return { id: c.id, text: c.cliente }; }) };
      }
    },
    language: {
      noResults: function () { return 'Sin resultados'; },
      searching: function () { return 'Buscando...'; }
    }
  });
  $('#bo-cliente').on('select2:select select2:clear', function () { actualizarEnlaceExcel(); cargarTabla(); });

  function cargarTabla() {
    var idPeriodo = $('#bo-periodo').val();
    var idUsuario = $('#bo-usuario').val();
    var idCliente = $('#bo-cliente').val();
    var params = {};
    if (idPeriodo) params.id_periodo = idPeriodo;
    if (idUsuario) params.id_usuario = idUsuario;
    if (idCliente) params.id_cliente = idCliente;
    $.getJSON('php/backorders_tabla.php', params, function (resp) {
      if (!resp.success) return;
      render(resp);
      renderSinVerificar(resp.sinVerificar);

      if (!periodoInicializado) {
        periodoInicializado = true;
        var $sel = $('#bo-periodo');
        $sel.html((resp.periodos || []).map(function (p) {
          return '<option value="' + p.id + '">' + h(p.periodo) + ' (Calendario ' + h(p.calendario) + ')</option>';
        }).join(''));
        $sel.val(String(resp.idPeriodo || resp.periodoActivo || ''));
      }

      // La lista de usuarios depende del período (solo los que tienen pendiente en su rango de
      // fechas), así que se rearma en cada carga — antes se llenaba solo la primera vez y al
      // cambiar a otro período (p. ej. 2026B) seguían saliendo los usuarios del período inicial.
      // Se conserva el usuario elegido si sigue en la lista.
      var $selUsuario = $('#bo-usuario');
      var usuarios = resp.usuarios || {};
      var elegido = $selUsuario.val() || '';
      function opciones(lista) {
        return (lista || []).map(function (u) {
          return '<option value="' + u.id + '">' + h(u.nombres + ' ' + u.apellidos) + '</option>';
        }).join('');
      }
      function grupo(etiqueta, lista) {
        return (lista || []).length ? '<optgroup label="' + etiqueta + '">' + opciones(lista) + '</optgroup>' : '';
      }
      if ($selUsuario.hasClass('select2-hidden-accessible')) $selUsuario.select2('destroy');
      $selUsuario.html(
        '<option value="">Todos</option>' +
        grupo('Eureka', usuarios.promotores) +
        grupo('Distribuidores', usuarios.distribuidores) +
        grupo('Otros', usuarios.otros)
      );
      var elegidoSigue = elegido && $selUsuario.find('option[value="' + elegido + '"]').length;
      if (elegidoSigue) $selUsuario.val(elegido);
      // Select2 para poder escribir y buscar en vez de desplazarse por una lista larga
      // de usuarios (mismo plugin ya usado en el panel "Documentos sin verificar" de esta
      // misma página).
      $selUsuario.select2({
        placeholder: 'Todos',
        allowClear: true,
        width: '100%',
        language: {
          noResults: function () { return 'Sin resultados'; },
          searching: function () { return 'Buscando...'; }
        }
      });
      actualizarEnlaceExcel();
      // El usuario elegido no tiene pendiente en el nuevo período: se vuelve a "Todos" y se recarga
      // la tabla para que no quede filtrada por un usuario que ya no aparece en el selector.
      if (elegido && !elegidoSigue) cargarTabla();
    });
  }
  var periodoInicializado = false;
  function actualizarEnlaceExcel() {
    var idPeriodo = $('#bo-periodo').val();
    var idUsuario = $('#bo-usuario').val();
    var idCliente = $('#bo-cliente').val();
    var qs = [];
    if (idPeriodo) qs.push('id_periodo=' + encodeURIComponent(idPeriodo));
    if (idUsuario) qs.push('id_usuario=' + encodeURIComponent(idUsuario));
    if (idCliente) qs.push('id_cliente=' + encodeURIComponent(idCliente));
    $('#bo-link-excel').attr('href', 'php/backorders_excel.php' + (qs.length ? '?' + qs.join('&') : ''));
  }
  cargarTabla();
  $('#bo-periodo, #bo-usuario').on('change', function () { actualizarEnlaceExcel(); cargarTabla(); });

  $('#bo-buscar').on('input', function () {
    var q = this.value.trim().toLowerCase();
    if (!q || !ultimaData) {
      $('#tabla-backorders [data-col]').show();
      $('#tabla-backorders tbody tr').show();
      return;
    }
    var columnasVisibles = {};
    (ultimaData.columnas || []).forEach(function (c) {
      var texto = ((c.consecutivo || '') + ' ' + c.documento + ' ' + c.cliente + ' ' + c.colegio + ' ' + c.pedidos_txt + ' ' + c.op).toLowerCase();
      columnasVisibles[c.clave] = texto.indexOf(q) !== -1;
    });
    $('#tabla-backorders [data-col]').each(function () {
      var visible = columnasVisibles[$(this).data('col')];
      $(this).toggle(!!visible);
    });
    $('#tabla-backorders tbody tr').each(function () {
      var $tr = $(this);
      var matchTitulo = ($tr.data('titulo') || '').toString().indexOf(q) !== -1;
      $tr.toggle(matchTitulo || Object.keys(columnasVisibles).some(function (k) { return columnasVisibles[k]; }));
    });
  });

  // ── Backorder general de libros (por título) — usa el mismo #bo-periodo de arriba ──
  var ultimosTitulos = [];

  function renderTitulos(titulos) {
    ultimosTitulos = titulos || [];
    var $tbody = $('#bt-tbody');
    if (!ultimosTitulos.length) {
      $tbody.html('');
      $('#bt-vacio').show();
      return;
    }
    $('#bt-vacio').hide();

    var filas = ultimosTitulos.map(function (t) {
      var filaTitulo = '<tr class="bt-fila-titulo" data-titulo="' + h(t.titulo.toLowerCase()) + '">' +
        '<td style="white-space:nowrap;">' + h(t.isbn || '—') + '</td>' +
        '<td>' + h(t.titulo) + '</td>' +
        '<td class="bt-num">' + celdaExistencia(t) + '</td>' +
        '<td class="bt-num">' + num(t.solicitada) + '</td>' +
        '<td class="bt-num">' + num(t.remisionada) + '</td>' +
        '<td class="bt-num">' + num(t.pendiente) + '</td>' +
        '<td class="bt-num">' + badgeCumplimiento(t.cumplimiento) + '</td>' +
        '</tr>';
      var filasUsuario = (t.usuarios || []).map(function (u) {
        return '<tr class="bt-fila-usuario" data-titulo="' + h(t.titulo.toLowerCase()) + '" data-usuario="' + h((u.usuario || '').toLowerCase()) + '">' +
          '<td></td>' +
          '<td class="bt-usuario">' + h(u.usuario || '—') + '</td>' +
          '<td class="bt-num"></td>' +
          '<td class="bt-num">' + num(u.solicitada) + '</td>' +
          '<td class="bt-num">' + num(u.remisionada) + '</td>' +
          '<td class="bt-num">' + num(u.pendiente) + '</td>' +
          '<td class="bt-num">' + badgeCumplimiento(u.cumplimiento) + '</td>' +
          '</tr>';
      }).join('');
      return filaTitulo + filasUsuario;
    });
    $('#bt-tbody').html(filas.join(''));
  }

  // ── Backorders por colegio: los pedidos del colegio sumados y lo devuelto (devoluciones de
  // remisión y notas crédito) restado del total del colegio. ──────────────────────────────────
  var ultimosColegios = [];
  function renderColegios() {
    var q = $('#bcol-buscar').val().trim().toLowerCase();
    var lista = ultimosColegios.filter(function (c) {
      return !q || (c.colegio + ' ' + (c.cliente || '')).toLowerCase().indexOf(q) !== -1;
    });
    actualizarEnlaceExcelColegios();
    if (!lista.length) { $('#bcol-tbody').html(''); $('#bcol-vacio').show(); return; }
    $('#bcol-vacio').hide();
    $('#bcol-tbody').html(lista.map(function (c) {
      var pedidos = (c.pedidos || []).map(function (p) {
        return '<a href="pedido_colegio.php?id_pedido=' + p + '&tp=3" target="_blank">#' + p + '</a>';
      }).join(', ');
      var fila = '<tr class="bt-fila-titulo">' +
        '<td></td>' +
        '<td><a href="reporte_backorders_pedido.php?id_colegio=' + c.id_colegio + '&id_periodo=' + c.id_periodo + '" target="_blank"><strong>' + h(c.colegio) + '</strong></a>' +
          (c.cliente ? '<span class="bo-sub">' + h(c.cliente) + '</span>' : '') +
          '<span class="bo-sub">Pedidos: ' + (pedidos || '—') + '</span>' +
        '</td>' +
        '<td class="bt-num">' + num(c.solicitada) + '</td>' +
        '<td class="bt-num">' + num(c.remisionada) + '</td>' +
        '<td class="bt-num">' + num(c.pendiente) + '</td>' +
        '<td class="bt-num">' + (c.cumplimiento === null ? '<span class="bt-badge-cump bt-cump-na">N/A</span>' : badgeCumplimiento(c.cumplimiento)) + '</td>' +
        '</tr>';
      var libros = (c.libros || []).map(function (l) {
        return '<tr class="bt-fila-usuario">' +
          '<td style="white-space:nowrap;">' + h(l.isbn || '—') + '</td>' +
          '<td class="bt-usuario">' + h(l.libro) + '</td>' +
          '<td class="bt-num">' + num(l.solicitada) + '</td>' +
          '<td class="bt-num">' + num(l.remisionada) + '</td>' +
          '<td class="bt-num">' + num(l.pendiente) + '</td>' +
          '<td class="bt-num">' + badgeCumplimiento(l.cumplimiento) + '</td>' +
          '</tr>';
      }).join('');
      return fila + libros;
    }).join(''));
  }
  function actualizarEnlaceExcelColegios() {
    var qs = [];
    var idPeriodo = $('#bo-periodo').val();
    var q = $('#bcol-buscar').val().trim();
    if (idPeriodo) qs.push('id_periodo=' + encodeURIComponent(idPeriodo));
    if (q) qs.push('buscar=' + encodeURIComponent(q));
    $('#bcol-link-excel').attr('href', 'php/backorders_colegios_excel.php' + (qs.length ? '?' + qs.join('&') : ''));
  }
  function cargarTablaColegios() {
    var idPeriodo = $('#bo-periodo').val();
    $.getJSON('php/backorders_colegios_tabla.php', idPeriodo ? { id_periodo: idPeriodo } : {}, function (resp) {
      if (!resp.success) return;
      ultimosColegios = resp.colegios || [];
      renderColegios();
    });
  }
  cargarTablaColegios();
  $('#bo-periodo').on('change', cargarTablaColegios);
  $('#bcol-buscar').on('input', renderColegios);

  function cargarTablaTitulos() {
    var idPeriodo = $('#bo-periodo').val();
    var params = idPeriodo ? { id_periodo: idPeriodo } : {};
    $.getJSON('php/backorders_titulos_tabla.php', params, function (resp) {
      if (!resp.success) return;
      renderTitulos(resp.titulos);
      actualizarEnlaceExcelTitulos();
    });
  }
  function actualizarEnlaceExcelTitulos() {
    var idPeriodo = $('#bo-periodo').val();
    $('#bt-link-excel').attr('href', 'php/backorders_titulos_excel.php' + (idPeriodo ? '?id_periodo=' + encodeURIComponent(idPeriodo) : ''));
  }
  cargarTablaTitulos();
  $('#bo-periodo').on('change', cargarTablaTitulos);

  $('#bt-buscar').on('input', function () {
    var q = this.value.trim().toLowerCase();
    if (!q) { $('#tabla-bt tbody tr').show(); return; }
    // Un título coincide si su propio nombre matchea, o si alguno de sus usuarios matchea —
    // en ese caso se muestran el título (para dar contexto) y solo los usuarios que matchean.
    // Coincidir por ISBN cuenta igual que por nombre del título (se muestran todos sus usuarios).
    var titulosCoincidentes = {};
    var titulosPropioMatch = {};
    ultimosTitulos.forEach(function (t) {
      var tituloMatch = t.titulo.toLowerCase().indexOf(q) !== -1 || (t.isbn || '').toLowerCase().indexOf(q) !== -1;
      var usuarioMatch = (t.usuarios || []).some(function (u) { return (u.usuario || '').toLowerCase().indexOf(q) !== -1; });
      if (tituloMatch || usuarioMatch) titulosCoincidentes[t.titulo.toLowerCase()] = true;
      if (tituloMatch) titulosPropioMatch[t.titulo.toLowerCase()] = true;
    });
    $('#tabla-bt tbody tr').each(function () {
      var $tr = $(this);
      var titulo = $tr.data('titulo');
      if (!titulosCoincidentes[titulo]) { $tr.hide(); return; }
      if ($tr.hasClass('bt-fila-titulo')) { $tr.show(); return; }
      var usuario = ($tr.data('usuario') || '').toString();
      $tr.toggle(!!titulosPropioMatch[titulo] || usuario.indexOf(q) !== -1);
    });
  });

  // ── Cumplimiento de despachos por cliente — usa el mismo #bo-periodo de arriba ──
  var ultimosClientes = [];

  function celdaCumplimientoCliente(c) {
    if (c.cumplimiento === null) return '<span class="bt-badge-cump bt-cump-na" title="El cliente no tiene unidades solicitadas">N/A</span>';
    var clase = claseCumplimiento(c.cumplimiento);
    return '<div class="bc-barra-wrap"><div class="bc-barra"><div class="' + clase + '" style="width:' + Math.min(100, c.cumplimiento) + '%"></div></div>' +
      '<span class="bt-badge-cump ' + clase + '">' + num(c.cumplimiento) + '%</span></div>';
  }

  function renderClientes() {
    var q = $('#bc-buscar').val().trim().toLowerCase();
    var orden = $('#bc-orden').val();

    // Solo clientes con algo por despachar: uno ya 100% despachado no tiene backorder.
    var lista = ultimosClientes.filter(function (c) {
      return c.pendiente > 0 && (!q || c.cliente.toLowerCase().indexOf(q) !== -1);
    });
    actualizarEnlaceExcelClientes();

    // N/A siempre al final, sin importar el sentido del orden por %.
    function pct(c, siNulo) { return c.cumplimiento === null ? siNulo : c.cumplimiento; }
    lista.sort(function (a, b) {
      if (orden === 'pct_desc') return (pct(b, -1) - pct(a, -1)) || (b.pendiente - a.pendiente);
      if (orden === 'pendiente_desc') return (b.pendiente - a.pendiente) || a.cliente.localeCompare(b.cliente);
      if (orden === 'cliente') return a.cliente.localeCompare(b.cliente);
      return (pct(a, 1000) - pct(b, 1000)) || (b.pendiente - a.pendiente);
    });

    // Indicadores sobre los clientes filtrados, también consolidados (suma de unidades, no promedio).
    var tot = lista.reduce(function (s, c) {
      s.solicitada += c.solicitada; s.despachada += c.despachada; s.pendiente += c.pendiente;
      s.remisionada += c.remisionada;
      return s;
    }, { solicitada: 0, despachada: 0, pendiente: 0, remisionada: 0 });
    $('#bc-kpi-clientes').text(lista.length);
    $('#bc-kpi-solicitada').text(num(tot.solicitada));
    $('#bc-kpi-remisionada').text(num(tot.remisionada));
    $('#bc-kpi-pendiente').text(num(tot.pendiente));
    $('#bc-kpi-cumplimiento').html(tot.solicitada > 0
      ? badgeCumplimiento(Math.round(tot.despachada / tot.solicitada * 1000) / 10)
      : '<span class="bt-badge-cump bt-cump-na">N/A</span>');

    if (!lista.length) { $('#bc-tbody').html(''); $('#bc-vacio').show(); return; }
    $('#bc-vacio').hide();

    $('#bc-tbody').html(lista.map(function (c) {
      var excedente = c.excedente > 0
        ? '<br><span class="bc-excedente" title="Unidades despachadas por encima de lo solicitado (no cuentan en el %). Revisar.">+' + num(c.excedente) + ' excedente</span>'
        : '';
      return '<tr>' +
        '<td class="bc-cliente"><strong>' + h(c.cliente) + '</strong>' +
          (c.sin_cliente_wo ? '<span class="bo-sub">Colegio (pedido sin cliente World Office)</span>' : '') +
        '</td>' +
        '<td class="bt-num">' + c.pedidos_con_pendiente + '</td>' +
        '<td class="bt-num">' + num(c.solicitada) + '</td>' +
        '<td class="bt-num">' + num(c.remisionada) + excedente + '</td>' +
        '<td class="bt-num">' + num(c.pendiente) + '</td>' +
        '<td>' + celdaCumplimientoCliente(c) + '</td>' +
        '</tr>';
    }).join(''));
  }

  // El Excel sale con los mismos filtros y orden que se ven en pantalla.
  function actualizarEnlaceExcelClientes() {
    var qs = [];
    var idPeriodo = $('#bo-periodo').val();
    if (idPeriodo) qs.push('id_periodo=' + encodeURIComponent(idPeriodo));
    var buscar = $('#bc-buscar').val().trim();
    if (buscar) qs.push('buscar=' + encodeURIComponent(buscar));
    qs.push('orden=' + encodeURIComponent($('#bc-orden').val()));
    $('#bc-link-excel').attr('href', 'php/backorders_clientes_excel.php?' + qs.join('&'));
  }

  function cargarTablaClientes() {
    var idPeriodo = $('#bo-periodo').val();
    var params = idPeriodo ? { id_periodo: idPeriodo } : {};
    $.getJSON('php/backorders_clientes_tabla.php', params, function (resp) {
      if (!resp.success) return;
      ultimosClientes = resp.clientes || [];
      renderClientes();
    });
  }
  cargarTablaClientes();
  $('#bo-periodo').on('change', cargarTablaClientes);
  $('#bc-buscar').on('input', renderClientes);
  $('#bc-orden').on('change', renderClientes);
  $('#bc-th-pct').on('click', function () {
    $('#bc-orden').val($('#bc-orden').val() === 'pct_asc' ? 'pct_desc' : 'pct_asc');
    renderClientes();
  });

  <?php if ($es_admin): ?>
  // ── Actualizar desde World Office: una llamada por tipo (php/sync_backorders_wo_rapido.php trae el
  // listado completo y solo descarga lo nuevo/modificado); si quedan documentos por traer
  // ("faltan"), se vuelve a llamar. Las tablas se recargan apenas termina cada tipo.
  // DEV = devoluciones de remisión y notas crédito (restan de lo despachado).
  var TIPOS = ['REM', 'FV', 'DEV'];
  var NOMBRES = { REM: 'remisiones', FV: 'facturas', DEV: 'devoluciones y notas crédito' };
  var btnIniciar = document.getElementById('btn-iniciar');
  var progreso = document.getElementById('bo-progreso');
  var progresoTxt = document.getElementById('bo-progreso-txt');
  var estado = document.getElementById('bo-estado');
  var total = null;

  function mostrarError(msg) { estado.textContent = msg; estado.className = 'bo-estado visible error'; }
  function recargarTodo() { cargarTabla(); cargarTablaTitulos(); cargarTablaClientes(); cargarTablaColegios(); }

  function sumar(r) {
    ['documentos', 'nuevos', 'modificados', 'anulados', 'eliminados', 'errores'].forEach(function (k) {
      total[k] = (total[k] || 0) + (r[k] || 0);
    });
  }
  function textoResumen() {
    return total.documentos + ' documentos revisados — ' + total.nuevos + ' nuevos, ' + total.modificados + ' modificados, ' +
      total.anulados + ' anulados, ' + total.eliminados + ' eliminados' + (total.errores ? ', ' + total.errores + ' con error (se reintentan la próxima vez)' : '');
  }

  function sincronizarTipo(idx, intento) {
    if (idx >= TIPOS.length) {
      btnIniciar.disabled = false;
      progreso.style.width = '100%';
      progresoTxt.textContent = 'Actualización completa — ' + textoResumen() + '.';
      return;
    }
    var tipo = TIPOS[idx];
    progresoTxt.textContent = 'Revisando ' + NOMBRES[tipo] + ' en World Office...';
    fetch('php/sync_backorders_wo_rapido.php?tipoDocumento=' + tipo)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) { mostrarError('Error: ' + (data.message || 'desconocido')); btnIniciar.disabled = false; return; }
        var r = data.resumen || {};
        // Al reintentar por "faltan" no se vuelven a contar los documentos revisados.
        if (intento > 0) r.documentos = 0;
        sumar(r);
        var hubo = r.nuevos || r.modificados || r.anulados || r.eliminados;
        if (hubo) recargarTodo(); // los cambios se ven de una vez
        if (r.faltan > 0 && intento < 20) {
          progresoTxt.textContent = tipo + ': faltan ' + r.faltan + ' documento(s) por descargar...';
          sincronizarTipo(idx, intento + 1);
          return;
        }
        progreso.style.width = Math.round(((idx + 1) / TIPOS.length) * 100) + '%';
        sincronizarTipo(idx + 1, 0);
      })
      .catch(function (err) { mostrarError('Error de red: ' + err.message); btnIniciar.disabled = false; });
  }

  btnIniciar.addEventListener('click', function () {
    total = {};
    estado.className = 'bo-estado';
    progreso.style.width = '0%';
    btnIniciar.disabled = true;
    sincronizarTipo(0, 0);
  });
  <?php endif; ?>
})();
</script>
</body>
</html>
