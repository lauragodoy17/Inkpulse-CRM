<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/pedidos_venta_directa.php");
asegurar_columnas_pedido_vd($bdd);

$id_pedido = intval($_GET['id_pedido'] ?? 0);
$tp        = intval($_GET['tp'] ?? 2);

$tp_cfg = [
  2 => ['label'=>'Pendiente',  'badge'=>'yellow', 'icon'=>'bi-hourglass-split',   'accent'=>'#b45309'],
  3 => ['label'=>'Aprobado',   'badge'=>'green',  'icon'=>'bi-check-circle-fill', 'accent'=>'#16a34a'],
  4 => ['label'=>'Entregado',  'badge'=>'green',   'icon'=>'bi-truck',             'accent'=>'#2563eb'],
  5 => ['label'=>'Anulado',    'badge'=>'red',    'icon'=>'bi-x-circle-fill',     'accent'=>'#b91c1c'],
  6 => ['label'=>'Procesando',    'badge'=>'blue',    'icon'=>'bi-shuffle',     'accent'=>'#b91c1c'],
  7 => ['label'=>'Facturación',    'badge'=>'yellow',    'icon'=>'bi-file-earmark-text',     'accent'=>'#b91c1c'],
  8 => ['label'=>'En despacho',   'badge'=>'purple',   'icon'=>'bi-box-seam',   'accent'=>'#7c3aed'],
];
$ac = $tp_cfg[$tp] ?? $tp_cfg[2];

// Pedido principal
$stmt = $bdd->prepare(
  "SELECT pe.id, pe.id_colegio, pe.id_periodo, pe.fecha, pe.observaciones, pe.fecha_r, pe.cliente, pe.fac_rem, pe.dir_ent, pe.tipo AS petipo,
          pe.para_andrea, pe.vd_responsable, pe.vd_tel_responsable, pe.vd_fecha_venta, pe.vd_hora_inicio, pe.vd_hora_fin, pe.vd_contacto_colegio, pe.vd_tel_contacto, pe.vd_direccion, pe.vd_observaciones,
          z.codigo AS codzona, z.zona,
          c.colegio, c.sub_zona, c.responsable,
          cal.calendario,
          u.nombres, u.apellidos, u.tipo,
          e.estado, e.id AS eid
   FROM pedidos pe
   JOIN colegios c  ON pe.id_colegio = c.id
   JOIN zonas z     ON z.codigo = c.cod_zona
   JOIN usuarios u  ON u.cod_zona = z.codigo
   JOIN estados_pedidos e ON e.id = pe.estado
   LEFT JOIN calendarios cal ON c.id_calendario = cal.id
   WHERE pe.id = ?"
);
$stmt->execute([$id_pedido]);
$pedido = $stmt->fetch();

// Cliente
$stmt2 = $bdd->prepare("SELECT cliente FROM clientes WHERE id = ?");
$stmt2->execute([$pedido['cliente'] ?? 0]);
$cliente = $stmt2->fetch();

// Sub_zona
$sub_zona_nombre = '—';
if (intval($pedido['tipo'] ?? 0) != 3) {
  $stmt3 = $bdd->prepare("SELECT sub_zona FROM sub_zonas WHERE id = ?");
  $stmt3->execute([$pedido['sub_zona'] ?? 0]);
  $sz = $stmt3->fetch();
  $sub_zona_nombre = $sz['sub_zona'] ?? '—';
}

if (intval($pedido['tipo'] ?? 0) == 3) {
  $parts     = explode("/", $pedido['zona'] ?? '');
  $d_empresa = htmlspecialchars(trim($parts[0] ?? ''));
  $d_zona    = htmlspecialchars(trim($parts[1] ?? ''));
  $d_resp    = htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')));
} else {
  $d_empresa = htmlspecialchars(trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')));
  $d_zona    = htmlspecialchars($sub_zona_nombre);
  $d_resp    = htmlspecialchars($pedido['responsable'] ?? '—');
}

// Libros. Desde 2026-11-10 un libro puede venir de un paquete (libros_pedidos.id_paquete) y, con adopción "Ambos",
// también como suelto en el mismo pedido: se agrupa por renglón de libros_pedidos (antes por libro) para no juntarlos.
require_once("includes/pedido_paquetes.php");
require_once("includes/plan_lector.php"); // MATERIA_PLAN_LECTOR
pedido_paquetes_asegurar($bdd);
require_once("includes/sobrecosto_canal.php");
sobrecosto_asegurar_columna($bdd); // presupuestos.pp_editado_pedido (precio venta padre: una sola modificación desde el pedido)
$stmt4 = $bdd->prepare(
  "SELECT l.id AS libroid, l.id_grado, l.id_materia, l.libro, l.precio, l.isbn,
          m.materia, lp.cantidad, p.cod_area, p.descuento, p.descuento_d,
          lp.cantidad_aprob, lp.descuento_aprob, lp.id AS lpid, lp.plataforma, p.precio_venta_final, p.pp_editado_pedido, p.id AS id_presup,
          lp.id_paquete, pq.paquete AS nombre_paquete, pq.precio_final AS precio_paquete, pq.id_grado AS grado_paquete
   FROM pedidos pe
   LEFT JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
   LEFT JOIN libros l           ON l.id = lp.id_libro
   LEFT JOIN materias m         ON l.id_materia = m.id
   LEFT JOIN paquetes_colegio pq ON pq.id = lp.id_paquete
   LEFT JOIN presupuestos p     ON p.id_colegio = pe.id_colegio
     AND p.id_libro = lp.id_libro
     AND COALESCE(lp.cod_area,'') = COALESCE(p.cod_area,'')
     AND pe.id_periodo = p.id_periodo
   WHERE pe.id = ? AND p.definido = 1 AND lp.cantidad != 0
   GROUP BY lp.id
   ORDER BY (lp.id_paquete IS NULL), lp.id_paquete, lp.id"
);
$stmt4->execute([$id_pedido]);
$libros_raw = $stmt4->fetchAll();

// OP directo
$stmt5 = $bdd->prepare("SELECT id, año FROM ordenes_pedidos WHERE id_pedido = ? AND estado != 4");
$stmt5->execute([$id_pedido]);
$op   = $stmt5->rowCount();
$n_op = $stmt5->fetch();

// OP agrupado
$stmt6 = $bdd->prepare("SELECT op FROM op_pedidos_agrupados WHERE id_pedido = ?");
$stmt6->execute([$id_pedido]);
$op_agp   = $stmt6->rowCount();
$n_op_agp = $stmt6->fetch();

// Pre-procesar libros
$libros  = [];
$total_v = 0;
$total_c = 0;
$total_vp = 0; // valor a precio venta padre (venta directa); se calcula con los grupos, más abajo
foreach ($libros_raw as $lb) {
  if (($lb['cod_area'] ?? '') == '') {
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$lb['id_grado']]);
    $g = $sg->fetch();
    $id_grado_ord = (int)$lb['id_grado'];
  } else {
    $sa = $bdd->prepare("SELECT id_grado_otro, id_materia FROM areas_objetivas WHERE codigo = ?");
    $sa->execute([$lb['cod_area']]);
    $ao = $sa->fetch();
    $sg = $bdd->prepare("SELECT grado FROM grados WHERE id = ?");
    $sg->execute([$ao['id_grado_otro'] ?? 0]);
    $g = $sg->fetch();
    $id_grado_ord = (int)($ao['id_grado_otro'] ?? 0);
  }
  // Plan lector (2026-11-23): materia del libro o del área objetiva con que se adoptó.
  $es_plan = (int)$lb['id_materia'] === MATERIA_PLAN_LECTOR || (($lb['cod_area'] ?? '') !== '' && (int)($ao['id_materia'] ?? 0) === MATERIA_PLAN_LECTOR);
  $su = $bdd->prepare(
    "SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion, lu.posicion
     FROM lugares l
     JOIN ubicaciones u      ON l.id = u.id_lugar
     JOIN libros_ubicaciones lu ON u.id = lu.ubicacion
     WHERE lu.id_libro = ?"
  );
  $su->execute([$lb['libroid']]);
  $ubs = $su->fetchAll();
  $ubi = '';
  foreach ($ubs as $ub)
    $ubi .= ($ub['id_tipo'] == 1)
      ? $ub['lugar'].$ub['piso'].' Pallet '.$ub['ubicacion'].' '.$ub['posicion'].', '
      : $ub['lugar'].' Bandeja '.$ub['ubicacion'].', ';

  $desc_val    = ($lb['descuento_d'] === '0.0000') ? floatval($lb['descuento']) : floatval($lb['descuento_d']);
  $precio_fact = floatval($lb['precio']) - (floatval($lb['precio']) * $desc_val);
  $v_venta     = $precio_fact * intval($lb['cantidad']);
  $total_v    += $v_venta;
  $total_c    += intval($lb['cantidad']);

  $libros[] = array_merge($lb, [
    'grado'       => $g['grado'] ?? '—',
    'grado_orden' => ($g && $id_grado_ord) ? $id_grado_ord : 99,
    'plan_lector' => $es_plan,
    'ubi'         => rtrim($ubi, ', '),
    'desc_pct'    => $desc_val * 100,
    'precio_fact' => $precio_fact,
    'v_venta'     => $v_venta,
  ]);
}

