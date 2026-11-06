<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}

require_once("conexion/bdd.php");
require_once("includes/backorders_datos.php");

// El buscador trae siempre los pedidos de 2026B y 2027 (BACKORDERS_PERIODOS_REPORTE), sin selector
// de período — pedido por la usuaria 2026-10-20 (antes 2026, 2026B y 2027).
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Backorders por pedido</title>
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
    .bp-section {
      background: #fff; border-radius: 14px;
      box-shadow: 0 2px 10px rgba(15,23,42,.08);
      margin-bottom: 20px; overflow: hidden;
    }
    .bp-section-head {
      display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
      padding: 18px 24px; border-bottom: 1px solid #e2e8f0;
    }
    .bp-section-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .bp-section-body { padding: 24px; }

    .bp-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 18px; border-radius: 8px; font-size: .85rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer; text-decoration: none;
      transition: opacity .15s;
    }
    .bp-btn:hover { opacity: .9; color: #fff; text-decoration: none; }
    .bp-btn-outline { background: #fff; color: #1d4ed8; border: 1.5px solid #1d4ed8; }

    #bp-select-pedido { width: 100%; }
    .select2-container .select2-selection--single { height: 38px !important; border: 1px solid #ced4da !important; }
    .select2-container .select2-selection__rendered { line-height: 36px !important; font-size: .9rem; }
    .select2-container .select2-selection__arrow { height: 36px !important; }

    .bp-info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 18px; }
    @media (max-width: 860px) { .bp-info-grid { grid-template-columns: 1fr 1fr; } }
    .bp-info-item { background: #f8fafc; border-radius: 10px; padding: 12px 16px; }
    .bp-info-label { font-size: .68rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
    .bp-info-val { font-size: .88rem; font-weight: 600; color: #0f172a; }

    .bp-resumen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 16px; margin-bottom: 20px; }
    @media (max-width: 860px) { .bp-resumen-grid { grid-template-columns: 1fr 1fr; } }
    .bp-resumen-card { border-radius: 10px; padding: 14px 16px; text-align: center; }
    .bp-resumen-card .num { font-size: 1.4rem; font-weight: 800; }
    .bp-resumen-card .lbl { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #64748b; margin-top: 2px; }
    .bp-card-solicitado { background: #eff6ff; } .bp-card-solicitado .num { color: #1d4ed8; }
    .bp-card-pendiente  { background: #fffbeb; } .bp-card-pendiente .num  { color: #b45309; }
    .bp-card-cumpl      { background: #f8fafc; }
    .bp-card-generado   { background: #f5f3ff; } .bp-card-generado .num { color: #6d28d9; }
    .bp-card-devuelto   { background: #fef2f2; } .bp-card-devuelto .num { color: #b91c1c; }
    .bp-nota-colegio { font-size: .8rem; color: #64748b; margin: -6px 0 14px; }
    .bp-hist-anulado td { color: #94a3b8; text-decoration: line-through; }
    .bp-hist-anulado td.bp-hist-estado { text-decoration: none; }
    .bp-hist-libros { margin: 0; padding-left: 18px; }
    .bp-consecutivo { display: inline-block; padding: 2px 10px; border-radius: 6px; background: #eef2ff; color: #1e3a8a; font-weight: 800; letter-spacing: .02em; }
    .bp-chip-sync { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .72rem; font-weight: 700; background: #fff7ed; color: #c2410c; }
    .bp-chip-ok { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .72rem; font-weight: 700; background: #dcfce7; color: #166534; }
    .bp-paquete { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
    .bp-paquete-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
    .bp-paquete-nombre { font-weight: 700; color: #1e293b; }
    .bp-paquete-codigo { color: #64748b; font-size: .8rem; margin-left: 6px; }
    .bp-paquete-pend { margin: 0 0 8px; padding-left: 18px; color: #9a3412; }
    .bp-paquete-pend strong { color: #7c2d12; }
    .bp-excedente { display: block; font-size: .72rem; font-weight: 700; color: #92400e; }

    table.bp-tabla { width: 100%; border-collapse: collapse; font-size: .84rem; }
    table.bp-tabla th, table.bp-tabla td { border: 1px solid #e2e8f0; padding: 8px 12px; }
    table.bp-tabla thead th { background: #eef2ff; color: #1e3a8a; font-weight: 700; text-align: left; }
    table.bp-tabla thead th.bp-num { text-align: center; }
    table.bp-tabla tbody td.bp-num { text-align: center; font-variant-numeric: tabular-nums; }

    .bp-badge-cump {
      display: inline-block; min-width: 54px; padding: 3px 10px; border-radius: 20px;
      font-size: .78rem; font-weight: 700; text-align: center;
    }
    .bp-cump-bajo  { background: #fee2e2; color: #991b1b; }
    .bp-cump-medio { background: #fef9c3; color: #854d0e; }
    .bp-cump-alto  { background: #dcfce7; color: #166534; }

    .bp-vacio { color: #64748b; font-size: .9rem; padding: 40px 0; text-align: center; }
    .bp-vacio i { display: block; font-size: 2rem; margin-bottom: 10px; color: #cbd5e1; }
    .bp-aviso {
      background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
      border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin: 0 0 18px;
    }
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
            <div class="title"><h4>Backorders por pedido</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="reporte_backorders.php">Backorders</a></li>
                <li class="breadcrumb-item active" aria-current="page">Por pedido</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="bp-section">
        <div class="bp-section-head">
          <p class="bp-section-title">Buscar un colegio</p>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="reporte_backorders.php" class="bp-btn bp-btn-outline"><i class="bi bi-grid"></i> Ver relación de pendientes</a>
            <a href="reporte_backorders.php#seccion-titulos" class="bp-btn bp-btn-outline"><i class="bi bi-sort-down"></i> Backorder general de libros</a>
          </div>
        </div>
        <div class="bp-section-body">
          <select id="bp-select-pedido" style="width:100%;">
            <option></option>
          </select>
        </div>
      </div>

      <div class="bp-section" id="bp-resultado" style="display:none;">
        <div class="bp-section-head">
          <p class="bp-section-title">Colegio: <span id="bp-titulo-colegio"></span> <span id="bp-titulo-periodo" style="font-weight:600; color:#64748b;"></span></p>
          <button type="button" id="bp-btn-completado" class="bp-btn bp-btn-outline" title="Saca este colegio del módulo de backorders aunque se deban unidades">
            <i class="bi bi-check2-circle"></i> Marcar como completado
          </button>
        </div>
        <div class="bp-section-body">
          <div id="bp-aviso-generado" class="bp-aviso" style="display:none; background:#f5f3ff; border-color:#ddd6fe; color:#5b21b6;">
            Este pedido fue generado para atender el backorder del pedido
            <a href="#" id="bp-aviso-generado-link" style="font-weight:700;">#<span id="bp-aviso-generado-id"></span></a>.
            Su cantidad ya está descontada del saldo pendiente de ese pedido original — el detalle
            completo (solicitado, despachado, pedidos generados y pendiente) se consulta desde allá.
          </div>
          <div id="bp-aviso-premigracion" class="bp-aviso" style="display:none;">
            Este pedido tiene una OP Atendida de antes de la migración a World Office (01/07/2026) —
            no hay traza confiable para calcular cuánto se despachó realmente. Revisar a mano.
          </div>

          <div class="bp-info-grid">
            <div class="bp-info-item"><div class="bp-info-label">Consecutivo(s) backorder</div><div class="bp-info-val" id="bp-consecutivo">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Colegio</div><div class="bp-info-val" id="bp-colegio">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Cliente</div><div class="bp-info-val" id="bp-cliente">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Responsable</div><div class="bp-info-val" id="bp-responsable">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Período</div><div class="bp-info-val" id="bp-periodo">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Fecha de generación del backorder</div><div class="bp-info-val" id="bp-fecha">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Documento(s)</div><div class="bp-info-val" id="bp-documento">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">OP</div><div class="bp-info-val" id="bp-op">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Pedidos del colegio</div><div class="bp-info-val" id="bp-pedidos-colegio">—</div></div>
            <div class="bp-info-item"><div class="bp-info-label">Devoluciones / notas crédito</div><div class="bp-info-val" id="bp-devoluciones">—</div></div>
          </div>
          <p class="bp-nota-colegio" id="bp-nota-colegio">Cantidades del colegio: todos sus pedidos del período, con las devoluciones de remisión y notas crédito restadas al colegio.</p>

          <!-- Tarjetas de totales ocultas (pedido por la usuaria 2026-10-29).
          <div class="bp-resumen-grid">
            <div class="bp-resumen-card bp-card-solicitado"><div class="num" id="bp-total-solicitado">0</div><div class="lbl">Solicitado</div></div>
            <div class="bp-resumen-card bp-card-devuelto" title="Devoluciones de remisión y notas crédito del colegio."><div class="num" id="bp-total-devuelto">0</div><div class="lbl">Devuelto</div></div>
            <div class="bp-resumen-card bp-card-generado" title="Unidades de las REM/FV de World Office de los pedidos nuevos (OP del original + PED del pedido nuevo)."><div class="num" id="bp-total-generado">0</div><div class="lbl">En pedidos nuevos (WO)</div></div>
            <div class="bp-resumen-card bp-card-pendiente" title="Solicitado − (remisionado / facturado − devuelto)."><div class="num" id="bp-total-pendiente">0</div><div class="lbl">Pendiente de despacho</div></div>
            <div class="bp-resumen-card bp-card-cumpl" title="(Remisionado / facturado − devuelto) ÷ solicitado (máximo 100 %; lo despachado de más se muestra como excedente)."><div class="num" id="bp-total-cumplimiento">—</div><div class="lbl">% Cumplimiento</div></div>
          </div>
          -->

          <div class="table-responsive">
            <table class="bp-tabla">
              <thead>
                <tr>
                  <th>ISBN</th>
                  <th>Título</th>
                  <th class="bp-num">Solicitado</th>
                  <th class="bp-num" title="Cantidades de las remisiones (REM) y facturas (FV) de los pedidos del colegio">Remisionado / Facturado</th>
                  <th class="bp-num" title="Devoluciones de remisión y notas crédito del colegio">Devuelto</th>
                  <th class="bp-num" title="Parte de lo remisionado / facturado que viene de las REM/FV de los pedidos nuevos">En pedidos nuevos (WO)</th>
                  <th class="bp-num">Pendiente de despacho</th>
                  <th class="bp-num" title="(Remisionado / facturado − devuelto) ÷ solicitado">% Cumplimiento</th>
                </tr>
              </thead>
              <tbody id="bp-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="bp-section" id="bp-paquetes-wrap" style="display:none;">
        <div class="bp-section-head">
          <p class="bp-section-title"><i class="bi bi-boxes"></i> Backorder por paquete</p>
        </div>
        <div class="bp-section-body">
          <p class="bp-nota-colegio">Un paquete en la REM/FV de World Office (mismo código del CRM) cuenta como una unidad de cada uno de sus libros. El pendiente de cada libro es el del colegio (todos sus pedidos, con devoluciones).</p>
          <div id="bp-paquetes"></div>
        </div>
      </div>

      <div class="bp-section" id="bp-historial-wrap" style="display:none;">
        <div class="bp-section-head">
          <p class="bp-section-title"><i class="bi bi-clock-history"></i> Pedidos generados para este backorder</p>
        </div>
        <div class="bp-section-body">
          <div class="table-responsive">
            <table class="bp-tabla">
              <thead>
                <tr>
                  <th>Backorder</th>
                  <th>Pedido nuevo</th>
                  <th>Generado (CRM)</th>
                  <th>Estado</th>
                  <th>OP</th>
                  <th>Generado por</th>
                  <th>Documento(s) World Office</th>
                  <th>Libros: generado / confirmado WO</th>
                  <th class="bp-num">Generado</th>
                  <th class="bp-num">Confirmado WO</th>
                </tr>
              </thead>
              <tbody id="bp-historial-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="bp-section" id="bp-atender-wrap" style="display:none;">
        <div class="bp-section-head">
          <p class="bp-section-title"><i class="bi bi-box-seam"></i> Atender backorder — generar pedido nuevo</p>
        </div>
        <div class="bp-section-body">
          <p class="bp-aviso" style="background:#eff6ff; border-color:#bfdbfe; color:#1e3a8a;">
            El pedido nuevo es una copia del pedido original (mismos datos, observaciones y descuentos) ligada a
            la <strong>misma OP #<span id="bp-atender-op"></span></strong>. Solo se puede cambiar la cantidad.
          </p>
          <div class="bo-filtro" style="max-width:420px; margin-bottom:14px;">
            <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Pedido base (el pedido nuevo es copia de este)</label>
            <select id="atender-pedido-base" class="form-control form-control-sm"></select>
          </div>
          <form id="form-atender-backorder" method="POST" action="php/backorders_generar_pedido.php">
            <input type="hidden" name="id_pedido_origen" id="atender-id-pedido-origen">
            <div class="bp-info-grid">
              <div class="bp-info-item"><div class="bp-info-label">Colegio</div><div class="bp-info-val" id="atender-colegio">—</div></div>
              <div class="bp-info-item"><div class="bp-info-label">Cliente</div><div class="bp-info-val" id="atender-cliente">—</div></div>
              <div class="bp-info-item"><div class="bp-info-label">Documento</div><div class="bp-info-val" id="atender-fac-rem">—</div></div>
              <div class="bp-info-item"><div class="bp-info-label">Tipo de pedido</div><div class="bp-info-val" id="atender-tipo">—</div></div>
              <div class="bp-info-item"><div class="bp-info-label">Fecha de recogida</div><div class="bp-info-val" id="atender-fecha-r">—</div></div>
              <div class="bp-info-item" style="grid-column: span 3;"><div class="bp-info-label">Dirección de entrega</div><div class="bp-info-val" id="atender-dir-ent">—</div></div>
              <div class="bp-info-item" style="grid-column: 1 / -1;"><div class="bp-info-label">Observaciones</div><div class="bp-info-val" id="atender-observaciones" style="white-space:pre-wrap; font-weight:500;">—</div></div>
            </div>
            <div class="table-responsive">
              <table class="bp-tabla">
                <thead>
                  <tr>
                    <th>ISBN</th>
                    <th>Título</th>
                    <th class="bp-num">Precio</th>
                    <th class="bp-num">Descuento</th>
                    <th class="bp-num">Precio facturado</th>
                    <th class="bp-num" id="atender-th-desc-aprob">Desc. aprobado</th>
                    <th class="bp-num" title="Pendiente de despacho que todavía no está en ningún pedido nuevo">Pendiente de gestión</th>
                    <th class="bp-num">Cantidad para el pedido nuevo</th>
                    <th class="bp-num">Valor venta</th>
                  </tr>
                </thead>
                <tbody id="bp-atender-tbody"></tbody>
                <tfoot>
                  <tr style="font-weight:700; background:#f8fafc;">
                    <td colspan="7" id="atender-tfoot-label" style="text-align:right;">Total</td>
                    <td class="bp-num" id="atender-total-cant">0</td>
                    <td class="bp-num" id="atender-total-valor">$ 0</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div style="margin-top:16px;">
              <button type="submit" class="bp-btn"><i class="bi bi-plus-circle"></i> Generar pedido con lo pendiente</button>
            </div>
          </form>
        </div>
      </div>

      <div class="bp-section" id="bp-vacio-wrap">
        <div class="bp-section-body">
          <div class="bp-vacio"><i class="bi bi-search"></i>Busca un colegio (o su cliente o consecutivo BK) para ver todo su detalle.</div>
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
  function h(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function num(v) { v = parseFloat(v) || 0; return v % 1 === 0 ? v.toString() : v.toFixed(2); }
  function claseCumplimiento(pct) {
    if (pct < 50) return 'bp-cump-bajo';
    if (pct < 80) return 'bp-cump-medio';
    return 'bp-cump-alto';
  }
  function badgeCumplimiento(pct) {
    return '<span class="bp-badge-cump ' + claseCumplimiento(pct) + '">' + num(pct) + '%</span>';
  }

  $('#bp-select-pedido').select2({
    placeholder: 'Escribe el colegio, cliente o consecutivo BK...',
    allowClear: true,
    width: '100%',
    // minimumInputLength: 0 para que al abrir el desplegable ya traiga resultados (sin tener que
    // escribir primero) y siga permitiendo buscar escribiendo — pedido por el usuario 2026-09-18.
    minimumInputLength: 0,
    ajax: {
      url: 'php/backorders_buscar_colegio_detalle.php',
      dataType: 'json',
      delay: 250,
      // El servidor ya limita a los periodos 2026B y 2027 (BACKORDERS_PERIODOS_REPORTE).
      data: function (params) { return { q: params.term || '' }; },
      processResults: function (data) {
        // Un resultado por colegio + período; la fecha es la de generación del backorder.
        return { results: (data || []).map(function (c) {
          return { id: c.id_colegio + '|' + c.id_periodo, id_colegio: c.id_colegio, id_periodo: c.id_periodo,
            text: (c.consecutivos ? c.consecutivos + ' — ' : '') + c.colegio + (c.cliente ? ' — ' + c.cliente : '') + ' — ' + c.periodo +
              (c.fecha_backorder ? ' — Fecha: ' + c.fecha_backorder : '') };
        }) };
      }
    },
    language: {
      noResults: function () { return 'Sin resultados'; },
      searching: function () { return 'Buscando...'; }
    }
  });

  $('#bp-select-pedido').on('select2:select', function (e) {
    cargarDetalle({ id_colegio: e.params.data.id_colegio, id_periodo: e.params.data.id_periodo });
  });
  $('#bp-select-pedido').on('select2:clear', function () {
    $('#bp-resultado').hide();
    $('#bp-vacio-wrap').show();
  });

  // Detalle del colegio + período (params: id_colegio + id_periodo, o id_pedido de uno de sus pedidos).
  function cargarDetalle(params) {
    $.getJSON('php/backorders_colegio_detalle.php', params, function (resp) {
      if (!resp.success) {
        if (window.inkToast) window.inkToast(resp.message || 'No se pudo cargar el colegio.', 'error');
        return;
      }
      render(resp.pedido);
    }).fail(function () {
      if (window.inkToast) window.inkToast('Error al conectar con el servidor.', 'error');
    });
  }
  function cargarPedido(idPedido) { cargarDetalle({ id_pedido: idPedido }); }

  // "Atender backorder" se hace desde un pedido base del colegio (el pedido nuevo copia sus datos y
  // su OP): al elegirlo se trae su formato (precios, descuentos) y el tope de cada libro.
  function cargarAtender(idPedidoBase) {
    $('#bp-atender-wrap').hide();
    if (!idPedidoBase) return;
    $.getJSON('php/backorders_pedido_detalle.php', { id_pedido: idPedidoBase }, function (resp) {
      if (resp.success) renderAtender(resp.pedido, resp.pedido.items || []);
    });
  }
  $('#atender-pedido-base').on('change', function () { cargarAtender(this.value); });

  var colegioActual = null; // { id_colegio, id_periodo } del colegio en pantalla

  function render(p) {
    colegioActual = { id_colegio: p.id_colegio, id_periodo: p.id_periodo };
    $('#bp-vacio-wrap').hide();
    $('#bp-resultado').show();

    $('#bp-titulo-colegio').text(p.colegio || '');
    $('#bp-titulo-periodo').text(p.periodo ? '— ' + p.periodo : '');
    // Vacío mientras no haya una REM/FV confirmada en World Office (sin numeración provisional).
    $('#bp-consecutivo').html(p.consecutivo ? '<span class="bp-consecutivo">' + h(p.consecutivo) + '</span>' : '—');
    $('#bp-colegio').text(p.colegio || '—');
    $('#bp-cliente').text(p.cliente || '—');
    $('#bp-responsable').text(p.responsable || '—');
    $('#bp-periodo').text(p.periodo || '—');
    $('#bp-fecha').text(p.fecha_backorder || '—');
    $('#bp-documento').text(p.documento || '—');
    $('#bp-op').html(linksOp(p.op_ids));
    // Cada pedido abre su ficha.
    $('#bp-pedidos-colegio').html((p.pedidos_colegio || []).length ? p.pedidos_colegio.map(function (id) {
      return '<a href="pedido_colegio.php?id_pedido=' + id + '&tp=3" target="_blank">#' + id + '</a>';
    }).join(', ') : '—');
    $('#bp-devoluciones').text((p.docs_devolucion || []).length ? p.docs_devolucion.join(', ') : '—');
    $('#bp-nota-colegio').toggle(!!(p.pedidos_colegio || []).length);

    $('#bp-aviso-premigracion').toggle(!!p.esPreMigracion);
    $('#bp-aviso-generado').hide(); // un pedido nuevo abre directamente su colegio

    renderHistorial(p);
    renderPaquetes(p.paquetes || []);

    $('#bp-total-solicitado').text(num(p.total_solicitada));
    $('#bp-total-devuelto').text(num(p.total_devuelta));
    $('#bp-total-generado').text(num(p.total_generada));
    $('#bp-total-pendiente').text(num(p.total_pendiente));
    $('#bp-total-cumplimiento').html(badgeCumplimiento(p.cumplimiento) +
      (p.total_excedente > 0 ? '<span class="bp-excedente">+' + num(p.total_excedente) + ' despachado de más — revisar</span>' : ''));

    var items = p.items || [];
    if (!items.length) {
      $('#bp-tbody').html('<tr><td colspan="8" class="bp-vacio">' +
        'Este colegio no tiene libros para calcular backorder.' +
        '</td></tr>');
      $('#bp-atender-wrap').hide();
      return;
    }
    // Solo los libros que son backorder: uno ya despachado completo no se lista (pedido por la
    // usuaria 2026-10-29). "Atender backorder" sigue recibiendo todos los items y filtra aparte.
    var itemsPendientes = items.filter(function (it) { return it.pendiente > 0; });
    $('#bp-tbody').html(!itemsPendientes.length
      ? '<tr><td colspan="8" class="bp-vacio">Este colegio no tiene libros pendientes de despacho.</td></tr>'
      : itemsPendientes.map(function (it) {
      return '<tr>' +
        '<td style="white-space:nowrap;">' + h(it.isbn || '—') + '</td>' +
        '<td>' + h(it.libro) + '</td>' +
        '<td class="bp-num">' + num(it.solicitada) + '</td>' +
        '<td class="bp-num">' + num(it.remisionada) + '</td>' +
        '<td class="bp-num">' + num(it.devuelta) + '</td>' +
        '<td class="bp-num">' + num(it.generada) + '</td>' +
        '<td class="bp-num">' + num(it.pendiente) + '</td>' +
        '<td class="bp-num">' + badgeCumplimiento(it.cumplimiento) +
          (it.excedente > 0 ? '<span class="bp-excedente">+' + num(it.excedente) + ' de más</span>' : '') + '</td>' +
        '</tr>';
    }).join(''));

    // Pedidos base para "Atender backorder" (por defecto, el del primer consecutivo).
    var bases = p.pedidos_base || [];
    $('#atender-pedido-base').html(bases.map(function (b) {
      return '<option value="' + b.id_pedido + '"' + (b.id_pedido === p.id_pedido_base ? ' selected' : '') + '>#' + b.id_pedido +
        (b.consecutivo ? ' — ' + h(b.consecutivo) : '') + ' — OP ' + h(b.op) + '</option>';
    }).join(''));
    cargarAtender(bases.length ? $('#atender-pedido-base').val() : null);
  }

  // # OP como enlace a la ficha de la OP, directo a su sección de pedidos relacionados.
  function linksOp(opIds) {
    if (!opIds || !opIds.length) return '—';
    return opIds.map(function (id) {
      return '<a href="op_pendiente.php?op=' + encodeURIComponent(id) + '#pedidos-relacionados" target="_blank">#' + h(id) + '</a>';
    }).join(', ');
  }

  // Vista de pedido_colegio.php (parámetro tp) que corresponde a cada estado del pedido.
  function tpPorEstado(estado) {
    return ({ 1: 2, 2: 3, 3: 5, 4: 4, 5: 6, 6: 7, 7: 8 })[estado] || 2;
  }

  // ── Backorder por paquete: qué libro quedó pendiente de cada paquete y en cuántas unidades ──
  function renderPaquetes(paquetes) {
    // Solo paquetes con algún libro pendiente, y dentro de cada uno solo esos libros (los ya
    // despachados completos no son backorder).
    paquetes = paquetes.filter(function (pq) {
      return pq.pendiente > 0 || pq.libros.some(function (l) { return l.pendiente > 0; });
    });
    $('#bp-paquetes-wrap').toggle(paquetes.length > 0);
    $('#bp-paquetes').html(paquetes.map(function (pq) {
      var pendientes = pq.libros.filter(function (l) { return l.pendiente > 0; });
      var frases = pendientes.length
        ? '<ul class="bp-paquete-pend">' + pendientes.map(function (l) {
            return '<li>Del ' + h(pq.paquete) + ' quedó pendiente <strong>' + h(l.libro) + '</strong> en <strong>' + num(l.pendiente) + '</strong> ' + (l.pendiente == 1 ? 'unidad' : 'unidades') + '.</li>';
          }).join('') + '</ul>'
        : '';
      return '<div class="bp-paquete">' +
        '<div class="bp-paquete-head">' +
          '<div><span class="bp-paquete-nombre">' + h(pq.paquete) + '</span><span class="bp-paquete-codigo">' + h(pq.codigo) + '</span></div>' +
          '<div>Despachados como paquete en WO: <strong>' + num(pq.despachados) + '</strong> ' +
            (pq.pendiente > 0 ? '<span class="bp-chip-sync">Pendiente: ' + num(pq.pendiente) + '</span>' : '<span class="bp-chip-ok">Completo</span>') + '</div>' +
        '</div>' + frases +
        '<div class="table-responsive"><table class="bp-tabla"><thead><tr>' +
          '<th>ISBN</th><th>Título</th><th class="bp-num">Solicitado</th><th class="bp-num">Despachado</th><th class="bp-num">Pendiente de despacho</th>' +
        '</tr></thead><tbody>' + pendientes.map(function (l) {
          return '<tr>' +
            '<td style="white-space:nowrap;">' + h(l.isbn || '—') + '</td>' +
            '<td>' + h(l.libro) + '</td>' +
            '<td class="bp-num">' + num(l.solicitada) + '</td>' +
            '<td class="bp-num">' + num(l.despachada) + '</td>' +
            '<td class="bp-num">' + num(l.pendiente) + '</td>' +
            '</tr>';
        }).join('') + '</tbody></table></div>' +
        '</div>';
    }).join(''));
  }

  // ── Historial de pedidos nuevos generados desde este pedido ──
  function renderHistorial(p) {
    var hist = p.generado_desde ? [] : (p.historial || []);
    $('#bp-historial-wrap').toggle(hist.length > 0);
    if (!hist.length) return;
    $('#bp-historial-tbody').html(hist.map(function (g) {
      var libros = '<ul class="bp-hist-libros">' + g.libros.map(function (l) {
        return '<li>' + (l.isbn ? '<span style="color:#64748b;">' + h(l.isbn) + '</span> — ' : '') + h(l.libro) + ': <strong>' + num(l.cantidad) + '</strong>' +
          ' / <strong style="color:#166534;">' + num(l.cantidad_wo || 0) + '</strong></li>';
      }).join('') + '</ul>';
      // Documentos de World Office del pedido nuevo; sin ninguno confirmado = pendiente de sincronización.
      var docs = (g.docs_wo || []).map(function (d) {
        return h(d.documento) + (d.anulado ? ' (anulada)' : (d.confirmado ? '' : ' (sin sincronizar)'));
      }).join(', ');
      var estadoWo = g.sincronizado
        ? '<span class="bp-chip-ok">Confirmado en WO</span>'
        : '<span class="bp-chip-sync">Pendiente de sincronización</span>';
      return '<tr' + (g.anulado ? ' class="bp-hist-anulado"' : '') + '>' +
        '<td>' + (g.consecutivo ? '<span class="bp-consecutivo">' + h(g.consecutivo) + '</span>' : '—') + '</td>' +
        '<td><a href="pedido_colegio.php?id_pedido=' + g.id_pedido_nuevo + '&tp=' + tpPorEstado(g.estado_id) + '" target="_blank">#' + g.id_pedido_nuevo + '</a></td>' +
        '<td>' + h(g.fecha) + '</td>' +
        '<td class="bp-hist-estado">' + h(g.estado || '—') + '</td>' +
        '<td>' + linksOp(g.id_op ? [g.id_op] : []) + '</td>' +
        '<td>' + h(g.usuario) + '</td>' +
        '<td>' + (docs || '—') + '<br>' + estadoWo + '</td>' +
        '<td>' + libros + '</td>' +
        '<td class="bp-num">' + num(g.total) + '</td>' +
        '<td class="bp-num">' + num(g.cantidad_wo || 0) + '</td>' +
        '</tr>';
    }).join(''));
  }

  // ── Atender backorder: formulario para generar un pedido nuevo con lo pendiente ──
  function renderAtender(p, items) {
    // Solo lo que falta por gestionar (pendiente de despacho que aún no está en un pedido nuevo).
    // Solo los libros de este pedido (el pedido nuevo copia sus líneas); el tope es lo pendiente del colegio.
    var pendientes = items.filter(function (it) { return it.en_pedido && it.pendiente_gestion > 0; });
    var puedeAtender = pendientes.length > 0 && !p.esPreMigracion && p.op && !p.generado_desde;
    $('#bp-atender-wrap').toggle(!!puedeAtender);
    if (!puedeAtender) return;

    $('#atender-id-pedido-origen').val(p.id_pedido);
    $('#bp-atender-op').text(p.op);
    // Todo se hereda del pedido original sin poder editarse (solo la cantidad) — pedido por la
    // usuaria 2026-10-20. Mismas etiquetas que pedido_colegio.php.
    var o = p.origen || {};
    $('#atender-colegio').text(p.colegio || '—');
    $('#atender-cliente').text(p.cliente || '—');
    $('#atender-fac-rem').text(parseInt(o.fac_rem, 10) === 1 ? 'Factura' : 'Remisión');
    $('#atender-tipo').text(parseInt(o.tipo, 10) === 1 ? 'Libros sueltos' : 'Paquete');
    $('#atender-fecha-r').text(o.fecha_r || '—');
    $('#atender-dir-ent').text(o.dir_ent || '—');
    $('#atender-observaciones').text(o.observaciones || '—');

    // "Desc. aprobado" solo si alguna línea lo tiene (igual que pedido_colegio.php).
    var hayDescAprob = pendientes.some(function (it) { return it.descuento_aprob > 0; });
    $('#atender-th-desc-aprob').toggle(hayDescAprob);
    $('#atender-tfoot-label').attr('colspan', hayDescAprob ? 7 : 6);

    $('#bp-atender-tbody').html(pendientes.map(function (it) {
      var maxCant = Math.floor(it.pendiente_gestion);
      var precioFact = precioFacturado(it);
      return '<tr>' +
        '<td>' + h(it.isbn || '—') + '</td>' +
        '<td>' + h(it.libro) + '</td>' +
        '<td class="bp-num">' + pesos(it.precio) + '</td>' +
        '<td class="bp-num">' + num(it.descuento_pct) + ' %</td>' +
        '<td class="bp-num">' + pesos(precioFact) + '</td>' +
        (hayDescAprob ? '<td class="bp-num">' + (it.descuento_aprob > 0 ? num(it.descuento_aprob) + ' %' : '—') + '</td>' : '') +
        '<td class="bp-num">' + num(it.pendiente_gestion) + '</td>' +
        '<td class="bp-num">' +
          '<input type="number" class="form-control form-control-sm" style="text-align:center;" ' +
          'name="cantidad[' + it.id_libro + ']" data-precio-fact="' + precioFact + '" min="0" max="' + maxCant + '" step="1" placeholder="0">' +
        '</td>' +
        '<td class="bp-num atender-valor">$ 0</td>' +
        '</tr>';
    }).join(''));
    actualizarTotalesAtender();
  }

  // Precio con el descuento del presupuesto, igual que pedido_colegio.php.
  function precioFacturado(it) {
    var precio = parseFloat(it.precio) || 0;
    return precio - precio * ((parseFloat(it.descuento_pct) || 0) / 100);
  }
  function pesos(v) { return '$ ' + Math.round(parseFloat(v) || 0).toLocaleString('es-CO'); }

  function actualizarTotalesAtender() {
    var totalCant = 0, totalValor = 0;
    $('#bp-atender-tbody tr').each(function () {
      var $input = $(this).find('input[type=number]');
      var cant = parseFloat($input.val()) || 0;
      var valor = cant * (parseFloat($input.data('precio-fact')) || 0);
      $(this).find('.atender-valor').text(pesos(valor));
      totalCant += cant;
      totalValor += valor;
    });
    $('#atender-total-cant').text(num(totalCant));
    $('#atender-total-valor').text(pesos(totalValor));
  }

  // No dejar escribir más de lo pendiente de esa fila (el servidor también lo valida, esto es
  // solo para que el usuario no tenga que enviar el formulario para enterarse).
  $('#bp-atender-tbody').on('input', 'input[type=number]', function () {
    var $input = $(this);
    var max = parseFloat($input.attr('max'));
    var val = parseFloat($input.val());
    if (!isNaN(max) && !isNaN(val) && val > max) $input.val(max);
    if (val < 0) $input.val(0);
    actualizarTotalesAtender();
  });

  $('#form-atender-backorder').on('submit', function (e) {
    e.preventDefault();
    var form = this;
    var hayCantidad = $(form).find('input[name^="cantidad"]').toArray().some(function (i) { return parseFloat(i.value) > 0; });
    if (!hayCantidad) {
      if (window.inkToast) window.inkToast('Indica al menos una cantidad para generar el pedido.', 'error');
      return;
    }
    inkConfirm({
      title: '¿Generar pedido nuevo?',
      text: 'Se creará un pedido nuevo con las cantidades indicadas, ligado a la misma OP. Esas cantidades se descontarán del saldo pendiente del pedido original.',
      type: 'success',
      btnOk: 'Sí, generar'
    }, function () {
      form.submit();
    });
  });

  // "Marcar como completado": el colegio + período sale de la búsqueda y de todo el módulo de
  // backorders, aunque se deban unidades.
  $('#bp-btn-completado').on('click', function () {
    if (!colegioActual) return;
    inkConfirm({
      title: '¿Marcar como completado?',
      text: 'Este colegio dejará de aparecer en la búsqueda y de contar en el módulo de backorders, aunque todavía se deban unidades.',
      type: 'warning',
      btnOk: 'Sí, marcar'
    }, function () {
      $.post('php/backorders_marcar_completado.php', colegioActual, function (resp) {
        if (!resp || !resp.success) {
          if (window.inkToast) window.inkToast((resp && resp.message) || 'No se pudo marcar como completado.', 'error');
          return;
        }
        if (window.inkToast) window.inkToast('Backorder marcado como completado.', 'success');
        colegioActual = null;
        $('#bp-select-pedido').val(null).trigger('change');
        $('#bp-resultado').hide();
        $('#bp-vacio-wrap').show();
      }, 'json').fail(function () {
        if (window.inkToast) window.inkToast('Error al conectar con el servidor.', 'error');
      });
    });
  });

  // Si se llega con ?id_pedido=X en la URL (ej. redirigido después de generar un pedido nuevo),
  // carga ese pedido directamente sin tener que volver a buscarlo.
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('id_colegio') && urlParams.get('id_periodo')) {
    cargarDetalle({ id_colegio: urlParams.get('id_colegio'), id_periodo: urlParams.get('id_periodo') });
  } else if (urlParams.get('id_pedido')) {
    cargarPedido(urlParams.get('id_pedido'));
  }
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
