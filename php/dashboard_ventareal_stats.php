<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 3, 6, 10], true)) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
// Libera el lock del archivo de sesión: este endpoint solo lee $_SESSION y nunca
// escribe, así que mantenerlo abierto solo bloquea las otras peticiones paralelas
// del dashboard (visitas/presupuestos/adopciones/asesores) contra la misma sesión de PHP.
session_write_close();

$periodo = intval($_GET['periodo'] ?? 0);
if ($periodo <= 0) {
    $periodo = intval($bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn());
}

// "Dueño de zona" del colegio: mismo criterio que dashboard_presupuestos_stats.php /
// dashboard_adopciones_stats.php — colegios.cod_zona -> usuarios.cod_zona, no quién cargó
// la línea de presupuesto. Los admins (tipo=1) nunca cuentan como dueños de zona; Hector
// Morales (id=69, tipo=10) sí, por la excepción de negocio ya existente.
$ownerJoin = "LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)";

// El rol "promotor" (etiqueta "Eureka" en el filtro) agrupa tipo=3 y además a
// Hector Morales (id=69, tipo=10) por pedido del negocio; se excluye de "otros"
// para no duplicarlo en ambos grupos. Ver también index.php ($promotores_dash/$otros_dash).
$rolCondiciones = ['promotor' => '(owner.tipo = 3 OR owner.id = 69)', 'distribuidor' => 'owner.tipo = 6', 'otros' => 'p.id_usuario IN (SELECT id FROM usuarios WHERE tipo IN (1,4,10) AND id <> 69)'];
if ($tipo_sesion === 1) {
    $rol = $_GET['rol'] ?? '';
    $usuario = intval($_GET['usuario'] ?? 0);
} else {
    // Promotores y distribuidores solo ven su propia información, sin importar el filtro enviado.
    $rol = '';
    $usuario = intval($_SESSION['id'] ?? 0);
}
if ($usuario > 0) {
    $tipo_usuario_filtro = intval($bdd->query("SELECT tipo FROM usuarios WHERE id = $usuario")->fetchColumn());
    if (in_array($tipo_usuario_filtro, [3, 6], true) || $usuario === 69) {
        $userFilter = " AND owner.id = $usuario";
    } else {
        $userFilter = " AND p.id_usuario = $usuario";
    }
} elseif ($rol === 'otros') {
    $userFilter = " AND " . $rolCondiciones['otros'];
} elseif (isset($rolCondiciones[$rol])) {
    $userFilter = " AND " . $rolCondiciones[$rol];
} else {
    $userFilter = "";
}

