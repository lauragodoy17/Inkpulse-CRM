<?php
/**
 * /includes/devoluciones_vd_datos.php
 * "Legalización venta directa" (menú Venta directa, aparte de Devoluciones desde 2026-10-21), pedidos de la usuaria 2026-10-19:
 *  - Ya no sale de las solicitudes de venta directa (ese módulo se quitó): es un consolidado de los pedidos
 *    de VENTA (tabla pedidos) Entregados del colegio. Los pedidos de muestras no aplican.
 *  - colegio + período → todos sus pedidos → documentos de World Office de sus OP (directas o agrupadas,
 *    con la OP en el concepto o asociados en op_pendiente.php; sin documentos, lo solicitado en
 *    libros_pedidos) → títulos sumados por libro → UNA devolución con tope = despachado − ya devuelto.
 *  - Toda devolución deja COMPLETOS los pedidos que incluye (no hay devolución parcial) y lo que no se
 *    devolvió queda en un documento de SALDO con consecutivo propio (SAL-n), valorizado con el precio final
 *    (con descuento) del renglón de la factura/remisión de WO (2026-10-21); los pedidos sin documentos en WO
 *    usan el precio del libro en el pedido (precio − descuento, ver dvd_precios_pedido()).
 *
 * Cada pedido se identifica con una "clave" 'v210' (pedido #210), que es como queda en ids_pedidos y reparto.
 *
 * Dónde se guarda: en las devoluciones de venta (`devoluciones_v` + `libros_devol_v`), mismo flujo
 * posterior en devolucion_colegio.php. Columnas de trazabilidad (dvd_asegurar_columnas):
 *   devoluciones_v.ids_pedidos   — claves de los pedidos incluidos: "v210,v215" (NOT NULL = de este módulo)
 *   devoluciones_v.ops_pedidos   — OP de esos pedidos ("2026-6146, 2026-6150")
 *   devoluciones_v.documentos_wo — documentos de WO ("CEUR 7689, FVE 2399")
 *   devoluciones_v.cierre        — 'completa': cierra todos sus pedidos en este módulo
 *   devoluciones_v.token_registro — UNIQUE: el mismo envío del formulario no se registra dos veces
 *   libros_devol_v.producto_wo / cant_despachada / documentos_wo (ids WO)
 *   libros_devol_v.reparto       — de qué pedido sale cada unidad: "v210:3:5870,v215:2:5870"
 * Saldos: `saldos_devolucion` (consecutivo UNIQUE, id_devolucion UNIQUE) + `saldos_devolucion_libros`. Un
 * saldo cuya devolución se anula se muestra como anulado (y sus pedidos vuelven a quedar abiertos).
 * Las devoluciones de este módulo NO salen en "Ver devoluciones de ventas" (ver_devol_ventas_query.php):
 * se ven en ver_devol_ventas.php (la lista propia ver_devol_venta_directa.php se borró el 2026-11-13). Las registradas antes con id_pedido_vd se conservan.
 */
require_once __DIR__ . '/devoluciones_muestras_datos.php'; // dm_validar_acceso, dm_filtro_alcance_sql, dm_libros_por_producto
require_once __DIR__ . '/wo_documentos_por_op.php';        // wo_documentos_por_op: concepto + documentos asociados a la OP

/** Estado "Entregado" de pedidos (estados_pedidos). */
const DVD_ESTADO_ENTREGADO = 4;
/** Estado "Anulado" de devoluciones_v: no cuenta como devuelto. */
const DVD_ESTADO_DEVOL_ANULADA = 3;
/** Máximo de pedidos en una misma devolución (cada uno suma consultas a World Office). */
const DVD_MAX_PEDIDOS = 30;
/** Prefijo del consecutivo de los documentos de saldo. */
const DVD_PREFIJO_SALDO = 'SAL';

require_once __DIR__ . '/sobrecosto_canal.php';
require_once __DIR__ . '/pedidos_venta_directa.php'; // pedidos.para_andrea

/**
 * Canales que usan el formato y la legalización de venta directa: solo "Venta directa - Eureka" (la usuaria
 * 2026-11-04). Los demás canales, Venta directa y Consignación incluidos, legalizan en Devoluciones de ventas.
 */
function dvd_canales_formato(PDO $bdd) {
    return [canal_andrea_id($bdd)];
}

/**
 * SQL (alias x = pedidos) que deja solo los pedidos que se legalizan en venta directa: los marcados "para Andrea"
 * al solicitarlos (pedidos.para_andrea, 2026-11-09: el pedido 2076 no salía porque la adopción de su colegio
 * tenía "Venta directa") o los de colegios con alguno de sus canales en dvd_canales_formato, en el período del
 * pedido. dvv_sql_sin_canal_vd es exactamente lo contrario, para que cada pedido salga en una sola pantalla.
 */
function dvd_sql_canal_vd(PDO $bdd) {
    sobrecosto_asegurar_columna($bdd);
    asegurar_columnas_pedido_vd($bdd);
    return " AND (x.para_andrea = 1 OR EXISTS (SELECT 1 FROM recursos r WHERE r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo AND " . sql_adopcion_tiene_canal(dvd_canales_formato($bdd)) . "))";
}

