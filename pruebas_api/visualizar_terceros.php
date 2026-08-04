<?php
/**
 * /pruebas_api/visualizar_terceros.php
 * Apartado de solo lectura para ver los terceros (clientes/proveedores)
 * registrados en World Office. Usa dos endpoints:
 *   - POST /terceros/listarTerceros — listado paginado, descubierto por prueba
 *     directa (no está en el catálogo público de developer.worldoffice.cloud).
 *     No requiere filtros obligatorios.
 *   - GET  /terceros/consultar/{id} — detalle por id, confirmado en la
 *     documentación propia de World Office para esta cuenta.
 * No se prueba/implementa nada de crear, editar ni eliminar terceros.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['autentificado']) || $_SESSION['autentificado'] !== 'SI' || !isset($_SESSION['tipo']) || $_SESSION['tipo'] != 1) {
    die('Acceso restringido: inicia sesión como administrador (tipo 1) en Inkpulse y vuelve a abrir este panel.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../includes/api_wo_terceros.php");

$nombre        = isset($_GET['nombre']) ? trim($_GET['nombre']) : '';
$identificacion = isset($_GET['identificacion']) ? trim($_GET['identificacion']) : '';
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 15;
$verDetalle    = isset($_GET['ver']) && $_GET['ver'] !== '' ? $_GET['ver'] : null;

$filtros = [];
if ($nombre !== '') {
    $filtros[] = crear_filtro_api('nombreCompleto', $nombre, 0, 1); // tipoFiltro 1 = contiene
}
if ($identificacion !== '') {
    $filtros[] = crear_filtro_api('identificacion', $identificacion, 0, 0); // exacto
}

$inicio = microtime(true);
$resultado = listar_terceros($filtros, $pagina, $porPagina);
$ms = round((microtime(true) - $inicio) * 1000);

$status = $resultado['status'] ?? 'error';
$es_error = !in_array($status, ['OK', 'ACCEPTED'], true);
$pag = $resultado['data'] ?? null;
$filas = $pag['content'] ?? [];
$totalElementos = $pag['totalElements'] ?? null;
$totalPaginas = $pag['totalPages'] ?? null;

$detalle = null;
$ms_detalle = null;
if ($verDetalle !== null) {
    $inicio2 = microtime(true);
    $detalle = consultar_tercero($verDetalle);
    $ms_detalle = round((microtime(true) - $inicio2) * 1000);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Terceros (World Office) — Inkpulse</title>
<style>
    body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background:#f4f6f8; margin:0; padding:24px; color:#1f2937; }
    h1 { font-size:1.35rem; margin-bottom:4px; }
    .subt { color:#6b7280; margin-bottom:18px; font-size:.88rem; max-width:900px; }
    form.filtros { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; display:flex; gap:14px; align-items:end; flex-wrap:wrap; margin-bottom:18px; }
    form.filtros label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.filtros input { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; width:200px; }
    form.filtros button { background:#111827; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .nota { font-size:.72rem; color:#9ca3af; }
    .aviso { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.85rem; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    th, td { text-align:left; padding:8px 10px; font-size:.82rem; border-bottom:1px solid #f0f1f3; vertical-align:top; }
    th { background:#111827; color:#fff; font-weight:600; }
    tr:hover td { background:#f9fafb; }
    .badge { display:inline-block; padding:1px 7px; border-radius:5px; font-size:.68rem; font-weight:700; }
    .badge.si { background:#dcfce7; color:#166534; }
    .badge.no { background:#fee2e2; color:#991b1b; }
    .paginacion { margin-top:14px; font-size:.85rem; display:flex; gap:10px; align-items:center; }
    .paginacion a { color:#2563eb; text-decoration:none; }
    pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; overflow:auto; font-size:.75rem; }
    .exclusion { margin-top:24px; font-size:.78rem; color:#9ca3af; max-width:900px; }
    nav.tabs { margin-bottom:16px; }
    nav.tabs a { color:#6b7280; text-decoration:none; font-size:.85rem; margin-right:16px; }
    nav.tabs a.activo { color:#111827; font-weight:700; border-bottom:2px solid #111827; padding-bottom:4px; }
    form.buscar { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; display:flex; gap:10px; align-items:end; margin-bottom:14px; }
    form.buscar label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.buscar input { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; width:140px; }
    form.buscar button { background:#2563eb; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .ver { color:#2563eb; text-decoration:none; font-size:.8rem; }
    .panel-detalle { margin-top:20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; }
    .panel-detalle h2 { margin-top:0; font-size:1.05rem; }
    .grid { display:grid; grid-template-columns:repeat(2, minmax(220px,1fr)); gap:8px 24px; font-size:.85rem; }
    .grid div b { display:block; color:#6b7280; font-size:.72rem; font-weight:600; text-transform:uppercase; }
    details.crudo summary { cursor:pointer; margin-top:12px; color:#6b7280; font-size:.8rem; }
</style>
</head>
<body>

<nav class="tabs">
    <a href="visualizar_documentos_venta.php">Documentos de venta</a>
    <a href="visualizar_terceros.php" class="activo">Terceros</a>
    <a href="visualizar_inventario.php">Inventario</a>
    <a href="visualizar_reporte_ventas.php">Ventas por producto</a>
</nav>

<h1>Terceros — World Office (Eureka)</h1>
<p class="subt">Solo lectura: <code>POST /terceros/listarTerceros</code> + <code>GET /terceros/consultar/{id}</code>. No se crean, editan ni eliminan terceros aquí.</p>

<form class="buscar" method="get">
    <div>
        <label>Buscar tercero por ID</label>
        <input type="text" name="ver" value="<?= h($verDetalle ?? '') ?>" placeholder="ej: 123">
    </div>
    <button type="submit">Ver detalle</button>
    <span class="nota">Usa <code>consultar/{id}</code> directo, sin pasar por el listado.</span>
</form>

<form class="filtros" method="get">
    <div>
        <label>Nombre contiene</label>
        <input type="text" name="nombre" value="<?= h($nombre) ?>" placeholder="ej: MEGALIBROS">
    </div>
    <div>
        <label>Identificación exacta</label>
        <input type="text" name="identificacion" value="<?= h($identificacion) ?>" placeholder="ej: 900979535">
    </div>
    <div>
        <label>Registros por página</label>
        <input type="number" name="porPagina" value="<?= h($porPagina) ?>" min="1" max="50" style="width:90px;">
    </div>
    <input type="hidden" name="pagina" value="0">
    <button type="submit">Buscar</button>
    <span class="nota">Sin filtros muestra los últimos terceros creados (orden por ID descendente).</span>
</form>

<?php if ($es_error): ?>
    <div class="aviso">
        La API respondió con error.
        <pre><?= h(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php else: ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Identificación</th>
            <th>Nombre completo</th>
            <th>Ciudad</th>
            <th>Tipo(s)</th>
            <th>Activo</th>
            <th></th>
        </tr>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td><?= h($f['id'] ?? '') ?></td>
                <td><?= h($f['tipoIdentificacion'] ?? '') ?> <?= h($f['identificacion'] ?? '') ?></td>
                <td><?= h($f['nombreCompleto'] ?? '') ?></td>
                <td><?= h($f['ubicacionCiudad'] ?? '') ?></td>
                <td><?= h($f['terceroTipos'] ?? '') ?></td>
                <td><?= !empty($f['senActivo']) ? '<span class="badge si">Sí</span>' : '<span class="badge no">No</span>' ?></td>
                <td><a class="ver" href="?<?= http_build_query(array_merge($_GET, ['ver' => $f['id']])) ?>#detalle">Ver detalle</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
            <tr><td colspan="7" style="text-align:center;color:#9ca3af;">Sin resultados.</td></tr>
        <?php endif; ?>
    </table>

    <div class="paginacion">
        <span>Página <?= $pagina + 1 ?> de <?= $totalPaginas ?? '?' ?> — <?= $totalElementos ?? '?' ?> terceros en total (<?= $ms ?> ms)</span>
        <?php if ($pagina > 0): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>">&laquo; anterior</a><?php endif; ?>
        <?php if ($totalPaginas && $pagina + 1 < $totalPaginas): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>">siguiente &raquo;</a><?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($detalle !== null): ?>
    <div class="panel-detalle" id="detalle">
        <h2>Detalle del tercero <?= h($verDetalle) ?> (<?= $ms_detalle ?> ms)</h2>
        <?php if (($detalle['status'] ?? '') === 'error' || isset($detalle['errorCode'])): ?>
            <div class="aviso">
                La API respondió con error.
                <pre><?= h(json_encode($detalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        <?php else:
            $d = $detalle['data'] ?? $detalle;
        ?>
            <div class="grid">
                <div><b>ID</b><?= h($d['id'] ?? '') ?></div>
                <div><b>Código</b><?= h($d['codigo'] ?? '') ?></div>
                <div><b>Tipo de identificación</b><?= h($d['terceroTipoIdentificacion']['nombre'] ?? '') ?></div>
                <div><b>Identificación</b><?= h($d['identificacion'] ?? '') ?><?= isset($d['digitoVerificacion']) ? '-' . h($d['digitoVerificacion']) : '' ?></div>
                <div><b>Nombre completo</b><?= h($d['nombreCompleto'] ?? '') ?></div>
                <div><b>Nombres / apellidos</b><?= h(trim(($d['primerNombre'] ?? '') . ' ' . ($d['segundoNombre'] ?? '') . ' ' . ($d['primerApellido'] ?? '') . ' ' . ($d['segundoApellido'] ?? ''))) ?></div>
                <div><b>Ciudad</b><?= h($d['ciudad']['nombre'] ?? '') ?></div>
                <div><b>Departamento</b><?= h($d['ciudad']['ubicacionDepartamento']['nombre'] ?? '') ?></div>
                <div><b>País</b><?= h($d['ciudad']['ubicacionDepartamento']['ubicacionPais']['nombre'] ?? '') ?></div>
                <div><b>Tipo(s) de tercero</b><?= h(implode(', ', array_column($d['terceroTipos'] ?? [], 'nombre'))) ?></div>
                <div><b>Activo</b><?= !empty($d['senActivo']) ? '<span class="badge si">Sí</span>' : '<span class="badge no">No</span>' ?></div>
                <div><b>Aplica ICA ventas</b><?= !empty($d['aplicaICAVentas']) ? '<span class="badge si">Sí</span>' : '<span class="badge no">No</span>' ?></div>
            </div>
        <?php endif; ?>

        <details class="crudo">
            <summary>Ver JSON crudo de la respuesta</summary>
            <pre><?= h(json_encode($detalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
    </div>
<?php endif; ?>

<p class="exclusion">
    No se prueba aquí ni existe confirmado para esta cuenta: crear, editar o eliminar terceros. El listado trae identificación, nombre, ciudad, tipo(s) de tercero y estado activo; el detalle por ID (<code>consultar/{id}</code>) agrega ubicación completa (ciudad/departamento/país), nombres/apellidos por separado, código y las banderas de ICA.
</p>

</body>
</html>
