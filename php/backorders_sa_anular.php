<?php
/**
 * Anula un backorder sin adopción (con motivo obligatorio). No borra nada: queda marcado como
 * anulado con fecha, usuario y motivo, y sus atenciones/pedidos anteriores se conservan. Ver
 * includes/backorders_sa_datos.php.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

function bsa_volver_anular($ok, $msg) {
    header('Location: ../backorders_sa.php?ink_status=' . ($ok ? 'ok' : 'error') . '&ink_msg=' . urlencode($msg));
    exit;
}

if (!bsa_tiene_acceso()) { header('Location: ../index.php'); exit; }

$id = intval($_POST['id'] ?? 0);
$motivo = trim((string)($_POST['motivo'] ?? ''));
if ($id <= 0) bsa_volver_anular(false, 'Backorder inválido.');
if ($motivo === '') bsa_volver_anular(false, 'Escribe el motivo de la anulación.');
if (mb_strlen($motivo) > 1000) bsa_volver_anular(false, 'El motivo no puede superar 1000 caracteres.');

bsa_asegurar_tablas($bdd);
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $bdd->beginTransaction();
    $bdd->prepare("SELECT id FROM backorders_sa WHERE id = ? FOR UPDATE")->execute([$id]);
    $bo = bsa_listar($bdd, ['id' => $id])['filas'][0] ?? null;
    if (!$bo) { $bdd->rollBack(); bsa_volver_anular(false, 'No se encontró el backorder.'); }
    if ($bo['estado'] === 'anulado') { $bdd->rollBack(); bsa_volver_anular(false, 'El backorder #' . $id . ' ya estaba anulado.'); }
    if ($bo['estado'] === 'atendido') { $bdd->rollBack(); bsa_volver_anular(false, 'El backorder #' . $id . ' ya está atendido por completo: no hay nada que anular.'); }

    $bdd->prepare("UPDATE backorders_sa SET anulado = 1, fecha_anulacion = NOW(), id_usuario_anulacion = ?, motivo_anulacion = ? WHERE id = ?")
        ->execute([intval($_SESSION['id']), $motivo, $id]);
    bsa_registrar_historial($bdd, $id, 'anulado',
        'Backorder anulado (estaba ' . mb_strtolower($bo['estado_label'], 'UTF-8') . ', con ' . $bo['cantidad_pendiente'] . ' pendientes). Motivo: ' . $motivo);
    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    bsa_volver_anular(false, 'Error al anular el backorder: ' . $e->getMessage());
}

bsa_volver_anular(true, 'Backorder #' . $id . ' anulado.');
