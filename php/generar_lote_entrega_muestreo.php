<?php
/**
 * Acción masiva desde lista_muestreo.php?tp=8 (En despacho): marca los
 * muestreos seleccionados como "Entregado" (estado=4). Mismo patrón que
 * php/generar_lote_entrega.php (pedidos con adopción).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function lem_redirect($status, $msg) {
    header('Location: ../lista_muestreo.php?tp=8&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lem_redirect('error', 'No se seleccionó ningún muestreo.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT m.id, c.cod_zona, c.zona_madre
        FROM muestreos m
        JOIN colegios c ON m.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.id = m.id_usuario
        WHERE m.estado = '7' AND m.id IN ($in_ph)
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

if (empty($rows)) lem_redirect('error', 'Ninguno de los muestreos seleccionados está disponible para marcar como entregado.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE muestreos SET estado = '4' WHERE id = ? AND estado = '7'");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'muestreos', $r['id'], 4, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        lem_redirect('error', 'Los muestreos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lem_redirect('error', 'Error al marcar como entregado: ' . $e->getMessage());
}

lem_redirect('ok', $n . ' muestreo(s) marcado(s) como Entregado.');
