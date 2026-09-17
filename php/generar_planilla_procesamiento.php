<?php
/**
 * Acción masiva desde lista_pedidos.php?tp=3 (Aprobados): recibe los pedidos
 * marcados con checkbox, los pasa a estado "Procesando" (estado=5, igual que
 * accion_pedidos.php?procesar=) y entrega para descargar una planilla en PDF
 * con un consecutivo que nunca se repite (el id autoincremental de
 * planillas_procesamiento), pensada para imprimir y firmar de recibido.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/historial_estados.php");
require_once("../includes/planilla_pdf.php");

function lp_error($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif;color:#b91c1c;padding:40px">' . htmlspecialchars($msg) .
         '</p><p><a href="javascript:history.back()">Volver</a></p>';
    exit;
}

$cols = planilla_pdf_columnas();

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lp_error('No se seleccionó ningún pedido.');

// Solo pedidos "Aprobados" (estado=2, tp=3) y dentro del mismo alcance de zona
// que usa lista_pedidos.php, para no dejar procesar pedidos ajenos al usuario.
// El JOIN a zonas/usuarios y el cálculo de "responsable" replican exactamente
// lista_pedidos_query_parts()/ajax/lista_pedidos_data.php, para que la
// columna "Cliente" de la planilla muestre lo mismo que la columna
// "Responsable" de la lista (puede ser el promotor o el responsable del
// colegio, según el tipo de usuario de la zona).
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, p.fecha, c.cod_zona, c.zona_madre,
               CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable
        FROM pedidos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        WHERE p.estado = '2' AND p.id IN ($in_ph)
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

if (empty($rows)) lp_error('Ninguno de los pedidos seleccionados está disponible para procesar.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Las CREATE TABLE deben ir fuera de la transacción: en MySQL todo DDL hace
// commit implícito (incluso CREATE TABLE IF NOT EXISTS cuando la tabla ya
// existe), lo que cerraría la transacción antes de tiempo si quedara dentro.
$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_planilla (id_planilla),
    FOREIGN KEY (id_planilla) REFERENCES planillas_procesamiento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

// Todo (cambios de estado + construcción del PDF) queda dentro de la misma
// transacción: si el PDF falla por cualquier motivo, se hace rollback y
// ningún pedido queda a medio procesar sin planilla entregada.
try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO planillas_procesamiento (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $consecutivo = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE pedidos SET estado = '5' WHERE id = ? AND estado = '2'");
    $ins = $bdd->prepare("INSERT INTO planillas_procesamiento_pedidos (id_planilla, id_pedido) VALUES (?, ?)");

    $procesados = [];
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$consecutivo, $r['id']]);
            registrar_historial_estado($bdd, 'pedidos', $r['id'], 5, $id_usuario);
            $procesados[] = $r;
        }
    }

    if (empty($procesados)) {
        $bdd->rollBack();
        lp_error('Los pedidos seleccionados ya habían cambiado de estado; no se generó la planilla.');
    }

    $fecha_descarga = date('d/m/Y H:i:s');
    $pdf_contenido  = planilla_pdf_generar($consecutivo, $fecha_descarga, $procesados, $cols, 'Con adopción');

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lp_error('Error al generar la planilla: ' . $e->getMessage());
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="planilla_procesamiento_' . $consecutivo . '.pdf"');
header('Content-Length: ' . strlen($pdf_contenido));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf_contenido;
