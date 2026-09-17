<?php
/**
 * Acción masiva desde lista_muestreo.php?tp=7 (Facturación): pasa los
 * muestreos marcados a estado "En despacho" (estado=7). Mismo patrón que
 * php/generar_lote_despacho.php (pedidos con adopción).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

// En error se vuelve a tp=7 (Facturación, de donde salió la selección). En
// éxito se manda a tp=8 (En despacho, adonde acaban de pasar) para que el
// usuario los vea de inmediato — antes mandaba siempre a tp=7 y los
// muestreos "desaparecían" de esa lista sin indicar a dónde habían ido.
function ldm_redirect($status, $msg, $tp = 7) {
    header('Location: ../lista_muestreo.php?tp=' . $tp . '&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) ldm_redirect('error', 'No se seleccionó ningún muestreo.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT m.id, c.cod_zona, c.zona_madre
        FROM muestreos m
        JOIN colegios c ON m.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.id = m.id_usuario
        WHERE m.estado = '6' AND m.id IN ($in_ph)
        GROUP BY m.id";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (($_SESSION['tipo'] ?? null) != 10) {
    $zona = $_SESSION['zona'] ?? null;
    $rows = array_values(array_filter($rows, function ($r) use ($zona) {
        return $r['cod_zona'] == $zona || $r['zona_madre'] == $zona;
    }));
}

if (empty($rows)) ldm_redirect('error', 'Ninguno de los muestreos seleccionados está disponible para pasar a despacho.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE muestreos SET estado = '7' WHERE id = ? AND estado = '6'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'muestreos', $r['id'], 7, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        ldm_redirect('error', 'Los muestreos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    ldm_redirect('error', 'Error al pasar a despacho: ' . $e->getMessage());
}

ldm_redirect('ok', $n . ' muestreo(s) pasado(s) a En despacho.', 8);
