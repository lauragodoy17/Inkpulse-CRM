<?php
/**
 * Acción masiva desde lista_pedidos_sa.php?tp=3 (Aprobados): recibe los
 * pedidos sin adopción marcados con checkbox, los pasa a estado "Procesando"
 * (estado=5, igual que accion_pedidos_sa.php?procesar=) y entrega para
 * descargar una planilla en PDF (marcada "Tipo: Sin adopción") con un
 * consecutivo que nunca se repite. Mismo patrón que
 * php/generar_planilla_procesamiento.php (pedidos con adopción), pero
 * pedidos_sa (tabla pedidos2) no tiene restricción de zona: cualquier
 * usuario con acceso al módulo ve/gestiona todos los pedidos SA.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/historial_estados.php");
require_once("../includes/planilla_pdf.php");

function lps_error($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif;color:#b91c1c;padding:40px">' . htmlspecialchars($msg) .
         '</p><p><a href="javascript:history.back()">Volver</a></p>';
    exit;
}

$cols = planilla_pdf_columnas();

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lps_error('No se seleccionó ningún pedido.');

// pedidos2.colegio es texto directo (no hay FK a colegios), y "responsable"
// es el nombre del distribuidor/promotor que hizo el pedido.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, p.fecha, CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS responsable
        FROM pedidos2 p
        JOIN usuarios u ON u.id = p.id_usuario
        WHERE p.estado = '2' AND p.id IN ($in_ph)
        GROUP BY p.id";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) lps_error('Ninguno de los pedidos seleccionados está disponible para procesar.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fuera de la transacción: en MySQL todo DDL hace commit implícito, incluso
// CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_sa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_sa_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_planilla (id_planilla),
    FOREIGN KEY (id_planilla) REFERENCES planillas_procesamiento_sa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

// Todo (cambios de estado + construcción del PDF) queda dentro de la misma
// transacción: si el PDF falla por cualquier motivo, se hace rollback y
// ningún pedido queda a medio procesar sin planilla entregada.
try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO planillas_procesamiento_sa (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $consecutivo = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE pedidos2 SET estado = '5' WHERE id = ? AND estado = '2'");
    $ins = $bdd->prepare("INSERT INTO planillas_procesamiento_sa_pedidos (id_planilla, id_pedido) VALUES (?, ?)");

    $procesados = [];
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$consecutivo, $r['id']]);
            registrar_historial_estado($bdd, 'pedidos_sa', $r['id'], 5, $id_usuario);
            $procesados[] = $r;
        }
    }

    if (empty($procesados)) {
        $bdd->rollBack();
        lps_error('Los pedidos seleccionados ya habían cambiado de estado; no se generó la planilla.');
    }

    $fecha_descarga = date('d/m/Y H:i:s');
    $pdf_contenido  = planilla_pdf_generar($consecutivo, $fecha_descarga, $procesados, $cols, 'Sin adopción');

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lps_error('Error al generar la planilla: ' . $e->getMessage());
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="planilla_procesamiento_sa_' . $consecutivo . '.pdf"');
header('Content-Length: ' . strlen($pdf_contenido));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf_contenido;
