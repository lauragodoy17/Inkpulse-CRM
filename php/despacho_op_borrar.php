<?php
/**
 * Borra un apartado de un despacho ya guardado (id), o el despacho completo del documento vigente
 * de la OP (todo=1). Si ya no queda ningún apartado de ese documento, el documento se libera (ver
 * includes/despacho_op.php). El archivo de soporte se conserva en disco.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/despacho_op.php");

$op_id = intval($_POST['op'] ?? 0);
$id    = intval($_POST['id'] ?? 0);
$todo  = ($_POST['todo'] ?? '') === '1';

$op = despacho_op_validar_acceso($bdd, $op_id);
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $bdd->beginTransaction();
    if ($todo) {
        $n_doc = $op['n_doc'];
        $del = $bdd->prepare("DELETE FROM op_atendidas WHERE opid = ? AND n_doc = ?");
        $del->execute([$op_id, $n_doc]);
        $borrados = $del->rowCount();
    } else {
        $stmt = $bdd->prepare("SELECT n_doc FROM op_atendidas WHERE id = ? AND opid = ?");
        $stmt->execute([$id, $op_id]);
        $n_doc = $stmt->fetchColumn();
        if ($n_doc === false) {
            $bdd->rollBack();
            despacho_op_volver($op_id, false, 'No se encontró ese apartado del despacho.');
        }
        $del = $bdd->prepare("DELETE FROM op_atendidas WHERE id = ? AND opid = ?");
        $del->execute([$id, $op_id]);
        $borrados = $del->rowCount();
    }
    $liberado = despacho_op_liberar_documento_si_vacio($bdd, $op_id, $n_doc);
    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    despacho_op_volver($op_id, false, 'Error al borrar el despacho: ' . $e->getMessage());
}

if ($borrados === 0) despacho_op_volver($op_id, false, 'No había apartados para borrar.');
$msg = $todo ? 'Despacho borrado.' : 'Apartado borrado.';
if ($liberado) $msg .= ' El documento ' . $n_doc . ' quedó libre: ya se puede editar o borrar del historial.';
despacho_op_volver($op_id, true, $msg);
