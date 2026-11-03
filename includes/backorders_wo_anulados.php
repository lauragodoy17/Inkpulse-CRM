<?php
/**
 * Documentos REM/FV ANULADOS (campo `senAnulado` de /inventarios/listarDocumentoSalidaAlmacen) o
 * ELIMINADOS de World Office dentro de la caché de backorders.
 *
 * Ninguno de los dos despachó nada: se conserva su fila en `backorder_wo_docs` con anulado=1 (y
 * eliminado=1 si ya no existe en World Office) — así la OP que lo tiene como "Núm. de documento"
 * no queda eternamente como "sin sincronizar" — pero se le borran los renglones, de modo que todo
 * lo que suma backorder_wo_renglones (backorders, cumplimiento por cliente, excedentes de
 * despacho) lo cuenta como 0 sin tocar esas consultas. En `backorder_wo_catalogo` se marca igual
 * para que no salga en "Documentos sin verificar" ni se pueda asignar a un pedido — pedido por la
 * usuaria 2026-10-20.
 *
 * - Anulados: las dos sincronizaciones (php/sync_backorders_wo.php y
 *   php/sync_backorders_wo_catalogo.php) revisan `senAnulado` en CADA corrida, también para
 *   documentos ya guardados (pueden anularse después de sincronizados).
 * - Eliminados: un documento borrado simplemente deja de salir en el listado. php/sync_backorders_wo.php
 *   marca con `ultima_corrida` cada documento guardado que sí ve en un recorrido completo; al
 *   terminar, los que no se vieron se consultan uno por uno por id y solo se marcan eliminados si
 *   World Office responde NOT_FOUND ("Documento no encontrado") — no basta con no verlos en el
 *   listado: si cambian documentos mientras se pagina, uno puede saltarse sin estar eliminado.
 */

/** Crea las tablas de caché de documentos World Office de backorders si no existen. */
function backorders_crear_tablas_wo($bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo_documento VARCHAR(10) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        id_wo INT NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        fecha DATE NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_tipo_numero (tipo_documento, numero)
    )");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_renglones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        id_libro INT NULL,
        isbn VARCHAR(100) NULL,
        descripcion VARCHAR(255) NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        KEY idx_doc (id_wo, tipo_documento),
        KEY idx_libro (id_libro)
    )");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_catalogo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        fecha DATE NULL,
        concepto TEXT NULL,
        colegio_extraido VARCHAR(255) NULL,
        id_pedido_verificado INT NULL,
        asignado_manual TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento),
        KEY idx_pedido_verificado (id_pedido_verificado)
    )");
    backorders_asegurar_columna_anulado($bdd);
}

/**
 * Índice de libros_pedidos.cod_pedido (cruce pedidos ↔ líneas de todo el cálculo de backorders):
 * sin él, cada cálculo de un período grande recorría todas las líneas de pedido (~4 s para 2026,
 * y el sync lo repite al asignar consecutivos) — 2026-10-20. Se crea una sola vez, desde el sync
 * (fuera de cualquier transacción: un ALTER hace commit implícito).
 */
function backorders_asegurar_indices($bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    try {
        if (!$bdd->query("SHOW INDEX FROM libros_pedidos WHERE Column_name = 'cod_pedido'")->fetch()) {
            $bdd->exec("ALTER TABLE libros_pedidos ADD INDEX idx_cod_pedido (cod_pedido)");
        }
    } catch (Exception $e) {}
}

/**
 * Fecha de World Office a Y-m-d. El LISTADO trae "dd/mm/yyyy" y el DETALLE "yyyy-mm-dd" — guardar
 * la del listado tal cual en una columna DATE la dañaba.
 */
function backorders_fecha_wo($fecha) {
    $fecha = trim((string)$fecha);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $fecha, $m)) return "$m[3]-$m[2]-$m[1]";
    if (preg_match('#^\d{4}-\d{2}-\d{2}#', $fecha)) return substr($fecha, 0, 10);
    return null;
}

