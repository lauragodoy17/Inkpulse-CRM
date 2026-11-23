<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$id_pedido = intval($_GET['id_pedido']);

$req_base = $bdd->prepare("SELECT id FROM devoluciones_v WHERE id='".$id_pedido."'");
$req_base->execute();
$base = $req_base->fetch();

$sql_ped = "SELECT pe.fecha, pe.tipo as petipo, pe.observaciones, pe.cliente,
                   z.codigo as codzona, z.zona, c.id as cid, c.colegio, c.sub_zona, c.responsable,
                   cal.calendario,
                   u.nombres, u.apellidos, u.tipo, e.id as eid, e.estado
            FROM devoluciones_v pe
            JOIN colegios c ON pe.id_colegio=c.id
            JOIN zonas z ON z.codigo=c.cod_zona
            JOIN usuarios u ON u.cod_zona=z.codigo
            JOIN estados_dev e ON e.id=pe.estado
            LEFT JOIN calendarios cal ON c.id_calendario=cal.id
            WHERE pe.id='".$base['id']."' AND id_colegio > 0";
$req_ped = $bdd->prepare($sql_ped);
$req_ped->execute();
$pedido  = $req_ped->fetch();
$n_cole  = ($pedido !== false) ? 1 : 0;

if ($n_cole > 0) {
    $sql_libros = "SELECT pe.id, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn,
                          m.materia, lp.cantidad, p.cod_area, p.descuento, p.descuento_d,
                          p.tasa_compra_d, lp.cod_pedido, lp.id as lpid
                   FROM devoluciones_v pe
                   LEFT JOIN libros_devol_v lp ON lp.cod_pedido=pe.codigo
                   LEFT JOIN libros l ON l.id=lp.id_libro
                   LEFT JOIN materias m ON l.id_materia=m.id
                   -- Adopción del libro en el colegio y período (descuento y área → grado). Con área en la línea, la de esa
                   -- área (como siempre). Sin área (Devoluciones de ventas, desde 2026-10-26, guarda cod_area = ''): primero
                   -- una adopción sin área, como antes; si no hay, la adopción definida del libro (2026-11-11).
                   LEFT JOIN presupuestos p ON p.id = (
                     SELECT p2.id FROM presupuestos p2
                     WHERE p2.id_colegio = pe.id_colegio AND p2.id_periodo = pe.id_periodo AND p2.id_libro = lp.id_libro
                       AND (p2.cod_area = lp.cod_area OR COALESCE(lp.cod_area, '') = '')
                     ORDER BY (p2.cod_area = lp.cod_area) DESC, p2.definido DESC, p2.id LIMIT 1)
                   WHERE pe.id='".$id_pedido."'
                   GROUP BY l.id, p.cod_area";
    $req_cli = $bdd->prepare("SELECT cliente FROM clientes WHERE id='".$pedido['cliente']."'");
    $req_cli->execute();
    $cliente      = $req_cli->fetch();
    $observaciones = null;
} else {
    $sql_libros = "SELECT pe.id, l.id as libroid, l.id_grado, l.libro, l.precio, l.isbn,
                          m.materia, lp.cantidad, lp.id as lpid, '' as cod_area,
                          0 as descuento, '0.0000' as descuento_d, 0 as tasa_compra_d
                   FROM devoluciones_v pe
                   JOIN libros_devol_v lp ON lp.cod_pedido=pe.codigo
                   JOIN libros l ON l.id=lp.id_libro
                   JOIN materias m ON l.id_materia=m.id
                   WHERE pe.id='".$id_pedido."'";
    $req_cli = $bdd->prepare("SELECT c.cliente FROM clientes c JOIN devoluciones_v d ON c.id=d.cliente WHERE d.id='".$id_pedido."'");
    $req_cli->execute();
    $cliente = $req_cli->fetch();
    $req_obs = $bdd->prepare("SELECT estado, observaciones FROM devoluciones_v WHERE id='".$id_pedido."'");
    $req_obs->execute();
    $observaciones = $req_obs->fetch();
}

$req_libros = $bdd->prepare($sql_libros);
$req_libros->execute();
$libros = $req_libros->fetchAll();

$req_op = $bdd->prepare("SELECT id, estado FROM ordenes_pedidos WHERE id_devol_v='".$id_pedido."' AND estado!=4");
$req_op->execute();
$op   = $req_op->rowCount();
$n_op = $req_op->fetch();

$is_admin = ($_SESSION['tipo'] == 1 || $_SESSION['tipo'] == 2);

// Devolución registrada desde "Devolución de venta directa": consolidado de pedidos (devoluciones_v.ids_pedidos)
// o, las anteriores, de una solicitud de venta directa (id_pedido_vd). Trazabilidad.
require_once(__DIR__ . "/includes/devoluciones_vd_datos.php");
dvd_asegurar_columnas($bdd);
$req_vd = $bdd->prepare("SELECT id_pedido_vd, ids_pedidos, op_vd, ops_pedidos, documentos_wo, cierre FROM devoluciones_v WHERE id = ? AND (id_pedido_vd IS NOT NULL OR ids_pedidos IS NOT NULL)");
$req_vd->execute([$id_pedido]);
$vd_devol = $req_vd->fetch();
$vd_saldo = $vd_devol ? dvd_saldo_de_devolucion($bdd, $id_pedido) : null;
// Devolución de un formato de venta directa (2026-10-22): se recibe en el paso 5 del formato, con firma.
require_once(__DIR__ . "/includes/formatos_vd_datos.php");
fvd_asegurar_tablas($bdd);
$vd_formato = $vd_devol ? fvd_formato_de_devolucion($bdd, $id_pedido) : null;
// Devolución registrada desde "Devolución de ventas" (devoluciones_ventas.php): pedidos de venta + documentos WO.
require_once(__DIR__ . "/includes/devoluciones_ventas_datos.php");
dvv_asegurar_columnas($bdd);
$req_dv = $bdd->prepare("SELECT ids_pedidos_venta, ops_pedidos, documentos_wo, tipo_devolucion FROM devoluciones_v WHERE id = ?");
$req_dv->execute([$id_pedido]);
$dv_devol = $req_dv->fetch();
$dv_tipo = dvv_etiqueta_tipo($dv_devol['tipo_devolucion'] ?? null); // De corte / Física
if ($dv_devol && $dv_devol['ids_pedidos_venta'] === null) $dv_devol = false;

