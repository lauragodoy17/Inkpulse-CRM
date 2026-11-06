<?php
/**
 * /php/backorders_buscar_pedido.php
 * Búsqueda de pedidos (por colegio, cliente o # de pedido) para el select2 de "asignar pedido" del
 * panel "Documentos sin verificar" en reporte_backorders.php — mismo patrón AJAX que
 * php/colocacion_buscar_colegio.php, pero buscando pedidos en vez de colegios porque acá la
 * asignación manual sí alimenta el backorder de un pedido específico (ver
 * php/backorders_wo_asignar_pedido.php).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if ($tipo_sesion !== 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

// Se excluyen los pedidos Anulados (estado=3) — igual criterio que includes/backorders_datos.php,
// un pedido cancelado no tiene nada pendiente por despachar.
// Se traen hasta 200 candidatos (más que los 30 finales) porque el siguiente paso los va a
// recortar a los que de verdad tienen algo pendiente por surtir — pedido por el usuario
// 2026-09-18 ("que solo me aparezca lo que está pendiente, no todos los pedidos"). Sin texto
// escrito (q vacío) se listan los primeros igual, sin filtrar por LIKE — así el desplegable ya
// trae algo con qué elegir apenas se abre, en vez de exigir escribir primero (pedido 2026-09-18).
$condiciones = "p.estado != 3";
$params = [];
if ($q !== '') {
    $condiciones .= " AND (c.colegio LIKE ? OR cl.cliente LIKE ? OR p.id = ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, ctype_digit($q) ? (int)$q : 0];
}
// Sin texto (listado inicial al abrir el desplegable) se abre la ventana de candidatos bastante más
// (500) que con texto (200): esta consulta solo corre una vez al abrir, no en cada tecla escrita, y
// con margen chico algunos colegios sin nada pendiente podían copar el LIMIT y dejar el desplegable
// vacío pese a sí haber pedidos pendientes más abajo en el orden alfabético.
$limiteCandidatos = $q === '' ? 500 : 200;
$stmt = $bdd->prepare("SELECT p.id, c.colegio, cl.cliente
                        FROM pedidos p
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
$idsConPendiente = [];
foreach ($calc['lineas'] as $l) {
    if ($l['pendiente'] > 0) $idsConPendiente[(int)$l['id_pedido']] = true;
}

$resultado = array_values(array_filter($candidatos, fn($c) => isset($idsConPendiente[(int)$c['id']])));
echo json_encode(array_slice($resultado, 0, 30));
