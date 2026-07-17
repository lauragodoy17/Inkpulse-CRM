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

// "Dueño de zona" del colegio: el criterio real de negocio para "de quién es" un colegio
// es la zona (colegios.cod_zona -> usuarios.cod_zona), NO quién cargó la línea de
// presupuesto (presupuestos.id_usuario) — ver la explicación completa en
// php/dashboard_presupuestos_stats.php. Los admins (tipo=1) nunca cuentan como dueños de
// zona; Hector Morales (id=69, tipo=10) sí, por la misma excepción de negocio que ya
// aplican $rolCondiciones más abajo. Verificado que ningún usuario activo tipo 3/6
// comparte cod_zona con otro, así que este LEFT JOIN nunca duplica filas.
$ownerJoin = "LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)";

// El rol "promotor" (etiqueta "Eureka" en el filtro) agrupa tipo=3 y además a
// Hector Morales (id=69, tipo=10) por pedido del negocio; se excluye de "otros"
// para no duplicarlo en ambos grupos. Ver también index.php ($promotores_dash/$otros_dash).
// El grupo "otros" (admins y tipos sin territorio) no tiene zona asignada, así que
// conserva el criterio viejo por id_usuario; promotor/distribuidor sí usan zona.
$rolCondiciones = ['promotor' => '(owner.tipo = 3 OR owner.id = 69)', 'distribuidor' => 'owner.tipo = 6', 'otros' => 'p.id_usuario IN (SELECT id FROM usuarios WHERE tipo IN (1,4,10) AND id <> 69)'];
$rol = $_GET['rol'] ?? '';
$usuario = intval($_GET['usuario'] ?? 0);
if ($usuario > 0) {
    // Un asesor/distribuidor real (tipo 3/6) o Hector Morales (id=69) se filtra por la
    // zona del colegio; cualquier otro tipo (admins sin territorio) usa el criterio viejo.
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

$sql_periodo = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$nombre_periodo = $sql_periodo->fetchColumn();

// Mismos joins/fórmulas que dashboard_presupuestos_stats.php y dashboard_adopciones_stats.php:
// valorizan cada ítem con el precio neto por el número de alumnos que se proyecta que compren
// (alumnos * tasa_compra), para poder comparar en las mismas unidades (COP) el presupuesto en
// definición contra lo ya adoptado, agrupado por asesor (presupuestos.id_usuario).
// El join a areas_objetivas se limita a un código de área por colegio (id_colegio, codigo).
// Algunos colegios tienen más de un registro para el mismo código de área con distinto
// id_grado_otro (dato ambiguo/duplicado); en ese caso no hay forma confiable de saber cuál
// vale, así que se descarta esa coincidencia (HAVING COUNT(*) = 1) y se cae al grado propio
// del libro (l.id_grado vía COALESCE) en vez de arriesgar a escoger el duplicado equivocado.
// Antes, al no filtrar por id_colegio, un mismo código de área podía además machear contra
// el de OTRO colegio, duplicando la fila del presupuesto (fan-out) e inflando el total.
$gradoJoin = "LEFT JOIN (
        SELECT id_colegio, codigo, MAX(id_grado_otro) as id_grado_otro
        FROM areas_objetivas
        WHERE id_periodo = $periodo AND codigo <> ''
        GROUP BY id_colegio, codigo
        HAVING COUNT(*) = 1
    ) ao ON ao.codigo = p.cod_area AND ao.id_colegio = p.id_colegio AND p.cod_area <> '' AND p.cod_area IS NOT NULL
    LEFT JOIN (SELECT id_colegio, id_grado, id_periodo, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_periodo = $periodo GROUP BY id_colegio, id_grado, id_periodo) gp
        ON gp.id_colegio = p.id_colegio AND gp.id_grado = COALESCE(ao.id_grado_otro, l.id_grado) AND gp.id_periodo = p.id_periodo";

$ventaPotencialExpr = "((p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra))";
$ventaAdopcionExpr = "(CASE WHEN p.tasa_compra_d = 0
    THEN (p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra)
    ELSE (p.precio - p.precio * p.descuento_d) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra_d) END)";

// Se exige el JOIN a colegios en ambas consultas porque hay presupuestos "huérfanos" que
// referencian id_colegio de colegios ya eliminados; sin este join se seguían sumando aunque
// no pertenezcan a ningún colegio visible, mientras que php/valoriza_global_excel.php los
// excluye automáticamente al partir su consulta principal de la tabla colegios.

// ── Presupuesto potencial por asesor ──
// Se agrupa por el dueño de zona del colegio (owner), no por quién cargó la línea
// (p.id_usuario), para que un colegio siempre le sume al mismo asesor/distribuidor sin
// importar quién haya digitado cada renglón. Los colegios sin dueño de zona se agrupan
// bajo "SIN ASESOR ASIGNADO" en vez de perderse o atribuirse a quien cargó la línea.
$stmt = $bdd->prepare("SELECT COALESCE(owner.id, 0) as id_usuario, COALESCE(UPPER(CONCAT(owner.nombres, ' ', owner.apellidos)), 'SIN ASESOR ASIGNADO') as asesor, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $ownerJoin
    $gradoJoin
    WHERE p.id_periodo = ? AND p.pre_definido = 1" . $userFilter . "
    GROUP BY COALESCE(owner.id, 0), asesor HAVING total > 0");
$stmt->execute([$periodo]);
$presupuestoPorAsesor = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Venta potencial de adopciones por asesor ──
$stmt = $bdd->prepare("SELECT COALESCE(owner.id, 0) as id_usuario, COALESCE(UPPER(CONCAT(owner.nombres, ' ', owner.apellidos)), 'SIN ASESOR ASIGNADO') as asesor, SUM($ventaAdopcionExpr) as total
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $ownerJoin
    $gradoJoin
    WHERE p.id_periodo = ? AND p.definido = 1" . $userFilter . "
    GROUP BY COALESCE(owner.id, 0), asesor HAVING total > 0");
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
