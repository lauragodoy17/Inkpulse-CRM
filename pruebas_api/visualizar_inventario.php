<?php
/**
 * /pruebas_api/visualizar_inventario.php
 * Apartado de solo lectura para ver el inventario (productos/libros)
 * registrado en World Office. Endpoint POST /inventarios/listarInventarios,
 * descubierto por prueba directa (no está en el catálogo público de
 * developer.worldoffice.cloud). No requiere filtros obligatorios.
 *
 * También muestra existencias reales (cantidad en stock) por producto, vía
 * GET /inventarios/{id}/existencias/empresa y GET /inventarios/{id}/existencias/bodega
 * — endpoints confirmados con la documentación propia de esta integración.
 *
 * No se prueba/implementa nada de crear, editar ni eliminar productos.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['autentificado']) || $_SESSION['autentificado'] !== 'SI' || !isset($_SESSION['tipo']) || $_SESSION['tipo'] != 1) {
    die('Acceso restringido: inicia sesión como administrador (tipo 1) en Inkpulse y vuelve a abrir este panel.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../includes/api_wo_inventario.php");

$descripcion = isset($_GET['descripcion']) ? trim($_GET['descripcion']) : '';
$codigo      = isset($_GET['codigo']) ? trim($_GET['codigo']) : '';
$pagina      = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina   = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 15;
$verExistencias = isset($_GET['ver']) ? $_GET['ver'] : null;

$filtros = [];
if ($descripcion !== '') {
    $filtros[] = crear_filtro_api('descripcion', $descripcion, 0, 1); // tipoFiltro 1 = contiene
}
if ($codigo !== '') {
    $filtros[] = crear_filtro_api('codigo', $codigo, 0, 0); // exacto (ISBN)
}

$inicio = microtime(true);
$resultado = listar_inventarios($filtros, $pagina, $porPagina);
$ms = round((microtime(true) - $inicio) * 1000);

$status = $resultado['status'] ?? 'error';
$es_error = !in_array($status, ['OK', 'ACCEPTED'], true);
$pag = $resultado['data'] ?? null;
$filas = $pag['content'] ?? [];
$totalElementos = $pag['totalElements'] ?? null;
$totalPaginas = $pag['totalPages'] ?? null;

$existenciasEmpresa = null;
$existenciasBodega = null;
$ms_existencias = null;
if ($verExistencias !== null) {
    $inicio2 = microtime(true);
    $existenciasEmpresa = consultar_existencias_por_empresa($verExistencias);
    $existenciasBodega = consultar_existencias_por_bodega($verExistencias);
    $ms_existencias = round((microtime(true) - $inicio2) * 1000);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 0, ',', '.'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Inventario (World Office) — Inkpulse</title>
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
</style>
</head>
<body>

<nav class="tabs">
    <a href="visualizar_documentos_venta.php">Documentos de venta</a>
    <a href="visualizar_terceros.php">Terceros</a>
    <a href="visualizar_inventario.php" class="activo">Inventario</a>
    <a href="visualizar_reporte_ventas.php">Ventas por producto</a>
</nav>

<h1>Inventario — World Office (Eureka)</h1>
<p class="subt">Solo lectura: <code>POST /inventarios/listarInventarios</code>. No se crean, editan ni eliminan productos aquí.</p>

<form class="filtros" method="get">
    <div>
        <label>Descripción contiene</label>
        <input type="text" name="descripcion" value="<?= h($descripcion) ?>" placeholder="ej: SMALL BIG THINGS">
    </div>
    <div>
        <label>Código exacto (ISBN)</label>
        <input type="text" name="codigo" value="<?= h($codigo) ?>" placeholder="ej: 9781292490960">
    </div>
    <div>
        <label>Registros por página</label>
        <input type="number" name="porPagina" value="<?= h($porPagina) ?>" min="1" max="50" style="width:90px;">
    </div>
    <input type="hidden" name="pagina" value="0">
    <button type="submit">Buscar</button>
    <span class="nota">Sin filtros muestra los últimos productos creados (orden por ID descendente).</span>
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
            <th>Código (ISBN)</th>
            <th>Descripción</th>
            <th>Grupo / Editorial</th>
            <th>Clasificación</th>
            <th>Unidad</th>
            <th>Activo</th>
            <th></th>
        </tr>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td><?= h($f['id'] ?? '') ?></td>
                <td><?= h($f['codigo'] ?? '') ?></td>
                <td><?= h($f['descripcion'] ?? '') ?></td>
                <td><?= h($f['grupo'][0]['nombreGrupo'] ?? '') ?></td>
                <td><?= h($f['inventarioClasificacion']['nombre'] ?? '') ?></td>
                <td><?= h($f['unidadMedida']['nombre'] ?? '') ?></td>
                <td><?= !empty($f['senActivo']) ? '<span class="badge si">Sí</span>' : '<span class="badge no">No</span>' ?></td>
                <td><a href="?<?= http_build_query(array_merge($_GET, ['ver' => $f['id']])) ?>#existencias" style="color:#2563eb;text-decoration:none;font-size:.8rem;">Ver existencias</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
            <tr><td colspan="8" style="text-align:center;color:#9ca3af;">Sin resultados.</td></tr>
        <?php endif; ?>
    </table>

    <div class="paginacion">
        <span>Página <?= $pagina + 1 ?> de <?= $totalPaginas ?? '?' ?> — <?= $totalElementos ?? '?' ?> productos en total (<?= $ms ?> ms)</span>
        <?php if ($pagina > 0): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>">&laquo; anterior</a><?php endif; ?>
        <?php if ($totalPaginas && $pagina + 1 < $totalPaginas): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>">siguiente &raquo;</a><?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($verExistencias !== null): ?>
    <div class="panel-detalle" id="existencias" style="margin-top:20px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
        <h2 style="margin-top:0;font-size:1.05rem;">Existencias del producto <?= h($verExistencias) ?> (<?= $ms_existencias ?> ms)</h2>
        <?php
        $errorEmpresa = ($existenciasEmpresa['status'] ?? '') === 'error';
        $errorBodega  = ($existenciasBodega['status'] ?? '') === 'error';
        ?>
        <?php if ($errorEmpresa || $errorBodega): ?>
            <div class="aviso">
                <?= h($existenciasEmpresa['mensaje_interno'] ?? $existenciasBodega['mensaje_interno'] ?? 'Error consultando existencias.') ?>
            </div>
        <?php else:
            $filasEmpresa = $existenciasEmpresa['data']['content'] ?? [];
            $filasBodega  = $existenciasBodega['data']['content'] ?? [];
            $totalGeneral = 0;
            foreach ($filasBodega as $b) { $totalGeneral += (float)($b['cantidad'] ?? 0); }
        ?>
            <div class="stats" style="display:flex;gap:12px;margin-bottom:16px;">
                <div class="stat" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;">
                    <b style="display:block;font-size:.68rem;color:#6b7280;text-transform:uppercase;margin-bottom:4px;">Existencia total (todas las bodegas)</b>
                    <span style="font-size:1.15rem;font-weight:700;"><?= money($totalGeneral) ?></span>
                </div>
            </div>
            <table>
                <tr><th>Bodega</th><th style="text-align:right;">Cantidad</th></tr>
                <?php foreach ($filasBodega as $b): ?>
                    <tr>
                        <td><?= h($b['nombre'] ?? '') ?></td>
                        <td style="text-align:right;"><?= money($b['cantidad'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filasBodega): ?>
                    <tr><td colspan="2" style="text-align:center;color:#9ca3af;">Sin existencias registradas en ninguna bodega.</td></tr>
                <?php endif; ?>
            </table>
            <p class="nota">Por empresa: <?php foreach ($filasEmpresa as $e) echo h($e['nombre'] ?? '') . ' = ' . money($e['cantidad'] ?? 0) . '  '; ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="exclusion">
    No se prueba aquí ni existe confirmado para esta cuenta: crear, editar o eliminar productos. El listado trae código, descripción, grupo/editorial, clasificación, unidad de medida y estado activo; el detalle de existencias trae cantidad real en stock por bodega y por empresa.
</p>

</body>
</html>
