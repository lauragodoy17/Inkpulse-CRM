<?php
/**
 * /includes/matching_pedidos_wo.php
 * Verifica a qué pedido del CRM corresponde un documento REM/FV de World Office cuando la OP de
 * ese pedido nunca quedó con "Núm. de documento" asociado (ver includes/backorders_datos.php).
 *
 * El `concepto` de World Office trae dos números escritos a mano por el usuario al crear la OP,
 * independientes entre sí: "OP <año>-<id>" (el id de `ordenes_pedidos`) y "PED <numero>" (lo que
 * el usuario cree que es el pedido). Verificado contra datos reales (2026-09-11, 184 documentos
 * REM/FV ya cacheados en wo_documentos_colocacion): resolver el id de OP a su `id_pedido` real
 * (directo o agrupado, YA existente en el CRM, no texto libre) y compararlo contra el número de
 * "PED" da 155/157 coincidencias exactas (98.7%) — mucho más confiable que cruzar por nombre de
 * colegio en el concepto (82% en la misma muestra, ver memory/project_colocacion_modulo.md para
 * los falsos positivos que ese método ya tuvo). Por eso esta verificación NO usa nombre de colegio
 * en absoluto: si el número de OP no resuelve a un pedido real del CRM, o no coincide con el "PED"
 * del concepto, el documento se deja sin verificar para asignación manual (ver
 * php/sync_backorders_wo_catalogo.php).
 */

function extraer_op_id_de_concepto($concepto) {
    if (!preg_match('/\bOP\.?\s+\d{4}\s*-\s*(\d+)/i', (string)$concepto, $m)) return null;
    return (int)$m[1];
}

function extraer_ped_de_concepto($concepto) {
    if (!preg_match('/\bPED\.?\s+(\d+)/i', (string)$concepto, $m)) return null;
    return (int)$m[1];
}

/**
 * Precarga, una sola vez por sincronización, a qué pedido(s) del CRM apunta cada OP —
 * directamente (`ordenes_pedidos.id_pedido`) o agrupada (`op_pedidos_agrupados`, donde una sola OP
 * puede repartirse entre varios pedidos). Devuelve [id_op => [id_pedido, ...]].
 */
function cargar_pedidos_por_op($bdd) {
    $porOp = [];
    $stmtDirectas = $bdd->query("SELECT id, id_pedido FROM ordenes_pedidos WHERE id_pedido IS NOT NULL AND id_pedido != 0");
    foreach ($stmtDirectas->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porOp[(int)$r['id']][] = (int)$r['id_pedido'];
    }
    $stmtAgrupadas = $bdd->query("SELECT op, id_pedido FROM op_pedidos_agrupados");
    foreach ($stmtAgrupadas->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porOp[(int)$r['op']][] = (int)$r['id_pedido'];
    }
    return $porOp;
}

/**
 * Verifica un documento: extrae OP y PED del concepto, resuelve la OP contra $pedidosPorOp (ver
 * cargar_pedidos_por_op) y confirma que el PED esté entre los pedidos reales de esa OP. Devuelve el
 * id_pedido verificado, o null si no se pudo verificar (OP no encontrada en el concepto, OP sin
 * ningún pedido ligado en el CRM, o el PED no coincide con ninguno de los pedidos de esa OP).
 */
function verificar_pedido_por_op_concepto($concepto, array $pedidosPorOp) {
    $opId = extraer_op_id_de_concepto($concepto);
    $ped = extraer_ped_de_concepto($concepto);
    if ($opId === null || $ped === null) return null;
    $pedidosDeEstaOp = $pedidosPorOp[$opId] ?? [];
    return in_array($ped, $pedidosDeEstaOp, true) ? $ped : null;
}
