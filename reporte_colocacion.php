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
  <title>Inkpulse - Colocación</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/select2/dist/css/select2.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .col-section {
      background: #fff; border-radius: 14px;
      box-shadow: 0 2px 10px rgba(15,23,42,.08);
      margin-bottom: 20px; overflow: hidden;
    }
    .col-section-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px; border-bottom: 1px solid #e2e8f0;
    }
    .col-num {
      width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; font-size: .85rem; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .col-section-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .col-section-body  { padding: 24px; }

    .col-aviso {
      background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
      border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin: 0 0 18px;
    }

    .col-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 26px; border-radius: 8px; font-size: .95rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .col-btn:hover { opacity: .9; transform: translateY(-1px); }
    .col-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .col-btn-stop { background: #fff; color: #dc2626; border: 1.5px solid #fecaca; }
    .col-btn-stop:hover { background: #fef2f2; }

    .col-progreso-wrap { margin-top: 16px; background: #f1f5f9; border-radius: 8px; height: 8px; overflow: hidden; }
    .col-progreso { height: 100%; background: linear-gradient(135deg, #1d4ed8, #2563eb); width: 0%; transition: width .2s; }
    .col-progreso-txt { font-size: .78rem; color: #64748b; margin-top: 6px; }

    .col-estado { font-size: .82rem; color: #64748b; margin-top: 10px; display: none; }
    .col-estado.visible { display: block; }
    .col-estado.error { color: #dc2626; }

    .col-resumen { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 18px; }
    .col-tarjeta { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 20px; min-width: 150px; }
    .col-tarjeta .num { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
    .col-tarjeta .lbl { font-size: .78rem; color: #64748b; }

    table.col-tabla { width: 100%; }
    table.col-tabla th, table.col-tabla td { text-align: left; padding: 9px 12px; font-size: .8rem; white-space: nowrap; }
    .col-money { text-align: right !important; font-variant-numeric: tabular-nums; }
    .col-wo-detalle { font-size: .74rem; color: #64748b; margin-top: 4px; }
    .col-wo-detalle span { display: block; }
    tfoot.col-tfoot td { font-weight: 700; background: #f8fafc; }

    .col-sincruzar-item { border-bottom: 1px solid #f1f5f9; padding: 8px 0; font-size: .8rem; }
    .col-sincruzar-item .concepto { color: #64748b; }
    .col-sincruzar-asignar { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .col-sincruzar-asignar .sincruzar-select { min-width: 300px; }
    .col-sincruzar-asignar .select2-container .select2-selection--single { font-size: .78rem; height: 32px; padding: 2px 4px; border-radius: 6px; border: 1px solid #e2e8f0; }
    .col-sincruzar-asignar .select2-container .select2-selection--single .select2-selection__rendered { line-height: 28px; }
    .col-sincruzar-asignar .select2-container .select2-selection--single .select2-selection__arrow { height: 30px; }
    .col-sincruzar-asignar button { font-size: .78rem; padding: 4px 12px; border-radius: 6px; border: none; background: #1d4ed8; color: #fff; cursor: pointer; }
    .col-sincruzar-asignar button:disabled { opacity: .5; cursor: not-allowed; }
    .col-sincruzar-asignar .ok { color: #16a34a; font-size: .78rem; }
    .col-sincruzar-asignar .err { color: #dc2626; font-size: .78rem; }
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
            <div class="title"><h4>Colocación (Calendario B)</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item active" aria-current="page">Colocación</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <?php if ($es_admin): ?>
      <div class="col-section">
        <div class="col-section-head">
          <div class="col-num">1</div>
          <p class="col-section-title">Actualizar desde World Office</p>
        </div>
        <div class="col-section-body">
          <p class="col-aviso">
            Trae las remisiones (REM, prefijo CEUR), facturas de venta (FV), devoluciones de venta (DREM)
            y facturas de punto de venta (POS) de World Office y las cruza con el colegio según el campo
            "concepto" de cada documento. Las devoluciones (DREM) restan del total colocado; REM, FV y POS
            suman. Los Abonos (Recibo de Caja) no se sincronizan: World Office no expone ningún valor
            monetario para ese tipo de documento.
          </p>

          <button type="button" class="col-btn" id="btn-iniciar"><i class="bi bi-arrow-repeat"></i> Actualizar desde World Office</button>
          <button type="button" class="col-btn col-btn-stop" id="btn-detener" style="display:none;"><i class="bi bi-stop-circle"></i> Detener</button>

          <div class="col-progreso-wrap"><div class="col-progreso" id="col-progreso"></div></div>
          <p class="col-progreso-txt" id="col-progreso-txt">Sin iniciar.</p>

          <div class="col-estado" id="col-estado"></div>

          <div class="col-resumen">
            <div class="col-tarjeta"><div class="num" id="col-revisados">0</div><div class="lbl">Documentos revisados</div></div>
            <div class="col-tarjeta"><div class="num" id="col-emparejados">0</div><div class="lbl">Cruzados con un colegio</div></div>
            <div class="col-tarjeta"><div class="num" id="col-sincruzar">0</div><div class="lbl">Sin cruzar</div></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-section">
        <div class="col-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="col-num"><?= $es_admin ? '2' : '1' ?></div>
            <p class="col-section-title">Reporte de colocación<span id="col-periodo-txt"></span></p>
          </div>
          <a href="php/colocacion_excel.php" id="link-excel-colocacion" class="col-btn" style="padding:8px 18px;">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
          </a>
        </div>
        <div class="col-section-body">
          <div class="col-filtros" style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
            <div>
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Filtrar por Periodo</label>
              <select id="filtro-periodo" class="form-control form-control-sm" style="min-width:140px;"></select>
            </div>
            <div>
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Filtrar por Empresa</label>
              <select id="filtro-empresa" class="form-control form-control-sm" style="min-width:220px;">
                <option value="">Todas</option>
              </select>
            </div>
            <div>
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Filtrar por Cliente</label>
              <select id="filtro-cliente" class="form-control form-control-sm" style="min-width:220px;">
                <option value="">Todos</option>
              </select>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover col-tabla" id="tabla-colocacion" style="width:100%">
              <thead>
                <tr>
                  <th>Empresa</th>
                  <th>Colegio</th>
                  <th>Presupuesto Registrado en CRM</th>
                  <th>Adopciones CRM</th>
                  <th>Atenciones a Clientes</th>
                  <th>Población General</th>
                  <th>Compradores Activos (# Adopciones)</th>
                  <th>Descuento Promedio</th>
                  <th>Número de la Adopción</th>
                  <th>Cliente</th>
                  <th>Factura de Venta</th>
                  <th>Abonos</th>
                  <th>Colocación World Office (REM-CEUR)</th>
                  <th>Facturas POS</th>
                  <th>Devoluciones</th>
                  <th>Total Colocado</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-section" id="panel-sin-cruzar" style="display:none;">
        <div class="col-section-head">
          <div class="col-num">!</div>
          <p class="col-section-title">Documentos de World Office sin cruzar</p>
        </div>
        <div class="col-section-body">
          <p class="col-aviso">
            Estos documentos no se pudieron asociar automáticamente a ningún colegio de Calendario B
            (el "concepto" no trae el patrón esperado, o el nombre no coincide con ningún colegio registrado).
          </p>
          <div id="lista-sin-cruzar"></div>
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
<script src="src/plugins/select2/dist/js/select2.min.js"></script>
<script>
(function () {
  var esAdmin = <?= $es_admin ? 'true' : 'false' ?>;

  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(v) {
    v = parseFloat(v) || 0;
    return '$ ' + Math.round(v).toLocaleString('es-CO');
  }

  // ── Sección 2/1: tabla del reporte (siempre visible) ──
  var tabla = $('#tabla-colocacion').DataTable({
    processing: true,
    autoWidth: false,
    data: [],
    columns: [
      { data: 'empresa' },
      { data: 'colegio' },
      { data: 'presupuesto_crm', className: 'col-money', render: function (d) { return money(d); } },
      { data: 'adopciones_crm', className: 'col-money', render: function (d) { return money(d); } },
      { data: 'atenciones_clientes', className: 'col-money', render: function (d) { return money(d); } },
      { data: 'poblacion_general', className: 'col-money' },
      { data: 'compradores_activos', className: 'col-money' },
      { data: 'descuento_promedio', className: 'col-money', render: function (d) { return (parseFloat(d) || 0).toFixed(2) + '%'; } },
      { data: 'numero_adopcion', className: 'col-money', render: function (d) { return d ? h(d) : '—'; } },
      { data: 'cliente', render: function (d) { return h(d); } },
      { data: 'factura_venta', className: 'col-money', render: function (d) { return money(d); } },
      { data: 'abonos', className: 'col-money', render: function (d) { return money(d); } },
      {
        data: 'colocacion_wo', className: 'col-money',
        render: function (movs) {
          movs = movs || [];
          if (!movs.length) return '—';
          var total = movs.reduce(function (s, m) { return s + (parseFloat(m.valor) || 0); }, 0);
          var detalle = movs.map(function (m) {
            return '<span>' + h(m.fecha) + ' · ' + money(m.valor) + '</span>';
          }).join('');
          return '<div>' + movs.length + ' mov. · ' + money(total) + '</div><div class="col-wo-detalle">' + detalle + '</div>';
        }
      },
      {
        data: 'colocacion_pos', className: 'col-money',
        render: function (movs) {
          movs = movs || [];
          if (!movs.length) return '—';
          var total = movs.reduce(function (s, m) { return s + (parseFloat(m.valor) || 0); }, 0);
          var detalle = movs.map(function (m) {
            return '<span>' + h(m.fecha) + ' · ' + money(m.valor) + '</span>';
          }).join('');
          return '<div>' + movs.length + ' mov. · ' + money(total) + '</div><div class="col-wo-detalle">' + detalle + '</div>';
        }
      },
      {
        data: 'devoluciones', className: 'col-money',
        render: function (d) {
          d = parseFloat(d) || 0;
          return d ? '<span style="color:#dc2626">- ' + money(d) + '</span>' : '—';
        }
      },
      { data: 'total_colocado', className: 'col-money', render: function (d) { return '<strong>' + money(d) + '</strong>'; } },
    ],
    language: {
      lengthMenu: "Mostrar _MENU_ registros",
      zeroRecords: "No se encontraron resultados",
      emptyTable: "No hay información para mostrar",
      info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
      infoEmpty: "Sin registros disponibles",
      infoFiltered: "(filtrado de _MAX_ registros)",
      search: "Buscar:",
      paginate: { first: "«", previous: "‹", next: "›", last: "»" }
    },
    footerCallback: function () {
      var api = this.api();
      var sum = function (col) {
        return api.column(col, { search: 'applied' }).data().reduce(function (a, b) { return a + (parseFloat(b) || 0); }, 0);
      };
      // Totales al pie, respetando el filtro/búsqueda activa de DataTables.
      var $tfoot = $(api.table().footer());
      if (!$tfoot.length) {
        $(api.table().container()).find('table').append('<tfoot class="col-tfoot"><tr>' +
          '<td colspan="2">Totales</td><td class="col-money"></td><td class="col-money"></td><td class="col-money"></td>' +
          '<td class="col-money"></td><td class="col-money"></td><td></td><td></td><td></td>' +
          '<td class="col-money"></td><td class="col-money"></td><td></td><td></td>' +
          '<td class="col-money"></td><td class="col-money"></td>' +
          '</tr></tfoot>');
      }
      var $celdas = $(api.table().container()).find('tfoot td.col-money');
      var valores = [sum(2), sum(3), sum(4), sum(5), sum(6), sum(10), sum(11), sum(14), sum(15)];
      var idxDevoluciones = 7;
      $celdas.each(function (i) {
        if (i === idxDevoluciones) {
          $(this).html(valores[i] ? '<strong style="color:#dc2626">- ' + money(valores[i]) + '</strong>' : '—');
        } else {
          $(this).html('<strong>' + money(valores[i]) + '</strong>');
        }
      });
    }
  });

  function poblarFiltro($select, valores) {
    var actual = $select.val();
    var unicos = Array.from(new Set(valores.filter(function (v) { return v; })));
    unicos.sort(function (a, b) { return a.localeCompare(b, 'es'); });
    var opciones = ['<option value="">' + $select.data('todos') + '</option>'].concat(unicos.map(function (v) {
      return '<option value="' + h(v) + '">' + h(v) + '</option>';
    }));
    $select.html(opciones.join(''));
    if (actual && unicos.indexOf(actual) !== -1) $select.val(actual);
  }

  var colegiosParaAsignar = [];
  var periodoInicializado = false;

  function actualizarEnlaceExcel() {
    var idPeriodo = $('#filtro-periodo').val();
    var href = 'php/colocacion_excel.php';
    if (idPeriodo) href += '?id_periodo=' + encodeURIComponent(idPeriodo);
    $('#link-excel-colocacion').attr('href', href);
  }

  function cargarTabla() {
    var idPeriodo = $('#filtro-periodo').val();
    var params = idPeriodo ? { id_periodo: idPeriodo } : {};
    $.getJSON('php/colocacion_tabla.php', params, function (resp) {
      if (!resp.success) return;
      tabla.clear().rows.add(resp.filas || []).draw();
      $('#col-periodo-txt').text(resp.periodo ? ' — periodo ' + resp.periodo : '');

      if (!periodoInicializado) {
        periodoInicializado = true;
        var $selPeriodo = $('#filtro-periodo');
        $selPeriodo.html((resp.periodos || []).map(function (p) {
          return '<option value="' + p.id + '">' + h(p.periodo) + ' (Calendario ' + h(p.calendario) + ')</option>';
        }).join(''));
        $selPeriodo.val(String(idPeriodo || (resp.periodos && resp.periodos[0] && resp.periodos[0].id) || ''));
      }
      actualizarEnlaceExcel();

      var filas = resp.filas || [];
      poblarFiltro($('#filtro-empresa').data('todos', 'Todas'), filas.map(function (f) { return f.empresa; }));
      poblarFiltro($('#filtro-cliente').data('todos', 'Todos'), filas.map(function (f) { return f.cliente; }));
      colegiosParaAsignar = filas.map(function (f) { return { id: f.id_colegio, colegio: f.colegio }; })
        .sort(function (a, b) { return a.colegio.localeCompare(b.colegio, 'es'); });

      var $panel = $('#panel-sin-cruzar');
      var $lista = $('#lista-sin-cruzar');
      var sinCruzar = resp.sinCruzar || [];
      if (sinCruzar.length) {
        var opcionesColegio = '<option value="">Seleccionar colegio...</option>' + colegiosParaAsignar.map(function (c) {
          return '<option value="' + c.id + '">' + h(c.colegio) + '</option>';
        }).join('');
        $lista.html(sinCruzar.map(function (d) {
          return '<div class="col-sincruzar-item" data-id-wo="' + h(d.id_wo) + '">' +
            '<strong>' + h(d.tipo_documento) + ' ' + h(d.numero) + '</strong> — ' + h(d.fecha) +
            '<div class="concepto">' + h(d.concepto) + '</div>' +
            (esAdmin ? (
              '<div class="col-sincruzar-asignar">' +
              '<select class="sincruzar-select">' + opcionesColegio + '</select>' +
              '<button type="button" class="btn-asignar">Asignar</button>' +
              '<span class="resultado"></span>' +
              '</div>'
            ) : '') +
            '</div>';
        }).join(''));
        $panel.show();
        if (esAdmin) {
          $('.sincruzar-select').select2({
            placeholder: 'Selecciona o escribe un colegio...',
            allowClear: true,
            width: '100%',
            language: {
              noResults: function () { return 'Sin resultados'; },
              searching: function () { return 'Buscando...'; }
            }
          });
        }
      } else {
        $panel.hide();
      }
    });
  }
  cargarTabla();

  $('#filtro-periodo').on('change', function () {
    actualizarEnlaceExcel();
    cargarTabla();
  });
  $('#filtro-empresa').on('change', function () {
    var v = this.value;
    tabla.column(0).search(v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : '', true, false).draw();
  });
  $('#filtro-cliente').on('change', function () {
    var v = this.value;
    tabla.column(9).search(v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : '', true, false).draw();
  });

  $('#lista-sin-cruzar').on('click', '.btn-asignar', function () {
    var $btn = $(this);
    var $item = $btn.closest('.col-sincruzar-item');
    var $select = $item.find('.sincruzar-select');
    var $resultado = $item.find('.resultado');
    var idWo = $item.data('id-wo');
    var idColegio = $select.val();
    if (!idColegio) {
      $resultado.attr('class', 'err').text('Selecciona un colegio.');
      return;
    }
    $btn.prop('disabled', true);
    $resultado.attr('class', '').text('Guardando...');
    $.post('php/colocacion_asignar_colegio.php', { id_wo: idWo, id_colegio: idColegio })
      .done(function (resp) {
        if (resp && resp.success) {
          $resultado.attr('class', 'ok').text('Asignado.');
          cargarTabla();
        } else {
          $btn.prop('disabled', false);
          $resultado.attr('class', 'err').text((resp && resp.message) || 'Error al asignar.');
        }
      })
      .fail(function () {
        $btn.prop('disabled', false);
        $resultado.attr('class', 'err').text('Error de red.');
      });
  });

  if (!esAdmin) return;

  // ── Sección 1: sincronización con World Office (solo admin) ──
  // REM/FV vienen de listarDocumentoSalidaAlmacen (api.worldoffice.cloud, ver
  // php/sync_colocacion_wo.php); DREM/POS vienen del microservicio de ventas
  // (ver php/sync_colocacion_wo_ventas.php) — mismo token, endpoint distinto.
  var TIPOS = [
    { codigo: 'REM',  endpoint: 'php/sync_colocacion_wo.php',        etiqueta: 'Remisiones (CEUR)' },
    { codigo: 'FV',   endpoint: 'php/sync_colocacion_wo.php',        etiqueta: 'Facturas de venta' },
    { codigo: 'DREM', endpoint: 'php/sync_colocacion_wo_ventas.php', etiqueta: 'Devoluciones de venta' },
    { codigo: 'POS',  endpoint: 'php/sync_colocacion_wo_ventas.php', etiqueta: 'Facturas POS' },
  ];
  var tipoIdx = 0;
  var corriendo = false;
  var pagina = 0;
  var totalPaginas = null;
  var revisados = 0;
  var emparejados = 0;
  var sinCruzar = 0;

  var btnIniciar = document.getElementById('btn-iniciar');
  var btnDetener = document.getElementById('btn-detener');
  var progreso = document.getElementById('col-progreso');
  var progresoTxt = document.getElementById('col-progreso-txt');
  var estado = document.getElementById('col-estado');

  function actualizarResumen() {
    document.getElementById('col-revisados').textContent = revisados.toLocaleString('es-CO');
    document.getElementById('col-emparejados').textContent = emparejados.toLocaleString('es-CO');
    document.getElementById('col-sincruzar').textContent = sinCruzar.toLocaleString('es-CO');
    var pct = totalPaginas ? Math.min(100, Math.round((pagina / totalPaginas) * 100)) : 0;
    progreso.style.width = pct + '%';
    progresoTxt.textContent = totalPaginas != null
      ? TIPOS[tipoIdx].etiqueta + ' — página ' + pagina + ' de ' + totalPaginas
      : 'Cargando...';
  }

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'col-estado visible error';
  }

  function siguientePagina() {
    if (!corriendo) return;
    var tipo = TIPOS[tipoIdx];
    fetch(tipo.endpoint + '?tipoDocumento=' + tipo.codigo + '&pagina=' + pagina + '&porPagina=20')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          mostrarError('Error consultando World Office: ' + (data.message || 'desconocido'));
          detener();
          return;
        }
        totalPaginas = data.totalPaginas;
        revisados += data.revisadosEnPagina;
        emparejados += (data.resumen && data.resumen.emparejados) || 0;
        sinCruzar += (data.resumen && data.resumen.sinCruzar) || 0;
        if (data.errores && data.errores.length) {
          estado.textContent = data.errores.length + ' documento(s) no se pudieron consultar en esta página.';
          estado.className = 'col-estado visible';
        }
        actualizarResumen();

        pagina++;
        if (corriendo && totalPaginas != null && pagina < totalPaginas) {
          siguientePagina();
        } else if (tipoIdx < TIPOS.length - 1) {
          tipoIdx++;
          pagina = 0;
          totalPaginas = null;
          siguientePagina();
        } else {
          detener();
          progreso.style.width = '100%';
          progresoTxt.textContent = 'Sincronización completa — ' + revisados.toLocaleString('es-CO') + ' documentos revisados.';
          cargarTabla();
        }
      })
      .catch(function (err) {
        mostrarError('Error de red: ' + err.message);
        detener();
      });
  }

  function iniciar() {
    corriendo = true;
    tipoIdx = 0; pagina = 0; totalPaginas = null;
    revisados = 0; emparejados = 0; sinCruzar = 0;
    estado.className = 'col-estado';
    btnIniciar.disabled = true;
    btnDetener.style.display = '';
    actualizarResumen();
    siguientePagina();
  }

  function detener() {
    corriendo = false;
    btnIniciar.disabled = false;
    btnDetener.style.display = 'none';
  }

  btnIniciar.addEventListener('click', iniciar);
  btnDetener.addEventListener('click', detener);
})();
</script>
</body>
</html>
