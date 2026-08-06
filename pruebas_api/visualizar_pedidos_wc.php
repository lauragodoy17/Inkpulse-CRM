<?php
/**
 * /pruebas_api/visualizar_pedidos_wc.php
 * Apartado de solo lectura para ver los pedidos (orders) de la tienda
 * WooCommerce registrada en apis_externas con api='WCN' (id=3, tienda nueva
 * new.eurekadigital.com.co) — NO la tienda vieja (api='WC', id=2).
 * Endpoints: GET /orders (listado con filtros) y GET /orders/{id} (detalle).
 * No se prueba/implementa nada de crear, editar ni eliminar pedidos.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['autentificado']) || $_SESSION['autentificado'] !== 'SI' || !isset($_SESSION['tipo']) || $_SESSION['tipo'] != 1) {
    die('Acceso restringido: inicia sesión como administrador (tipo 1) en Inkpulse y vuelve a abrir este panel.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../includes/wc_pedidos.php");

$estado    = isset($_GET['estado']) ? trim($_GET['estado']) : '';
$busqueda  = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$pagina    = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$porPagina = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 10;
$verId     = isset($_GET['ver']) ? $_GET['ver'] : null;

$filtros = [
    'page'     => $pagina,
    'per_page' => $porPagina,
];
if ($estado !== '') {
    $filtros['status'] = $estado;
}
if ($busqueda !== '') {
    $filtros['search'] = $busqueda;
}

$inicio = microtime(true);
$resultado = listar_pedidos_woocommerce($filtros);
$ms = round((microtime(true) - $inicio) * 1000);

$es_error = isset($resultado['status']) && $resultado['status'] === 'error';
$es_error_wp = !$es_error && isset($resultado['code']);
$filas = (!$es_error && !$es_error_wp) ? $resultado : [];

$detalle = null;
$ms_detalle = null;
if ($verId !== null) {
    $inicio2 = microtime(true);
    $detalle = obtener_pedido_woocommerce_por_id($verId);
    $ms_detalle = round((microtime(true) - $inicio2) * 1000);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 0, ',', '.'); }

$estados = ['' => 'Todos', 'pending' => 'Pendiente', 'processing' => 'Procesando', 'on-hold' => 'En espera', 'completed' => 'Completado', 'cancelled' => 'Cancelado', 'refunded' => 'Reembolsado', 'failed' => 'Fallido', 'trash' => 'Papelera'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Pedidos (WooCommerce) — Inkpulse</title>
<style>
    body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background:#f4f6f8; margin:0; padding:24px; color:#1f2937; }
    h1 { font-size:1.35rem; margin-bottom:4px; }
    .subt { color:#6b7280; margin-bottom:18px; font-size:.88rem; max-width:900px; }
    form.filtros { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; display:flex; gap:14px; align-items:end; flex-wrap:wrap; margin-bottom:18px; }
    form.filtros label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.filtros input, form.filtros select { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; width:200px; }
    form.filtros button { background:#111827; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .nota { font-size:.72rem; color:#9ca3af; }
    .aviso { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.85rem; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    th, td { text-align:left; padding:8px 10px; font-size:.82rem; border-bottom:1px solid #f0f1f3; vertical-align:top; }
    th { background:#111827; color:#fff; font-weight:600; }
    tr:hover td { background:#f9fafb; }
    .badge { display:inline-block; padding:1px 7px; border-radius:5px; font-size:.68rem; font-weight:700; background:#e5e7eb; color:#374151; }
    .badge.completed { background:#dcfce7; color:#166534; }
    .badge.processing { background:#dbeafe; color:#1e40af; }
    .badge.pending, .badge.on-hold { background:#fef3c7; color:#92400e; }
    .badge.cancelled, .badge.failed, .badge.trash { background:#fee2e2; color:#991b1b; }
    .paginacion { margin-top:14px; font-size:.85rem; display:flex; gap:10px; align-items:center; }
    .paginacion a { color:#2563eb; text-decoration:none; }
    pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; overflow:auto; font-size:.75rem; }
    .exclusion { margin-top:24px; font-size:.78rem; color:#9ca3af; max-width:900px; }
    .panel-detalle { margin-top:20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; }
</style>
</head>
<body>

<h1>Pedidos — WooCommerce (tienda nueva)</h1>
<p class="subt">Fuente: <code>apis_externas.id = 3</code> (<code>api = 'WCN'</code>, new.eurekadigital.com.co). Solo lectura: <code>GET /orders</code> y <code>GET /orders/{id}</code>. No se crean, editan ni eliminan pedidos aquí.</p>

<form class="filtros" method="get">
    <div>
        <label>Estado</label>
        <select name="estado">
            <?php foreach ($estados as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $val === $estado ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Buscar (cliente, email, # pedido)</label>
        <input type="text" name="busqueda" value="<?= h($busqueda) ?>" placeholder="ej: Juan Pérez">
    </div>
    <div>
        <label>Registros por página</label>
        <input type="number" name="porPagina" value="<?= h($porPagina) ?>" min="1" max="50" style="width:90px;">
    </div>
    <input type="hidden" name="pagina" value="1">
    <button type="submit">Buscar</button>
    <span class="nota">Sin filtros muestra los pedidos más recientes.</span>
</form>

<?php if ($es_error): ?>
    <div class="aviso">
        La conexión con WooCommerce falló.
        <pre><?= h(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php elseif ($es_error_wp): ?>
    <div class="aviso">
        WordPress/WooCommerce devolvió un error.
        <pre><?= h(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php else: ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Estado</th>
            <th>Cliente</th>
            <th>Colegio</th>
            <th>ID WO (libros)</th>
            <th>Total</th>
            <th></th>
        </tr>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td><?= h($f['id'] ?? '') ?></td>
                <td><?= h($f['date_created'] ?? '') ?></td>
                <td><span class="badge <?= h($f['status'] ?? '') ?>"><?= h($f['status'] ?? '') ?></span></td>
                <td><?= h(trim(($f['billing']['first_name'] ?? '') . ' ' . ($f['billing']['last_name'] ?? ''))) ?></td>
                <td><?= h(obtener_colegio_pedido($f)) ?></td>
                <td><?= h(implode(', ', obtener_ids_wo_pedido($f))) ?></td>
                <td><?= money($f['total'] ?? 0) ?> <?= h($f['currency'] ?? '') ?></td>
                <td><a href="?<?= http_build_query(array_merge($_GET, ['ver' => $f['id']])) ?>#detalle" style="color:#2563eb;text-decoration:none;font-size:.8rem;">Ver detalle</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
            <tr><td colspan="8" style="text-align:center;color:#9ca3af;">Sin resultados.</td></tr>
        <?php endif; ?>
    </table>

    <div class="paginacion">
        <span>Página <?= $pagina ?> — <?= count($filas) ?> pedidos en esta página (<?= $ms ?> ms)</span>
        <?php if ($pagina > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>">&laquo; anterior</a><?php endif; ?>
        <?php if (count($filas) >= $porPagina): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>">siguiente &raquo;</a><?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($verId !== null): ?>
    <div class="panel-detalle" id="detalle">
        <h2 style="margin-top:0;font-size:1.05rem;">Detalle del pedido <?= h($verId) ?> (<?= $ms_detalle ?> ms)</h2>
        <?php if (isset($detalle['status']) && $detalle['status'] === 'error'): ?>
            <div class="aviso"><?= h($detalle['mensaje_interno'] ?? 'Error consultando el pedido.') ?></div>
        <?php elseif (isset($detalle['code'])): ?>
            <div class="aviso"><?= h($detalle['message'] ?? 'Error devuelto por WordPress.') ?></div>
        <?php else: ?>
            <table>
                <tr><th>Producto</th><th>SKU (ISBN)</th><th>ID WO</th><th style="text-align:right;">Cantidad</th><th style="text-align:right;">Precio unit.</th><th style="text-align:right;">Total</th></tr>
                <?php foreach (($detalle['line_items'] ?? []) as $li): ?>
                    <tr>
                        <td><?= h($li['name'] ?? '') ?></td>
                        <td><?= h($li['sku'] ?? '') ?></td>
                        <td><?= h($li['acf']['id_wo'] ?? '') ?></td>
                        <td style="text-align:right;"><?= h($li['quantity'] ?? '') ?></td>
                        <td style="text-align:right;"><?= money($li['price'] ?? 0) ?></td>
                        <td style="text-align:right;"><?= money($li['total'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="nota" style="margin-top:10px;">
                Colegio: <b><?= h(obtener_colegio_pedido($detalle)) ?></b> &middot;
                Estado: <b><?= h($detalle['status'] ?? '') ?></b> &middot;
                Pago: <?= h($detalle['payment_method_title'] ?? '') ?> &middot;
                Total pedido: <b><?= money($detalle['total'] ?? 0) ?> <?= h($detalle['currency'] ?? '') ?></b>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="exclusion">
    No se prueba aquí ni existe implementado: crear, editar, cancelar o eliminar pedidos. El listado trae fecha, estado, cliente y total; el detalle trae los productos (line items) con cantidad y precio.
</p>

</body>
</html>
