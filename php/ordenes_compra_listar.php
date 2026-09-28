<?php
/**
 * /php/ordenes_compra_listar.php
 * Todas las órdenes de compra (documentoTipo "OC") de World Office para ordenes_compra.php.
 * Se recorren las páginas del servicio (100 por página, hasta ORDENES_COMPRA_MAX_PAGINAS) y se
 * devuelven juntas: la tabla pagina, ordena y busca en el navegador (DataTables), sin cambiar
 * los datos que manda World Office. Solo lectura.
 */
require_once("aut.php");
header('Content-Type: application/json');

if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.', 'data' => []]);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/ordenes_compra_datos.php");
set_time_limit(120);

const ORDENES_COMPRA_POR_PAGINA = 100;
const ORDENES_COMPRA_MAX_PAGINAS = 50;

$filas = [];
$total = null;
for ($pagina = 0; $pagina < ORDENES_COMPRA_MAX_PAGINAS; $pagina++) {
    $resp = listar_ordenes_compra_wo($pagina, ORDENES_COMPRA_POR_PAGINA);
    $data = $resp['data'] ?? null;
    // hacer_peticion_api_ventas() marca OK todo JSON válido, incluso un error del servicio:
    // la respuesta buena es la que trae `content`.
    if ($resp['status'] !== 'OK' || !is_array($data) || !array_key_exists('content', $data)) {
        $detalle = $resp['mensaje_interno'] ?? ($data['detail'] ?? $data['message'] ?? $data['title'] ?? '');
        echo json_encode(['ok' => false, 'error' => 'No se pudo consultar World Office' . ($detalle !== '' ? ": $detalle" : '.'), 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $total = (int)($data['totalElements'] ?? 0);
    foreach ($data['content'] as $row) {
        // Todos los campos de WO tal cual (para la futura vista de detalle) + dos calculados.
        $row['numero_completo'] = trim(($row['prefijo'] ?? '') . ' ' . ($row['numero'] ?? ''));
        $row['fecha_iso'] = (string)oc_fecha_iso($row['fecha'] ?? '');
        $filas[] = $row;
    }
    if (!empty($data['last']) || empty($data['content'])) break;
}

// Estado de recepción registrado en el CRM; sin recepciones, "pendiente de recepción".
oc_crear_tablas($bdd);
$estados = oc_estados_recepcion($bdd, array_column($filas, 'id'));
foreach ($filas as &$f) {
    $f['estado_recepcion'] = $estados[(int)$f['id']] ?? 'pendiente';
    $f['estado_recepcion_label'] = OC_ESTADOS_ORDEN[$f['estado_recepcion']];
}
unset($f);

echo json_encode([
    'ok' => true,
    'error' => null,
    'total' => $total,
    // Si WO tiene más de lo que se alcanzó a traer, la pantalla lo avisa.
    'incompleto' => $total !== null && count($filas) < $total,
    'data' => $filas,
], JSON_UNESCAPED_UNICODE);