/** Agrega las columnas de estado a las tablas de caché si todavía no las tienen (cada una puede no existir aún). */
function backorders_asegurar_columna_anulado($bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    $columnas = [
        'backorder_wo_docs' => [
            'anulado' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'eliminado' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ultima_corrida' => 'VARCHAR(30) NULL',
            // "fechaModificacion" del listado de World Office cuando se trajeron los renglones: si
            // cambia, el documento se editó allá y hay que volver a traerlos.
            'fecha_modificacion' => 'VARCHAR(25) NULL',
        ],
        'backorder_wo_catalogo' => [
            'anulado' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fecha_modificacion' => 'VARCHAR(25) NULL',
            // Tercero (cliente) del documento en World Office, para "Documentos sin verificar".
            'cliente' => 'VARCHAR(255) NULL',
        ],
    ];
    foreach ($columnas as $tabla => $defs) {
        foreach ($defs as $col => $def) {
            try {
                if (!$bdd->query("SHOW COLUMNS FROM $tabla LIKE '$col'")->fetch()) {
                    $bdd->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
                }
            } catch (Exception $e) {} // la tabla aún no existe: la crea su sync
        }
    }
}

/**
 * Marca un documento como anulado (o eliminado de World Office): fila en backorder_wo_docs con
 * anulado=1 (sin pisar un documento VÁLIDO distinto que tenga el mismo número), sin renglones, y
 * anulado en el catálogo.
 */
function backorders_marcar_doc_anulado($bdd, $tipoDocumento, $numero, $idWo, $prefijo, $fecha, $eliminado = false) {
    backorders_asegurar_columna_anulado($bdd);
    $bdd->prepare("INSERT INTO backorder_wo_docs (tipo_documento, numero, id_wo, prefijo, fecha, anulado, eliminado, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE
                       eliminado = IF(id_wo = VALUES(id_wo), GREATEST(eliminado, VALUES(eliminado)), eliminado),
                       anulado = IF(id_wo = VALUES(id_wo), 1, anulado),
                       updated_at = NOW()")
        ->execute([$tipoDocumento, $numero, (int)$idWo, is_array($prefijo) ? '' : (string)$prefijo, backorders_fecha_wo($fecha), $eliminado ? 1 : 0]);
    $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE id_wo = ? AND tipo_documento = ?")->execute([(int)$idWo, $tipoDocumento]);
    try {
        $bdd->prepare("UPDATE backorder_wo_catalogo SET anulado = 1, updated_at = NOW() WHERE id_wo = ? AND tipo_documento = ?")
            ->execute([(int)$idWo, $tipoDocumento]);
    } catch (Exception $e) {}
}

/**
 * Final de un recorrido COMPLETO de php/sync_backorders_wo.php para un tipo: los documentos
 * guardados (válidos o anulados) que no aparecieron en el listado (ultima_corrida distinta a $corrida) se
 * consultan uno por uno; los que World Office ya no tiene (NOT_FOUND) se marcan eliminados.
 * Máximo $limite consultas por llamada (el resto se revisa en el siguiente recorrido).
 * Devuelve [['numero' => , 'id_wo' => ], ...] de los marcados.
 */
function backorders_detectar_eliminados($bdd, $tipoDocumento, $corrida, $limite = 40) {
    // También los ya anulados (eliminado = 0): si luego los borran de World Office, pasan a
    // mostrarse como "(eliminada)" en vez de "(anulada)".
    $stmt = $bdd->prepare("SELECT numero, id_wo, prefijo, fecha FROM backorder_wo_docs
                           WHERE tipo_documento = ? AND eliminado = 0 AND (ultima_corrida IS NULL OR ultima_corrida <> ?)
                           LIMIT " . (int)$limite);
    $stmt->execute([$tipoDocumento, $corrida]);
    $eliminados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $detalle = obtener_documento_salida_por_id((int)$doc['id_wo']);
        $status = $detalle['status'] ?? '';
        if ($status === 'NOT_FOUND') {
            backorders_marcar_doc_anulado($bdd, $tipoDocumento, $doc['numero'], $doc['id_wo'], $doc['prefijo'], $doc['fecha'], true);
            $eliminados[] = ['numero' => $doc['numero'], 'id_wo' => (int)$doc['id_wo']];
        } elseif ($status === 'OK' || $status === 'ACCEPTED') {
            // Sigue existiendo (se saltó en el listado al paginar): se da por visto en esta corrida.
            $bdd->prepare("UPDATE backorder_wo_docs SET ultima_corrida = ? WHERE tipo_documento = ? AND id_wo = ?")
                ->execute([$corrida, $tipoDocumento, (int)$doc['id_wo']]);
        }
        // Cualquier otra respuesta (error de red/API): no se toca, se revisa en el próximo recorrido.
    }
    return $eliminados;
}
