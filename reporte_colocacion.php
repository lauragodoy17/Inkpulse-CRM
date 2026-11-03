<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}
$es_admin = $tipo_sesion === 1;

// El export "por usuario" (antes en reporte_colocacion_usuario.php, ahora fusionado acá para que
// tipo=1 tenga un único archivo de Colocación — ver memory/project_colocacion_modulo.md) solo lo
// necesita el admin: tipo=2 sigue usando reporte_colocacion_usuario.php sin cambios.
if ($es_admin) {
    require_once("conexion/bdd.php");
    $sqlUsuariosColocacion = "SELECT id, CONCAT(nombres, ' ', apellidos) as promotor FROM usuarios WHERE (tipo=3 || tipo=6 || tipo=1 || tipo=10) AND act=1";
    $usuariosColocacion = $bdd->query($sqlUsuariosColocacion)->fetchAll(PDO::FETCH_ASSOC);
}
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

    .sc-buscar { max-width: 420px; margin-bottom: 14px; }
    .sc-estado { font-size: .8rem; color: #16a34a; margin-bottom: 10px; }
    .sc-tipo { border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 10px; }
    .sc-tipo > summary { padding: 12px 16px; font-size: .88rem; font-weight: 700; color: #0f172a; cursor: pointer; }
    .sc-tipo > .sc-cuerpo { padding: 0 16px 12px; }
    .sc-grupo { border-top: 1px solid #f1f5f9; }
    .sc-grupo > summary { padding: 9px 4px; font-size: .82rem; font-weight: 600; color: #334155; cursor: pointer; }
    .sc-grupo > .sc-cuerpo { padding: 2px 4px 12px 18px; }
    .sc-cuenta { font-weight: 400; color: #64748b; margin-left: 6px; }
    .sc-doc { display: flex; gap: 8px; border-bottom: 1px solid #f1f5f9; padding: 7px 0; font-size: .78rem; margin: 0; font-weight: 400; }
    .sc-doc input { margin-top: 2px; flex-shrink: 0; }
    .sc-doc .concepto { color: #64748b; }
    .sc-enlace { background: none; border: none; padding: 0; color: #1d4ed8; font-size: .76rem; cursor: pointer; }
    .sc-ver-mas { margin-top: 8px; }
    .col-sincruzar-asignar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 4px 0 8px; }
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
            <div class="title"><h4>Colocación</h4></div>
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

          <div style="margin-top:18px;">
            <label class="d-block" for="filtro-periodo" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Periodo a consultar</label>
            <select id="filtro-periodo" class="form-control form-control-sm" style="width:auto; min-width:220px;"></select>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($es_admin): ?>
      <div class="col-section">
        <div class="col-section-head">
          <div class="col-num">2</div>
          <p class="col-section-title">Exportar por usuario<span class="col-periodo-txt"></span></p>
        </div>
        <div class="col-section-body">
          <form action="php/colocacion_excel_usuario.php" method="POST" style="display:flex; gap:16px; flex-wrap:wrap; align-items:end;">
            <div>
              <label class="d-block" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Usuario <small style="color:red;">*</small></label>
              <select name="usuario" class="form-control form-control-sm custom-select2" required style="min-width:260px;">
                <option value="">Seleccionar</option>
                <option value="0">Todos</option>
                <?php foreach ($usuariosColocacion as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['promotor']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <input type="hidden" name="periodo" id="export-periodo">
            <div>
              <button class="col-btn" style="padding:8px 18px;"><i class="bi bi-download"></i> Exportar Excel</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-section">
        <div class="col-section-head" style="justify-content: space-between;">
          <div style="display:flex; align-items:center; gap:14px;">
            <div class="col-num"><?= $es_admin ? '3' : '1' ?></div>
            <p class="col-section-title">Reporte de colocación<span class="col-periodo-txt"></span></p>
          </div>
          <a href="php/colocacion_excel.php" id="link-excel-colocacion" class="col-btn" style="padding:8px 18px;">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
          </a>
        </div>
        <div class="col-section-body">
          <div class="col-filtros" style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
            <?php if (!$es_admin): // tipo=2 no ve la sección de World Office, donde está el selector único ?>
            <div>
              <label class="d-block" for="filtro-periodo" style="font-size:.75rem; color:#64748b; margin-bottom:4px;">Periodo a consultar</label>
              <select id="filtro-periodo" class="form-control form-control-sm" style="min-width:140px;"></select>
            </div>
            <?php endif; ?>
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
          <p class="col-section-title">Documentos de World Office sin cruzar<span class="sc-cuenta" id="sc-total"></span></p>
        </div>
        <div class="col-section-body">
          <p class="col-aviso">
            Están agrupados por tipo de documento y por el nombre que trae el concepto. Al asignar un nombre
            a un colegio, los documentos futuros con ese mismo nombre se cruzan solos.
          </p>
          <input type="search" id="sc-buscar" class="form-control form-control-sm sc-buscar" placeholder="Buscar por nombre, concepto, número o tercero...">
          <div class="sc-estado" id="sc-estado"></div>
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
  // Render compartido para columnas de movimientos individuales (Factura de Venta, Colocación
  // World Office, Facturas POS, Devoluciones) — cada movimiento muestra tipo + número de
  // documento (ej. "POS #12503") junto a fecha y valor, a pedido del usuario 2026-09-02.
  function renderMovs(movs, negativo) {
    movs = movs || [];
    if (!movs.length) return '—';
    var total = movs.reduce(function (s, m) { return s + (parseFloat(m.valor) || 0); }, 0);
    var signo = negativo ? '- ' : '';
    var detalle = movs.map(function (m) {
      return '<span>' + h(m.tipo) + ' #' + h(m.numero) + ' · ' + h(m.fecha) + ' · ' + signo + money(m.valor) + '</span>';
    }).join('');
    var resumen = movs.length + ' mov. · ' + signo + money(total);
    if (negativo && total) resumen = '<span style="color:#dc2626">' + resumen + '</span>';
    return '<div>' + resumen + '</div><div class="col-wo-detalle">' + detalle + '</div>';
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
      { data: 'factura_venta_mov', className: 'col-money', render: function (movs) { return renderMovs(movs, false); } },
      { data: 'abonos_mov', className: 'col-money', render: function (movs) { return renderMovs(movs, false); } },
      { data: 'colocacion_wo', className: 'col-money', render: function (movs) { return renderMovs(movs, false); } },
      { data: 'colocacion_pos', className: 'col-money', render: function (movs) { return renderMovs(movs, false); } },
      { data: 'devoluciones_mov', className: 'col-money', render: function (movs) { return renderMovs(movs, true); } },
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
      // Factura de Venta (10) y Devoluciones (14) ahora traen un arreglo de movimientos en vez de
      // un total escalar — sumar el 'valor' de cada movimiento en vez de parseFloat directo.
      var sumMovs = function (col) {
        return api.column(col, { search: 'applied' }).data().reduce(function (a, movs) {
          return a + (movs || []).reduce(function (s, m) { return s + (parseFloat(m.valor) || 0); }, 0);
        }, 0);
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
      var valores = [sum(2), sum(3), sum(4), sum(5), sum(6), sumMovs(10), sumMovs(11), sumMovs(14), sum(15)];
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

  var periodoInicializado = false;

  function actualizarEnlaceExcel() {
    var idPeriodo = $('#filtro-periodo').val();
    var href = 'php/colocacion_excel.php';
    if (idPeriodo) href += '?id_periodo=' + encodeURIComponent(idPeriodo);
    $('#link-excel-colocacion').attr('href', href);
    $('#export-periodo').val(idPeriodo || '');
  }

  // Solo refresca la tabla principal (para reflejar los nuevos valores tras asignar un colegio a
  // un documento sin cruzar) sin tocar el panel "sin cruzar" ni reiniciar la paginación — evitar
  // reconstruir TODO el panel (que reinicializa select2 en cada fila restante y da sensación de
  // "recargar la página") cada vez que se asigna un solo documento.
  function refrescarSoloTabla() {
    var idPeriodo = $('#filtro-periodo').val();
    var params = idPeriodo ? { id_periodo: idPeriodo } : {};
    $.getJSON('php/colocacion_tabla.php', params, function (resp) {
      if (!resp.success) return;
      tabla.clear().rows.add(resp.filas || []).draw(false);
      var filas = resp.filas || [];
      poblarFiltro($('#filtro-empresa').data('todos', 'Todas'), filas.map(function (f) { return f.empresa; }));
      poblarFiltro($('#filtro-cliente').data('todos', 'Todos'), filas.map(function (f) { return f.cliente; }));
    });
  }

  function cargarTabla() {
    var idPeriodo = $('#filtro-periodo').val();
    var params = idPeriodo ? { id_periodo: idPeriodo } : {};
    $.getJSON('php/colocacion_tabla.php', params, function (resp) {
      if (!resp.success) return;
      tabla.clear().rows.add(resp.filas || []).draw();
      $('.col-periodo-txt').text(resp.periodo ? ' — periodo ' + resp.periodo : '');

      if (!periodoInicializado) {
        periodoInicializado = true;
        var $selPeriodo = $('#filtro-periodo');
        $selPeriodo.html((resp.periodos || []).map(function (p) {
          return '<option value="' + p.id + '">' + h(p.periodo) + ' (Calendario ' + h(p.calendario) + ')</option>';
        }).join(''));
        $selPeriodo.val(String(resp.id_periodo || idPeriodo || ''));
      }
      actualizarEnlaceExcel();

      var filas = resp.filas || [];
      poblarFiltro($('#filtro-empresa').data('todos', 'Todas'), filas.map(function (f) { return f.empresa; }));
      poblarFiltro($('#filtro-cliente').data('todos', 'Todos'), filas.map(function (f) { return f.cliente; }));
    });
  }
  cargarTabla();

  // ── Documentos sin cruzar: tipo de documento → nombre del concepto → documentos. Se despliega por
  // niveles y solo se dibuja lo que se abre, porque pueden ser miles. No depende del período y
  // conserva lo que estaba abierto al recargar (p. ej. después de sincronizar). ──
  var TIPOS_SIN_CRUZAR = {
    REM: 'Remisiones (CEUR)', FV: 'Facturas de venta', DREM: 'Devoluciones de venta',
    NCV: 'Notas crédito de venta', POS: 'Facturas POS', RC: 'Recibos de caja (Abonos)'
  };
  var DOCS_POR_PAGINA = 50;
  var docsSinCruzar = [];
  var gruposSinCruzar = [];
  var abiertosSinCruzar = {};

  function claveDocumento(d) { return d.tipo_documento + '|' + d.id_wo; }
  function claveNombre(texto) {
    return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
  }
  function plural(n, uno, varios) { return n.toLocaleString('es-CO') + ' ' + (n === 1 ? uno : varios); }

  function construirGrupos() {
    var busqueda = claveNombre($('#sc-buscar').val());
    var indice = {};
    gruposSinCruzar = [];
    docsSinCruzar.forEach(function (d) {
      if (busqueda && claveNombre([d.colegio_extraido, d.concepto, d.numero, d.tercero_externo_nombre].join(' ')).indexOf(busqueda) === -1) return;
      var nombre = claveNombre(d.colegio_extraido);
      var id = d.tipo_documento + '|' + nombre;
      if (!indice[id]) {
        // Un grupo con nombre es casi siempre un solo colegio: arranca todo seleccionado. Los que
        // no traen nombre pueden ser de colegios distintos: se eligen uno a uno.
        indice[id] = { id: id, tipo: d.tipo_documento, nombre: nombre, docs: [], seleccion: {}, mostrados: DOCS_POR_PAGINA };
        gruposSinCruzar.push(indice[id]);
      }
      indice[id].docs.push(d);
      if (nombre) indice[id].seleccion[claveDocumento(d)] = true;
    });
    gruposSinCruzar.sort(function (a, b) {
      if (!a.nombre !== !b.nombre) return a.nombre ? -1 : 1;
      return b.docs.length - a.docs.length || a.nombre.localeCompare(b.nombre);
    });
  }

  function dibujarSinCruzar() {
    construirGrupos();
    var hayBusqueda = !!$('#sc-buscar').val();
    $('#panel-sin-cruzar').toggle(docsSinCruzar.length > 0);
    $('#sc-total').text('(' + docsSinCruzar.length.toLocaleString('es-CO') + ')');
    var html = Object.keys(TIPOS_SIN_CRUZAR).map(function (tipo) {
      var grupos = gruposSinCruzar.filter(function (g) { return g.tipo === tipo; });
      if (!grupos.length) return '';
      var total = grupos.reduce(function (s, g) { return s + g.docs.length; }, 0);
      var abierto = hayBusqueda || abiertosSinCruzar[tipo];
      return '<details class="sc-tipo" data-tipo="' + h(tipo) + '"' + (abierto ? ' open' : '') + '>' +
        '<summary>' + h(TIPOS_SIN_CRUZAR[tipo]) + '<span class="sc-cuenta">' + plural(total, 'documento', 'documentos') + '</span></summary>' +
        '<div class="sc-cuerpo"></div></details>';
    }).join('');
    $('#lista-sin-cruzar').html(html || '<p class="col-progreso-txt">Sin resultados.</p>');
    $('#lista-sin-cruzar .sc-tipo[open]').each(function () { llenarTipo($(this)); });
  }

  function llenarTipo($tipo) {
    var $cuerpo = $tipo.children('.sc-cuerpo');
    if ($cuerpo.children().length) return;
    var tipo = $tipo.data('tipo');
    var html = '';
    gruposSinCruzar.forEach(function (g, i) {
      if (g.tipo !== tipo) return;
      html += '<details class="sc-grupo" data-grupo="' + i + '"' + (abiertosSinCruzar[g.id] ? ' open' : '') + '>' +
        '<summary>' + h(g.nombre || 'Sin nombre de colegio en el concepto') +
        '<span class="sc-cuenta">' + plural(g.docs.length, 'documento', 'documentos') + '</span></summary>' +
        '<div class="sc-cuerpo"></div></details>';
    });
    $cuerpo.html(html);
    $cuerpo.children('.sc-grupo[open]').each(function () { llenarGrupo($(this)); });
  }

  function filaDocumento(d, g) {
    var clave = claveDocumento(d);
    var marcado = g.seleccion[clave] ? ' checked' : '';
    var texto = '<div><strong>' + h(d.tipo_documento + ' ' + (d.prefijo || '') + ' ' + d.numero) + '</strong> — ' + h(d.fecha) +
      (d.tercero_externo_nombre ? ' — ' + h(d.tercero_externo_nombre) : '') +
      ' — $' + Number(d.valor_neto || 0).toLocaleString('es-CO') +
      '<div class="concepto">' + h(d.concepto) + '</div></div>';
    return esAdmin
      ? '<label class="sc-doc"><input type="checkbox" class="sc-check" data-clave="' + h(clave) + '"' + marcado + '>' + texto + '</label>'
      : '<div class="sc-doc">' + texto + '</div>';
  }

  function textoBotonAsignar(g) {
    return 'Asignar ' + plural(Object.keys(g.seleccion).length, 'documento', 'documentos');
  }

  function llenarGrupo($grupo) {
    var $cuerpo = $grupo.children('.sc-cuerpo');
    if ($cuerpo.children().length) return;
    var g = gruposSinCruzar[$grupo.data('grupo')];
    var controles = esAdmin ? (
      '<div class="col-sincruzar-asignar">' +
      '<select class="sincruzar-select"><option></option></select>' +
      '<button type="button" class="btn-asignar">' + h(textoBotonAsignar(g)) + '</button>' +
      '<button type="button" class="sc-enlace sc-todos">Seleccionar o quitar todos</button>' +
      '<span class="resultado"></span>' +
      '</div>'
    ) : '';
    $cuerpo.html(controles + '<div class="sc-docs"></div>');
    dibujarDocumentos($grupo, g);
    if (!esAdmin) return;
    // Búsqueda por AJAX y un solo <select> por grupo abierto, en vez de precargar los ~3.840 colegios
    // en cada documento — eso colgaba el navegador con miles de documentos sin cruzar.
    $cuerpo.find('.sincruzar-select').select2({
      placeholder: 'Escribe para buscar un colegio por nombre o DANE...',
      allowClear: true,
      width: '360px',
      minimumInputLength: 2,
      ajax: {
        url: 'php/colocacion_buscar_colegio.php',
        dataType: 'json',
        delay: 250,
        data: function (params) { return { q: params.term }; },
        processResults: function (data) {
          return { results: (data || []).map(function (c) {
            return { id: c.id, text: c.colegio + (c.dane ? ' — DANE: ' + c.dane : '') };
          }) };
        }
      },
      language: {
        inputTooShort: function () { return 'Escribe al menos 2 letras...'; },
        noResults: function () { return 'Sin resultados'; },
        searching: function () { return 'Buscando...'; }
      }
    });
  }

  function dibujarDocumentos($grupo, g) {
    var restantes = g.docs.length - g.mostrados;
    $grupo.find('> .sc-cuerpo > .sc-docs').html(
      g.docs.slice(0, g.mostrados).map(function (d) { return filaDocumento(d, g); }).join('') +
      (restantes > 0 ? '<div class="sc-ver-mas"><button type="button" class="sc-enlace sc-mas">Ver ' + plural(Math.min(restantes, DOCS_POR_PAGINA), 'más', 'más') + ' de ' + restantes.toLocaleString('es-CO') + '</button></div>' : '')
    );
  }

  function cargarSinCruzar() {
    $.getJSON('php/colocacion_sin_cruzar.php', function (resp) {
      if (!resp.success) return;
      docsSinCruzar = resp.documentos || [];
      dibujarSinCruzar();
    });
  }
  cargarSinCruzar();

  // "toggle" no burbujea: se escucha en captura para atender todos los <details> del panel.
  document.getElementById('lista-sin-cruzar').addEventListener('toggle', function (e) {
    var $d = $(e.target);
    if ($d.hasClass('sc-tipo')) {
      abiertosSinCruzar[$d.data('tipo')] = e.target.open;
      if (e.target.open) llenarTipo($d);
    } else if ($d.hasClass('sc-grupo')) {
      abiertosSinCruzar[gruposSinCruzar[$d.data('grupo')].id] = e.target.open;
      if (e.target.open) llenarGrupo($d);
    }
  }, true);

  var temporizadorBusqueda = null;
  $('#sc-buscar').on('input', function () {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(dibujarSinCruzar, 250);
  });

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

  $('#lista-sin-cruzar').on('click', '.sc-mas', function () {
    var $grupo = $(this).closest('.sc-grupo');
    var g = gruposSinCruzar[$grupo.data('grupo')];
    g.mostrados += DOCS_POR_PAGINA;
    dibujarDocumentos($grupo, g);
  });

  $('#lista-sin-cruzar').on('change', '.sc-check', function () {
    var $grupo = $(this).closest('.sc-grupo');
    var g = gruposSinCruzar[$grupo.data('grupo')];
    if (this.checked) g.seleccion[$(this).data('clave')] = true;
    else delete g.seleccion[$(this).data('clave')];
    $grupo.find('> .sc-cuerpo .btn-asignar').text(textoBotonAsignar(g));
  });

  $('#lista-sin-cruzar').on('click', '.sc-todos', function () {
    var $grupo = $(this).closest('.sc-grupo');
    var g = gruposSinCruzar[$grupo.data('grupo')];
    var todos = Object.keys(g.seleccion).length === g.docs.length;
    g.seleccion = {};
    if (!todos) g.docs.forEach(function (d) { g.seleccion[claveDocumento(d)] = true; });
    dibujarDocumentos($grupo, g);
    $grupo.find('> .sc-cuerpo .btn-asignar').text(textoBotonAsignar(g));
  });

  $('#lista-sin-cruzar').on('click', '.btn-asignar', function () {
    var $btn = $(this);
    var $grupo = $btn.closest('.sc-grupo');
    var g = gruposSinCruzar[$grupo.data('grupo')];
    var $select = $grupo.find('.sincruzar-select');
    var $resultado = $grupo.find('.resultado');
    var idColegio = $select.val();
    var claves = Object.keys(g.seleccion);
    if (!idColegio) {
      $resultado.attr('class', 'err').text('Selecciona un colegio.');
      return;
    }
    if (!claves.length) {
      $resultado.attr('class', 'err').text('Selecciona al menos un documento.');
      return;
    }
    var nombreColegio = $select.find('option:selected').text();
    var documentos = g.docs.filter(function (d) { return g.seleccion[claveDocumento(d)]; })
      .map(function (d) { return { id_wo: d.id_wo, tipo_documento: d.tipo_documento }; });
    $btn.prop('disabled', true);
    $resultado.attr('class', '').text('Guardando...');
    $.post('php/colocacion_asignar_colegio.php', { documentos: JSON.stringify(documentos), id_colegio: idColegio })
      .done(function (resp) {
        if (resp && resp.success) {
          docsSinCruzar = docsSinCruzar.filter(function (d) { return !g.seleccion[claveDocumento(d)]; });
          $('#sc-estado').text(plural(resp.asignados, 'documento asignado', 'documentos asignados') + ' a ' + nombreColegio + '.');
          dibujarSinCruzar();
          var $sc = $('#col-sincruzar'), $em = $('#col-emparejados');
          var numSc = (parseInt($sc.text().replace(/\D/g, ''), 10) || 0) - resp.asignados;
          var numEm = (parseInt($em.text().replace(/\D/g, ''), 10) || 0) + resp.asignados;
          $sc.text(Math.max(0, numSc).toLocaleString('es-CO'));
          $em.text(numEm.toLocaleString('es-CO'));
          refrescarSoloTabla();
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
  // Una llamada por tipo a php/sync_colocacion_wo_rapido.php, que trae el listado completo y solo
  // escribe lo nuevo o modificado; si quedan renglones por traer ("faltan"), se vuelve a llamar.
  var TIPOS = [
    { codigo: 'REM',  etiqueta: 'Remisiones (CEUR)' },
    { codigo: 'FV',   etiqueta: 'Facturas de venta' },
    { codigo: 'DREM', etiqueta: 'Devoluciones de venta' },
    { codigo: 'NCV',  etiqueta: 'Notas crédito de venta' },
    { codigo: 'POS',  etiqueta: 'Facturas POS' },
    { codigo: 'RC',   etiqueta: 'Recibos de caja (Abonos)' },
  ];
  var corriendo = false;
  var total = {};

  var btnIniciar = document.getElementById('btn-iniciar');
  var btnDetener = document.getElementById('btn-detener');
  var progreso = document.getElementById('col-progreso');
  var progresoTxt = document.getElementById('col-progreso-txt');
  var estado = document.getElementById('col-estado');

  function actualizarResumen() {
    document.getElementById('col-revisados').textContent = total.documentos.toLocaleString('es-CO');
    document.getElementById('col-emparejados').textContent = total.emparejados.toLocaleString('es-CO');
    document.getElementById('col-sincruzar').textContent = total.sinCruzar.toLocaleString('es-CO');
  }

  function textoResumen() {
    return total.documentos.toLocaleString('es-CO') + ' documentos revisados — ' +
      total.nuevos + ' nuevos, ' + total.modificados + ' modificados, ' +
      total.anulados + ' anulados, ' + total.eliminados + ' eliminados' +
      (total.errores ? ', ' + total.errores + ' con error (se reintentan la próxima vez)' : '');
  }

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'col-estado visible error';
  }

  function sincronizarTipo(idx, intento) {
    if (!corriendo) {
      progresoTxt.textContent = 'Detenido — ' + textoResumen() + '.';
      cargarTabla();
      cargarSinCruzar();
      return;
    }
    if (idx >= TIPOS.length) {
      detener();
      progreso.style.width = '100%';
      progresoTxt.textContent = 'Sincronización completa — ' + textoResumen() + '.';
      cargarTabla();
      cargarSinCruzar();
      return;
    }
    var tipo = TIPOS[idx];
    progresoTxt.textContent = 'Revisando ' + tipo.etiqueta + ' en World Office...';
    fetch('php/sync_colocacion_wo_rapido.php?tipoDocumento=' + tipo.codigo)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          mostrarError('Error consultando World Office: ' + (data.message || 'desconocido'));
          detener();
          cargarTabla();
          cargarSinCruzar();
          return;
        }
        var r = data.resumen || {};
        // Al reintentar por "faltan" el listado se recorre de nuevo: no se vuelve a contar.
        if (intento === 0) {
          total.documentos += r.documentos || 0;
          total.emparejados += r.emparejados || 0;
          total.sinCruzar += r.sinCruzar || 0;
        }
        ['nuevos', 'modificados', 'anulados', 'eliminados', 'errores'].forEach(function (k) {
          total[k] += r[k] || 0;
        });
        actualizarResumen();

        // Si una llamada no guardó nada, reintentar daría lo mismo: se sigue con el siguiente tipo.
        if (r.faltan > 0 && (r.nuevos || r.modificados)) {
          progresoTxt.textContent = tipo.etiqueta + ': faltan ' + r.faltan + ' documento(s) por descargar...';
          sincronizarTipo(idx, intento + 1);
          return;
        }
        progreso.style.width = Math.round(((idx + 1) / TIPOS.length) * 100) + '%';
        sincronizarTipo(idx + 1, 0);
      })
      .catch(function (err) {
        mostrarError('Error de red: ' + err.message);
        detener();
      });
  }

  function iniciar() {
    corriendo = true;
    total = { documentos: 0, emparejados: 0, sinCruzar: 0, nuevos: 0, modificados: 0, anulados: 0, eliminados: 0, errores: 0 };
    estado.className = 'col-estado';
    progreso.style.width = '0%';
    btnIniciar.disabled = true;
    btnDetener.style.display = '';
    actualizarResumen();
    sincronizarTipo(0, 0);
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
