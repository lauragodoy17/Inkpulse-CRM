<?php
/**
 * Acción masiva desde lista_pedidos.php?tp=6 (Procesando): pasa los pedidos
 * marcados a estado "Facturación" (estado=6, igual que
 * accion_pedidos.php?facturacion=) y los deja guardados en un lote
 * (lotes_facturacion / lotes_facturacion_pedidos) para que después se puedan
 * enviar por correo a facturación con el botón de lista_pedidos.php?tp=6
 * (ver php/enviar_lote_facturacion.php).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function lf_redirect($status, $msg) {
    header('Location: ../lista_pedidos.php?tp=6&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lf_redirect('error', 'No se seleccionó ningún pedido.');

// Solo pedidos "Procesando" (estado=5, tp=6) y dentro del mismo alcance de
// zona que usa lista_pedidos.php, para no dejar pasar a facturación pedidos
// ajenos al usuario.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, c.cod_zona, c.zona_madre
        FROM pedidos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        WHERE p.estado = '5' AND p.id IN ($in_ph)
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

if (empty($rows)) lf_redirect('error', 'Ninguno de los pedidos seleccionados está disponible para pasar a facturación.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fuera de la transacción: en MySQL todo DDL hace commit implícito, incluso
// CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO lotes_facturacion (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $id_lote = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE pedidos SET estado = '6' WHERE id = ? AND estado = '5'");
    $ins = $bdd->prepare("INSERT INTO lotes_facturacion_pedidos (id_lote, id_pedido) VALUES (?, ?)");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$id_lote, $r['id']]);
            registrar_historial_estado($bdd, 'pedidos', $r['id'], 6, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        lf_redirect('error', 'Los pedidos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lf_redirect('error', 'Error al pasar a facturación: ' . $e->getMessage());
}

lf_redirect('ok', $n . ' pedido(s) pasado(s) a Facturación. Quedaron guardados para enviarlos por correo desde la lista de Facturación.');