/** Columnas y tablas. Antes de cualquier transacción (DDL = commit implícito). */
function dvd_asegurar_columnas(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    sobrecosto_asegurar_columna($bdd);
    $bdd->exec("CREATE TABLE IF NOT EXISTS saldos_devolucion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consecutivo INT NOT NULL,
        id_devolucion INT NOT NULL,
        id_colegio INT NOT NULL,
        id_periodo INT NULL,
        cliente INT NULL,
        ids_pedidos VARCHAR(500) NOT NULL,
        unidades INT NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        id_usuario INT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_saldos_consecutivo (consecutivo),
        UNIQUE KEY uniq_saldos_devolucion (id_devolucion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $bdd->exec("CREATE TABLE IF NOT EXISTS saldos_devolucion_libros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_saldo INT NOT NULL,
        id_pedido INT NOT NULL,
        id_libro INT NOT NULL,
        cantidad INT NOT NULL,
        precio DECIMAL(14,2) NOT NULL DEFAULT 0,
        descuento DECIMAL(7,4) NOT NULL DEFAULT 0,
        precio_final DECIMAL(14,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        KEY idx_saldos_libros_saldo (id_saldo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // ids_pedidos_venta: devoluciones de venta (devoluciones_ventas.php, 2026-10-26), mismas claves 'v210'.
    if (!$bdd->query("SHOW COLUMNS FROM devoluciones_v LIKE 'ids_pedidos_venta'")->fetch()) {
        try { $bdd->exec("ALTER TABLE devoluciones_v ADD COLUMN ids_pedidos_venta VARCHAR(500) NULL DEFAULT NULL"); } catch (Exception $e) { /* ya existe */ }
    }
    if ($bdd->query("SHOW COLUMNS FROM devoluciones_v LIKE 'ops_pedidos'")->fetch()
        && $bdd->query("SHOW COLUMNS FROM libros_devol_v LIKE 'reparto'")->fetch()) return;

    $alter = [
        // id_pedido_vd: devoluciones de las solicitudes de venta directa anteriores (se conservan).
        "ALTER TABLE devoluciones_v ADD COLUMN id_pedido_vd INT NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN documentos_wo VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN op_vd VARCHAR(20) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN cierre VARCHAR(10) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN token_registro VARCHAR(40) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN ids_pedidos VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD COLUMN ops_pedidos VARCHAR(255) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones_v ADD INDEX idx_devoluciones_v_pedido_vd (id_pedido_vd)",
        "ALTER TABLE devoluciones_v ADD UNIQUE INDEX uniq_devoluciones_v_token (token_registro)",
        "ALTER TABLE libros_devol_v ADD COLUMN producto_wo INT NULL DEFAULT NULL",
        "ALTER TABLE libros_devol_v ADD COLUMN cant_despachada INT NULL DEFAULT NULL",
        "ALTER TABLE libros_devol_v ADD COLUMN documentos_wo VARCHAR(255) NULL DEFAULT NULL",
        "ALTER TABLE libros_devol_v ADD COLUMN reparto VARCHAR(500) NULL DEFAULT NULL",
    ];
    foreach ($alter as $sql) {
        try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
    }
}

/** Clave válida ('v45') o null. */
function dvd_clave_valida($clave) {
    return preg_match('/^v(\d+)$/', (string)$clave, $m) && (int)$m[1] > 0 ? 'v' . (int)$m[1] : null;
}

/** "Pedido #210". */
function dvd_etiqueta($clave) {
    return 'Pedido #' . (int)substr($clave, 1);
}

/** "SAL-12". */
function dvd_numero_saldo($consecutivo) {
    return DVD_PREFIJO_SALDO . '-' . (int)$consecutivo;
}

/**
 * Pedidos de venta Entregados de colegios con canal Venta directa, dentro del alcance del usuario (el mismo
 * de devoluciones de muestras), con quién lo pidió, sus OP (directas o agrupadas: "2026-6146, 2026-6150") y la devolución de este módulo que
 * los cerró ('id_devolucion', null si sigue por devolver). Con $idPeriodo, solo los de ese período.
 */
function dvd_listar_pedidos(PDO $bdd, $idPeriodo = null) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $filtroPer = $idPeriodo ? ' AND x.id_periodo = ' . (int)$idPeriodo : '';
    $st = $bdd->prepare("SELECT CONCAT('v', x.id) AS clave, x.id, x.fecha, c.id AS id_colegio, c.colegio,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante,
                                (SELECT GROUP_CONCAT(CONCAT(o.`año`, '-', o.id) ORDER BY o.id SEPARATOR ', ') FROM ordenes_pedidos o
                                  WHERE o.estado <> 4 AND (o.id_pedido = x.id OR o.id IN (SELECT op FROM op_pedidos_agrupados WHERE id_pedido = x.id))) AS ops
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN usuarios u ON u.id = x.id_usuario
                         WHERE x.estado = " . DVD_ESTADO_ENTREGADO . $filtroPer . dvd_sql_canal_vd($bdd) . $alcance . "
                         ORDER BY x.fecha DESC, x.id DESC");
    $st->execute($params);
    $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

    $cerrados = [];
    foreach ($bdd->query("SELECT id, ids_pedidos FROM devoluciones_v WHERE ids_pedidos IS NOT NULL AND cierre = 'completa' AND estado <> " . DVD_ESTADO_DEVOL_ANULADA . " ORDER BY id") as $d) {
        foreach (explode(',', $d['ids_pedidos']) as $c) $cerrados[$c] = $cerrados[$c] ?? (int)$d['id'];
    }
    foreach ($pedidos as &$p) {
        $p['id_devolucion'] = $cerrados[$p['clave']] ?? null;
        $p['completado'] = $p['id_devolucion'] !== null;
    }
    unset($p);
    return $pedidos;
}

/**
 * Pedido de venta Entregado de un colegio con canal Venta directa, dentro del alcance del usuario, o null: ['clave','id','codigo','fecha',
 * 'id_colegio','id_periodo','colegio','solicitante','id_cliente','cliente','tipo'].
 */
function dvd_obtener_pedido(PDO $bdd, $clave) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT x.id, x.codigo, x.fecha, x.id_colegio, x.id_periodo, x.tipo, c.colegio,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante, cl.id AS id_cliente, cl.cliente
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN usuarios u ON u.id = x.id_usuario
                         LEFT JOIN clientes cl ON cl.id = x.cliente
                         WHERE x.id = ? AND x.estado = " . DVD_ESTADO_ENTREGADO . dvd_sql_canal_vd($bdd) . $alcance);
    $st->execute(array_merge([(int)substr($clave, 1)], $params));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ? ['clave' => $clave] + $p : null;
}

/** OP (no anuladas) del pedido, directas o agrupadas: [['id','anio','n_doc'], ...]. */
function dvd_ops_pedido(PDO $bdd, $clave) {
    $id = (int)substr($clave, 1);
    $st = $bdd->prepare("SELECT id, `año` AS anio, n_doc FROM ordenes_pedidos
                         WHERE estado <> 4 AND (id_pedido = ? OR id IN (SELECT op FROM op_pedidos_agrupados WHERE id_pedido = ?))
                         ORDER BY id");
    $st->execute([$id, $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Unidades devueltas en devoluciones activas de este módulo y de devoluciones de venta (ids_pedidos_venta,
 * devoluciones_ventas.php), según libros_devol_v.reparto: un mismo pedido no se devuelve dos veces entre
 * los dos módulos. Con $clave solo las de ese pedido. Devuelve [['clave','producto_wo','id_libro','cantidad'], ...].
 */
function dvd_devuelto(PDO $bdd, $clave = null) {
    dvd_asegurar_columnas($bdd);
    $sql = "SELECT ld.reparto, ld.id_libro FROM libros_devol_v ld JOIN devoluciones_v d ON d.codigo = ld.cod_pedido
            WHERE (d.ids_pedidos IS NOT NULL OR d.ids_pedidos_venta IS NOT NULL) AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA;
    $params = [];
    if ($clave !== null) { $sql .= " AND (FIND_IN_SET(?, d.ids_pedidos) OR FIND_IN_SET(?, d.ids_pedidos_venta))"; $params[] = $clave; $params[] = $clave; }
    $st = $bdd->prepare($sql);
    $st->execute($params);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (array_filter(explode(',', (string)$r['reparto'])) as $parte) {
            [$c, $cant, $pid] = explode(':', $parte) + ['', 0, 0];
            if ($clave !== null && $c !== $clave) continue;
            $res[] = ['clave' => $c, 'producto_wo' => (int)$pid ?: null, 'id_libro' => (int)$r['id_libro'], 'cantidad' => (int)$cant];
        }
    }
    return $res;
}

/** [producto_wo => unidades devueltas] o, con $porLibro, [id_libro => unidades]. */
function dvd_devuelto_agrupado(PDO $bdd, $clave, $porLibro = false) {
    $res = [];
    foreach (dvd_devuelto($bdd, $clave) as $x) {
        $k = $porLibro ? $x['id_libro'] : $x['producto_wo'];
        if ($k !== null) $res[$k] = ($res[$k] ?? 0) + $x['cantidad'];
    }
    return $res;
}

/** Devolución activa de este módulo que cerró el pedido: ['id','fecha','usuario'] o null. */
function dvd_cierre_completo(PDO $bdd, $clave) {
    $st = $bdd->prepare("SELECT d.id, d.created_at AS fecha, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM devoluciones_v d LEFT JOIN usuarios u ON u.id = d.id_usuario
                         WHERE FIND_IN_SET(?, d.ids_pedidos) AND d.cierre = 'completa' AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . "
                         ORDER BY d.id LIMIT 1");
    $st->execute([$clave]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Precio final de cada libro del pedido: precio del libro − descuento, con la misma regla de
 * pedido_colegio_estado.php: el descuento aprobado (libros_pedidos.descuento_aprob, en %) si lo tiene; si
 * no, el del presupuesto del colegio en el período (descuento_d, o descuento si descuento_d es 0).
 * Devuelve [id_libro => ['precio','descuento' (fracción),'precio_final']].
 */
function dvd_precios_pedido(PDO $bdd, array $pedido) {
    $st = $bdd->prepare("SELECT lp.id_libro, l.precio, lp.descuento_aprob, p.descuento, p.descuento_d
                         FROM libros_pedidos lp
                         JOIN libros l ON l.id = lp.id_libro
                         LEFT JOIN presupuestos p ON p.id_colegio = ? AND p.id_libro = lp.id_libro AND p.id_periodo = ?
                              AND COALESCE(lp.cod_area, '') = COALESCE(p.cod_area, '') AND p.definido = 1
                         WHERE lp.cod_pedido = ? AND lp.cantidad <> 0
                         ORDER BY lp.id");
    $st->execute([(int)$pedido['id_colegio'], (int)$pedido['id_periodo'], (string)$pedido['codigo']]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idLibro = (int)$r['id_libro'];
        if (isset($res[$idLibro])) continue; // mismo libro en dos áreas: se toma la primera fila
        if ($r['descuento_aprob'] !== null && $r['descuento_aprob'] !== '') {
            $desc = (float)$r['descuento_aprob'] / 100;
        } else {
            $desc = ((string)$r['descuento_d'] === '0.0000' || $r['descuento_d'] === null) ? (float)$r['descuento'] : (float)$r['descuento_d'];
        }
        $precio = (float)$r['precio'];
        $res[$idLibro] = ['precio' => $precio, 'descuento' => $desc, 'precio_final' => round($precio - $precio * $desc, 2), 'fuente' => 'crm'];
    }
    return $res;
}

/**
 * Títulos despachados de un pedido según sus documentos de WO: suma por producto WO, lo ya devuelto y lo
 * disponible ('clave' = producto WO).
 */
function dvd_titulos_de_documentos(PDO $bdd, array $pedido, array $documentos) {
    $porProducto = [];
    foreach ($documentos as $doc) {
        foreach ($doc['renglones'] as $r) {
            $pid = $r['producto_wo'];
            if (!$pid || $r['cantidad'] <= 0) continue;
            if (!isset($porProducto[$pid])) {
                $porProducto[$pid] = ['producto_wo' => $pid, 'descripcion' => $r['descripcion'], 'codigo' => $r['codigo'],
                                      'despachada' => 0, 'documentos' => [], 'ids_documentos' => [], 'bruto' => 0, 'neto' => 0];
            }
            $porProducto[$pid]['despachada'] += (int)round($r['cantidad']);
            $porProducto[$pid]['bruto'] += $r['cantidad'] * $r['valor_unitario'];
            $porProducto[$pid]['neto'] += $r['cantidad'] * $r['valor_unitario'] * (1 - $r['descuento']);
            $porProducto[$pid]['documentos'][$doc['id']] = $doc['documento'];
            $porProducto[$pid]['ids_documentos'][$doc['id']] = $doc['id'];
        }
    }
    $libros = dm_libros_por_producto($bdd, array_values($porProducto), $pedido['codigo']);
    // Producto que WO despachó en lugar del libro pedido (dvd_equivalencias_pedido): cuenta como el libro del pedido.
    foreach (dvd_equivalencias_pedido($bdd, $pedido['id'] ?? 0) as $pid => $e) {
        if (!isset($porProducto[$pid])) continue;
        $libros[$pid] = ['id' => (int)$e['id_libro'], 'libro' => $e['libro'], 'isbn' => (string)$e['isbn'], 'tipo_ed' => null];
        $porProducto[$pid]['descripcion'] = $e['libro'];
    }
    $devuelto = dvd_devuelto_agrupado($bdd, $pedido['clave']);
    $titulos = [];
    foreach ($porProducto as $pid => $p) {
        $ya = $devuelto[$pid] ?? 0;
        // Precio de la factura/remisión de WO (si el título viene en varios documentos con precios
        // distintos, el promedio ponderado por cantidad).
        $precioWo = null;
        if ($p['despachada'] > 0 && $p['bruto'] > 0) {
            $precioWo = ['precio' => round($p['bruto'] / $p['despachada'], 2), 'descuento' => round(1 - $p['neto'] / $p['bruto'], 4),
                         'precio_final' => round($p['neto'] / $p['despachada'], 2), 'fuente' => 'wo'];
        }
        unset($p['bruto'], $p['neto']);
        $titulos[] = array_merge($p, [
            'precio_wo'  => $precioWo,
            'clave'      => $pid,
            'id_libro'   => $libros[$pid]['id'] ?? null,
            'devuelta'   => $ya,
            'disponible' => max(0, $p['despachada'] - $ya),
            'documentos' => implode(', ', $p['documentos']),
            'ids_documentos' => implode(',', $p['ids_documentos']),
        ]);
    }
    return $titulos;
}

/**
 * Equivalencias por pedido (2026-11-19, la usuaria): WO despachó un producto distinto al libro pedido y debe contar como
 * ese libro (caso real: pedido #2089, la remisión CEUR 7751 trae AULA MAXIMA INICIAL CARTILLA SCRIPT, producto WO 1476,
 * y el pedido / la adopción tienen AULA MAXIMA INICIAL CARTILLA, libros.id 14). Tabla pedidos_equivalencias_wo
 * (fvd_asegurar_tablas). Lo devuelto se sigue contando por el producto de WO. Devuelve [producto_wo => ['id_libro','libro','isbn']].
 */
function dvd_equivalencias_pedido(PDO $bdd, $idPedido) {
    if (!(int)$idPedido) return [];
    try {
        $st = $bdd->prepare("SELECT e.producto_wo, e.id_libro, l.libro, TRIM(l.isbn) AS isbn FROM pedidos_equivalencias_wo e
                             JOIN libros l ON l.id = e.id_libro WHERE e.id_pedido = ?");
        $st->execute([(int)$idPedido]);
        return $st->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return []; // sin la tabla
    }
}

/** Libros solicitados en el CRM (libros_pedidos), para cuando WO no trae documentos ('clave' = id del libro). */
function dvd_titulos_crm(PDO $bdd, array $pedido) {
    $st = $bdd->prepare("SELECT lp.id_libro, SUM(lp.cantidad) AS solicitada, l.libro, TRIM(l.isbn) AS isbn, l.id_wo
                         FROM libros_pedidos lp JOIN libros l ON l.id = lp.id_libro WHERE lp.cod_pedido = ?
                         GROUP BY lp.id_libro HAVING solicitada > 0");
    $st->execute([(string)$pedido['codigo']]);
    $devuelto = dvd_devuelto_agrupado($bdd, $pedido['clave'], true);
    $titulos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $idLibro = (int)$l['id_libro'];
        $ya = $devuelto[$idLibro] ?? 0;
        $titulos[] = [
            'clave' => $idLibro, 'producto_wo' => (int)$l['id_wo'] ?: null, 'descripcion' => $l['libro'], 'codigo' => (string)$l['isbn'],
            'id_libro' => $idLibro, 'solicitada' => (int)$l['solicitada'], 'devuelta' => $ya,
            'disponible' => max(0, (int)$l['solicitada'] - $ya), 'documentos' => 'Solicitud CRM', 'ids_documentos' => '',
        ];
    }
    return $titulos;
}

/**
 * Detalle de una devolución de varios pedidos de venta del mismo colegio: una búsqueda en WO con las OP de
 * todos y los títulos sumados por libro, con el precio final de cada uno. Un pedido sin OP o sin
 * documentos en WO se carga con lo solicitado en el CRM. Un documento de una OP agrupada que comparten
 * varios de los pedidos elegidos se cuenta una sola vez (en el primero).
 * Devuelve ['ok','error','colegio','id_colegio','id_periodo','cliente','id_cliente','tipo','pedidos' =>
 * [resumen por pedido], 'titulos' => [... 'partes' => de qué pedido sale cada saldo], 'incluidos' => [claves]].
 */
function dvd_detalle_varios(PDO $bdd, array $claves) {
    $claves = array_values(array_unique(array_filter(array_map('dvd_clave_valida', $claves))));
    if (!$claves) return ['ok' => false, 'error' => 'Elige al menos un pedido.'];
    if (count($claves) > DVD_MAX_PEDIDOS) return ['ok' => false, 'error' => 'Puedes devolver hasta ' . DVD_MAX_PEDIDOS . ' pedidos a la vez.'];
    // Orden por número: el reparto descuenta primero del más antiguo.
    usort($claves, fn($a, $b) => (int)substr($a, 1) <=> (int)substr($b, 1));

    $pedidos = [];
    $opsAuto = [];
    $idColegio = null;
    foreach ($claves as $clave) {
        $p = dvd_obtener_pedido($bdd, $clave);
        if (!$p) return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($clave) . ' no existe, no está Entregado, su colegio no tiene canal de venta Venta directa - Eureka, o no está en tu alcance.'];
        if ($idColegio !== null && (int)$p['id_colegio'] !== $idColegio) return ['ok' => false, 'error' => 'Todos los pedidos de una devolución deben ser del mismo colegio.'];
        $idColegio = (int)$p['id_colegio'];
        $ops = dvd_ops_pedido($bdd, $clave);
        $pedidos[$clave] = ['pedido' => $p, 'ops' => $ops, 'cierre' => dvd_cierre_completo($bdd, $clave), 'precios' => dvd_precios_pedido($bdd, $p)];
        foreach ($ops as $op) $opsAuto[(int)$op['id']] = $op;
    }

    $docsPorOp = [];
    if ($opsAuto) {
        // Solo remisiones CEUR y facturas FVE (2026-10-26): un concepto de WO con la OP mal escrita trajo una
        // remisión de muestras RE22 como despacho de un pedido de venta (OP 6247 / RE22 8229).
        require_once __DIR__ . '/devoluciones_ventas_datos.php';
        $wo = dvv_documentos_de_ops($bdd, array_values($opsAuto), false);
        if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error']];
        foreach ($wo['documentos'] as $doc) $docsPorOp[(int)$doc['op']][] = $doc;
    }

    $agrupados = [];
    $resumen = [];
    $incluidos = [];
    $docsUsados = [];
    $cliente = null;
    foreach ($pedidos as $clave => $x) {
        $p = $x['pedido'];
        if (!$cliente && !empty($p['id_cliente'])) $cliente = $p;
        $info = ['clave' => $clave, 'id' => (int)$p['id'], 'etiqueta' => dvd_etiqueta($clave),
                 'fecha' => $p['fecha'] ? date('d/m/Y', strtotime($p['fecha'])) : '', 'solicitante' => $p['solicitante'],
                 'ops' => implode(', ', array_map(fn($o) => $o['anio'] . '-' . $o['id'], $x['ops'])), 'fuente' => 'auto',
                 'cierre' => $x['cierre'], 'documentos' => [], 'compartidos' => [], 'error' => null, 'sin_documentos' => false,
                 'unidades' => 0, 'disponible' => 0];
        $docs = [];
        foreach ($x['ops'] as $op) {
            foreach ($docsPorOp[(int)$op['id']] ?? [] as $doc) {
                if (isset($docsUsados[$doc['id']])) { $info['compartidos'][] = $doc['documento'] . ' (en ' . $docsUsados[$doc['id']] . ')'; continue; }
                $docsUsados[$doc['id']] = $info['etiqueta'];
                $docs[] = $doc;
            }
        }
        if ($docs) {
            $titulos = dvd_titulos_de_documentos($bdd, $p, $docs);
            foreach ($docs as $doc) { unset($doc['renglones']); $info['documentos'][] = $doc; }
        } else {
            $info['sin_documentos'] = true;
            $info['sin_op'] = !$x['ops'];
            $info['fuente'] = 'crm';
            $titulos = dvd_titulos_crm($bdd, $p);
            if (!$titulos) $info['error'] = 'No tiene libros registrados en el CRM.';
        }

        $crm = $info['fuente'] === 'crm';
        foreach ($titulos as $t) {
            $base = $crm ? (int)$t['solicitada'] : (int)$t['despachada'];
            $disponible = $x['cierre'] ? 0 : (int)$t['disponible'];
            // Saldo por el precio final (con descuento) del renglón de la factura/remisión de WO; solo los
            // pedidos sin documentos en WO usan el precio del CRM.
            $precio = !$t['id_libro'] ? null : ($crm ? ($x['precios'][(int)$t['id_libro']] ?? null) : $t['precio_wo']);
            $info['unidades'] += $base;
            if ($t['id_libro']) $info['disponible'] += $disponible;
            $k = $t['id_libro'] ? 'L' . $t['id_libro'] : 'W' . $t['producto_wo'];
            if (!isset($agrupados[$k])) {
                $agrupados[$k] = ['id_libro' => $t['id_libro'] ?: null, 'descripcion' => $t['descripcion'], 'codigo' => $t['codigo'],
                                  'base' => 0, 'devuelta' => 0, 'disponible' => 0, 'valor_disponible' => 0, 'sin_precio' => false,
                                  'documentos' => [], 'pedidos' => [], 'partes' => []];
            }
            $a = &$agrupados[$k];
            $a['base'] += $base;
            $a['devuelta'] += (int)$t['devuelta'];
            $a['disponible'] += $disponible;
            if ($precio) $a['valor_disponible'] += $disponible * $precio['precio_final'];
            elseif ($disponible > 0) $a['sin_precio'] = true;
            foreach ($crm ? ['Solicitud CRM ' . $info['etiqueta']] : explode(', ', $t['documentos']) as $etq) $a['documentos'][$etq] = $etq;
            $a['pedidos'][$clave] = ['clave' => $clave, 'etiqueta' => $info['etiqueta'], 'base' => ($a['pedidos'][$clave]['base'] ?? 0) + $base,
                                     'disponible' => ($a['pedidos'][$clave]['disponible'] ?? 0) + $disponible];
            $a['partes'][] = ['clave_pedido' => $clave, 'id_pedido' => (int)$p['id'], 'fuente' => $info['fuente'], 'clave' => (int)$t['clave'],
                              'producto_wo' => $t['producto_wo'] ?: null, 'base' => $base, 'disponible' => $disponible, 'cerrado' => (bool)$x['cierre'],
                              'ids_documentos' => (string)$t['ids_documentos'], 'precio' => $precio,
                              // Formato de venta directa (fvd_referencias): documentos de WO y precio del renglón, también de los paquetes.
                              'documentos' => $crm ? '' : (string)$t['documentos'], 'precio_wo' => $crm ? null : ($t['precio_wo'] ?? null)];
            unset($a);
            if ($t['id_libro'] && !$x['cierre']) $incluidos[$clave] = $clave;
        }
        $resumen[] = $info;
    }

    $titulos = array_values($agrupados);
    foreach ($titulos as &$t) {
        $t['documentos'] = implode(', ', $t['documentos']);
        $t['pedidos'] = array_values($t['pedidos']);
        $t['valor_disponible'] = round($t['valor_disponible'], 2);
    }
    unset($t);
    usort($titulos, fn($a, $b) => strcasecmp($a['descripcion'], $b['descripcion']));
    $primero = reset($pedidos)['pedido'];

    return ['ok' => true, 'error' => null, 'colegio' => $primero['colegio'], 'id_colegio' => $idColegio, 'id_periodo' => (int)$primero['id_periodo'],
            'cliente' => $cliente['cliente'] ?? null, 'id_cliente' => (int)($cliente['id_cliente'] ?? 0), 'tipo' => (int)($cliente['tipo'] ?? $primero['tipo']),
            'pedidos' => $resumen, 'titulos' => $titulos, 'incluidos' => array_values($incluidos)];
}

/**
 * Guarda UNA devolución de varios pedidos del mismo colegio y, con lo que no se devolvió, su documento de
 * saldo (SAL-n). $cantidades = [id_libro => cantidad]: el tope es lo disponible del libro sumado en todos
 * los pedidos; lo devuelto se reparte entre ellos, el más antiguo primero (libros_devol_v.reparto). Todos
 * los pedidos incluidos quedan cerrados (cierre 'completa'). Se recalcula todo con los pedidos bloqueados
 * (FOR UPDATE). $token evita registrar dos veces el mismo envío.
 * $idFormato: el formato de venta directa (formatos_vd) del que sale la legalización; es obligatorio, debe
 * estar activo, sin otra legalización activa y con exactamente los mismos pedidos (2026-10-21).
 * $enTransaccion($idDevolucion): se ejecuta antes del commit (el formato pasa de etapa en la misma
 * transacción); si devuelve un error se deshace todo. $permitirVacia: sin unidades a devolver (todo se
 * vendió) se registra la devolución sin libros y todo queda en el saldo (2026-10-22, paso 3 del formato).
 * Devuelve ['ok','error','id_devolucion','repetida','saldo' => ['id','numero','unidades','total'] | null].
 */
function dvd_guardar_devolucion(PDO $bdd, array $claves, array $cantidades, $observaciones, $token, $idFormato, ?callable $enTransaccion = null, $permitirVacia = false) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return ['ok' => false, 'error' => 'El formulario no es válido; recarga la página e inténtalo de nuevo.'];

    $st = $bdd->prepare("SELECT id FROM devoluciones_v WHERE token_registro = ?");
    $st->execute([$token]);
    if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id_devolucion' => (int)$ya, 'repetida' => true, 'saldo' => dvd_saldo_de_devolucion($bdd, (int)$ya)];

    $detalle = dvd_detalle_varios($bdd, $claves);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    $incluidos = $detalle['incluidos'];
    if (!$incluidos) return ['ok' => false, 'error' => 'Ninguno de los pedidos elegidos tiene títulos por devolver.'];
    if (!$detalle['id_cliente']) return ['ok' => false, 'error' => 'Ninguno de los pedidos tiene cliente; no se puede registrar la devolución.'];

    $porLibro = [];
    foreach ($detalle['titulos'] as $t) if ($t['id_libro']) $porLibro[(int)$t['id_libro']] = $t;
    $aDevolver = [];
    foreach ($cantidades as $idLibro => $cant) {
        if (!preg_match('/^\d*$/', trim((string)$cant))) return ['ok' => false, 'error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        $cant = (int)$cant;
        if ($cant === 0) continue;
        if (!isset($porLibro[(int)$idLibro])) return ['ok' => false, 'error' => 'Uno de los títulos no está en los pedidos elegidos (ni despachado en World Office ni en la solicitud del CRM).'];
        $aDevolver[(int)$idLibro] = $cant;
    }
    if (!$aDevolver && !$permitirVacia) return ['ok' => false, 'error' => 'Ingresa al menos una cantidad a devolver.'];

    $fuentePorPedido = [];
    $docsLabel = [];
    $opsLabel = [];
    foreach ($detalle['pedidos'] as $p) {
        if (!in_array($p['clave'], $incluidos, true)) continue;
        $fuentePorPedido[$p['clave']] = $p['fuente'];
        if ($p['ops'] !== '') $opsLabel[] = $p['ops'];
        foreach ($p['documentos'] as $d) $docsLabel[$d['documento']] = $d['documento'];
    }

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        // Bloqueo de los pedidos en orden de id: serializa devoluciones simultáneas de los mismos pedidos.
        $ids = array_map(fn($c) => (int)substr($c, 1), $incluidos);
        $bdd->prepare("SELECT id FROM pedidos WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id FOR UPDATE")->execute($ids);
        // Formato de venta directa: bloqueado, activo, sin otra legalización y con los mismos pedidos.
        $st = $bdd->prepare("SELECT id, ids_pedidos, estado FROM formatos_vd WHERE id = ? FOR UPDATE");
        $st->execute([(int)$idFormato]);
        $formato = $st->fetch(PDO::FETCH_ASSOC);
        $mismos = $formato ? explode(',', $formato['ids_pedidos']) : [];
        sort($mismos);
        $elegidos = $incluidos;
        sort($elegidos);
        if (!$formato || (int)$formato['estado'] !== 1) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'La legalización debe salir de un formato de venta directa activo.'];
        }
        if ($mismos !== $elegidos) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'Los pedidos no coinciden con los del formato de venta directa; vuelve a abrir la legalización desde el formato.'];
        }
        $st = $bdd->prepare("SELECT id FROM devoluciones_v WHERE id_formato_vd = ? AND estado <> " . DVD_ESTADO_DEVOL_ANULADA);
        $st->execute([(int)$idFormato]);
        if ($ya = $st->fetchColumn()) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'Este formato ya se legalizó con el registro #' . (int)$ya . '.'];
        }

        $devuelto = [];
        foreach ($incluidos as $clave) {
            if ($c = dvd_cierre_completo($bdd, $clave)) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($clave) . ' ya está completo: se cerró con la devolución #' . (int)$c['id'] . '.'];
            }
            $devuelto[$clave] = dvd_devuelto_agrupado($bdd, $clave, $fuentePorPedido[$clave] === 'crm');
        }

        $reparto = [];
        foreach ($aDevolver as $idLibro => $cant) {
            $t = $porLibro[$idLibro];
            $resta = $cant;
            $disponibleTotal = 0;
            $reparto[$idLibro] = [];
            foreach ($t['partes'] as $pa) {
                if ($pa['cerrado'] || !isset($devuelto[$pa['clave_pedido']])) continue;
                $ya = $devuelto[$pa['clave_pedido']][$pa['clave']] ?? 0;
                $disp = max(0, $pa['base'] - $ya);
                $disponibleTotal += $disp;
                $toma = min($resta, $disp);
                if ($toma <= 0) continue;
                $reparto[$idLibro][] = $pa + ['cantidad' => $toma];
                $devuelto[$pa['clave_pedido']][$pa['clave']] = $ya + $toma;
                $resta -= $toma;
            }
            if ($resta > 0) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'No puedes devolver ' . $cant . ' de "' . $t['descripcion'] . '": en los pedidos elegidos quedan ' . $disponibleTotal . ' disponibles.'];
            }
        }

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM devoluciones_v WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        $stL = $bdd->prepare("INSERT INTO libros_devol_v (cod_pedido, id_libro, cod_area, cantidad, producto_wo, cant_despachada, documentos_wo, reparto, created_at)
                              VALUES (?, ?, '', ?, ?, ?, ?, ?, NOW())");
        foreach ($aDevolver as $idLibro => $cant) {
            $t = $porLibro[$idLibro];
            $productoWo = null;
            $despachada = null;
            $idsDocs = [];
            foreach ($t['partes'] as $pa) {
                if ($pa['cerrado'] || !isset($devuelto[$pa['clave_pedido']])) continue;
                if ($productoWo === null && $pa['producto_wo']) $productoWo = (int)$pa['producto_wo'];
                if ($pa['fuente'] === 'auto') $despachada = (int)$despachada + $pa['base'];
                foreach (array_filter(explode(',', $pa['ids_documentos'])) as $idDoc) $idsDocs[$idDoc] = $idDoc;
            }
            $partes = array_map(fn($pa) => $pa['clave_pedido'] . ':' . $pa['cantidad'] . ':' . (int)$pa['producto_wo'], $reparto[$idLibro]);
            $stL->execute([$codigo, $idLibro, $cant, $productoWo, $despachada, mb_substr(implode(',', $idsDocs), 0, 255) ?: null, mb_substr(implode(',', $partes), 0, 500)]);
        }
        // Mismas columnas que php/devolucion.php (fecha_impre/fecha_proceso quedan en su valor por defecto).
        $bdd->prepare("INSERT INTO devoluciones_v (codigo, id_periodo, id_colegio, id_usuario, observaciones, cliente, tipo, estado,
                                                   ids_pedidos, ops_pedidos, documentos_wo, cierre, token_registro, id_formato_vd, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'completa', ?, ?, NOW())")
            ->execute([$codigo, $detalle['id_periodo'], $detalle['id_colegio'], (int)$_SESSION['id'], $observaciones, $detalle['id_cliente'], $detalle['tipo'],
                       implode(',', $incluidos), mb_substr(implode(', ', $opsLabel), 0, 255), mb_substr(implode(', ', $docsLabel), 0, 500), $token, (int)$idFormato]);
        $idDevolucion = (int)$bdd->lastInsertId();

        // Saldo: lo que quedó sin devolver de cada libro y pedido (ya descontado lo de esta devolución),
        // por el precio final del libro en ese pedido. Los títulos que no están en el catálogo del CRM no
        // tienen precio y no entran.
        $renglones = [];
        foreach ($porLibro as $idLibro => $t) {
            foreach ($t['partes'] as $pa) {
                if ($pa['cerrado'] || !isset($devuelto[$pa['clave_pedido']])) continue;
                $queda = max(0, $pa['base'] - ($devuelto[$pa['clave_pedido']][$pa['clave']] ?? 0));
                if ($queda <= 0) continue;
                $k = $pa['id_pedido'] . '|' . $idLibro;
                if (!isset($renglones[$k])) {
                    $pr = $pa['precio'] ?: ['precio' => 0, 'descuento' => 0, 'precio_final' => 0];
                    $renglones[$k] = ['id_pedido' => $pa['id_pedido'], 'id_libro' => $idLibro, 'cantidad' => 0] + $pr;
                }
                $renglones[$k]['cantidad'] += $queda;
            }
        }
        $saldo = null;
        if ($renglones) {
            $unidades = array_sum(array_column($renglones, 'cantidad'));
            $total = round(array_sum(array_map(fn($r) => $r['cantidad'] * $r['precio_final'], $renglones)), 2);
            $consecutivo = (int)$bdd->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM saldos_devolucion FOR UPDATE")->fetchColumn();
            $bdd->prepare("INSERT INTO saldos_devolucion (consecutivo, id_devolucion, id_colegio, id_periodo, cliente, ids_pedidos, unidades, total, id_usuario, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$consecutivo, $idDevolucion, $detalle['id_colegio'], $detalle['id_periodo'], $detalle['id_cliente'], implode(',', $incluidos),
                           $unidades, $total, (int)$_SESSION['id']]);
            $idSaldo = (int)$bdd->lastInsertId();
            $stS = $bdd->prepare("INSERT INTO saldos_devolucion_libros (id_saldo, id_pedido, id_libro, cantidad, precio, descuento, precio_final, subtotal)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($renglones as $r) {
                $stS->execute([$idSaldo, $r['id_pedido'], $r['id_libro'], $r['cantidad'], $r['precio'], $r['descuento'], $r['precio_final'],
                               round($r['cantidad'] * $r['precio_final'], 2)]);
            }
            $saldo = ['id' => $idSaldo, 'numero' => dvd_numero_saldo($consecutivo), 'unidades' => $unidades, 'total' => $total];
        }

        if ($enTransaccion && ($error = $enTransaccion($idDevolucion))) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => $error];
        }
        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_devolucion' => $idDevolucion, 'repetida' => false, 'saldo' => $saldo];
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        // Otro envío con el mismo token ganó la carrera: se devuelve esa devolución.
        if ($e->getCode() === '23000') {
            $st = $bdd->prepare("SELECT id FROM devoluciones_v WHERE token_registro = ?");
            $st->execute([$token]);
            if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id_devolucion' => (int)$ya, 'repetida' => true, 'saldo' => dvd_saldo_de_devolucion($bdd, (int)$ya)];
        }
        error_log('Devolución de venta directa ' . implode(',', $incluidos) . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la devolución: ' . $e->getMessage()];
    }
}