// Venta directa (2026-11-11): con el desglose guardado (devoluciones_v_vd) la tabla se separa como en el pedido en
// "En paquete" (cada paquete devuelto = una unidad de cada libro del paquete) y "Libros sueltos" (sueltos del pedido y
// libros sueltos de paquetes abiertos). Si el desglose no cuadra con libros_devol_v, se muestra la lista de siempre.
$filas_dev = [];
foreach ($libros as $lb_d) $filas_dev[] = ['libro' => $lb_d, 'cantidad' => (int)$lb_d['cantidad'], 'grupo' => null, 'tag' => '', 'paq' => null];
$grupos_dev = [];
$desglose_dev = dvv_desglose_vd($bdd, $id_pedido);
if ($desglose_dev) {
    $por_libro_dev = [];
    foreach ($libros as $lb_d) if ($lb_d['libroid'] && !isset($por_libro_dev[(int)$lb_d['libroid']])) $por_libro_dev[(int)$lb_d['libroid']] = $lb_d;
    $ids_paq_dev = array_values(array_unique(array_map(fn($x) => (int)$x['id_paquete'], array_filter($desglose_dev, fn($x) => in_array($x['tipo'], ['paquete', 'abierto'], true)))));
    $paq_dev = []; // id_paquete => ['paquete','libros' => [id_libro]]
    if ($ids_paq_dev) {
        foreach ($bdd->query("SELECT pq.id, pq.paquete, pq.id_grado, pl.id_libro FROM paquetes_colegio pq JOIN paquetes_colegio_libros pl ON pl.id_paquete = pq.id
                              WHERE pq.id IN (" . implode(',', $ids_paq_dev) . ") ORDER BY pq.id, pl.id") as $r) {
            $paq_dev[(int)$r['id']]['paquete'] = $r['paquete'];
            $paq_dev[(int)$r['id']]['id_grado'] = (int)$r['id_grado'];
            $paq_dev[(int)$r['id']]['libros'][] = (int)$r['id_libro'];
        }
    }
    $nombres_paq = function ($idLibro) use ($paq_dev) {
        return implode(', ', array_map(fn($p) => $p['paquete'], array_filter($paq_dev, fn($p) => in_array($idLibro, $p['libros'], true))));
    };
    $nuevas = [];
    $usado = [];
    $cuadra = true;
    foreach ($desglose_dev as $x) {
        $n = (int)$x['cantidad'];
        if ($x['tipo'] === 'abierto') continue; // paquete abierto (2026-11-13): sus libros devueltos vienen como 'suelto_paq'
        if ($x['tipo'] === 'paquete') {
            $p = $paq_dev[(int)$x['id_paquete']] ?? null;
            if (!$p) { $cuadra = false; break; }
            $grupos_dev['p']['paquetes'] = ($grupos_dev['p']['paquetes'] ?? 0) + $n;
            foreach (array_unique($p['libros']) as $l) {
                if (!isset($por_libro_dev[$l])) { $cuadra = false; break 2; }
                $nuevas[] = ['libro' => $por_libro_dev[$l], 'cantidad' => $n, 'grupo' => 'p', 'tag' => '', 'paq' => (int)$x['id_paquete']];
                $usado[$l] = ($usado[$l] ?? 0) + $n;
            }
        } else {
            $l = (int)$x['id_libro'];
            if (!isset($por_libro_dev[$l])) { $cuadra = false; break; }
            $nuevas[] = ['libro' => $por_libro_dev[$l], 'cantidad' => $n, 'grupo' => 's',
                         'tag' => $x['tipo'] === 'suelto_paq' ? 'Suelto de paquete abierto' . (($np = !empty($x['id_paquete']) ? ($paq_dev[(int)$x['id_paquete']]['paquete'] ?? '') : $nombres_paq($l)) !== '' ? ': ' . $np : '') : '', 'paq' => null];
            $usado[$l] = ($usado[$l] ?? 0) + $n;
        }
    }
    foreach ($por_libro_dev as $l => $lb_d) if (($usado[$l] ?? 0) !== (int)$lb_d['cantidad']) $cuadra = false;
    if ($cuadra && $nuevas) {
        // Paquetes primero (en el orden del desglose), luego los sueltos.
        usort($nuevas, fn($a, $b) => ($a['grupo'] === 'p' ? 0 : 1) <=> ($b['grupo'] === 'p' ? 0 : 1));
        $filas_dev = $nuevas;
        foreach ($filas_dev as $fd) {
            $grupos_dev[$fd['grupo']]['titulos'] = ($grupos_dev[$fd['grupo']]['titulos'] ?? 0) + 1;
            $grupos_dev[$fd['grupo']]['unidades'] = ($grupos_dev[$fd['grupo']]['unidades'] ?? 0) + $fd['cantidad'];
        }
    } else {
        $grupos_dev = [];
    }
}
$agrupar_dev = isset($grupos_dev['p']);

// Grado de la ADOPCIÓN (2026-11-11, la usuaria), como en pedido_colegio.php: el del área objetiva de la
// adopción del colegio en el período; sin área, el del libro. Las devoluciones de venta guardan el libro
// sin cod_area (libros_devol_v.cod_area = ''), así que el área se busca en la adopción.
// Libro devuelto dentro de un paquete: el grado del paquete (un libro puede estar adoptado en dos grados).
// Se calcula antes de dibujar la tabla para poder ordenar por curso.
$nombres_grado_dev = [];
foreach ($bdd->query("SELECT id, grado FROM grados") as $g_dev_n) $nombres_grado_dev[(int)$g_dev_n['id']] = $g_dev_n['grado'];
$st_area_adop = $bdd->prepare("SELECT p.cod_area FROM presupuestos p JOIN devoluciones_v d ON d.id_colegio = p.id_colegio AND d.id_periodo = p.id_periodo
                               WHERE d.id = ? AND p.id_libro = ? AND COALESCE(p.cod_area, '') <> ''
                               ORDER BY p.definido DESC, p.id LIMIT 1");
$st_grado_otro = $bdd->prepare("SELECT id_grado_otro, id_materia FROM areas_objetivas WHERE codigo = ? LIMIT 1");
// Plan lector (2026-11-23): materia del libro o del área objetiva de la adopción (includes/plan_lector.php).
require_once(__DIR__ . "/includes/plan_lector.php");
$st_mat_lib = $bdd->prepare("SELECT id_materia FROM libros WHERE id = ?");
foreach ($filas_dev as &$fd_g) {
    $lb_g = $fd_g['libro'];
    $grado_paq = $fd_g['paq'] ? (int)($paq_dev[$fd_g['paq']]['id_grado'] ?? 0) : 0;
    $cod_area_g = (string)($lb_g['cod_area'] ?? '');
    $mat_area_g = 0;
    if ($grado_paq) {
        $id_g = $grado_paq;
    } else {
        if ($cod_area_g === '' && $lb_g['libroid']) {
            $st_area_adop->execute([(int)$id_pedido, (int)$lb_g['libroid']]);
            $cod_area_g = (string)$st_area_adop->fetchColumn();
        }
        if ($cod_area_g === '') {
            $id_g = (int)$lb_g['id_grado'];
        } else {
            $st_grado_otro->execute([$cod_area_g]);
            $ao_g = $st_grado_otro->fetch(PDO::FETCH_ASSOC) ?: [];
            $id_g = (int)($ao_g['id_grado_otro'] ?? 0);
            $mat_area_g = (int)($ao_g['id_materia'] ?? 0);
        }
    }
    $st_mat_lib->execute([(int)$lb_g['libroid']]);
    $fd_g['plan_lector'] = !$grado_paq && ((int)$st_mat_lib->fetchColumn() === MATERIA_PLAN_LECTOR || $mat_area_g === MATERIA_PLAN_LECTOR);
    $fd_g['id_grado'] = $id_g;
    $fd_g['grado'] = $nombres_grado_dev[$id_g] ?? '—';
}
unset($fd_g);
// Orden (2026-11-20, la usuaria): con paquetes, primero "Devuelto en paquete" y luego "Libros sueltos"; dentro de cada
// grupo por curso (grados.id va de Pre jardín a 11; sin grado al final) y dentro del curso alfabético por título.
require_once(__DIR__ . "/includes/orden_texto.php"); // alfabético sin distinguir tildes
// 2026-11-23: dentro de cada grupo por curso; en cada curso los libros particulares alfabético y luego el plan lector alfabético.
usort($filas_dev, fn($a, $b) => (($a['grupo'] === 's' ? 1 : 0) <=> ($b['grupo'] === 's' ? 1 : 0))
                            ?: plan_lector_comparar(['plan' => $a['plan_lector'], 'grado' => $a['id_grado'], 'titulo' => $a['libro']['libro'] ?? ''],
                                                    ['plan' => $b['plan_lector'], 'grado' => $b['id_grado'], 'titulo' => $b['libro']['libro'] ?? '']));

$total_venta    = array_sum(array_map(function($l) use ($libros) {
    $desc = ($l['descuento_d'] === '0.0000' || $l['descuento_d'] == 0) ? $l['descuento'] : $l['descuento_d'];
    return ($l['precio'] - ($l['precio'] * $desc)) * $l['cantidad'];
}, $libros));
$total_cantidad = array_sum(array_column($libros, 'cantidad'));

// Estado display
$eid = intval($pedido['eid'] ?? 0);
$obs_estado = intval($observaciones['estado'] ?? 0);

if (isset($n_op['estado']) && $n_op['estado'] == 2) {
    $estado_display = 'Atendida';
    $estado_cls     = 'vd-badge-green';
} elseif ($eid == 1) {
    $estado_display = $pedido['estado'];
    $estado_cls     = 'vd-badge-yellow';
} elseif ($eid == 2) {
    $estado_display = $pedido['estado'];
    $estado_cls     = 'vd-badge-blue';
} elseif ($eid == 3) {
    $estado_display = $pedido['estado'] ?? ($observaciones['estado'] ?? '');
    $estado_cls     = 'vd-badge-red';
} else {
    $estado_display = $pedido['estado'] ?? '';
    $estado_cls     = 'vd-badge-green';
}

// Zona / empresa / responsable
$empresa    = '';
$zona_label = '';
$responsable = '';
if ($n_cole > 0) {
    if (($pedido['tipo'] ?? 0) == 3) {
        [$empresa, $zona_label] = array_pad(explode('/', $pedido['zona'] ?? '/'), 2, '');
        $responsable = trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? ''));
    } else {
        $req_sz = $bdd->prepare("SELECT sub_zona FROM sub_zonas WHERE id='".$pedido['sub_zona']."'");
        $req_sz->execute();
        $sub_zona    = $req_sz->fetch();
        $empresa     = trim(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? ''));
        $zona_label  = $sub_zona['sub_zona'] ?? '';
        $responsable = $pedido['responsable'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Devolución de venta</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    @media print {
      .mc-actions, .breadcrumb, .d-print-none, .left-side-bar, .header { display: none !important; }
      a[href]:after { content: none !important; }
      body { font-size: 9px; }
      #dc-table { width: 100% !important; table-layout: auto !important; }
      #dc-table th, #dc-table td { display: table-cell !important; }
      #dc-table thead th { padding: 6px 5px !important; font-size: .72rem !important; }
      #dc-table tbody td, #dc-table tfoot td { padding: 5px !important; font-size: .72rem !important; }
    }

    /* Info cards (legacy — usado por JS print) */
    .vd-info-row  { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
    .vd-info-card {
      background:#fff; border:1px solid #e2e8f0; border-radius:10px;
      padding:12px 18px; flex:1 1 160px; min-width:140px;
      box-shadow:0 1px 3px rgba(15,23,42,.05);
    }
    .vd-ic-label { display:block; font-size:.7rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
    .vd-ic-value { display:block; font-size:.9rem; font-weight:600; color:#0f172a; }

    /* Info table */
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
    .mc-card-icon {
      width: 30px; height: 30px; border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; flex-shrink: 0;
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

    /* Badges */
    .vd-badge-yellow { display:inline-block; background:#fef3c7; color:#92400e; border-radius:20px; padding:3px 12px; font-size:12px; font-weight:600; }
    .vd-badge-green  { display:inline-block; background:#dcfce7; color:#15803d; border-radius:20px; padding:3px 12px; font-size:12px; font-weight:600; }
    .vd-badge-blue   { display:inline-block; background:#dbeafe; color:#1d4ed8; border-radius:20px; padding:3px 12px; font-size:12px; font-weight:600; }
    .vd-badge-red    { display:inline-block; background:#fee2e2; color:#dc2626; border-radius:20px; padding:3px 12px; font-size:12px; font-weight:600; }

    /* Table */
    .lm-count-badge { font-size:12px; color:#64748b; background:#f1f5f9; border-radius:20px; padding:3px 10px; font-weight:500; }
    #dc-table thead th {
      background: #1e40af !important; color: #fff !important;
      font-weight: 600; font-size: .80rem;
      padding: 11px 12px; white-space: nowrap; border: none;
    }
    #dc-table tbody tr:nth-child(even) td { background: #eff6ff; }
    #dc-table tbody tr:hover td           { background: #dbeafe !important; }
    #dc-table tbody tr                    { border-left: 3px solid transparent; transition: border-color .15s; }
    #dc-table tbody tr:hover              { border-left-color: #1d4ed8; }
    #dc-table tfoot td { padding:10px 12px; background:#f8fafc; color:#374151; font-weight:700; font-size:.83rem; }
    /* Venta directa: grupos "Devuelto en paquete" (índigo) y "Libros sueltos" (ámbar), como en pedido_colegio.php */
    #dc-table tbody tr.dc-grupo td, #dc-table tbody tr.dc-grupo:hover td { background:transparent !important; padding:14px 0 6px; border-bottom:none; }
    #dc-table tbody tr.dc-grupo, #dc-table tbody tr.dc-grupo:hover { border-left-color:transparent; }
    .dc-grupo-in { display:flex; align-items:center; flex-wrap:wrap; gap:6px 14px; padding:9px 14px; border-radius:9px; border-left:5px solid; }
    .dc-grupo-paq .dc-grupo-in { background:#eef2ff; border-color:#6366f1; color:#3730a3; }
    .dc-grupo-suelto .dc-grupo-in { background:#fffbeb; border-color:#f59e0b; color:#92400e; }
    .dc-grupo-ico { width:30px; height:30px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
    .dc-grupo-paq .dc-grupo-ico { background:#6366f1; }
    .dc-grupo-suelto .dc-grupo-ico { background:#f59e0b; }
    .dc-grupo-tit { font-weight:700; font-size:.9rem; line-height:1.15; }
    .dc-grupo-datos { font-size:.78rem; padding-left:14px; border-left:1px solid rgba(0,0,0,.12); }
    #dc-table tbody tr.dc-fila-paq td:first-child { box-shadow:inset 4px 0 0 #a5b4fc; }
    #dc-table tbody tr.dc-fila-suelto td:first-child { box-shadow:inset 4px 0 0 #fcd34d; }
    .dc-paq-tag, .dc-suelto-tag { display:inline-flex; align-items:center; gap:4px; margin-top:3px; font-size:.7rem; font-weight:600; border-radius:6px; padding:1px 7px; }
    .dc-paq-tag { color:#4338ca; background:#eef2ff; }
    .dc-suelto-tag { color:#92400e; background:#fef3c7; }
    @media print {
      .dc-grupo-in, .dc-grupo-ico, .dc-paq-tag, .dc-suelto-tag, #dc-table tbody tr.dc-fila-paq td:first-child, #dc-table tbody tr.dc-fila-suelto td:first-child { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      #dc-table tbody tr.dc-grupo { page-break-after:avoid; }
    }

    /* Buttons */
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:10px 22px; border-radius:8px; font-size:.9rem; font-weight:700;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); color:#fff; text-decoration:none; }
    .mc-btn-red   { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; }
    .mc-btn-green { background:linear-gradient(135deg,#15803d,#16a34a); color:#fff; }
    .mc-btn-amber { background:linear-gradient(135deg,#d97706,#f59e0b); color:#fff; }
    .mc-btn-blue  { background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; }
    .mc-btn-teal  { background:linear-gradient(135deg,#0f766e,#0d9488); color:#fff; }

    /* Print sigs */
    .vd-print-sigs { display:none; }
    @media print {
      .vd-print-sigs { display:flex; justify-content:space-between; margin-top:40px; }
      #dc-table thead, #dc-table tfoot { display: table-row-group !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
      textarea { overflow:visible !important; white-space:pre-wrap !important; }
    }
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
            <div class="title"><h4>Devolución de venta</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <?php if ($vd_devol): ?>
                <li class="breadcrumb-item"><a href="ver_devol_ventas.php">Devoluciones de venta</a></li>
                <?php else: ?>
                <li class="breadcrumb-item"><a href="ver_devol_ventas.php">Devoluciones de venta</a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active"># <?= $id_pedido ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <?php if ($eid == 3) {
        require_once(__DIR__ . "/includes/historial_estados.php");
        crear_tabla_historial_estados($bdd);
        echo html_motivo_anulacion(obtener_historial_estado($bdd, 'devoluciones_v', $id_pedido, ESTADO_ANULADO), 'Devolución anulada');
      } ?>

      <?php if ($dv_devol): ?>
      <!-- Trazabilidad de la devolución de ventas (includes/devoluciones_ventas_datos.php) -->
      <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.85rem;">
        <i class="bi bi-arrow-return-left"></i> Devolución <b><?= htmlspecialchars(mb_strtolower($dv_tipo)) ?></b> de los pedidos
        <?php foreach (explode(',', $dv_devol['ids_pedidos_venta']) as $i_dv => $clave_dv): if (!dvd_clave_valida($clave_dv)) continue; $id_dv = (int)substr($clave_dv, 1); ?>
          <?= $i_dv ? ',' : '' ?> <a href="pedido_colegio.php?id_pedido=<?= $id_dv ?>&tp=4" target="_blank"><b><?= htmlspecialchars(dvd_etiqueta($clave_dv)) ?></b></a>
        <?php endforeach; ?>
        · OP <b><?= htmlspecialchars($dv_devol['ops_pedidos'] ?: '—') ?></b>
        · Documento(s) WO <b><?= htmlspecialchars($dv_devol['documentos_wo'] ?: 'Solicitud CRM') ?></b>
      </div>
      <?php endif; ?>

      <?php if ($vd_devol): ?>
      <!-- Trazabilidad de la devolución de venta directa (includes/devoluciones_vd_datos.php) -->
      <div style="background:#f0fdfa;border:1px solid #99f6e4;color:#134e4a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.85rem;">
        <i class="bi bi-bag-check"></i> Legalización venta directa
        <?php if ($vd_devol['ids_pedidos']): ?>
          de los pedidos
          <?php foreach (explode(',', $vd_devol['ids_pedidos']) as $i_vd => $clave_vd): if (!dvd_clave_valida($clave_vd)) continue; $id_vd = (int)substr($clave_vd, 1); ?>
            <?= $i_vd ? ',' : '' ?> <a href="pedido_colegio.php?id_pedido=<?= $id_vd ?>" target="_blank"><b><?= htmlspecialchars(dvd_etiqueta($clave_vd)) ?></b></a>
          <?php endforeach; ?>
        <?php else: ?>
          VD-<?= (int)$vd_devol['id_pedido_vd'] ?>
        <?php endif; ?>
        · OP <b><?= htmlspecialchars(($vd_devol['ops_pedidos'] ?: $vd_devol['op_vd']) ?: '—') ?></b>
        · Documento(s) WO <b><?= htmlspecialchars($vd_devol['documentos_wo'] ?: '—') ?></b>
        <?php if ($vd_saldo): ?>
        · Saldo <a href="ver_saldo_devolucion.php?id=<?= (int)$vd_saldo['id'] ?>"><b><?= htmlspecialchars($vd_saldo['numero']) ?></b></a> (<?= (int)$vd_saldo['unidades'] ?> uds. · $ <?= number_format($vd_saldo['total'], 0, ',', '.') ?>)
        <?php endif; ?>
        <?php if ($vd_formato): ?>
        · Sale del <a href="formato_venta_directa.php?id=<?= (int)$vd_formato['id'] ?>"><b><?= htmlspecialchars(fvd_numero($vd_formato['consecutivo'])) ?></b></a>
          (<a href="ver_formato_venta_directa.php?id=<?= (int)$vd_formato['id'] ?>&acta=recepcion" target="_blank">acta de recepción</a>): cada paquete devuelto aparece abajo como una unidad de cada uno de sus libros.
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Info cards -->
      <div class="mc-cards">
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-arrow-return-left"></i></div>
          <div>
            <p class="mc-card-label">Devolución de venta</p>
            <p class="mc-card-val"># <?= $id_pedido ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
          <div>
            <p class="mc-card-label">Fecha</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['fecha'] ?? '') ?></p>
          </div>
        </div>
        <?php if ($n_cole > 0): ?>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
          <div>
            <p class="mc-card-label">Colegio</p>
            <p class="mc-card-val"><?= htmlspecialchars($pedido['colegio'] ?? '') ?></p>
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
          <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
          <div>
            <p class="mc-card-label">Empresa</p>
            <p class="mc-card-val"><?= htmlspecialchars($empresa) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon blue"><i class="bi bi-geo-alt-fill"></i></div>
          <div>
            <p class="mc-card-label">Zona</p>
            <p class="mc-card-val"><?= htmlspecialchars($zona_label) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon teal"><i class="bi bi-person-badge"></i></div>
          <div>
            <p class="mc-card-label">Responsable</p>
            <p class="mc-card-val"><?= htmlspecialchars($responsable) ?></p>
          </div>
        </div>
        <?php endif; ?>
        <div class="mc-card">
          <div class="mc-card-icon green"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <p class="mc-card-label">Cliente</p>
            <p class="mc-card-val"><?= htmlspecialchars($cliente['cliente'] ?? '') ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon amber"><i class="bi bi-layers"></i></div>
          <div>
            <p class="mc-card-label">Tipo</p>
            <p class="mc-card-val"><?= [1 => 'Libros sueltos', 2 => 'Paquetes', 3 => 'Paquetes y libros sueltos'][intval($pedido['petipo'] ?? 0)] ?? 'Paquetes' ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= $dv_tipo === 'De corte' ? 'blue' : 'teal' ?>"><i class="bi bi-scissors"></i></div>
          <div>
            <p class="mc-card-label">Tipo de devolución</p>
            <p class="mc-card-val"><?= htmlspecialchars($dv_tipo) ?></p>
          </div>
        </div>
        <div class="mc-card">
          <div class="mc-card-icon <?= ($estado_cls === 'vd-badge-green') ? 'green' : (($estado_cls === 'vd-badge-blue') ? 'blue' : (($estado_cls === 'vd-badge-red') ? 'red' : 'amber')) ?>"><i class="bi bi-flag-fill"></i></div>
          <div>
            <p class="mc-card-label">Estado</p>
            <p class="mc-card-val"><span class="<?= $estado_cls ?>"><?= htmlspecialchars($estado_display) ?></span></p>
          </div>
        </div>
      </div>

      <!-- OP vinculada -->
      <?php if ($op != 0): ?>
      <div class="modern-card mb-3">
        <div class="card-head">
          <h5><i class="bi bi-file-earmark-check mr-2"></i> Orden de Pedido vinculada</h5>
        </div>
        <div class="px-4 py-3">
          <a href="op_pendiente.php?op=<?= $n_op['id'] ?>" target="_blank" class="mc-btn mc-btn-blue d-print-none">
            <i class="bi bi-box-arrow-up-right"></i> OP # <?= $n_op['id'] ?>
          </a>
          <span class="d-none d-print-inline" style="font-size:.95rem;font-weight:600;color:#0f172a;">OP # <?= $n_op['id'] ?></span>
        </div>
      </div>
      <?php endif; ?>

      <!-- Fecha recibido bodega (print) -->
      <div id="impre"></div>

      <!-- Tabla de libros -->
      <div class="modern-card mb-3">
        <div class="card-head">
          <h5><i class="bi bi-book mr-2"></i> Libros de la devolución</h5>
          <span class="lm-count-badge"><?= count($libros) ?> libro(s) &middot; Total: <?= $total_cantidad ?></span>
        </div>
        <div class="table-responsive px-2 pb-2">
          <table class="table table-sm table-hover" id="dc-table">
            <thead>
              <tr>
                <th>ISBN</th>
                <th>Título</th>
                <th>Ubicación</th>
                <th class="d-print-none">Materia</th>
                <th>Grado</th>
                <th>PVP</th>
                <th>Descuento</th>
                <th>Precio facturación</th>
                <th>Cantidad</th>
                <th>Valor</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $total_v_sum = 0;
              $total_c_sum = 0;
              $grupo_act = null;
              foreach ($filas_dev as $fila_dev):
                $libro = $fila_dev['libro'];
                $libro['cantidad'] = $fila_dev['cantidad'];
                $desc       = ($libro['descuento_d'] === '0.0000' || $libro['descuento_d'] == 0) ? $libro['descuento'] : $libro['descuento_d'];
                $precio_fact = $libro['precio'] - ($libro['precio'] * $desc);
                $v_venta     = $precio_fact * $libro['cantidad'];
                $total_v_sum += $v_venta;
                $total_c_sum += $libro['cantidad'];
                // Encabezado del grupo: DataTables no admite filas con colspan en el tbody, así que va en un atributo de la
                // primera fila del grupo y se inserta al dibujar la tabla (drawCallback).
                $grupo_attr = '';
                if ($agrupar_dev && $fila_dev['grupo'] !== $grupo_act) {
                  $grupo_act = $fila_dev['grupo']; $g_dev = $grupos_dev[$grupo_act]; $es_paq = $grupo_act === 'p';
                  $grupo_html = '<div class="dc-grupo-in"><span class="dc-grupo-ico"><i class="bi ' . ($es_paq ? 'bi-box-seam' : 'bi-book') . '"></i></span>'
                    . '<span class="dc-grupo-tit">' . ($es_paq ? 'Devuelto en paquete' : 'Libros sueltos') . '</span>'
                    . '<span class="dc-grupo-datos">' . ($es_paq ? (int)$g_dev['paquetes'] . ' paquete(s) · ' : '') . (int)$g_dev['titulos'] . ' fila(s) · ' . (int)$g_dev['unidades'] . ' unidad(es)</span></div>';
                  $grupo_attr = ' data-grupo-html="' . htmlspecialchars($grupo_html) . '" data-grupo-cls="' . ($es_paq ? 'dc-grupo-paq' : 'dc-grupo-suelto') . '"';
                }
                $desc_pct    = round($desc * 100);

                $req_ubi = $bdd->prepare("SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion FROM lugares l JOIN ubicaciones u ON l.id=u.id_lugar JOIN libros_ubicaciones lu ON u.id=lu.ubicacion WHERE lu.id_libro='".$libro['libroid']."'");
                $req_ubi->execute();
                $ubicaciones = $req_ubi->fetchAll();
                $ubi = '';
                foreach ($ubicaciones as $u) {
                  $ubi .= ($u['id_tipo'] == 1)
                    ? $u['lugar'].$u['piso'].' Pallet '.$u['ubicacion'].', '
                    : $u['lugar'].' Bandeja '.$u['ubicacion'].', ';
                }
                $ubi = rtrim($ubi, ', ');

                // Grado de la adopción: calculado antes de la tabla (ver el orden por curso arriba).
                $grado = ['grado' => $fila_dev['grado']];
              ?>
              <tr<?= $agrupar_dev ? ' class="' . ($fila_dev['grupo'] === 'p' ? 'dc-fila-paq' : 'dc-fila-suelto') . '"' : '' ?><?= $grupo_attr ?>>
                <td><?= htmlspecialchars($libro['isbn']) ?></td>
                <td><?= htmlspecialchars($libro['libro']) ?>
                  <?php if ($fila_dev['tag'] !== ''): ?><br><span class="<?= $fila_dev['grupo'] === 'p' ? 'dc-paq-tag' : 'dc-suelto-tag' ?>"><i class="bi <?= $fila_dev['grupo'] === 'p' ? 'bi-box-seam' : 'bi-box-arrow-up' ?>"></i> <?= htmlspecialchars($fila_dev['tag']) ?></span><?php endif; ?></td>
                <td><?= htmlspecialchars($ubi ?: '—') ?></td>
                <td class="d-print-none"><?= htmlspecialchars($libro['materia']) ?></td>
                <td><?= htmlspecialchars($grado['grado'] ?? '—') ?></td>
                <td>$ <?= number_format($libro['precio'], 0, ',', '.') ?></td>
                <td><?= $desc_pct ?> %</td>
                <td>$ <?= number_format($precio_fact, 0, ',', '.') ?></td>
                <td><?= $libro['cantidad'] ?></td>
                <td>$ <?= number_format($v_venta, 0, ',', '.') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td>
                <td></td>
                <td></td>
                <td class="d-print-none"></td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align:right;padding-right:16px;"><strong>Total:</strong></td>
                <td><strong><?= $total_c_sum ?></strong></td>
                <td><strong>$ <?= number_format($total_v_sum, 0, ',', '.') ?></strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Observaciones -->
      <div class="modern-card mb-3">
        <div class="card-head">
          <h5><i class="bi bi-chat-text mr-2"></i> Observaciones</h5>
        </div>
        <div class="px-4 py-3">
          <textarea class="form-control" rows="3" readonly><?= htmlspecialchars($n_cole > 0 ? ($pedido['observaciones'] ?? '') : ($observaciones['observaciones'] ?? '')) ?></textarea>
        </div>
      </div>

      <!-- Firmas impresión -->
      <div class="vd-print-sigs">
        <div id="entregado"></div>
        <div id="recibido"></div>
      </div>

      <!-- Botones de acción -->
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:24px;" class="d-print-none">
        <?php
        $can_anular  = false;
        $can_recibir = false;
        $can_proceso = false;

        if (isset($n_op['estado']) && $n_op['estado'] == 2) {
            echo '<span class="vd-badge-green" style="padding:10px 18px;font-size:.9rem;">Atendida</span>';
        } elseif ($eid == 1 && $is_admin) {
            $can_anular = $can_recibir = true;
        } elseif ($pedido['cid'] == 0 && $obs_estado != 2 && $is_admin) {
            $can_anular = $can_recibir = true;
        } elseif ($eid == 2 && ($_SESSION['tipo'] == 1 || $_SESSION['id'] == 24)) {
            $can_anular = $can_proceso = true;
        } elseif ($pedido['cid'] == 0 && $obs_estado == 1 && $is_admin) {
            $can_anular = $can_proceso = true;
        } else {
            $bc = ($eid == 3) ? 'vd-badge-red' : 'vd-badge-blue';
            echo '<span class="'.$bc.'" style="padding:10px 18px;font-size:.9rem;">'.htmlspecialchars($estado_display).'</span>';
        }

        if ($can_anular)  echo '<button class="mc-btn mc-btn-red"   id="rechazar" type="button"><i class="bi bi-x-circle"></i> Anular</button>';
        if ($can_recibir && $vd_formato) echo '<a class="mc-btn mc-btn-green" href="formato_venta_directa.php?id=' . (int)$vd_formato['id'] . '"><i class="bi bi-check-circle"></i> Recibir en el formato No. ' . (int)$vd_formato['consecutivo'] . '</a>';
        elseif ($can_recibir) echo '<button class="mc-btn mc-btn-green"  id="aprobar"  type="button"><i class="bi bi-check-circle"></i> Recibir</button>';
        if ($can_proceso) echo '<button class="mc-btn mc-btn-amber"  id="proceso"  type="button"><i class="bi bi-arrow-repeat"></i> En proceso</button>';
        ?>
        <?php if ($is_admin && $op == 0): ?>
        <a href="solicitar_op.php?id_devol_v=<?= $id_pedido ?>" target="_blank" class="mc-btn mc-btn-amber">
          <i class="bi bi-file-earmark-plus"></i> Solicitar OP
        </a>
        <?php endif; ?>
        <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
          <i class="bi bi-printer"></i> Imprimir
        </button>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="src/ink-alerts.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script>

$(document).ready(function () {
  $('#dc-table').DataTable({
    // Venta directa: encabezados "Devuelto en paquete" / "Libros sueltos" antes de la primera fila de cada grupo, con el
    // colspan de las columnas visibles (Responsive puede ocultar algunas).
    drawCallback: function () {
      var $t = $(this.api().table().node()), n = $t.find('thead th:visible').length || 10;
      $t.find('tbody tr.dc-grupo').remove();
      $t.find('tbody tr[data-grupo-html]').each(function () {
        $('<tr class="dc-grupo ' + $(this).data('grupo-cls') + '"><td colspan="' + n + '">' + $(this).attr('data-grupo-html') + '</td></tr>').insertBefore(this);
      });
    },
    autoWidth:   false,
    paging:      false,
    searching:   false,
    info:        false,
    responsive:  true,
    ordering:    false,
    // Responsive escondía primero las columnas de la derecha (Cantidad, Valor): título y cantidad nunca se ocultan;
    // si no cabe, se esconden antes materia, ubicación, grado y descuento (2026-10-22).
    columnDefs: [
      { responsivePriority: 1, targets: 1 },  // Título
      { responsivePriority: 2, targets: 8 },  // Cantidad
      { responsivePriority: 3, targets: 9 },  // Valor
      { responsivePriority: 4, targets: 0 },  // ISBN
      { responsivePriority: 5, targets: 7 },  // Precio facturación
      { responsivePriority: 6, targets: 5 },  // PVP
      { responsivePriority: 7, targets: 6 },  // Descuento
      { responsivePriority: 8, targets: 4 },  // Grado
      { responsivePriority: 9, targets: 2 },  // Ubicación
      { responsivePriority: 10, targets: 3 }  // Materia
    ],
    language: {
      emptyTable: 'No hay libros para mostrar'
    }
  });
  // Responsive oculta/muestra columnas sin redibujar: el encabezado del grupo ocupa siempre todas las visibles.
  $('#dc-table').on('responsive-resize.dt', function (e, dt, columnas) {
    var n = columnas.filter(function (v) { return v; }).length;
    $('#dc-table tbody tr.dc-grupo > td').attr('colspan', n);
  });
});

$('#rechazar').on('click', function () {
  inkMotivoAnulacion({
    title: '¿Anular devolución?', text: 'Esta acción no se puede deshacer.', btnOk: 'Sí, anular'
  }, function (motivo) {
    window.location = 'php/accion_devol_v.php?rechazar=<?= intval($id_pedido) ?>&tipo=<?= intval($_GET['tipo'] ?? 0) ?>&motivo=' + encodeURIComponent(motivo);
  });
});

$('#aprobar').on('click', function () {
  inkConfirm({
    type: 'success', title: '¿Confirmar recepción?',
    text: 'Se marcará la devolución como recibida.', btnOk: 'Sí, recibir'
  }, function () {
    window.location = 'php/accion_devol_v.php?aprobar=<?= $id_pedido ?>&tipo=<?= intval($_GET['tipo'] ?? 0) ?>';
  });
});

$('#proceso').on('click', function () {
  inkConfirm({
    type: 'warning', title: '¿Poner en proceso?',
    text: 'Se actualizará el estado de la devolución.', btnOk: 'Confirmar'
  }, function () {
    window.location = 'php/accion_devol_v.php?proceso=<?= $id_pedido ?>&tipo=<?= intval($_GET['tipo'] ?? 0) ?>';
  });
});

window.addEventListener('beforeprint', function () {
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

<?php if ($is_admin): ?>
window.addEventListener('beforeprint', function () {
  $("#impre").html("<div class='vd-info-row' style='margin-bottom:16px;'><div class='vd-info-card' style='max-width:240px;'><span class='vd-ic-label'>Fecha recibido bodega</span><span class='vd-ic-value'><?= date('Y-m-d H:i') ?></span></div></div>");
  $("#entregado").html("<h4>Entregado por: ___________________________</h4>");
  $("#recibido").html("<h4>Recibido por: ___________________________</h4>");
  $.ajax({
    url: 'ajax/fecha_impre_devol.php', type: 'POST',
    data: 'feid=<?= date('Y-m-d H:i:s') ?>/<?= $id_pedido ?>',
    dataType: 'html'
  });
});
<?php endif; ?>
</script>
<script src="src/motivo-anulacion.js"></script>
</body>
</html>
