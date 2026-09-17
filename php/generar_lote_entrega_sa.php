<?php
/**
 * Acción masiva desde lista_pedidos_sa.php?tp=8 (En despacho): marca los
 * pedidos sin adopción seleccionados como "Entregado" (estado=4). Mismo
 * patrón que php/generar_lote_entrega.php, sin restricción de zona.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function les_redirect($status, $msg) {
    header('Location: ../lista_pedidos_sa.php?tp=8&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) les_redirect('error', 'No se seleccionó ningún pedido.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id FROM pedidos2 p WHERE p.estado = '7' AND p.id IN ($in_ph)";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) les_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para marcar como entregado.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE pedidos2 SET estado = '4' WHERE id = ? AND estado = '7'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'pedidos_sa', $r['id'], 4, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        les_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    les_redirect('error', 'Error al marcar como entregado: ' . $e->getMessage());
}

les_redirect('ok', $n . ' pedido(s) marcado(s) como Entregado.');
