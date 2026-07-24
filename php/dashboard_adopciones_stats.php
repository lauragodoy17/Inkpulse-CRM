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
// del dashboard (visitas/presupuestos/adopciones) contra la misma sesión de PHP.
session_write_close();

$periodo = intval($_GET['periodo'] ?? 0);
if ($periodo <= 0) {
    $periodo = intval($bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn());
}

// "Dueño de zona" del colegio: el criterio real de negocio para "de quién es" un colegio
// es la zona, NO quién cargó la línea de presupuesto (presupuestos.id_usuario) — un colegio
// puede tener líneas cargadas por un asesor o distribuidor que no es el dueño de su zona
// (p.ej. cobertura puntual, o un distribuidor vendiendo títulos específicos), y eso no debe
// cambiar de quién es el colegio. Se resuelve por presupuestos.cod_zona (la zona vigente EN
// ESE PERIODO), no por colegios.cod_zona (que es el valor ACTUAL/vivo y cambia con el
// tiempo — usarlo para un periodo pasado atribuye el colegio al dueño de HOY, no al de
// entonces). Cuando un mismo colegio+periodo tiene más de un cod_zona distinto entre sus
// líneas (dato ambiguo, ~5 casos reales), se descarta esa resolución (HAVING
// COUNT(DISTINCT cod_zona) = 1) y se cae a colegios.cod_zona como respaldo, en vez de
// adivinar cuál aplica. Los admins (tipo=1) nunca cuentan como dueños de zona aunque la
// compartan con un asesor real (caso zona "EUREKA/GERENCIA": Mariana Castañeda tipo=3 +
// Giovanni Barbosa tipo=1 comparten cod_zona=5656; solo Mariana es dueña). Hector Morales
// (id=69, tipo=10) es dueño de zona igual que un asesor por la misma excepción de negocio
// que ya aplican $rolCondiciones más abajo.
$ownerJoin = "LEFT JOIN (
        SELECT id_colegio, id_periodo, MIN(cod_zona) as cod_zona
        FROM presupuestos
        WHERE cod_zona <> ''
        GROUP BY id_colegio, id_periodo
        HAVING COUNT(DISTINCT cod_zona) = 1
    ) pz ON pz.id_colegio = c.id AND pz.id_periodo = p.id_periodo
    LEFT JOIN usuarios owner ON owner.cod_zona = COALESCE(pz.cod_zona, c.cod_zona) AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)";

// Mismo criterio que usa php/valoriza_excel.php ("valorización libro a libro") para que
// adopciones cuente igual en dashboard y reporte: excluye probabilidad "Perdida" (id=3) y
// las líneas sin ninguna tasa de compra asignada (ni propia ni de distribuidor).
$condicionesNegocio = " AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)";

// El rol "promotor" (etiqueta "Eureka" en el filtro) agrupa tipo=3 y además a
// Hector Morales (id=69, tipo=10) por pedido del negocio; se excluye de "otros"
// para no duplicarlo en ambos grupos. Ver también index.php ($promotores_dash/$otros_dash).
// El grupo "otros" (admins y tipos sin territorio) no tiene zona asignada, así que
// conserva el criterio viejo por id_usuario; promotor/distribuidor sí usan zona.
$rolCondiciones = ['promotor' => '(owner.tipo = 3 OR owner.id = 69)', 'distribuidor' => 'owner.tipo = 6', 'otros' => 'p.id_usuario IN (SELECT id FROM usuarios WHERE tipo IN (1,4,10) AND id <> 69)'];
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

$sql_periodo = $bdd->prepare("SELECT periodo, id_calendario FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$row_periodo = $sql_periodo->fetch(PDO::FETCH_ASSOC);
$nombre_periodo = $row_periodo['periodo'] ?? '';
// Un colegio solo debe sumar en el período de su propio calendario (A/B); sin este filtro,
// un presupuesto mal cargado en el período del calendario equivocado (error de captura real
// que se dio con al menos 2 colegios) se sigue contando aunque el colegio no pertenezca ahí.
$calendario_periodo = intval($row_periodo['id_calendario'] ?? 0);

// las "adopciones" son los ítems de presupuesto ya definidos (presupuestos.definido = 1),
// igual criterio que usa ajax/tab_adopciones.php para "Total de títulos adoptados".
// venta_potencial replica la fórmula de esa misma pestaña (precio_neto * FLOOR(alumnos * tasa_compra)),
// usando tasa/descuento del distribuidor (_d) cuando existe, igual que hace
// php/dashboard_presupuestos_stats.php para la "Venta potencial estimada" de presupuestos.
$ventaPotencialExpr = "(CASE WHEN p.tasa_compra_d = 0
    THEN (p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra)
    ELSE (p.precio - p.precio * p.descuento_d) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra_d) END)";

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

