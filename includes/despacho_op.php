<?php
/**
 * Corrección de un despacho ya guardado en op_pendiente.php (filas de `op_atendidas` del documento
 * vigente de la OP): editar o borrar un apartado, borrar el despacho completo o cambiar su
 * documento de World Office. Lo usan php/despacho_op_editar.php, php/despacho_op_borrar.php y
 * php/despacho_op_cambiar_documento.php.
 *
 * Un "despacho" = todos los apartados de la OP con el mismo n_doc. El documento queda "consumido"
 * en documentos_wo_usados (ver includes/documentos_wo_usados.php) mientras tenga al menos un
 * apartado; al borrar el último se libera, para que se pueda volver a editar/borrar en el
 * historial o usar en otro despacho.
 */

function despacho_op_volver($op_id, $ok, $msg) {
    header('Location: ../op_pendiente.php?op=' . intval($op_id) . '&ink_status=' . ($ok ? 'ok' : 'error') . '&ink_msg=' . urlencode($msg));
    exit;
}

/**
 * Sesión válida y usuario con acceso al módulo de despacho (mismo criterio que op_pendiente.php:
 * asesores/distribuidores (tipo 3/6) y el usuario 69 no pueden tocar el despacho). php/aut.php
 * redirige sin cortar la ejecución, así que aquí se corta explícitamente.
 * Devuelve la OP (id, estado, n_doc, valor, tipo_doc) o termina con error.
 */
function despacho_op_validar_acceso(PDO $bdd, $op_id) {
    if (($_SESSION['autentificado'] ?? '') !== 'SI') {
        header('Location: ../login.php');
        exit;
    }
    $tipo = intval($_SESSION['tipo'] ?? 0);
    $id_usuario = intval($_SESSION['id'] ?? 0);
    if (in_array($tipo, [3, 6], true) || $id_usuario === 69) {
        despacho_op_volver($op_id, false, 'No tienes permiso para modificar despachos.');
    }
    $stmt = $bdd->prepare("SELECT id, estado, n_doc, valor, tipo_doc FROM ordenes_pedidos WHERE id = ?");
    $stmt->execute([intval($op_id)]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$op) despacho_op_volver($op_id, false, 'No se encontró la OP.');
    if ((int)$op['estado'] === 4) despacho_op_volver($op_id, false, 'La OP está anulada: su despacho no se puede modificar.');
    return $op;
}

/** Si ya no queda ningún apartado de ese documento en la OP, deja el documento libre otra vez. */
function despacho_op_liberar_documento_si_vacio(PDO $bdd, $op_id, $n_doc) {
    $stmt = $bdd->prepare("SELECT COUNT(*) FROM op_atendidas WHERE opid = ? AND n_doc = ?");
    $stmt->execute([intval($op_id), $n_doc]);
    if ((int)$stmt->fetchColumn() > 0) return false;
    $del = $bdd->prepare("DELETE FROM documentos_wo_usados WHERE opid = ? AND n_doc = ?");
    $del->execute([intval($op_id), $n_doc]);
    return true;
}

/**
 * Cambia el documento de World Office de UN despacho de la OP (el de $n_doc_anterior): todos sus
 * apartados pasan al documento nuevo (n_doc y valor), el anterior queda libre y el nuevo consumido.
 * Si es el despacho del documento vigente de la OP, también se actualiza ordenes_pedidos y queda
 * constancia en el historial de documentos; si es un despacho anterior, solo cambian sus apartados
 * (el documento actual de la OP no se toca). Debe llamarse DENTRO de una transacción (y con las
 * tablas ya creadas antes de abrirla). Devuelve null si todo bien, o el mensaje de error.
 */
function despacho_op_cambiar_documento(PDO $bdd, array $op, $n_doc_anterior, $tipo_doc, $n_doc, $id_wo, $valor) {
    $op_id = intval($op['id']);
    $es_vigente = (string)$n_doc_anterior === (string)$op['n_doc'];
    $tipo_doc = $tipo_doc !== '' && $tipo_doc !== null ? $tipo_doc : $op['tipo_doc'];

    $otro_despacho = documento_wo_ya_usado($bdd, $id_wo);
    if ($otro_despacho && (int)$otro_despacho['opid'] !== $op_id) {
        return 'El documento ' . $n_doc . ' ya se usó en el despacho de la OP ' . $otro_despacho['año'] . '-' . $otro_despacho['opid'] . '. Elige otro documento.';
    }

    $bdd->prepare("UPDATE op_atendidas SET n_doc = ?, valor = ?, updated_at = NOW() WHERE opid = ? AND n_doc = ?")
        ->execute([$n_doc, (int)round((float)$valor), $op_id, $n_doc_anterior]);
    $bdd->prepare("DELETE FROM documentos_wo_usados WHERE opid = ? AND n_doc = ?")->execute([$op_id, $n_doc_anterior]);
    marcar_documento_wo_usado($bdd, $id_wo, $op_id, $n_doc, $_SESSION['id']);
    if ($es_vigente) {
        $bdd->prepare("UPDATE ordenes_pedidos SET tipo_doc = ?, n_doc = ?, valor = ? WHERE id = ?")
            ->execute([$tipo_doc, $n_doc, $valor, $op_id]);
        registrar_historial_documento_op($bdd, $op_id, $tipo_doc, $n_doc, $id_wo, $valor, $_SESSION['id']);
    }
    return null;
}

/** Sube el soporte de entrega (mismo destino y nombre que php/procesar_op.php). '' si no se envió. */
function despacho_op_subir_soporte($campo) {
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    $nombre = uniqid() . '_' . basename($f['name']);
    $destino = dirname(__DIR__) . '/adjuntos/envio/' . $nombre;
    return move_uploaded_file($f['tmp_name'], $destino) ? $nombre : '';
}
