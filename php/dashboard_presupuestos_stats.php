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

// Venta potencial: mismo criterio que la pestaña "Presupuesto" del colegio (ajax/tab_presup.php) —
// suma todos los ítems con tasa de compra asignada, excluyendo solo probabilidad "Perdida".
// Incluye también los libros por área electiva (cod_area), resolviendo su grado equivalente vía
// areas_objetivas para poder cruzarlos con la población de grados_paralelos igual que hace esa pestaña.
$ventaPotencialExpr = "((p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra))";

$gradoJoin = "LEFT JOIN areas_objetivas ao ON ao.codigo = p.cod_area AND p.cod_area <> '' AND p.cod_area IS NOT NULL
    LEFT JOIN (SELECT id_colegio, id_grado, id_periodo, SUM(alumnos) as alumnos FROM grados_paralelos GROUP BY id_colegio, id_grado, id_periodo) gp
        ON gp.id_colegio = p.id_colegio AND gp.id_grado = COALESCE(ao.id_grado_otro, l.id_grado) AND gp.id_periodo = p.id_periodo";

// Se exige pre_aprob = 1 porque es el mismo flag que usan ajax/tab_presup.php y
// php/presupuesto_excel.php para decidir qué libros hacen parte del presupuesto oficial
// del colegio; sin este filtro el dashboard también sumaba libros del flujo de
// cumplimiento/distribuidor que nunca aparecen en la pestaña de presupuesto ni en su Excel.
$baseFrom = "FROM presupuestos p
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.probabilidad != 3 AND p.tasa_compra IS NOT NULL AND p.pre_aprob = 1" . $userFilter;

// ── Tarjetas de estadística ──
$stmt = $bdd->prepare("SELECT SUM($ventaPotencialExpr) as venta_potencial, COUNT(*) as total $baseFrom");
$stmt->execute([$periodo]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC);
$venta_potencial = round(floatval($resumen['venta_potencial']), 0);
$total_valorizables = intval($resumen['total']);

$stmt = $bdd->prepare("SELECT COUNT(*) FROM presupuestos p WHERE p.id_periodo = ? AND p.pre_aprob = 1" . $userFilter);
$stmt->execute([$periodo]);
$total_items = intval($stmt->fetchColumn());

$stmt = $bdd->prepare("SELECT COUNT(*) FROM presupuestos p WHERE p.id_periodo = ? AND p.pre_aprob = 1" . $userFilter . " AND p.definido = 1");
$stmt->execute([$periodo]);
$definidos = intval($stmt->fetchColumn());

$pct_definidos = $total_items > 0 ? round(($definidos / $total_items) * 100, 1) : 0;

// ── Donut: venta potencial por probabilidad ──
$stmt = $bdd->prepare("SELECT COALESCE(pr.probabilidad, 'Sin definir') as etiqueta, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    LEFT JOIN probabilidades pr ON p.probabilidad = pr.id
    WHERE p.id_periodo = ? AND p.probabilidad != 3 AND p.tasa_compra IS NOT NULL AND p.pre_aprob = 1" . $userFilter . "
    GROUP BY p.probabilidad HAVING total > 0 ORDER BY total DESC");
$stmt->execute([$periodo]);
$probabilidad = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: top colegios por venta potencial ──
$stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.probabilidad != 3 AND p.tasa_compra IS NOT NULL AND p.pre_aprob = 1" . $userFilter . "
    GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Barras: venta potencial por editorial (solo visible para tipo 1 en el dashboard) ──
$editoriales = [];
if ($tipo_sesion === 1) {
    $stmt = $bdd->prepare("SELECT e.editorial, SUM($ventaPotencialExpr) as total
        FROM presupuestos p
        JOIN libros l ON p.id_libro = l.id
        JOIN editoriales e ON l.editorial = e.id
        $gradoJoin
        WHERE p.id_periodo = ? AND p.probabilidad != 3 AND p.tasa_compra IS NOT NULL AND p.pre_aprob = 1" . $userFilter . "
        GROUP BY l.editorial, e.editorial HAVING total > 0 ORDER BY total DESC LIMIT 10");
    $stmt->execute([$periodo]);
    $editoriales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'success' => true,
    'periodo' => $nombre_periodo,
    'stats' => [
        'venta_potencial' => $venta_potencial,
        'total_items' => $total_items,
        'definidos' => $definidos,
        'pct_definidos' => $pct_definidos,
    ],
    'probabilidad' => [
        'labels' => array_column($probabilidad, 'etiqueta'),
        'data' => array_map('floatval', array_column($probabilidad, 'total')),
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
