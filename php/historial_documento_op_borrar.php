<?php
/**
 * Borra un registro del historial de documentos de una OP. Si era el más
 * reciente, `ordenes_pedidos` se sincroniza con el que quede de último
 * (o se limpia si no queda ninguno), para que el documento "actual" no
 * quede apuntando a uno que ya no está en el historial.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_documento_op.php");
require_once("../includes/documentos_wo_usados.php");

$id    = intval($_POST['id'] ?? 0);
$op_id = intval($_POST['op'] ?? 0);

if ($id <= 0 || $op_id <= 0) {
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Registro inválido.'));
    exit;
}

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

crear_tabla_documentos_wo_usados($bdd);

// No se puede borrar un registro cuyo documento ya quedó consumido por un
// despacho real (con apartados registrados) — perdería el rastro de qué
// documento se usó para ese despacho.
$id_wo_actual = obtener_id_wo_de_historial($bdd, $id);
if (documento_wo_ya_usado($bdd, $id_wo_actual)) {
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Este documento ya quedó asociado a un despacho y no se puede borrar.'));
    exit;
}

try {
    $bdd->beginTransaction();

    $era_mas_reciente = id_historial_documento_op_mas_reciente($bdd, $op_id) == $id;

    $del = $bdd->prepare("DELETE FROM historial_documento_op WHERE id = ? AND opid = ?");
    $del->execute([$id, $op_id]);

    if ($era_mas_reciente) {
        $quedan = id_historial_documento_op_mas_reciente($bdd, $op_id);
        if ($quedan) {
            sincronizar_documento_op_desde_historial($bdd, $op_id);
        } else {
            $upd = $bdd->prepare("UPDATE ordenes_pedidos SET n_doc = '', valor = 0 WHERE id = ?");
            $upd->execute([$op_id]);
        }
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Error al borrar el registro: ' . $e->getMessage()));
    exit;
}

header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=ok&ink_msg=' . urlencode('Registro del historial borrado.'));
