<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 3, 6], true)) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
// Libera el lock del archivo de sesión: este endpoint solo lee $_SESSION y nunca
// escribe, así que mantenerlo abierto solo bloquea las otras peticiones paralelas
// del dashboard (visitas/presupuestos/adopciones) contra la misma sesión de PHP.
session_write_close();

$periodo = intval($_GET['periodo'] ?? 0);
if ($periodo <= 0) {
    $periodo = intval($bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn());
}

// El rol "promotor" (etiqueta "Eureka" en el filtro) agrupa tipo=3 y además a
// Hector Morales (id=69, tipo=10) por pedido del negocio; se excluye de "otros"
// para no duplicarlo en ambos grupos. Ver también index.php ($promotores_dash/$otros_dash).
$rolCondiciones = ['promotor' => '(tipo = 3 OR id = 69)', 'distribuidor' => 'tipo = 6', 'otros' => 'tipo IN (1,4,10) AND id <> 69'];
if ($tipo_sesion === 1) {
    $rol = $_GET['rol'] ?? '';
    $usuario = intval($_GET['usuario'] ?? 0);
} else {
    // Promotores y distribuidores solo ven su propia información,
    // sin importar qué filtro envíe el cliente.
    $rol = '';
    $usuario = intval($_SESSION['id'] ?? 0);
}
if ($usuario > 0) {
    $userFilter = " AND p.id_usuario = $usuario";
} elseif (isset($rolCondiciones[$rol])) {
    $userFilter = " AND p.id_usuario IN (SELECT id FROM usuarios WHERE " . $rolCondiciones[$rol] . ")";
} else {
    $userFilter = "";
}

$sql_periodo = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$nombre_periodo = $sql_periodo->fetchColumn();

// las "adopciones" son los ítems de presupuesto ya definidos (presupuestos.definido = 1),
// igual criterio que usa ajax/tab_adopciones.php para "Total de títulos adoptados".
// venta_potencial replica la fórmula de esa misma pestaña (precio_neto * FLOOR(alumnos * tasa_compra)),
// usando tasa/descuento del distribuidor (_d) cuando existe, igual que hace
// php/dashboard_presupuestos_stats.php para la "Venta potencial estimada" de presupuestos.
$ventaPotencialExpr = "(CASE WHEN p.tasa_compra_d = 0
    THEN (p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra)
    ELSE (p.precio - p.precio * p.descuento_d) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra_d) END)";

$gradoJoin = "LEFT JOIN areas_objetivas ao ON ao.codigo = p.cod_area AND p.cod_area <> '' AND p.cod_area IS NOT NULL
    LEFT JOIN (SELECT id_colegio, id_grado, id_periodo, SUM(alumnos) as alumnos FROM grados_paralelos GROUP BY id_colegio, id_grado, id_periodo) gp
        ON gp.id_colegio = p.id_colegio AND gp.id_grado = COALESCE(ao.id_grado_otro, l.id_grado) AND gp.id_periodo = p.id_periodo";

$baseFrom = "FROM presupuestos p JOIN libros l ON p.id_libro = l.id $gradoJoin
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter;

// ── Tarjetas de estadística ──
$stmt = $bdd->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT p.id_colegio) as colegios, SUM($ventaPotencialExpr) as venta_potencial
    $baseFrom");
$stmt->execute([$periodo]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC);

$total_adoptados = intval($resumen['total']);
$colegios_con_adopcion = intval($resumen['colegios']);
$venta_potencial = round(floatval($resumen['venta_potencial']), 0);
$promedio_por_colegio = $colegios_con_adopcion > 0 ? round($total_adoptados / $colegios_con_adopcion, 1) : 0;
$venta_promedio_titulo = $total_adoptados > 0 ? round($venta_potencial / $total_adoptados, 0) : 0;

// ── Barras: venta potencial por materia ──
$stmt = $bdd->prepare("SELECT m.materia, SUM($ventaPotencialExpr) as total
    FROM presupuestos p JOIN libros l ON p.id_libro = l.id JOIN materias m ON l.id_materia = m.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
    GROUP BY m.id HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: top colegios por venta potencial ──
$stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, SUM($ventaPotencialExpr) as total
    FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
    GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: colegios con mayor descuento promedio, sobre los títulos ya adoptados ──
// Usa el mismo descuento que aplica venta_potencial: el del distribuidor (_d) cuando
// tiene tasa_compra_d asignada, si no el descuento base del presupuesto.
$descuentoExpr = "(CASE WHEN p.tasa_compra_d = 0 THEN p.descuento ELSE p.descuento_d END * 100)";
$stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, AVG($descuentoExpr) as total
    FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
    GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$descuentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Barras: venta potencial por editorial, sobre los títulos ya adoptados (solo tipo 1) ──
$editoriales = [];
if ($tipo_sesion === 1) {
    $stmt = $bdd->prepare("SELECT e.editorial, SUM($ventaPotencialExpr) as total
        FROM presupuestos p JOIN libros l ON p.id_libro = l.id JOIN editoriales e ON l.editorial = e.id
        $gradoJoin
        WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
        GROUP BY l.editorial, e.editorial HAVING total > 0 ORDER BY total DESC LIMIT 10");
    $stmt->execute([$periodo]);
    $editoriales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'success' => true,
    'periodo' => $nombre_periodo,
    'stats' => [
        'venta_potencial' => $venta_potencial,
        'venta_promedio_titulo' => $venta_promedio_titulo,
        'total_adoptados' => $total_adoptados,
        'colegios_con_adopcion' => $colegios_con_adopcion,
        'promedio_por_colegio' => $promedio_por_colegio,
    ],
    'materias' => [
        'labels' => array_column($materias, 'materia'),
        'data' => array_map('floatval', array_column($materias, 'total')),
    ],
    'ranking' => [
        'labels' => array_column($ranking, 'colegio'),
        'data' => array_map('floatval', array_column($ranking, 'total')),
        'codigos' => array_column($ranking, 'codigo'),
    ],
    'descuentos' => [
        'labels' => array_column($descuentos, 'colegio'),
        'data' => array_map('floatval', array_column($descuentos, 'total')),
        'codigos' => array_column($descuentos, 'codigo'),
    ],
    'editoriales' => [
        'labels' => array_column($editoriales, 'editorial'),
        'data' => array_map('floatval', array_column($editoriales, 'total')),
    ],
]);
