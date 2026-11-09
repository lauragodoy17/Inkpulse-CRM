<?php
/**
 * Corrige un registro del historial de documentos de una OP (por si se
 * buscó/guardó el documento equivocado). Si el registro editado es el más
 * reciente, también sincroniza `ordenes_pedidos` para que el documento
 * "actual" que se ve arriba en op_pendiente.php quede igual al corregido.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_documento_op.php");
require_once("../includes/documentos_wo_usados.php");
require_once("../includes/excedentes_despacho.php");

$id       = intval($_POST['id'] ?? 0);
$op_id    = intval($_POST['op'] ?? 0);
$tipo_doc = trim($_POST['tipo_doc'] ?? '');
$n_doc    = trim($_POST['n_doc'] ?? '');
$id_wo    = trim($_POST['id_wo'] ?? '');
$valor    = trim($_POST['valor'] ?? '');

if ($id <= 0 || $op_id <= 0 || $n_doc === '' || $valor === '') {
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Faltan datos para editar el documento.'));
    exit;
}

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

crear_tabla_historial_documento_op($bdd);
crear_tabla_documentos_wo_usados($bdd);

// No se puede editar un registro cuyo documento actual ya quedó consumido
// por un despacho real (con apartados registrados) — ni para corregirle el
// tipo/valor ni para reemplazarlo por otro.
$id_wo_actual = obtener_id_wo_de_historial($bdd, $id);
if (documento_wo_ya_usado($bdd, $id_wo_actual)) {
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Este documento ya quedó asociado a un despacho y no se puede editar.'));
    exit;
}

// Un documento de World Office solo puede usarse para UN despacho en total.
$otro_despacho = documento_wo_ya_usado($bdd, $id_wo);
if ($otro_despacho) {
    $msg = 'El documento ' . $n_doc . ' ya se usó en el despacho de la OP ' . $otro_despacho['año'] . '-' . $otro_despacho['opid'] . '. Elige otro documento.';
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode($msg));
    exit;
}

// Excedente frente a lo solicitado (ver includes/excedentes_despacho.php): op_pendiente.php avisa
// antes de enviar y manda confirmar_excedente=1 si el usuario acepta.
$excedente = excedentes_calcular($bdd, $op_id, intval($id_wo));
if (excedentes_requiere_confirmacion($excedente, !empty($_POST['confirmar_excedente']))) {
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode(excedentes_mensaje($excedente) . ' Confirma el excedente para guardarlo.'));
    exit;
}
excedentes_asegurar_tabla($bdd);

try {
    $bdd->beginTransaction();

    $era_mas_reciente = id_historial_documento_op_mas_reciente($bdd, $op_id) == $id;

    $upd = $bdd->prepare("UPDATE historial_documento_op SET tipo_doc = ?, n_doc = ?, id_wo = ?, valor = ? WHERE id = ? AND opid = ?");
    $upd->execute([$tipo_doc !== '' ? $tipo_doc : null, $n_doc, $id_wo !== '' ? intval($id_wo) : null, $valor, $id, $op_id]);

    if ($era_mas_reciente) {
        sincronizar_documento_op_desde_historial($bdd, $op_id);
    }

    excedentes_registrar($bdd, $op_id, $id_wo, $n_doc, $excedente, 'editar_historial', $_SESSION['id']);

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode('Error al editar el documento: ' . $e->getMessage()));
    exit;
}

header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=ok&ink_msg=' . urlencode('Registro del historial actualizado.'));