// Se exige el JOIN a colegios porque hay presupuestos "huérfanos" que referencian id_colegio
// de colegios ya eliminados; sin este join el dashboard los seguía sumando aunque no
// pertenezcan a ningún colegio visible, mientras que php/valoriza_global_excel.php los
// excluye automáticamente al partir su consulta principal de la tabla colegios.
$baseFrom = "FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id JOIN libros l ON p.id_libro = l.id $gradoJoin $ownerJoin
    WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter;

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
    FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id JOIN libros l ON p.id_libro = l.id JOIN materias m ON l.id_materia = m.id
    $gradoJoin
    $ownerJoin
    WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter . "
    GROUP BY m.id HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: top colegios por venta potencial ──
$stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, SUM($ventaPotencialExpr) as total
    FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    $ownerJoin
    WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter . "
    GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: colegios con mayor descuento promedio, sobre los títulos ya adoptados ──
// Usa el mismo descuento que aplica venta_potencial: el del distribuidor (_d) cuando
// tiene tasa_compra_d asignada, si no el descuento base del presupuesto.
$descuentoExpr = "(CASE WHEN p.tasa_compra_d = 0 THEN p.descuento ELSE p.descuento_d END * 100)";
function descuentosRanking($bdd, $descuentoExpr, $ownerJoin, $periodo, $calendario_periodo, $condicionesNegocio, $filtro)
{
    $stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, AVG($descuentoExpr) as total
        FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id
        $ownerJoin
        WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $filtro . "
        GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
    $stmt->execute([$periodo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Con el filtro de rol en "Todos" ($userFilter vacío), "Colegios con más descuento" mezclaba
// colegios de Eureka y de distribuidores en un mismo ranking; ahora se separa en dos rankings
// (uno por cada grupo) para que no se pierda de vista a qué grupo pertenece cada colegio. Con
// un rol o asesor específico ya filtrado, se mantiene el ranking único de siempre.
$descuentosSplit = ($userFilter === "");
if ($descuentosSplit) {
    $descuentos = [];
    $descuentos_eureka = descuentosRanking($bdd, $descuentoExpr, $ownerJoin, $periodo, $calendario_periodo, $condicionesNegocio, " AND " . $rolCondiciones['promotor']);
    $descuentos_distribuidores = descuentosRanking($bdd, $descuentoExpr, $ownerJoin, $periodo, $calendario_periodo, $condicionesNegocio, " AND " . $rolCondiciones['distribuidor']);
} else {
    $descuentos = descuentosRanking($bdd, $descuentoExpr, $ownerJoin, $periodo, $calendario_periodo, $condicionesNegocio, $userFilter);
    $descuentos_eureka = [];
    $descuentos_distribuidores = [];
}

// ── Barras: venta potencial por editorial, sobre los títulos ya adoptados (solo tipo 1) ──
$editoriales = [];
if ($tipo_sesion === 1) {
    $stmt = $bdd->prepare("SELECT e.editorial, SUM($ventaPotencialExpr) as total
        FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id JOIN libros l ON p.id_libro = l.id JOIN editoriales e ON l.editorial = e.id
        $gradoJoin
        $ownerJoin
        WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter . "
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
    'descuentos_split' => $descuentosSplit,
    'descuentos' => [
        'labels' => array_column($descuentos, 'colegio'),
        'data' => array_map('floatval', array_column($descuentos, 'total')),
        'codigos' => array_column($descuentos, 'codigo'),
    ],
    'descuentos_eureka' => [
        'labels' => array_column($descuentos_eureka, 'colegio'),
        'data' => array_map('floatval', array_column($descuentos_eureka, 'total')),
        'codigos' => array_column($descuentos_eureka, 'codigo'),
    ],
    'descuentos_distribuidores' => [
        'labels' => array_column($descuentos_distribuidores, 'colegio'),
        'data' => array_map('floatval', array_column($descuentos_distribuidores, 'total')),
        'codigos' => array_column($descuentos_distribuidores, 'codigo'),
    ],
    'editoriales' => [
        'labels' => array_column($editoriales, 'editorial'),
        'data' => array_map('floatval', array_column($editoriales, 'total')),
    ],
]);
