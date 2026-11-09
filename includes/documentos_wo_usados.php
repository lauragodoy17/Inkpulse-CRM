<?php
/**
 * Registro de documentos de World Office ya "consumidos" por un despacho.
 * Regla del negocio: un documento (id_wo) solo puede usarse para UN
 * despacho en total — ni siquiera para un segundo despacho de la MISMA OP
 * (aunque un mismo despacho sí puede repartirse en varios apartados si se
 * agregan todos en una sola solicitud) — mientras que el número de OP sí
 * puede tener varios despachos, cada uno con su propio documento distinto.
 *
 * `id_wo` es PRIMARY KEY: la propia base de datos garantiza que un mismo
 * documento nunca quede registrado dos veces, sin depender de comparar
 * texto/valor a mano (eso fue lo que falló antes: comparar por n_doc
 * bloqueaba textos genéricos reutilizados legítimamente, y comparar contra
 * el historial de cambios no detectaba un segundo despacho con el MISMO
 * documento porque ahí "no cambiaba nada").
 */

// Debe llamarse ANTES de abrir cualquier transacción: en MySQL todo DDL hace
// commit implícito, incluso CREATE TABLE IF NOT EXISTS cuando la tabla ya existe.
function crear_tabla_documentos_wo_usados(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS documentos_wo_usados (
        id_wo INT NOT NULL PRIMARY KEY,
        opid INT NOT NULL,
        n_doc VARCHAR(50) NOT NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Devuelve el despacho que ya consumió este documento (opid, n_doc, año),
 * o null si el documento todavía está libre para usarse.
 */
function documento_wo_ya_usado(PDO $bdd, $id_wo) {
    $id_wo = intval($id_wo);
    if ($id_wo <= 0) return null;

    $stmt = $bdd->prepare(
        "SELECT d.id_wo, d.opid, d.n_doc, d.fecha, o.año
         FROM documentos_wo_usados d
         JOIN ordenes_pedidos o ON o.id = d.opid
         WHERE d.id_wo = ?"
    );
    $stmt->execute([$id_wo]);
    return $stmt->fetch() ?: null;
}

/**
 * Marca un documento como consumido por un despacho real (con al menos un
 * apartado registrado). INSERT IGNORE por si acaso: la validación previa ya
 * impide llegar aquí con un documento repetido, pero la PRIMARY KEY es la
 * garantía real contra condiciones de carrera.
 */
function marcar_documento_wo_usado(PDO $bdd, $id_wo, $opid, $n_doc, $id_usuario) {
    $id_wo = intval($id_wo);
    if ($id_wo <= 0) return;

    $stmt = $bdd->prepare(
        "INSERT IGNORE INTO documentos_wo_usados (id_wo, opid, n_doc, id_usuario) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$id_wo, intval($opid), $n_doc, intval($id_usuario)]);
}
