<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/backorders_sa_datos.php");

if (!bsa_tiene_acceso()) {
    header("Location: index.php");
    exit;
}
bsa_asegurar_tablas($bdd);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Informe de backorders sin adopción</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <?php include("template/backorders_sa_estilos.php"); ?>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Informe de backorders sin adopción</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="backorders_sa.php">Backorders sin adopción</a></li>
                <li class="breadcrumb-item active" aria-current="page">Informe</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="bsa-section">
        <div class="bsa-section-head">
          <p class="bsa-section-title"><i class="bi bi-bar-chart"></i> Resumen</p>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="backorders_sa.php" class="bsa-btn bsa-btn-outline"><i class="bi bi-list-check"></i> Administrar backorders</a>
            <a href="php/backorders_sa_excel.php" id="bsa-link-excel" class="bsa-btn"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</a>
          </div>
        </div>
        <div class="bsa-section-body">
          <?php include("template/backorders_sa_filtros.php"); ?>
          <div class="bsa-resumen" id="bsa-resumen"></div>
        </div>
      </div>

      <div class="bsa-section">
        <div class="bsa-section-head">
          <p class="bsa-section-title"><i class="bi bi-table"></i> Detalle por backorder</p>
        </div>
        <div class="bsa-section-body">
          <div class="table-responsive">
            <table class="bsa-tabla">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Fecha de registro</th>
                  <th>Libro</th>
                  <th>Pedido / Cliente</th>
                  <th class="bsa-num">Solicitada</th>
                  <th class="bsa-num">Atendida</th>
                  <th class="bsa-num">Pendiente</th>
                  <th>Estado</th>
                  <th>Atenciones</th>
                  <th>Última atención</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="bsa-tbody"><tr><td colspan="11" class="bsa-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="bsa-section">
        <div class="bsa-section-head">
          <p class="bsa-section-title"><i class="bi bi-clock-history"></i> Historial de atenciones</p>
        </div>
        <div class="bsa-section-body">
          <div class="table-responsive">
            <table class="bsa-tabla">
              <thead>
                <tr>
                  <th>Fecha de atención</th>
                  <th>Backorder</th>
                  <th>Libro</th>
                  <th>Pedido / Cliente</th>
                  <th class="bsa-num">Cantidad</th>
                  <th class="bsa-num">Pendiente antes</th>
                  <th>Observaciones</th>
                  <th>Atendido por</th>
                </tr>
              </thead>
              <tbody id="bsa-tbody-atenciones"></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<?php include("template/backorders_sa_modal_historial.php"); ?>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/backorders_sa.js"></script>
<script>
(function () {
  var BSA = window.BSA, h = BSA.h, n = BSA.num;
  var porId = {};

  function render(data) {
    porId = {};
    $('#bsa-resumen').html(BSA.resumenHtml(data.totales));
    var filas = data.filas || [];

    $('#bsa-tbody').html(filas.length ? filas.map(function (b) {
      porId[b.id] = b;
      var pedidos = b.atenciones.map(function (a) {
        return n(a.cantidad) + ' uds' + (a.id_pedido2 ? ' · pedido ' + BSA.linkPedido(a.id_pedido2, a.estado_pedido_id) : '') +
          (a.pedido_anulado ? ' <span class="bsa-sub" style="display:inline;">(pedido anulado)</span>' : '');
      }).join('<br>') || '—';
      var ultima = b.atenciones.length ? b.atenciones[b.atenciones.length - 1].fecha : null;
      return '<tr' + (b.estado === 'anulado' ? ' class="bsa-fila-anulada"' : '') + '>' +
        '<td><strong>#' + b.id + '</strong></td>' +
        '<td>' + h(BSA.fecha(b.fecha_registro)) + '</td>' +
        '<td>' + h(b.libro) + (b.isbn ? '<span class="bsa-sub">' + h(b.isbn) + '</span>' : '') + '</td>' +
        '<td>' + BSA.pedidoCliente(b) + '</td>' +
        '<td class="bsa-num">' + n(b.cantidad_solicitada) + '</td>' +
        '<td class="bsa-num">' + n(b.cantidad_atendida) + '</td>' +
        '<td class="bsa-num"><strong>' + n(b.cantidad_pendiente) + '</strong></td>' +
        '<td>' + BSA.badgeEstado(b) + '</td>' +
        '<td>' + pedidos + '</td>' +
        '<td>' + h(ultima ? BSA.fecha(ultima) : '—') + '</td>' +
        '<td><button type="button" class="bsa-btn bsa-btn-sm bsa-btn-outline bsa-btn-historial" data-id="' + b.id + '"><i class="bi bi-clock-history"></i> Historial</button></td>' +
        '</tr>';
    }).join('') : '<tr><td colspan="11" class="bsa-vacio">No hay backorders con los filtros seleccionados.</td></tr>');

    var atenciones = [];
    filas.forEach(function (b) { b.atenciones.forEach(function (a) { atenciones.push({ b: b, a: a }); }); });
    atenciones.sort(function (x, y) { return x.a.fecha < y.a.fecha ? 1 : -1; });
    $('#bsa-tbody-atenciones').html(atenciones.length ? atenciones.map(function (x) {
      var a = x.a, b = x.b;
      return '<tr' + (a.pedido_anulado ? ' class="bsa-fila-anulada"' : '') + '>' +
        '<td>' + h(BSA.fecha(a.fecha)) + '</td>' +
        '<td>#' + b.id + '</td>' +
        '<td>' + h(b.libro) + '</td>' +
        '<td>' + (b.id_pedido2 ? 'Pedido #' + b.id_pedido2 + ' — ' : '') + h(b.tercero) + '</td>' +
        '<td class="bsa-num">' + n(a.cantidad) + '</td>' +
        '<td class="bsa-num">' + n(a.pendiente_antes) + '</td>' +
        '<td>' + h(a.observaciones || '—') + (a.pedido_anulado ? '<span class="bsa-sub">Su pedido se anuló: no cuenta como atendido</span>' : '') + '</td>' +
        '<td>' + h(a.usuario) + '</td></tr>';
    }).join('') : '<tr><td colspan="8" class="bsa-vacio">Sin atenciones con los filtros seleccionados.</td></tr>');
  }

  function cargar() {
    var f = BSA.filtros();
    $('#bsa-link-excel').attr('href', 'php/backorders_sa_excel.php?' + $.param(f));
    $('#bsa-tbody').html('<tr><td colspan="11" class="bsa-vacio">Cargando...</td></tr>');
    $.getJSON('php/backorders_sa_datos.php', f, function (resp) {
      if (!resp.success) { if (window.inkToast) window.inkToast(resp.message || 'No se pudo cargar.', 'error'); return; }
      render(resp);
    }).fail(function () { if (window.inkToast) window.inkToast('Error al conectar con el servidor.', 'error'); });
  }
  BSA.alFiltrar(cargar);
  cargar();

  $('#bsa-tbody').on('click', '.bsa-btn-historial', function () {
    var b = porId[$(this).data('id')];
    if (b) BSA.mostrarHistorial(b);
  });
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