/** Saldo generado por una devolución: ['id','numero','unidades','total'] o null. */
function dvd_saldo_de_devolucion(PDO $bdd, $idDevolucion) {
    $st = $bdd->prepare("SELECT id, consecutivo, unidades, total FROM saldos_devolucion WHERE id_devolucion = ?");
    $st->execute([(int)$idDevolucion]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    return $s ? ['id' => (int)$s['id'], 'numero' => dvd_numero_saldo($s['consecutivo']), 'unidades' => (int)$s['unidades'], 'total' => (float)$s['total']] : null;
}

/**
 * Documento de saldo con sus renglones, dentro del alcance del usuario, o null. 'anulado' = su devolución
 * se anuló (los pedidos vuelven a quedar abiertos y el saldo ya no vale).
 */
function dvd_obtener_saldo(PDO $bdd, $idSaldo) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT s.*, c.colegio, cl.cliente AS cliente_nombre, d.estado AS estado_devolucion, d.observaciones,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM saldos_devolucion s
                         JOIN colegios c ON c.id = s.id_colegio
                         LEFT JOIN devoluciones_v d ON d.id = s.id_devolucion
                         LEFT JOIN clientes cl ON cl.id = s.cliente
                         LEFT JOIN usuarios u ON u.id = s.id_usuario
                         WHERE s.id = ?" . $alcance);
    $st->execute(array_merge([(int)$idSaldo], $params));
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['numero'] = dvd_numero_saldo($s['consecutivo']);
    $s['anulado'] = (int)$s['estado_devolucion'] === DVD_ESTADO_DEVOL_ANULADA;
    $st = $bdd->prepare("SELECT sl.*, l.libro, TRIM(l.isbn) AS isbn FROM saldos_devolucion_libros sl JOIN libros l ON l.id = sl.id_libro
                         WHERE sl.id_saldo = ? ORDER BY sl.id_pedido, l.libro");
    $st->execute([(int)$idSaldo]);
    $s['libros'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $s;
}

/** Saldos dentro del alcance del usuario, los más recientes primero. */
function dvd_listar_saldos(PDO $bdd) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT s.id, s.consecutivo, s.id_devolucion, s.ids_pedidos, s.unidades, s.total, s.created_at, c.colegio,
                                cl.cliente AS cliente_nombre, d.estado AS estado_devolucion, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM saldos_devolucion s
                         JOIN colegios c ON c.id = s.id_colegio
                         LEFT JOIN devoluciones_v d ON d.id = s.id_devolucion
                         LEFT JOIN clientes cl ON cl.id = s.cliente
                         LEFT JOIN usuarios u ON u.id = s.id_usuario
                         WHERE 1 = 1" . $alcance . "
                         ORDER BY s.consecutivo DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
