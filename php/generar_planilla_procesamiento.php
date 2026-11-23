<?php
/**
 * Acción masiva desde lista_pedidos.php?tp=3 (Aprobados): recibe los pedidos
 * marcados con checkbox, los pasa a estado "Procesando" (estado=5, igual que
 * accion_pedidos.php?procesar=) y entrega para descargar una planilla en PDF
 * con un consecutivo que nunca se repite (el id autoincremental de
 * planillas_procesamiento), pensada para imprimir y firmar de recibido.
 *
 * 2026-11-18: los pedidos de venta directa (ver $ids_vd) salen en una planilla aparte, tipo
 * "Pedidos venta directa", con su propio consecutivo (planillas_procesamiento_vd). Como pueden ser
 * dos PDF, la respuesta es JSON (lista_pedidos.php descarga cada PDF y muestra la alerta):
 *   {ok: true, planillas: [{consecutivo, tipo, label, cantidad, archivo, pdf_b64}]}
 *   {ok: false, error: "..."}
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../lib/FPDF/fpdf.php");
require_once("../includes/historial_estados.php");
require_once("../includes/planilla_pdf.php");
require_once("../includes/sobrecosto_canal.php");      // canal "Venta directa - Eureka"
require_once("../includes/pedidos_venta_directa.php"); // pedidos.para_andrea

function lp_json($datos) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function lp_error($msg) {
    lp_json(['ok' => false, 'error' => $msg]);
}

$cols = planilla_pdf_columnas();

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? explode(',', $_POST['ids_csv'] ?? '')))));
if (empty($ids)) lp_error('No se seleccionó ningún pedido.');

// Solo pedidos "Aprobados" (estado=2, tp=3) y dentro del mismo alcance de zona
// que usa lista_pedidos.php, para no dejar procesar pedidos ajenos al usuario.
// El JOIN a zonas/usuarios y el cálculo de "responsable" replican exactamente
// lista_pedidos_query_parts()/ajax/lista_pedidos_data.php, para que la
// columna "Cliente" de la planilla muestre lo mismo que la columna
// "Responsable" de la lista (puede ser el promotor o el responsable del
// colegio, según el tipo de usuario de la zona).
// "cliente" es lo que se imprime en la columna Cliente de la planilla: el
// cliente del pedido y, si no tiene, el responsable; si el responsable sale
// vacío (colegio sin responsable en una zona cuyo usuario no es tipo 3), el
// usuario de la zona y por último quien hizo el pedido — nunca queda en blanco.
$in_ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT p.id, p.fecha, c.cod_zona, c.zona_madre,
               CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable,
               COALESCE(NULLIF(TRIM(cl.cliente),''),
                        NULLIF(TRIM(CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE c.responsable END),''),
                        NULLIF(TRIM(CONCAT_WS(' ',TRIM(u.nombres),TRIM(u.apellidos))),''),
                        NULLIF(TRIM(CONCAT_WS(' ',TRIM(uc.nombres),TRIM(uc.apellidos))),'')) AS cliente
        FROM pedidos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN zonas z ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        LEFT JOIN clientes cl ON cl.id = p.cliente
        LEFT JOIN usuarios uc ON uc.id = p.id_usuario
        WHERE p.estado = '2' AND p.id IN ($in_ph)
        GROUP BY p.id";
$req = $bdd->prepare($sql);
$req->execute($ids);
$rows = $req->fetchAll(PDO::FETCH_ASSOC);

if (($_SESSION['tipo'] ?? null) == 10) {
    $zona = $_SESSION['zona'] ?? null;
    $rows = array_values(array_filter($rows, function ($r) use ($zona) {
        return $r['cod_zona'] == $zona || $r['zona_madre'] == $zona;
    }));
}

if (empty($rows)) lp_error('Ninguno de los pedidos seleccionados está disponible para procesar.');

// Pedidos de venta directa: mismo criterio que dvd_sql_canal_vd() (includes/devoluciones_vd_datos.php),
// pedido marcado para jornada de venta en el colegio (para_andrea) o colegio cuya adopción del período
// del pedido tiene el canal "Venta directa - Eureka". Antes de la transacción: estas funciones hacen DDL.
sobrecosto_asegurar_columna($bdd);
asegurar_columnas_pedido_vd($bdd);
$ids_rows = array_column($rows, 'id');
$req_vd = $bdd->prepare("SELECT x.id FROM pedidos x
        WHERE x.id IN (" . implode(',', array_fill(0, count($ids_rows), '?')) . ")
          AND (x.para_andrea = 1 OR EXISTS (SELECT 1 FROM recursos r WHERE r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo AND " . sql_adopcion_tiene_canal([canal_andrea_id($bdd)]) . "))");
$req_vd->execute($ids_rows);
$ids_vd = array_flip(array_map('intval', $req_vd->fetchAll(PDO::FETCH_COLUMN)));

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

// Planillas de venta directa: tablas propias para que lleven su propio consecutivo.
$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_vd (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS planillas_procesamiento_vd_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_planilla (id_planilla),
    FOREIGN KEY (id_planilla) REFERENCES planillas_procesamiento_vd(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

crear_tabla_historial_estados($bdd);

// Todo (cambios de estado + construcción del PDF) queda dentro de la misma
// transacción: si el PDF falla por cualquier motivo, se hace rollback y
// ningún pedido queda a medio procesar sin planilla entregada.
try {
    $bdd->beginTransaction();

    $id_usuario = intval($_SESSION['id'] ?? 0);
    $upd = $bdd->prepare("UPDATE pedidos SET estado = '5' WHERE id = ? AND estado = '2'");

    // Primero se pasan a Procesando; luego cada grupo que haya quedado con pedidos recibe su
    // propia planilla, con el consecutivo de su propia tabla.
    $grupos = [
        'venta' => ['label' => 'Con adopción',          'tabla' => 'planillas_procesamiento',    'archivo' => 'planilla_procesamiento_',    'pedidos' => []],
        'vd'    => ['label' => 'Pedidos venta directa', 'tabla' => 'planillas_procesamiento_vd', 'archivo' => 'planilla_procesamiento_vd_', 'pedidos' => []],
    ];
    foreach ($rows as $r) {
        $upd->execute([$r['id']]);
        if ($upd->rowCount() > 0) {
            registrar_historial_estado($bdd, 'pedidos', $r['id'], 5, $id_usuario);
            $grupos[isset($ids_vd[(int)$r['id']]) ? 'vd' : 'venta']['pedidos'][] = $r;
        }
    }

    if (empty($grupos['venta']['pedidos']) && empty($grupos['vd']['pedidos'])) {
        $bdd->rollBack();
        lp_error('Los pedidos seleccionados ya habían cambiado de estado; no se generó la planilla.');
    }

    $fecha_descarga = date('d/m/Y H:i:s');
    $planillas = [];
    foreach ($grupos as $tipo => $g) {
        if (empty($g['pedidos'])) continue;
        $bdd->prepare("INSERT INTO {$g['tabla']} (id_usuario, cantidad_pedidos) VALUES (?, ?)")
            ->execute([$id_usuario, count($g['pedidos'])]);
        $consecutivo = intval($bdd->lastInsertId());
        $ins = $bdd->prepare("INSERT INTO {$g['tabla']}_pedidos (id_planilla, id_pedido) VALUES (?, ?)");
        foreach ($g['pedidos'] as $r) $ins->execute([$consecutivo, $r['id']]);

        $planillas[] = [
            'consecutivo' => str_pad($consecutivo, 6, '0', STR_PAD_LEFT),
            'tipo'        => $tipo,
            'label'       => $g['label'],
            'cantidad'    => count($g['pedidos']),
            'archivo'     => $g['archivo'] . $consecutivo . '.pdf',
            'pdf_b64'     => base64_encode(planilla_pdf_generar($consecutivo, $fecha_descarga, $g['pedidos'], $cols, $g['label'])),
        ];
    }

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    lp_error('Error al generar la planilla: ' . $e->getMessage());
}

lp_json(['ok' => true, 'planillas' => $planillas]);
