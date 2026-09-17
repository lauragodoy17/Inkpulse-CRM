<?php
/**
 * Acción masiva desde lista_pedidos_sa.php?tp=6 (Procesando): pasa los
 * pedidos sin adopción marcados a estado "Facturación" (estado=6) y los deja
 * guardados en un lote (lotes_facturacion_sa / lotes_facturacion_sa_pedidos)
 * para enviarlos después por correo con el botón de
 * lista_pedidos_sa.php?tp=7 (ver php/enviar_lote_facturacion_sa.php). Mismo
 * patrón que php/generar_lote_facturacion.php, sin restricción de zona.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function lfs_redirect($status, $msg) {
    header('Location: ../lista_pedidos_sa.php?tp=6&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lfs_redirect('error', 'No se seleccionó ningún pedido.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id FROM pedidos2 p WHERE p.estado = '5' AND p.id IN ($in_ph)";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) lfs_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para pasar a facturación.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_sa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_sa_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion_sa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO lotes_facturacion_sa (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $id_lote = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE pedidos2 SET estado = '6' WHERE id = ? AND estado = '5'");
    $ins = $bdd->prepare("INSERT INTO lotes_facturacion_sa_pedidos (id_lote, id_pedido) VALUES (?, ?)");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$id_lote, $r['id']]);
            registrar_historial_estado($bdd, 'pedidos_sa', $r['id'], 6, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        lfs_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lfs_redirect('error', 'Error al pasar a facturación: ' . $e->getMessage());
}

lfs_redirect('ok', $n . ' pedido(s) pasado(s) a Facturación. Quedaron guardados para enviarlos por correo desde la lista de Facturación.');
