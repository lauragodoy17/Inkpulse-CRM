<?php
/**
 * Canal de venta "Venta directa - Eureka" y sobrecosto por canal (pedido de la usuaria 2026-10-26).
 *
 * - "Venta directa - Eureka" es un canal nuevo de canales_venta; se crea solo si no existe (canal_andrea_id).
 * - Con "Venta directa - Eureka" (Consignación ya no, 2026-11-04) la adopción exige el precio venta padre de los títulos
 *   adoptados y un % de sobrecosto (recursos.sobrecosto_pct), uno solo para toda la adopción.
 * - Solo "Venta directa - Eureka" usa el formato de venta directa (dvd_canales_formato, 2026-11-04).
 * - Sobrecosto = unidades vendidas × precio del cierre del formato × %, es decir Σ formatos_vd_items.total × %,
 *   de los formatos activos con el cierre de inventario hecho (etapa ≥ 5). Se calcula con el % vigente y se muestra en
 *   Adopciones (ajax/tab_adopciones.php), no en el formato.
 * - 2026-11-10: el % para toda la adopción se reemplazó por un valor de sobrecosto por libro, digitado a mano en cada
 *   título (presupuestos.sobrecosto, columna "Sobrecosto por libro" de Adopciones). Adopciones ya no usa
 *   recursos.sobrecosto_pct ni sobrecosto_resumen(); se dejan por si hacen falta.
 */

const CANAL_VENTA_DIRECTA_ANDREA = 'Venta directa - Eureka';
const CANAL_VENTA_DIRECTA_ANTERIOR = 'Venta directa - Andrea'; // nombre anterior (renombrado el 2026-11-10)

/** Id del canal "Venta directa - Eureka"; lo crea si no existe. */
function canal_andrea_id(PDO $bdd) {
    static $id = null;
    if ($id !== null) return $id;
    $st = $bdd->prepare("SELECT id FROM canales_venta WHERE canal_venta = ? ORDER BY id LIMIT 1");
    $st->execute([CANAL_VENTA_DIRECTA_ANDREA]);
    $id = (int)$st->fetchColumn();
    if (!$id) {
        // 2026-11-10: el canal se llamaba "Venta directa - Andrea"; se renombra el mismo registro para que las
        // adopciones que ya lo tienen (recursos.id_canal / id_canal2) lo conserven.
        $st->execute([CANAL_VENTA_DIRECTA_ANTERIOR]);
        $id = (int)$st->fetchColumn();
        if ($id) {
            $bdd->prepare("UPDATE canales_venta SET canal_venta = ?, updated_at = NOW() WHERE id = ?")->execute([CANAL_VENTA_DIRECTA_ANDREA, $id]);
        }
    }
    if (!$id) {
        $bdd->prepare("INSERT INTO canales_venta (canal_venta, created_at, updated_at) VALUES (?, NOW(), NOW())")->execute([CANAL_VENTA_DIRECTA_ANDREA]);
        $id = (int)$bdd->lastInsertId();
    }
    return $id;
}

/** Canales que exigen precio venta padre y % de sobrecosto. */
function canales_con_sobrecosto(PDO $bdd) {
    return [canal_andrea_id($bdd)];
}

/**
 * recursos.sobrecosto_pct y recursos.id_canal2 (segundo canal de venta de la adopción, pedido de la usuaria
 * 2026-11-04; el primero sigue en id_canal). DDL: llamar fuera de transacciones.
 */
function sobrecosto_asegurar_columna(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $columnas = ['sobrecosto_pct' => 'DECIMAL(5,2) NULL DEFAULT NULL', 'id_canal2' => 'INT NULL DEFAULT NULL'];
    foreach ($columnas as $columna => $definicion) {
        if ($bdd->query("SHOW COLUMNS FROM recursos LIKE '$columna'")->fetch()) continue;
        try {
            $bdd->exec("ALTER TABLE recursos ADD COLUMN $columna $definicion");
        } catch (Exception $e) {}
    }
    // Descuentos de la adopción con más decimales (2026-11-11): DECIMAL(10,4) solo guardaba 2 decimales del %; con
    // DECIMAL(12,8) caben 6 (ej. 51,648352 % para que 45.500 quede en 22.000,00 neto). Solo amplía, no pierde datos.
    $st_dec = $bdd->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'presupuestos' AND COLUMN_NAME IN ('descuento', 'descuento_d') AND NUMERIC_SCALE < 8");
    foreach ($st_dec->fetchAll(PDO::FETCH_COLUMN) as $col_dec) {
        try { $bdd->exec("ALTER TABLE presupuestos MODIFY COLUMN $col_dec DECIMAL(12,8) NOT NULL"); } catch (Exception $e) {}
    }
    // Precio venta padre desde el pedido (2026-11-11): solo se puede modificar una vez por título desde pedido_colegio.php.
    // presupuestos.pp_editado_pedido = cuándo se hizo esa modificación (NULL = todavía se puede). Al crear la columna se
    // marcan los títulos que ya se habían modificado desde un pedido (historial del colegio).
    if (!$bdd->query("SHOW COLUMNS FROM presupuestos LIKE 'pp_editado_pedido'")->fetch()) {
        try {
            $bdd->exec("ALTER TABLE presupuestos ADD COLUMN pp_editado_pedido DATETIME NULL DEFAULT NULL");
            $bdd->exec("UPDATE presupuestos p
                        JOIN libros l ON l.id = p.id_libro
                        JOIN (SELECT h.id_colegio, h.campo, MIN(h.fecha) AS fecha FROM historial_colegios h
                              WHERE h.campo LIKE 'Precio venta padre - % (desde el pedido #%)' GROUP BY h.id_colegio, h.campo) h
                          ON h.id_colegio = p.id_colegio
                         AND h.campo LIKE CONCAT('Precio venta padre - ', l.libro, ' (desde el pedido #%)')
                        JOIN pedidos pe ON pe.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(h.campo, '#', -1), ')', 1) AS UNSIGNED)
                         AND pe.id_periodo = p.id_periodo
                        SET p.pp_editado_pedido = h.fecha
                        WHERE p.definido = 1 AND p.pp_editado_pedido IS NULL");
        } catch (Exception $e) {}
    }
    // Sobrecosto por libro (2026-11-10): valor en pesos que se digita en cada título de la adopción; reemplaza al %.
    if (!$bdd->query("SHOW COLUMNS FROM presupuestos LIKE 'sobrecosto'")->fetch()) {
        try {
            $bdd->exec("ALTER TABLE presupuestos ADD COLUMN sobrecosto DECIMAL(12,2) NULL DEFAULT NULL");
        } catch (Exception $e) {}
    }
}

