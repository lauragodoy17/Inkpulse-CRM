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
// es la zona (colegios.cod_zona -> usuarios.cod_zona), NO quién cargó la línea de
// presupuesto (presupuestos.id_usuario) — un colegio puede tener líneas cargadas por un
// asesor o distribuidor que no es el dueño de su zona (p.ej. cobertura puntual, o un
// distribuidor vendiendo títulos específicos), y eso no debe cambiar de quién es el
// colegio. Los admins (tipo=1) nunca cuentan como dueños de zona aunque la compartan con
// un asesor real (caso zona "EUREKA/GERENCIA": Mariana Castañeda tipo=3 + Giovanni
// Barbosa tipo=1 comparten cod_zona=5656; solo Mariana es dueña). Hector Morales (id=69,
// tipo=10) es dueño de zona igual que un asesor por la misma excepción de negocio que ya
// aplican $rolCondiciones más abajo. Verificado que ningún usuario activo tipo 3/6
// comparte cod_zona con otro, así que este LEFT JOIN nunca duplica filas.
$ownerJoin = "LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)";

// Mismos criterios que usa php/valoriza_excel.php ("valorización libro a libro") para que
// presupuesto/adopciones cuenten igual en dashboard y reporte: excluye probabilidad
// "Perdida" (id=3) y las líneas sin ninguna tasa de compra asignada (ni propia ni de
// distribuidor), que no representan una venta proyectable.
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

// Venta potencial: incluye también los libros por área electiva (cod_area), resolviendo su
// grado equivalente vía areas_objetivas para poder cruzarlos con la población de
// grados_paralelos, igual que hace la pestaña "Presupuesto" del colegio (ajax/tab_presup.php).
$ventaPotencialExpr = "((p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra))";

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

// Se exige pre_definido = 1 (mismo criterio que usa php/valoriza_global_excel.php para
// "Valor Presupuestado"), sin excluir probabilidad "Perdida" ni exigir tasa_compra asignada,
// a pedido del negocio, en vez de pre_aprob = 1 que usan ajax/tab_presup.php y
// php/presupuesto_excel.php.
// Se exige el JOIN a colegios en todas las consultas (no solo en el ranking) porque hay
// presupuestos "huérfanos" que referencian id_colegio de colegios ya eliminados; sin este
// join el dashboard los seguía sumando aunque no pertenezcan a ningún colegio visible en el
// sistema, mientras que php/valoriza_global_excel.php los excluye automáticamente al partir
// su consulta principal de la tabla colegios.
$baseFrom = "FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    $ownerJoin
    WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter;

// ── Tarjetas de estadística ──
$stmt = $bdd->prepare("SELECT SUM($ventaPotencialExpr) as venta_potencial, COUNT(*) as total $baseFrom");
$stmt->execute([$periodo]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC);
$venta_potencial = round(floatval($resumen['venta_potencial']), 0);
$total_valorizables = intval($resumen['total']);

$stmt = $bdd->prepare("SELECT COUNT(*) FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id $ownerJoin WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter);
$stmt->execute([$periodo]);
$total_items = intval($stmt->fetchColumn());

$stmt = $bdd->prepare("SELECT COUNT(*) FROM presupuestos p JOIN colegios c ON p.id_colegio = c.id $ownerJoin WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter . " AND p.definido = 1");
$stmt->execute([$periodo]);
$definidos = intval($stmt->fetchColumn());

$pct_definidos = $total_items > 0 ? round(($definidos / $total_items) * 100, 1) : 0;

// ── Donut: venta potencial por probabilidad ──
$stmt = $bdd->prepare("SELECT COALESCE(pr.probabilidad, 'Sin definir') as etiqueta, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    $ownerJoin
    LEFT JOIN probabilidades pr ON p.probabilidad = pr.id
    WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter . "
    GROUP BY p.probabilidad HAVING total > 0 ORDER BY total DESC");
$stmt->execute([$periodo]);
$probabilidad = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ranking: top colegios por venta potencial ──
$stmt = $bdd->prepare("SELECT UPPER(c.colegio) as colegio, c.codigo as codigo, SUM($ventaPotencialExpr) as total
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    JOIN libros l ON p.id_libro = l.id
    $gradoJoin
    $ownerJoin
    WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter . "
    GROUP BY p.id_colegio, c.codigo HAVING total > 0 ORDER BY total DESC LIMIT 8");
$stmt->execute([$periodo]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Barras: venta potencial por editorial (solo visible para tipo 1 en el dashboard) ──
$editoriales = [];
if ($tipo_sesion === 1) {
    $stmt = $bdd->prepare("SELECT e.editorial, SUM($ventaPotencialExpr) as total
        FROM presupuestos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN libros l ON p.id_libro = l.id
        JOIN editoriales e ON l.editorial = e.id
        $gradoJoin
        $ownerJoin
        WHERE p.id_periodo = ? AND p.pre_definido = 1 AND c.id_calendario = $calendario_periodo" . $condicionesNegocio . $userFilter . "
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
