<?php
/**
 * Historial compartido de "quién pasó este registro a tal estado y cuándo",
 * usado tanto por pedidos como por muestreo (columna `modulo`) para todos
 * los estados desde Aprobado en adelante (Aprobado, Procesando,
 * Facturación, Entregado/Despachado). Alimentado tanto por las acciones de
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
}

function registrar_historial_estado(PDO $bdd, $modulo, $id_registro, $estado, $id_usuario) {
    $stmt = $bdd->prepare("INSERT INTO historial_estados (modulo, id_registro, estado, id_usuario) VALUES (?, ?, ?, ?)");
    $stmt->execute([$modulo, intval($id_registro), intval($estado), intval($id_usuario)]);
}

function obtener_historial_estado(PDO $bdd, $modulo, $id_registro, $estado) {
    $stmt = $bdd->prepare(
        "SELECT h.fecha, CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS nombre
         FROM historial_estados h
         JOIN usuarios u ON u.id = h.id_usuario
         WHERE h.modulo = ? AND h.id_registro = ? AND h.estado = ?
         ORDER BY h.id DESC LIMIT 1"
    );
    $stmt->execute([$modulo, intval($id_registro), intval($estado)]);
    return $stmt->fetch() ?: null;
}
