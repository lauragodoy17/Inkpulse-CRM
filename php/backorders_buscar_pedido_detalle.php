<?php
/**
 * /php/backorders_buscar_pedido_detalle.php
 * Búsqueda de pedidos (por colegio, cliente o # de pedido) para el select2 del módulo
 * "Backorders por pedido" (reporte_backorders_pedido.php). Mismo patrón que
 * php/backorders_buscar_pedido.php, pero abierto a tipo 1 y 2 (todo el módulo de Backorders),
 * no solo admin — esa otra queda reservada a la acción de asignar pedido a un documento.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

// El buscador solo trae pedidos de 2026B y 2027 (BACKORDERS_PERIODOS_REPORTE, mismos períodos que
// reporte_backorders.php) — pedido por la usuaria 2026-10-20. Un período fuera de esa lista no
// devuelve nada.
$idsPeriodosPermitidos = obtener_ids_periodos_backorders_pedido($bdd, BACKORDERS_PERIODOS_REPORTE);
if (empty($idsPeriodosPermitidos)) {
    echo json_encode([]);
    exit;
}

// Se excluyen los pedidos Anulados (estado=3) — igual criterio que includes/backorders_datos.php,
// un pedido cancelado no tiene nada pendiente por despachar.
// Se traen hasta 200 candidatos (más que los 30 finales) porque el siguiente paso los va a
// recortar a los que de verdad tienen algo pendiente por surtir — pedido por el usuario
// 2026-09-18 ("que solo me aparezca lo que está pendiente, no todos los pedidos"). Sin texto
// escrito (q vacío) se listan los primeros igual, sin filtrar por LIKE — así el desplegable ya
// trae algo con qué elegir apenas se abre, en vez de exigir escribir primero (pedido 2026-09-18).
// Solo backorders con una REM/FV CONFIRMADA en World Office, es decir, con consecutivo BK (se
// asigna únicamente cuando hay documento confirmado, ver includes/backorders_consecutivos.php) —
// pedido por la usuaria 2026-10-20. La condición va en la consulta (JOIN), no solo en pantalla.
backorders_asegurar_tabla_consecutivos($bdd);
// Primera vez (tabla vacía): se asignan los consecutivos de todos los períodos.
if (!(int)$bdd->query("SELECT COUNT(*) FROM backorder_consecutivos")->fetchColumn()) {
    backorders_asignar_consecutivos_todos($bdd);
}
$condiciones = "p.estado != 3 AND p.id_periodo IN (" . implode(',', $idsPeriodosPermitidos) . ")";
$params = [];
if ($q !== '') {
    $condiciones .= " AND (c.colegio LIKE ? OR cl.cliente LIKE ? OR p.id = ? OR k.consecutivo LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, ctype_digit($q) ? (int)$q : 0, $like];
}
// Sin texto (listado inicial al abrir el desplegable) se abre la ventana de candidatos bastante más
// (500) que con texto (200): esta consulta solo corre una vez al abrir, no en cada tecla escrita, y
// con margen chico algunos colegios sin nada pendiente podían copar el LIMIT y dejar el desplegable
// vacío pese a sí haber pedidos pendientes más abajo en el orden alfabético.
$limiteCandidatos = $q === '' ? 500 : 200;
// Fecha de aprobación = último paso a estado 2 (Aprobado) en historial_estados. Los pedidos
// aprobados antes de que existiera ese historial no la tienen (null): en ese caso el buscador
// muestra la fecha de creación (p.fecha) marcada como "Creado" — pedido por la usuaria 2026-10-20.
// (fecha_r no sirve: es la fecha de entrega pedida, no la de aprobación).
$stmt = $bdd->prepare("SELECT p.id, p.id_colegio, p.id_periodo, k.consecutivo, c.colegio, cl.cliente, DATE(p.fecha) AS fecha_creado,
                              (SELECT DATE(MAX(h.fecha)) FROM historial_estados h
                               WHERE h.modulo = 'pedidos' AND h.id_registro = p.id AND h.estado = 2) AS fecha_aprobado
                        FROM pedidos p
                        JOIN backorder_consecutivos k ON k.id_pedido = p.id
                        JOIN colegios c ON c.id = p.id_colegio
                        LEFT JOIN clientes cl ON cl.id = p.cliente
                        WHERE $condiciones
                        ORDER BY c.colegio ASC LIMIT $limiteCandidatos");
$stmt->execute($params);
$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($candidatos)) {
    echo json_encode([]);
    exit;
}

// Se calcula el backorder SOLO de estos candidatos (no de todo el historial de pedidos) — así el
// buscador sigue siendo rápido en cada tecla escrita, ver comentario en calcular_lineas_backorder().
$idsCandidatos = array_column($candidatos, 'id');
$calc = calcular_lineas_backorder($bdd, null, null, null, null, $idsCandidatos);
// Con algo pendiente de despacho Y con su documento todavía confirmado (si la REM/FV se anuló o
// eliminó en World Office después de asignado el consecutivo, el consecutivo se conserva pero el
// pedido deja de salir aquí mientras no tenga otro documento confirmado).
$idsConPendiente = [];
$idsConfirmados = [];
foreach ($calc['lineas'] as $l) {
    if ($l['pendiente'] > 0) $idsConPendiente[(int)$l['id_pedido']] = true;
    foreach ($l['ops'] as $op) if (!empty($op['confirmado'])) $idsConfirmados[(int)$l['id_pedido']] = true;
}

// El detalle es por COLEGIO (todos sus pedidos del período, con devoluciones y notas crédito
// restadas al colegio): un pedido sin pendiente propio también sale si su colegio tiene pendiente.
$revisados = [];
foreach ($candidatos as $c) {
    $id = (int)$c['id'];
    if (isset($idsConPendiente[$id]) || !isset($idsConfirmados[$id])) continue;
    $cp = (int)$c['id_colegio'] . '|' . (int)$c['id_periodo'];
    if (!isset($revisados[$cp])) {
        if (count($revisados) >= 15) continue; // tope de cálculos por tecla escrita
        $lineasCol = calcular_lineas_backorder($bdd, (int)$c['id_periodo'], null, null, null, null, (int)$c['id_colegio'])['lineas'];
        $revisados[$cp] = (bool)array_filter(backorders_grupos_colegio($bdd, $lineasCol), fn($g) => $g['pendiente'] > 0);
    }
    if ($revisados[$cp]) $idsConPendiente[$id] = true;
}

$resultado = array_values(array_filter($candidatos, fn($c) => isset($idsConPendiente[(int)$c['id']]) && isset($idsConfirmados[(int)$c['id']])));
echo json_encode(array_slice($resultado, 0, 30));
