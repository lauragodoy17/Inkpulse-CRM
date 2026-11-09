<?php
/**
 * Edita un despacho ya guardado desde el lápiz de "Despacho registrado" en op_pendiente.php:
 *   - Datos del apartado (fila de op_atendidas): entregado a, guía, fecha, observaciones y,
 *     opcionalmente, un soporte de entrega nuevo.
 *   - Opcionalmente, el documento de World Office de ESE despacho (si se eligió uno nuevo en el
 *     buscador): como el documento es común a todo el despacho, pasan al nuevo TODOS sus
 *     apartados, no solo este. Sirve para cualquier despacho de la OP, no solo el vigente.
 *     Ver despacho_op_cambiar_documento() en includes/despacho_op.php.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_documento_op.php");
require_once("../includes/documentos_wo_usados.php");
require_once("../includes/despacho_op.php");
require_once("../includes/excedentes_despacho.php");

$op_id         = intval($_POST['op'] ?? 0);
$id            = intval($_POST['id'] ?? 0);
$transportista = trim($_POST['transportista'] ?? '');
$guia          = trim($_POST['guia'] ?? '');
$fecha_entrega = trim($_POST['fecha_entrega'] ?? '');
$obs_envio     = trim($_POST['obs_envio'] ?? '');
// Documento nuevo (solo si se eligió uno en el buscador de World Office del modal).
$tipo_doc      = trim($_POST['tipo_doc'] ?? '');
$n_doc         = trim($_POST['n_doc'] ?? '');
$id_wo         = intval($_POST['id_wo'] ?? 0);
$valor         = trim($_POST['valor'] ?? '');

$op = despacho_op_validar_acceso($bdd, $op_id);

if ($id <= 0 || $transportista === '' || $fecha_entrega === '') {
    despacho_op_volver($op_id, false, 'Completa al menos "Entregado a" y la fecha de despacho.');
}
$fecha_ok = DateTime::createFromFormat('Y-m-d', $fecha_entrega);
if (!$fecha_ok || $fecha_ok->format('Y-m-d') !== $fecha_entrega) {
    despacho_op_volver($op_id, false, 'La fecha de despacho no es válida.');
}

$stmt = $bdd->prepare("SELECT id, n_doc, adjunto_envio FROM op_atendidas WHERE id = ? AND opid = ?");
$stmt->execute([$id, $op_id]);
$apartado = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$apartado) despacho_op_volver($op_id, false, 'No se encontró ese apartado del despacho.');

$cambia_documento = $id_wo > 0 && $n_doc !== '' && $n_doc !== $apartado['n_doc'];
if ($cambia_documento && $valor === '') {
    despacho_op_volver($op_id, false, 'Falta el valor del documento nuevo.');
}

// Excedente del documento nuevo frente a lo solicitado (ver includes/excedentes_despacho.php).
$excedente = $cambia_documento ? excedentes_calcular($bdd, $op_id, $id_wo) : ['items' => []];
if ($cambia_documento && excedentes_requiere_confirmacion($excedente, !empty($_POST['confirmar_excedente']))) {
    despacho_op_volver($op_id, false, excedentes_mensaje($excedente) . ' Confirma el excedente para guardarlo.');
}

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_documento_op($bdd); // DDL: antes de abrir la transacción
excedentes_asegurar_tabla($bdd);
crear_tabla_documentos_wo_usados($bdd);

// Soporte nuevo opcional: si no se sube ninguno se conserva el que ya tenía.
$soporte = despacho_op_subir_soporte('adjunto_envio');
if ($soporte === '') $soporte = $apartado['adjunto_envio'];

try {
    $bdd->beginTransaction();

    if ($cambia_documento) {
        $error = despacho_op_cambiar_documento($bdd, $op, $apartado['n_doc'], $tipo_doc, $n_doc, $id_wo, $valor);
        if ($error !== null) {
            $bdd->rollBack();
            despacho_op_volver($op_id, false, $error);
        }
        excedentes_registrar($bdd, $op_id, $id_wo, $n_doc, $excedente, 'editar_despacho', $_SESSION['id']);
    }

    $bdd->prepare("UPDATE op_atendidas
                   SET transportista = ?, guia = ?, fecha_entrega = ?, obs_envio = ?, adjunto_envio = ?, updated_at = NOW()
                   WHERE id = ? AND opid = ?")
        ->execute([$transportista, $guia, $fecha_entrega, $obs_envio, $soporte, $id, $op_id]);

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    despacho_op_volver($op_id, false, 'Error al editar el despacho: ' . $e->getMessage());
}

despacho_op_volver($op_id, true, $cambia_documento
    ? 'Despacho actualizado. Documento cambiado de ' . $apartado['n_doc'] . ' a ' . $n_doc . '.'
    : 'Despacho actualizado.');
