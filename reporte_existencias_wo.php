<?php
require_once("php/aut.php");

if (!in_array((int)($_SESSION['tipo'] ?? 0), [1, 2], true)) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Existencias World Office</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .ewo-progreso-wrap { height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin: 14px 0 6px; }
    .ewo-progreso { height: 100%; width: 0; background: #2563eb; transition: width .3s; }
    .ewo-txt { font-size: .82rem; color: #64748b; margin: 0; }
    .ewo-txt.error { color: #b91c1c; font-weight: 600; }
    .ewo-tarjetas { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    .ewo-tarjeta { flex: 1 1 160px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 14px; background: #fff; }
    .ewo-tarjeta .num { font-size: 1.35rem; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
    .ewo-tarjeta .lbl { font-size: .75rem; color: #64748b; }
    .ewo-filtros { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 14px; }
    .ewo-filtros label { display: block; font-size: .75rem; color: #64748b; margin-bottom: 4px; }
    .ewo-tabla { width: 100%; font-size: .82rem; border-collapse: collapse; }
    .ewo-tabla th { background: #f8fafc; color: #334155; font-weight: 600; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; cursor: pointer; white-space: nowrap; }
    .ewo-tabla td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
    .ewo-tabla .n { text-align: right; font-variant-numeric: tabular-nums; }
    .ewo-tabla tr.inactivo td { color: #94a3b8; }
    .ewo-tabla .cero { color: #cbd5e1; }
    .ewo-badge { font-size: .68rem; padding: 1px 6px; border-radius: 99px; background: #ede9fe; color: #5b21b6; margin-left: 6px; }
    .ewo-badge.inact { background: #f1f5f9; color: #64748b; }
    .ewo-pie { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 12px; font-size: .8rem; color: #64748b; }
    .ewo-oculto { display: none; }
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
            <div class="title"><h4>Existencias World Office</h4></div>
          </div>
        </div>
      </div>

      <div class="sm-section">
        <div class="sm-section-head">
          <span class="sm-sec-icon"><i class="bi bi-box-seam"></i></span>
          <span class="sm-section-title">Todos los títulos de World Office — Bodega General y Muestras General</span>
        </div>
        <div class="sm-section-body">
          <button type="button" class="btn btn-primary" id="ewo-consultar"><i class="bi bi-arrow-repeat"></i> Actualizar desde World Office</button>
          <div class="ewo-progreso-wrap"><div class="ewo-progreso" id="ewo-progreso"></div></div>
          <p class="ewo-txt" id="ewo-txt">Cargando la última consulta guardada...</p>
        </div>
      </div>

      <div class="sm-section ewo-oculto" id="ewo-resultado">
        <div class="sm-section-body">
          <div class="ewo-tarjetas">
            <div class="ewo-tarjeta"><div class="num" id="ewo-t-titulos">0</div><div class="lbl">Títulos con este filtro</div></div>
            <div class="ewo-tarjeta"><div class="num" id="ewo-t-general">0</div><div class="lbl">Unidades en Bodega General</div></div>
            <div class="ewo-tarjeta"><div class="num" id="ewo-t-muestras">0</div><div class="lbl">Unidades en Muestras General</div></div>
          </div>

          <div class="ewo-filtros">
            <div style="flex: 1 1 240px;">
              <label for="ewo-buscar">Buscar por título o código</label>
              <input type="search" id="ewo-buscar" class="form-control form-control-sm" placeholder="Ej. Smart Explorers o 978958...">
            </div>
            <div style="flex: 1 1 220px;">
              <label for="ewo-editorial">Editorial</label>
              <select id="ewo-editorial" class="form-control form-control-sm"><option value="">Todas</option></select>
            </div>
            <div>
              <label for="ewo-tipo">Tipo</label>
              <select id="ewo-tipo" class="form-control form-control-sm">
                <option value="">Títulos y paquetes</option>
                <option value="titulo">Solo títulos</option>
                <option value="paquete">Solo paquetes</option>
              </select>
            </div>
            <div>
              <label for="ewo-estado">Estado en WO</label>
              <select id="ewo-estado" class="form-control form-control-sm">
                <option value="">Activos e inactivos</option>
                <option value="1">Solo activos</option>
                <option value="0">Solo inactivos</option>
              </select>
            </div>
            <div>
              <label for="ewo-exist">Existencias</label>
              <select id="ewo-exist" class="form-control form-control-sm">
                <option value="">Todas</option>
                <option value="alguna">Con existencias</option>
                <option value="general">Con existencias en General</option>
                <option value="muestras">Con existencias en Muestras</option>
                <option value="ninguna">Sin existencias</option>
              </select>
            </div>
            <div>
              <button type="button" class="btn btn-outline-primary btn-sm" id="ewo-excel"><i class="bi bi-download"></i> Exportar Excel</button>
            </div>
          </div>

          <div style="overflow-x:auto;">
            <table class="ewo-tabla">
              <thead>
                <tr>
                  <th data-orden="codigo">Código</th>
                  <th data-orden="titulo">Título</th>
                  <th data-orden="editorial">Editorial</th>
                  <th data-orden="general" class="n">Bodega General</th>
                  <th data-orden="muestras" class="n">Muestras General</th>
                  <th data-orden="total" class="n">Total</th>
                </tr>
              </thead>
              <tbody id="ewo-cuerpo"></tbody>
            </table>
          </div>
          <div class="ewo-pie">
            <span id="ewo-pagina-txt"></span>
            <span>
              <button type="button" class="btn btn-light btn-sm" id="ewo-ant">Anterior</button>
              <button type="button" class="btn btn-light btn-sm" id="ewo-sig">Siguiente</button>
            </span>
          </div>
        </div>
      </div>

      <form id="ewo-form-excel" action="php/existencias_wo_excel.php" method="POST" target="_blank" class="ewo-oculto">
        <input type="hidden" name="filas" id="ewo-filas">
        <input type="hidden" name="filtro" id="ewo-filtro">
      </form>

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
  var todas = [], vista = [], pagina = 0, orden = { campo: 'titulo', asc: true };
  var $ = function (id) { return document.getElementById(id); };
  var fmt = new Intl.NumberFormat('es-CO');

  function h(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function sinTildes(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function num(v) { return v === null ? '<span title="World Office no respondió">—</span>' : (v === 0 ? '<span class="cero">0</span>' : fmt.format(v)); }

  // ── Datos guardados + actualización en segundo plano ──
  // La actualización corre en el servidor (~30 s, ver includes/existencias_wo_datos.php): mientras tanto
  // la tabla sigue mostrando la consulta anterior y se puede usar; al terminar se cambia sola.
  var URL = 'ajax/existencias_wo_bloque.php?accion=';
  var sondeo = null;

  function json(r) { return r.json(); }
  function aviso(texto, error) { $('ewo-txt').textContent = texto; $('ewo-txt').className = 'ewo-txt' + (error ? ' error' : ''); }
  function fechaTexto(f) { return f.slice(0, 16).replace(' ', ' a las '); }

  function mostrar(filas) {
    todas = filas;
    todas.forEach(function (f) { f.total = f.general === null ? null : f.general + f.muestras; });
    llenarEditoriales();
    $('ewo-resultado').classList.remove('ewo-oculto');
    filtrar();
  }

  function textoDatos(u) {
    var fallidos = todas.filter(function (f) { return f.general === null; }).length;
    return fmt.format(todas.length) + ' títulos. Datos de World Office del ' + fechaTexto(u.fecha) +
      (u.usuario ? ' (actualizó ' + u.usuario + ')' : '') + '.' +
      (fallidos ? ' ' + fallidos + ' sin respuesta de World Office (salen con —); actualiza para reintentarlos.' : '');
  }

  // Trae la última consulta guardada; si no hay o es vieja, empieza a actualizar sola.
  function cargar(despuesDeActualizar) {
    return fetch(URL + 'ultima').then(json).then(function (d) {
      if (!d.success) throw new Error(d.message || 'desconocido');
      if (d.ultima) { mostrar(d.ultima.filas); aviso(textoDatos(d.ultima)); }
      if (despuesDeActualizar) return;
      if (d.estado.en_curso) { seguir(); return; }
      if (!d.ultima || d.ultima.minutos >= d.vigencia) actualizar(true);
    }).catch(function (e) { aviso('No se pudo cargar la última consulta: ' + e.message, true); });
  }

  function actualizar(automatica) {
    $('ewo-consultar').disabled = true;
    $('ewo-progreso').style.width = '2%';
    aviso((automatica ? 'Los datos tienen más de 30 minutos: actualizando' : 'Actualizando') + ' desde World Office...' + (todas.length ? ' Mientras tanto puedes seguir usando la tabla.' : ''));
    fetch(URL + 'actualizar').then(json).then(function (d) {
      if (!d.success) throw new Error(d.message || 'desconocido');
      if (d.en_curso) return; // ya la estaba haciendo otra persona: el sondeo avisa cuando termine
      terminar();
    }).catch(function (e) { terminar(e.message); });
    seguir();
  }

  // Avance mientras el servidor actualiza (también si la empezó otra persona u otra pestaña).
  function seguir() {
    $('ewo-consultar').disabled = true;
    if (sondeo) return;
    sondeo = setInterval(function () {
      fetch(URL + 'estado').then(json).then(function (d) {
        var e = d.estado;
        if (!e.en_curso) { terminar(e.error); return; }
        var pct = e.total ? Math.max(3, Math.round(e.hechos / e.total * 100)) : 3;
        $('ewo-progreso').style.width = pct + '%';
        aviso('Actualizando desde World Office: ' + (e.total ? fmt.format(e.hechos) + ' de ' + fmt.format(e.total) + ' títulos' : 'leyendo el listado de títulos') +
          '...' + (todas.length ? ' Mientras tanto puedes seguir usando la tabla.' : ''));
      }).catch(function () {});
    }, 1500);
  }

  function terminar(error) {
    if (sondeo) { clearInterval(sondeo); sondeo = null; }
    $('ewo-consultar').disabled = false;
    $('ewo-progreso').style.width = error ? '0%' : '100%';
    if (error) { aviso('No se pudo actualizar desde World Office: ' + error + (todas.length ? ' Se siguen mostrando los datos anteriores.' : ''), true); return; }
    cargar(true);
  }

  cargar(false);
  function llenarEditoriales() {
    var sel = $('ewo-editorial'), actual = sel.value, vistas = {};
    todas.forEach(function (f) { if (f.editorial) vistas[f.editorial] = true; });
    sel.innerHTML = '<option value="">Todas</option>' + Object.keys(vistas).sort().map(function (e) {
      return '<option' + (e === actual ? ' selected' : '') + '>' + h(e) + '</option>';
    }).join('');
  }

  // ── Filtros, orden y página ──
  function filtrar() {
    var q = sinTildes($('ewo-buscar').value.trim()), ed = $('ewo-editorial').value, tipo = $('ewo-tipo').value,
        est = $('ewo-estado').value, ex = $('ewo-exist').value;
    vista = todas.filter(function (f) {
      if (q && sinTildes(f.titulo).indexOf(q) === -1 && sinTildes(f.codigo).indexOf(q) === -1) return false;
      if (ed && f.editorial !== ed) return false;
      if (tipo === 'titulo' && f.paquete) return false;
      if (tipo === 'paquete' && !f.paquete) return false;
      if (est !== '' && String(f.activo ? 1 : 0) !== est) return false;
      if (ex === 'alguna' && !(f.total > 0)) return false;
      if (ex === 'general' && !(f.general > 0)) return false;
      if (ex === 'muestras' && !(f.muestras > 0)) return false;
      if (ex === 'ninguna' && f.total !== 0) return false;
      return true;
    });
    var c = orden.campo, s = orden.asc ? 1 : -1;
    vista.sort(function (a, b) {
      var x = a[c], y = b[c];
      if (typeof x === 'number' || typeof y === 'number' || x === null || y === null) return ((x === null ? -1 : x) - (y === null ? -1 : y)) * s;
      return String(x).localeCompare(String(y), 'es') * s;
    });
    var g = 0, m = 0;
    vista.forEach(function (f) { g += f.general || 0; m += f.muestras || 0; });
    $('ewo-t-titulos').textContent = fmt.format(vista.length);
    $('ewo-t-general').textContent = fmt.format(g);
    $('ewo-t-muestras').textContent = fmt.format(m);
    pagina = 0;
    pintar();
  }

  function pintar() {
    var paginas = Math.max(1, Math.ceil(vista.length / POR_PAGINA));
    pagina = Math.min(pagina, paginas - 1);
    var trozo = vista.slice(pagina * POR_PAGINA, (pagina + 1) * POR_PAGINA);
    $('ewo-cuerpo').innerHTML = trozo.length ? trozo.map(function (f) {
      return '<tr' + (f.activo ? '' : ' class="inactivo"') + '><td>' + h(f.codigo) + '</td><td>' + h(f.titulo) +
        (f.paquete ? '<span class="ewo-badge">Paquete</span>' : '') + (f.activo ? '' : '<span class="ewo-badge inact">Inactivo</span>') +
        '</td><td>' + h(f.editorial) + '</td><td class="n">' + num(f.general) + '</td><td class="n">' + num(f.muestras) +
        '</td><td class="n">' + num(f.total) + '</td></tr>';
    }).join('') : '<tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">Ningún título con este filtro.</td></tr>';
    $('ewo-pagina-txt').textContent = vista.length
      ? 'Mostrando ' + fmt.format(pagina * POR_PAGINA + 1) + '–' + fmt.format(pagina * POR_PAGINA + trozo.length) + ' de ' + fmt.format(vista.length) + ' (página ' + (pagina + 1) + ' de ' + paginas + ')'
      : '';
    $('ewo-ant').disabled = pagina === 0;
    $('ewo-sig').disabled = pagina >= paginas - 1;
  }

  function textoFiltro() {
    var partes = [];
    if ($('ewo-buscar').value.trim()) partes.push('"' + $('ewo-buscar').value.trim() + '"');
    ['ewo-editorial', 'ewo-tipo', 'ewo-estado', 'ewo-exist'].forEach(function (id) {
      var s = $(id); if (s.value) partes.push(s.options[s.selectedIndex].text);
    });
    return partes.join(' · ');
  }

  $('ewo-consultar').addEventListener('click', function () { actualizar(false); });
  var espera;
  $('ewo-buscar').addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(filtrar, 200); });
  ['ewo-editorial', 'ewo-tipo', 'ewo-estado', 'ewo-exist'].forEach(function (id) { $(id).addEventListener('change', filtrar); });
  $('ewo-ant').addEventListener('click', function () { pagina--; pintar(); });
  $('ewo-sig').addEventListener('click', function () { pagina++; pintar(); });
  document.querySelectorAll('.ewo-tabla th[data-orden]').forEach(function (th) {
    th.addEventListener('click', function () {
      var c = th.getAttribute('data-orden');
      orden = { campo: c, asc: orden.campo === c ? !orden.asc : (c === 'titulo' || c === 'codigo' || c === 'editorial') };
      filtrar();
    });
  });
  $('ewo-excel').addEventListener('click', function () {
    $('ewo-filas').value = JSON.stringify(vista.map(function (f) {
      return { codigo: f.codigo, titulo: f.titulo, editorial: f.editorial, activo: f.activo, paquete: f.paquete, general: f.general, muestras: f.muestras };
    }));
    $('ewo-filtro').value = textoFiltro();
    $('ewo-form-excel').submit();
  });
})();
</script>
</body>
</html>
