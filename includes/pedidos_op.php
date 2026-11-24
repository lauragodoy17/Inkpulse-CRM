<?php
/**
 * includes/pedidos_op.php
 * OP obligatoria para pasar un pedido de Aprobado a Procesando (2026-11-24, la usuaria): sin una OP
 * activa el pedido no se deja procesar, ni desde el botón del pedido (php/accion_pedidos.php?procesar=)
 * ni desde la planilla de procesamiento masiva (php/generar_planilla_procesamiento.php).
 * OP activa = ordenes_pedidos no anulada (estado <> 4) del pedido (id_pedido) o una OP agrupada que lo
 * incluye (op_pedidos_agrupados), el mismo criterio de dvd_ops_pedido().
 */

/** De estos pedidos, los que NO tienen OP activa: [id_pedido, ...] (ordenados). */
function pedidos_sin_op(PDO $bdd, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $bdd->prepare("SELECT o.id_pedido FROM ordenes_pedidos o WHERE o.estado <> 4 AND o.id_pedido IN ($ph)
                         UNION
                         SELECT a.id_pedido FROM op_pedidos_agrupados a JOIN ordenes_pedidos o ON o.id = a.op
                         WHERE o.estado <> 4 AND a.id_pedido IN ($ph)");
    $st->execute(array_merge($ids, $ids));
    $conOp = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $sin = array_values(array_filter($ids, fn($id) => !isset($conOp[$id])));
    sort($sin);
    return $sin;
}

/** ¿El pedido tiene OP activa? */
function pedido_tiene_op(PDO $bdd, $idPedido) {
    return !pedidos_sin_op($bdd, [(int)$idPedido]);
}
