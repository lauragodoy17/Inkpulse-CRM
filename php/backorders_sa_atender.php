<?php
/**
 * Atiende (total o parcialmente) un backorder sin adopción: solo REGISTRA la cantidad atendida,
 * sin generar ningún pedido (a pedido de la usuaria 2026-09-29). La cantidad se descuenta del
 * saldo pendiente; al completarse lo solicitado el backorder pasa a "Atendido" y sale de la lista
 * de backorders_sa.php (sigue en el informe). La cantidad nunca puede superar lo pendiente: se
 * valida con el backorder bloqueado (FOR UPDATE), así dos envíos simultáneos no pueden atender dos
 * veces lo mismo. Ver includes/backorders_sa_datos.php.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

class BsaValidacionException extends Exception {}

function bsa_volver_atender($ok, $msg) {
    header('Location: ../backorders_sa.php?ink_status=' . ($ok ? 'ok' : 'error') . '&ink_msg=' . urlencode($msg));
    exit;
}

if (!bsa_tiene_acceso()) { header('Location: ../index.php'); exit; }

$id = intval($_POST['id'] ?? 0);
$cantidadTxt = trim((string)($_POST['cantidad'] ?? ''));
$observaciones = trim((string)($_POST['observaciones'] ?? ''));

if ($id <= 0) bsa_volver_atender(false, 'Backorder inválido.');
if (!ctype_digit($cantidadTxt) || (int)$cantidadTxt <= 0) bsa_volver_atender(false, 'La cantidad a atender debe ser un número entero mayor que cero.');
$cantidad = (int)$cantidadTxt;
if (mb_strlen($observaciones) > 1000) bsa_volver_atender(false, 'Las observaciones no pueden superar 1000 caracteres.');

bsa_asegurar_tablas($bdd);
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $bdd->beginTransaction();

    $bdd->prepare("SELECT id FROM backorders_sa WHERE id = ? FOR UPDATE")->execute([$id]);
    $bo = bsa_listar($bdd, ['id' => $id])['filas'][0] ?? null;
    if (!$bo) throw new BsaValidacionException('No se encontró el backorder.');
    if ($bo['estado'] === 'anulado') throw new BsaValidacionException('El backorder #' . $id . ' está anulado.');
    if ($bo['cantidad_pendiente'] <= 0) throw new BsaValidacionException('El backorder #' . $id . ' ya no tiene cantidad pendiente.');
    if ($cantidad > $bo['cantidad_pendiente']) {
        throw new BsaValidacionException('La cantidad a atender (' . $cantidad . ') supera lo pendiente (' . $bo['cantidad_pendiente'] . ').');
    }

    $bdd->prepare("INSERT INTO backorders_sa_atenciones (id_backorder, id_pedido2, cantidad, pendiente_antes, observaciones, fecha, id_usuario)
                   VALUES (?, NULL, ?, ?, ?, NOW(), ?)")
        ->execute([$id, $cantidad, $bo['cantidad_pendiente'], $observaciones !== '' ? $observaciones : null, intval($_SESSION['id'])]);

    $pendienteDespues = $bo['cantidad_pendiente'] - $cantidad;
    $estadoDespues = bsa_estado($bo['cantidad_solicitada'], $bo['cantidad_atendida'] + $cantidad, false);
    bsa_registrar_historial($bdd, $id, 'atencion',
        'Atendidas ' . $cantidad . ' unidades. Pendiente: ' . $bo['cantidad_pendiente'] . ' → ' . $pendienteDespues
        . ($estadoDespues !== $bo['estado'] ? '. Estado: ' . $bo['estado_label'] . ' → ' . BSA_ESTADOS[$estadoDespues] : '') . '.'
        . ($observaciones !== '' ? ' Observaciones: ' . $observaciones : ''));

    $bdd->commit();
} catch (BsaValidacionException $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    bsa_volver_atender(false, $e->getMessage());
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    bsa_volver_atender(false, 'Error al atender el backorder: ' . $e->getMessage());
}

bsa_volver_atender(true, $pendienteDespues > 0
    ? 'Backorder #' . $id . ': atendidas ' . $cantidad . ' unidades, quedan ' . $pendienteDespues . ' pendientes.'
    : 'Backorder #' . $id . ' atendido por completo.');
