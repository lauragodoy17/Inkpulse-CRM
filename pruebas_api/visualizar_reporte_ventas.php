<?php
/**
 * /pruebas_api/visualizar_reporte_ventas.php
 * Reporte de ventas por producto detallado por documento (World Office).
 *
 * IMPORTANTE: este reporte usa un microservicio distinto al resto del panel
 * (wo-reportes-...azurewebsites.net) y se autentica con un TOKEN DE SESIÓN
 * de un usuario real de World Office que expira cada ~24h — NO con el token
 * permanente que usan los otros paneles. El token se pega aquí en un campo
 * de formulario y NUNCA se guarda (no va a base de datos ni a archivo):
 * solo vive en la petición que el navegador hace a este script.
 *
 * Cómo conseguir el token: en World Office, generar este mismo reporte
 * ("Ventas por Producto Detallado por Documento"), abrir el Network tab del
 * navegador, buscar la petición a "reporte/mensaje" y copiar el valor
 * completo del header "authorization" (empieza con "WO eyJ...").
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['autentificado']) || $_SESSION['autentificado'] !== 'SI' || !isset($_SESSION['tipo']) || $_SESSION['tipo'] != 1) {
    die('Acceso restringido: inicia sesión como administrador (tipo 1) en Inkpulse y vuelve a abrir este panel.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../includes/api_wo_reportes.php");

$tokenEnviado = isset($_POST['consultar']);

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
$fechaInicio = isset($_POST['desde']) && $_POST['desde'] !== '' ? $_POST['desde'] : date('Y-m-d', strtotime('-30 days'));
$fechaFin    = isset($_POST['hasta']) && $_POST['hasta'] !== '' ? $_POST['hasta'] : date('Y-m-d');
$buscar      = isset($_POST['buscar']) ? trim($_POST['buscar']) : '';
$porPagina   = 50;
$incluirFV   = !isset($_POST['tipos']) || in_array('1', $_POST['tipos'] ?? [], true);
$incluirREM  = !isset($_POST['tipos']) || in_array('6', $_POST['tipos'] ?? [], true);
$resultado = null;
$totales = null;
$filas = [];
$totalFilas = 0;

if ($tokenEnviado && $token !== '') {
    $tipos = [];
    if ($incluirFV) $tipos[] = 1;
    if ($incluirREM) $tipos[] = 6;
    if (!$tipos) $tipos = [1, 6];

    $resultado = obtener_reporte_ventas_producto($token, $fechaInicio, $fechaFin, $tipos);

    if ($resultado['status'] === 'ok') {
        $todasLasFilas = $resultado['data']['rows'] ?? [];
        foreach ($todasLasFilas as $f) {
            if (!empty($f['isTotal'])) { $totales = $f; continue; }
            if ($buscar !== '') {
                $texto = mb_strtolower(($f['cliente'] ?? '') . ' ' . ($f['descripcionInventario'] ?? '') . ' ' . ($f['codigoInventario'] ?? '') . ' ' . ($f['documento'] ?? '') . ' ' . ($f['concepto'] ?? ''));
                if (mb_strpos($texto, mb_strtolower($buscar)) === false) continue;
            }
            $filas[] = $f;
        }
        $totalFilas = count($filas);
    }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 0, ',', '.'); }
function dinero($v) { return number_format((float)$v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ventas por producto (World Office) — Inkpulse</title>
<style>
    body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background:#f4f6f8; margin:0; padding:24px; color:#1f2937; }
    h1 { font-size:1.35rem; margin-bottom:4px; }
    .subt { color:#6b7280; margin-bottom:18px; font-size:.88rem; max-width:900px; }
    .avisoInfo { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.82rem; max-width:900px; }
    form.filtros { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:18px; }
    form.filtros .fila { display:flex; gap:14px; align-items:end; flex-wrap:wrap; margin-bottom:10px; }
    form.filtros label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.filtros input[type=text], form.filtros input[type=date] { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; }
    form.filtros textarea { border:1px solid #d1d5db; border-radius:6px; padding:8px; font-size:.78rem; width:100%; box-sizing:border-box; font-family:Consolas,monospace; resize:vertical; }
    form.filtros button { background:#111827; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .nota { font-size:.72rem; color:#9ca3af; }
    .aviso { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.85rem; }
    .stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 16px; min-width:150px; }
    .stat b { display:block; font-size:.68rem; color:#6b7280; text-transform:uppercase; margin-bottom:4px; }
    .stat span { font-size:1.15rem; font-weight:700; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    th, td { text-align:left; padding:7px 9px; font-size:.78rem; border-bottom:1px solid #f0f1f3; vertical-align:top; }
    th { background:#111827; color:#fff; font-weight:600; }
    tr:hover td { background:#f9fafb; }
    td.num { text-align:right; font-variant-numeric:tabular-nums; }
    .paginacion { margin-top:14px; font-size:.85rem; display:flex; gap:10px; align-items:center; }
    .paginacion a { color:#2563eb; text-decoration:none; }
    .exclusion { margin-top:24px; font-size:.78rem; color:#9ca3af; max-width:900px; }
    nav.tabs { margin-bottom:16px; }
    nav.tabs a { color:#6b7280; text-decoration:none; font-size:.85rem; margin-right:16px; }
    nav.tabs a.activo { color:#111827; font-weight:700; border-bottom:2px solid #111827; padding-bottom:4px; }
    .checks { display:flex; gap:14px; align-items:center; font-size:.85rem; }
</style>
</head>
<body>

<nav class="tabs">
    <a href="visualizar_documentos_venta.php">Documentos de venta</a>
    <a href="visualizar_terceros.php">Terceros</a>
    <a href="visualizar_inventario.php">Inventario</a>
    <a href="visualizar_reporte_ventas.php" class="activo">Ventas por producto</a>
</nav>

<h1>Ventas por producto — World Office (Eureka)</h1>
<p class="subt">Reporte "Ventas por Producto Detallado por Documento": trae precio, descuento y total real por cada libro vendido en cada factura/remisión. Solo lectura — genera el mismo reporte que ya existe en World Office, no crea ni modifica nada.</p>

<div class="avisoInfo">
    Este reporte usa un <b>token de sesión</b> de un usuario real de World Office (dura ~24h), distinto al token permanente de los otros paneles. No se guarda en ningún lado — se pega en el formulario cada vez.<br>
    <b>Cómo conseguirlo:</b> genera este reporte manualmente en World Office → abre el Network tab del navegador (F12) → busca la petición a <code>reporte/mensaje</code> → copia el valor completo del header <code>authorization</code> (empieza con "WO eyJ...").
</div>

<form class="filtros" method="post">
    <div class="fila">
        <div style="flex:1;">
            <label>Token de sesión (authorization)</label>
            <textarea name="token" rows="2" placeholder="WO eyJhbGciOiJIUzUxMiJ9...." required><?= h($token) ?></textarea>
        </div>
    </div>
    <div class="fila">
        <div>
            <label>Desde</label>
            <input type="date" name="desde" value="<?= h($fechaInicio) ?>">
        </div>
        <div>
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= h($fechaFin) ?>">
        </div>
        <div>
            <label>Buscar (cliente, libro, ISBN, documento)</label>
            <input type="text" name="buscar" value="<?= h($buscar) ?>" placeholder="ej: SIGNOS 1" style="width:220px;">
        </div>
        <div class="checks">
            <label style="margin:0;"><input type="checkbox" name="tipos[]" value="1" <?= $incluirFV ? 'checked' : '' ?>> Factura de venta</label>
            <label style="margin:0;"><input type="checkbox" name="tipos[]" value="6" <?= $incluirREM ? 'checked' : '' ?>> Remisión de venta</label>
        </div>
        <button type="submit" name="consultar" value="1">Consultar</button>
    </div>
    <span class="nota">El rango de fechas por defecto es de los últimos 30 días para no traer miles de filas de una vez.</span>
</form>

<?php if ($tokenEnviado && $token === ''): ?>
    <div class="aviso">Pega el token primero.</div>
<?php elseif ($resultado && $resultado['status'] === 'error'): ?>
    <div class="aviso">
        <?= h($resultado['mensaje_interno']) ?>
    </div>
<?php elseif ($resultado && $resultado['status'] === 'ok'): ?>

    <?php if ($totales): ?>
        <div class="stats">
            <div class="stat"><b>Unidades vendidas</b><span><?= money($totales['cantidad'] ?? 0) ?></span></div>
            <div class="stat"><b>Venta bruta</b><span>$<?= dinero($totales['ventaBruta'] ?? 0) ?></span></div>
            <div class="stat"><b>Descuento</b><span>$<?= dinero($totales['descuento'] ?? 0) ?></span></div>
            <div class="stat"><b>Venta neta</b><span>$<?= dinero($totales['ventaNeta'] ?? 0) ?></span></div>
            <div class="stat"><b>Total (con IVA)</b><span>$<?= dinero($totales['total'] ?? 0) ?></span></div>
        </div>
        <p class="nota">Totales del rango completo consultado (antes de aplicar el buscador de texto). <?= $totalFilas ?> línea(s) coinciden con el filtro de búsqueda actual.</p>
    <?php endif; ?>

    <table id="tablaFilas">
        <tr>
            <th>Documento</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Libro (ISBN)</th>
            <th>Editorial</th>
            <th class="num">Cant.</th>
            <th class="num">Vr. Unit.</th>
            <th class="num">Descuento</th>
            <th class="num">Venta neta</th>
            <th class="num">Total</th>
        </tr>
        <?php foreach ($filas as $f): ?>
            <tr class="filaDato">
                <td><?= h($f['documento'] ?? '') ?></td>
                <td><?= h($f['fechaCalculada'] ?? '') ?></td>
                <td><?= h($f['cliente'] ?? '') ?></td>
                <td><?= h($f['descripcionInventario'] ?? '') ?><br><span style="color:#9ca3af;font-size:.7rem;"><?= h($f['codigoInventario'] ?? '') ?></span></td>
                <td><?= h($f['grupoInventario'] ?? '') ?></td>
                <td class="num"><?= money($f['cantidad'] ?? 0) ?></td>
                <td class="num">$<?= dinero($f['valorUnitario'] ?? 0) ?></td>
                <td class="num">$<?= dinero($f['descuento'] ?? 0) ?></td>
                <td class="num">$<?= dinero($f['ventaNeta'] ?? 0) ?></td>
                <td class="num">$<?= dinero($f['total'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
            <tr><td colspan="10" style="text-align:center;color:#9ca3af;">Sin resultados para este filtro.</td></tr>
        <?php endif; ?>
    </table>

    <div class="paginacion">
        <span id="resumenPagina"></span>
        <a href="#" id="btnAnterior">&laquo; anterior</a>
        <a href="#" id="btnSiguiente">siguiente &raquo;</a>
    </div>

    <script>
    (function() {
        var porPagina = <?= (int)$porPagina ?>;
        var filas = Array.prototype.slice.call(document.querySelectorAll('#tablaFilas tr.filaDato'));
        var pagina = 0;
        var totalPaginas = Math.max(1, Math.ceil(filas.length / porPagina));

        function render() {
            filas.forEach(function(tr, i) {
                tr.style.display = (i >= pagina * porPagina && i < (pagina + 1) * porPagina) ? '' : 'none';
            });
            document.getElementById('resumenPagina').textContent =
                'Página ' + (pagina + 1) + ' de ' + totalPaginas + ' — ' + filas.length + ' línea(s)';
            document.getElementById('btnAnterior').style.visibility = pagina > 0 ? 'visible' : 'hidden';
            document.getElementById('btnSiguiente').style.visibility = pagina < totalPaginas - 1 ? 'visible' : 'hidden';
        }
        document.getElementById('btnAnterior').addEventListener('click', function(e) {
            e.preventDefault(); if (pagina > 0) { pagina--; render(); }
        });
        document.getElementById('btnSiguiente').addEventListener('click', function(e) {
            e.preventDefault(); if (pagina < totalPaginas - 1) { pagina++; render(); }
        });
        render();
    })();
    </script>

<?php endif; ?>

<p class="exclusion">
    Este reporte es de solo lectura: genera la misma consulta que ya existe dentro de World Office. No se crea, edita, ni elimina nada. El token pegado no se guarda en ningún archivo ni base de datos — solo se usa para esta petición.
</p>

</body>
</html>