// Orden (2026-11-19): primero los paquetes y luego los libros sueltos por curso (grados.id va de Pre jardín a 11) y
// dentro del curso por título. Paquetes (2026-11-20): por el curso del paquete y su nombre, cada paquete con sus libros
// juntos (las celdas de precio del paquete se unen) y dentro del paquete alfabético por título.
require_once("includes/orden_texto.php"); // alfabético sin distinguir tildes
usort($libros, function ($a, $b) {
  $pa = !empty($a['id_paquete']); $pb = !empty($b['id_paquete']);
  if ($pa !== $pb) return $pa ? -1 : 1;
  if ($pa) return (((int)$a['grado_paquete'] ?: 99) <=> ((int)$b['grado_paquete'] ?: 99))
              ?: orden_texto_cmp($a['nombre_paquete'] ?? '', $b['nombre_paquete'] ?? '')
              ?: ((int)$a['id_paquete'] <=> (int)$b['id_paquete'])
              ?: orden_texto_cmp($a['libro'] ?? '', $b['libro'] ?? '');
  // 2026-11-23: por curso; dentro del curso, los libros particulares alfabético y luego el plan lector alfabético.
  return ($a['grado_orden'] <=> $b['grado_orden'])
      ?: ((int)$a['plan_lector'] <=> (int)$b['plan_lector']) ?: orden_texto_cmp($a['libro'] ?? '', $b['libro'] ?? '');
});

$es_andrea        = !empty($pedido['para_andrea']);
// Columnas "Precio venta padre" / "Venta potencial a padres" (antes "Valor venta padre"; = precio venta padre × cantidad,
// o precio del paquete × paquetes): pedidos marcados para venta directa (antes del 2026-11-10) o de
// colegios cuya adopción tiene el canal "Venta directa - Eureka" (el formulario de pedido ya no pregunta por la jornada).
require_once("includes/sobrecosto_canal.php");
$es_vd = $es_andrea || adopcion_es_venta_directa($bdd, $pedido["id_colegio"] ?? 0, $pedido["id_periodo"] ?? 0);
$show_stock       = ($tp == 2 || $tp == 3) && (($_SESSION['tipo'] ?? null) == 1 || $_SESSION['id']==25);
$show_plataforma  = (intval($pedido['tipo'] ?? 0) == 3 || ($pedido['codzona'] ?? '') == '5656');
$show_tipo_pedido = (intval($pedido['tipo'] ?? 0) == 3 || ($pedido['codzona'] ?? '') == '5656' || intval($pedido['tipo'] ?? 0) == 10);
$can_act = ($_SESSION['tipo'] == 1 || $_SESSION['tipo'] == 2 || $_SESSION['id'] == 21 || $_SESSION['tipo'] == 10);
$rechazar_label = (intval($pedido['eid'] ?? 0) == 1) ? 'Rechazar' : 'Anular';
// Venta directa: con el pedido Pendiente o Aprobado se puede cambiar el precio venta padre. Se guarda en la adopción
// del colegio (php/pedido_precio_padre.php), así aplica a todos los pedidos de ese colegio y no a otros colegios.
$puede_pp = $es_vd && $can_act && in_array(intval($pedido['eid'] ?? 0), [1, 2], true);

// Pedidos con paquetes y libros sueltos: la tabla se separa en dos grupos, "En paquete" (todos los libros de paquetes
// juntos) y "Libros sueltos", con un encabezado cada uno. Los libros ya vienen ordenados: paquetes primero, luego sueltos.
// Venta a padres: el libro suelto vale su "Precio venta padre"; los libros de un paquete no se venden por separado,
// el paquete vale su precio (paquetes_colegio.precio_final, el de Adopciones) × la cantidad de paquetes.
$grupos_ped = [];
$paq_cant = [];   // id_paquete => cantidad de paquetes (cada libro del paquete va con esa cantidad)
$paq_filas = [];  // id_paquete => filas del pedido (para unir las celdas de precio del paquete)
$paq_precio = []; // id_paquete => precio del paquete
foreach ($libros as $lb) {
  $k = !empty($lb['id_paquete']) ? 'p' : 's';
  if (!isset($grupos_ped[$k])) $grupos_ped[$k] = ['paquete' => $k === 'p', 'titulos' => 0, 'unidades' => 0, 'paquetes' => 0, 'valor' => 0, 'valor_vp' => 0];
  $grupos_ped[$k]['titulos']++;
  $grupos_ped[$k]['unidades'] += intval($lb['cantidad']);
  $grupos_ped[$k]['valor'] += $lb['v_venta'];
  if ($k === 'p') {
    $idq = (int)$lb['id_paquete'];
    $paq_cant[$idq] = max($paq_cant[$idq] ?? 0, intval($lb['cantidad']));
    $paq_filas[$idq] = ($paq_filas[$idq] ?? 0) + 1;
    $paq_precio[$idq] = floatval($lb['precio_paquete']);
  } else {
    $grupos_ped[$k]['valor_vp'] += floatval($lb['precio_venta_final']) * intval($lb['cantidad']);
  }
}
if (isset($grupos_ped['p'])) {
  $grupos_ped['p']['paquetes'] = array_sum($paq_cant);
  foreach ($paq_cant as $idq => $cq) $grupos_ped['p']['valor_vp'] += $paq_precio[$idq] * $cq;
}
$total_vp = ($grupos_ped['p']['valor_vp'] ?? 0) + ($grupos_ped['s']['valor_vp'] ?? 0);
$agrupar_ped = count(array_filter($grupos_ped, function ($g) { return $g['paquete']; })) > 0;
$n_cols_ped = 13 + ($es_vd ? 2 : 0) + ($show_plataforma ? 1 : 0) + ($show_stock ? 1 : 0);

// Quién pasó este pedido al estado que se está viendo, y cuándo (botón en el
// header) — para todos los estados desde Aprobado en adelante. El mismo
// mapeo tp->estado que usa lista_pedidos_query.php (lista_pedidos_estado_val).
require_once(__DIR__ . "/includes/historial_estados.php");
$tp_estado_map = [3 => 2, 4 => 4, 5 => 3, 6 => 5, 7 => 6, 8 => 7];
$tp_estado_label = [3 => 'Aprobado', 4 => 'Entregado', 5 => 'Anulado', 6 => 'Procesando', 7 => 'Facturación', 8 => 'En despacho'];
$procesado_info = null;
if (isset($tp_estado_map[$tp])) {
  crear_tabla_historial_estados($bdd);
  $procesado_info = obtener_historial_estado($bdd, 'pedidos', $id_pedido, $tp_estado_map[$tp]);
}

