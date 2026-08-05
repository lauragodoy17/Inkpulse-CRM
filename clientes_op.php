<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Comparar clientes</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .cp-section {
      background: #fff; border-radius: 14px;
      box-shadow: 0 2px 10px rgba(15,23,42,.08);
      margin-bottom: 20px; overflow: hidden;
    }
    .cp-section-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px; border-bottom: 1px solid #e2e8f0;
    }
    .cp-num {
      width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; font-size: .85rem; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .cp-section-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .cp-section-body  { padding: 24px; }

    .cp-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 26px; border-radius: 8px; font-size: .95rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .cp-btn:hover { opacity: .9; transform: translateY(-1px); }
    .cp-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .cp-btn-stop {
      background: #fff; color: #dc2626; border: 1.5px solid #fecaca;
    }
    .cp-btn-stop:hover { background: #fef2f2; }

    .cp-resumen { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 18px; }
    .cp-tarjeta { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 20px; min-width: 170px; }
    .cp-tarjeta .num { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
    .cp-tarjeta .lbl { font-size: .78rem; color: #64748b; }
    .cp-tarjeta.nuevo .num { color: #16a34a; }

    .cp-progreso-wrap { margin-top: 16px; background: #f1f5f9; border-radius: 8px; height: 8px; overflow: hidden; }
    .cp-progreso { height: 100%; background: linear-gradient(135deg, #1d4ed8, #2563eb); width: 0%; transition: width .2s; }
    .cp-progreso-txt { font-size: .78rem; color: #64748b; margin-top: 6px; }

    .cp-estado { font-size: .82rem; color: #64748b; margin-top: 10px; display: none; }
    .cp-estado.visible { display: block; }
    .cp-estado.error { color: #dc2626; }

    table.cp-tabla { width: 100%; border-collapse: collapse; margin-top: 20px; }
    table.cp-tabla th, table.cp-tabla td { text-align: left; padding: 9px 12px; font-size: .82rem; border-bottom: 1px solid #f1f5f9; }
    table.cp-tabla th { background: #0f172a; color: #fff; font-weight: 600; }
    table.cp-tabla th.chk, table.cp-tabla td.chk { width: 34px; text-align: center; }
    table.cp-tabla tr:hover td { background: #f9fafb; }
    table.cp-tabla tr.cp-fila-cruzada td { opacity: .45; text-decoration: line-through; }
    .cp-vacio { text-align: center; color: #9ca3af; padding: 20px; font-size: .85rem; }

    .cp-barra-tabla {
      display: flex; align-items: center; justify-content: space-between;
      gap: 14px; flex-wrap: wrap; margin-top: 4px;
    }
    .cp-buscador {
      position: relative; flex: 1; min-width: 240px; max-width: 360px;
    }
    .cp-buscador input {
      width: 100%; padding: 9px 12px 9px 34px; border: 1px solid #e2e8f0;
      border-radius: 8px; font-size: .85rem; box-sizing: border-box;
    }
    .cp-buscador i {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      color: #94a3b8; font-size: .85rem;
    }
    .cp-seleccion-info { font-size: .82rem; color: #64748b; }
    .cp-resultado-cruce { font-size: .82rem; margin-top: 14px; padding: 10px 14px; border-radius: 8px; display: none; }
    .cp-resultado-cruce.visible { display: block; }
    .cp-resultado-cruce.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .cp-resultado-cruce.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
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
            <div class="title"><h4>Comparar clientes</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="lista_op.php">OP</a></li>
                <li class="breadcrumb-item active" aria-current="page">Comparar clientes</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="cp-section">
        <div class="cp-section-head">
          <div class="cp-num">1</div>
          <p class="cp-section-title">Buscar clientes nuevos en World Office</p>
        </div>
        <div class="cp-section-body">
          <button type="button" class="cp-btn" id="btn-iniciar"><i class="bi bi-arrow-repeat"></i> Buscar clientes nuevos</button>
          <button type="button" class="cp-btn cp-btn-stop" id="btn-detener" style="display:none;"><i class="bi bi-stop-circle"></i> Detener</button>

          <div class="cp-progreso-wrap"><div class="cp-progreso" id="cp-progreso"></div></div>
          <p class="cp-progreso-txt" id="cp-progreso-txt">Sin iniciar.</p>

          <div class="cp-estado" id="cp-estado"></div>

          <div class="cp-resumen">
            <div class="cp-tarjeta"><div class="num" id="cp-piso">—</div><div class="lbl">No se buscan ids de WO menores o iguales a</div></div>
            <div class="cp-tarjeta"><div class="num" id="cp-revisados">0</div><div class="lbl">Revisados</div></div>
            <div class="cp-tarjeta nuevo"><div class="num" id="cp-nuevos">0</div><div class="lbl">Nuevos encontrados</div></div>
          </div>
        </div>
      </div>

      <div class="cp-section">
        <div class="cp-section-head">
          <div class="cp-num">2</div>
          <p class="cp-section-title">Clientes nuevos por cruzar</p>
        </div>
        <div class="cp-section-body">
          <div class="cp-barra-tabla">
            <div class="cp-buscador">
              <i class="bi bi-search"></i>
              <input type="text" id="cp-buscar" placeholder="Buscar por cliente o identificación...">
            </div>
            <div class="cp-seleccion-info"><span id="cp-seleccionados">0</span> seleccionado(s)</div>
            <button type="button" class="cp-btn" id="btn-cruzar" disabled><i class="bi bi-check2-circle"></i> Cruzar seleccionados con clientes</button>
          </div>

          <div class="cp-resultado-cruce" id="cp-resultado-cruce"></div>

          <table class="cp-tabla">
            <thead>
              <tr>
                <th class="chk"><input type="checkbox" id="cp-check-todos"></th>
                <th>Identificación</th>
                <th>Nombre completo</th>
                <th>Ciudad</th>
                <th>Departamento</th>
                <th>País</th>
                <th>Tipo(s)</th>
              </tr>
            </thead>
            <tbody id="cp-tbody"></tbody>
          </table>
          <p class="cp-vacio" id="cp-vacio">Busca clientes nuevos para ver resultados aquí.</p>
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
  var POR_PAGINA = 100;
  var corriendo = false;
  var pagina = 0;
  var totalPaginas = null;
  var piso = null;
  var revisados = 0;
  var nuevos = 0;
  var idCorrida = 0; // se incrementa en cada iniciar(), para descartar respuestas de
                      // una corrida anterior que llegan tarde (detener + iniciar rápido)

  var btnIniciar = document.getElementById('btn-iniciar');
  var btnDetener = document.getElementById('btn-detener');
  var btnCruzar = document.getElementById('btn-cruzar');
  var progreso = document.getElementById('cp-progreso');
  var progresoTxt = document.getElementById('cp-progreso-txt');
  var estado = document.getElementById('cp-estado');
  var tbody = document.getElementById('cp-tbody');
  var vacio = document.getElementById('cp-vacio');
  var buscar = document.getElementById('cp-buscar');
  var checkTodos = document.getElementById('cp-check-todos');
  var seleccionadosLbl = document.getElementById('cp-seleccionados');
  var resultadoCruce = document.getElementById('cp-resultado-cruce');

  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function actualizarResumen() {
    document.getElementById('cp-piso').textContent = piso != null ? piso.toLocaleString('es-CO') : '—';
    document.getElementById('cp-revisados').textContent = revisados.toLocaleString('es-CO');
    document.getElementById('cp-nuevos').textContent = nuevos.toLocaleString('es-CO');
    var pct = totalPaginas ? Math.min(100, Math.round((pagina / totalPaginas) * 100)) : 0;
    progreso.style.width = pct + '%';
    progresoTxt.textContent = piso != null
      ? 'Página ' + (pagina) + ' de ' + (totalPaginas ?? '?') + ' — ' + revisados.toLocaleString('es-CO') + ' revisados (id > ' + piso + ')'
      : 'Cargando...';
  }

  function agregarFilas(lista) {
    vacio.style.display = 'none';
    lista.forEach(function (n) {
      var tr = document.createElement('tr');
      tr.dataset.id = n.id;
      tr.dataset.buscar = (n.identificacion + ' ' + n.nombreCompleto).toLowerCase();
      tr.innerHTML =
        '<td class="chk"><input type="checkbox" class="cp-check-fila" data-id="' + h(n.id) + '"></td>' +
        '<td>' + h(n.identificacion) + '</td>' +
        '<td>' + h(n.nombreCompleto) + '</td>' +
        '<td>' + h(n.ciudad) + '</td>' +
        '<td>' + h(n.departamento) + '</td>' +
        '<td>' + h(n.pais) + '</td>' +
        '<td>' + h(n.terceroTipos) + '</td>';
      tbody.appendChild(tr);
    });
  }

  function mostrarError(msg) {
    estado.textContent = msg;
    estado.className = 'cp-estado visible error';
  }

  function siguientePagina(miCorrida) {
    if (!corriendo || miCorrida !== idCorrida) return;
    var url = 'php/comparar_terceros.php?pagina=' + pagina + '&porPagina=' + POR_PAGINA;
    if (pagina > 0 && piso != null) url += '&piso=' + piso;
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (miCorrida !== idCorrida) return; // esta corrida ya no es la vigente: se ignora la respuesta

        if (!data.success) {
          mostrarError('Error consultando World Office: ' + (data.message || 'desconocido'));
          detener();
          return;
        }
        piso = data.piso;
        totalPaginas = data.totalPaginas;
        revisados += data.revisadosEnPagina;
        nuevos += data.nuevos.length;
        agregarFilas(data.nuevos);
        actualizarResumen();

        pagina++;
        if (corriendo && miCorrida === idCorrida && totalPaginas != null && pagina < totalPaginas) {
          siguientePagina(miCorrida);
        } else {
          detener();
          progreso.style.width = '100%';
          if (tbody.children.length === 0) {
            vacio.style.display = '';
            vacio.textContent = 'No se encontraron clientes nuevos (con id mayor a ' + piso + '): todos ya existen en la tabla clientes.';
          }
          progresoTxt.textContent = 'Búsqueda completa — ' + revisados.toLocaleString('es-CO') + ' terceros revisados (id > ' + piso + '), ' + nuevos.toLocaleString('es-CO') + ' nuevos.';
        }
      })
      .catch(function (err) {
        if (miCorrida !== idCorrida) return;
        mostrarError('Error de red: ' + err.message);
        detener();
      });
  }

  function iniciar() {
    idCorrida++;
    var miCorrida = idCorrida;
    corriendo = true;
    pagina = 0; totalPaginas = null; piso = null; revisados = 0; nuevos = 0;
    tbody.innerHTML = '';
    vacio.style.display = 'none';
    estado.className = 'cp-estado';
    resultadoCruce.className = 'cp-resultado-cruce';
    buscar.value = '';
    checkTodos.checked = false;
    btnIniciar.disabled = true;
    btnDetener.style.display = '';
    actualizarSeleccion();
    actualizarResumen();
    siguientePagina(miCorrida);
  }

  function detener() {
    corriendo = false;
    btnIniciar.disabled = false;
    btnDetener.style.display = 'none';
  }

  function filasVisibles() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function (tr) {
      return tr.style.display !== 'none';
    });
  }

  function aplicarBusqueda() {
    var texto = buscar.value.trim().toLowerCase();
    Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
      tr.style.display = (!texto || tr.dataset.buscar.indexOf(texto) !== -1) ? '' : 'none';
    });
    checkTodos.checked = false;
  }

  function actualizarSeleccion() {
    var marcados = tbody.querySelectorAll('.cp-check-fila:checked').length;
    seleccionadosLbl.textContent = marcados;
    btnCruzar.disabled = marcados === 0;
  }

  function cruzarSeleccionados() {
    var checks = Array.prototype.slice.call(tbody.querySelectorAll('.cp-check-fila:checked'));
    var ids = checks.map(function (c) { return parseInt(c.dataset.id, 10); });
    if (!ids.length) return;

    btnCruzar.disabled = true;
    resultadoCruce.className = 'cp-resultado-cruce';

    fetch('php/cruzar_terceros.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: ids })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          resultadoCruce.textContent = 'Error al cruzar: ' + (data.message || 'desconocido');
          resultadoCruce.className = 'cp-resultado-cruce visible error';
          actualizarSeleccion();
          return;
        }

        data.insertados.concat(data.omitidos).forEach(function (item) {
          var tr = tbody.querySelector('tr[data-id="' + item.id + '"]');
          if (tr) {
            tr.classList.add('cp-fila-cruzada');
            tr.querySelector('.cp-check-fila').disabled = true;
            tr.querySelector('.cp-check-fila').checked = false;
          }
        });

        nuevos -= data.insertados.length;
        if (data.piso != null) piso = data.piso;
        actualizarResumen();

        var msg = data.insertados.length + ' cliente(s) agregado(s) a la tabla clientes.';
        if (data.omitidos.length) msg += ' ' + data.omitidos.length + ' ya existían y no se tocaron.';
        if (data.errores.length) msg += ' ' + data.errores.length + ' con error al consultar World Office.';
        resultadoCruce.textContent = msg;
        resultadoCruce.className = 'cp-resultado-cruce visible ' + (data.errores.length ? 'error' : 'ok');

        actualizarSeleccion();
      })
      .catch(function (err) {
        resultadoCruce.textContent = 'Error de red: ' + err.message;
        resultadoCruce.className = 'cp-resultado-cruce visible error';
        actualizarSeleccion();
      });
  }

  btnIniciar.addEventListener('click', iniciar);
  btnDetener.addEventListener('click', detener);
  btnCruzar.addEventListener('click', cruzarSeleccionados);
  buscar.addEventListener('input', aplicarBusqueda);
  checkTodos.addEventListener('change', function () {
    filasVisibles().forEach(function (tr) {
      var chk = tr.querySelector('.cp-check-fila');
      if (chk && !chk.disabled) chk.checked = checkTodos.checked;
    });
    actualizarSeleccion();
  });
  tbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('cp-check-fila')) actualizarSeleccion();
  });
})();
</script>
</body>
</html>
