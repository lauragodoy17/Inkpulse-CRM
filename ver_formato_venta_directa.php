<?php
/**
 * /ver_formato_venta_directa.php?id=N[&acta=entrega|recepcion]
 * Impresiones del formato de venta directa (se firma en papel, pedido de la usuaria 2026-10-22). Cada acta
 * con firmas arrastra lo de los pasos anteriores para que se pueda verificar en papel:
 *   acta=entrega    datos generales + solicitud + inventario a entregar/entregado, cajas y firmas de
 *                   aux. de bodega que entrega y responsable de la venta.
 *   acta=recepcion  lo anterior + devolución documentada (devueltas por fila, documento y saldo) con
 *                   columnas de recibido/diferencia y firma del aux. de bodega que recibe.
 *   (sin acta)      formato completo, mismo orden del Excel que reemplaza: inventario, caja, firmas,
 *                   observaciones e historial de etapas.
 * Una firma ya registrada (dibujada o subida) sale impresa; si no, queda el espacio para firmar.
 * Se diligencia en formato_venta_directa.php. Ver includes/formatos_vd_datos.php.
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/formatos_vd_datos.php");

dm_validar_acceso();
fvd_asegurar_tablas($bdd);

$f = fvd_obtener($bdd, (int)($_GET['id'] ?? 0));
if (!$f) {
  header('Location: formatos_venta_directa.php?ink_status=error&ink_msg=' . urlencode('El formato no existe o no está en tu alcance.'));
  exit;
}
// Formato automático (creado con la devolución): el documento se genera solo cuando el cierre de dinero está confirmado.
if ($f['automatico'] && $f['activo'] && $f['etapa'] < FVD_ETAPA_CERRADO) {
  header('Location: formato_venta_directa.php?id=' . (int)$f['id'] . '&ink_status=info&ink_msg=' . urlencode('El formato se genera al confirmar el cierre de dinero.'));
  exit;
}
// Datos de la venta que falten (jornada, contacto y dirección del colegio, responsable del pedido).
if ($f['automatico'] && fvd_completar_datos_venta($bdd, $f['id'])) $f = fvd_obtener($bdd, $f['id']);
// Pedido y remisiones de cada fila (2026-11-23): se consultan una vez y quedan guardados.
if (fvd_asegurar_referencias($bdd, $f)) $f = fvd_obtener($bdd, $f['id']);
$acta = !$f['automatico'] && in_array($_GET['acta'] ?? '', ['entrega', 'recepcion'], true) ? $_GET['acta'] : 'completo';
if ($acta === 'recepcion' && !$f['legalizacion'] && $f['etapa'] < FVD_ETAPA_RECIBIDO) $acta = 'completo'; // sin devolución no hay acta de recepción
$anulado = !$f['activo'];
$hist = fvd_historial($bdd, $f['id']);
$entregado = $f['etapa'] >= FVD_ETAPA_ENTREGADO;
$devuelto = $f['etapa'] >= FVD_ETAPA_DEV_GENERADA;
$conciliado = $f['etapa'] >= FVD_ETAPA_CONCILIADO; // vendidas, inventario final y precios guardados
$recibido = $f['etapa'] >= FVD_ETAPA_RECIBIDO;
// Formato automático (2026-11-13): sin entrega ni recepción en bodega; no lleva auxiliares de bodega, firmas ni recibidas.
$sin_bodega = !empty($f['automatico']);
$pesos = fn($v) => '$ ' . number_format((float)$v, 0, ',', '.');
$h = fn($v) => htmlspecialchars((string)$v);
$horas = trim(($f['hora_inicio'] ? date('g:i A', strtotime($f['hora_inicio'])) : '') . ($f['hora_fin'] ? ' - ' . date('g:i A', strtotime($f['hora_fin'])) : ''));
$t = ['sol' => 0, 'prop' => 0, 'ini' => 0, 'dev' => 0, 'fin' => 0, 'vend' => 0, 'rec' => 0];
foreach ($f['items'] as $it) {
  // Los libros de un paquete abierto ya cuentan en la fila del paquete (solicitud / entregado).
  if (!fvd_item_de_devolucion($it) || $it['tipo'] === FVD_TIPO_SUELTO_PAQ) { $t['sol'] += $it['solicitud']; $t['prop'] += $it['inv_propuesto']; $t['ini'] += $it['inv_inicial']; }
  $t['dev'] += (int)$it['devueltas'];
  $t['fin'] += $it['inv_final']; $t['vend'] += $it['vendidas']; $t['rec'] += (int)$it['recibidas'];
  // Vendidas según la devolución: el libro suelto de un paquete abierto resta.
  $t['vend_dev'] = ($t['vend_dev'] ?? 0) + fvd_vendidas_item($it);
}
// Fecha en que se completó una etapa (último registro del historial).
$fecha_etapa = function ($et) use ($hist) {
  $r = null;
  foreach ($hist as $x) if ($x['modulo'] === FVD_MODULO_ETAPAS && (int)$x['estado'] === (int)$et) $r = $x;
  return $r ? date('d/m/Y g:i A', strtotime($r['fecha'])) : '';
};
$firma = function ($tipo) use ($f, $h) {
  $fi = $f['firmas'][$tipo] ?? null;
  if (!$fi) return '';
  return ($fi['es_pdf'] ? '<a href="' . $h($fi['url']) . '" target="_blank">Firma (PDF)</a>' : '<img src="' . $h($fi['url']) . '" alt="Firma">')
       . '<div class="fx-det">' . $h($fi['nombre']) . ' · ' . $h(date('d/m/Y g:i A', strtotime($fi['created_at']))) . '</div>';
};
/** Recuadro para firmar en papel: la firma registrada si existe; si no, el espacio en blanco. */
$caja_firma = function ($tipo, $nombre, $fecha) use ($f, $h) {
  $fi = $f['firmas'][$tipo] ?? null; ?>
  <div class="fx-fbox">
    <div class="fx-fbox-tit"><?= $h(FVD_FIRMAS[$tipo]) ?></div>
    <div class="fx-fbox-area"><?php if ($fi && !$fi['es_pdf']): ?><img src="<?= $h($fi['url']) ?>" alt="Firma"><?php elseif ($fi): ?><a href="<?= $h($fi['url']) ?>" target="_blank">Firma registrada (PDF)</a><?php endif; ?></div>
    <div class="fx-fbox-lin"><span>Firma</span></div>
    <div class="fx-fbox-dato">Nombre: <b><?= $h($nombre) ?></b></div>
    <div class="fx-fbox-dato">Fecha y hora: <b><?= $h($fecha) ?></b></div>
  </div>
<?php };
$st = $bdd->prepare("SELECT CONCAT(TRIM(nombres), ' ', TRIM(apellidos)) FROM usuarios WHERE id = ?");
$st->execute([(int)$_SESSION['id']]);
$impreso_por = (string)$st->fetchColumn();
$titulo_acta = ['entrega' => 'Acta de entrega de inventario', 'recepcion' => 'Acta de recepción de devolución', 'completo' => ''][$acta];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - <?= $h(($titulo_acta ? $titulo_acta . ' · ' : '') . fvd_numero($f['consecutivo'])) ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .fx-doc { background:#fff; border-radius:16px; box-shadow:0 2px 12px rgba(15,23,42,.07); padding:26px 30px; }
    .fx-tit { text-align:center; font-size:1.15rem; font-weight:800; color:#0f172a; border:1px solid #cbd5e1; padding:8px; margin:0; }
    .fx-tit small { display:block; font-size:.8rem; font-weight:700; color:#475569; }
    .fx-col { text-align:center; font-weight:800; border:1px solid #cbd5e1; border-top:none; padding:6px; }
    .fx-anulado { display:inline-block; background:#fee2e2; color:#dc2626; border-radius:20px; padding:2px 12px; font-size:.78rem; font-weight:700; margin-left:8px; vertical-align:middle; }
    .fx-pend { display:inline-block; background:#fef3c7; color:#b45309; border-radius:20px; padding:2px 12px; font-size:.78rem; font-weight:700; margin-left:8px; vertical-align:middle; }
    .fx-leg { display:inline-block; background:#dcfce7; color:#15803d; border-radius:20px; padding:2px 12px; font-size:.78rem; font-weight:700; margin-left:8px; vertical-align:middle; }
    .fx-datos { display:grid; grid-template-columns:1fr 1fr; border:1px solid #cbd5e1; border-top:none; font-size:.85rem; }
    .fx-datos div { padding:5px 10px; border-bottom:1px solid #e2e8f0; }
    .fx-datos b { text-transform:uppercase; font-size:.78rem; }
    .fx-sec { font-size:.8rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f172a; background:#e2e8f0; border:1px solid #cbd5e1; padding:5px 10px; margin:16px 0 0; }
    .fx-tabla { width:100%; font-size:.84rem; border-collapse:collapse; margin-top:14px; }
    .fx-sec + .table-responsive .fx-tabla, .fx-sec + .fx-tabla { margin-top:0; }
    .fx-tabla th { background:#f1f5f9; color:#0f172a; font-size:.72rem; font-weight:800; padding:7px; text-align:center; border:1px solid #cbd5e1; text-transform:uppercase; }
    .fx-tabla td { padding:6px 8px; border:1px solid #cbd5e1; }
    .fx-tabla tbody tr:nth-child(even) td { background:#f8fafc; }
    .fx-tabla .c { text-align:center; } .fx-tabla .r { text-align:right; white-space:nowrap; }
    .fx-tabla td.fx-blanco { min-width:90px; background:#fff !important; }
    .fx-tabla tfoot td { font-weight:800; border-top:2px solid #0f172a; }
    .fx-tabla tbody tr.fx-curso.fx-plan td { background:#dcfce7 !important; color:#166534; }
    .fx-tabla th .fx-th-ref { font-size:.68rem; font-weight:700; color:#1d4ed8; text-transform:none; margin-top:2px; }
    .fx-tabla tbody tr.fx-curso td { background:#e2e8f0 !important; font-weight:800; font-size:.74rem; text-transform:uppercase; letter-spacing:.03em; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .fx-det { font-size:.74rem; color:#475569; }
    .fx-resumen { display:grid; grid-template-columns:repeat(3,1fr); border:1px solid #cbd5e1; border-top:none; font-size:.84rem; }
    .fx-resumen div { padding:5px 10px; border-right:1px solid #e2e8f0; }
    .fx-resumen b { text-transform:uppercase; font-size:.74rem; }
    .fx-caja { display:grid; grid-template-columns:minmax(260px,1.2fr) 1fr; gap:18px; margin-top:18px; }
    .fx-caja table { width:100%; font-size:.84rem; border-collapse:collapse; }
    .fx-caja td { padding:5px 10px; border:1px solid #cbd5e1; }
    .fx-caja td:first-child { background:#e5e7eb; font-weight:700; text-transform:uppercase; font-size:.76rem; }
    .fx-caja td.r { text-align:right; white-space:nowrap; width:42%; }
    .fx-firmas td { height:70px; vertical-align:top; text-align:center; font-weight:800; font-size:.8rem; background:#fff !important; text-transform:uppercase; }
    .fx-firmas img { max-width:220px; max-height:70px; display:block; margin:4px auto 0; }
    .fx-firmas .fx-det { text-transform:none; font-weight:500; }
    .fx-fboxes { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:16px; margin-top:16px; page-break-inside:avoid; }
    .fx-fbox { border:1px solid #cbd5e1; padding:10px 12px; font-size:.82rem; }
    .fx-fbox.ref { opacity:.85; }
    .fx-fbox-tit { font-weight:800; text-transform:uppercase; font-size:.74rem; text-align:center; }
    .fx-fbox-area { height:78px; display:flex; align-items:flex-end; justify-content:center; }
    .fx-fbox-area img { max-width:100%; max-height:74px; }
    .fx-fbox-lin { border-top:1.5px solid #0f172a; text-align:center; font-size:.7rem; color:#475569; margin-bottom:6px; }
    .fx-fbox-dato { margin-top:3px; } .fx-fbox-dato b { font-weight:600; display:inline-block; min-width:60%; border-bottom:1px dotted #94a3b8; }
    .fx-obs { border:1px solid #cbd5e1; margin-top:18px; }
    .fx-obs div:first-child { text-align:center; font-weight:800; padding:6px; border-bottom:1px solid #cbd5e1; text-transform:uppercase; font-size:.82rem; }
    .fx-obs div:last-child { padding:10px; min-height:60px; font-size:.85rem; white-space:pre-wrap; }
    .fx-pie { margin-top:14px; font-size:.72rem; color:#64748b; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .fx-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:600; border:1.5px solid #1d4ed8; color:#1d4ed8; background:#fff; text-decoration:none; cursor:pointer; }
    .fx-btn:hover { background:#1d4ed8; color:#fff; text-decoration:none; }
    .fx-btn.prim, .fx-btn.on { background:#1d4ed8; color:#fff; } .fx-btn.prim:hover { opacity:.9; }
    @media (max-width:768px) { .fx-datos, .fx-caja, .fx-resumen { grid-template-columns:1fr; } }
    @media print {
      .left-side-bar, .header, .footer-wrap, .fx-acciones, .mobile-menu-overlay, .fx-noprint { display:none !important; }
      .main-container { padding:0 !important; margin:0 !important; }
      .fx-doc { box-shadow:none; padding:0; }
      a[href]:after { content:none !important; }
      .fx-tabla th, .fx-sec { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="fx-acciones" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if (!$sin_bodega): /* Formato automático (2026-11-19): sin actas de entrega ni de recepción */ ?>
          <a href="ver_formato_venta_directa.php?id=<?= (int)$f['id'] ?>&acta=entrega" class="fx-btn <?= $acta === 'entrega' ? 'on' : '' ?>"><i class="bi bi-box-seam"></i> Acta de entrega</a>
          <?php if ($f['legalizacion'] || $recibido): ?><a href="ver_formato_venta_directa.php?id=<?= (int)$f['id'] ?>&acta=recepcion" class="fx-btn <?= $acta === 'recepcion' ? 'on' : '' ?>"><i class="bi bi-box-arrow-in-down"></i> Acta de recepción</a><?php endif; ?>
          <a href="ver_formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="fx-btn <?= $acta === 'completo' ? 'on' : '' ?>"><i class="bi bi-file-earmark-text"></i> Formato completo</a>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="php/formato_vd_excel.php?id=<?= (int)$f['id'] ?>" class="fx-btn"><i class="bi bi-file-earmark-excel"></i> Descargar Excel</a>
          <button type="button" class="fx-btn" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
          <a href="formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="fx-btn prim"><?= $f['activo'] && $f['etapa'] < FVD_ETAPA_CERRADO ? 'Continuar el formato' : 'Ver etapas' ?> <i class="bi bi-arrow-right"></i></a>
        </div>
      </div>

      <?php if ($anulado) echo html_motivo_anulacion(obtener_historial_estado($bdd, FVD_MODULO_HISTORIAL, $f['id'], ESTADO_ANULADO), 'Formato anulado'); ?>

      <div class="fx-doc">
        <p class="fx-tit"><?= $titulo_acta ? $h(mb_strtoupper($titulo_acta)) . '<small>' . $h(fvd_numero($f['consecutivo'])) . '</small>' : $h(mb_strtoupper(fvd_numero($f['consecutivo']))) ?>
          <?= $anulado ? '<span class="fx-anulado">Anulado</span>' : ($f['etapa'] >= FVD_ETAPA_CERRADO ? '<span class="fx-leg fx-noprint">Operación cerrada</span>' : '<span class="fx-pend fx-noprint">' . $h(fvd_etapa_texto($f['etapa'])) . '</span>') ?></p>
        <div class="fx-col"><?= $h(mb_strtoupper($f['colegio'])) ?><?= $f['numero_adopcion'] ? ' (' . $h($f['numero_adopcion']) . ')' : '' ?></div>
        <div class="fx-datos">
          <div><b>Fecha venta:</b> <?= $h(date('d/m/Y', strtotime($f['fecha_venta']))) ?></div>
          <div><b>Hora de venta:</b> <?= $h($horas) ?></div>
          <div><b>Responsable venta:</b> <?= $h($f['responsable']) ?></div>
          <div><b>Tel del responsable:</b> <?= $h($f['tel_responsable']) ?></div>
          <div><b>Responsable colegio:</b> <?= $h($f['contacto_colegio']) ?></div>
          <div><b>Tel contacto:</b> <?= $h($f['tel_contacto']) ?></div>
          <div><b>Dirección:</b> <?= $h($f['direccion']) ?></div>
          <div><b>Auxiliares:</b> <?= $h(fvd_auxiliares_texto($f)) ?><?= $f['auxiliares_total'] ? ' · Total ' . $pesos($f['auxiliares_total']) : '' ?></div>
          <div><b>Pedidos:</b>
            <?php foreach (explode(',', $f['ids_pedidos']) as $i => $c): $idp = (int)substr($c, 1); ?><?= $i ? ', ' : '' ?><a href="pedido_colegio.php?id_pedido=<?= $idp ?>" target="_blank">#<?= $idp ?></a><?php endforeach; ?>
            · Período <?= $h($f['periodo']) ?></div>
          <div><b>Solicitó:</b> <?= $h($f['usuario'] ?: '—') ?> · <?= $h(date('d/m/Y g:i A', strtotime($f['created_at']))) ?></div>
        </div>

<?php if ($acta === 'entrega'): ?>
        <p class="fx-sec">1. Solicitud y entrega de inventario</p>
        <div class="table-responsive">
          <table class="fx-tabla">
            <thead><tr><th style="text-align:left;">Paquete / título</th><th>Solicitud</th><?php if (!$entregado): ?><th>Despachado (propuesto)</th><?php endif; ?><th>Inventario entregado</th></tr></thead>
            <tbody>
              <?php foreach ($f['items'] as $it): if (fvd_item_de_devolucion($it)) continue; /* no se entregan: salen en la devolución */ ?>
              <tr>
                <td><?= $h($it['descripcion']) ?><?php if ((string)$it['detalle'] !== ''): ?><div class="fx-det"><?= $h($it['detalle']) ?></div><?php endif; ?></td>
                <td class="c"><?= (int)$it['solicitud'] ?></td>
                <?php if (!$entregado): ?><td class="c"><?= (int)$it['inv_propuesto'] ?></td><td class="fx-blanco"></td>
                <?php else: ?><td class="c"><strong><?= (int)$it['inv_inicial'] ?></strong></td><?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="c">TOTALES</td><td class="c"><?= $t['sol'] ?></td><?php if (!$entregado): ?><td class="c"><?= $t['prop'] ?></td><td></td><?php else: ?><td class="c"><?= $t['ini'] ?></td><?php endif; ?></tr></tfoot>
          </table>
        </div>
        <div class="fx-resumen">
          <div><b>Total de cajas:</b> <?= $entregado ? (int)$f['total_cajas'] : '________' ?></div>
          <div><b>Aux. bodega que entrega:</b> <?= $h($f['aux_entrega']) ?></div>
          <div><b>Entrega confirmada:</b> <?= $h($fecha_etapa(FVD_ETAPA_ENTREGADO)) ?></div>
        </div>
        <div class="fx-fboxes">
          <?php $caja_firma('entrega_aux', $f['aux_entrega'], isset($f['firmas']['entrega_aux']) ? date('d/m/Y g:i A', strtotime($f['firmas']['entrega_aux']['created_at'])) : ''); ?>
          <?php $caja_firma('entrega_resp', $f['responsable'], isset($f['firmas']['entrega_resp']) ? date('d/m/Y g:i A', strtotime($f['firmas']['entrega_resp']['created_at'])) : ''); ?>
        </div>
        <div class="fx-det" style="margin-top:8px;">El responsable de la venta recibe el inventario relacionado para la venta en el colegio y responde por él hasta su devolución a bodega.</div>
        <div class="fx-obs"><div>Observaciones</div><div><?= $h($f['observaciones']) ?></div></div>

<?php elseif ($acta === 'recepcion'): ?>
        <p class="fx-sec">1. Entrega de inventario (confirmada <?= $h($fecha_etapa(FVD_ETAPA_ENTREGADO)) ?>)</p>
        <div class="fx-resumen" style="border-top:1px solid #cbd5e1;">
          <div><b>Aux. bodega que entregó:</b> <?= $h($f['aux_entrega']) ?></div>
          <div><b>Responsable de la venta:</b> <?= $h($f['responsable']) ?></div>
          <div><b>Total de cajas:</b> <?= (int)$f['total_cajas'] ?></div>
        </div>
        <p class="fx-sec">2. Devolución documentada en InkPulse · Devolución #<?= (int)($f['legalizacion']['id'] ?? 0) ?><?= $f['legalizacion'] ? ' del ' . $h(date('d/m/Y g:i A', strtotime($f['legalizacion']['fecha']))) : '' ?><?php $sal = $f['legalizacion'] ? dvd_saldo_de_devolucion($bdd, (int)$f['legalizacion']['id']) : null; ?><?= $sal ? ' · Saldo ' . $h($sal['numero']) : '' ?></p>
        <div class="table-responsive">
          <table class="fx-tabla">
            <thead><tr><th style="text-align:left;">Paquete / título</th><th>Solicitud</th><th>Inventario entregado</th><th>Unidades devueltas (documento)</th><th>Unidades vendidas</th><th>Cantidad recibida en bodega</th><th>Diferencia</th></tr></thead>
            <tbody>
              <?php foreach ($f['items'] as $it): $dif = (int)$it['recibidas'] - (int)$it['devueltas']; ?>
              <tr>
                <td><?= $h($it['descripcion']) ?><?php if ($it['tipo'] === 'paquete'): ?><div class="fx-det">Cada paquete: <?= $h($it['detalle']) ?></div><?php if ((int)($it['abiertos'] ?? 0) > 0): ?><div class="fx-det"><?= (int)$it['abiertos'] ?> abierto(s): sus libros se venden por separado</div><?php endif; ?><?php elseif ($it['tipo'] === FVD_TIPO_SUELTO_PAQ): ?><div class="fx-det"><?= $h($it['detalle']) ?> (se resta de la venta)</div><?php elseif ($it['tipo'] === FVD_TIPO_ABIERTO): ?><div class="fx-det"><?= $h($it['detalle']) ?> (se vende por libro)</div><?php endif; ?></td>
                <td class="c"><?= (int)$it['solicitud'] ?></td>
                <td class="c"><?= (int)$it['inv_inicial'] ?></td>
                <td class="c"><strong><?= (int)$it['devueltas'] ?></strong></td>
                <td class="c"><?= fvd_vendidas_item($it) ?></td>
                <?php if ($recibido): ?><td class="c"><strong><?= (int)$it['recibidas'] ?></strong></td><td class="c"<?= $dif ? ' style="color:#dc2626;font-weight:700;"' : '' ?>><?= $dif > 0 ? '+' . $dif : $dif ?></td>
                <?php else: ?><td class="fx-blanco"></td><td class="fx-blanco"></td><?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="c">TOTALES</td><td class="c"><?= $t['sol'] ?></td><td class="c"><?= $t['ini'] ?></td><td class="c"><?= $t['dev'] ?></td><td class="c"><?= $t['vend_dev'] ?></td>
              <?php if ($recibido): ?><td class="c"><?= $t['rec'] ?></td><td class="c"><?= $t['rec'] - $t['dev'] ?></td><?php else: ?><td></td><td></td><?php endif; ?></tr></tfoot>
          </table>
        </div>
        <?php $det_doc = fvd_detalle_documento($bdd, $f); ?>
        <p class="fx-sec">Detalle por libro del documento (cada paquete = una unidad de cada uno de sus títulos)</p>
        <table class="fx-tabla">
          <thead><tr><th style="text-align:left;">Libro</th><th>ISBN</th><th style="text-align:left;">Sale de</th><th>Libros en el documento</th><th>En el saldo (vendido)</th></tr></thead>
          <tbody>
            <?php foreach ($det_doc as $r): ?>
            <tr><td><?= $h($r['libro'] ?? ('Libro #' . $r['id_libro'])) ?></td><td class="c"><?= $h($r['isbn'] ?? '') ?></td><td class="fx-det"><?= $h(implode(' + ', $r['origen']) ?: '—') ?></td>
              <td class="c"><strong><?= $r['en_documento'] ?></strong></td><td class="c"><?= $r['en_saldo'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td class="c" colspan="3">TOTALES</td><td class="c"><?= array_sum(array_column($det_doc, 'en_documento')) ?></td><td class="c"><?= array_sum(array_column($det_doc, 'en_saldo')) ?></td></tr></tfoot>
        </table>
        <p class="fx-sec">3. Recepción en bodega</p>
        <div class="fx-fboxes" style="margin-top:10px;">
          <?php $caja_firma('recibe_aux', $f['aux_recibe'], $f['recibido_at'] ? date('d/m/Y g:i A', strtotime($f['recibido_at'])) : ''); ?>
          <div class="fx-fbox ref"><div class="fx-fbox-tit">Firmas de la entrega (referencia)</div>
            <?php foreach (['entrega_aux' => $f['aux_entrega'], 'entrega_resp' => $f['responsable']] as $tp => $nom): $fi = $f['firmas'][$tp] ?? null; ?>
              <div style="display:flex;align-items:center;gap:8px;margin-top:6px;"><?php if ($fi && !$fi['es_pdf']): ?><img src="<?= $h($fi['url']) ?>" alt="Firma" style="max-width:110px;max-height:40px;"><?php endif; ?>
                <div class="fx-det"><b><?= $h(FVD_FIRMAS[$tp]) ?>:</b> <?= $h($nom) ?><?= $fi ? ' · ' . ($fi['origen'] === 'archivo' ? 'firma en papel subida' : 'firmó en pantalla') : '' ?></div></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="fx-obs"><div>Observaciones de la recepción (justifica cualquier diferencia)</div><div><?= $h($f['obs_recepcion']) ?></div></div>

<?php else: ?>
        <div class="table-responsive">
          <table class="fx-tabla">
            <?php $n_cols = 8 + ($recibido && !$sin_bodega ? 1 : 0); ?>
            <thead><tr><th style="text-align:left;">Título</th><?php $enc_sol = fvd_encabezado_solicitud($f); $enc_rem = fvd_encabezado_remisiones($f); /* pedidos y remisiones solo en el encabezado (2026-11-23) */ ?>
              <th>Solicitud<?php if ($enc_sol !== ''): ?><div class="fx-th-ref"><?= $h($enc_sol) ?></div><?php endif; ?></th>
              <th>Inventario entregado<?php if ($enc_rem !== ''): ?><div class="fx-th-ref"><?= $h($enc_rem) ?></div><?php endif; ?></th><th>Unidades devueltas</th><th>Recibido final</th><th>Unidades vendidas</th><th>Precio venta</th><th>Total venta</th><?php if ($recibido && !$sin_bodega): ?><th>Recibidas bodega</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach (fvd_filas_por_curso($bdd, $f) as $fila): if (isset($fila['curso'])): /* libros sueltos y plan lector por curso de la adopción (2026-11-23) */ ?>
              <tr class="fx-curso<?= strpos($fila['curso'], 'Plan lector') === 0 ? ' fx-plan' : '' ?>"><td colspan="<?= $n_cols ?>"><?= $h($fila['curso']) ?></td></tr>
              <?php continue; endif; $it = $fila['item']; ?>
              <tr>
                <td><?= $h($it['descripcion']) ?><?php if ($it['detalle'] !== '' && $it['detalle'] !== null): ?><div class="fx-det"><?= $h($it['detalle']) ?></div><?php endif; ?></td>
                <td class="c"><?= (int)$it['solicitud'] ?></td>
                <td class="c"><?= $entregado ? (int)$it['inv_inicial'] : '' ?></td>
                <td class="c"><?= $devuelto ? (int)$it['devueltas'] : '' ?></td>
                <td class="c"><?= $conciliado ? (int)$it['inv_final'] : '' ?></td>
                <td class="c"><?= $conciliado ? (int)$it['vendidas'] : '' ?></td>
                <td class="r"><?= $conciliado ? $pesos($it['precio']) : '' ?></td>
                <td class="r"><?= $conciliado ? $pesos($it['total']) : '' ?></td>
                <?php if ($recibido && !$sin_bodega): ?><td class="c"<?= (int)$it['recibidas'] !== (int)$it['devueltas'] ? ' style="color:#dc2626;font-weight:700;"' : '' ?>><?= (int)$it['recibidas'] ?></td><?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="c">TOTALES</td><td class="c"><?= $t['sol'] ?></td><td class="c"><?= $entregado ? $t['ini'] : '' ?></td><td class="c"><?= $devuelto ? $t['dev'] : '' ?></td>
              <td class="c"><?= $conciliado ? $t['fin'] : '' ?></td><td class="c"><?= $conciliado ? $t['vend'] : '' ?></td><td></td><td class="r"><?= $conciliado ? $pesos($f['total_venta']) : '' ?></td><?php if ($recibido && !$sin_bodega): ?><td class="c"><?= $t['rec'] ?></td><?php endif; ?></tr></tfoot>
          </table>
        </div>

        <?php if ($sin_bodega): ?>
        <div class="fx-caja">
          <div>
            <p class="fx-sec" style="margin-top:0;">Datos de la operación</p>
            <table>
              <tr><td>Total de cajas:</td><td class="r"><?= $f['total_cajas'] !== null ? (int)$f['total_cajas'] : '' ?></td></tr>
              <tr><td>Devolución InkPulse:</td><td class="r"><?= $f['legalizacion'] ? '#' . (int)$f['legalizacion']['id'] : '' ?></td></tr>
              <tr><td>Auxiliares:</td><td class="r" style="white-space:normal;"><?= $h(fvd_auxiliares_texto($f)) ?></td></tr>
              <tr><td>Valor total inventario devuelto</td><td class="r"><?= $pesos($f['valor_inventario']) ?></td></tr>
            </table>
          </div>
          <div>
            <p class="fx-sec" style="margin-top:0;">Cierre de dinero</p>
            <table>
              <tr><td>Base entregada:</td><td class="r"><?= $pesos($f['base_entregada']) ?></td></tr>
              <tr><td>Gastos:</td><td class="r"><?= $pesos($f['gastos']) ?></td></tr>
              <tr><td>Valor auxiliares:</td><td class="r"><?= $pesos($f['auxiliares_total']) ?></td></tr>
              <tr><td>Base devuelta:</td><td class="r"><?= $pesos($f['base_devuelta']) ?></td></tr>
              <tr><td>Efectivo</td><td class="r"><?= $pesos($f['efectivo']) ?></td></tr>
              <tr><td>Datáfono</td><td class="r"><?= $pesos($f['datafono']) ?></td></tr>
              <tr><td>Bre-B</td><td class="r"><?= $pesos($f['breb']) ?></td></tr>
              <tr><td>Suma total recaudada</td><td class="r"><strong><?= $pesos($f['suma_total']) ?></strong></td></tr>
              <tr><td>Total venta</td><td class="r"><strong><?= $pesos($f['total_venta']) ?></strong></td></tr>
              <tr><td>Diferencia</td><td class="r" style="color:<?= round((float)$f['diferencia']) == 0 ? '#15803d' : '#dc2626' ?>;font-weight:700;"><?= $pesos($f['diferencia']) ?></td></tr>
            </table>
          </div>
        </div>
        <?php else: ?>
        <div class="fx-caja">
          <table>
            <tr><td>Aux. bodega que entrega mercancía:</td><td class="r"><?= $h($f['aux_entrega']) ?></td></tr>
            <tr><td>Total de cajas:</td><td class="r"><?= $f['total_cajas'] !== null ? (int)$f['total_cajas'] : '' ?></td></tr>
            <tr><td>Devolución InkPulse:</td><td class="r"><?= $f['legalizacion'] ? '#' . (int)$f['legalizacion']['id'] : '' ?></td></tr>
            <tr><td>Base entregada:</td><td class="r"><?= $pesos($f['base_entregada']) ?></td></tr>
            <tr><td>Gastos:</td><td class="r"><?= $pesos($f['gastos']) ?></td></tr>
            <tr><td>Valor auxiliares:</td><td class="r"><?= $pesos($f['auxiliares_total']) ?></td></tr>
            <tr><td>Base devuelta:</td><td class="r"><?= $pesos($f['base_devuelta']) ?></td></tr>
            <tr><td>Efectivo</td><td class="r"><?= $pesos($f['efectivo']) ?></td></tr>
            <tr><td>Datáfono</td><td class="r"><?= $pesos($f['datafono']) ?></td></tr>
            <tr><td>Bre-B</td><td class="r"><?= $pesos($f['breb']) ?></td></tr>
            <tr><td>Suma total</td><td class="r"><?= $pesos($f['suma_total']) ?></td></tr>
            <tr><td>Valor total inventario</td><td class="r"><?= $pesos($f['valor_inventario']) ?></td></tr>
            <tr><td>Diferencia</td><td class="r" style="color:<?= round((float)$f['diferencia']) == 0 ? '#15803d' : '#dc2626' ?>;font-weight:700;"><?= $pesos($f['diferencia']) ?></td></tr>
            <tr><td>Aux. bodega que recibe mercancía:</td><td class="r"><?= $h($f['aux_recibe']) ?></td></tr>
            <tr><td>Fecha y hora recibido:</td><td class="r"><?= $f['recibido_at'] ? $h(date('d/m/Y g:i A', strtotime($f['recibido_at']))) : '' ?></td></tr>
          </table>
          <table class="fx-firmas">
            <tr><td>Firma aux. bodega que entrega<?= $firma('entrega_aux') ?></td></tr>
            <tr><td>Firma responsable de la venta<?= $firma('entrega_resp') ?></td></tr>
            <tr><td>Firma aux. bodega que recibe<?= $firma('recibe_aux') ?></td></tr>
          </table>
        </div>
        <?php endif; ?>

        <div class="fx-obs"><div>Observaciones</div><div><?= $h($f['observaciones']) ?><?= $f['obs_recepcion'] && !$sin_bodega ? "\n\nRecepción en bodega: " . $h($f['obs_recepcion']) : '' ?><?= $f['obs_cierre'] ? "\n\nCierre de dinero: " . $h($f['obs_cierre']) : '' ?></div></div>
        <?php /* Historial de etapas: ya no se muestra en el formato (2026-11-19, la usuaria). */ ?>
<?php endif; ?>
        <div class="fx-pie"><span><?= $h(fvd_numero($f['consecutivo'])) ?> · <?= $h($f['colegio']) ?></span><span>Impreso el <?= date('d/m/Y g:i A') ?> por <?= $h($impreso_por ?: '—') ?></span></div>
      </div>
    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
