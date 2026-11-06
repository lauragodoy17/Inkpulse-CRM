<?php
/**
 * /php/backorders_buscar_colegio_detalle.php
 * Búsqueda de COLEGIOS (por colegio, cliente o consecutivo BK) para el select2 del detalle de
 * backorders (reporte_backorders_pedido.php) — desde 2026-10-20 se busca por colegio, no por
 * pedido. Un resultado = colegio + período, con sus consecutivos y la fecha en que se generó el
 * backorder (asignación del primer consecutivo BK). Solo colegios con backorder confirmado
 * (consecutivo = REM/FV confirmada en World Office) y algo pendiente a nivel de colegio (con las
 * devoluciones y notas crédito restadas), de los períodos 2026B y 2027.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}
session_write_close();

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

$idsPeriodosPermitidos = obtener_ids_periodos_backorders_pedido($bdd, BACKORDERS_PERIODOS_REPORTE);
if (empty($idsPeriodosPermitidos)) {
    echo json_encode([]);
    exit;
}
backorders_asegurar_tabla_consecutivos($bdd);
if (!(int)$bdd->query("SELECT COUNT(*) FROM backorder_consecutivos")->fetchColumn()) {
    backorders_asignar_consecutivos_todos($bdd);
}

$condiciones = "p.estado != 3 AND p.id_periodo IN (" . implode(',', $idsPeriodosPermitidos) . ")";
$params = [];
if ($q !== '') {
    $condiciones .= " AND (c.colegio LIKE ? OR cl.cliente LIKE ? OR k.consecutivo LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$stmt = $bdd->prepare("SELECT p.id_colegio, p.id_periodo, c.colegio, pe.periodo,
                              GROUP_CONCAT(DISTINCT cl.cliente ORDER BY cl.cliente SEPARATOR ', ') AS cliente,
                              GROUP_CONCAT(DISTINCT k.consecutivo ORDER BY k.numero SEPARATOR ', ') AS consecutivos,
                              DATE(MIN(k.fecha_asignacion)) AS fecha_backorder
                       FROM pedidos p
                       JOIN backorder_consecutivos k ON k.id_pedido = p.id
                       JOIN colegios c ON c.id = p.id_colegio
                       JOIN periodos pe ON pe.id = p.id_periodo
                       LEFT JOIN clientes cl ON cl.id = p.cliente
                       WHERE $condiciones
                       GROUP BY p.id_colegio, p.id_periodo, c.colegio, pe.periodo
                       ORDER BY c.colegio ASC, p.id_periodo DESC
                       LIMIT 60");
$stmt->execute($params);

// Solo los que tienen algo pendiente a nivel de colegio (máximo 30 resultados).
$resultado = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    // Solo pedidos con documento asociado (los que no tienen no aparecen ni suman).
    $lineas = backorders_lineas_con_documento(calcular_lineas_backorder($bdd, (int)$c['id_periodo'], null, null, null, null, (int)$c['id_colegio'])['lineas']);
    $pendiente = array_sum(array_column(backorders_grupos_colegio($bdd, $lineas), 'pendiente'));
    if ($pendiente <= 0) continue;
    $c['pendiente'] = round($pendiente, 2);
    $resultado[] = $c;
    if (count($resultado) >= 30) break;
}
echo json_encode($resultado);
