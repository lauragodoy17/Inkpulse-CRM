<?php
/**
 * /php/backorders_buscar_cliente.php
 * Búsqueda de clientes (catálogo World Office, tabla `clientes`) para el select2 del filtro
 * "Cliente" en reporte_backorders.php — mismo patrón AJAX que php/backorders_buscar_pedido.php.
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
require_once("../includes/periodos_fechas.php");
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
// Acota "pendiente" al rango de fechas del calendario del período seleccionado en la página (no al
// id_periodo literal de cada pedido) — pedido por el usuario 2026-09-18. Sin período (o uno sin
// rango calculable, ver calcular_rango_periodo()) no se filtra por fecha.
[$fechaInicioPeriodo, $fechaFinPeriodo] = obtener_rango_fechas_periodo($bdd, $_GET['id_periodo'] ?? 0);

// Se traen hasta 200 candidatos (más que los 30 finales) porque el siguiente paso los va a
// recortar a los que de verdad tienen algo pendiente por surtir — pedido por el usuario
// 2026-09-18 ("que solo salgan a los que se les debe algo"). Sin texto escrito (q vacío) se listan
// los primeros igual, sin filtrar por LIKE — así el desplegable ya trae algo con qué elegir apenas
// se abre, en vez de exigir escribir primero (pedido 2026-09-18).
$condiciones = '1=1';
$params = [];
if ($q !== '') {
    $condiciones = 'cliente LIKE ?';
    $params = ['%' . $q . '%'];
}
// Sin texto (listado inicial al abrir el desplegable) se abre la ventana de candidatos bastante más
// (500) que con texto (200): esta consulta solo corre una vez al abrir, no en cada tecla escrita, y
// con margen chico algunos clientes sin nada pendiente podían copar el LIMIT y dejar el desplegable
// vacío pese a sí haber clientes pendientes más abajo en el orden alfabético.
$limiteCandidatos = $q === '' ? 500 : 200;
$stmt = $bdd->prepare("SELECT id, cliente FROM clientes WHERE $condiciones ORDER BY cliente ASC LIMIT $limiteCandidatos");
$stmt->execute($params);
$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($candidatos)) {
    echo json_encode([]);
    exit;
}

// Pedidos (no Anulados) de esos clientes candidatos — se acota el cálculo de backorder a solo
// estos pedidos, no a todo el historial, para que el buscador siga siendo rápido en cada tecla
// escrita (ver comentario en calcular_lineas_backorder()).
$idsClientesCandidatos = array_column($candidatos, 'id');
$ph = implode(',', array_fill(0, count($idsClientesCandidatos), '?'));
$condicionesPedidos = "estado != 3 AND cliente IN ($ph)";
$paramsPedidos = $idsClientesCandidatos;
if ($fechaInicioPeriodo && $fechaFinPeriodo) {
    $condicionesPedidos .= " AND fecha BETWEEN ? AND ?";
    $paramsPedidos[] = $fechaInicioPeriodo;
    $paramsPedidos[] = $fechaFinPeriodo;
}
$stmtPedidos = $bdd->prepare("SELECT id, cliente FROM pedidos WHERE $condicionesPedidos");
$stmtPedidos->execute($paramsPedidos);
$pedidosPorCliente = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);
if (empty($pedidosPorCliente)) {
    echo json_encode([]);
    exit;
}
$clientePorPedido = [];
foreach ($pedidosPorCliente as $p) $clientePorPedido[(int)$p['id']] = (int)$p['cliente'];

$calc = calcular_lineas_backorder($bdd, null, null, null, null, array_keys($clientePorPedido));
$idsClientesConPendiente = [];
foreach ($calc['lineas'] as $l) {
    if ($l['pendiente'] > 0 && isset($clientePorPedido[(int)$l['id_pedido']])) {
        $idsClientesConPendiente[$clientePorPedido[(int)$l['id_pedido']]] = true;
    }
}

$resultado = array_values(array_filter($candidatos, fn($c) => isset($idsClientesConPendiente[(int)$c['id']])));
echo json_encode(array_slice($resultado, 0, 30));