/** Ids de canal que llegan del formulario de Adopciones: máximo 2, sin repetir. */
function canales_del_formulario($valor) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$valor))));
    return array_slice($ids, 0, 2);
}

/** Condición SQL: la adopción (alias de recursos) tiene alguno de estos canales, en cualquiera de los dos campos. */
function sql_adopcion_tiene_canal(array $canales, $alias = 'r') {
    $lista = implode(',', array_map('intval', $canales));
    return "($alias.id_canal IN ($lista) OR $alias.id_canal2 IN ($lista))";
}

/** ¿La adopción del colegio en el período tiene el canal "Venta directa - Eureka"? */
function adopcion_es_venta_directa(PDO $bdd, $idColegio, $idPeriodo) {
    $st = $bdd->prepare("SELECT COUNT(*) FROM recursos r WHERE r.id_colegio = ? AND r.id_periodo = ? AND " . sql_adopcion_tiene_canal([canal_andrea_id($bdd)]));
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    return (int)$st->fetchColumn() > 0;
}

/** % de sobrecosto del colegio en el período (0 si su canal no lleva sobrecosto). */
function sobrecosto_pct(PDO $bdd, $idColegio, $idPeriodo) {
    sobrecosto_asegurar_columna($bdd);
    $st = $bdd->prepare("SELECT id_canal, id_canal2, sobrecosto_pct FROM recursos WHERE id_colegio = ? AND id_periodo = ? LIMIT 1");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !array_intersect([(int)$r['id_canal'], (int)$r['id_canal2']], canales_con_sobrecosto($bdd))) return 0.0;
    return (float)$r['sobrecosto_pct'];
}

/**
 * Resumen del colegio en el período para la adopción: ['pct','formatos','vendidas','total_venta','sobrecosto',
 * 'detalle' => [['descripcion','tipo','precio','vendidas','total']]].
 * Solo formatos activos con el cierre de inventario hecho (etapa ≥ 5).
 */
function sobrecosto_resumen(PDO $bdd, $idColegio, $idPeriodo) {
    $pct = sobrecosto_pct($bdd, $idColegio, $idPeriodo);
    $res = ['pct' => $pct, 'formatos' => 0, 'vendidas' => 0, 'total_venta' => 0.0, 'sobrecosto' => 0.0, 'detalle' => []];
    try {
        $st = $bdd->prepare("SELECT COUNT(DISTINCT f.id) AS formatos, COALESCE(SUM(i.vendidas), 0) AS vendidas, COALESCE(SUM(i.total), 0) AS total
                             FROM formatos_vd f JOIN formatos_vd_items i ON i.id_formato = f.id
                             WHERE f.id_colegio = ? AND f.id_periodo = ? AND f.estado = 1 AND f.etapa >= 5");
        $st->execute([(int)$idColegio, (int)$idPeriodo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $res; // sin tablas de formatos todavía
    }
    $res['formatos'] = (int)$r['formatos'];
    $res['vendidas'] = (int)$r['vendidas'];
    $res['total_venta'] = round((float)$r['total'], 2);
    $res['sobrecosto'] = round($res['total_venta'] * $pct / 100, 2);
    // Detalle por paquete / título suelto y precio del cierre.
    $st = $bdd->prepare("SELECT i.descripcion, i.tipo, i.precio, SUM(i.vendidas) AS vendidas, SUM(i.total) AS total
                         FROM formatos_vd f JOIN formatos_vd_items i ON i.id_formato = f.id
                         WHERE f.id_colegio = ? AND f.id_periodo = ? AND f.estado = 1 AND f.etapa >= 5 AND i.vendidas > 0
                         GROUP BY i.descripcion, i.tipo, i.precio ORDER BY i.descripcion");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $res['detalle'] = array_map(fn($d) => ['descripcion' => $d['descripcion'], 'tipo' => $d['tipo'], 'precio' => (float)$d['precio'],
                                           'vendidas' => (int)$d['vendidas'], 'total' => round((float)$d['total'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
    return $res;
}
