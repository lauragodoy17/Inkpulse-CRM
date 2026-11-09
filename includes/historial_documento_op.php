<?php
/**
 * Historial de documentos (factura/remisión) asignados a una OP. El campo
 * n_doc/valor en `ordenes_pedidos` es ÚNICO por OP y se sobrescribe cada vez
 * que se guarda uno nuevo desde op_pendiente.php (buscador de World Office o
 * a mano) — sin esta tabla, el documento anterior se perdía al reemplazarlo.
 * Aquí queda un registro por cada cambio, sin impedir seguir reemplazando el
 * documento actual las veces que haga falta.
 */

// Debe llamarse ANTES de abrir cualquier transacción: en MySQL todo DDL hace
// commit implícito, incluso CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
function crear_tabla_historial_documento_op(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS historial_documento_op (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opid INT NOT NULL,
        tipo_doc INT NULL,
        n_doc VARCHAR(50) NOT NULL,
        id_wo INT NULL,
        valor DECIMAL(14,2) NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_op (opid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // `id_wo` (el id interno del documento en World Office, para poder
    // consultar sus ítems con el botón "Ver") se agregó después de crear la
    // tabla por primera vez — en instalaciones donde ya existía sin esta
    // columna, se agrega aparte para no perder los registros ya guardados.
    $col = $bdd->query("SHOW COLUMNS FROM historial_documento_op LIKE 'id_wo'")->fetch();
    if (!$col) {
        $bdd->exec("ALTER TABLE historial_documento_op ADD COLUMN id_wo INT NULL AFTER n_doc");
    }
}

function registrar_historial_documento_op(PDO $bdd, $opid, $tipo_doc, $n_doc, $id_wo, $valor, $id_usuario) {
    $stmt = $bdd->prepare(
        "INSERT INTO historial_documento_op (opid, tipo_doc, n_doc, id_wo, valor, id_usuario) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        intval($opid),
        $tipo_doc !== null && $tipo_doc !== '' ? intval($tipo_doc) : null,
        $n_doc,
        $id_wo !== null && $id_wo !== '' ? intval($id_wo) : null,
        $valor !== '' && $valor !== null ? $valor : null,
        intval($id_usuario),
    ]);
}

function obtener_historial_documento_op(PDO $bdd, $opid) {
    $stmt = $bdd->prepare(
        "SELECT h.id, h.tipo_doc, h.n_doc, h.id_wo, h.valor, h.fecha, t.tipo,
                CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS usuario
         FROM historial_documento_op h
         LEFT JOIN tipo_doc t ON t.id = h.tipo_doc
         JOIN usuarios u ON u.id = h.id_usuario
         WHERE h.opid = ?
         ORDER BY h.id DESC"
    );
    $stmt->execute([intval($opid)]);
    return $stmt->fetchAll();
}

/**
 * El registro más reciente del historial es el que refleja el documento
 * "actual" de la OP en `ordenes_pedidos`. Editar o borrar justo ese
 * registro debe sincronizar ordenes_pedidos para que no queden
 * desactualizados entre sí; tocar uno más viejo del historial solo corrige
 * el registro, sin afectar el documento vigente.
 */
function id_historial_documento_op_mas_reciente(PDO $bdd, $opid) {
    $stmt = $bdd->prepare("SELECT id FROM historial_documento_op WHERE opid = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([intval($opid)]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Compara el documento/valor/tipo que ya tenía la OP contra lo que se va a
 * guardar ahora, normalizando el formato antes de comparar. Sin esto,
 * comparar como texto plano ("453600.00" que trae `ordenes_pedidos.valor`
 * como DECIMAL contra "453600" recién enviado por el formulario) marcaba
 * "cambio" aunque fuera exactamente el mismo valor — eso era lo que llenaba
 * el historial de registros duplicados cada vez que se volvía a guardar el
 * mismo documento sin haber cambiado nada.
 */
function documento_op_cambio($prev, $tipo_doc_nuevo, $n_doc_nuevo, $valor_nuevo) {
    if (!$prev) return true;

    $n_doc_prev = trim((string)($prev['n_doc'] ?? ''));
    $n_doc_nuevo = trim((string)$n_doc_nuevo);

    $valor_prev = number_format((float)($prev['valor'] ?? 0), 2, '.', '');
    $valor_nuevo = number_format((float)$valor_nuevo, 2, '.', '');

    $tipo_prev = (int)($prev['tipo_doc'] ?? 0);
    $tipo_nuevo = (int)($tipo_doc_nuevo !== '' && $tipo_doc_nuevo !== null ? $tipo_doc_nuevo : 0);

    return $n_doc_prev !== $n_doc_nuevo || $valor_prev !== $valor_nuevo || $tipo_prev !== $tipo_nuevo;
}

/**
 * id_wo que tiene guardado un registro específico del historial, para poder
 * comprobar (vía documento_wo_ya_usado() en includes/documentos_wo_usados.php)
 * si ESE registro ya quedó consumido por un despacho real y por lo tanto no
 * se debe poder editar ni borrar.
 */
function obtener_id_wo_de_historial(PDO $bdd, $historial_id) {
    $stmt = $bdd->prepare("SELECT id_wo FROM historial_documento_op WHERE id = ?");
    $stmt->execute([intval($historial_id)]);
    return $stmt->fetchColumn();
}

function sincronizar_documento_op_desde_historial(PDO $bdd, $opid) {
    $stmt = $bdd->prepare("SELECT tipo_doc, n_doc, valor FROM historial_documento_op WHERE opid = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([intval($opid)]);
    $ultimo = $stmt->fetch();
    if (!$ultimo) return;

    $upd = $bdd->prepare("UPDATE ordenes_pedidos SET tipo_doc = ?, n_doc = ?, valor = ? WHERE id = ?");
    $upd->execute([$ultimo['tipo_doc'], $ultimo['n_doc'], $ultimo['valor'], intval($opid)]);
}
