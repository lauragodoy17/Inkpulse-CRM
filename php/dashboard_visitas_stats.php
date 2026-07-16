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
    $userFilterPlan = " AND id_promotor = $usuario";
    $userFilterVisitas = " AND p.id_promotor = $usuario";
} elseif (isset($rolCondiciones[$rol])) {
    $rolSubquery = "(SELECT id FROM usuarios WHERE " . $rolCondiciones[$rol] . ")";
    $userFilterPlan = " AND id_promotor IN $rolSubquery";
    $userFilterVisitas = " AND p.id_promotor IN $rolSubquery";
} else {
    $userFilterPlan = "";
    $userFilterVisitas = "";
}

$sql_periodo = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$nombre_periodo = $sql_periodo->fetchColumn();

// ── Tarjetas de estadística ──
// Solo se muestran visitas EJECUTADAS (plan_trabajo.resultado = 1); no se consultan ni exponen
// las planificadas/pendientes (registradas pero aún sin ejecutar).
$stmt = $bdd->prepare("SELECT COUNT(*) FROM plan_trabajo WHERE id_periodo = ? AND resultado = 1" . $userFilterPlan);
$stmt->execute([$periodo]);
$ejecutadas = intval($stmt->fetchColumn());

// Nota: se filtra por p.id_periodo (plan_trabajo), NO por v.id_periodo (visitas) — ese campo
// se captura de forma independiente al ejecutar la visita y puede no coincidir con el periodo
// del plan original (confirmado: ~127 registros con periodos distintos entre ambas tablas).
// Usar p.id_periodo mantiene esta tarjeta consistente con "ejecutadas" y con cómo
// php/visitas_general.php resuelve la visita de un plan (por id_plan_trabajo, no por periodo).
//
// También se deduplica a 1 visita por plan (la de id más alto): algunos planes tienen más de
// un registro en `visitas` (envíos duplicados desde la app), lo que inflaba "Efectividad" por
// encima del propio conteo de "Visitas ejecutadas" (confirmado: 9 planes duplicados en el
// periodo 2027, ej. 937/858 en vez de 928/850).
$visitaUnica = "(SELECT v1.* FROM visitas v1
    INNER JOIN (SELECT id_plan_trabajo, MAX(id) as max_id FROM visitas GROUP BY id_plan_trabajo) vm
        ON vm.id_plan_trabajo = v1.id_plan_trabajo AND vm.max_id = v1.id)";

$stmt = $bdd->prepare("SELECT COUNT(*) FROM $visitaUnica v JOIN plan_trabajo p ON v.id_plan_trabajo = p.id WHERE p.id_periodo = ? AND p.resultado = 1" . $userFilterVisitas);
$stmt->execute([$periodo]);
$total_visitas = intval($stmt->fetchColumn());

$stmt = $bdd->prepare("SELECT COUNT(*) FROM $visitaUnica v JOIN plan_trabajo p ON v.id_plan_trabajo = p.id WHERE p.id_periodo = ? AND p.resultado = 1 AND v.efectiva = 1" . $userFilterVisitas);
$stmt->execute([$periodo]);
$efectivas = intval($stmt->fetchColumn());

$efectividad_pct = $total_visitas > 0 ? round(($efectivas / $total_visitas) * 100, 1) : 0;

// ── Ranking: top promotores por visitas ejecutadas en el periodo ──
$stmt = $bdd->prepare("SELECT CONCAT(u.nombres, ' ', u.apellidos) as promotor, COUNT(*) as total
    FROM plan_trabajo p JOIN usuarios u ON p.id_promotor = u.id
    WHERE p.id_periodo = ? AND p.resultado = 1" . $userFilterPlan . "
    GROUP BY p.id_promotor ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Objetivos más frecuentes en las visitas ejecutadas ──
$stmt = $bdd->prepare("SELECT COALESCE(NULLIF(o.objetivo, ''), 'Sin objetivo') as objetivo, COUNT(*) as total
    FROM plan_trabajo p LEFT JOIN objetivos o ON p.id_objetivo = o.id
    WHERE p.id_periodo = ? AND p.resultado = 1" . $userFilterPlan . "
    GROUP BY p.id_objetivo ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$objetivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'periodo' => $nombre_periodo,
    'stats' => [
        'ejecutadas' => $ejecutadas,
        'efectivas' => $efectivas,
        'no_efectivas' => max(0, $total_visitas - $efectivas),
        'total_visitas' => $total_visitas,
        'efectividad_pct' => $efectividad_pct,
    ],
    'ranking' => [
        'labels' => array_column($ranking, 'promotor'),
        'data' => array_map('intval', array_column($ranking, 'total')),
    ],
    'objetivos' => [
        'labels' => array_column($objetivos, 'objetivo'),
        'data' => array_map('intval', array_column($objetivos, 'total')),
    ],
]);
