<?php
/**
 * /formato_venta_directa.php?claves[]=v210&claves[]=v215   (nuevo: paso 1, desde Legalización venta directa)
 * /formato_venta_directa.php?id=N                            (continuar el flujo del formato)
 * Formato de venta directa por etapas (2026-10-22): 1 Solicitud · 2 Entrega de bodega · 3 Devolución ·
 * 4 Cierre del inventario · 5 Recepción en bodega · 6 Cierre de dinero. Solo se diligencia la etapa en
 * curso; las anteriores se muestran resueltas y las siguientes bloqueadas. Lógica en
 * includes/formatos_vd_datos.php; se guarda en php/formato_vd_guardar.php (que valida la etapa otra vez).
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/formatos_vd_datos.php");

dm_validar_acceso();
dm_asegurar_columnas($bdd);
fvd_asegurar_tablas($bdd);

$id_formato = (int)($_GET['id'] ?? 0);
$error = null;
$token = '';
$armado = null; // pantalla Armar paquetes (fvd_armado_datos)
$cl = null; // tabla por grado del cierre de ventas (fvdl_calcular)
$f = null;
$dos_dev = false; // formato con 2 devoluciones de venta directa (2026-11-24): vendidas a dos precios
$hist = [];
$etapa = 0; // 0 = formato nuevo (paso 1 sin guardar)
if ($id_formato) {
  $f = fvd_obtener($bdd, $id_formato);
  // Consecutivo reservado sin pedidos (2026-11-24): no hay nada que diligenciar hasta que llegue su devolución.
  if ($f && $f['reservado']) {
    header('Location: ver_formato_venta_directa.php?id=' . (int)$f['id']);
    exit;
  }
  if (!$f) $error = 'El formato no existe o no está en tu alcance.';
  else {
    // Pedido, remisiones y precio de la remisión de cada fila (2026-11-23): se consultan una vez y quedan guardados.
    if (fvd_asegurar_referencias($bdd, $f)) $f = fvd_obtener($bdd, $id_formato);
    // Devolución documentada: el cierre del inventario se calcula solo (y se reintenta si faltaba un precio).
    // Formato automático: también se salta la recepción en bodega (fvd_avanzar_automatico).
    if ($f['activo'] && (in_array($f['etapa'], [FVD_ETAPA_DEV_GENERADA, FVD_ETAPA_DEV_CONFIRMADA], true) || ($f['automatico'] && $f['etapa'] === FVD_ETAPA_CONCILIADO))
        && fvd_avanzar_automatico($bdd, $id_formato) !== $f['etapa']) {
      $f = fvd_obtener($bdd, $id_formato);
    }
    // Formato automático sin cerrar: completa los datos de la venta que falten (jornada, contacto, dirección, responsable).
    if ($f['automatico'] && $f['activo'] && fvd_completar_datos_venta($bdd, $id_formato)) $f = fvd_obtener($bdd, $id_formato);
    $etapa = $f['etapa'];
    $items = $f['items'];
    $colegio = $f['colegio'];
    $claves = explode(',', $f['ids_pedidos']);
    $v = $f;
    // Paso 2 (Entrega de bodega): propone el responsable de la venta escrito al aprobar el pedido; se puede cambiar.
    if ($etapa === FVD_ETAPA_SOLICITUD && trim((string)$v['responsable']) === '') $v['responsable'] = responsable_venta_de_pedidos($bdd, $claves);
    $hist = fvd_historial($bdd, $id_formato);
    // Nombres de los libros de las filas: vista previa por libro del documento de devolución (paso 3).
    $ids_libros = array_unique(array_filter(array_map('intval', explode(',', implode(',', array_column($items, 'libros'))))));
    $nombres_libros = $ids_libros ? $bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', $ids_libros) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    if ($etapa >= FVD_ETAPA_DEV_GENERADA) $verif = fvd_verificar_devolucion($bdd, $f);
    // Hasta confirmar el cierre del inventario se muestran los precios vigentes; después, los guardados.
    if ($etapa >= FVD_ETAPA_DEV_CONFIRMADA) [$conc, $tot] = fvd_conciliar($f, $etapa === FVD_ETAPA_DEV_CONFIRMADA ? fvd_precios_actuales($bdd, $f) : null);
    // Paquetes abiertos de la devolución (2026-11-13): descuento puesto a cada libro, para mostrarlo en el cierre.
    $abiertos_dev = [];
    $dos_dev = count($f['legalizacion']['ids'] ?? []) > 1;
    if ($etapa >= FVD_ETAPA_DEV_CONFIRMADA && !empty($f['legalizacion']['id'])) {
      require_once("includes/devoluciones_ventas_datos.php");
      foreach ($f['legalizacion']['ids'] as $id_dev_ab) $abiertos_dev += dvv_paquetes_abiertos($bdd, (int)$id_dev_ab); // 2 devoluciones de venta directa (2026-11-24)
    }
    // Token de la devolución: el mismo envío no genera dos devoluciones.
    if ($etapa === FVD_ETAPA_ENTREGADO) $token = bin2hex(random_bytes(16));
    // Paso 3: devoluciones registradas en Devoluciones de ventas que se pueden vincular (2026-11-10).
    $vinculables = $etapa === FVD_ETAPA_ENTREGADO && $f['activo'] ? fvd_devoluciones_para_vincular($bdd, $f) : [];
    // Armar paquetes con lo vendido suelto, después de la devolución (2026-11-19, fvd_armado_datos).
    $armado = $etapa >= FVD_ETAPA_DEV_GENERADA && $f['legalizacion'] ? fvd_armado_datos($bdd, $f) : null;
    // Tabla por grado del cierre de ventas (2026-11-26, includes/formatos_vd_libros.php): desde el cierre de dinero, una fila
    // por libro con Kit / Suelto; los kits se arman solos con la columna Kit (reemplaza la sección "Armar paquetes").
    $cl_filas = fvdl_sincronizar($bdd, $f);
    $cl = $cl_filas ? fvdl_calcular($bdd, $f, $cl_filas) : null;
    if ($cl) $armado = null;
  }
} else {
  $prop = fvd_propuesta($bdd, (array)($_GET['claves'] ?? []));
  if (!$prop['ok']) $error = $prop['error'];
  else {
    // La propuesta se guarda en la sesión con el token del formulario: al guardar se usan las mismas filas
    // (sin volver a consultar World Office) y el token impide registrar dos veces el mismo formato.
    $token = bin2hex(random_bytes(16));
    $_SESSION['fvd_prop'] = array_slice(($_SESSION['fvd_prop'] ?? []), -4, 4, true);
    $_SESSION['fvd_prop'][$token] = $prop;
    $items = $prop['items'];
    $colegio = $prop['colegio'];
    $claves = $prop['claves'];
    // Solo se prellena lo que se digitó al solicitar los pedidos para Andrea Díaz (pedidos.vd_*); sin eso,
    // el formulario sigue en blanco (no se adivinan datos — pedido de la usuaria 2026-10-21).
    $v = datos_fijos_formato_vd($bdd, $claves);
    $hist = [];
  }
}
// Datos generales que vienen de los pedidos para Andrea Díaz: se muestran bloqueados (el servidor también los impone).
$fijos = isset($claves) ? datos_fijos_formato_vd($bdd, $claves) : [];
$fijo = fn($k) => isset($fijos[$k]) ? ' readonly class="fv-fijo" title="Viene del pedido; no se puede editar."' : '';
$activo = !$f || $f['activo'];
$h = fn($x) => htmlspecialchars((string)$x);
$val = fn($k) => htmlspecialchars((string)($v[$k] ?? ''));
$hora = fn($k) => htmlspecialchars(substr((string)($v[$k] ?? ''), 0, 5));
$pesos = fn($x) => '$ ' . number_format((float)$x, 0, ',', '.');
$pesos_in = fn($k) => isset($v[$k]) && (float)$v[$k] ? (string)(int)$v[$k] : '';
$fecha_hora = fn($x) => $x ? date('d/m/Y g:i A', strtotime($x)) : '';
// Etiqueta de la fila: título suelto del pedido o libro suelto de un paquete abierto (se resta del total de venta).
$tag_item = fn($it) => $it['tipo'] === 'titulo' ? '<span class="fv-tag">Título suelto</span>'
  : ($it['tipo'] === FVD_TIPO_SUELTO_PAQ ? '<span class="fv-tag fv-tag-sp" title="' . htmlspecialchars((string)$it['detalle']) . '">Suelto de paquete</span>'
  : ($it['tipo'] === FVD_TIPO_ABIERTO ? '<span class="fv-tag fv-tag-sp" title="' . htmlspecialchars((string)$it['detalle']) . '">' . htmlspecialchars((string)$it['detalle']) . '</span>'
  : ($it['tipo'] === 'paquete' && (int)($it['abiertos'] ?? 0) > 0 ? '<span class="fv-tag fv-tag-sp">' . (int)$it['abiertos'] . ' abierto(s): se venden por libro</span>' : '')));
// Unidades vendidas de una fila: el libro suelto de un paquete abierto resta.
$vend_item = fn($it) => fvd_vendidas_item($it); // el paquete descuenta los abiertos; sus libros van en filas propias
// Filas del pedido (sin los libros sueltos de paquetes, que solo existen desde la devolución).
$items_base = array_values(array_filter($items ?? [], fn($it) => !fvd_item_de_devolucion($it)));
// Formato automático (creado con la devolución, 2026-11-13, la usuaria): mientras no se cierre solo se muestra el cierre de
// dinero (y el cierre del inventario si aún falta un precio); el formato completo se genera al confirmar el cierre.
$solo_cierre = $f && $f['automatico'] && $activo && $etapa < FVD_ETAPA_CERRADO;

// Pasos de pantalla: [n, título, etapa en la que se diligencia, etapa con la que queda completo].
$pasos = [
  1 => ['Solicitud', 0, FVD_ETAPA_SOLICITUD],
  2 => ['Entrega de bodega', FVD_ETAPA_SOLICITUD, FVD_ETAPA_ENTREGADO],
  3 => ['Devolución', FVD_ETAPA_ENTREGADO, FVD_ETAPA_DEV_CONFIRMADA],
  4 => ['Cierre del inventario', FVD_ETAPA_DEV_CONFIRMADA, FVD_ETAPA_CONCILIADO],
  5 => ['Recepción en bodega', FVD_ETAPA_CONCILIADO, FVD_ETAPA_RECIBIDO],
  6 => ['Cierre de dinero', FVD_ETAPA_RECIBIDO, FVD_ETAPA_CERRADO],
];
$estado_paso = function ($n) use ($pasos, $etapa, $f, $activo) {
  [, $desde, $hasta] = $pasos[$n];
  if ($f && $etapa >= $hasta) return 'hecho';
  if (!$activo) return 'bloqueado';
  if ($etapa === $desde || ($n === 3 && $etapa === FVD_ETAPA_DEV_GENERADA)) return 'actual';
  return 'bloqueado';
};
// Quién y cuándo completó cada etapa (último registro del historial).
$hecho_por = function ($et) use ($hist) {
  $r = null;
  foreach ($hist as $x) if ($x['modulo'] === FVD_MODULO_ETAPAS && (int)$x['estado'] === (int)$et) $r = $x;
  return $r ? 'Confirmado por ' . htmlspecialchars($r['nombre'] ?: '—') . ' el ' . date('d/m/Y g:i A', strtotime($r['fecha'])) : '';
};
$bloqueo_txt = [
  2 => 'Se habilita al guardar la solicitud.',
  3 => 'Se habilita cuando bodega confirma la entrega del inventario.',
  4 => 'Se calcula automáticamente al vincular la devolución registrada en Devoluciones de ventas.',
  5 => 'Se habilita al confirmar el cierre del inventario.',
  6 => 'Se habilita cuando bodega registra la recepción de la devolución.',
];

/** Recuadro de firma: dibujar en pantalla o subir foto/PDF. */
$firma_pad = function ($tipo) {
  $t = htmlspecialchars($tipo); ?>
  <div class="fv-firma" data-tipo="<?= $t ?>">
    <div class="fv-firma-lienzo"><canvas width="600" height="180"></canvas><span class="fv-firma-ph">Firma aquí</span></div>
    <input type="hidden" name="firma_dibujo[<?= $t ?>]" class="fv-firma-dato">
    <div class="fv-firma-acc">
      <button type="button" class="fv-link fv-firma-borrar"><i class="bi bi-eraser"></i> Borrar</button>
      <label class="fv-link mb-0"><i class="bi bi-paperclip"></i> o subir foto/PDF del papel firmado
        <input type="file" name="firma_archivo[<?= $t ?>]" class="fv-firma-file d-none" accept="image/png,image/jpeg,application/pdf"></label>
      <span class="fv-firma-nombre"></span>
    </div>
  </div>
<?php };
/** Firma guardada. */
$firma_ver = function ($tipo) use ($f, $h) {
  $fi = $f['firmas'][$tipo] ?? null;
  if (!$fi) { echo '<div class="fv-det">Sin firma</div>'; return; } ?>
  <div class="fv-firma-ok">
    <?php if ($fi['es_pdf']): ?><a href="<?= $h($fi['url']) ?>" target="_blank" class="fv-link"><i class="bi bi-file-earmark-pdf"></i> Ver firma (PDF)</a>
    <?php else: ?><a href="<?= $h($fi['url']) ?>" target="_blank"><img src="<?= $h($fi['url']) ?>" alt="Firma"></a><?php endif; ?>
    <div class="fv-det"><?= $h(FVD_FIRMAS[$tipo]) ?>: <strong><?= $h($fi['nombre']) ?></strong> · <?= $fi['origen'] === 'archivo' ? 'archivo subido' : 'firmada en pantalla' ?> · <?= $h(date('d/m/Y g:i A', strtotime($fi['created_at']))) ?></div>
  </div>
<?php };
/** Encabezado de cada paso con su estado. */
$cabecera = function ($n, $extra = '') use ($pasos, $estado_paso, $hecho_por) {
  $e = $estado_paso($n);
  $pill = ['hecho' => '<span class="fv-pill ok"><i class="bi bi-check-circle-fill"></i> Completado</span>',
           'actual' => '<span class="fv-pill cur"><i class="bi bi-pencil-square"></i> En curso</span>',
           'bloqueado' => '<span class="fv-pill off"><i class="bi bi-lock-fill"></i> Bloqueado</span>'][$e];
  echo '<div class="fv-sec-head"><span class="fv-paso ' . $e . '">' . ($e === 'hecho' ? '<i class="bi bi-check-lg"></i>' : $n) . '</span> ' . htmlspecialchars($pasos[$n][0]) . ' ' . $pill . $extra
     . ($e === 'hecho' ? '<span class="fv-quien">' . $hecho_por($pasos[$n][2]) . '</span>' : '') . '</div>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Formato de venta directa</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    :root { --fv-azul:#1d4ed8; --fv-borde:#e2e8f0; --fv-gris:#64748b; --fv-tinta:#0f172a; }
    .fv-hero { background:linear-gradient(135deg,#1e3a8a 0%,#1d4ed8 55%,#3b82f6 100%); border-radius:16px; padding:22px 26px; color:#fff; margin-bottom:16px; }
    .fv-hero h4 { color:#fff; font-weight:800; margin:0; font-size:1.3rem; }
    .fv-hero p { color:rgba(255,255,255,.88); margin:6px 0 0; font-size:.88rem; }
    .fv-hero a { color:#fff; text-decoration:underline; }
    .fv-hero-acc { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
    .fv-hero-acc a, .fv-hero-acc button { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.35); color:#fff; border-radius:8px; padding:6px 12px; font-size:.8rem; font-weight:600; text-decoration:none; cursor:pointer; }
    .fv-hero-acc a:hover, .fv-hero-acc button:hover { background:rgba(255,255,255,.25); color:#fff; }
    .fv-steps { display:flex; gap:6px; margin-bottom:18px; flex-wrap:wrap; }
    .fv-step { flex:1 1 140px; background:#fff; border:1px solid var(--fv-borde); border-radius:12px; padding:10px 12px; display:flex; align-items:center; gap:9px; font-size:.78rem; font-weight:700; color:#94a3b8; }
    .fv-step b { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#f1f5f9; color:#94a3b8; font-size:.75rem; flex:none; }
    .fv-step.hecho { color:#15803d; } .fv-step.hecho b { background:#dcfce7; color:#15803d; }
    .fv-step.actual { color:var(--fv-azul); border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.12); } .fv-step.actual b { background:var(--fv-azul); color:#fff; }
    .fv-sec { background:#fff; border-radius:16px; box-shadow:0 2px 12px rgba(15,23,42,.07); margin-bottom:18px; }
    .fv-sec.bloqueado { opacity:.6; box-shadow:none; border:1px dashed #cbd5e1; }
    .fv-sec-head { padding:14px 22px; border-bottom:1px solid var(--fv-borde); font-weight:700; color:var(--fv-tinta); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .fv-sec.bloqueado .fv-sec-head { border-bottom:none; }
    .fv-paso { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%; background:var(--fv-azul); color:#fff; font-size:.78rem; font-weight:800; }
    .fv-paso.hecho { background:#16a34a; } .fv-paso.bloqueado { background:#cbd5e1; }
    .fv-pill { font-size:.68rem; font-weight:700; padding:2px 9px; border-radius:999px; }
    .fv-pill.ok { background:#dcfce7; color:#15803d; } .fv-pill.cur { background:#dbeafe; color:#1d4ed8; } .fv-pill.off { background:#f1f5f9; color:#64748b; } .fv-pill.warn { background:#fef3c7; color:#b45309; }
    .fv-quien { margin-left:auto; font-size:.74rem; font-weight:500; color:var(--fv-gris); }
    .fv-aux-fila { display:grid; grid-template-columns:minmax(200px,1fr) 220px 32px; gap:12px; align-items:end; margin-bottom:10px; }
    .fv-aux-quitar { color:#dc2626; font-size:1rem; padding-bottom:8px; }
    @media (max-width:600px) { .fv-aux-fila { grid-template-columns:1fr 32px; } .fv-aux-fila .fv-m { grid-column:1; } }
    .fv-imp { font-size:.74rem; font-weight:700; color:var(--fv-azul); border:1px solid #bfdbfe; border-radius:8px; padding:3px 9px; text-decoration:none; }
    .fv-imp:hover { background:#eff6ff; text-decoration:none; }
    .fv-sec-body { padding:18px 22px; }
    .fv-lock { padding:0 22px 14px 58px; font-size:.82rem; color:var(--fv-gris); }
    .fv-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
    .fv-sub { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--fv-azul); margin:18px 0 10px; }
    .fv-sub:first-child { margin-top:0; }
    .fv-campo label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--fv-gris); margin:0 0 5px; }
    .fv-campo input, .fv-campo textarea { width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:8px 11px; font-size:.88rem; }
    .fv-campo input:focus, .fv-campo textarea:focus, .fv-num:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); outline:none; }
    .fv-campo .fv-fijo, .fv-campo .fv-fijo:focus { background:#f1f5f9; color:#334155; font-weight:600; cursor:not-allowed; box-shadow:none; border-color:#cbd5e1; }
    .fv-req { color:#dc2626; }
    .fv-dato span { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--fv-gris); }
    .fv-dato strong { font-size:.88rem; color:var(--fv-tinta); font-weight:600; }
    .fv-tabla { width:100%; font-size:.85rem; border-collapse:separate; border-spacing:0; }
    .fv-tabla th { background:#f8fafc; color:var(--fv-gris); font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; font-weight:800; padding:10px; border-bottom:1px solid var(--fv-borde); white-space:nowrap; }
    .fv-tabla td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .fv-tabla .c { text-align:center; } .fv-tabla .r { text-align:right; white-space:nowrap; }
    .fv-tabla tfoot td { font-weight:800; border-top:2px solid var(--fv-azul); border-bottom:none; }
    .fv-tabla tr.dif td { background:#fff7ed; }
    .fv-paq { font-weight:700; color:var(--fv-tinta); }
    .fv-det { font-size:.74rem; color:#94a3b8; margin-top:2px; max-width:520px; }
    .fv-tag { display:inline-block; font-size:.66rem; font-weight:700; padding:2px 8px; border-radius:999px; background:#f1f5f9; color:#64748b; margin-left:6px; }
    .fv-tag.fv-tag-sp { background:#fef3c7; color:#92400e; }
    /* Ventas del cierre de dinero (2026-11-24) */
    .fv-v-ayuda { display:flex; gap:10px; align-items:flex-start; background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:10px; padding:10px 14px; font-size:.8rem; margin-bottom:10px; }
    .fv-v-ayuda .bi { font-size:1rem; margin-top:1px; }
    .fv-v-calc { font-size:.74rem; color:#64748b; margin-top:3px; }
    .fv-v-calc b { color:#334155; }
    .fv-v-pill { display:inline-block; font-size:.62rem; font-weight:800; padding:2px 8px; border-radius:999px; background:#dbeafe; color:#1d4ed8; margin-right:6px; vertical-align:middle; text-transform:uppercase; letter-spacing:.03em; }
    .fv-v-pill.p2 { background:#ede9fe; color:#6d28d9; }
    .fv-p1-pill { display:none; }
    .fv-ventas tr.fv-split .fv-p1-pill { display:inline-block; }
    .fv-ventas tr.fv-split td { border-bottom:none; }
    .fv-ventas tr.fv-fila-p2 td { background:#faf5ff; border-bottom:1px solid #ede9fe; }
    .fv-v-sub { font-size:.8rem; color:#4c1d95; padding-left:12px; border-left:3px solid #c4b5fd; }
    .fv-p2-abrir { display:inline-flex; gap:4px; align-items:center; margin-top:5px; font-size:.75rem; }
    .fv-p2-quitar { margin-left:8px; font-size:.72rem; color:#94a3b8; }
    .fv-num { width:84px; text-align:center; border:1px solid #cbd5e1; border-radius:8px; padding:6px; font-weight:700; }
    .fv-num.is-invalid { border-color:#dc2626; background:#fef2f2; }
    .fv-prop { font-size:.7rem; color:#b45309; margin-top:3px; }
    .fv-sinprecio { color:#dc2626; font-weight:700; font-size:.78rem; }
    .fv-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 22px; border-radius:10px; font-size:.88rem; font-weight:700; background:var(--fv-azul); color:#fff; border:none; cursor:pointer; text-decoration:none; }
    .fv-btn:hover { color:#fff; opacity:.92; }
    .fv-btn:disabled { background:#94a3b8; cursor:not-allowed; opacity:1; }
    .fv-btn-light { background:#fff; color:var(--fv-azul); border:1.5px solid #bfdbfe; }
    .fv-btn-light:hover { color:var(--fv-azul); background:#eff6ff; }
    .fv-btn-ok { background:#16a34a; }
    .fv-acc { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; flex-wrap:wrap; }
    .fv-link { background:none; border:none; padding:0; color:var(--fv-azul); font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .fv-vacio { text-align:center; padding:26px 10px; color:#64748b; font-size:.88rem; border:1px dashed #cbd5e1; border-radius:12px; }
    .fv-vacio > i { font-size:1.8rem; display:block; margin-bottom:6px; color:#94a3b8; }
    .fv-aviso { border-radius:12px; padding:12px 16px; font-size:.85rem; margin-bottom:14px; background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .fv-aviso.err { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .fv-aviso.ok { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
    .fv-aviso.info { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .fv-checks { list-style:none; padding:0; margin:0 0 6px; font-size:.86rem; }
    .fv-checks li { padding:4px 0; } .fv-checks .bi-check-circle-fill { color:#16a34a; } .fv-checks .bi-x-circle-fill { color:#dc2626; }
    .fv-firmas { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
    .fv-firma-box { border:1px solid var(--fv-borde); border-radius:14px; padding:14px; background:#fcfdff; }
    .fv-firma-lienzo { position:relative; border:1.5px dashed #94a3b8; border-radius:10px; background:#fff; margin-top:8px; }
    .fv-firma-lienzo canvas { width:100%; height:auto; display:block; touch-action:none; cursor:crosshair; }
    .fv-firma-ph { position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); color:#cbd5e1; font-weight:700; pointer-events:none; }
    .fv-firma.firmado .fv-firma-ph, .fv-firma.con-archivo .fv-firma-ph { display:none; }
    .fv-firma.con-archivo .fv-firma-lienzo { opacity:.35; }
    .fv-firma-acc { display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-top:6px; }
    .fv-firma-nombre { font-size:.75rem; color:#15803d; font-weight:600; }
    .fv-firma-ok img { max-width:240px; max-height:90px; border:1px solid var(--fv-borde); border-radius:8px; background:#fff; }
    .fv-caja { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
    .fv-card { border:1px solid var(--fv-borde); border-radius:14px; padding:16px; background:#fcfdff; display:flex; flex-direction:column; gap:12px; }
    .fv-card-tit { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--fv-azul); display:flex; align-items:center; gap:8px; }
    .fv-m label { display:block; font-size:.78rem; font-weight:600; color:#334155; margin:0 0 4px; }
    .fv-pesos-in { display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:10px; background:#fff; padding:0 10px; }
    .fv-pesos-in:focus-within { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
    .fv-pesos-in span { color:#94a3b8; font-weight:700; margin-right:6px; }
    .fv-pesos-in input { border:none; outline:none; width:100%; padding:8px 0; font-size:.95rem; font-weight:600; text-align:right; background:transparent; }
    .fv-signo { color:#94a3b8; margin-right:8px; font-size:1rem; }
    .fv-res { margin-top:auto; display:flex; justify-content:space-between; align-items:center; border-top:2px dashed #cbd5e1; padding-top:10px; }
    .fv-res span { font-size:.78rem; font-weight:800; text-transform:uppercase; color:var(--fv-gris); }
    .fv-res strong { font-size:1.1rem; color:var(--fv-tinta); }
    .fv-resumen { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-top:16px; }
    .fv-resumen > div { background:#f8fafc; border:1px solid var(--fv-borde); border-radius:12px; padding:12px 14px; }
    .fv-resumen span { display:block; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--fv-gris); }
    .fv-resumen strong { display:block; font-size:1.25rem; color:var(--fv-tinta); margin:2px 0; }
    .fv-resumen small { font-size:.72rem; color:#94a3b8; }
    .fv-resumen-dif.ok { background:#f0fdf4; border-color:#bbf7d0; } .fv-resumen-dif.ok strong { color:#15803d; }
    .fv-resumen-dif.mal { background:#fef2f2; border-color:#fecaca; } .fv-resumen-dif.mal strong { color:#dc2626; }
    .fv-armar-lib { font-size:.8rem; color:#334155; line-height:1.55; } .fv-armar-lib .bi-check-circle { color:#16a34a; }
    .fv-armar-lib.falta { color:#dc2626; font-weight:600; } .fv-armar-off td { background:#fafafa; } .fv-armar-off .fv-paq { color:#94a3b8; }
    /* Tabla por grado del cierre de ventas (2026-11-26) */
    .fv-cl { font-size:.82rem; }
    .fv-cl th { white-space:normal; line-height:1.2; text-align:center; }
    .fv-cl th:first-child { text-align:left; }
    .fv-cl td { padding:6px 8px; }
    .fv-cl .fv-num { width:64px; padding:5px 4px; }
    .fv-cl .fv-cl-pesos { padding:0 6px; min-width:110px; }
    .fv-cl .fv-cl-pesos input { padding:5px 0; font-size:.84rem; }
    .fv-cl tr.fv-cl-head td { background:#e0e7ff; color:#1e1b4b; font-weight:800; border-top:2px solid #a5b4fc; border-bottom:1px solid #c7d2fe; }
    .fv-cl tr.fv-cl-head.faltante td { background:#fde047; color:#713f12; border-top-color:#eab308; border-bottom-color:#facc15; }
    .fv-cl .fv-cl-falta { display:none; margin-left:8px; font-size:.7rem; font-weight:700; text-transform:none; }
    .fv-cl tr.fv-cl-head.faltante .fv-cl-falta { display:inline; }
    .fv-cl .fv-cl-kits { font-size:.72rem; font-weight:600; margin-top:2px; opacity:.85; }
    .fv-cl tr.fv-cl-fila.mal td { background:#fef2f2; }
    .fv-cl tfoot tr.fv-cl-gran td { font-size:1rem; background:#eff6ff; color:#1e3a8a; }
    .fv-cl.sin-nov .fv-cl-nc { display:none; }
    .fv-cl td.fv-cl-rem.falta { background:#fde047; color:#713f12; font-weight:800; }
    .fv-cl td.fv-cl-rem.falta .fv-num { border-color:#eab308; background:#fef9c3; }
    .fv-cl-nov-lbl { display:inline-flex; align-items:center; gap:8px; font-size:.86rem; font-weight:700; color:var(--fv-tinta); margin:0 0 10px; cursor:pointer; }
    .fv-cl-nov-lbl input[type="checkbox"] { width:17px; height:17px; }
    .fv-cl-amarillo { background:#fde047; color:#713f12; font-weight:700; padding:0 6px; border-radius:4px; }
    .fv-modal { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:9980; display:none; align-items:center; justify-content:center; padding:16px; }
    .fv-modal > form { background:#fff; border-radius:14px; padding:22px; width:440px; max-width:100%; }
    @media (max-width:992px) { .fv-caja { grid-template-columns:1fr; } .fv-resumen { grid-template-columns:1fr 1fr; } .fv-quien { margin-left:0; width:100%; } }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
<?php if ($error): ?>
      <div class="fv-hero"><h4><i class="bi bi-clipboard-check"></i> Formato de venta directa</h4></div>
      <div class="fv-aviso err"><i class="bi bi-x-octagon"></i> <?= $h($error) ?></div>
      <a href="devoluciones_venta_directa.php" class="fv-btn fv-btn-light"><i class="bi bi-arrow-left"></i> Volver a Legalización venta directa</a>
<?php else: ?>
      <div class="fv-hero">
        <h4><i class="bi bi-clipboard-check"></i> <?= $f ? $h(fvd_numero($f['consecutivo'])) : 'Nuevo formato de venta directa' ?></h4>
        <p><strong><?= $h($colegio) ?></strong><?= $f ? ' · Período ' . $h($f['periodo']) : '' ?> · Pedidos
          <?php foreach ($claves as $i => $c): $idp = (int)substr($c, 1); ?><?= $i ? ', ' : '' ?><a href="pedido_colegio.php?id_pedido=<?= $idp ?>" target="_blank">#<?= $idp ?></a><?php endforeach; ?>
          <?php if ($f): ?> · <strong><?= $activo ? $h(fvd_etapa_texto($etapa)) : 'Anulado' ?></strong><?php endif; ?></p>
        <?php if ($f): ?>
        <div class="fv-hero-acc">
          <a href="formatos_venta_directa.php"><i class="bi bi-list-ul"></i> Formatos</a>
          <?php if (!$solo_cierre): ?><a href="ver_formato_venta_directa.php?id=<?= (int)$f['id'] ?>" target="_blank"><i class="bi bi-printer"></i> Imprimir formato completo</a><?php endif; ?>
          <?php if ($f['legalizacion']): foreach ($f['legalizacion']['ids'] as $id_dev_l): ?><a href="devolucion_colegio.php?id_pedido=<?= (int)$id_dev_l ?>"><i class="bi bi-arrow-return-left"></i> Devolución #<?= (int)$id_dev_l ?></a><?php endforeach; endif; ?>
          <?php if ($f['anulable']): ?><button type="button" id="fv-anular"><i class="bi bi-x-octagon"></i> Anular formato</button><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$activo) echo html_motivo_anulacion(obtener_historial_estado($bdd, FVD_MODULO_HISTORIAL, $f['id'], ESTADO_ANULADO), 'Formato anulado'); ?>

      <?php if ($solo_cierre): ?>
      <div class="fv-aviso ok"><i class="bi bi-info-circle"></i> Formato generado con <?= $f['legalizacion'] ? htmlspecialchars(fvd_texto_devoluciones($f['legalizacion'])) : 'la devolución' ?>: <?= $etapa < FVD_ETAPA_RECIBIDO ? 'falta un precio de venta para calcular el total (abajo). Después se habilita el cierre de dinero.' : 'revisa la tabla por grado (reparte lo vendido de cada libro entre Kit y Suelto; los kits se arman solos), y llena los auxiliares y el cierre de dinero.' ?> Al confirmar el cierre se genera el formato completo para imprimir.</div>
      <?php else: ?>
      <div class="fv-steps">
        <?php foreach ($pasos as $n => $p): $e = $estado_paso($n); ?>
          <div class="fv-step <?= $e ?>"><b><?= $e === 'hecho' ? '<i class="bi bi-check-lg"></i>' : $n ?></b><?= $h($p[0]) ?></div>
        <?php endforeach; ?>
      </div>

      <?php /* ───────────── Paso 1: Solicitud ───────────── */ ?>
      <div class="fv-sec">
        <?php $cabecera(1); ?>
        <div class="fv-sec-body">
          <?php if (!$f): ?>
          <form method="POST" action="php/formato_vd_guardar.php" id="fv-form-1" autocomplete="off">
            <input type="hidden" name="accion" value="solicitud">
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <div class="fv-sub">Datos generales</div>
            <?php include __DIR__ . '/includes/formato_vd_generales.php'; ?>
          <?php else: ?>
            <div class="fv-grid">
              <div class="fv-dato"><span>Colegio</span><strong><?= $h($colegio) ?></strong></div>
              <div class="fv-dato"><span>Fecha de venta</span><strong><?= $h(date('d/m/Y', strtotime($f['fecha_venta']))) ?></strong></div>
              <div class="fv-dato"><span>Responsable de la solicitud</span><strong><?= $h($f['usuario'] ?: '—') ?></strong></div>
              <div class="fv-dato"><span>Registrada</span><strong><?= $h($fecha_hora($f['created_at'])) ?></strong></div>
            </div>
          <?php endif; ?>
          <div class="fv-sub">Solicitud por paquete / título</div>
          <?php if (!$f && !$prop['con_paquetes']): ?>
            <div class="fv-aviso"><i class="bi bi-info-circle"></i> Este colegio no tiene paquetes armados en Adopciones: el inventario va por título.</div>
          <?php endif; ?>
          <?php if (!$f && $prop['sin_catalogo'] > 0): ?>
            <div class="fv-aviso"><i class="bi bi-exclamation-triangle"></i> <?= (int)$prop['sin_catalogo'] ?> unidad(es) despachadas en World Office no están en el catálogo del CRM y no entran en el formato.</div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="fv-tabla">
              <thead><tr><th>Título</th><th class="c">Solicitud</th></tr></thead>
              <tbody>
                <?php foreach ($items_base as $it): ?>
                <tr><td><div class="fv-paq"><?= $h($it['descripcion']) ?><?= $tag_item($it) ?></div>
                  <?php if ((string)$it['detalle'] !== ''): ?><div class="fv-det"><?= $h($it['detalle']) ?></div><?php endif; ?></td>
                  <td class="c"><?= (int)$it['solicitud'] ?></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td>Total</td><td class="c"><?= array_sum(array_map(fn($it) => (int)$it['solicitud'], $items)) ?></td></tr></tfoot>
            </table>
          </div>
          <div class="fv-det" style="max-width:none;margin-top:8px;">La solicitud sale de los pedidos y queda guardada como referencia: no cambia con la entrega ni con la devolución.</div>
          <?php if (!$f): ?>
            <div class="fv-acc">
              <a href="devoluciones_venta_directa.php" class="fv-btn fv-btn-light"><i class="bi bi-x-lg"></i> Cancelar</a>
              <button type="submit" class="fv-btn"><i class="bi bi-save"></i> Guardar solicitud</button>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php /* ───────────── Paso 2: Entrega de bodega ───────────── */ $e2 = $estado_paso(2); ?>
      <div class="fv-sec <?= $e2 ?>">
        <?php $cabecera(2, $f && $activo && !$f['automatico'] ? ' <a class="fv-imp" href="ver_formato_venta_directa.php?id=' . (int)$f['id'] . '&acta=entrega" target="_blank"><i class="bi bi-printer"></i> Imprimir acta de entrega</a>' : ''); ?>
        <?php if ($e2 === 'actual'): ?><div class="fv-lock" style="padding-left:22px;"><i class="bi bi-info-circle"></i> Las firmas son obligatorias: fírmalas aquí o sube la foto o el PDF del papel firmado.</div><?php endif; ?>
        <?php if ($e2 === 'bloqueado'): ?><div class="fv-lock"><?= $bloqueo_txt[2] ?></div>
        <?php elseif ($e2 === 'actual'): ?>
        <div class="fv-sec-body">
          <form method="POST" action="php/formato_vd_guardar.php" id="fv-form-2" enctype="multipart/form-data" autocomplete="off" class="fv-con-firmas">
            <input type="hidden" name="accion" value="entrega">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="fv-sub">Datos generales (revísalos y complétalos)</div>
            <?php include __DIR__ . '/includes/formato_vd_generales.php'; ?>
            <div class="fv-sub">Entrega</div>
            <div class="fv-grid">
              <div class="fv-campo"><label>Aux. de bodega que entrega <span class="fv-req">*</span></label><input type="text" name="aux_entrega" maxlength="150" value="<?= $val('aux_entrega') ?>" required></div>
              <div class="fv-campo"><label>Responsable de la venta <span class="fv-req">*</span></label><input type="text" name="responsable" maxlength="150" value="<?= $val('responsable') ?>" required placeholder="Quien recibe la mercancía"<?= $fijo('responsable') ?>></div>
              <div class="fv-campo"><label>Teléfono del responsable</label><input type="text" name="tel_responsable" maxlength="40" value="<?= $val('tel_responsable') ?>"></div>
              <div class="fv-campo"><label>Total de cajas entregadas <span class="fv-req">*</span></label><input type="number" name="total_cajas" min="1" step="1" value="<?= $val('total_cajas') ?>" required></div>
            </div>
            <div class="fv-sub">Auxiliares de la venta</div>
            <div id="fv-auxiliares">
              <?php foreach ($f['auxiliares_lista'] ?: [['nombre' => '', 'valor' => '']] as $a): ?>
              <div class="fv-aux-fila">
                <div class="fv-campo"><label>Nombre del auxiliar</label><input type="text" name="aux_nombre[]" maxlength="150" value="<?= $h($a['nombre']) ?>"></div>
                <div class="fv-m"><label>Valor por persona</label><div class="fv-pesos-in"><span>$</span><input type="number" class="fv-aux-valor" name="aux_valor[]" min="0" step="1" placeholder="0" value="<?= (float)$a['valor'] ? (int)$a['valor'] : '' ?>"></div></div>
                <button type="button" class="fv-link fv-aux-quitar" title="Quitar"><i class="bi bi-trash"></i></button>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px;">
              <button type="button" class="fv-btn fv-btn-light" id="fv-aux-agregar" style="padding:6px 14px;font-size:.8rem;"><i class="bi bi-person-plus"></i> Agregar otro auxiliar</button>
              <div class="fv-det" style="max-width:none;">Total auxiliares: <strong id="fv-aux-total">$ 0</strong></div>
            </div>
            <div class="fv-sub">Inventario entregado</div>
            <div class="table-responsive">
              <table class="fv-tabla">
                <thead><tr><th>Título</th><th class="c">Solicitud</th><th class="c">Inventario entregado</th></tr></thead>
                <tbody>
                  <?php foreach ($items_base as $it): ?>
                  <tr class="fv-fila-ent" data-prop="<?= (int)$it['inv_propuesto'] ?>">
                    <td><div class="fv-paq"><?= $h($it['descripcion']) ?><?= $tag_item($it) ?></div></td>
                    <td class="c"><?= (int)$it['solicitud'] ?></td>
                    <td class="c"><input type="number" class="fv-num fv-ent" name="entregado[<?= $h($it['clave']) ?>]" min="0" step="1" value="<?= (int)$it['inv_inicial'] ?>" required>
                      <div class="fv-prop d-none">Despachado: <?= (int)$it['inv_propuesto'] ?></div></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total</td><td class="c"><?= array_sum(array_column($items, 'solicitud')) ?></td><td class="c" id="fv-t-ent">0</td></tr></tfoot>
              </table>
            </div>
            <div class="fv-det" style="max-width:none;margin-top:8px;">Se propone lo despachado en World Office (o lo solicitado, si el pedido no tiene documento) armado en paquetes completos; corrígelo con lo que bodega entrega realmente.</div>
            <div class="fv-sub">Firmas</div>
            <div class="fv-firmas">
              <div class="fv-firma-box"><div class="fv-paq"><?= $h(FVD_FIRMAS['entrega_aux']) ?> <span class="fv-req">*</span></div><div class="fv-det">Constancia de que entregó el inventario.</div><?php $firma_pad('entrega_aux'); ?></div>
              <div class="fv-firma-box"><div class="fv-paq"><?= $h(FVD_FIRMAS['entrega_resp']) ?> <span class="fv-req">*</span></div><div class="fv-det">Constancia de que recibió el inventario para la venta.</div><?php $firma_pad('entrega_resp'); ?></div>
            </div>
            <div class="fv-acc"><button type="submit" class="fv-btn fv-btn-ok" data-confirmar="Se confirma la entrega del inventario. Después no se podrán cambiar el inventario entregado ni los datos generales."><i class="bi bi-box-seam"></i> Confirmar entrega</button></div>
          </form>
        </div>
        <?php else: ?>
        <div class="fv-sec-body">
          <?php if ($f['automatico']): ?>
            <div class="fv-aviso"><i class="bi bi-magic"></i> Formato creado automáticamente con <?= $f['legalizacion'] ? htmlspecialchars(fvd_texto_devoluciones($f['legalizacion'])) : 'la devolución' ?><?= count($f['legalizacion']['ids'] ?? []) > 1 ? ' (las unidades de todas se suman en las devueltas)' : '' ?>: el inventario entregado es lo despachado (World Office o, sin documento, lo solicitado) y no lleva firmas de entrega.</div>
          <?php endif; ?>
          <div class="fv-grid">
            <div class="fv-dato"><span>Fecha / hora de venta</span><strong><?= $h(date('d/m/Y', strtotime($f['fecha_venta']))) ?> <?= $f['hora_inicio'] ? $h(date('g:i A', strtotime($f['hora_inicio']))) . ($f['hora_fin'] ? ' – ' . $h(date('g:i A', strtotime($f['hora_fin']))) : '') : '' ?></strong></div>
            <div class="fv-dato"><span>Responsable del colegio</span><strong><?= $h($f['contacto_colegio'] ?: '—') ?> <?= $f['tel_contacto'] ? '· ' . $h($f['tel_contacto']) : '' ?></strong></div>
            <div class="fv-dato"><span>Dirección</span><strong><?= $h($f['direccion'] ?: '—') ?></strong></div>
            <div class="fv-dato"><span>Responsable de la venta</span><strong><?= $h($f['responsable']) ?> <?= $f['tel_responsable'] ? '· ' . $h($f['tel_responsable']) : '' ?></strong></div>
            <?php if (!$f['automatico']): ?>
            <div class="fv-dato"><span>Aux. de bodega que entrega</span><strong><?= $h($f['aux_entrega']) ?></strong></div>
            <div class="fv-dato"><span>Total de cajas</span><strong><?= (int)$f['total_cajas'] ?></strong></div>
            <?php endif; ?>
            <div class="fv-dato"><span>Inventario entregado</span><strong><?= array_sum(array_column($items, 'inv_inicial')) ?> unidades</strong></div>
            <?php if ($f['auxiliares'] || $f['auxiliares_lista']): ?><div class="fv-dato"><span>Auxiliares de la venta</span><strong><?= $h(fvd_auxiliares_texto($f)) ?><?= $f['auxiliares_total'] ? ' · Total ' . $pesos($f['auxiliares_total']) : '' ?></strong></div><?php endif; ?>
          </div>
          <div class="table-responsive" style="margin-top:14px;">
            <table class="fv-tabla">
              <thead><tr><th>Paquete / título</th><th class="c">Solicitud</th><th class="c">Despachado (propuesto)</th><th class="c">Inventario entregado</th></tr></thead>
              <tbody>
                <?php foreach ($items_base as $it): ?>
                <tr><td><div class="fv-paq"><?= $h($it['descripcion']) ?><?= $tag_item($it) ?></div></td>
                  <td class="c"><?= (int)$it['solicitud'] ?></td><td class="c"><?= (int)$it['inv_propuesto'] ?></td><td class="c"><strong><?= (int)$it['inv_inicial'] ?></strong></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td>Totales</td><td class="c"><?= array_sum(array_column($items, 'solicitud')) ?></td><td class="c"><?= array_sum(array_column($items, 'inv_propuesto')) ?></td><td class="c"><?= array_sum(array_column($items, 'inv_inicial')) ?></td></tr></tfoot>
            </table>
          </div>
          <?php if (!$f['automatico']): ?><div class="fv-firmas" style="margin-top:14px;"><div><?php $firma_ver('entrega_aux'); ?></div><div><?php $firma_ver('entrega_resp'); ?></div></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php /* ───────────── Paso 3: Devolución ───────────── */
        $e3 = $estado_paso(3);
        $en_prep = false; ?>
      <div class="fv-sec <?= $e3 ?>">
        <?php $cabecera(3, $etapa === FVD_ETAPA_DEV_GENERADA ? ' <span class="fv-pill warn">Vinculada · por confirmar</span>' : ''); ?>
        <?php if ($e3 === 'bloqueado'): ?><div class="fv-lock"><?= $bloqueo_txt[3] ?></div>
        <?php elseif ($etapa === FVD_ETAPA_ENTREGADO): ?>
        <div class="fv-sec-body">
          <?php /* 2026-11-10: la devolución se registra en Devoluciones de ventas (remisiones, paquetes completos y libros
                   sueltos) y aquí solo se vincula: sus unidades pasan al formato y sigue el cierre automático. */ ?>
          <div class="fv-aviso"><i class="bi bi-info-circle"></i> La devolución ya no se registra en el formato. Regístrala en <a href="devoluciones_ventas.php?id_pedido=<?= (int)substr($claves[0] ?? 'v0', 1) ?>" target="_blank"><strong>Devoluciones de ventas</strong></a> eligiendo las remisiones, los paquetes completos y los libros sueltos, y luego vincúlala aquí: sus unidades pasan al formato y el cierre del inventario se calcula solo.</div>
          <?php if (!$vinculables): ?>
            <div class="fv-vacio"><i class="bi bi-inbox"></i> Todavía no hay devoluciones de estos pedidos (<?= $h(implode(', ', array_map('dvd_etiqueta', $claves))) ?>) para vincular.
              <div style="margin-top:10px;"><a href="devoluciones_ventas.php?id_pedido=<?= (int)substr($claves[0] ?? 'v0', 1) ?>" class="fv-btn" target="_blank"><i class="bi bi-arrow-return-left"></i> Registrar en Devoluciones de ventas</a>
              <a href="formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="fv-btn fv-btn-light"><i class="bi bi-arrow-clockwise"></i> Ya la registré, actualizar</a></div></div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="fv-tabla">
                <thead><tr><th>Devolución</th><th>Pedidos</th><th>Remisiones</th><th class="c">Libros</th><th>Registrada</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($vinculables as $dv_v): ?>
                  <tr>
                    <td><a href="devolucion_colegio.php?id_pedido=<?= (int)$dv_v['id'] ?>" target="_blank"><strong>Devolución #<?= (int)$dv_v['id'] ?></strong></a><?php if (trim((string)$dv_v['observaciones']) !== ''): ?><div class="fv-det"><?= $h($dv_v['observaciones']) ?></div><?php endif; ?></td>
                    <td><?= $h(implode(', ', array_map('dvd_etiqueta', $dv_v['pedidos']))) ?></td>
                    <td class="fv-det" style="font-size:.78rem;"><?= $h($dv_v['documentos_wo'] ?: 'Solicitud CRM') ?></td>
                    <td class="c"><strong><?= (int)$dv_v['libros'] ?></strong></td>
                    <td><?= $h($fecha_hora($dv_v['created_at'])) ?><div class="fv-det"><?= $h($dv_v['usuario']) ?></div></td>
                    <td class="r">
                      <form method="POST" action="php/formato_vd_guardar.php" style="margin:0;">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <input type="hidden" name="accion" value="devolucion_vincular">
                        <input type="hidden" name="id_devolucion" value="<?= (int)$dv_v['id'] ?>">
                        <button type="submit" class="fv-btn" data-confirmar="Se vincula la devolución #<?= (int)$dv_v['id'] ?> a este formato: sus paquetes y libros pasan a las unidades devueltas y se calcula el cierre del inventario."><i class="bi bi-link-45deg"></i> Vincular</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="fv-sec-body">
          <?php $dv = $verif['devolucion']; $sal = $verif['saldo']; ?>
          <?php if ($dv): ?>
            <div class="fv-grid" style="margin-bottom:14px;">
              <div class="fv-dato"><span>Documento de devolución</span><strong><a href="devolucion_colegio.php?id_pedido=<?= (int)$dv['id'] ?>">Devolución #<?= (int)$dv['id'] ?></a> · <?= $h($fecha_hora($dv['fecha'])) ?></strong></div>
              <div class="fv-dato"><span>Devuelto</span><strong><?= array_sum(array_map(fn($it) => (int)$it['devueltas'], $items)) ?> paquete(s)/título(s) = <?= array_sum(fvd_libros_devueltos($items)) ?> libros en el documento</strong></div>
              <div class="fv-dato"><span>Documento de saldo (lo vendido)</span><strong><?= $sal ? '<a href="ver_saldo_devolucion.php?id=' . (int)$sal['id'] . '">' . $h($sal['numero']) . '</a> · ' . (int)$sal['unidades'] . ' libros · ' . $pesos($sal['total']) : 'Sin documento de saldo' ?></strong></div>
            </div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="fv-tabla">
              <thead><tr><th>Paquete / título</th><th class="c">Inventario entregado</th><th class="c">Unidades devueltas</th><th class="c">Unidades vendidas</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                <tr><td><div class="fv-paq"><?= $h($it['descripcion']) ?><?= $tag_item($it) ?></div>
                    <?php if ($it['tipo'] === 'paquete'): ?><div class="fv-det">Cada paquete: <?= $h($it['detalle']) ?></div><?php endif; ?></td>
                  <td class="c"><?= (int)$it['inv_inicial'] ?></td><td class="c"><strong><?= (int)$it['devueltas'] ?></strong></td><td class="c"><?= $vend_item($it) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td>Totales</td><td class="c"><?= array_sum(array_column($items_base, 'inv_inicial')) ?></td><td class="c"><?= array_sum(array_map(fn($it) => (int)$it['devueltas'], $items)) ?></td><td class="c"><?= array_sum(array_map($vend_item, $items)) ?></td></tr></tfoot>
            </table>
          </div>
          <?php $det_doc = fvd_detalle_documento($bdd, $f); ?>
          <div class="fv-sub">Detalle por libro del documento<?= $dv ? ' · Devolución #' . (int)$dv['id'] : '' ?></div>
          <div class="table-responsive">
            <table class="fv-tabla">
              <thead><tr><th>Libro</th><th>ISBN</th><th>Sale de</th><th class="c">Devuelto (formato)</th><th class="c">En el documento</th><th class="c">En el saldo (vendido)</th><th class="c"></th></tr></thead>
              <tbody>
                <?php foreach ($det_doc as $r): $cuadra = $r['esperado'] === $r['en_documento']; ?>
                <tr<?= $cuadra ? '' : ' class="dif"' ?>><td><div class="fv-paq"><?= $h($r['libro'] ?? ('Libro #' . $r['id_libro'])) ?></div></td><td><?= $h($r['isbn'] ?? '') ?></td>
                  <td class="fv-det" style="font-size:.76rem;"><?= $h(implode(' + ', $r['origen']) ?: '—') ?></td>
                  <td class="c"><?= $r['esperado'] ?></td><td class="c"><strong><?= $r['en_documento'] ?></strong></td><td class="c"><?= $r['en_saldo'] ?></td>
                  <td class="c"><i class="bi <?= $cuadra ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="3">Totales</td><td class="c"><?= array_sum(array_column($det_doc, 'esperado')) ?></td><td class="c"><?= array_sum(array_column($det_doc, 'en_documento')) ?></td><td class="c"><?= array_sum(array_column($det_doc, 'en_saldo')) ?></td><td></td></tr></tfoot>
            </table>
          </div>
          <div class="fv-det" style="max-width:none;margin:8px 0 12px;">"En el saldo" es lo que quedó sin devolver frente a lo despachado en los pedidos (World Office o, sin documentos, lo solicitado en el CRM).</div>
          <ul class="fv-checks">
            <?php foreach ($verif['checks'] as $c): ?><li><i class="bi <?= $c['ok'] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i> <?= $h($c['texto']) ?></li><?php endforeach; ?>
          </ul>
          <?php if ($etapa === FVD_ETAPA_DEV_GENERADA && $activo): ?>
            <?php if (!$verif['ok']): ?><div class="fv-aviso err"><i class="bi bi-x-octagon"></i> La devolución no se puede confirmar hasta que la verificación esté completa. Si se anuló, el formato vuelve a la devolución en preparación.</div><?php endif; ?>
            <form method="POST" action="php/formato_vd_guardar.php" class="fv-acc">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="accion" value="devolucion_confirmar">
              <button type="submit" class="fv-btn fv-btn-ok" <?= $verif['ok'] ? '' : 'disabled' ?>><i class="bi bi-patch-check"></i> Confirmar devolución</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; /* fin pasos 1-3 (no se muestran en el formato automático sin cerrar) */ ?>

      <?php /* ───────────── Armar paquetes (2026-11-19): después de la devolución, con lo vendido suelto ───────────── */ ?>
      <?php if ($armado && ($armado['editable'] || array_filter(array_column($armado['paquetes'], 'armados')))): $ed = $armado['editable']; ?>
      <div class="fv-sec" id="fv-armar">
        <div class="fv-sec-head"><span class="fv-paso actual"><i class="bi bi-box-seam"></i></span> Armar paquetes
          <?= array_filter(array_column($armado['paquetes'], 'armados')) ? '<span class="fv-pill ok"><i class="bi bi-check-circle-fill"></i> ' . array_sum(array_column($armado['paquetes'], 'armados')) . ' armado(s)</span>' : '<span class="fv-pill warn">Sin armar</span>' ?></div>
        <div class="fv-sec-body">
          <div class="fv-aviso info"><i class="bi bi-info-circle"></i> World Office remisiona por libro. Aquí armas los paquetes de la adopción del colegio con lo <strong>vendido</strong> de cada libro (entregado − devuelto): cada paquete lleva 1 unidad de cada uno de sus libros. Solo se arma si están <strong>todos</strong> sus libros; lo que no armes queda como libro suelto. Los precios se revisan y ajustan en el <strong>cierre de dinero</strong>.<?= $ed ? ' Puedes cambiarlo hasta confirmar el cierre de dinero.' : '' ?></div>
          <form method="POST" action="php/formato_vd_guardar.php" id="fv-armar-form">
            <input type="hidden" name="accion" value="armar_paquetes"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="table-responsive">
              <table class="fv-tabla">
                <thead><tr><th>Paquete</th><th>Libros del paquete (unidades disponibles)</th><th class="c">Máximo</th><th class="c">Paquetes a armar</th></tr></thead>
                <tbody>
                <?php foreach ($armado['paquetes'] as $p): $ok = !$p['faltan'] && $p['precio'] > 0; ?>
                  <tr class="fv-armar-fila<?= $ok ? '' : ' fv-armar-off' ?>" data-paq="<?= (int)$p['id'] ?>" data-libros="<?= $h(implode(',', array_column($p['componentes'], 'id_libro'))) ?>" data-armados="<?= (int)$p['armados'] ?>">
                    <td><div class="fv-paq"><?= $h($p['paquete']) ?></div><?= $p['grado'] !== '' ? '<div class="fv-det">' . $h($p['grado']) . '</div>' : '' ?></td>
                    <td><?php foreach ($p['componentes'] as $c): $v = $armado['libros'][$c['id_libro']]['vendido'] ?? 0; $falta = in_array($c['libro'], $p['faltan'], true); ?>
                      <div class="fv-armar-lib<?= $falta ? ' falta' : '' ?>"><?= $falta ? '<i class="bi bi-x-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?> <?= $h($c['libro']) ?>
                        <span class="fv-det" style="display:inline;"> · <?= $falta ? 'no se vendió suelto' : '<span class="fv-armar-queda" data-libro="' . (int)$c['id_libro'] . '">' . $v . '</span> disp.' ?></span></div>
                    <?php endforeach; ?></td>
                    <td class="c"><?= $ok ? '<span class="fv-armar-max">' . (int)$p['max'] . '</span>' : '—' ?></td>
                    <td class="c"><?php if ($ok && $ed): ?><input type="number" class="fv-num fv-armar-n" name="armar[<?= (int)$p['id'] ?>]" min="0" step="1" value="<?= (int)$p['armados'] ?>">
                      <?php elseif ($ok): ?><strong><?= (int)$p['armados'] ?></strong>
                      <?php else: ?><span class="fv-sinprecio" style="font-weight:600;"><?= $p['faltan'] ? 'Incompleto' : 'Sin precio en Adopciones' ?></span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="fv-resumen" id="fv-armar-res">
              <div><span>Paquetes armados</span><strong id="fv-ar-paq">0</strong><small>con libros completos</small></div>
              <div><span>Libros sueltos</span><strong id="fv-ar-sue">0</strong><small>unidades que quedan sueltas</small></div>
            </div>
            <?php if ($ed): ?><div class="fv-acc"><button type="submit" class="fv-btn" id="fv-armar-btn"><i class="bi bi-box-seam"></i> Guardar paquetes armados</button></div><?php endif; ?>
          </form>
        </div>
      </div>
      <script>
      var FV_ARMAR = <?= json_encode(['libros' => array_map(fn($x) => ['v' => $x['vendido']], $armado['libros']) ?: new stdClass()], JSON_UNESCAPED_UNICODE) ?>;
      </script>
      <?php endif; ?>


      <?php if (!$solo_cierre || $etapa < FVD_ETAPA_RECIBIDO): ?>
      <?php /* ───────────── Paso 4: Cierre del inventario ───────────── */ $e4 = $estado_paso(4); ?>
      <div class="fv-sec <?= $e4 ?>">
        <?php $cabecera(4); ?>
        <?php if ($e4 === 'bloqueado' && $etapa < FVD_ETAPA_DEV_CONFIRMADA): ?><div class="fv-lock"><?= $bloqueo_txt[4] ?></div>
        <?php else: ?>
        <div class="fv-sec-body">
          <?php if ($etapa === FVD_ETAPA_DEV_CONFIRMADA && $tot['sin_precio']): ?>
            <div class="fv-aviso err"><i class="bi bi-exclamation-octagon"></i> Las unidades se calcularon con <?= htmlspecialchars(fvd_texto_devoluciones($f['legalizacion'])) ?>, pero <?= (int)$tot['sin_precio'] ?> fila(s) no tienen precio de venta configurado. Configúralo en Adopciones del colegio (precio del paquete o precio venta padre del título) y vuelve a calcular: el cierre se completa solo. No se calcula el total mientras falte un precio.</div>
          <?php elseif ($etapa >= FVD_ETAPA_CONCILIADO): ?>
            <div class="fv-aviso ok"><i class="bi bi-calculator"></i> Calculado automáticamente con <?= htmlspecialchars(fvd_texto_devoluciones($f['legalizacion']) ?: 'la devolución') ?>; los precios aplicados quedaron guardados en el formato aunque luego cambien las listas de precios.</div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="fv-tabla">
              <thead><tr><th>Título</th><th class="c">Solicitud</th><th class="c">Entregado</th><th class="c">Devueltas</th><th class="c">Recibido final</th><th class="c">Vendidas</th><th class="r">Precio venta</th><th class="r">Total venta</th></tr></thead>
              <tbody>
                <?php foreach ($conc as $x): ?>
                <tr>
                  <td><div class="fv-paq"><?= $h($x['descripcion']) ?><?= $tag_item($x) ?></div></td>
                  <td class="c"><?= (int)$x['solicitud'] ?></td><td class="c"><?= $x['entregado'] ?></td><td class="c"><?= $x['dev'] ?><?php if ($dos_dev && $x['dev']): ?><div class="fv-det">1ª: <?= $x['dev'] - $x['dev2'] ?> · 2ª: <?= $x['dev2'] ?></div><?php endif; ?></td>
                  <td class="c"><?= $x['fin'] ?></td><td class="c"><strong><?= $x['vend'] ?></strong><?php if ($x['n2']): ?><div class="fv-det"><?= $x['vend1'] ?> a precio 1 · <?= $x['n2'] ?> a precio 2</div><?php endif; ?></td>
                  <td class="r"><?php if ($x['precio_v']): $dsc = $x['tipo'] === FVD_TIPO_ABIERTO ? ($abiertos_dev[(int)$x['id_paquete']]['libros'][(int)$x['id_libro']]['descuento'] ?? null) : null; ?><?= $pesos($x['precio_v']) ?><div class="fv-det"><?= $h($x['origen_v']) ?><?= $dsc ? ' · desc. ' . $h(rtrim(rtrim(number_format($dsc, 2, ',', ''), '0'), ',')) . ' %' : '' ?></div><?php elseif ($x['sin_precio']): ?><span class="fv-sinprecio"><i class="bi bi-exclamation-triangle"></i> Sin precio</span><?php else: ?>—<?php endif; ?>
                    <?php if ($x['n2']): ?><div class="fv-det" style="margin-top:4px;">Precio 2: <?= $x['precio2_v'] ? $pesos($x['precio2_v']) : 'sin precio' ?></div><?php endif; ?></td>
                  <td class="r"><?= $x['total_v'] !== null ? '<strong>' . $pesos($x['total_v']) . '</strong>' : '—' ?><?php if ($x['n2'] && $x['total_v'] !== null): ?><div class="fv-det"><?= $pesos($x['vend1'] * $x['precio_v']) ?> + <?= $pesos($x['n2'] * $x['precio2_v']) ?></div><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td>Totales</td><td class="c"><?= $tot['solicitud'] ?></td><td class="c"><?= $tot['entregado'] ?></td><td class="c"><?= $tot['devueltas'] ?></td><td class="c"><?= $tot['inv_final'] ?></td><td class="c"><?= $tot['vendidas'] ?></td><td></td>
                <td class="r"><?= $tot['sin_precio'] ? '<span class="fv-sinprecio">Pendiente</span>' : $pesos($tot['total_venta']) ?></td></tr></tfoot>
            </table>
          </div>
          <div class="fv-det" style="max-width:none;margin-top:8px;">Unidades vendidas = inventario entregado − unidades devueltas. Recibido final = lo devuelto a bodega. Las diferencias se explican en las observaciones de la recepción o del cierre.</div>
          <?php if ($dos_dev): $ids_l = $f['legalizacion']['ids']; ?>
          <div class="fv-det" style="max-width:none;margin-top:4px;"><strong>Dos devoluciones (#<?= (int)$ids_l[0] ?> y #<?= (int)end($ids_l) ?>):</strong> vendidas = entregado − 1ª devolución − 2ª devolución. En el cierre de dinero puedes repartir las vendidas de cada título en dos precios: escribes cuántas van a precio 2 y el resto queda a precio 1.</div>
          <?php endif; ?>
          <?php if (array_filter($conc, fn($x) => $x['tipo'] === FVD_TIPO_ABIERTO)): ?>
          <div class="fv-det" style="max-width:none;margin-top:4px;"><span class="fv-tag fv-tag-sp">Paquete abierto</span> Si de un paquete volvió un libro suelto, ese paquete no se cobra a su precio: se descuenta de la fila del paquete (vendidas = entregado − devueltos completos − abiertos) y cada uno de sus libros se cobra en su propia fila al precio puesto en la devolución. El libro que volvió suelto queda en el recibido final y no se cobra.</div>
          <?php endif; ?>
          <?php if (array_filter($conc, fn($x) => $x['tipo'] === FVD_TIPO_SUELTO_PAQ)): ?>
          <div class="fv-det" style="max-width:none;margin-top:4px;"><span class="fv-tag fv-tag-sp">Suelto de paquete</span> Registrado en el formato: el paquete se cobra completo y el libro que volvió suelto se resta a su precio venta padre.</div>
          <?php endif; ?>
          <?php if ($etapa === FVD_ETAPA_DEV_CONFIRMADA && $activo): ?>
            <div class="fv-acc"><a href="formato_venta_directa.php?id=<?= (int)$f['id'] ?>" class="fv-btn"><i class="bi bi-arrow-clockwise"></i> Volver a calcular</a></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php if (!$solo_cierre): ?>
      <?php /* ───────────── Paso 5: Recepción en bodega ───────────── */ $e5 = $estado_paso(5); ?>
      <div class="fv-sec <?= $e5 ?>">
        <?php $cabecera(5, $f && $activo && $f['legalizacion'] && !$f['automatico'] ? ' <a class="fv-imp" href="ver_formato_venta_directa.php?id=' . (int)$f['id'] . '&acta=recepcion" target="_blank"><i class="bi bi-printer"></i> Imprimir acta de recepción</a>' : ''); ?>
        <?php if ($e5 === 'actual'): ?><div class="fv-lock" style="padding-left:22px;"><i class="bi bi-info-circle"></i> El acta de recepción trae la solicitud, la entrega y la devolución documentada para contar y firmar en bodega.</div><?php endif; ?>
        <?php if ($e5 === 'bloqueado'): ?><div class="fv-lock"><?= $bloqueo_txt[5] ?></div>
        <?php elseif ($e5 === 'actual'): ?>
        <div class="fv-sec-body">
          <form method="POST" action="php/formato_vd_guardar.php" id="fv-form-5" enctype="multipart/form-data" autocomplete="off" class="fv-con-firmas">
            <input type="hidden" name="accion" value="recepcion">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="fv-grid">
              <div class="fv-campo"><label>Aux. de bodega que recibe <span class="fv-req">*</span></label><input type="text" name="aux_recibe" maxlength="150" required></div>
              <div class="fv-campo"><label>Fecha y hora de recepción <span class="fv-req">*</span></label><div style="display:flex;gap:8px;"><input type="datetime-local" name="recibido_at" id="fv-recibido-at" required>
                <button type="button" class="fv-btn fv-btn-light" id="fv-ahora" style="padding:6px 12px;font-size:.8rem;white-space:nowrap;"><i class="bi bi-clock"></i> Ahora</button></div>
                <div class="fv-det">Hoy o un día anterior.</div></div>
            </div>
            <div class="fv-sub">Cantidad recibida</div>
            <div class="table-responsive">
              <table class="fv-tabla">
                <thead><tr><th>Título</th><th class="c">Devueltas (documento)</th><th class="c">Recibidas en bodega</th><th class="c">Diferencia</th></tr></thead>
                <tbody>
                  <?php foreach ($items as $it): ?>
                  <tr class="fv-fila-rec" data-dev="<?= (int)$it['devueltas'] ?>">
                    <td><div class="fv-paq"><?= $h($it['descripcion']) ?></div></td>
                    <td class="c"><?= (int)$it['devueltas'] ?></td>
                    <td class="c"><input type="number" class="fv-num fv-rec" name="recibidas[<?= $h($it['clave']) ?>]" min="0" step="1" value="<?= (int)$it['devueltas'] ?>" required></td>
                    <td class="c"><strong class="fv-rdif">0</strong></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="fv-aviso err d-none" id="fv-rec-aviso" style="margin-top:12px;"><i class="bi bi-exclamation-triangle"></i> Lo recibido no coincide con la devolución documentada. Revisa el conteo o justifica la diferencia para poder continuar.</div>
            <div class="fv-campo" style="margin-top:12px;"><label>Observaciones de la recepción <span class="fv-req d-none" id="fv-rec-req">* (justifica la diferencia)</span></label><textarea name="obs_recepcion" rows="2" maxlength="2000"></textarea></div>
            <div class="fv-sub">Firma</div>
            <div class="fv-firmas"><div class="fv-firma-box"><div class="fv-paq"><?= $h(FVD_FIRMAS['recibe_aux']) ?> <span class="fv-req">*</span></div><div class="fv-det">Constancia de la recepción física de lo devuelto.</div><?php $firma_pad('recibe_aux'); ?></div></div>
            <div class="fv-acc"><button type="submit" class="fv-btn fv-btn-ok" data-confirmar="Se registra la recepción en bodega y la devolución queda como Recibido."><i class="bi bi-box-arrow-in-down"></i> Confirmar recepción</button></div>
          </form>
        </div>
        <?php elseif ($f['automatico']): ?>
        <div class="fv-sec-body">
          <div class="fv-aviso"><i class="bi bi-magic"></i> Formato automático: no se diligencia la recepción en bodega; se toma como recibido lo devuelto (<?= array_sum(array_map(fn($it) => (int)$it['devueltas'], $items)) ?> paquete(s)/título(s)). La recepción física de la devolución se marca en <?= $f['legalizacion'] && count($f['legalizacion']['ids']) > 1 ? 'cada devolución' : 'la devolución' ?><?= $f['legalizacion'] ? ' ' . fvd_links_devoluciones($f['legalizacion']) : '' ?>.</div>
        </div>
        <?php else: ?>
        <div class="fv-sec-body">
          <div class="fv-grid">
            <div class="fv-dato"><span>Aux. de bodega que recibe</span><strong><?= $h($f['aux_recibe']) ?></strong></div>
            <div class="fv-dato"><span>Fecha y hora de recepción</span><strong><?= $h($fecha_hora($f['recibido_at'])) ?></strong></div>
            <div class="fv-dato"><span>Recibidas / devueltas</span><strong><?= array_sum(array_map(fn($it) => (int)$it['recibidas'], $items)) ?> / <?= array_sum(array_map(fn($it) => (int)$it['devueltas'], $items)) ?></strong></div>
          </div>
          <?php $difs = array_filter($items, fn($it) => (int)$it['recibidas'] !== (int)$it['devueltas']); ?>
          <?php if ($difs): ?><div class="fv-aviso" style="margin-top:12px;"><i class="bi bi-exclamation-triangle"></i> Con diferencias: <?= $h(implode('; ', array_map(fn($it) => $it['descripcion'] . ' (devueltas ' . (int)$it['devueltas'] . ', recibidas ' . (int)$it['recibidas'] . ')', $difs))) ?>.<br><strong>Justificación:</strong> <?= $h($f['obs_recepcion']) ?></div>
          <?php elseif ($f['obs_recepcion']): ?><div class="fv-det" style="max-width:none;margin-top:8px;">Observaciones: <?= $h($f['obs_recepcion']) ?></div><?php endif; ?>
          <div style="margin-top:14px;"><?php $firma_ver('recibe_aux'); ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php if (!$solo_cierre || $etapa >= FVD_ETAPA_RECIBIDO): ?>
      <?php /* ───────────── Paso 6: Cierre de dinero ───────────── */ $e6 = $estado_paso(6); ?>
      <div class="fv-sec <?= $e6 ?>">
        <?php $cabecera(6); ?>
        <?php if ($e6 === 'bloqueado'): ?><div class="fv-lock"><?= $bloqueo_txt[6] ?></div>
        <?php else: $cerrado = $e6 === 'hecho'; ?>
        <div class="fv-sec-body">
          <form method="POST" action="php/formato_vd_guardar.php" id="fv-form-6" autocomplete="off" data-total="<?= (float)($cl ? $cl['gran_total'] : $f['total_venta']) ?>">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="fv-sub">Ventas</div>
            <?php
            // 2026-11-24 (la usuaria): solo las filas con unidades vendidas (sin paquetes no armados ni títulos en 0). Con 2
            // devoluciones cada título muestra su cálculo y se puede agregar un 2º precio para parte de sus vendidas.
            $precio_ed = !$cerrado && $activo;
            $vendibles = array_values(array_filter($items, fn($it) => (int)$it['vendidas'] !== 0));
            $ocultos = count($items) - count($vendibles);
            $ids_l = $f['legalizacion']['ids'] ?? [];
            ?>
            <?php if ($cl): /* ── Tabla por grado del cierre de ventas (2026-11-26, includes/formatos_vd_libros.php) ── */
              $cl_ed = !$cerrado && $activo;
              $cl_in = function ($r, $k, $campo) use ($cl_ed, $h) {
                $v = (int)$r[$campo];
                if (!$cl_ed) return '<span class="fv-cl-v" data-k="' . $k . '" data-v="' . $v . '">' . $v . '</span>';
                return '<input type="number" class="fv-num fv-cl-in" data-k="' . $k . '" name="cl[' . (int)$r['id'] . '][' . $k . ']" min="0" step="1" value="' . $v . '">';
              };
              $cl_pin = function ($r, $k, $campo) use ($cl_ed, $pesos) {
                $v = (int)round((float)$r[$campo]);
                if (!$cl_ed) return '<span class="fv-cl-v" data-k="' . $k . '" data-v="' . $v . '">' . $pesos($v) . '</span>';
                return '<div class="fv-pesos-in fv-cl-pesos"><span>$</span><input type="number" class="fv-cl-in" data-k="' . $k . '" name="cl[' . (int)$r['id'] . '][' . $k . ']" min="0" step="1" value="' . $v . '"></div>';
              }; ?>
            <?php $nov = $cl['novedades']; $span = $nov ? 10 : 7; /* sin novedades no se ven Kit, Suelto ni Sin inventario */ ?>
            <div class="fv-v-ayuda"><i class="bi bi-info-circle"></i><div>Un renglón por libro, agrupado por grado. <strong>Vendido = Remisionado − Devuelto</strong>. Sin novedades, los kits se arman solos con lo vendido (cada paquete lleva 1 unidad de cada uno de sus libros) y lo demás queda suelto. Si la venta tuvo <strong>novedades</strong>, márcalo para repartir lo vendido de cada libro entre <strong>Kit</strong> y <strong>Suelto</strong> y anotar lo <strong>Sin inventario</strong>: las tres arrancan en 0 y solo cuenta lo que escribas; un paquete sale aunque solo uno de sus libros tenga Kit, y lo vendido que no va en kits se cobra suelto. Los precios vienen de la adopción y se pueden cambiar aquí sin modificar la adopción. <span class="fv-cl-amarillo">En amarillo</span> = remisionado por debajo de lo solicitado (World Office).</div></div>
            <label class="fv-cl-nov-lbl"><input type="hidden" name="cl_novedades" value="0"><input type="checkbox" name="cl_novedades" value="1" id="fv-cl-nov" <?= $nov ? 'checked' : '' ?> <?= $cl_ed ? '' : 'disabled' ?>> La venta tuvo novedades <span class="fv-det" style="display:inline;">(muestra Kit, Suelto y Sin inventario)</span></label>
            <div class="table-responsive">
              <table class="fv-tabla fv-cl<?= $nov ? '' : ' sin-nov' ?>" id="fv-cl">
                <thead><tr><th>Libro</th><th class="c">Solicitado</th><th class="c">Remisionado</th><th class="c">Devuelto</th><th class="c">Vendido</th><th class="c fv-cl-nc">Kit</th><th class="c fv-cl-nc">Suelto</th><th class="c fv-cl-nc">Sin inventario</th><th class="r">Precio kit</th><th class="r">Precio suelto</th><th class="r">Total precio kit</th><th class="r">Total precio suelto</th></tr></thead>
                <?php foreach ($cl['grados'] as $g): ?>
                <tbody class="fv-cl-g" data-grado="<?= $h($g['clave']) ?>">
                  <tr class="fv-cl-head<?= $g['faltante'] ? ' faltante' : '' ?>">
                    <td class="fv-cl-span" colspan="<?= $span ?>"><i class="bi bi-mortarboard"></i> <?= $h(mb_strtoupper($g['grado'])) ?>
                      <span class="fv-cl-falta"><i class="bi bi-exclamation-triangle-fill"></i> Remisionado menor que lo solicitado</span>
                      <div class="fv-cl-kits"><?= $g['paquetes'] ? $h(implode(' · ', array_map(fn($p) => $p['paquete'] . ': ' . $p['kits'] . ' kit(s) × ' . $pesos($p['precio']), $g['paquetes']))) : 'Sin kits' ?></div></td>
                    <td class="r fv-cl-gk"><?= $pesos($g['total_kit']) ?></td><td class="r fv-cl-gs"><?= $pesos($g['total_suelto']) ?></td>
                  </tr>
                  <?php foreach ($g['filas'] as $r): ?>
                  <tr class="fv-cl-fila" data-id="<?= (int)$r['id'] ?>" data-paq="<?= $h($r['paquetes']) ?>">
                    <td><div class="fv-paq"><?= $h($r['libro']) ?><?= $r['plan_lector'] ? '<span class="fv-tag">Plan lector</span>' : '' ?><?= trim((string)$r['paquetes']) === '' ? '<span class="fv-tag" title="No hace parte de ningún paquete de la adopción: solo se vende suelto.">Solo suelto</span>' : '' ?></div></td>
                    <td class="c"><?= $cl_in($r, 'sol', 'solicitado') ?></td>
                    <td class="c fv-cl-rem<?= $r['faltante'] ? ' falta' : '' ?>"><?= $cl_in($r, 'rem', 'remisionado') ?></td>
                    <td class="c"><?= $cl_in($r, 'dev', 'devuelto') ?></td>
                    <td class="c"><strong class="fv-cl-vend"><?= (int)$r['vendido'] ?></strong></td>
                    <td class="c fv-cl-nc"><?= $cl_in($r, 'kit', 'kit') ?></td>
                    <td class="c fv-cl-nc"><?= $cl_in($r, 'suelto', 'suelto') ?></td>
                    <td class="c fv-cl-nc"><?= $cl_in($r, 'sinv', 'sin_inventario') ?></td>
                    <td class="r"><?= $cl_pin($r, 'pk', 'precio_kit') ?></td>
                    <td class="r"><?= $cl_pin($r, 'ps', 'precio_suelto') ?></td>
                    <td class="r fv-cl-tk"><?= $pesos($r['total_kit']) ?></td>
                    <td class="r fv-cl-ts"><?= $pesos($r['total_suelto']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <?php endforeach; ?>
                <tfoot>
                  <tr><td class="fv-cl-span" colspan="<?= $span ?>">Total precio kit <span class="fv-det" style="display:inline;">· <span id="fv-cl-nkits"><?= (int)$cl['kits'] ?></span> kit(s) × precio del kit</span></td><td class="r" id="fv-cl-tk"><?= $pesos($cl['total_kit']) ?></td><td></td></tr>
                  <tr><td class="fv-cl-span" colspan="<?= $span ?>">Total precio suelto <span class="fv-det" style="display:inline;">· libros sueltos × precio individual</span></td><td></td><td class="r" id="fv-cl-ts"><?= $pesos($cl['total_suelto']) ?></td></tr>
                  <tr class="fv-cl-gran"><td class="fv-cl-span" colspan="<?= $span ?>">GRAN TOTAL <span class="fv-det" style="display:inline;">· total precio kit + total precio suelto</span></td><td class="r" colspan="2" id="fv-cl-gran"><?= $pesos($cl['gran_total']) ?></td></tr>
                </tfoot>
              </table>
            </div>
            <div class="fv-aviso err<?= $cl['errores'] ? '' : ' d-none' ?>" id="fv-cl-errores" style="margin-top:12px;"><i class="bi bi-exclamation-triangle"></i> <strong>Revisa la tabla antes de cerrar:</strong><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($cl['errores'] as $e_cl): ?><li><?= $h($e_cl) ?></li><?php endforeach; ?></ul></div>
            <script>var FV_CL = <?= json_encode(['paquetes' => array_map(fn($p) => ['id' => $p['id'], 'grado' => 'g' . (int)$p['id_grado'], 'nombre' => $p['paquete'], 'libros' => $p['libros']], $cl['paquetes'])], JSON_UNESCAPED_UNICODE) ?>;</script>
            <?php else: ?>
            <?php if ($dos_dev): ?>
            <div class="fv-v-ayuda"><i class="bi bi-info-circle"></i><div>Este formato tiene <strong>2 devoluciones</strong> (#<?= (int)$ids_l[0] ?> y #<?= (int)end($ids_l) ?>). Las vendidas de cada título son lo entregado menos lo que volvió en las dos.
              <?php if ($precio_ed): ?>En los títulos que tuvieron unidades en la 2ª devolución, si parte se vendió a otro precio usa <strong>Agregar 2º precio</strong> y escribe cuántas unidades van a ese precio; las demás quedan al precio 1. Los títulos sin 2ª devolución llevan un solo precio.<?php endif; ?></div></div>
            <?php endif; ?>
            <?php if (!$vendibles): ?>
            <div class="fv-aviso"><i class="bi bi-info-circle"></i> No hay unidades vendidas: todo lo entregado volvió en la devolución.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="fv-tabla fv-ventas">
                <thead><tr><th>Paquete / título</th><th class="c">Vendidas</th><th class="r">Precio</th><th class="r">Total</th></tr></thead>
                <tbody>
                  <?php foreach ($vendibles as $it):
                    $vend_t = (int)$it['vendidas'];
                    $dev2 = min((int)$it['devueltas'], (int)($it['devueltas_2'] ?? 0));
                    // Precio 2 solo si la fila tuvo unidades en la 2a devolución (2026-11-24, la usuaria).
                    $puede_p2 = $dos_dev && $vend_t > 0 && $dev2 > 0;  // se puede repartir en dos precios
                    $n2 = $puede_p2 ? min(max(0, (int)($it['vendidas_2'] ?? 0)), $vend_t) : 0;
                    $p2 = (float)($it['precio_2'] ?? 0) ?: (float)$it['precio'];
                    $ver_p2 = $puede_p2 && $n2 > 0;                 // ya está repartido
                    $fila_p2 = $puede_p2 && ($precio_ed || $n2 > 0); // se pinta el renglón del 2º precio (oculto si no se usa)
                    $dev1 = (int)$it['devueltas'] - $dev2;
                    $id_it = (int)$it['id']; ?>
                  <tr class="fv-fila-venta<?= $ver_p2 ? ' fv-split' : '' ?>" data-item="<?= $id_it ?>" data-vend-total="<?= $vend_t ?>" data-vend="<?= $vend_t - $n2 ?>" data-fin="<?= (int)$it['inv_final'] ?>">
                    <td><div class="fv-paq"><?= $h($it['descripcion']) ?><?= $tag_item($it) ?></div>
                      <?php if ($dos_dev && $vend_t > 0): ?>
                      <div class="fv-v-calc">Entregado <b><?= (int)$it['inv_inicial'] ?></b> − 1ª dev. <b><?= $dev1 ?></b> − 2ª dev. <b><?= $dev2 ?></b> = <b><?= $vend_t ?> vendida<?= $vend_t === 1 ? '' : 's' ?></b></div>
                      <?php endif; ?>
                      <?php if ($fila_p2 && $precio_ed): ?><button type="button" class="fv-link fv-p2-abrir<?= $ver_p2 ? ' d-none' : '' ?>" data-item="<?= $id_it ?>"><i class="bi bi-plus-circle"></i> Agregar 2º precio</button><?php endif; ?></td>
                    <td class="c"><span class="fv-v-pill fv-p1-pill">Precio 1</span><strong class="fv-vend-txt"><?= $vend_t - $n2 ?></strong></td>
                    <td class="r"><?php if ($precio_ed): ?><div class="fv-pesos-in" style="justify-content:flex-end;"><span>$</span><input type="number" class="fv-precio-ap" name="precio[<?= $id_it ?>]" min="0" step="1" value="<?= (int)round((float)$it['precio']) ?>" data-orig="<?= (int)round((float)$it['precio']) ?>" style="width:110px;text-align:right;"></div>
                      <?php else: ?><?= $pesos($it['precio']) ?><?php endif; ?><div class="fv-det fv-precio-origen" data-orig="<?= $h($it['precio_origen']) ?>"><?= $h($it['precio_origen']) ?></div></td>
                    <td class="r fv-total-fila"><?= $pesos($puede_p2 ? ($vend_t - $n2) * (float)$it['precio'] : $it['total']) ?></td>
                  </tr>
                  <?php if ($fila_p2): ?>
                  <tr class="fv-fila-venta fv-fila-p2<?= $ver_p2 ? '' : ' d-none' ?>" data-item="<?= $id_it ?>" data-vend="<?= $n2 ?>" data-fin="0">
                    <td><div class="fv-v-sub"><span class="fv-v-pill p2">Precio 2</span> Unidades de las <?= $vend_t ?> vendidas que se cobran a otro precio
                      <?php if ($precio_ed): ?><button type="button" class="fv-link fv-p2-quitar" data-item="<?= $id_it ?>" title="Quitar el 2º precio"><i class="bi bi-x-circle"></i> Quitar</button><?php endif; ?></div></td>
                    <td class="c"><?php if ($precio_ed): ?><input type="number" class="fv-num fv-cant2" name="cant2[<?= $id_it ?>]" min="0" max="<?= $vend_t ?>" step="1" value="<?= $n2 ?>" style="width:70px;text-align:center;"><div class="fv-det">de <?= $vend_t ?></div><?php else: ?><strong><?= $n2 ?></strong><?php endif; ?></td>
                    <td class="r"><?php if ($precio_ed): ?><div class="fv-pesos-in" style="justify-content:flex-end;"><span>$</span><input type="number" class="fv-precio-ap" name="precio2[<?= $id_it ?>]" min="0" step="1" value="<?= (int)round($p2) ?>" data-orig="<?= (int)round($p2) ?>" style="width:110px;text-align:right;"></div>
                      <?php else: ?><?= $pesos($p2) ?><?php endif; ?><div class="fv-det fv-precio-origen" data-orig="<?= $h($it['precio_2_origen'] ?? 'Mismo precio de la fila') ?>"><?= $h($it['precio_2_origen'] ?? 'Mismo precio de la fila') ?></div></td>
                    <td class="r fv-total-fila"><?= $pesos($n2 * $p2) ?></td>
                  </tr>
                  <?php endif; endforeach; ?>
                </tbody>
                <tfoot><tr><td>Totales</td><td class="c"><?= array_sum(array_column($items, 'vendidas')) ?></td><td></td><td class="r" id="fv-total-venta-tabla"><?= $pesos($f['total_venta']) ?></td></tr></tfoot>
              </table>
            </div>
            <?php endif; ?>
            <?php if ($ocultos): ?><div class="fv-det" style="max-width:none;margin-top:6px;"><i class="bi bi-eye-slash"></i> No se muestran <?= $ocultos ?> fila(s) sin unidades vendidas (paquetes no armados y títulos que volvieron completos).</div><?php endif; ?>
            <?php endif; /* fin tabla por grado / tabla de ventas anterior */ ?>
            <fieldset <?= $cerrado ? 'disabled' : '' ?>>
            <?php if ($f['automatico'] || trim((string)$f['responsable']) === ''): /* 2026-11-23: obligatorio para cerrar (fvd_guardar_dinero); se propone el del pedido. */ ?>
            <div class="fv-sub">Responsable de la venta</div>
            <div class="fv-grid">
              <div class="fv-campo"><label>Responsable de la venta <span class="fv-req">*</span></label><input type="text" name="responsable" maxlength="150" value="<?= $val('responsable') ?>" required placeholder="Quien hizo la venta en el colegio"<?= $fijo('responsable') ?>>
                <?php if (trim((string)$f['responsable']) === ''): ?><div class="fv-det">No quedó registrado al aprobar el pedido: escríbelo para poder cerrar.</div><?php endif; ?></div>
              <div class="fv-campo"><label>Teléfono del responsable</label><input type="text" name="tel_responsable" maxlength="40" value="<?= $val('tel_responsable') ?>"></div>
            </div>
            <?php endif; ?>
            <?php if ($f['automatico']): /* Sin paso de entrega: los auxiliares de la venta se llenan aquí (fvd_guardar_dinero). */ ?>
            <div class="fv-sub">Auxiliares de la venta</div>
            <div id="fv-auxiliares">
              <?php foreach ($f['auxiliares_lista'] ?: [['nombre' => '', 'valor' => '']] as $a): ?>
              <div class="fv-aux-fila">
                <div class="fv-campo"><label>Nombre del auxiliar</label><input type="text" name="aux_nombre[]" maxlength="150" value="<?= $h($a['nombre']) ?>"></div>
                <div class="fv-m"><label>Valor por persona</label><div class="fv-pesos-in"><span>$</span><input type="number" class="fv-aux-valor" name="aux_valor[]" min="0" step="1" placeholder="0" value="<?= (float)$a['valor'] ? (int)$a['valor'] : '' ?>"></div></div>
                <button type="button" class="fv-link fv-aux-quitar" title="Quitar"><i class="bi bi-trash"></i></button>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px;">
              <?php if (!$cerrado): ?><button type="button" class="fv-btn fv-btn-light" id="fv-aux-agregar" style="padding:6px 14px;font-size:.8rem;"><i class="bi bi-person-plus"></i> Agregar otro auxiliar</button><?php else: ?><span></span><?php endif; ?>
              <div class="fv-det" style="max-width:none;">Total auxiliares: <strong id="fv-aux-total">$ 0</strong> · si se pagaron de la caja, inclúyelos en gastos.</div>
            </div>
            <div class="fv-sub">Cajas</div>
            <div class="fv-campo" style="max-width:220px;"><label for="f_total_cajas">Total de cajas <span class="fv-req">*</span></label><input type="number" id="f_total_cajas" name="total_cajas" min="0" step="1" placeholder="0" value="<?= $f['total_cajas'] !== null ? (int)$f['total_cajas'] : '' ?>"></div>
            <?php endif; ?>
            <div class="fv-sub">Dinero</div>
            <div class="fv-caja">
              <div class="fv-card">
                <div class="fv-card-tit"><i class="bi bi-wallet2"></i> Base de caja</div>
                <div class="fv-m"><label for="f_base_entregada">Base entregada</label><div class="fv-pesos-in"><span>$</span><input type="number" class="fv-pesos" id="f_base_entregada" name="base_entregada" min="0" step="1" placeholder="0" value="<?= $pesos_in('base_entregada') ?>"></div></div>
                <div class="fv-m"><label for="f_gastos">Gastos</label><div class="fv-pesos-in"><b class="fv-signo">−</b><span>$</span><input type="number" class="fv-pesos" id="f_gastos" name="gastos" min="0" step="1" placeholder="0" value="<?= $pesos_in('gastos') ?>"></div></div>
                <div class="fv-res"><span>Base devuelta</span><strong id="fv-base-dev">$ 0</strong></div>
                <?php if ($f['auxiliares_total'] && !$f['automatico']): ?><div class="fv-det" style="max-width:none;">Auxiliares de la venta (paso 2): <strong><?= $pesos($f['auxiliares_total']) ?></strong> — <?= $h(fvd_auxiliares_texto($f)) ?>. Si se pagaron de la caja, inclúyelos en gastos.</div><?php endif; ?>
              </div>
              <div class="fv-card">
                <div class="fv-card-tit"><i class="bi bi-cash-coin"></i> Recaudo de la venta</div>
                <div class="fv-m"><label for="f_efectivo">Efectivo</label><div class="fv-pesos-in"><span>$</span><input type="number" class="fv-pesos" id="f_efectivo" name="efectivo" min="0" step="1" placeholder="0" value="<?= $pesos_in('efectivo') ?>"></div></div>
                <div class="fv-m"><label for="f_datafono">Datáfono</label><div class="fv-pesos-in"><b class="fv-signo">+</b><span>$</span><input type="number" class="fv-pesos" id="f_datafono" name="datafono" min="0" step="1" placeholder="0" value="<?= $pesos_in('datafono') ?>"></div></div>
                <div class="fv-m"><label for="f_breb">Bre-B</label><div class="fv-pesos-in"><b class="fv-signo">+</b><span>$</span><input type="number" class="fv-pesos" id="f_breb" name="breb" min="0" step="1" placeholder="0" value="<?= $pesos_in('breb') ?>"></div></div>
                <div class="fv-res"><span>Suma total</span><strong id="fv-suma">$ 0</strong></div>
              </div>
            </div>
            <div class="fv-resumen">
              <div><span>Total venta</span><strong id="fv-total-venta"><?= $pesos($cl ? $cl['gran_total'] : $f['total_venta']) ?></strong><small><?= $cl ? 'Gran total de la tabla por grado' : array_sum(array_column($items, 'vendidas')) . ' unidades vendidas' ?></small></div>
              <div><span>Suma total recaudada</span><strong id="fv-suma2">$ 0</strong><small>Efectivo + datáfono + Bre-B</small></div>
              <div class="fv-resumen-dif" id="fv-dif-box"><span>Diferencia</span><strong id="fv-dif">$ 0</strong><small id="fv-dif-txt">Recaudo − total venta</small></div>
              <div><span>Valor inventario devuelto</span><strong id="fv-valor-inv"><?= $pesos($f['valor_inventario']) ?></strong><small>Recibido final × precio</small></div>
            </div>
            <div class="fv-campo" style="margin-top:14px;"><label>Observaciones del cierre <span class="fv-req d-none" id="fv-cierre-req">* (justifica la diferencia)</span></label><textarea name="obs_cierre" rows="2" maxlength="2000"><?= $val('obs_cierre') ?></textarea></div>
            </fieldset>
            <?php if (!$cerrado && $activo): ?>
            <div class="fv-acc">
              <button type="submit" name="accion" value="dinero_borrador" class="fv-btn fv-btn-light" formnovalidate><i class="bi bi-save"></i> Guardar</button>
              <button type="submit" name="accion" value="dinero_cerrar" class="fv-btn fv-btn-ok" data-confirmar="Se confirma el cierre de dinero y la operación queda cerrada. Después no se puede modificar."><i class="bi bi-lock"></i> Confirmar cierre</button>
            </div>
            <?php endif; ?>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($hist && !$solo_cierre): ?>
      <div class="fv-sec">
        <div class="fv-sec-head"><i class="bi bi-clock-history"></i> Historial</div>
        <div class="fv-sec-body">
          <table class="fv-tabla">
            <thead><tr><th>Fecha y hora</th><th>Cambio</th><th>Usuario</th><th>Detalle</th></tr></thead>
            <tbody>
              <?php foreach ($hist as $x): ?>
              <tr><td style="white-space:nowrap"><?= $h($fecha_hora($x['fecha'])) ?></td><td><?= $h($x['texto']) ?></td><td><?= $h($x['nombre'] ?: '—') ?></td><td><?= $h($x['motivo'] ?? '') ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
<?php endif; ?>
    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<?php if ($f && $f['anulable']): ?>
<div class="fv-modal" id="fv-modal">
  <form method="POST" action="php/formato_vd_anular.php">
    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <h5 style="font-weight:800;margin-bottom:6px;">Anular <?= $h(fvd_numero($f['consecutivo'])) ?></h5>
    <p style="font-size:.85rem;color:#64748b;">Sus pedidos quedan libres para otro formato. Escribe el motivo:</p>
    <textarea name="motivo" class="form-control" rows="3" maxlength="<?= MOTIVO_ANULACION_MAX ?>" required></textarea>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
      <button type="button" class="fv-btn fv-btn-light" id="fv-cancelar">Cancelar</button>
      <button type="submit" class="fv-btn" style="background:#dc2626;">Anular formato</button>
    </div>
  </form>
</div>
<?php endif; ?>
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/ink-alerts.js"></script>
<script>
(function () {
  function pesos(v) { return '$ ' + Math.round(v).toLocaleString('es-CO'); }
  function num(el) { var n = parseInt($(el).val(), 10); return isNaN(n) ? 0 : n; }
  var LIBROS = <?= json_encode((object)($nombres_libros ?? [])) ?>;

  $('#fv-anular').on('click', function () { $('#fv-modal').css('display', 'flex').find('textarea').focus(); });
  $('#fv-cancelar').on('click', function () { $('#fv-modal').hide(); });

  // ── Firmas: dibujar en el recuadro o subir foto/PDF ──
  $('.fv-firma').each(function () {
    var $c = $(this), canvas = $c.find('canvas')[0], ctx = canvas.getContext('2d'), dibujando = false;
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
    function punto(e) {
      var r = canvas.getBoundingClientRect();
      return { x: (e.clientX - r.left) * canvas.width / r.width, y: (e.clientY - r.top) * canvas.height / r.height };
    }
    canvas.addEventListener('pointerdown', function (e) {
      dibujando = true; canvas.setPointerCapture(e.pointerId);
      var p = punto(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x + .1, p.y + .1); ctx.stroke();
      $c.addClass('firmado');
    });
    canvas.addEventListener('pointermove', function (e) { if (!dibujando) return; var p = punto(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    canvas.addEventListener('pointerup', function () { dibujando = false; });
    canvas.addEventListener('pointercancel', function () { dibujando = false; });
    $c.find('.fv-firma-borrar').on('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height); $c.removeClass('firmado con-archivo');
      $c.find('.fv-firma-file').val(''); $c.find('.fv-firma-nombre').text('');
    });
    $c.find('.fv-firma-file').on('change', function () {
      var f = this.files[0];
      $c.toggleClass('con-archivo', !!f).find('.fv-firma-nombre').text(f ? f.name : '');
    });
  });
  // Al enviar: la firma dibujada viaja como PNG. Las firmas son obligatorias: devuelve el error de la primera que falte.
  function firmasListas($form) {
    var falta = null;
    $form.find('.fv-firma').each(function () {
      var $c = $(this), archivo = $c.find('.fv-firma-file')[0].files.length > 0;
      $c.find('.fv-firma-dato').val(!archivo && $c.hasClass('firmado') ? $c.find('canvas')[0].toDataURL('image/png') : '');
      if (!falta && !archivo && !$c.hasClass('firmado')) falta = 'Falta la firma de ' + $c.closest('.fv-firma-box').find('.fv-paq').text().replace('*', '').trim() + ': fírmala en el recuadro o sube la foto/PDF del papel firmado.';
    });
    return falta;
  }

  // ── Paso 2: inventario entregado ──
  function recalcEntrega() {
    var t = 0;
    $('.fv-fila-ent').each(function () { var n = num($(this).find('.fv-ent')); t += n; $(this).find('.fv-prop').toggleClass('d-none', n === Number($(this).data('prop'))); });
    $('#fv-t-ent').text(t);
  }
  $(document).on('input change', '.fv-ent', recalcEntrega);
  recalcEntrega();

  // ── Paso 2: auxiliares de la venta (nombre + valor por persona) ──
  function recalcAux() {
    var t = 0;
    $('.fv-aux-valor').each(function () { t += num(this); });
    $('#fv-aux-total').text(pesos(t));
    $('.fv-aux-quitar').toggle($('.fv-aux-fila').length > 1);
  }
  $('#fv-aux-agregar').on('click', function () {
    var $nueva = $('.fv-aux-fila').first().clone();
    $nueva.find('input').val('');
    $('#fv-auxiliares').append($nueva);
    $nueva.find('input').first().focus();
    recalcAux();
  });
  $(document).on('click', '.fv-aux-quitar', function () { $(this).closest('.fv-aux-fila').remove(); recalcAux(); });
  $(document).on('input change', '.fv-aux-valor', recalcAux);
  recalcAux();

  // ── Paso 3: devueltas ≤ entregado; vendidas = entregado − devueltas ──
  function recalcDevolucion() {
    var tDev = 0, tVend = 0;
    $('.fv-fila-dev').each(function () {
      var $f = $(this), ent = Number($f.data('ent')), $in = $f.find('.fv-dev'), vacio = $in.val() === '', dev = num($in);
      $in.toggleClass('is-invalid', dev > ent);
      $f.find('.fv-vend').text(vacio ? '—' : Math.max(0, ent - dev));
      tDev += dev; if (!vacio) tVend += Math.max(0, ent - dev);
    });
    $('#fv-t-dev').text(tDev); $('#fv-t-vend').text(tVend);
    // Vista previa por libro: cada paquete devuelto es una unidad de cada uno de sus títulos (fvd_libros_devueltos).
    var porLibro = {}, total = 0;
    $('.fv-fila-dev').each(function () {
      var dev = num($(this).find('.fv-dev')), nombre = $(this).data('nombre');
      if (dev <= 0) return;
      String($(this).data('libros')).split(',').forEach(function (l) {
        if (!l) return;
        porLibro[l] = porLibro[l] || { n: 0, origen: [] };
        porLibro[l].n += dev; porLibro[l].origen.push(dev + ' × ' + nombre); total += dev;
      });
    });
    var h = '';
    Object.keys(porLibro).sort(function (a, b) { return String(LIBROS[a] || '').localeCompare(String(LIBROS[b] || '')); }).forEach(function (l) {
      h += '<tr><td><div class="fv-paq">' + $('<div>').text(LIBROS[l] || ('Libro #' + l)).html() + '</div></td><td class="fv-det">' + $('<div>').text(porLibro[l].origen.join(' + ')).html() + '</td><td class="c"><strong>' + porLibro[l].n + '</strong></td></tr>';
    });
    $('#fv-prev-doc tbody').html(h || '<tr><td colspan="3" class="fv-det">Sin unidades devueltas: todo queda como vendido en el saldo.</td></tr>');
    $('#fv-prev-total').text(total);
  }
  $(document).on('input change', '.fv-dev', recalcDevolucion);
  recalcDevolucion();

  // ── Paso 5: fecha y hora de recepción (sin max en el campo: el selector del navegador bloqueaba hoy; el servidor rechaza días futuros) ──
  $('#fv-ahora').on('click', function () {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    $('#fv-recibido-at').val(d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()));
  });

  // ── Paso 5: recibidas vs devueltas ──
  function recalcRecepcion() {
    var hay = false;
    $('.fv-fila-rec').each(function () {
      var $f = $(this), d = num($f.find('.fv-rec')) - Number($f.data('dev'));
      $f.find('.fv-rdif').text(d > 0 ? '+' + d : d); $f.toggleClass('dif', d !== 0); if (d !== 0) hay = true;
    });
    $('#fv-rec-aviso, #fv-rec-req').toggleClass('d-none', !hay);
    $('[name="obs_recepcion"]').prop('required', hay);
  }
  $(document).on('input change', '.fv-rec', recalcRecepcion);
  recalcRecepcion();

  // ── Paso 6: tabla por grado del cierre de ventas (2026-11-26; mismo cálculo que fvdl_calcular) ──
  var clVal = function ($tr, k) {
    var $i = $tr.find('.fv-cl-in[data-k="' + k + '"]');
    return $i.length ? num($i) : Number($tr.find('.fv-cl-v[data-k="' + k + '"]').data('v')) || 0;
  };
  var clNov = function () { return $('#fv-cl-nov').is(':checked'); };
  // Kit / Suelto / Sin inventario son solo lo que se escribe (arrancan en 0 y no se llenan solos).
  $(document).on('input change', '#fv-cl .fv-cl-in', function () { recalcCL(); recalcDinero(); });
  // Casilla "La venta tuvo novedades": muestra u oculta Kit / Suelto / Sin inventario.
  $('#fv-cl-nov').on('change', function () {
    var nov = clNov();
    $('#fv-cl').toggleClass('sin-nov', !nov).find('.fv-cl-span').attr('colspan', nov ? 10 : 7);
    recalcCL(); recalcDinero();
  });
  function recalcCL() {
    var $t = $('#fv-cl'); if (!$t.length || !window.FV_CL) return;
    var nov = clNov(), filas = {}, errores = [], libre = {}, porGrado = {}, totKit = 0, totSue = 0, nKits = 0, kitsGrado = {};
    $t.find('.fv-cl-fila').each(function () {
      var $tr = $(this), id = String($tr.data('id')), x = {};
      ['sol', 'rem', 'dev', 'kit', 'suelto', 'sinv', 'pk', 'ps'].forEach(function (k) { x[k] = clVal($tr, k); });
      x.$tr = $tr; x.vend = x.rem - x.dev; x.nombre = $tr.find('.fv-paq').contents().first().text();
      x.grado = String($tr.closest('tbody').data('grado')); x.tk = 0; x.enKit = 0;
      filas[id] = x; libre[id] = Math.max(0, x.vend);
    });
    // Kits de cada paquete: con novedades y Kit escrito en alguno de sus libros, el mayor escrito; si no, se arma solo con lo vendido.
    FV_CL.paquetes.forEach(function (p) {
      var pres = p.libros.filter(function (l) { return l.fila !== null && filas[String(l.fila)]; }).map(function (l) { return String(l.fila); });
      if (!pres.length) return;
      var escrito = nov ? Math.max.apply(null, pres.map(function (i) { return filas[i].kit; })) : 0, n;
      if (escrito > 0) n = escrito;
      else if (pres.length === p.libros.length) n = Math.min.apply(null, pres.map(function (i) { return libre[i]; }));
      else return;
      if (n <= 0) return;
      var precio = 0;
      p.libros.forEach(function (l) {
        var i = l.fila === null ? null : String(l.fila);
        if (i === null || !filas[i]) { precio += l.pk; return; }
        var x = filas[i];
        precio += x.pk; libre[i] -= Math.min(libre[i], n); x.enKit += n; x.tk += n * x.pk;
      });
      var g = $t.find('tbody[data-grado="' + p.grado + '"]').length ? p.grado : filas[pres[0]].grado;
      (kitsGrado[g] = kitsGrado[g] || []).push({ nombre: p.nombre, kits: n, precio: precio });
      porGrado[g] = porGrado[g] || { k: 0, s: 0, falta: false }; porGrado[g].k += n * precio; totKit += n * precio; nKits += n;
    });
    Object.keys(filas).forEach(function (id) {
      var x = filas[id], $tr = x.$tr, mal = false, sinPaq = !String($tr.data('paq'));
      var suelto = libre[id]; // lo vendido que no fue en kits
      if (nov && x.suelto > 0) {
        if (x.suelto !== libre[id]) { errores.push('"' + x.nombre + '": se vendieron ' + Math.max(0, x.vend) + (x.enKit ? ' y ' + Math.min(Math.max(0, x.vend), x.enKit) + ' van en kits' : '') + ': el Suelto debe ser ' + libre[id] + ' (escribiste ' + x.suelto + ').'); mal = true; }
        suelto = x.suelto;
      }
      porGrado[x.grado] = porGrado[x.grado] || { k: 0, s: 0, falta: false };
      var falta = x.rem < x.sol;
      if (falta) porGrado[x.grado].falta = true;
      $tr.find('.fv-cl-rem').toggleClass('falta', falta);
      $tr.find('.fv-cl-vend').text(x.vend);
      $tr.find('.fv-cl-tk').text(pesos(x.tk)); $tr.find('.fv-cl-ts').text(pesos(suelto * x.ps));
      porGrado[x.grado].s += suelto * x.ps; totSue += suelto * x.ps;
      if (x.vend < 0) { errores.push('"' + x.nombre + '": lo devuelto (' + x.dev + ') es más que lo remisionado (' + x.rem + ').'); mal = true; }
      if (nov && x.kit > 0 && sinPaq) { errores.push('"' + x.nombre + '" no hace parte de ningún paquete de la adopción: no puede ir en Kit.'); mal = true; }
      if (suelto > 0 && x.ps <= 0) { errores.push('"' + x.nombre + '": falta el precio suelto.'); mal = true; }
      if (x.tk > 0 && x.pk <= 0) { errores.push('"' + x.nombre + '": falta el precio kit.'); mal = true; }
      $tr.toggleClass('mal', mal);
    });
    $t.find('tbody.fv-cl-g').each(function () {
      var g = String($(this).data('grado')), d = porGrado[g] || { k: 0, s: 0, falta: false }, $h = $(this).find('.fv-cl-head');
      $h.toggleClass('faltante', d.falta);
      $h.find('.fv-cl-gk').text(pesos(d.k)); $h.find('.fv-cl-gs').text(pesos(d.s));
      $h.find('.fv-cl-kits').text(kitsGrado[g] ? kitsGrado[g].map(function (p) { return p.nombre + ': ' + p.kits + ' kit(s) × ' + pesos(p.precio); }).join(' · ') : 'Sin kits');
    });
    $('#fv-cl-tk').text(pesos(totKit)); $('#fv-cl-ts').text(pesos(totSue)); $('#fv-cl-gran').text(pesos(totKit + totSue)); $('#fv-cl-nkits').text(nKits);
    var $e = $('#fv-cl-errores');
    $e.toggleClass('d-none', !errores.length).find('ul').html(errores.map(function (t) { return '<li>' + $('<div>').text(t).html() + '</li>'; }).join(''));
    $('#fv-form-6').data('total', totKit + totSue).data('cl-errores', errores);
    $('#fv-total-venta').text(pesos(totKit + totSue));
  }
  recalcCL();

  // ── Paso 6: mismos cálculos que fvd_guardar_dinero() ──
  function recalcDinero() {
    var $form = $('#fv-form-6'); if (!$form.length) return;
    // Precio aplicado editable (2026-11-19): total de cada fila, total venta y valor del inventario (mismo cálculo que fvd_conciliar).
    var $filasV = $form.find('.fv-fila-venta');
    if ($filasV.find('.fv-precio-ap').length) {
      var tv = 0, vi = 0, c2 = {};
      // 2 devoluciones (2026-11-24): vendidas a precio 2 (0 a las vendidas); el renglón de precio 1 queda con el resto.
      $form.find('.fv-cant2').each(function () {
        var $tr = $(this).closest('tr'), id = $tr.data('item'), tot = Number($form.find('.fv-fila-venta[data-item="' + id + '"]').first().data('vend-total')) || 0;
        var n = num(this), ok = n >= 0 && n <= tot && String($(this).val()).indexOf('.') < 0;
        $(this).toggleClass('is-invalid', !ok);
        n = Math.max(0, Math.min(n, tot)); c2[id] = n; $tr.data('vend', n);
      });
      $filasV.each(function () {
        var $tr = $(this), id = $tr.data('item');
        if ($tr.data('vend-total') !== undefined && c2[id] !== undefined) { $tr.data('vend', Number($tr.data('vend-total')) - c2[id]); $tr.find('.fv-vend-txt').text(Number($tr.data('vend-total')) - c2[id]); }
      });
      $filasV.each(function () {
        var $tr = $(this), $p = $tr.find('.fv-precio-ap'), p = num($p), vend = Number($tr.data('vend')), fin = Number($tr.data('fin'));
        var $o = $tr.find('.fv-precio-origen'), cambio = String(p) !== String($p.data('orig'));
        $o.text(cambio ? 'Precio editado' : $o.data('orig'));
        $p.toggleClass('is-invalid', vend !== 0 && p <= 0);
        $tr.find('.fv-total-fila').text(pesos(vend * p));
        tv += vend * p; vi += fin * p;
      });
      $form.data('total', tv);
      $('#fv-total-venta, #fv-total-venta-tabla').text(pesos(tv));
      $('#fv-valor-inv').text(pesos(vi));
    }
    var total = Number($form.data('total')), base = num('[name="base_entregada"]'), gastos = num('[name="gastos"]');
    var suma = num('[name="efectivo"]') + num('[name="datafono"]') + num('[name="breb"]'), dif = suma - total, cuadra = Math.round(dif) === 0;
    $('#fv-base-dev').text(pesos(base - gastos));
    $('#fv-suma, #fv-suma2').text(pesos(suma));
    $('#fv-dif').text(pesos(dif));
    $('#fv-dif-box').toggleClass('ok', cuadra).toggleClass('mal', !cuadra);
    $('#fv-dif-txt').text(cuadra ? 'La caja cuadra' : (dif < 0 ? 'Falta ' + pesos(-dif) + ' por recaudar' : 'Sobran ' + pesos(dif)));
    $('#fv-cierre-req').toggleClass('d-none', cuadra);
  }
  $(document).on('input change', '.fv-pesos, .fv-precio-ap, .fv-cant2', recalcDinero);
  // 2º precio (2026-11-24): se muestra el renglón al pedirlo; al quitarlo vuelve a 0 y todo queda al precio 1.
  $(document).on('click', '.fv-p2-abrir', function () {
    var id = $(this).data('item');
    $(this).addClass('d-none').closest('tr').addClass('fv-split');
    $('.fv-fila-p2[data-item="' + id + '"]').removeClass('d-none').find('.fv-cant2').trigger('focus').trigger('select');
  });
  $(document).on('click', '.fv-p2-quitar', function () {
    var id = $(this).data('item'), $p2 = $('.fv-fila-p2[data-item="' + id + '"]');
    $p2.addClass('d-none').find('.fv-cant2').val(0);
    $('.fv-fila-venta[data-item="' + id + '"]').first().removeClass('fv-split').find('.fv-p2-abrir').removeClass('d-none');
    recalcDinero();
  });
  recalcDinero();

  // ── Envío: validación en pantalla (el servidor valida lo mismo) y confirmación ──
  var boton = null;
  $(document).on('click', 'form [type="submit"]', function () { boton = this; });
  $('form[action="php/formato_vd_guardar.php"]').on('submit', function (e) {
    var $form = $(this), $b = $(boton && $form.has(boton).length ? boton : $form.find('[type="submit"]').last());
    if ($form.data('ok')) return;
    e.preventDefault();
    var accion = $b.val() || $form.find('[name="accion"]').val(), error = null;
    if ($form.hasClass('fv-con-firmas')) error = firmasListas($form);
    if (!error && accion === 'devolucion_generar') {
      $('.fv-fila-dev').each(function () {
        var v = $(this).find('.fv-dev').val(), nombre = $(this).find('.fv-paq').text();
        if (!/^\d+$/.test(v)) { error = 'Escribe las unidades devueltas de "' + nombre + '" (0 si no se devuelve nada).'; return false; }
        if (parseInt(v, 10) > Number($(this).data('ent'))) { error = 'En "' + nombre + '" no se puede devolver más de lo entregado.'; return false; }
      });
    }
    if (!error && accion === 'dinero_cerrar' && ($form.data('cl-errores') || []).length) error = 'Revisa la tabla del cierre antes de cerrar: ' + $form.data('cl-errores')[0];
    if (!error && accion === 'dinero_cerrar' && !$('#fv-cierre-req').hasClass('d-none') && !$.trim($('[name="obs_cierre"]').val())) error = 'El recaudo no cuadra con el total de venta: justifica la diferencia en las observaciones del cierre.';
    if (!error && $b.is('[formnovalidate]') === false && !$form[0].checkValidity()) { $form[0].reportValidity(); return; }
    if (error) { inkToast(error, 'error'); return; }
    var enviar = function () {
      // submit() no envía el botón pulsado: la acción va en un campo oculto.
      // fv-accion-envio: el que agrega este envío (se quita al volver a la página, ver pageshow).
      if (!$form.find('input[type="hidden"][name="accion"]').not('.fv-accion-envio').length) {
        $form.find('.fv-accion-envio').remove();
        $form.append($('<input type="hidden" name="accion" class="fv-accion-envio">').val(accion));
      }
      $form.data('ok', true);
      $form.find('[type="submit"]').each(function () {
        if ($(this).data('html-orig') === undefined) $(this).data({ 'html-orig': $(this).html(), 'disabled-orig': $(this).prop('disabled') });
      }).prop('disabled', true);
      $b.html('<i class="bi bi-hourglass-split"></i> Guardando...');
      $form[0].submit();
    };
    var texto = $b.data('confirmar');
    if (texto) inkConfirm({ type: 'warning', title: '¿Confirmar?', text: texto, btnOk: 'Sí, continuar' }, enviar); else enviar();
  });
  // Al volver con el navegador (p. ej. "Volver al formato" tras un error del servidor, 2026-11-24) la página sale de la
  // caché tal como quedó al enviar: formulario marcado como enviado y botones en "Guardando...". Se deja lista otra vez,
  // conservando lo escrito.
  window.addEventListener('pageshow', function () {
    $('form[action="php/formato_vd_guardar.php"]').each(function () {
      var $form = $(this);
      $form.removeData('ok');
      $form.find('.fv-accion-envio').remove();
      $form.find('[type="submit"]').each(function () {
        var orig = $(this).data('html-orig');
        if (orig === undefined) return; // este envío no lo tocó
        $(this).html(orig).prop('disabled', !!$(this).data('disabled-orig')).removeData('html-orig disabled-orig');
      });
    });
    boton = null;
  });
  // ── Armar paquetes (2026-11-19): cada paquete usa 1 unidad de cada libro; entre todos no se pasa de lo vendido suelto
  // (mismas reglas que fvd_armar_paquetes). Lo que no se arma queda suelto a su precio venta padre. ──
  function recalcArmar() {
    if (!window.FV_ARMAR) return;
    var L = FV_ARMAR.libros, uso = {}, nPaq = 0, mal = false;
    var libsDe = function ($tr) { return String($tr.data('libros')).split(',').filter(Boolean); };
    var nDe = function ($tr) { var $i = $tr.find('.fv-armar-n'); return $i.length ? num($i) : (parseInt($tr.data('armados'), 10) || 0); };
    $('.fv-armar-fila').each(function () {
      var $tr = $(this), n = nDe($tr);
      libsDe($tr).forEach(function (l) { uso[l] = (uso[l] || 0) + n; });
      nPaq += n;
    });
    $('.fv-armar-fila').not('.fv-armar-off').each(function () {
      var $tr = $(this), $i = $tr.find('.fv-armar-n'), propio = nDe($tr), max = null;
      libsDe($tr).forEach(function (l) { var v = L[l] ? L[l].v : 0, m = v - (uso[l] || 0) + propio; max = max === null ? m : Math.min(max, m); });
      max = Math.max(0, max || 0);
      $tr.find('.fv-armar-max').text(max);
      if ($i.length) { $i.attr('max', max); $i.toggleClass('is-invalid', propio > max); if (propio > max) mal = true; }
    });
    $('.fv-armar-queda').each(function () { var l = $(this).data('libro'); $(this).text((L[l] ? L[l].v : 0) - (uso[l] || 0)); });
    var sue = 0;
    Object.keys(L).forEach(function (l) { sue += L[l].v - (uso[l] || 0); });
    $('#fv-ar-paq').text(nPaq);
    $('#fv-ar-sue').text(sue);
    $('#fv-armar-btn').prop('disabled', mal);
  }
  $(document).on('input change', '.fv-armar-n', recalcArmar);
  recalcArmar();
})();
</script>
</body>
</html>
