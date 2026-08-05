<?php
require_once("php/aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Clasificar libros por bodega</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .lb-section {
      background: #fff; border-radius: 14px;
      box-shadow: 0 2px 10px rgba(15,23,42,.08);
      margin-bottom: 20px; overflow: hidden;
    }
    .lb-section-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px; border-bottom: 1px solid #e2e8f0;
    }
    .lb-num {
      width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; font-size: .85rem; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .lb-section-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .lb-section-body  { padding: 24px; }

    .lb-aviso {
      background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
      border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin: 0 0 18px;
    }

    .lb-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 26px; border-radius: 8px; font-size: .95rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .lb-btn:hover { opacity: .9; transform: translateY(-1px); }
    .lb-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .lb-btn-stop {
      background: #fff; color: #dc2626; border: 1.5px solid #fecaca;
    }
    .lb-btn-stop:hover { background: #fef2f2; }

    .lb-resumen { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 18px; }
    .lb-tarjeta { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 20px; min-width: 150px; }
    .lb-tarjeta .num { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
    .lb-tarjeta .lbl { font-size: .78rem; color: #64748b; }
    .lb-tarjeta.t1 .num { color: #2563eb; }
    .lb-tarjeta.t4 .num { color: #d97706; }
    .lb-tarjeta.t3 .num { color: #16a34a; }
    .lb-tarjeta.t0 .num { color: #94a3b8; }

    .lb-progreso-wrap { margin-top: 16px; background: #f1f5f9; border-radius: 8px; height: 8px; overflow: hidden; }
    .lb-progreso { height: 100%; background: linear-gradient(135deg, #1d4ed8, #2563eb); width: 0%; transition: width .2s; }
    .lb-progreso-txt { font-size: .78rem; color: #64748b; margin-top: 6px; }

    .lb-estado { font-size: .82rem; color: #64748b; margin-top: 10px; display: none; }
    .lb-estado.visible { display: block; }
    .lb-estado.error { color: #dc2626; }

    table.lb-tabla { width: 100%; border-collapse: collapse; margin-top: 20px; }
    table.lb-tabla th, table.lb-tabla td { text-align: left; padding: 9px 12px; font-size: .82rem; border-bottom: 1px solid #f1f5f9; }
    table.lb-tabla th { background: #0f172a; color: #fff; font-weight: 600; position: sticky; top: 0; }
    table.lb-tabla tr:hover td { background: #f9fafb; }
    .lb-tabla-wrap { max-height: 520px; overflow-y: auto; border: 1px solid #f1f5f9; border-radius: 10px; }
    .lb-vacio { text-align: center; color: #9ca3af; padding: 20px; font-size: .85rem; }
    .lb-badge { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: .74rem; font-weight: 700; }
    .lb-badge.t1 { background: #dbeafe; color: #1d4ed8; }
    .lb-badge.t4 { background: #fef3c7; color: #92400e; }
    .lb-badge.t3 { background: #dcfce7; color: #166534; }
    .lb-badge.t0 { background: #f1f5f9; color: #64748b; }
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
            <div class="title"><h4>Clasificar libros por bodega</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="libros.php">Libros</a></li>
                <li class="breadcrumb-item active" aria-current="page">Clasificar por bodega</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="lb-section">
        <div class="lb-section-head">
          <div class="lb-num">1</div>
          <p class="lb-section-title">Clasificar según existencias en World Office</p>
        </div>
        <div class="lb-section-body">
          <p class="lb-aviso">
            Esto SÍ escribe en la base de datos: por cada libro con id de World Office, consulta en qué bodega(s)
            tiene existencias (<code>GET /inventarios/{id}/existencias/bodega</code>) y actualiza <code>libros.tipo</code> —
            1 si solo está en bodega "General", 4 si solo está en "Muestras General", 3 si está en ambas, 0 si no está
            en ninguna de las dos. Son ~2.474 libros consultados uno por uno contra World Office, así que puede tardar
            varios minutos.
          </p>

          <button type="button" class="lb-btn" id="btn-iniciar"><i class="bi bi-arrow-repeat"></i> Clasificar libros</button>
          <button type="button" class="lb-btn lb-btn-stop" id="btn-detener" style="display:none;"><i class="bi bi-stop-circle"></i> Detener</button>

          <div class="lb-progreso-wrap"><div class="lb-progreso" id="lb-progreso"></div></div>
          <p class="lb-progreso-txt" id="lb-progreso-txt">Sin iniciar.</p>

          <div class="lb-estado" id="lb-estado"></div>

          <div class="lb-resumen">
            <div class="lb-tarjeta"><div class="num" id="lb-total">—</div><div class="lbl">Libros con id de WO</div></div>
            <div class="lb-tarjeta"><div class="num" id="lb-revisados">0</div><div class="lbl">Revisados</div></div>
            <div class="lb-tarjeta t1"><div class="num" id="lb-tipo1">0</div><div class="lbl">Solo General (tipo 1)</div></div>
            <div class="lb-tarjeta t4"><div class="num" id="lb-tipo4">0</div><div class="lbl">Solo Muestras General (tipo 4)</div></div>
            <div class="lb-tarjeta t3"><div class="num" id="lb-tipo3">0</div><div class="lbl">Ambas bodegas (tipo 3)</div></div>
            <div class="lb-tarjeta t0"><div class="num" id="lb-tipo0">0</div><div class="lbl">Ninguna de las dos (tipo 0)</div></div>
          </div>
        </div>
      </div>

      <div class="lb-section">
        <div class="lb-section-head">
          <div class="lb-num">2</div>
          <p class="lb-section-title">Detalle de lo clasificado</p>
        </div>
        <div class="lb-section-body">
          <div class="lb-tabla-wrap">
            <table class="lb-tabla">
              <thead>
                <tr>
                  <th>ISBN</th>
                  <th>Libro</th>
                  <th>Bodega General</th>
                  <th>Bodega Muestras General</th>
                  <th>Tipo asignado</th>
                </tr>
              </thead>
              <tbody id="lb-tbody"></tbody>
            </table>
          </div>
          <p class="lb-vacio" id="lb-vacio">Dale a "Clasificar libros" para ver resultados aquí.</p>
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
<script>
(function () {
  var POR_PAGINA = 20;
  var corriendo = false;
  var pagina = 0;
  var totalPaginas = null;
  var totalLibros = null;
  var revisados = 0;
  var resumen = { 1: 0, 3: 0, 4: 0, 0: 0 };

  var btnIniciar = document.getElementById('btn-iniciar');
  var btnDetener = document.getElementById('btn-detener');
  var progreso = document.getElementById('lb-progreso');
  var progresoTxt = document.getElementById('lb-progreso-txt');
  var estado = document.getElementById('lb-estado');
  var tbody = document.getElementById('lb-tbody');
  var vacio = document.getElementById('lb-vacio');

  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function badge(tipo) {
    var texto = { 1: 'General (1)', 4: 'Muestras General (4)', 3: 'Ambas (3)', 0: 'Ninguna (0)' }[tipo];
    return '<span class="lb-badge t' + tipo + '">' + texto + '</span>';
  }

  function actualizarResumen() {
    document.getElementById('lb-total').textContent = totalLibros != null ? totalLibros.toLocaleString('es-CO') : '—';
    document.getElementById('lb-revisados').textContent = revisados.toLocaleString('es-CO');
    document.getElementById('lb-tipo1').textContent = resumen[1].toLocaleString('es-CO');
    document.getElementById('lb-tipo4').textContent = resumen[4].toLocaleString('es-CO');
    document.getElementById('lb-tipo3').textContent = resumen[3].toLocaleString('es-CO');
    document.getElementById('lb-tipo0').textContent = resumen[0].toLocaleString('es-CO');
    var pct = totalPaginas ? Math.min(100, Math.round((pagina / totalPaginas) * 100)) : 0;
    progreso.style.width = pct + '%';
    progresoTxt.textContent = totalPaginas != null
      ? 'Página ' + pagina + ' de ' + totalPaginas + ' — ' + revisados.toLocaleString('es-CO') + ' de ' + (totalLibros ?? '?').toLocaleString('es-CO') + ' revisados'
      : 'Cargando...';
  }

  function agregarFilas(lista) {
    vacio.style.display = 'none';
    lista.forEach(function (p) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + h(p.isbn) + '</td>' +
        '<td>' + h(p.libro) + '</td>' +
        '<td>' + (p.enGeneral ? 'Sí' : 'No') + '</td>' +
        '<td>' + (p.enMuestras ? 'Sí' : 'No') + '</td>' +
        '<td>' + badge(p.tipoNuevo) + '</td>';
      tbody.appendChild(tr);
    });
  }

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'lb-estado visible error';
  }

  function siguientePagina() {
    if (!corriendo) return;
    fetch('php/clasificar_libros_bodega.php?pagina=' + pagina + '&porPagina=' + POR_PAGINA)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          mostrarError('Error consultando World Office: ' + (data.message || 'desconocido'));
          detener();
          return;
        }
        totalPaginas = data.totalPaginas;
        totalLibros = data.totalLibros;
        revisados += data.revisadosEnPagina;
        resumen[1] += data.resumen[1] || 0;
        resumen[4] += data.resumen[4] || 0;
        resumen[3] += data.resumen[3] || 0;
        resumen[0] += data.resumen[0] || 0;
        agregarFilas(data.procesados);
        if (data.errores && data.errores.length) {
          estado.textContent = data.errores.length + ' libro(s) no se pudieron consultar en esta página (sin cambios para esos).';
          estado.className = 'lb-estado visible';
        }
        actualizarResumen();

        pagina++;
        if (corriendo && totalPaginas != null && pagina < totalPaginas) {
          siguientePagina();
        } else {
          detener();
          progreso.style.width = '100%';
          progresoTxt.textContent = 'Clasificación completa — ' + revisados.toLocaleString('es-CO') + ' libros revisados.';
        }
      })
      .catch(function (err) {
        mostrarError('Error de red: ' + err.message);
        detener();
      });
  }

  function iniciar() {
    corriendo = true;
    pagina = 0; totalPaginas = null; totalLibros = null; revisados = 0;
    resumen = { 1: 0, 3: 0, 4: 0, 0: 0 };
    tbody.innerHTML = '';
    vacio.style.display = 'none';
    estado.className = 'lb-estado';
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
