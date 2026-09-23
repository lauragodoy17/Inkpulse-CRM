<?php
/**
 * Acción masiva desde lista_muestreo.php?tp=3 (Aprobados): recibe los
 * muestreos marcados con checkbox, los pasa a estado "Procesando" (estado=5,
 * igual que accion_muestreo.php?procesar=) y entrega para descargar una
 * planilla en PDF (marcada "Tipo: Muestreo") con un consecutivo que nunca se
 * repite, pensada para imprimir y firmar de recibido. Mismo patrón que
 * php/generar_planilla_procesamiento.php (pedidos con adopción).
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/historial_estados.php");
require_once("../includes/planilla_pdf.php");

function lpm_error($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif;color:#b91c1c;padding:40px">' . htmlspecialchars($msg) .
         '</p><p><a href="javascript:history.back()">Volver</a></p>';
    exit;
}

$cols = planilla_pdf_columnas();

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (empty($ids)) lpm_error('No se seleccionó ningún muestreo.');

// Solo muestreos "Aprobados" (estado=2, tp=3) y dentro del mismo alcance de
// zona que usa lista_muestreo.php. El JOIN y el cálculo de "responsable"
// replican lista_muestreo_query_parts() (u.tipo IN (1,3), a diferencia de
// pedidos que solo usa u.tipo=3).
// "cliente" (columna Cliente de la planilla): muestreos no tiene cliente, así
// que va el responsable y, si sale vacío, quien hizo el muestreo.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT m.id, m.fecha, c.cod_zona, c.zona_madre,
               CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable,
               COALESCE(NULLIF(TRIM(CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE c.responsable END),''),
                        NULLIF(TRIM(CONCAT_WS(' ',TRIM(u.nombres),TRIM(u.apellidos))),'')) AS cliente
        FROM muestreos m
        JOIN colegios c ON m.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.id = m.id_usuario
        WHERE m.estado = '2' AND m.id IN ($in_ph)
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

if (empty($rows)) lpm_error('Ninguno de los muestreos seleccionados está disponible para procesar.');

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fuera de la transacción: en MySQL todo DDL hace commit implícito, incluso
// CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_muestreo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_muestreo_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_planilla (id_planilla),
    FOREIGN KEY (id_planilla) REFERENCES planillas_procesamiento_muestreo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

// Todo (cambios de estado + construcción del PDF) queda dentro de la misma
// transacción: si el PDF falla por cualquier motivo, se hace rollback y
// ningún muestreo queda a medio procesar sin planilla entregada.
try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $stmt = $bdd->prepare("INSERT INTO planillas_procesamiento_muestreo (id_usuario, cantidad_pedidos) VALUES (?, ?)");
    $stmt->execute([$id_usuario, count($rows)]);
    $consecutivo = intval($bdd->lastInsertId());

    $upd = $bdd->prepare("UPDATE muestreos SET estado = '5' WHERE id = ? AND estado = '2'");
    $ins = $bdd->prepare("INSERT INTO planillas_procesamiento_muestreo_pedidos (id_planilla, id_pedido) VALUES (?, ?)");

    $procesados = [];
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            $ins->execute([$consecutivo, $r['id']]);
            registrar_historial_estado($bdd, 'muestreos', $r['id'], 5, $id_usuario);
            $procesados[] = $r;
        }
    }

    if (empty($procesados)) {
        $bdd->rollBack();
        lpm_error('Los muestreos seleccionados ya habían cambiado de estado; no se generó la planilla.');
    }

    $fecha_descarga = date('d/m/Y H:i:s');
    $pdf_contenido  = planilla_pdf_generar($consecutivo, $fecha_descarga, $procesados, $cols, 'Muestreo');

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lpm_error('Error al generar la planilla: ' . $e->getMessage());
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="planilla_procesamiento_muestreo_' . $consecutivo . '.pdf"');
header('Content-Length: ' . strlen($pdf_contenido));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf_contenido;