$sql_periodo = $bdd->prepare("SELECT periodo, id_calendario FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$row_periodo = $sql_periodo->fetch(PDO::FETCH_ASSOC);
$nombre_periodo = $row_periodo['periodo'] ?? '';
// Un colegio solo debe sumar en el período de su propio calendario (A/B), mismo criterio
// que dashboard_presupuestos_stats.php / dashboard_adopciones_stats.php.
$calendario_periodo = intval($row_periodo['id_calendario'] ?? 0);

// Mismo criterio que usa php/valoriza_excel.php ("valorización libro a libro") y
// dashboard_adopciones_stats.php ($condicionesNegocio) para que una línea cuente igual en
// los tres lados: excluye probabilidad "Perdida" (id=3) y las líneas sin ninguna tasa de
// compra asignada (ni propia ni de distribuidor). Sin este filtro, una línea marcada
// definido!=0 pero con probabilidad=3 o sin tasa se contaba acá y no en el excel,
// causando una diferencia por editorial.
$condicionesNegocio = " AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)";

// ── Venta real por colegio ──
// Mismo criterio que php/valoriza_global_excel.php: por colegio, si recursos.venta_real
// está capturado (>0) ese valor manual reemplaza por completo al calculado (precio neto de
// adopción * unidades de venta real); si no, se usa el calculado a partir de las líneas ya
// definidas (presupuestos.definido != 0). La decisión es por colegio, así que no se pueden
// sumar ambas fuentes: primero se arma el total por colegio y luego se agregan las tarjetas
// y el ranking sobre ese total ya resuelto.
$stmt = $bdd->prepare("SELECT p.id_colegio, UPPER(c.colegio) as colegio, c.codigo as codigo,
        SUM(CASE WHEN p.tasa_compra_d = 0
            THEN (p.precio - p.precio * p.descuento) * p.uni_vr
            ELSE (p.precio - p.precio * p.descuento_d) * p.uni_vr END) as venta_calculada
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    $ownerJoin
    WHERE p.id_periodo = ? AND p.definido != 0 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter . "
    GROUP BY p.id_colegio, c.colegio, c.codigo");
$stmt->execute([$periodo]);
$calculadaPorColegio = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JOIN presupuestos: solo para poder aplicar el mismo $userFilter (basado en
// p.id_usuario/owner.id) que la consulta anterior; como puede haber varias líneas de
// presupuesto por colegio, se colapsa con MAX(r.venta_real) para no duplicar el valor
// manual (una sola fila por colegio en recursos).
$stmt = $bdd->prepare("SELECT r.id_colegio, UPPER(c.colegio) as colegio, c.codigo as codigo, MAX(r.venta_real) as venta_real
    FROM recursos r
    JOIN colegios c ON r.id_colegio = c.id
    JOIN presupuestos p ON p.id_colegio = c.id AND p.id_periodo = r.id_periodo
    $ownerJoin
    WHERE r.id_periodo = ? AND r.venta_real > 0 AND c.id_calendario = $calendario_periodo" . $userFilter . "
    GROUP BY r.id_colegio, c.colegio, c.codigo");
$stmt->execute([$periodo]);
$manualPorColegio = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ventaPorColegio = [];
foreach ($calculadaPorColegio as $row) {
    $ventaPorColegio[$row['id_colegio']] = ['colegio' => $row['colegio'], 'codigo' => $row['codigo'], 'total' => floatval($row['venta_calculada'])];
}
foreach ($manualPorColegio as $row) {
    // El manual reemplaza al calculado para ese colegio, sin importar si ya había uno.
    $ventaPorColegio[$row['id_colegio']] = ['colegio' => $row['colegio'], 'codigo' => $row['codigo'], 'total' => floatval($row['venta_real'])];
}
$ventaPorColegio = array_filter($ventaPorColegio, function ($fila) { return $fila['total'] > 0; });

$venta_real_total = array_sum(array_column($ventaPorColegio, 'total'));
$colegios_con_venta = count($ventaPorColegio);

$ranking = array_values($ventaPorColegio);
usort($ranking, function ($a, $b) { return $b['total'] <=> $a['total']; });
$ranking = array_slice($ranking, 0, 8);

// ── Barras: venta real por editorial (solo tipo 1) ──
// Limitación real: el valor manual de recursos.venta_real es un total por colegio, sin
// desglose por libro, así que no hay forma de saber a qué editorial(es) corresponde. Se
// excluyen del desglose los colegios que usan ese valor manual (mismos $idsManual que ya
// se resolvieron arriba) y su suma se muestra aparte como "Sin desglosar (dato manual)",
// para que el total de esta gráfica siga cuadrando con la tarjeta de venta real total.
$editoriales = [];
if ($tipo_sesion === 1) {
    $idsManual = array_map('intval', array_column($manualPorColegio, 'id_colegio'));
    $exclManual = !empty($idsManual) ? implode(',', $idsManual) : '0';

    $stmt = $bdd->prepare("SELECT e.editorial, SUM(CASE WHEN p.tasa_compra_d = 0
            THEN (p.precio - p.precio * p.descuento) * p.uni_vr
            ELSE (p.precio - p.precio * p.descuento_d) * p.uni_vr END) as total
        FROM presupuestos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN libros l ON p.id_libro = l.id
        JOIN editoriales e ON l.editorial = e.id
        $ownerJoin
        WHERE p.id_periodo = ? AND p.definido != 0 AND c.id_calendario = $calendario_periodo
            AND p.id_colegio NOT IN ($exclManual)" . $condicionesNegocio . $userFilter . "
        GROUP BY l.editorial, e.editorial HAVING total > 0 ORDER BY total DESC LIMIT 10");
    $stmt->execute([$periodo]);
    $editoriales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sinDesglosar = array_sum(array_map('floatval', array_column($manualPorColegio, 'venta_real')));
    if ($sinDesglosar > 0) {
        $editoriales[] = ['editorial' => 'Sin desglosar (dato manual)', 'total' => $sinDesglosar];
    }
}

echo json_encode([
    'success' => true,
    'periodo' => $nombre_periodo,
    'stats' => [
        'venta_real_total' => round($venta_real_total, 0),
        'colegios_con_venta' => $colegios_con_venta,
    ],
    'ranking' => [
        'labels' => array_column($ranking, 'colegio'),
        'data' => array_map('floatval', array_column($ranking, 'total')),
        'codigos' => array_column($ranking, 'codigo'),
    ],
    'editoriales' => [
        'labels' => array_column($editoriales, 'editorial'),
        'data' => array_map('floatval', array_column($editoriales, 'total')),
    ],
]);
