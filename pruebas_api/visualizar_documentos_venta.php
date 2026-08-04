<?php
/**
 * /pruebas_api/visualizar_documentos_venta.php
 * Apartado de pruebas de SOLO LECTURA para la API de World Office (Eureka).
 *
 * Usa los endpoints ya confirmados como funcionales para esta cuenta:
 *   - POST /inventarios/listarDocumentoSalidaAlmacen  (listado paginado, requiere
 *     al menos el filtro documentoTipo.codigoDocumento + moneda.id, si no da 403)
 *   - GET  /inventarios/getDocumentoSalidaAlmacenId/{id}  (detalle por id)
 *   - POST /documentos/getRenglonesByDocumentoEncabezado/{id}  (productos del
 *     documento — a pesar del nombre exige POST, un GET da 405)
 *
 * El catálogo genérico documentado en developer.worldoffice.cloud (terceros,
 * documentos, cuentasContables, etc.) NO está habilitado para esta cuenta
 * (responde 404 "No static resource") — se comprobó en pruebas manuales.
 * No se prueba ni implementa nada de crear, editar, eliminar, anular,
 * contabilizar o enviar por correo: solo listar y consultar detalle.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['autentificado']) || $_SESSION['autentificado'] !== 'SI' || !isset($_SESSION['tipo']) || $_SESSION['tipo'] != 1) {
    die('Acceso restringido: inicia sesión como administrador (tipo 1) en Inkpulse y vuelve a abrir este panel.');
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../includes/prueba_post2.php"); // listar_documentos_salida_almacen()
require_once("../includes/prueba_get2.php");  // obtener_documento_salida_por_id()

// -----------------------------------------------------------------------
// Parámetros de consulta (formulario simple, todos con valor por defecto)
// -----------------------------------------------------------------------
$documentoTipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? $_GET['tipo'] : 'FV';
$prefijo       = isset($_GET['prefijo']) ? trim($_GET['prefijo']) : '';
$monedaId      = '31'; // Peso Colombiano — filtro obligatorio de la API, no es un criterio de búsqueda real (ver nota abajo)
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 10;
$verDetalle    = isset($_GET['ver']) ? $_GET['ver'] : null;

$filtros = [
    crear_filtro_api('documentoTipo.codigoDocumento', $documentoTipo, 0),
    crear_filtro_api('moneda.id', $monedaId, 4),
];
if ($prefijo !== '') {
    // "prefijo" (ej: FVE, CEUR, RE22) es la serie/consecutivo dentro de un mismo tipo de
    // documento (ej: el tipo REM se divide en los prefijos RE22 y CEUR) — filtro exacto.
    $filtros[] = crear_filtro_api('prefijo.nombre', $prefijo, 0, 0);
}

$inicio = microtime(true);
$lista = listar_documentos_salida_almacen($filtros, $pagina, $porPagina);
$ms_lista = round((microtime(true) - $inicio) * 1000);

$es_error = isset($lista['status']) && in_array($lista['status'], ['error', 'FORBIDDEN'], true);
$pag = $lista['data'] ?? null;
$filas = $pag['content'] ?? [];
$totalElementos = $pag['totalElements'] ?? null;
$totalPaginas = $pag['totalPages'] ?? null;

$detalle = null;
$ms_detalle = null;
$renglones = null;
$ms_renglones = null;
if ($verDetalle !== null) {
    $inicio2 = microtime(true);
    $detalle = obtener_documento_salida_por_id($verDetalle);
    $ms_detalle = round((microtime(true) - $inicio2) * 1000);

    $inicio3 = microtime(true);
    $renglones = obtener_renglones_documento($verDetalle);
    $ms_renglones = round((microtime(true) - $inicio3) * 1000);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 0, ',', '.'); }
function dinero($v) { return number_format((float)$v, 2, ',', '.'); }

function badge($condicion, $etiqueta) {
    if ($condicion) return '<span class="badge si">' . h($etiqueta) . '</span>';
    return '<span class="badge no">' . h($etiqueta) . '</span>';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Documentos de venta (World Office) — Inkpulse</title>
<style>
    body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background:#f4f6f8; margin:0; padding:24px; color:#1f2937; }
    h1 { font-size:1.35rem; margin-bottom:4px; }
    .subt { color:#6b7280; margin-bottom:18px; font-size:.88rem; max-width:900px; }
    form.filtros { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; display:flex; gap:14px; align-items:end; flex-wrap:wrap; margin-bottom:18px; }
    form.filtros label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.filtros input { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; width:110px; }
    form.filtros button { background:#111827; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .aviso { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.85rem; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    th, td { text-align:left; padding:8px 10px; font-size:.82rem; border-bottom:1px solid #f0f1f3; vertical-align:top; }
    th { background:#111827; color:#fff; font-weight:600; }
    tr:hover td { background:#f9fafb; }
    .badge { display:inline-block; padding:1px 7px; border-radius:5px; font-size:.68rem; font-weight:700; margin:1px 2px 1px 0; }
    .badge.si { background:#dcfce7; color:#166534; }
    .badge.no { background:#f3f4f6; color:#9ca3af; }
    .paginacion { margin-top:14px; font-size:.85rem; display:flex; gap:10px; align-items:center; }
    .paginacion a { color:#2563eb; text-decoration:none; }
    .ver { color:#2563eb; text-decoration:none; font-size:.8rem; }
    .panel-detalle { margin-top:20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; }
    .panel-detalle h2 { margin-top:0; font-size:1.05rem; }
    .grid { display:grid; grid-template-columns:repeat(2, minmax(220px,1fr)); gap:8px 24px; font-size:.85rem; }
    .grid div b { display:block; color:#6b7280; font-size:.72rem; font-weight:600; text-transform:uppercase; }
    .concepto { margin-top:12px; padding:10px; background:#f9fafb; border-radius:8px; font-size:.85rem; }
    details.crudo summary { cursor:pointer; margin-top:12px; color:#6b7280; font-size:.8rem; }
    pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; overflow:auto; font-size:.75rem; max-height:400px; }
    .exclusion { margin-top:24px; font-size:.78rem; color:#9ca3af; max-width:900px; }
    nav.tabs { margin-bottom:16px; }
    nav.tabs a { color:#6b7280; text-decoration:none; font-size:.85rem; margin-right:16px; }
    nav.tabs a.activo { color:#111827; font-weight:700; border-bottom:2px solid #111827; padding-bottom:4px; }
    form.buscar { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; display:flex; gap:10px; align-items:end; margin-bottom:14px; }
    form.buscar label { font-size:.75rem; color:#6b7280; display:block; margin-bottom:3px; }
    form.buscar input { border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:.85rem; width:140px; }
    form.buscar button { background:#2563eb; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-size:.85rem; cursor:pointer; }
    .nota { font-size:.72rem; color:#9ca3af; }
</style>
</head>
<body>

<nav class="tabs">
    <a href="visualizar_documentos_venta.php" class="activo">Documentos de venta</a>
    <a href="visualizar_terceros.php">Terceros</a>
    <a href="visualizar_inventario.php">Inventario</a>
    <a href="visualizar_reporte_ventas.php">Ventas por producto</a>
</nav>

<h1>Documentos de venta — World Office (Eureka)</h1>
<p class="subt">Solo lectura: <code>listarDocumentoSalidaAlmacen</code> + <code>getDocumentoSalidaAlmacenId</code>. No se crean, editan, eliminan, anulan ni contabilizan documentos aquí.</p>

<form class="buscar" method="get">
    <div>
        <label>Buscar documento por ID</label>
        <input type="text" name="ver" value="<?= h($verDetalle ?? '') ?>" placeholder="ej: 558">
    </div>
    <button type="submit">Ver detalle</button>
    <span class="nota">Usa <code>getDocumentoSalidaAlmacenId</code> directo, sin pasar por el listado.</span>
</form>

<form class="filtros" method="get">
    <div>
        <label>Tipo documento</label>
        <input type="text" name="tipo" value="<?= h($documentoTipo) ?>" title="Códigos verificados: FV, RC, CE, NC, REM. Otros códigos dan 403 TIPO_DOCUMENTO_NO_ADMITO_API si no están habilitados para la API.">
    </div>
    <div>
        <label>Prefijo (opcional)</label>
        <input type="text" name="prefijo" value="<?= h($prefijo) ?>" placeholder="ej: CEUR, RE22, FVE" title="Serie/consecutivo dentro del tipo elegido. Ej: el tipo REM se divide en los prefijos RE22 y CEUR.">
    </div>
    <div>
        <label>Registros por página</label>
        <input type="number" name="porPagina" value="<?= h($porPagina) ?>" min="1" max="50">
    </div>
    <input type="hidden" name="pagina" value="0">
    <button type="submit">Consultar listado</button>
    <span class="nota">El filtro de moneda queda fijo en 31 (COP): la API lo exige para listar, pero no es un criterio de búsqueda útil.</span>
</form>

<?php if ($es_error): ?>
    <div class="aviso">
        La API respondió con error para este filtro.
        <pre><?= h(json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php else: ?>

    <table>
        <tr>
            <th>Documento</th>
            <th>Fecha</th>
            <th>Cliente (tercero externo)</th>
            <th>Responsable</th>
            <th>Forma de pago</th>
            <th>Estados</th>
            <th></th>
        </tr>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td><?= h($f['prefijo'] ?? '') . '-' . h($f['numero'] ?? '') ?><br><span style="color:#9ca3af;font-size:.72rem;">id <?= h($f['id'] ?? '') ?></span></td>
                <td><?= h($f['fecha'] ?? '') ?></td>
                <td><?= h($f['terceroExterno'] ?? '') ?></td>
                <td><?= h($f['responsable'] ?? '') ?></td>
                <td><?= h($f['formaPago'] ?? '') ?></td>
                <td>
                    <?= badge(!empty($f['senContabilizado']), 'Contabilizado') ?>
                    <?= badge(!empty($f['senCuadrado']), 'Cuadrado') ?>
                    <?= badge(!empty($f['senPresentadoElectronicamente']), 'Electrónica') ?>
                    <?= !empty($f['senAnulado']) ? '<span class="badge no" style="background:#fee2e2;color:#991b1b;">Anulado</span>' : '' ?>
                </td>
                <td><a class="ver" href="?<?= http_build_query(array_merge($_GET, ['ver' => $f['id']])) ?>#detalle">Ver detalle</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
            <tr><td colspan="7" style="text-align:center;color:#9ca3af;">Sin resultados para este filtro.</td></tr>
        <?php endif; ?>
    </table>

    <div class="paginacion">
        <span>Página <?= $pagina + 1 ?> de <?= $totalPaginas ?? '?' ?> — <?= $totalElementos ?? '?' ?> documentos en total (<?= $ms_lista ?> ms)</span>
        <?php if ($pagina > 0): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>">&laquo; anterior</a><?php endif; ?>
        <?php if ($totalPaginas && $pagina + 1 < $totalPaginas): ?><a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>">siguiente &raquo;</a><?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($detalle !== null): ?>
    <div class="panel-detalle" id="detalle">
        <h2>Detalle del documento <?= h($verDetalle) ?> (<?= $ms_detalle ?> ms)</h2>
        <?php if (($detalle['status'] ?? '') === 'error'): ?>
            <div class="aviso"><?= h($detalle['mensaje_interno'] ?? 'Error consultando el detalle.') ?></div>
        <?php else:
            $d = $detalle['data'] ?? [];
        ?>
            <div class="grid">
                <div><b>Empresa</b><?= h($d['empresa']['nombre'] ?? '') ?></div>
                <div><b>Ciudad empresa</b><?= h($d['empresa']['ubicacionCiudad']['nombre'] ?? '') ?></div>
                <div><b>Cliente</b><?= h($d['terceroExterno']['nombreCompleto'] ?? '') ?> (<?= h($d['terceroExterno']['identificacion'] ?? '') ?>)</div>
                <div><b>Dirección de entrega</b><?= h($d['direccionTerceroExterno']['direccion'] ?? '') ?>, <?= h($d['direccionTerceroExterno']['ciudad'] ?? '') ?></div>
                <div><b>Vendedor</b><?= h($d['terceroInterno']['nombreCompleto'] ?? '') ?></div>
                <div><b>Forma de pago</b><?= h($d['formaPago']['nombre'] ?? '') ?></div>
                <div><b>Moneda</b><?= h($d['moneda']['nombre'] ?? '') ?> (<?= h($d['moneda']['simbolo'] ?? '') ?>)</div>
                <div><b>Estado</b><?= h($d['documentoEncabezadosEstados'][0]['nombre'] ?? '') ?></div>
            </div>
            <?php if (!empty($d['concepto'])): ?>
                <div class="concepto"><b style="display:block;color:#6b7280;font-size:.72rem;text-transform:uppercase;">Concepto (suele traer el colegio/pedido de origen)</b><?= nl2br(h($d['concepto'])) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <h3 style="margin-top:18px;font-size:.95rem;">Productos (<?= $ms_renglones ?> ms)</h3>
        <?php
        $filasRenglones = ($renglones['status'] ?? '') === 'error' ? [] : ($renglones['data']['content'] ?? []);
        $totalFacturaBruto = 0;
        $totalFacturaDescuento = 0;
        $totalFacturaNeto = 0;
        foreach ($filasRenglones as $r) {
            $cantidad = (float)($r['cantidad'] ?? 0);
            $pvp = (float)($r['valorUnitario'] ?? 0);
            $pctDescuento = (float)($r['porcentajeDescuento'] ?? 0);
            $bruto = $cantidad * $pvp;
            $descuentoValor = $bruto * $pctDescuento;
            $totalFacturaBruto += $bruto;
            $totalFacturaDescuento += $descuentoValor;
            $totalFacturaNeto += $bruto - $descuentoValor;
        }
        ?>
        <?php if (($renglones['status'] ?? '') === 'error'): ?>
            <div class="aviso"><?= h($renglones['mensaje_interno'] ?? 'Error consultando los renglones.') ?></div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Libro (ISBN)</th>
                    <th class="num" style="text-align:right;">Cant.</th>
                    <th class="num" style="text-align:right;">Precio PVP</th>
                    <th class="num" style="text-align:right;">Subtotal (cant. × PVP)</th>
                    <th class="num" style="text-align:right;">% Desc.</th>
                    <th class="num" style="text-align:right;">Descuento</th>
                    <th class="num" style="text-align:right;">Total renglón (neto)</th>
                </tr>
                <?php foreach ($filasRenglones as $r):
                    $cantidad = (float)($r['cantidad'] ?? 0);
                    $pvp = (float)($r['valorUnitario'] ?? 0);
                    $pctDescuento = (float)($r['porcentajeDescuento'] ?? 0);
                    $bruto = $cantidad * $pvp;
                    $descuentoValor = $bruto * $pctDescuento;
                    $neto = $bruto - $descuentoValor;
                ?>
                    <tr>
                        <td><?= h($r['inventario']['descripcion'] ?? '') ?><br><span style="color:#9ca3af;font-size:.72rem;"><?= h($r['inventario']['codigo'] ?? '') ?></span></td>
                        <td style="text-align:right;"><?= money($cantidad) ?></td>
                        <td style="text-align:right;">$<?= dinero($pvp) ?></td>
                        <td style="text-align:right;">$<?= dinero($bruto) ?></td>
                        <td style="text-align:right;"><?= round($pctDescuento * 100, 1) ?>%</td>
                        <td style="text-align:right;">-$<?= dinero($descuentoValor) ?></td>
                        <td style="text-align:right;">$<?= dinero($neto) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filasRenglones): ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;">Este documento no tiene renglones (ej: recibos de caja, comprobantes de egreso).</td></tr>
                <?php else: ?>
                    <tr style="font-weight:700;background:#f9fafb;">
                        <td colspan="3" style="text-align:right;">Totales</td>
                        <td style="text-align:right;">$<?= dinero($totalFacturaBruto) ?></td>
                        <td></td>
                        <td style="text-align:right;">-$<?= dinero($totalFacturaDescuento) ?></td>
                        <td style="text-align:right;">$<?= dinero($totalFacturaNeto) ?></td>
                    </tr>
                <?php endif; ?>
            </table>
            <p class="nota">Cálculo por renglón: subtotal = cantidad × precio PVP; descuento = subtotal × % descuento; total del renglón = subtotal − descuento. El total de factura es la suma de los totales netos de cada renglón (no incluye IVA si aplicara).</p>
        <?php endif; ?>

        <details class="crudo">
            <summary>Ver JSON crudo de la respuesta (encabezado)</summary>
            <pre><?= h(json_encode($detalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
        <details class="crudo">
            <summary>Ver JSON crudo de la respuesta (renglones)</summary>
            <pre><?= h(json_encode($renglones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
    </div>
<?php endif; ?>

<p class="exclusion">
    No se prueban aquí (ni existen accesibles para esta cuenta según el catálogo público de World Office): crear/editar/eliminar terceros, inventarios, documentos o cuentas contables; anular o contabilizar documentos; enviar documentos por correo; ni facturación electrónica. Este panel solo lista y consulta detalle.
</p>

</body>
</html>
