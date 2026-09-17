<?php
/**
 * Acción masiva desde lista_pedidos_sa.php?tp=7 (Facturación): pasa los
 * pedidos sin adopción marcados a estado "En despacho" (estado=7). Mismo
 * patrón que php/generar_lote_despacho.php, sin restricción de zona.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

// En error se vuelve a tp=7 (Facturación, de donde salió la selección). En
// éxito se manda a tp=8 (En despacho, adonde acaban de pasar) para que el
// usuario los vea de inmediato — antes mandaba siempre a tp=7 y los pedidos
// "desaparecían" de esa lista sin indicar a dónde habían ido.
function lds_redirect($status, $msg, $tp = 7) {
    header('Location: ../lista_pedidos_sa.php?tp=' . $tp . '&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lds_redirect('error', 'No se seleccionó ningún pedido.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id FROM pedidos2 p WHERE p.estado = '6' AND p.id IN ($in_ph)";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) lds_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para pasar a despacho.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE pedidos2 SET estado = '7' WHERE id = ? AND estado = '6'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'pedidos_sa', $r['id'], 7, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        lds_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lds_redirect('error', 'Error al pasar a despacho: ' . $e->getMessage());
}

lds_redirect('ok', $n . ' pedido(s) pasado(s) a En despacho.', 8);