// Columnas vacías en todas las filas: se ocultan al imprimir para que el resto se ajuste
$col_isbn       = false;
$col_desc_aprob = false;
$col_cant_aprob = false;
foreach ($libros as $lb) {
  if (trim($lb['isbn'] ?? '') !== '')      $col_isbn       = true;
  if (floatval($lb['descuento_aprob'] ?? 0) > 0) $col_desc_aprob = true;
  if (floatval($lb['cantidad_aprob'] ?? 0) > 0)  $col_cant_aprob = true;
}
$ph_isbn       = $col_isbn       ? '' : ' d-print-none';
$ph_desc_aprob = $col_desc_aprob ? '' : ' d-print-none';
// Pedidos para Andrea Díaz: sin columna Descuento Aprobado (el campo sigue en el formulario y conserva su valor).
if ($es_andrea) $ph_desc_aprob .= ' d-none';
$ph_cant_aprob = $col_cant_aprob ? '' : ' d-print-none';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse — Pedido <?= $ac['label'] ?> #<?= $id_pedido ?></title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32"  href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16"  href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    /* Spin buttons */
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }

    /* Print */
    @page { size: portrait; margin: 10mm; }
    @media print {
      .mc-actions, .breadcrumb, .d-print-none, .left-side-bar, .header { display:none !important; }
      a[href]:after { content:none !important; }
      body { zoom: 0.68; }
      .mc-obs-wrap textarea { height:auto !important; min-height:0 !important; overflow:visible !important; white-space:pre-wrap !important; page-break-inside:avoid; }
      #pc-table td input[type="number"] { border:none !important; background:transparent !important; width:auto !important; }
      #pc-table thead, #pc-table tfoot { display: table-row-group !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      #pc-table { width:100% !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
    }

    /* ── Info table ── */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 1px;
      background: #e2e8f0;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(15,23,42,.06);
    }
    @media (max-width: 767px) { .mc-cards { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 480px) { .mc-cards { grid-template-columns: repeat(2, 1fr); } }
    .mc-card {
      background: #fff;
      display: flex; align-items: center; gap: 9px;
      padding: 9px 13px;
    }
    .mc-card-link { text-decoration:none; color:inherit; display:flex; align-items:center; gap:9px; width:100%; }
    .mc-card-link:hover .mc-card-val-link { color:#2563eb; }
    .mc-card-icon {
      width:30px; height:30px; border-radius:7px;
      display:flex; align-items:center; justify-content:center;
      font-size:.85rem; flex-shrink:0;
    }
    .mc-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .mc-card-icon.green  { background:#dcfce7; color:#15803d; }
    .mc-card-icon.orange { background:#ffedd5; color:#c2410c; }
    .mc-card-icon.purple { background:#ede9fe; color:#6d28d9; }
    .mc-card-icon.teal   { background:#ccfbf1; color:#0d9488; }
    .mc-card-icon.amber  { background:#fef3c7; color:#b45309; }
    .mc-card-icon.red    { background:#fee2e2; color:#dc2626; }
    .mc-card-label { font-size:.63rem; color:#94a3b8; margin:0 0 1px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .mc-card-val   { font-size:.82rem; font-weight:600; color:#0f172a; margin:0; }
    .mc-card-val-link { font-size:.82rem; font-weight:600; color:#2563eb; margin:0; }
    /* Celda ancho completo (dirección) */
    .mc-card-full {
      grid-column: 1 / -1;
      background: #f8fafc;
    }
    .mc-card-full .mc-card-label { display:inline; margin:0 5px 0 0; }
    .mc-card-full .mc-card-label::after { content:':'; }
    .mc-card-full .mc-card-val   { display:inline; font-weight:500; font-size:.82rem; }
    /* Datos de la venta directa (pedido para Andrea Díaz), ocultos hasta pulsar el botón */
    .vd-toggle {
      display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px;
      border:1.5px solid #6d28d9; color:#6d28d9; background:#fff; font-size:.8rem; font-weight:600; cursor:pointer; white-space:nowrap;
    }
    .vd-toggle:hover { background:#ede9fe; }
    .vd-panel {
      grid-column: 1 / -1; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px 18px;
      background:#faf5ff; border:1px solid #ddd6fe; border-radius:10px; padding:14px 16px;
    }
    .vd-panel-label { font-size:.68rem; color:#64748b; margin:0 0 3px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .vd-panel-val   { font-size:.84rem; font-weight:600; color:#0f172a; margin:0; word-break:break-word; }

    /* Status badge */
    .pc-badge        { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; }
    .pc-badge-yellow { background:#fef3c7; color:#b45309; }
    .pc-badge-green  { background:#dcfce7; color:#15803d; }
    .pc-badge-blue   { background:#dbeafe; color:#1d4ed8; }
    .pc-badge-red    { background:#fee2e2; color:#dc2626; }
    .pc-badge-purple { background:#ede9fe; color:#6d28d9; }

    /* ── ¿Quién lo pasó a X? ── */
    .pc-quien-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 8px 16px; border-radius: 9px; font-size: 12.5px; font-weight: 700;
      font-family: 'Inter', sans-serif; border: 1.5px solid #c7d2fe;
      background: #eef2ff; color: #4338ca; cursor: pointer;
      transition: background .15s, transform .1s, box-shadow .15s;
      box-shadow: 0 1px 2px rgba(67,56,202,.08);
    }
    .pc-quien-btn:hover { background: #e0e7ff; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(67,56,202,.18); }
    .pc-quien-btn:active { transform: translateY(0); }
    .pc-quien-info {
      display: flex; align-items: flex-start; gap: 10px;
      margin-top: 10px; padding: 10px 14px;
      background: #eef2ff; border-left: 3px solid #6366f1; border-radius: 0 9px 9px 0;
      font-size: 12.5px; line-height: 1.5; color: #3730a3; text-align: left;
    }
    .pc-quien-info i { font-size: 15px; margin-top: 1px; flex-shrink: 0; }

    /* ── Table ── */
    .mc-table-wrap { border-radius:10px; overflow-x:auto; box-shadow:0 2px 10px rgba(15,23,42,.09); margin-bottom:24px; }
    #pc-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    #pc-table thead th {
      background:#f8fafc; color:#374151; font-weight:600;
      padding:11px 12px; text-align:left; border:none;
      border-bottom:2px solid #e2e8f0; white-space:nowrap; font-size:.79rem;
    }
    #pc-table tbody tr              { background:#fff; }
    #pc-table tbody tr:nth-child(even) { background:#f8fafc; }
    #pc-table tbody tr:hover        { background:#eff6ff; }
    #pc-table tbody td { padding:9px 12px; border-bottom:1px solid #e2e8f0; color:#1e293b; vertical-align:middle; }
    #pc-table tbody td input[type="number"] {
      width:70px; padding:4px 8px; border:1.5px solid #d1d5db;
      border-radius:6px; font-size:.82rem; text-align:center;
      background:#f9fafb; outline:none; transition:border-color .15s;
    }
    #pc-table tbody td input[type="number"]:focus { border-color:#4361ee; background:#fff; }
    #pc-table tfoot td {
      padding:10px 12px; background:#f8fafc; color:#374151;
      font-weight:700; font-size:.83rem; border:none; border-top:2px solid #e2e8f0;
    }

    /* ── Observations ── */
    .mc-obs-wrap {
      background:#fff; border-radius:10px; padding:16px 20px;
      box-shadow:0 1px 6px rgba(15,23,42,.08); margin-bottom:20px;
    }
    .mc-obs-label {
      font-size:.78rem; font-weight:700; color:#374151;
      text-transform:uppercase; letter-spacing:.04em;
      display:flex; align-items:center; gap:6px; margin:0 0 10px;
    }
    .mc-obs-label i { color:#6366f1; }
    .mc-obs-wrap textarea {
      width:100%; border-radius:8px; border:1.5px solid #d1d5db;
      padding:10px 14px; font-size:.85rem; background:#f9fafb;
      color:#1e293b; resize:vertical; outline:none; transition:border-color .15s; min-height:120px;
    }
    .mc-obs-wrap textarea:focus { border-color:#6366f1; background:#fff; }

    /* ── Actions ── */
    .mc-actions { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:4px; padding-bottom:10px; }
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 22px; border-radius:8px; font-size:14px; font-weight:600;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; }
    .mc-btn-gray  { background:#f1f5f9; color:#475569 !important; border:1.5px solid #cbd5e1; }
    .mc-btn-teal  { background:#0d9488; color:#fff !important; }
    .mc-btn-green { background:#16a34a; color:#fff !important; }
    .mc-btn-red   { background:#dc2626; color:#fff !important; }
    .mc-btn-amber { background:#d97706; color:#fff !important; }
    .mc-btn-blue  { background:#2563eb; color:#fff !important; }
  /* Grupos del pedido: paquetes (índigo) y libros sueltos (ámbar) */
  #pc-table tbody tr.pc-grupo, #pc-table tbody tr.pc-grupo:hover { background:transparent; }
  #pc-table tbody tr.pc-grupo td { padding:14px 0 6px; border-bottom:none; }
  .pc-grupo-in { display:flex; align-items:center; flex-wrap:wrap; gap:6px 14px; padding:9px 14px; border-radius:9px; border-left:5px solid; }
  .pc-grupo-paq .pc-grupo-in { background:#eef2ff; border-color:#6366f1; color:#3730a3; }
  .pc-grupo-suelto .pc-grupo-in { background:#fffbeb; border-color:#f59e0b; color:#92400e; }
  .pc-grupo-ico { width:30px; height:30px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
  .pc-grupo-paq .pc-grupo-ico { background:#6366f1; }
  .pc-grupo-suelto .pc-grupo-ico { background:#f59e0b; }
  .pc-grupo-tit { font-weight:700; font-size:.9rem; line-height:1.15; }

  .pc-grupo-datos { font-size:.78rem; padding-left:14px; border-left:1px solid currentColor; border-left-color:rgba(0,0,0,.12); }
  .pc-grupo-in { position:sticky; left:0; max-width:calc(100vw - 340px); }
  #pc-table tbody tr.pc-fila-paq td:first-child { box-shadow:inset 4px 0 0 #a5b4fc; }
  #pc-table tbody tr.pc-fila-suelto td:first-child { box-shadow:inset 4px 0 0 #fcd34d; }
  #pc-table tbody td.pc-paq-precio { background:#f5f7ff; color:#3730a3; font-weight:700; text-align:center; border-left:1px solid #e0e7ff; white-space:nowrap; }
  #pc-table tbody td.pc-paq-precio small { display:block; font-weight:500; font-size:.7rem; color:#6366f1; margin-top:2px; white-space:normal; }
  /* Paquetes (2026-11-24): precio individual de cada libro + precio del paquete en la celda unida */
  #pc-table tbody td.pc-pp-ind { white-space:nowrap; }
  #pc-table tbody td.pc-paq-precio { vertical-align:middle; }
  #pc-table tbody td.pc-paq-precio .pc-paq-box { display:flex; flex-direction:column; align-items:center; gap:4px; }
  #pc-table tbody td.pc-paq-precio .pc-paq-lbl { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#6366f1; white-space:normal; }
  #pc-table tbody td.pc-paq-precio .pc-paq-tot { font-size:1.05rem; color:#312e81; }
  #pc-table tbody td.pc-paq-precio .pc-paq-unit { font-size:.72rem; font-weight:600; color:#6366f1; background:#e0e7ff; border-radius:999px; padding:2px 10px; }
  .pc-suelto-tag { display:inline-flex; align-items:center; gap:4px; margin-top:3px; font-size:.7rem; font-weight:600; color:#92400e; background:#fef3c7; border-radius:6px; padding:1px 7px; }
  @media print {
    .pc-grupo-in, .pc-grupo-ico, .pc-paq-tag, .pc-suelto-tag, #pc-table tbody tr.pc-fila-paq td:first-child, #pc-table tbody tr.pc-fila-suelto td:first-child { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    #pc-table tbody tr.pc-grupo { page-break-after:avoid; }
  }
  .pc-paq-tag { display:inline-flex; align-items:center; gap:4px; margin-top:3px; font-size:.7rem; font-weight:600; color:#4338ca; background:#eef2ff; border-radius:6px; padding:1px 7px; }
    /* Precio venta padre editable (venta directa, pedido Pendiente o Aprobado) */
    .pp-wrap { display:inline-flex; align-items:center; border:1.5px solid #d1d5db; border-radius:8px; background:#fff; padding:0 8px; min-width:110px; transition:border-color .15s, box-shadow .15s; }
    .pp-wrap:focus-within { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
    .pp-wrap.pp-guardando { border-color:#94a3b8; }
    .pp-wrap.pp-ok { border-color:#16a34a; }
    .pp-wrap.pp-error { border-color:#dc2626; }
    .pp-signo { color:#64748b; font-size:13px; margin-right:3px; }
    .pp-inp { border:none; outline:none; background:transparent; width:90px; padding:6px 0; font-size:13px; text-align:right; }
    .pp-inp::placeholder { color:#b45309; }
    .pp-candado { color:#94a3b8; font-size:12px; margin-left:4px; cursor:help; }
    @media print { .pp-wrap { border:none !important; box-shadow:none !important; padding:0; } .pp-candado { display:none; } }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <!-- Header -->
      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title">
              <h4>
                <i class="bi <?= $ac['icon'] ?>" style="color:<?= $ac['accent'] ?>;margin-right:6px"></i>
                Pedido <?= $ac['label'] ?>
              </h4>
            </div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="lista_pedidos.php?tp=<?= $tp ?>">Pedidos</a></li>
                <li class="breadcrumb-item active"><?= $ac['label'] ?></li>
              </ol>
            </nav>
          </div>
          <?php if ($procesado_info): ?>
          <div class="col-md-4 col-sm-12 text-md-right d-print-none">
            <button type="button" id="pc-btn-quien" class="pc-quien-btn">
              <i class="bi bi-person-check"></i> ¿Quién lo pasó a <?= htmlspecialchars($tp_estado_label[$tp]) ?>?
            </button>
            <div id="pc-quien-info" class="pc-quien-info" style="display:none;">
              <i class="bi bi-info-circle-fill"></i>
              <span>
                Pasado a <b><?= htmlspecialchars($tp_estado_label[$tp]) ?></b> por <b><?= htmlspecialchars(trim($procesado_info['nombre'])) ?></b>
                el <b><?= date('d/m/Y', strtotime($procesado_info['fecha'])) ?> a las <?= date('H:i:s', strtotime($procesado_info['fecha'])) ?></b>.
              </span>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (intval($pedido['eid'] ?? 0) == ESTADO_ANULADO) {
        crear_tabla_historial_estados($bdd);
        echo html_motivo_anulacion(obtener_historial_estado($bdd, 'pedidos', $id_pedido, ESTADO_ANULADO), 'Pedido anulado');
      } ?>

      <?php
        // Trazabilidad: documentos de World Office que enviaron más unidades de las solicitadas en
        // este pedido (o en su pedido original, si este es un pedido nuevo de backorder). Ver
        // includes/excedentes_despacho.php.
        require_once(__DIR__ . "/includes/excedentes_despacho.php");
        $excedentes_pedido = excedentes_listar($bdd, $id_pedido);
        if (!empty($excedentes_pedido)) echo excedentes_estilos() . excedentes_html($excedentes_pedido);
      ?>

      <center id="impre"></center>

      <?php if ($show_stock): ?>
      <div id="stock-alert-banner" class="d-print-none" style="display:none;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:.85rem;font-weight:600;">
        <i class="bi bi-exclamation-triangle-fill"></i> <span id="stock-alert-texto"></span>
      </div>
      <?php endif; ?>

      <!-- Tarjetas informativas -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-receipt"></i></div>
          <div>
            <p class="mc-card-label"># Pedido</p>
            <p class="mc-card-val"><?= $id_pedido ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
          <div>
            <p class="mc-card-label">Colegio</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['colegio'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-calendar2-week"></i></div>
          <div>
            <p class="mc-card-label">Calendario</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['calendario'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
          <div>
            <p class="mc-card-label">Fecha</p>
            <p class="mc-card-val"><?= date('d/m/Y H:i', strtotime($pedido['fecha'] ?? 'now')) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= $ac['badge'] === 'yellow' ? 'amber' : $ac['badge'] ?>"><i class="bi bi-flag-fill"></i></div>
          <div>
            <p class="mc-card-label">Estado</p>
            <p class="mc-card-val">
              <span class="pc-badge pc-badge-<?= $ac['badge'] ?>"><?= htmlspecialchars($pedido['estado'] ?? '') ?></span>
            </p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
          <div>
            <p class="mc-card-label">Promotor / Empresa</p>
            <p class="mc-card-val"><?= $d_empresa ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-geo-alt-fill"></i></div>
          <div>
            <p class="mc-card-label">Zona</p>
            <p class="mc-card-val"><?= $d_zona ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-person-badge"></i></div>
          <div>
            <p class="mc-card-label">Responsable</p>
            <p class="mc-card-val"><?= $d_resp ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar-check"></i></div>
          <div>
            <p class="mc-card-label">Fecha de recogida</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_r'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <p class="mc-card-label">Cliente</p>
            <p class="mc-card-val"><?= htmlspecialchars($cliente['cliente'] ?? '—') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-file-earmark-text"></i></div>
          <div>
            <p class="mc-card-label">Documento</p>
            <p class="mc-card-val"><?= (intval($pedido['fac_rem'] ?? 0) == 1) ? 'Factura' : 'Remisión' ?></p>
          </div>
        </div>
        <?php if ($show_tipo_pedido): ?>
        <div class="mc-card">
          <div class="mc-card-icon purple"><i class="bi bi-layers"></i></div>
          <div>
            <p class="mc-card-label">Tipo de pedido</p>
            <p class="mc-card-val"><?= htmlspecialchars(pedido_tipo_texto($pedido['petipo'] ?? 1)) ?></p>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($op || $op_agp): ?>
        <div class="mc-card">
          <a href="op_pendiente.php?op=<?= $op ? $n_op['id'] : $n_op_agp['op'] ?>" target="_blank" class="mc-card-link">
            <div class="mc-card-icon amber"><i class="bi bi-file-earmark-arrow-up"></i></div>
            <div>
              <p class="mc-card-label">Orden de Pedido</p>
              <p class="mc-card-val-link">
                OP #<?= $op ? ($n_op['año'].'-'.$n_op['id']) : $n_op_agp['op'] ?>
              </p>
            </div>
          </a>
        </div>
        <?php endif; ?>
        <?php if (!empty($pedido['dir_ent'])): ?>
        <div class="mc-card mc-card-full">
          <div class="mc-card-icon blue"><i class="bi bi-house-door"></i></div>
          <div>
            <p class="mc-card-label">Dirección de entrega</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['dir_ent']) ?></p>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($pedido['para_andrea'])):
          $vd_hora = trim(($pedido['vd_hora_inicio'] ? substr($pedido['vd_hora_inicio'], 0, 5) : '') . ' – ' . ($pedido['vd_hora_fin'] ? substr($pedido['vd_hora_fin'], 0, 5) : ''), ' –');
          $vd_datos = [
            'Fecha de venta'      => $pedido['vd_fecha_venta'] ? date('d/m/Y', strtotime($pedido['vd_fecha_venta'])) : '',
            'Hora de venta'       => $vd_hora,
            'Contacto del colegio' => $pedido['vd_contacto_colegio'],
            'Responsable de la venta' => trim($pedido['vd_responsable'] . ($pedido['vd_tel_responsable'] ? ' · ' . $pedido['vd_tel_responsable'] : '')),
            'Teléfonos'           => $pedido['vd_tel_contacto'],
            'Dirección / ubicación' => $pedido['vd_direccion'],
            'Observaciones de la venta' => $pedido['vd_observaciones'],
          ];
        ?>
        <!-- Datos de la venta visibles de una vez (2026-10-26). -->
        <div class="mc-card mc-card-full" style="align-items:center">
          <div class="mc-card-icon purple"><i class="bi bi-shop"></i></div>
          <div style="flex:1">
            <p class="mc-card-label">Pedido para</p>
            <p class="mc-card-val">Andrea Díaz (venta directa)</p>
          </div>
        </div>
        <div id="vd_panel" class="vd-panel">
          <?php foreach ($vd_datos as $vd_label => $vd_val): ?>
          <div><p class="vd-panel-label"><?= $vd_label ?></p><p class="vd-panel-val"><?= (string)$vd_val === '' ? '—' : nl2br(htmlspecialchars($vd_val)) ?></p></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($es_vd && empty($pedido['para_andrea']) && trim((string)$pedido['vd_responsable']) !== ''): ?>
        <div class="mc-card mc-card-full">
          <div class="mc-card-icon purple"><i class="bi bi-person-badge"></i></div>
          <div>
            <p class="mc-card-label">Responsable de la venta</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['vd_responsable']) ?><?= $pedido['vd_tel_responsable'] ? ' · ' . htmlspecialchars($pedido['vd_tel_responsable']) : '' ?></p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Form: tabla + observaciones + acciones -->
      <form method="POST" action="php/aprobar_pedido.php" id="form_pedido">
        <input type="hidden" name="vd_responsable" id="vd_responsable" value="">
        <input type="hidden" name="vd_tel_responsable" id="vd_tel_responsable" value="">
        <input type="hidden" name="id_colegio" value="<?= htmlspecialchars($_GET['id_colegio'] ?? '') ?>">
        <input type="hidden" name="periodo"    value="<?= htmlspecialchars($_GET['periodo'] ?? '') ?>">
        <input type="hidden" name="tp"         value="<?= $tp ?>">
        <input type="hidden" name="pedido"     value="<?= $id_pedido ?>">

        <div class="mc-table-wrap">
          <table id="pc-table">
            <thead>
              <tr>
                <th>#</th>
                <th class="print-col-isbn<?= $ph_isbn ?>">ISBN</th>
                <th>Título</th>
                <th>Ubicación</th>
                <th class="d-print-none">Materia</th>
                <th>Grado</th>
                <th>Precio de Venta</th>
                <th>Descuento</th>
                <th>Precio Facturación</th>
                <th><?= $col_cant_aprob ? 'Cantidad solicitada' : 'Cantidad' ?></th>
                <th>Valor Venta</th>
                <?php if ($es_vd): ?><th>Precio venta padre</th><th title="Lo que se recibiría si se venden a los padres todas las unidades del pedido: precio venta padre × cantidad (en paquetes, precio del paquete × número de paquetes).">Venta potencial a padres</th><?php endif; ?>
                <?php if ($show_plataforma): ?><th>Plataforma</th><?php endif; ?>
                <?php if ($show_stock): ?><th class="d-print-none">Existencia</th><?php endif; ?>
                <th class="print-col-descaprob<?= $ph_desc_aprob ?>">Descuento Aprobado</th>
                <th class="print-col-cantaprob<?= $ph_cant_aprob ?>">Cantidad Aprobada</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; $grupo_act = null; $paq_pintado = []; foreach ($libros as $lb):
                $k_gr = !empty($lb['id_paquete']) ? 'p' : 's';
                if ($agrupar_ped && $k_gr !== $grupo_act): $grupo_act = $k_gr; $g = $grupos_ped[$k_gr]; ?>
              <tr class="pc-grupo <?= $g['paquete'] ? 'pc-grupo-paq' : 'pc-grupo-suelto' ?>">
                <td colspan="<?= $n_cols_ped ?>">
                  <div class="pc-grupo-in">
                    <span class="pc-grupo-ico"><i class="bi <?= $g['paquete'] ? 'bi-box-seam' : 'bi-book' ?>"></i></span>
                    <span class="pc-grupo-tit"><?= $g['paquete'] ? 'En paquete' : 'Libros sueltos' ?></span>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
              <tr data-libroid="<?= $lb['libroid'] ?>" data-grupo="<?= $k_gr ?>"<?php if ($agrupar_ped): ?> class="<?= $k_gr === 's' ? 'pc-fila-suelto' : 'pc-fila-paq' ?>"<?php endif; ?>>
                <td><?= $i++ ?></td>
                <td class="print-col-isbn<?= $ph_isbn ?>"><?= htmlspecialchars($lb['isbn']) ?></td>
                <td><?= htmlspecialchars($lb['libro']) ?></td>
                <td><?= htmlspecialchars($lb['ubi']) ?></td>
                <td class="d-print-none"><?= htmlspecialchars($lb['materia']) ?></td>
                <td><?= htmlspecialchars($lb['grado']) ?></td>
                <td>$ <?= number_format($lb['precio'], 0, ',', '.') ?></td>
                <td><?= number_format($lb['desc_pct'], 0) ?> %</td>
                <td>$ <?= number_format($lb['precio_fact'], 0, ',', '.') ?></td>
                <td style="text-align:center"><?= intval($lb['cantidad']) ?></td>
                <td>$ <?= number_format($lb['v_venta'], 0, ',', '.') ?></td>
                <?php if ($es_vd && !empty($lb['id_paquete'])): $idq = (int)$lb['id_paquete'];
                  // 2026-11-24 (la usuaria): cada libro del paquete muestra su precio venta padre individual (informativo: el
                  // paquete se vende a su precio); el precio del paquete y su venta potencial van en la celda unida.
                  $pp_ind = floatval($lb['precio_venta_final']); ?>
                <td class="pc-pp-ind"><?= $pp_ind > 0 ? '$ ' . number_format($pp_ind, 0, ',', '.') : '<span style="color:#b45309">Sin precio</span>' ?></td>
                <?php if (!isset($paq_pintado[$idq])): $paq_pintado[$idq] = true; ?>
                <td rowspan="<?= $paq_filas[$idq] ?>" class="pc-paq-precio pp-valor-fijo" data-valor="<?= $paq_precio[$idq] * $paq_cant[$idq] ?>">
                  <div class="pc-paq-box">
                    <span class="pc-paq-lbl"><i class="bi bi-box-seam"></i> <?= htmlspecialchars($lb['nombre_paquete'] ?: 'Paquete') ?></span>
                    <?php /* 2026-11-24 (la usuaria): el dato grande es el precio del paquete; el cálculo va en el recuadro. */ ?>
                    <span class="pc-paq-tot"><?= $paq_precio[$idq] > 0 ? '$ ' . number_format($paq_precio[$idq], 0, ',', '.') : '<span style="color:#b45309">Sin precio</span>' ?></span>
                    <span class="pc-paq-unit"><?= $paq_cant[$idq] ?> paquete<?= $paq_cant[$idq] == 1 ? '' : 's' ?> × $ <?= number_format($paq_precio[$idq], 0, ',', '.') ?> = <strong>$ <?= number_format($paq_precio[$idq] * $paq_cant[$idq], 0, ',', '.') ?></strong></span>
                  </div>
                </td>
                <?php endif; ?>
                <?php elseif ($es_vd): ?>
                <?php if ($puede_pp && empty($lb['pp_editado_pedido'])): ?>
                <td><span class="pp-wrap"><span class="pp-signo">$</span><input type="text" inputmode="numeric" class="pp-inp" data-lpid="<?= $lb['lpid'] ?>" data-pres="<?= (int)$lb['id_presup'] ?>" data-orig="<?= (int)floatval($lb['precio_venta_final']) ?>" value="<?= floatval($lb['precio_venta_final']) > 0 ? number_format($lb['precio_venta_final'], 0, ',', '.') : '' ?>" placeholder="Sin precio" title="Se aplica a todos los pedidos de este colegio. Solo se puede modificar una vez."></span></td>
                <?php else: ?>
                <td><?= floatval($lb['precio_venta_final']) > 0 ? '$ ' . number_format($lb['precio_venta_final'], 0, ',', '.') : '<span style="color:#b45309">Sin precio</span>' ?><?php if ($puede_pp): ?> <i class="bi bi-lock-fill pp-candado" title="Ya se modificó una vez desde un pedido (<?= date('d/m/Y g:i a', strtotime($lb['pp_editado_pedido'])) ?>): no se puede volver a cambiar aquí."></i><?php endif; ?></td>
                <?php endif; ?>
                <td class="pp-valor" data-pres="<?= (int)$lb['id_presup'] ?>" data-precio="<?= (int)floatval($lb['precio_venta_final']) ?>" data-cant="<?= intval($lb['cantidad']) ?>">$ <?= number_format(floatval($lb['precio_venta_final']) * intval($lb['cantidad']), 0, ',', '.') ?></td>
                <?php endif; ?>
                <?php if ($show_plataforma): ?>
                <td style="text-align:center"><?= (intval($lb['plataforma']) == 1) ? 'Sí' : 'No' ?></td>
                <?php endif; ?>
                <?php if ($show_stock): ?><td class="pc-stock-cell d-print-none" style="text-align:center">—</td><?php endif; ?>
                <td class="print-col-descaprob<?= $ph_desc_aprob ?>" style="text-align:center">
                  <input type="number" id="d<?= $lb['lpid'] ?>" class="ap-desc-input"
                         data-lpid="<?= $lb['lpid'] ?>" value="<?= htmlspecialchars($lb['descuento_aprob']) ?>">
                </td>
                <td class="print-col-cantaprob<?= $ph_cant_aprob ?>" style="text-align:center">
                  <input type="number" id="c<?= $lb['lpid'] ?>" class="ap-qty-input"
                         data-lpid="<?= $lb['lpid'] ?>" data-solicitada="<?= intval($lb['cantidad']) ?>" value="<?= htmlspecialchars($lb['cantidad_aprob']) ?>">
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td>
                <td class="print-col-isbn<?= $ph_isbn ?>"></td>
                <td></td>
                <td></td>
                <td class="d-print-none"></td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:center;font-weight:700"><?= $total_c ?></td>
                <td style="font-weight:700">$ <?= number_format($total_v, 0, ',', '.') ?></td>
                <?php if ($es_vd): ?><td></td><td style="font-weight:700" id="total-vp">$ <?= number_format($total_vp, 0, ',', '.') ?></td><?php endif; ?>
                <?php if ($show_plataforma): ?><td></td><?php endif; ?>
                <?php if ($show_stock): ?><td class="d-print-none"></td><?php endif; ?>
                <td class="print-col-descaprob<?= $ph_desc_aprob ?>"></td>
                <td class="print-col-cantaprob<?= $ph_cant_aprob ?>" id="total-cant-aprob" style="text-align:center;font-weight:700"></td>
              </tr>
            </tfoot>
          </table>
          <!-- Hidden lib_p[] dentro del form, fuera del table -->
          <div class="d-none">
            <?php foreach ($libros as $lb): ?>
            <input type="hidden" name="lib_p[]" id="l<?= $lb['lpid'] ?>">
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Observaciones -->
        <div class="mc-obs-wrap">
          <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
          <textarea name="observaciones" id="observaciones" maxlength="300" data-contador><?= htmlspecialchars($pedido['observaciones'] ?? '') ?></textarea>
        </div>

        <!-- Acciones -->
        <div class="mc-actions d-print-none">
          <?php if (!($es_andrea && intval($pedido['eid'] ?? 0) == 1)): ?>
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <?php endif; ?>
          <?php if ($can_act): ?>
            <?php if (intval($pedido['eid'] ?? 0) == 1): ?>
              <button type="button" id="btn-aprobar" class="mc-btn mc-btn-green">
                <i class="bi bi-check-circle"></i> Aprobar
              </button>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Rechazar
              </button>
            <?php elseif (intval($pedido['eid'] ?? 0) == 2): ?>
              <?php if ($op == 0 && $op_agp == 0): ?>
              <a href="solicitar_op.php?id_pedido=<?= $id_pedido ?>" target="_blank" class="mc-btn mc-btn-amber">
                <i class="bi bi-file-earmark-plus"></i> Solicitar OP
              </a>
              <?php endif; ?>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Anular
              </button>
              <button type="button" id="procesar" class="mc-btn btn-info">
                <i class="bi bi-shuffle"></i> Procesar
              </button>
              <button type="button" id="modificar" class="mc-btn mc-btn-blue">
                <i class="bi bi-pencil"></i> Guardar cambios
              </button>
            <?php elseif (intval($pedido['eid'] ?? 0) == 5): ?>
              <?php if ($_SESSION['tipo']==1 || ($_SESSION['id']==25 || $_SESSION['id']==21) ): ?>
              <button type="button" id="rechazar" class="mc-btn mc-btn-red">
                <i class="bi bi-x-circle"></i> Anular
              </button>
              <?php endif; ?>
              <button type="button" id="facturacion" class="mc-btn btn-warning">
                <i class="bi bi-file-earmark-text"></i> Facturación
              </button>
            <?php elseif (intval($pedido['eid'] ?? 0) == 6): ?>
              <button type="button" id="despacho" class="mc-btn" style="background:#7c3aed;color:#fff;">
                <i class="bi bi-box-seam"></i> Pasar a Despacho
              </button>
            <?php elseif (intval($pedido['eid'] ?? 0) == 7): ?>
              <button type="button" id="entregar" class="mc-btn mc-btn-green">
                <i class="bi bi-truck"></i> Entregar
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </div>

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
// Total de la columna "Cantidad aprobada": libros sin cantidad aprobada cuentan con lo solicitado.
// Solo se muestra si al menos un libro tiene cantidad aprobada.
function actualizarTotalCantAprob() {
  var total = 0, alguna = false;
  $('.ap-qty-input').each(function () {
    var v = $.trim($(this).val());
    if (v !== '') alguna = true;
    total += (v !== '') ? (parseInt(v, 10) || 0) : (parseInt($(this).data('solicitada'), 10) || 0);
  });
  $('#total-cant-aprob').text(alguna ? total : '');
}
$(function () { actualizarTotalCantAprob(); });
$(document).on('input', '.ap-qty-input', actualizarTotalCantAprob);
function buildLibpVal(lpid) {
  return $('#c'+lpid).val() + '/' + lpid + '/' + $('#d'+lpid).val();
}
$(document).on('input', '.ap-qty-input, .ap-desc-input', function () {
  var lpid = $(this).data('lpid');
  $('#l'+lpid).val(buildLibpVal(lpid));
});
$('#form_pedido').on('submit', function () {
  $('.ap-qty-input').each(function () {
    var lpid = $(this).data('lpid');
    $('#l'+lpid).val(buildLibpVal(lpid));
  });
});

// Precio venta padre editable: se guarda al salir del campo o con Enter en la adopción del colegio
// (php/pedido_precio_padre.php) y aplica a todos los pedidos de ese colegio.
function ppFormato(n) { return (n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
function ppRecalcular() {
  var total = 0, porGrupo = {};
  $('#pc-table .pp-valor').each(function () {
    var $inp = $('.pp-inp[data-pres="' + $(this).data('pres') + '"]').first();
    var precio = $inp.length ? (parseInt($inp.attr('data-orig'), 10) || 0) : (parseInt($(this).attr('data-precio'), 10) || 0);
    var v = precio * (parseInt($(this).data('cant'), 10) || 0);
    $(this).text('$ ' + ppFormato(v));
    total += v;
    var gr = $(this).closest('tr').data('grupo');
    porGrupo[gr] = (porGrupo[gr] || 0) + v;
  });
  $('#pc-table .pp-valor-fijo').each(function () {
    var v = parseFloat($(this).data('valor')) || 0;
    total += v;
    porGrupo.p = (porGrupo.p || 0) + v;
  });
  $('#total-vp').text('$ ' + ppFormato(total));
  $('.pc-grupo-vp').each(function () { $(this).text('$ ' + ppFormato(porGrupo[$(this).data('grupo')] || 0)); });
}
$(document).on('input', '.pp-inp', function () {
  var d = this.value.replace(/\D/g, '');
  this.value = d ? ppFormato(parseInt(d, 10)) : '';
});
$(document).on('keydown', '.pp-inp', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
});
$(document).on('change', '.pp-inp', function () {
  var $inp = $(this), $wrap = $inp.closest('.pp-wrap');
  var precio = parseInt($inp.val().replace(/\D/g, ''), 10) || 0;
  var orig = parseInt($inp.attr('data-orig'), 10) || 0;
  if (precio === orig) return;
  if (precio <= 0) {
    inkToast('Escribe un precio venta padre mayor a cero.', 'error');
    $inp.val(orig ? ppFormato(orig) : '');
    return;
  }
  // Solo se puede modificar una vez: confirmar antes de guardar.
  // Mientras se confirma, el campo vuelve al precio actual (si se cancela, queda como estaba).
  var restaurar = function () { $inp.val(orig ? ppFormato(orig) : ''); };
  restaurar();
  var guardar = function () { $inp.val(ppFormato(precio)); ppGuardar($inp, $wrap, precio, orig); };
  var opts = { type: 'warning', title: '¿Guardar $ ' + ppFormato(precio) + ' como precio venta padre?',
    text: 'Solo se puede modificar una vez desde el pedido: después queda fijo. Aplica a todos los pedidos de este colegio.', btnOk: 'Sí, guardar' };
  if (window.inkConfirm) window.inkConfirm(opts, guardar);
  else if (confirm(opts.title + ' ' + opts.text)) guardar();
});
function ppGuardar($inp, $wrap, precio, orig) {
  $wrap.removeClass('pp-ok pp-error').addClass('pp-guardando');
  $.post('php/pedido_precio_padre.php', { lpid: $inp.data('lpid'), precio: precio }, null, 'json')
    .done(function (r) {
      $wrap.removeClass('pp-guardando');
      if (!r || !r.ok) {
        $wrap.addClass('pp-error');
        $inp.val(orig ? ppFormato(orig) : '');
        inkToast((r && r.error) || 'No se pudo guardar el precio.', 'error');
        return;
      }
      // El mismo título puede salir en varias filas: todas quedan fijas con el precio nuevo y el candado.
      $('#pc-table .pp-valor[data-pres="' + r.id_presup + '"]').attr('data-precio', r.precio);
      $('.pp-inp[data-pres="' + r.id_presup + '"]').closest('td').html('$ ' + ppFormato(r.precio)
        + ' <i class="bi bi-lock-fill pp-candado" title="Ya se modificó una vez desde un pedido: no se puede volver a cambiar aquí."></i>');
      ppRecalcular();
      inkToast('Precio venta padre guardado. Ya no se puede volver a modificar desde el pedido.', 'ok');
    })
    .fail(function () {
      $wrap.removeClass('pp-guardando').addClass('pp-error');
      $inp.val(orig ? ppFormato(orig) : '');
      inkToast('No se pudo guardar el precio. Intenta de nuevo.', 'error');
    });
}

function syncColumnasVaciasImpresion() {
  var hayIsbn = Array.from(document.querySelectorAll('#pc-table tbody td.print-col-isbn'))
    .some(function (td) { return td.textContent.trim() !== ''; });
  document.querySelectorAll('#pc-table .print-col-isbn').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayIsbn);
  });

  var hayDescAprob = Array.from(document.querySelectorAll('#pc-table .ap-desc-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#pc-table .print-col-descaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayDescAprob);
  });

  var hayCantAprob = Array.from(document.querySelectorAll('#pc-table .ap-qty-input'))
    .some(function (inp) { return parseFloat(inp.value) > 0; });
  document.querySelectorAll('#pc-table .print-col-cantaprob').forEach(function (el) {
    el.classList.toggle('d-print-none', !hayCantAprob);
  });
}

window.addEventListener('beforeprint', function () {
  syncColumnasVaciasImpresion();
  $.ajax({ url:'ajax/fecha_impre.php', type:'POST', data:'feid=<?= date("Y-m-d H:i:s") ?>/<?= $id_pedido ?>' });
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta._ph = ta.style.height;
    ta.style.setProperty('height', ta.scrollHeight + 'px', 'important');
  });
});
window.addEventListener('afterprint', function () {
  document.querySelectorAll('textarea').forEach(function (ta) {
    ta.style.height = ta._ph || '';
  });
});

$('#imprimir').on('click', function () { window.print(); });

$('#rechazar').on('click', function () {
  inkMotivoAnulacion({
    title: '¿<?= $rechazar_label ?> este pedido?',
    text:  'Esta acción no se puede deshacer.',
    label: 'Motivo <?= $rechazar_label === 'Rechazar' ? 'del rechazo' : 'de la anulación' ?>',
    btnOk: 'Sí, <?= strtolower($rechazar_label) ?>'
  }, function (motivo) {
    window.location = 'php/accion_pedidos.php?rechazar=<?= intval($id_pedido) ?>&motivo=' + encodeURIComponent(motivo);
  });
});
$('#entregar').on('click', function () {
  window.location = 'php/accion_pedidos.php?entregado=<?= $id_pedido ?>';
});
$('#procesar').on('click', function () {
  // OP obligatoria para procesar (2026-11-24); el servidor también lo valida (php/accion_pedidos.php).
  <?php if ($op == 0 && $op_agp == 0): ?>
  if (window.inkToast) inkToast('Este pedido no tiene OP. Solicita la OP antes de pasarlo a Procesando.', 'error'); else alert('Este pedido no tiene OP. Solicita la OP antes de pasarlo a Procesando.');
  return;
  <?php endif; ?>
  window.location = 'php/accion_pedidos.php?procesar=<?= $id_pedido ?>';
});
$('#facturacion').on('click', function () {
  window.location = 'php/accion_pedidos.php?facturacion=<?= $id_pedido ?>';
});
$('#despacho').on('click', function () {
  window.location = 'php/accion_pedidos.php?despacho=<?= $id_pedido ?>';
});
$('#pc-btn-quien').on('click', function () {
  $('#pc-quien-info').toggle();
});
$('#modificar').on('click', function () {
  $('#form_pedido').attr('action', 'php/mod_pedido.php').submit();
});
<?php if ($show_stock): ?>
window.stockCheckPromise = $.ajax({
  url: 'ajax/stock_bajo_pedidos.php',
  type: 'POST',
  data: { origen: 'pedidos', ids: [<?= $id_pedido ?>] },
  dataType: 'json'
}).done(function (data) {
  var libros = data[<?= $id_pedido ?>];
  if (!libros || !libros.length) {
    $('#pc-table .pc-stock-cell').html('<span style="color:#94a3b8;font-size:12px">OK</span>');
    return;
  }
  window.hayStockBajo = true;
  var porLibro = {};
  libros.forEach(function (l) { porLibro[l.id_libro] = l.existencia; });
  $('#pc-table tbody tr').each(function () {
    var $tr = $(this);
    var id  = $tr.data('libroid');
    var $celda = $tr.find('.pc-stock-cell');
    if (porLibro.hasOwnProperty(id)) {
      $celda.html('<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:#fee2e2;color:#dc2626;">' +
        '<i class="bi bi-exclamation-triangle-fill"></i> ' + porLibro[id] + ' unid.</span>');
    } else {
      $celda.html('<span style="color:#94a3b8;font-size:12px">OK</span>');
    }
  });
  var texto = libros.length === 1
    ? '1 libro de este pedido tiene existencia baja en la bodega General: '
    : libros.length + ' libros de este pedido tienen existencia baja en la bodega General: ';
  texto += libros.map(function (l) { return l.libro + ' (' + l.existencia + ' unid.)'; }).join(', ');
  $('#stock-alert-texto').text(texto);
  $('#stock-alert-banner').show();
}).fail(function () {
  $('#pc-table .pc-stock-cell').html('<span style="color:#94a3b8;font-size:12px" title="No se pudo verificar">—</span>');
});
<?php endif; ?>

// Pedido para Andrea Díaz: 1) confirmar que se aprueba con pocas o nulas existencias; 2) preguntar si ya
// tiene responsable de la venta (Sí → escribir el nombre; No → se aprueba igual).
var esAndrea = <?= $es_andrea ? 'true' : 'false' ?>;
// Canal "Venta directa - Eureka" (2026-11-13): también se pregunta por el responsable de la venta (nombre y teléfono).
var esVentaDirecta = <?= $es_vd ? 'true' : 'false' ?>;
function modalResponsableVenta() {
  var $ov = $('<div class="ink-overlay"><div class="ink-modal" style="width:380px;">'
    + '<div class="ink-modal-icon info"><i class="bi bi-person-badge"></i></div>'
    + '<p class="ink-modal-title">¿Ya tiene responsable de la venta?</p>'
    + '<div id="rv-campo" style="display:none;margin:0 0 18px;">'
    +   '<input type="text" id="rv-nombre" maxlength="150" placeholder="Nombre del responsable de la venta" '
    +     'style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:.875rem;">'
    +   '<input type="tel" id="rv-tel" maxlength="40" placeholder="Teléfono del responsable" '
    +     'style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:.875rem;margin-top:8px;">'
    +   '<p id="rv-error" style="display:none;color:#dc2626;font-size:.78rem;margin:6px 0 0;"></p>'
    + '</div>'
    + '<p class="ink-modal-text" id="rv-texto">Si ya lo tiene, escribe su nombre y teléfono. Si no, el pedido se aprueba igual.</p>'
    + '<div class="ink-modal-btns" id="rv-btns">'
    +   '<button type="button" class="ink-btn-cancel" id="rv-no">No</button>'
    +   '<button type="button" class="ink-btn-info" id="rv-si">Sí</button>'
    + '</div>'
    + '<div class="ink-modal-btns" id="rv-btns2" style="display:none">'
    +   '<button type="button" class="ink-btn-cancel" id="rv-volver">Volver</button>'
    +   '<button type="button" class="ink-btn-success" id="rv-ok">Aprobar</button>'
    + '</div></div></div>');
  $('body').append($ov);
  $ov.on('click', function (e) { if (e.target === this) $ov.remove(); });
  $ov.find('#rv-no').on('click', function () {
    $('#vd_responsable, #vd_tel_responsable').val('');
    $ov.remove();
    $('#form_pedido').trigger('submit');
  });
  $ov.find('#rv-si').on('click', function () {
    $ov.find('#rv-campo, #rv-btns2').show();
    $ov.find('#rv-btns, #rv-texto').hide();
    $ov.find('#rv-nombre').focus();
  });
  $ov.find('#rv-volver').on('click', function () {
    $ov.find('#rv-campo, #rv-btns2, #rv-error').hide();
    $ov.find('#rv-btns, #rv-texto').show();
  });
  $ov.find('#rv-ok').on('click', function () {
    var nombre = $.trim($ov.find('#rv-nombre').val()), tel = $.trim($ov.find('#rv-tel').val());
    if (!nombre) { $ov.find('#rv-error').text('Escribe el nombre del responsable.').show(); $ov.find('#rv-nombre').focus(); return; }
    var digitos = tel.replace(/\D/g, '');
    if (!/^[0-9+\s().-]+$/.test(tel) || digitos.length < 7 || digitos.length > 15) {
      $ov.find('#rv-error').text('Escribe el teléfono del responsable (solo números, mínimo 7 dígitos).').show(); $ov.find('#rv-tel').focus(); return;
    }
    $('#vd_responsable').val(nombre);
    $('#vd_tel_responsable').val(tel);
    $ov.remove();
    $('#form_pedido').trigger('submit');
  });
  $ov.find('#rv-nombre, #rv-tel').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $ov.find('#rv-ok').click(); } });
}

$('#btn-aprobar').on('click', function () {
  function procederAprobar() {
    if (esAndrea) {
      inkConfirm({
        title: '¿Aprobar con pocas o nulas existencias?',
        text:  window.hayStockBajo
          ? 'Hay libros de este pedido con existencia baja o en cero en la bodega General. ¿Estás seguro de aprobarlo?'
          : 'Este pedido es para venta directa de Andrea Díaz. ¿Estás seguro de aprobarlo aunque haya pocas o nulas existencias?',
        type:  'warning',
        btnOk: 'Sí, continuar'
      }, modalResponsableVenta);
      return;
    }
    var enviar = esVentaDirecta ? modalResponsableVenta : function () { $('#form_pedido').trigger('submit'); };
    if (window.hayStockBajo) {
      inkConfirm({
        title: '¿Aprobar este pedido?',
        text:  'Hay libros de este pedido con existencia baja en la bodega General (menos de 50 unidades). ¿Deseas aprobarlo de todas formas?',
        type:  'warning',
        btnOk: 'Sí, aprobar'
      }, enviar);
    } else {
      enviar();
    }
  }
  if (window.stockCheckPromise) {
    window.stockCheckPromise.always(procederAprobar);
  } else {
    procederAprobar();
  }
});
</script>
<script src="src/ink-alerts.js"></script>
<script src="src/contador-caracteres.js"></script>
<script src="src/motivo-anulacion.js"></script>
</body>
</html>