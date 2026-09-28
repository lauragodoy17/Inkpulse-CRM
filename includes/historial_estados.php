<?php
/**
 * Historial compartido de "quién pasó este registro a tal estado y cuándo",
 * usado tanto por pedidos como por muestreo (columna `modulo`) para todos
 * los estados desde Aprobado en adelante (Aprobado, Procesando,
 * Facturación, Entregado/Despachado). Desde 2026-09-28 también guarda la
 * anulación (estado 3) con su `motivo`, incluidas las devoluciones (módulos
 * 'devoluciones', 'devoluciones_prov' y 'devoluciones_v'). Alimentado tanto por las acciones de
 * un solo registro (accion_pedidos.php / accion_muestreo.php) como por las
 * acciones masivas (generar_planilla_procesamiento.php / generar_lote_facturacion.php).
 */

// Debe llamarse ANTES de abrir cualquier transacción: en MySQL todo DDL hace
// commit implícito, incluso CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
function crear_tabla_historial_estados(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS historial_estados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modulo VARCHAR(20) NOT NULL,
        id_registro INT NOT NULL,
        estado INT NOT NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (modulo, id_registro, estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Motivo de anulación/rechazo (2026-09-28): obligatorio al pasar a estado 3 (Anulado) en
    // pedidos, pedidos sin adopción, muestreos y devoluciones; NULL en los demás estados.
    if (!$bdd->query("SHOW COLUMNS FROM historial_estados LIKE 'motivo'")->fetch()) {
        $bdd->exec("ALTER TABLE historial_estados ADD COLUMN motivo VARCHAR(300) NULL AFTER id_usuario");
    }
}

// Estado 3 = Anulado/Rechazado en pedidos, pedidos2 (sin adopción), muestreos y devoluciones.
const ESTADO_ANULADO = 3;
const MOTIVO_ANULACION_MAX = 300;

function registrar_historial_estado(PDO $bdd, $modulo, $id_registro, $estado, $id_usuario, $motivo = null) {
    $stmt = $bdd->prepare("INSERT INTO historial_estados (modulo, id_registro, estado, id_usuario, motivo) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$modulo, intval($id_registro), intval($estado), intval($id_usuario), $motivo]);
}

function obtener_historial_estado(PDO $bdd, $modulo, $id_registro, $estado) {
    $stmt = $bdd->prepare(
        "SELECT h.fecha, h.motivo, CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS nombre
         FROM historial_estados h
         JOIN usuarios u ON u.id = h.id_usuario
         WHERE h.modulo = ? AND h.id_registro = ? AND h.estado = ?
         ORDER BY h.id DESC LIMIT 1"
    );
    $stmt->execute([$modulo, intval($id_registro), intval($estado)]);
    return $stmt->fetch() ?: null;
}

/**
 * Motivo de anulación que llega del formulario (?motivo=...): sin espacios sobrantes y con máximo
 * MOTIVO_ANULACION_MAX caracteres. Devuelve '' si no se escribió (el llamador debe rechazarlo).
 */
function leer_motivo_anulacion($valor) {
    $motivo = trim(preg_replace('/\s+/u', ' ', (string)$valor));
    return mb_substr($motivo, 0, MOTIVO_ANULACION_MAX);
}

/**
 * Aviso rojo con el motivo de anulación para las pantallas de detalle. $info es lo que devuelve
 * obtener_historial_estado(..., ESTADO_ANULADO); las anulaciones hechas antes de que existiera el
 * motivo (o sin registro en el historial) muestran que no hay motivo registrado.
 */
function html_motivo_anulacion($info, $etiqueta = 'Anulado') {
    $motivo = trim((string)($info['motivo'] ?? ''));
    $quien = $info ? ' por <b>' . htmlspecialchars(trim((string)$info['nombre'])) . '</b> el ' . date('d/m/Y \a \l\a\s H:i', strtotime($info['fecha'])) : '';
    return '<div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:10px;padding:12px 16px;margin:0 0 18px;color:#7f1d1d;font-size:.88rem;">'
        . '<div style="font-weight:700;margin-bottom:4px;"><i class="bi bi-x-octagon-fill"></i> ' . htmlspecialchars($etiqueta) . $quien . '</div>'
        . ($motivo !== ''
            ? '<div><b>Motivo:</b> ' . nl2br(htmlspecialchars($motivo)) . '</div>'
            : '<div style="color:#991b1b;font-style:italic;">Sin motivo registrado (se anuló antes de que el sistema pidiera el motivo).</div>')
        . '</div>';
}
