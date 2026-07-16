<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

// A diferencia de los otros dashboard_*_stats.php, este cruce por asesor es
// solo para el administrador (tipo 1), no para promotores/distribuidores.
$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if ($tipo_sesion !== 1) {
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

// El rol "promotor" (etiqueta "Eureka" en el filtro) agrupa tipo=3 y además a
// Hector Morales (id=69, tipo=10) por pedido del negocio; se excluye de "otros"
// para no duplicarlo en ambos grupos. Ver también index.php ($promotores_dash/$otros_dash).
$rolCondiciones = ['promotor' => '(tipo = 3 OR id = 69)', 'distribuidor' => 'tipo = 6', 'otros' => 'tipo IN (1,4,10) AND id <> 69'];
$rol = $_GET['rol'] ?? '';
$usuario = intval($_GET['usuario'] ?? 0);
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

// Mismos joins/fórmulas que dashboard_presupuestos_stats.php y dashboard_adopciones_stats.php:
// valorizan cada ítem con el precio neto por el número de alumnos que se proyecta que compren
// (alumnos * tasa_compra), para poder comparar en las mismas unidades (COP) el presupuesto en
// definición contra lo ya adoptado, agrupado por asesor (presupuestos.id_usuario).
$gradoJoin = "LEFT JOIN areas_objetivas ao ON ao.codigo = p.cod_area AND p.cod_area <> '' AND p.cod_area IS NOT NULL
    LEFT JOIN (SELECT id_colegio, id_grado, id_periodo, SUM(alumnos) as alumnos FROM grados_paralelos GROUP BY id_colegio, id_grado, id_periodo) gp
        ON gp.id_colegio = p.id_colegio AND gp.id_grado = COALESCE(ao.id_grado_otro, l.id_grado) AND gp.id_periodo = p.id_periodo";

$ventaPotencialExpr = "((p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra))";
$ventaAdopcionExpr = "(CASE WHEN p.tasa_compra_d = 0
    THEN (p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra)
    ELSE (p.precio - p.precio * p.descuento_d) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra_d) END)";

// ── Presupuesto potencial por asesor ──
$stmt = $bdd->prepare("SELECT p.id_usuario, UPPER(CONCAT(u.nombres, ' ', u.apellidos)) as asesor, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN libros l ON p.id_libro = l.id
    JOIN usuarios u ON p.id_usuario = u.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.probabilidad != 3 AND p.tasa_compra IS NOT NULL AND p.pre_aprob = 1" . $userFilter . "
    GROUP BY p.id_usuario, asesor HAVING total > 0");
$stmt->execute([$periodo]);
$presupuestoPorAsesor = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Venta potencial de adopciones por asesor ──
$stmt = $bdd->prepare("SELECT p.id_usuario, UPPER(CONCAT(u.nombres, ' ', u.apellidos)) as asesor, SUM($ventaAdopcionExpr) as total
    FROM presupuestos p
    JOIN libros l ON p.id_libro = l.id
    JOIN usuarios u ON p.id_usuario = u.id
    $gradoJoin
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
    GROUP BY p.id_usuario, asesor HAVING total > 0");
$stmt->execute([$periodo]);
$adopcionesPorAsesor = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Combina ambos grupos por asesor (no es un JOIN porque un asesor puede tener
// presupuesto sin adopciones todavía, o viceversa) ──
$porAsesor = [];
foreach ($presupuestoPorAsesor as $row) {
    $porAsesor[$row['id_usuario']] = ['asesor' => $row['asesor'], 'presupuesto' => floatval($row['total']), 'adopciones' => 0.0];
}
foreach ($adopcionesPorAsesor as $row) {
    if (!isset($porAsesor[$row['id_usuario']])) {
        $porAsesor[$row['id_usuario']] = ['asesor' => $row['asesor'], 'presupuesto' => 0.0, 'adopciones' => 0.0];
    }
    $porAsesor[$row['id_usuario']]['adopciones'] = floatval($row['total']);
}

usort($porAsesor, function ($a, $b) {
    return ($b['presupuesto'] + $b['adopciones']) <=> ($a['presupuesto'] + $a['adopciones']);
});
$porAsesor = array_slice($porAsesor, 0, 8);

echo json_encode([
    'success' => true,
    'periodo' => $nombre_periodo,
    'asesores' => [
        'labels' => array_column($porAsesor, 'asesor'),
        'presupuesto' => array_column($porAsesor, 'presupuesto'),
        'adopciones' => array_column($porAsesor, 'adopciones'),
    ],
]);
