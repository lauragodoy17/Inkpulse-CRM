<?php
/**
 * Acción masiva desde lista_pedidos.php?tp=8 (En despacho): marca los
 * pedidos seleccionados como "Entregado" (estado=4), el último tramo del
 * flujo. Mismo patrón que generar_lote_despacho.php: solo cambia el estado
 * y queda registrado en historial_estados (quién y cuándo).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function le_redirect($status, $msg) {
    header('Location: ../lista_pedidos.php?tp=8&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) le_redirect('error', 'No se seleccionó ningún pedido.');

// Solo pedidos "En despacho" (estado=7, tp=8) y dentro del mismo alcance de
// zona que usa lista_pedidos.php, para no dejar marcar como entregados
// pedidos ajenos al usuario.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, c.cod_zona, c.zona_madre
        FROM pedidos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        WHERE p.estado = '7' AND p.id IN ($in_ph)
        GROUP BY p.id";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (($_SESSION['tipo'] ?? null) != 10) {
    $zona = $_SESSION['zona'] ?? null;
    $rows = array_values(array_filter($rows, function ($r) use ($zona) {
        return $r['cod_zona'] == $zona || $r['zona_madre'] == $zona;
    }));
}

if (empty($rows)) le_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para marcar como entregado.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE pedidos SET estado = '4' WHERE id = ? AND estado = '7'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'pedidos', $r['id'], 4, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        le_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    le_redirect('error', 'Error al marcar como entregado: ' . $e->getMessage());
}

le_redirect('ok', $n . ' pedido(s) marcado(s) como Entregado.');
