<?php
/**
 * Búsqueda de documentos de World Office para "Registrar despacho" en
 * op_pendiente.php. Dos modos:
 *   - modo=auto: busca por el número de la OP indicada (?op=<id>), tal como
 *     Inkpulse lo escribe en el concepto del documento al generarlo. Si es una
 *     OP de venta directa (OPVD-n), busca "VD-<id>" en el concepto.
 * Busca en facturas, remisiones, devoluciones de remisión, notas crédito y facturas POS (WO_TIPOS_OP_PENDIENTE).
 *   - modo=manual: busca por texto libre (?texto=...) contra el concepto,
 *     para cuando la búsqueda automática no encuentra nada o se quiere
 *     asociar otro documento distinto.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/api_wo_documentos_op.php");

header('Content-Type: application/json; charset=utf-8');

$modo = $_GET['modo'] ?? 'auto';

if ($modo === 'auto') {
    $op_id = intval($_GET['op'] ?? 0);
    if ($op_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'OP inválida.', 'candidatos' => []]);
        exit;
    }
    require_once("../includes/venta_directa_datos.php");
    vd_asegurar_tablas($bdd);
    $stmt = $bdd->prepare("SELECT id, año, id_pedido_vd FROM ordenes_pedidos WHERE id = ?");
    $stmt->execute([$op_id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$op) {
        echo json_encode(['ok' => false, 'error' => 'No se encontró la OP.', 'candidatos' => []]);
        exit;
    }
    if ((int)$op['id_pedido_vd'] > 0) {
        // OP de venta directa: el documento de WO se reconoce por "VD-<id>" en el concepto, no por la OP.
        // Solo se ofrecen los que traen exactamente ese número (VD-15 no es VD-150).
        $vd = (int)$op['id_pedido_vd'];
        $resultado = wo_buscar_documentos_por_texto('VD-' . $vd, WO_TIPOS_OP_PENDIENTE);
        if (!empty($resultado['candidatos'])) {
            $resultado['candidatos'] = array_values(array_filter($resultado['candidatos'],
                fn($c) => in_array($vd, vd_ids_en_concepto($c['concepto'] ?? ''), true)));
        }
    } else {
        $resultado = wo_buscar_documentos_por_op($op['año'], $op['id'], WO_TIPOS_OP_PENDIENTE);
    }
} else {
    $texto = trim($_GET['texto'] ?? '');
    if ($texto === '') {
        echo json_encode(['ok' => false, 'error' => 'Escribe un texto para buscar.', 'candidatos' => []]);
        exit;
    }
    $resultado = wo_buscar_documentos_por_texto($texto, WO_TIPOS_OP_PENDIENTE);
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
