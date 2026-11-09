<?php
/**
 * Canal de venta "Venta directa - Andrea" y sobrecosto por canal (pedido de la usuaria 2026-10-26).
 *
 * - "Venta directa - Andrea" es un canal nuevo de canales_venta; se crea solo si no existe (canal_andrea_id).
 * - Con "Venta directa - Andrea" (Consignación ya no, 2026-11-04) la adopción exige el precio venta padre de los títulos
 *   adoptados y un % de sobrecosto (recursos.sobrecosto_pct), uno solo para toda la adopción.
 * - Solo "Venta directa - Andrea" usa el formato de venta directa (dvd_canales_formato, 2026-11-04).
 * - Sobrecosto = unidades vendidas × precio del cierre del formato × %, es decir Σ formatos_vd_items.total × %,
 *   de los formatos activos con el cierre de inventario hecho (etapa ≥ 5). Se calcula con el % vigente y se muestra en
 *   Adopciones (ajax/tab_adopciones.php), no en el formato.
 */

const CANAL_VENTA_DIRECTA_ANDREA = 'Venta directa - Andrea';

/** Id del canal "Venta directa - Andrea"; lo crea si no existe. */
function canal_andrea_id(PDO $bdd) {
    static $id = null;
    if ($id !== null) return $id;
    $st = $bdd->prepare("SELECT id FROM canales_venta WHERE canal_venta = ? ORDER BY id LIMIT 1");
    $st->execute([CANAL_VENTA_DIRECTA_ANDREA]);
    $id = (int)$st->fetchColumn();
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
