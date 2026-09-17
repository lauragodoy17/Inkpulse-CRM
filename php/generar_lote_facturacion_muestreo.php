<?php
/**
 * Acción masiva desde lista_muestreo.php?tp=6 (Procesando): pasa los
 * muestreos marcados a estado "Facturación" (estado=6, igual que
 * accion_muestreo.php?facturacion=) y los deja guardados en un lote
 * (lotes_facturacion_muestreo / lotes_facturacion_muestreo_pedidos) para
 * enviarlos después por correo con el botón de lista_muestreo.php?tp=7
 * (ver php/enviar_lote_facturacion_muestreo.php). Mismo patrón que
 * php/generar_lote_facturacion.php (pedidos con adopción).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_estados.php");

function lfm_redirect($status, $msg) {
    header('Location: ../lista_muestreo.php?tp=6&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lfm_redirect('error', 'No se seleccionó ningún muestreo.');

$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT m.id, c.cod_zona, c.zona_madre
        FROM muestreos m
        JOIN colegios c ON m.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.id = m.id_usuario
        WHERE m.estado = '5' AND m.id IN ($in_ph)
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

if (empty($rows)) lfm_redirect('error', 'Ninguno de los muestreos seleccionados está disponible para pasar a facturación.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion_muestreo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO lotes_facturacion_muestreo (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $id_lote = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE muestreos SET estado = '6' WHERE id = ? AND estado = '5'");
    $ins = $bdd->prepare("INSERT INTO lotes_facturacion_muestreo_pedidos (id_lote, id_pedido) VALUES (?, ?)");

    $n = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$id_lote, $r['id']]);
            registrar_historial_estado($bdd, 'muestreos', $r['id'], 6, $id_usuario);
            $n++;
        }
    }

    if ($n === 0) {
        $bdd->rollBack();
        lfm_redirect('error', 'Los muestreos seleccionados ya habían cambiado de estado.');
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lfm_redirect('error', 'Error al pasar a facturación: ' . $e->getMessage());
}

lfm_redirect('ok', $n . ' muestreo(s) pasado(s) a Facturación. Quedaron guardados para enviarlos por correo desde la lista de Facturación.');
