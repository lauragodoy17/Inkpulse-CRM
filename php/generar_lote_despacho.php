<?php
/**
 * Acción masiva desde lista_pedidos.php?tp=7 (Facturación): pasa los pedidos
 * marcados a estado "En despacho" (estado=7), el tramo que sigue después de
 * Facturación y antes de Entregado. Mismo patrón que
 * generar_lote_facturacion.php pero sin lote/correo: aquí solo cambia el
 * estado y queda registrado en historial_estados (quién y cuándo).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

// En error se vuelve a tp=7 (Facturación, de donde salió la selección y
// donde los pedidos siguen estando). En éxito se manda a tp=8 (En despacho,
// adonde acaban de pasar) para que el usuario los vea de inmediato — antes
// mandaba siempre a tp=7 y los pedidos "desaparecían" de esa lista sin
// indicar a dónde habían ido.
function ld_redirect($status, $msg, $tp = 7) {
    header('Location: ../lista_pedidos.php?tp=' . $tp . '&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) ld_redirect('error', 'No se seleccionó ningún pedido.');

// Solo pedidos "Facturación" (estado=6, tp=7) y dentro del mismo alcance de
// zona que usa lista_pedidos.php, para no dejar pasar a despacho pedidos
// ajenos al usuario.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, c.cod_zona, c.zona_madre
        FROM pedidos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        WHERE p.estado = '6' AND p.id IN ($in_ph)
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

if (empty($rows)) ld_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para pasar a despacho.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE pedidos SET estado = '7' WHERE id = ? AND estado = '6'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'pedidos', $r['id'], 7, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        ld_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    ld_redirect('error', 'Error al pasar a despacho: ' . $e->getMessage());
}

ld_redirect('ok', $n . ' pedido(s) pasado(s) a En despacho.', 8);
