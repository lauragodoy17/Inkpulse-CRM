<?php
/**
 * /includes/devoluciones_ventas_datos.php
 * "Devolución de ventas" (menú Devoluciones → Ventas), pedido de la usuaria 2026-10-26: se maneja igual que
 * devoluciones de muestras (devoluciones_muestras.php) en vez del formulario manual colegios_devols.php →
 * solicitar_devol.php (que se conserva, sin enlace en el menú).
 *  - Colegio + período → todos sus pedidos de VENTA Entregados por devolver → documentos de World Office de sus
 *    OP (directas o agrupadas, OP en el concepto o asociados en op_pendiente.php): SOLO remisiones con prefijo
 *    CEUR y facturas FVE (regla de la usuaria) → títulos sumados por libro → UNA devolución con tope =
 *    despachado − ya devuelto. Un pedido sin OP o sin esos documentos se carga con lo solicitado en el CRM.
 *  - Toda devolución es parcial: el pedido queda Completado cuando ya no le quedan unidades por devolver
 *    (como muestras desde 2026-10-21). Sin saldo SAL-n (eso es solo de Legalización venta directa).
 *  - Desde 2026-11-10 también salen los colegios con canal Venta directa (antes se legalizaban en su propio módulo,
 *    que se quitó del menú); sus devoluciones quedan con devoluciones_v.venta_directa = 1.
 *
 * Se guarda en devoluciones_v + libros_devol_v (mismo flujo posterior en devolucion_colegio.php):
 *   devoluciones_v.ids_pedidos_venta — claves de los pedidos ("v210,v215"; NOT NULL = de este módulo)
 *   devoluciones_v.ops_pedidos / documentos_wo / cierre ('parcial') / token_registro
 *   libros_devol_v.producto_wo / cant_despachada / documentos_wo / reparto ("v210:3:5870,v215:2:5870")
 *   pedidos.unidades_despachadas_devol — unidades base (despachadas en WO o solicitadas) del último cálculo,
 *     para marcar Completado en la lista sin consultar WO.
 * Lo ya devuelto se cuenta junto con Legalización venta directa (dvd_devuelto()).
 *
 * Tipo de devolución (2026-10-26, devoluciones_v.tipo_devolucion; NULL = las anteriores = normal):
 *   'normal' (se muestra "Física") — lo de arriba: CEUR + FVE de los pedidos − lo ya devuelto de esos pedidos.
 *   'corte'  — solo lo REMISIONADO (CEUR) de los pedidos − TODAS las devoluciones de venta no anuladas del
 *              colegio en el período (normales, de corte, de venta directa y las del formulario manual
 *              anterior), por libro. Sin remisión no hay nada que devolver (no se usa lo solicitado en el CRM).
 * Venta directa (2026-11-19, la usuaria): solo lo remisionado en WO. Los libros se devuelven sueltos; un paquete solo si
 * WO trae el producto del paquete (código = paquetes_colegio.codigo), y sus libros salen de lo creado en Inkpulse
 * (dvv_vd_paquetes_wo). Sin "Libros sueltos de paquetes abiertos".
 */
require_once __DIR__ . '/devoluciones_vd_datos.php'; // dvd_* (pedidos, reparto, títulos) + dm_validar_acceso, dm_filtro_alcance_sql

/** Prefijo de World Office permitido por tipo de documento (regla de la usuaria 2026-10-26). */
const DVV_PREFIJOS = ['REM' => 'CEUR', 'FV' => 'FVE'];

/** Tipos de devolución de venta: valor guardado => etiqueta. */
// 'normal' se muestra como "Física" (pedido de la usuaria 2026-10-26); el valor guardado sigue siendo 'normal'.
const DVV_TIPOS_DEVOLUCION = ['normal' => 'Física', 'corte' => 'De corte'];

/**
 * Modo "Devolución de venta directa" (menú Venta directa, pedido de la usuaria 2026-11-10): el mismo flujo directo
 * de devoluciones de venta (sin formato de venta directa) pero solo con los pedidos de colegios cuya adopción tiene
 * el canal Venta directa - Eureka (dvd_sql_canal_vd). Se activa con ?vd=1 / vd=1 en la pantalla, el detalle y el
 * guardado. Las devoluciones guardadas así quedan con devoluciones_v.venta_directa = 1.
 */
function dvv_modo_vd($activar = null) {
    static $modo = null;
    if ($activar !== null) $modo = (bool)$activar;
    if ($modo === null) $modo = (string)($_REQUEST['vd'] ?? '') === '1';
    return $modo;
}

/** true si la adopción del colegio en el período tiene el canal Venta directa - Eureka (marca devoluciones_v.venta_directa). */
function dvv_colegio_es_vd(PDO $bdd, $idColegio, $idPeriodo) {
    $st = $bdd->prepare("SELECT 1 FROM recursos r WHERE r.id_colegio = ? AND r.id_periodo = ? AND " . sql_adopcion_tiene_canal(dvd_canales_formato($bdd)) . " LIMIT 1");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    return (bool)$st->fetchColumn();
}

/** URL de una pantalla del módulo conservando el modo venta directa. */
function dvv_url($url) {
    if (!dvv_modo_vd()) return $url;
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'vd=1';
}

/** 'normal' o 'corte' (cualquier otro valor = normal). */
function dvv_tipo_devolucion($tipo) {
    return $tipo === 'corte' ? 'corte' : 'normal';
}

/** Etiqueta de devoluciones_v.tipo_devolucion (NULL = Física). */
function dvv_etiqueta_tipo($tipo) {
    return DVV_TIPOS_DEVOLUCION[dvv_tipo_devolucion((string)$tipo)];
}

/**
 * Clave del MISMO libro en el catálogo, que tiene títulos repetidos (caso real 2026-10-26: "AVENTURAS DEL
 * CAPITAN CALZONCILLOS" es libros.id 1479 y 1725, mismo ISBN e id_wo; WO cae en uno y la adopción y las
 * devoluciones en el otro): 'w<id_wo>', si no 'i<isbn>', si no 'l<id>'. Devuelve [id_libro => clave].
 */
function dvv_claves_catalogo(PDO $bdd, array $idsLibro) {
    $idsLibro = array_values(array_unique(array_filter(array_map('intval', $idsLibro))));
    if (!$idsLibro) return [];
    $res = [];
    foreach ($bdd->query("SELECT id, id_wo, TRIM(isbn) AS isbn FROM libros WHERE id IN (" . implode(',', $idsLibro) . ")")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $res[(int)$l['id']] = (int)$l['id_wo'] > 0 ? 'w' . (int)$l['id_wo'] : ((string)$l['isbn'] !== '' ? 'i' . $l['isbn'] : 'l' . (int)$l['id']);
    }
    foreach ($idsLibro as $id) $res[$id] = $res[$id] ?? 'l' . $id;
    return $res;
}

/** Libros adoptados (presupuestos.definido = 1) del colegio en esos períodos: [id_libro => true]. */
function dvv_libros_adoptados(PDO $bdd, $idColegio, array $periodos) {
    $periodos = array_values(array_unique(array_map('intval', $periodos)));
    if (!$periodos) return [];
    $st = $bdd->prepare("SELECT DISTINCT id_libro FROM presupuestos WHERE id_colegio = ? AND id_periodo IN (" . implode(',', $periodos) . ") AND definido = 1");
    $st->execute([(int)$idColegio]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

/**
 * Lo devuelto del colegio (dvv_devuelto_colegio) sumado por clave de catálogo (dvv_claves_catalogo): así una
 * devolución registrada con un libros.id repetido cuenta para el mismo título. [clave => unidades].
 */
function dvv_devuelto_colegio_por_clave(PDO $bdd, $idColegio, array $periodos) {
    $porLibro = dvv_devuelto_colegio($bdd, $idColegio, $periodos);
    $claves = dvv_claves_catalogo($bdd, array_keys($porLibro));
    $res = [];
    foreach ($porLibro as $idLibro => $cant) $res[$claves[$idLibro]] = ($res[$claves[$idLibro]] ?? 0) + $cant;
    return $res;
}

/**
 * Unidades de TODAS las devoluciones de venta no anuladas del colegio en esos períodos, por libro (para el
 * corte): [id_libro => unidades].
 */
function dvv_devuelto_colegio(PDO $bdd, $idColegio, array $periodos) {
    $periodos = array_values(array_unique(array_map('intval', $periodos)));
    if (!$periodos) return [];
    $st = $bdd->prepare("SELECT ld.id_libro, SUM(ld.cantidad) FROM libros_devol_v ld JOIN devoluciones_v d ON d.codigo = ld.cod_pedido
                         WHERE d.id_colegio = ? AND d.id_periodo IN (" . implode(',', $periodos) . ") AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . "
                         GROUP BY ld.id_libro");
    $st->execute([(int)$idColegio]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Columnas. Antes de cualquier transacción (DDL = commit implícito). */
function dvv_asegurar_columnas(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    dvd_asegurar_columnas($bdd);
    if (!$bdd->query("SHOW COLUMNS FROM pedidos LIKE 'unidades_despachadas_devol'")->fetch()) {
        try { $bdd->exec("ALTER TABLE pedidos ADD COLUMN unidades_despachadas_devol INT NULL DEFAULT NULL"); } catch (Exception $e) { /* ya existe */ }
    }
    if (!$bdd->query("SHOW COLUMNS FROM devoluciones_v LIKE 'tipo_devolucion'")->fetch()) {
        try { $bdd->exec("ALTER TABLE devoluciones_v ADD COLUMN tipo_devolucion VARCHAR(10) NULL DEFAULT NULL"); } catch (Exception $e) { /* ya existe */ }
    }
    // Devolución directa de venta directa (dvv_modo_vd, 2026-11-10).
    if (!$bdd->query("SHOW COLUMNS FROM devoluciones_v LIKE 'venta_directa'")->fetch()) {
        try { $bdd->exec("ALTER TABLE devoluciones_v ADD COLUMN venta_directa TINYINT NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ya existe */ }
    }
}

/**
 * SQL (alias x = pedidos) que deja fuera los colegios que se legalizan en venta directa (dvd_canales_formato) en
 * el período del pedido, y los pedidos marcados "para Andrea" (lo contrario de dvd_sql_canal_vd). Con dos canales
 * basta que uno sea de ese grupo: así cada pedido sale en una sola pantalla.
 */
function dvv_sql_sin_canal_vd(PDO $bdd) {
    sobrecosto_asegurar_columna($bdd);
    asegurar_columnas_pedido_vd($bdd);
    // Modo venta directa (?vd=1): solo los pedidos de venta directa.
    if (dvv_modo_vd()) return dvd_sql_canal_vd($bdd);
    // 2026-11-10 (pedido de la usuaria): se quitó del menú la Legalización venta directa; desde ahora TODOS los
    // pedidos de venta, también los de colegios con canal Venta directa - Eureka, se devuelven en devoluciones_ventas.php.
    return '';
}

/**
 * Pedidos de venta Entregados (sin canal Venta directa) dentro del alcance del usuario, con sus OP, cuántas
 * devoluciones activas tienen y 'completado' (ya no queda nada por devolver, o cerrado con una legalización
 * completa). Con $idPeriodo, solo los de ese período.
 */
function dvv_listar_pedidos(PDO $bdd, $idPeriodo = null) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $filtroPer = $idPeriodo ? ' AND x.id_periodo = ' . (int)$idPeriodo : '';
    $st = $bdd->prepare("SELECT CONCAT('v', x.id) AS clave, x.id, x.fecha, x.unidades_despachadas_devol, c.id AS id_colegio, c.colegio,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN usuarios u ON u.id = x.id_usuario
                         WHERE x.estado = " . DVD_ESTADO_ENTREGADO . $filtroPer . dvv_sql_sin_canal_vd($bdd) . $alcance . "
                         ORDER BY x.id DESC");
    $st->execute($params);
    $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

    $cerrados = [];
    foreach ($bdd->query("SELECT ids_pedidos FROM devoluciones_v WHERE ids_pedidos IS NOT NULL AND cierre = 'completa' AND estado <> " . DVD_ESTADO_DEVOL_ANULADA) as $d) {
        foreach (explode(',', $d['ids_pedidos']) as $c) $cerrados[$c] = true;
    }
    // Venta directa: con DVV_MAX_DEVOL_VD devoluciones el pedido queda cerrado (dvv_cierre_vd).
    $nVd = [];
    foreach ($bdd->query("SELECT ids_pedidos_venta FROM devoluciones_v WHERE ids_pedidos_venta IS NOT NULL AND venta_directa = 1 AND estado <> " . DVD_ESTADO_DEVOL_ANULADA) as $d) {
        foreach (explode(',', str_replace(' ', '', $d['ids_pedidos_venta'])) as $c) {
            $nVd[$c] = ($nVd[$c] ?? 0) + 1;
            if ($nVd[$c] >= DVV_MAX_DEVOL_VD) $cerrados[$c] = true;
        }
    }
    $devuelto = [];
    $nDevoluciones = [];
    foreach (dvd_devuelto($bdd) as $x) $devuelto[$x['clave']] = ($devuelto[$x['clave']] ?? 0) + $x['cantidad'];
    foreach ($bdd->query("SELECT ids_pedidos_venta FROM devoluciones_v WHERE ids_pedidos_venta IS NOT NULL AND estado <> " . DVD_ESTADO_DEVOL_ANULADA) as $d) {
        foreach (explode(',', $d['ids_pedidos_venta']) as $c) $nDevoluciones[$c] = ($nDevoluciones[$c] ?? 0) + 1;
    }
    foreach ($pedidos as &$p) {
        $base = $p['unidades_despachadas_devol'];
        $p['n_devoluciones'] = $nDevoluciones[$p['clave']] ?? 0;
        $p['completado'] = isset($cerrados[$p['clave']])
            || ($base !== null && (int)$base > 0 && (int)($devuelto[$p['clave']] ?? 0) >= (int)$base);
    }
    unset($p);
    return $pedidos;
}

/**
 * Venta directa: devoluciones permitidas por pedido. 2026-11-11 era UNA; 2026-11-24 (la usuaria) pasan a DOS: la
 * segunda solo con lo que quedó sin devolver tras la primera (despachado − devuelto, que ya calcula el detalle).
 */
const DVV_MAX_DEVOL_VD = 2;

/** Devoluciones de venta directa activas (venta_directa = 1, no anuladas) del pedido, en orden: [['id','fecha','usuario']]. */
function dvv_devoluciones_vd(PDO $bdd, $clave) {
    $st = $bdd->prepare("SELECT d.id, d.created_at AS fecha, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM devoluciones_v d LEFT JOIN usuarios u ON u.id = d.id_usuario
                         WHERE FIND_IN_SET(?, REPLACE(d.ids_pedidos_venta, ' ', '')) AND d.venta_directa = 1 AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . "
                         ORDER BY d.id");
    $st->execute([(string)$clave]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Un pedido de venta directa con DVV_MAX_DEVOL_VD devoluciones activas queda cerrado: no suma disponible ni deja
 * registrar otra. Si una se anula, el pedido se vuelve a abrir. Devuelve la última ['id','fecha','usuario','tipo' => 'vd',
 * 'ids' => [ids de sus devoluciones]] o null.
 */
function dvv_cierre_vd(PDO $bdd, $clave) {
    $devs = dvv_devoluciones_vd($bdd, $clave);
    if (count($devs) < DVV_MAX_DEVOL_VD) return null;
    return end($devs) + ['tipo' => 'vd', 'ids' => array_map(fn($d) => (int)$d['id'], $devs)];
}

/** Cierre del pedido: legalización completa (dvd_cierre_completo) o su devolución de venta directa (dvv_cierre_vd). */
function dvv_cierre(PDO $bdd, $clave) {
    return dvd_cierre_completo($bdd, $clave) ?: dvv_cierre_vd($bdd, $clave);
}

/** Pedido de venta Entregado (sin canal Venta directa) dentro del alcance del usuario, o null (mismos campos que dvd_obtener_pedido). */
function dvv_obtener_pedido(PDO $bdd, $clave) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT x.id, x.codigo, x.fecha, x.id_colegio, x.id_periodo, x.tipo, x.para_andrea, c.colegio,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante, cl.id AS id_cliente, cl.cliente
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN usuarios u ON u.id = x.id_usuario
                         LEFT JOIN clientes cl ON cl.id = x.cliente
                         WHERE x.id = ? AND x.estado = " . DVD_ESTADO_ENTREGADO . dvv_sql_sin_canal_vd($bdd) . $alcance);
    $st->execute(array_merge([(int)substr($clave, 1)], $params));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ? ['clave' => $clave] + $p : null;
}

/**
 * Documentos de World Office de las OP (wo_documentos_por_op) que sirven para devoluciones de venta: REM con
 * prefijo CEUR y FV con prefijo FVE. Los asociados a mano en op_pendiente.php no traen el prefijo de WO, así
 * que se toma del tipo de documento elegido allá (tipo_doc: "REM RCEUR", "FVE"). Con $soloRemisiones (corte)
 * no entran las facturas.
 */
function dvv_documentos_de_ops(PDO $bdd, array $ops, $soloRemisiones = false) {
    $wo = wo_documentos_por_op($bdd, $ops);
    if (!$wo['ok']) return $wo;
    $tipoAsociado = [];
    $ids = array_map(fn($d) => (int)$d['id'], array_filter($wo['documentos'], fn($d) => ($d['origen'] ?? '') === 'op_pendiente'));
    if ($ids) {
        $st = $bdd->query("SELECT h.id_wo, t.tipo FROM historial_documento_op h LEFT JOIN tipo_doc t ON t.id = h.tipo_doc
                           WHERE h.id_wo IN (" . implode(',', $ids) . ") ORDER BY h.id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tipoAsociado[(int)$r['id_wo']] = strtoupper((string)$r['tipo']);
    }
    $wo['documentos'] = array_values(array_filter($wo['documentos'], function ($d) use ($tipoAsociado, $soloRemisiones) {
        if ($soloRemisiones && $d['tipo'] !== 'REM') return false;
        $prefijo = DVV_PREFIJOS[$d['tipo']] ?? null;
        if ($prefijo === null) return false;
        $texto = ($d['origen'] ?? '') === 'op_pendiente'
            ? ($tipoAsociado[(int)$d['id']] ?? '')
            : strtoupper(strtok(trim($d['documento']), ' '));
        return strpos($texto, $prefijo) !== false;
    }));
    return $wo;
}

/**
 * Detalle de una devolución de varios pedidos de venta del mismo colegio: una búsqueda en WO con las OP de
 * todos y los títulos sumados por libro. Un pedido sin OP o sin documentos CEUR/FVE se carga con lo
 * solicitado en el CRM. Un documento de una OP agrupada que comparten varios pedidos elegidos se cuenta una
 * sola vez (en el primero). Guarda las unidades base de cada pedido (pedidos.unidades_despachadas_devol).
 * $tipoDevolucion 'corte': solo remisiones CEUR, sin lo solicitado en el CRM, y por libro disponible =
 * remisionado − todas las devoluciones del colegio (dvv_devuelto_colegio()); 'partes' sigue por pedido para
 * repartir. $sinCrm (Saldos de colegio): un pedido sin documentos CEUR/FVE no toma lo solicitado en el CRM (no
 * aporta títulos) y no se actualiza pedidos.unidades_despachadas_devol.
 * Devuelve ['ok','error','tipo_devolucion','colegio','id_colegio','id_periodo','cliente','id_cliente',
 * 'clientes_distintos','tipo','pedidos' => [resumen por pedido], 'titulos' => [... 'partes'], 'incluidos' => [claves]].
 */
function dvv_detalle_varios(PDO $bdd, array $claves, $tipoDevolucion = 'normal', $sinCrm = false, ?array $docsSel = null) {
    // $docsSel (venta directa, 2026-11-10): solo los documentos de WO elegidos (ids) y, de los pedidos sin documentos,
    // los marcados 'crm:v210'. El disponible de cada título es lo despachado en esos documentos, sin pasar de lo que
    // le queda al pedido por devolver (todo lo despachado − todo lo devuelto). null = todos (como antes).
    if ($docsSel !== null) $docsSel = array_values(array_map('strval', $docsSel));
    $corte = dvv_tipo_devolucion($tipoDevolucion) === 'corte';
    $claves = array_values(array_unique(array_filter(array_map('dvd_clave_valida', $claves))));
    if (!$claves) return ['ok' => false, 'error' => 'Elige al menos un pedido.'];
    if (count($claves) > DVD_MAX_PEDIDOS) return ['ok' => false, 'error' => 'Puedes devolver hasta ' . DVD_MAX_PEDIDOS . ' pedidos a la vez.'];
    // Orden por número: el reparto descuenta primero del más antiguo.
    usort($claves, fn($a, $b) => (int)substr($a, 1) <=> (int)substr($b, 1));

    $pedidos = [];
    $opsAuto = [];
    $idColegio = null;
    foreach ($claves as $clave) {
        $p = dvv_obtener_pedido($bdd, $clave);
        if (!$p) return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($clave) . ' no existe, no está Entregado, su colegio es de Venta directa o no está en tu alcance.'];
        if ($idColegio !== null && (int)$p['id_colegio'] !== $idColegio) return ['ok' => false, 'error' => 'Todos los pedidos de una devolución deben ser del mismo colegio.'];
        $idColegio = (int)$p['id_colegio'];
        $ops = dvd_ops_pedido($bdd, $clave);
        $pedidos[$clave] = ['pedido' => $p, 'ops' => $ops, 'cierre' => dvv_cierre($bdd, $clave), 'devoluciones_vd' => dvv_devoluciones_vd($bdd, $clave)];
        foreach ($ops as $op) $opsAuto[(int)$op['id']] = $op;
    }

    $docsPorOp = [];
    if ($opsAuto) {
        $wo = dvv_documentos_de_ops($bdd, array_values($opsAuto), $corte);
        if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error']];
        foreach ($wo['documentos'] as $doc) $docsPorOp[(int)$doc['op']][] = $doc;
    }

    $agrupados = [];
    $resumen = [];
    $incluidos = [];
    $docsUsados = [];
    $cliente = null;
    $clientes = [];
    $stBase = $bdd->prepare("UPDATE pedidos SET unidades_despachadas_devol = ? WHERE id = ? AND NOT (unidades_despachadas_devol <=> ?)");
    // Venta directa: se calcula antes del recorrido para pasar los paquetes remisionados en WO a sus libros (dvv_vd_paquetes_wo).
    $primeroPed = reset($pedidos)['pedido'];
    $esVd = !$corte && (dvv_colegio_es_vd($bdd, $idColegio, (int)$primeroPed['id_periodo']) || array_filter($pedidos, fn($x) => !empty($x['pedido']['para_andrea'])));
    $paqPorCodigo = $esVd ? dvv_paquetes_por_codigo($bdd, $idColegio, (int)$primeroPed['id_periodo']) : [];
    $paqWo = ['paq' => [], 'lib' => []]; // paquetes de WO (documentos elegidos): id_paquete => n; id_libro => unidades en paquete
    foreach ($pedidos as $clave => $x) {
        $p = $x['pedido'];
        if (!empty($p['id_cliente'])) {
            if (!$cliente) $cliente = $p;
            $clientes[(int)$p['id_cliente']] = true;
        }
        $info = ['clave' => $clave, 'id' => (int)$p['id'], 'etiqueta' => dvd_etiqueta($clave),
                 'fecha' => $p['fecha'] ? date('d/m/Y', strtotime($p['fecha'])) : '', 'solicitante' => $p['solicitante'],
                 'cliente' => $p['cliente'] ?? '',
                 'ops' => implode(', ', array_map(fn($o) => $o['anio'] . '-' . $o['id'], $x['ops'])), 'fuente' => 'auto',
                 'cierre' => $x['cierre'], 'devoluciones_vd' => array_map(fn($d) => ['id' => (int)$d['id'], 'fecha' => $d['fecha'] ? date('d/m/Y', strtotime($d['fecha'])) : ''], $x['devoluciones_vd']),
                 'documentos' => [], 'compartidos' => [], 'error' => null, 'sin_documentos' => false,
                 'unidades' => 0, 'disponible' => 0];
        $docs = [];
        foreach ($x['ops'] as $op) {
            foreach ($docsPorOp[(int)$op['id']] ?? [] as $doc) {
                if (isset($docsUsados[$doc['id']])) { $info['compartidos'][] = $doc['documento'] . ' (en ' . $docsUsados[$doc['id']] . ')'; continue; }
                $docsUsados[$doc['id']] = $info['etiqueta'];
                $docs[] = $doc;
            }
        }
        if ($docs && $docsSel !== null) {
            $sel = array_values(array_filter($docs, fn($d) => in_array((string)$d['id'], $docsSel, true)));
            $todos = [];
            foreach (dvd_titulos_de_documentos($bdd, $p, $docs) as $tt) $todos[$tt['clave']] = $tt;
            $titulos = $sel ? dvd_titulos_de_documentos($bdd, $p, $sel) : [];
            if ($paqPorCodigo) {
                $nada = ['paq' => [], 'lib' => []];
                $todos = array_column(dvv_vd_paquetes_wo($bdd, $p, array_values($todos), $paqPorCodigo, $nada), null, 'clave');
                $titulos = dvv_vd_paquetes_wo($bdd, $p, $titulos, $paqPorCodigo, $paqWo);
            }
            foreach ($titulos as &$tt) {
                $tot = $todos[$tt['clave']] ?? null;
                $tt['tope'] = min((int)$tt['despachada'], $tot ? (int)$tot['disponible'] : 0);
                $tt['disponible'] = $tt['tope'];
                $tt['devuelta'] = $tot ? (int)$tot['devuelta'] : 0;
                $tt['despachada_total'] = $tot ? (int)$tot['despachada'] : (int)$tt['despachada'];
            }
            unset($tt);
            foreach ($docs as $doc) { unset($doc['renglones']); $doc['elegido'] = in_array((string)$doc['id'], $docsSel, true); $info['documentos'][] = $doc; }
        } elseif ($docs) {
            $titulos = dvd_titulos_de_documentos($bdd, $p, $docs);
            if ($paqPorCodigo) $titulos = dvv_vd_paquetes_wo($bdd, $p, $titulos, $paqPorCodigo, $paqWo);
            foreach ($docs as $doc) { unset($doc['renglones']); $info['documentos'][] = $doc; }
        } elseif ($docsSel !== null && !$corte && !$sinCrm && !in_array('crm:' . $clave, $docsSel, true)) {
            // Venta directa: pedido sin documentos en WO que no se eligió.
            $info['sin_documentos'] = true;
            $info['sin_op'] = !$x['ops'];
            $info['fuente'] = 'crm';
            $info['crm_elegido'] = false;
            $titulos = [];
        } elseif ($corte || $sinCrm) {
            // Corte (o saldo): sin documento en WO no hay nada remisionado.
            $info['sin_documentos'] = true;
            $info['sin_op'] = !$x['ops'];
            $titulos = [];
        } else {
            $info['sin_documentos'] = true;
            $info['sin_op'] = !$x['ops'];
            $info['fuente'] = 'crm';
            $info['crm_elegido'] = true;
            $titulos = dvd_titulos_crm($bdd, $p);
            if (!$titulos) $info['error'] = 'No tiene libros registrados en el CRM.';
        }

        $crm = $info['fuente'] === 'crm';
        foreach ($titulos as $t) {
            $base = $crm ? (int)$t['solicitada'] : (int)$t['despachada'];
            $disponible = $x['cierre'] ? 0 : (int)$t['disponible'];
            $info['unidades'] += $base;
            if ($t['id_libro']) $info['disponible'] += $disponible;
            $k = $t['id_libro'] ? 'L' . $t['id_libro'] : 'W' . $t['producto_wo'];
            if (!isset($agrupados[$k])) {
                $agrupados[$k] = ['id_libro' => $t['id_libro'] ?: null, 'descripcion' => $t['descripcion'], 'codigo' => $t['codigo'],
                                  'base' => 0, 'devuelta' => 0, 'disponible' => 0, 'documentos' => [], 'pedidos' => [], 'partes' => []];
            }
            $a = &$agrupados[$k];
            $a['base'] += $base;
            $a['devuelta'] += (int)$t['devuelta'];
            $a['disponible'] += $disponible;
            foreach ($crm ? ['Solicitud CRM ' . $info['etiqueta']] : explode(', ', $t['documentos']) as $etq) $a['documentos'][$etq] = $etq;
            $a['pedidos'][$clave] = ['clave' => $clave, 'id' => (int)$p['id'], 'base' => ($a['pedidos'][$clave]['base'] ?? 0) + $base,
                                     'disponible' => ($a['pedidos'][$clave]['disponible'] ?? 0) + $disponible];
            // Con documentos elegidos, el reparto descuenta de todo lo despachado del pedido (base) con el tope de lo
            // despachado en los documentos elegidos ('tope').
            $a['partes'][] = ['clave_pedido' => $clave, 'fuente' => $info['fuente'], 'clave' => (int)$t['clave'],
                              'producto_wo' => $t['producto_wo'] ?: null, 'base' => (int)($t['despachada_total'] ?? $base), 'cerrado' => (bool)$x['cierre'],
                              'ids_documentos' => (string)$t['ids_documentos'], 'tope' => $t['tope'] ?? null];
            unset($a);
            if ($t['id_libro'] && !$x['cierre']) $incluidos[$clave] = $clave;
        }
        // Unidades base para marcar Completado en la lista (como muestreos.unidades_despachadas_wo); el corte
        // solo cuenta remisiones, así que no la actualiza.
        if (!$corte && !$sinCrm && $docsSel === null && !$bdd->inTransaction()) $stBase->execute([$info['unidades'], (int)$p['id'], $info['unidades']]);
        $resumen[] = $info;
    }

    $primero = reset($pedidos)['pedido'];
    $periodosPedidos = array_map(fn($x) => (int)$x['pedido']['id_periodo'], $pedidos);

    // Un mismo libro repetido en el catálogo (dvv_claves_catalogo) es UNA fila: se junta con el libros.id que
    // adoptó el colegio (si ninguno está adoptado, el menor).
    $idsTitulos = [];
    foreach ($agrupados as $a) if ($a['id_libro']) $idsTitulos[] = (int)$a['id_libro'];
    $clavesCat = dvv_claves_catalogo($bdd, $idsTitulos);
    $adoptados = dvv_libros_adoptados($bdd, $idColegio, $periodosPedidos);
    $unidos = [];
    foreach ($agrupados as $k => $a) {
        $kc = $a['id_libro'] ? 'C' . $clavesCat[(int)$a['id_libro']] : $k;
        if (!isset($unidos[$kc])) { $unidos[$kc] = $a + ['clave_catalogo' => $a['id_libro'] ? $clavesCat[(int)$a['id_libro']] : null]; continue; }
        $u = &$unidos[$kc];
        $actual = (int)$u['id_libro'];
        $otro = (int)$a['id_libro'];
        $usarOtro = (!isset($adoptados[$actual]) && isset($adoptados[$otro])) || (isset($adoptados[$actual]) === isset($adoptados[$otro]) && $otro < $actual);
        if ($usarOtro) { $u['id_libro'] = $otro; $u['descripcion'] = $a['descripcion']; $u['codigo'] = $a['codigo']; }
        $u['base'] += $a['base'];
        $u['devuelta'] += $a['devuelta'];
        $u['disponible'] += $a['disponible'];
        $u['documentos'] += $a['documentos'];
        foreach ($a['pedidos'] as $c => $tp) {
            if (!isset($u['pedidos'][$c])) { $u['pedidos'][$c] = $tp; continue; }
            $u['pedidos'][$c]['base'] += $tp['base'];
            $u['pedidos'][$c]['disponible'] += $tp['disponible'];
        }
        $u['partes'] = array_merge($u['partes'], $a['partes']);
        // El reparto descuenta primero del pedido más antiguo.
        usort($u['partes'], fn($x, $y) => (int)substr($x['clave_pedido'], 1) <=> (int)substr($y['clave_pedido'], 1));
        unset($u);
    }

    // Corte: lo ya devuelto es TODO lo devuelto del colegio en los períodos de los pedidos, por libro.
    $devueltoColegio = $corte ? dvv_devuelto_colegio_por_clave($bdd, $idColegio, $periodosPedidos) : [];
    $titulos = array_values($unidos);
    foreach ($titulos as &$t) {
        $t['documentos'] = implode(', ', $t['documentos']);
        $t['pedidos'] = array_values($t['pedidos']);
        $t['adoptado'] = $t['id_libro'] && isset($adoptados[(int)$t['id_libro']]);
        if ($corte && $t['id_libro']) {
            $t['devuelta'] = $devueltoColegio[$t['clave_catalogo']] ?? 0;
            $t['disponible'] = max(0, $t['base'] - $t['devuelta']);
        }
    }
    unset($t);
    $ordenGrado = [];
    $gradoDe = dvv_grados_adopcion($bdd, $idColegio, (int)$primero['id_periodo'], array_map(fn($t) => (int)$t['id_libro'], $titulos), $ordenGrado, $planDe);
    foreach ($titulos as &$t) {
        $t['grado'] = $t['id_libro'] ? ($gradoDe[(int)$t['id_libro']] ?? '') : '';
        $t['grado_orden'] = $t['id_libro'] ? ($ordenGrado[(int)$t['id_libro']] ?? 99) : 99;
        $t['plan_lector'] = $t['id_libro'] && isset($planDe[(int)$t['id_libro']]);
    }
    unset($t);
    // Por curso (el primer grado de la adopción del libro, Pre jardín → 11) y dentro del curso por título (2026-11-19,
    // como en el pedido). Sin grado, al final. Los libros sueltos de venta directa siguen este mismo orden.
    // Alfabético sin distinguir tildes (2026-11-20). 2026-11-23: en cada curso el plan lector va después de los particulares.
    require_once __DIR__ . '/plan_lector.php';
    usort($titulos, fn($a, $b) => plan_lector_comparar(['plan' => $a['plan_lector'], 'grado' => $a['grado_orden'], 'titulo' => $a['descripcion']],
                                                       ['plan' => $b['plan_lector'], 'grado' => $b['grado_orden'], 'titulo' => $b['descripcion']]));
    if ($corte) {
        // Solo entran los pedidos que aportan algún título con disponible del colegio.
        $conDisponible = [];
        foreach ($titulos as $t) {
            if (!$t['id_libro'] || $t['disponible'] <= 0) continue;
            foreach ($t['pedidos'] as $tp) if ($tp['disponible'] > 0) $conDisponible[$tp['clave']] = true;
        }
        $incluidos = array_filter($incluidos, fn($c) => isset($conDisponible[$c]));
    }

    // Reparto por curso de los títulos pedidos en varios cursos (dvv_cursos_titulos, 2026-11-24).
    $cursos = dvv_cursos_titulos($bdd, array_values($incluidos), $idColegio, $titulos);
    foreach ($titulos as &$t) $t['cursos'] = $t['id_libro'] ? ($cursos[(int)$t['id_libro']] ?? []) : [];
    unset($t);

    // Venta directa: paquetes remisionados en WO y libros sueltos (dvv_vd_estructura).
    $vd = $esVd ? dvv_vd_estructura($bdd, array_values($incluidos), $idColegio, (int)$primero['id_periodo'], $titulos, $paqWo) : null;


    return ['ok' => true, 'error' => null, 'tipo_devolucion' => $corte ? 'corte' : 'normal', 'es_vd' => (bool)$esVd, 'vd' => $vd, 'con_seleccion' => $docsSel !== null, 'colegio' => $primero['colegio'], 'id_colegio' => $idColegio, 'id_periodo' => (int)$primero['id_periodo'],
            'cliente' => $cliente['cliente'] ?? null, 'id_cliente' => (int)($cliente['id_cliente'] ?? 0), 'clientes_distintos' => count($clientes) > 1,
            'tipo' => (int)($cliente['tipo'] ?? $primero['tipo']),
            'pedidos' => $resumen, 'titulos' => $titulos, 'incluidos' => array_values($incluidos)];
}

/** Código de producto normalizado para comparar el de WO con paquetes_colegio.codigo. */
function dvv_codigo_norm($c) {
    return strtoupper(preg_replace('/\s+/', '', (string)$c));
}

/** Paquetes del colegio en el período por código normalizado: [codigo => ['id','paquete','codigo','precio','libros','titulos']]. */
function dvv_paquetes_por_codigo(PDO $bdd, $idColegio, $idPeriodo) {
    require_once __DIR__ . '/formatos_vd_datos.php'; // fvd_paquetes_colegio
    $res = [];
    foreach (fvd_paquetes_colegio($bdd, $idColegio, $idPeriodo) as $p) {
        $c = dvv_codigo_norm($p['codigo']);
        if ($c !== '' && $p['libros']) $res[$c] = $p;
    }
    return $res;
}

/**
 * Venta directa (2026-11-19, la usuaria): solo cuenta lo remisionado en WO. Si un documento trae el PRODUCTO de un
 * paquete (su código = paquetes_colegio.codigo), ese renglón se pasa a los libros del paquete tal como está creado en
 * Inkpulse: cada paquete despachado = 1 unidad de cada libro (se suma al título del mismo producto WO, libros.id_wo,
 * o entra como título nuevo). $paqWo (salida) acumula ['paq' => [id_paquete => paquetes], 'lib' => [id_libro => unidades
 * en paquete]] para dvv_vd_estructura. Libros del paquete sin id_wo no entran (no hay cómo contar lo ya devuelto).
 */
function dvv_vd_paquetes_wo(PDO $bdd, array $pedido, array $titulos, array $paqPorCodigo, array &$paqWo) {
    $extra = []; // id_libro => ['n','docs' => [doc => doc],'ids' => [id => id]]
    $res = [];
    foreach ($titulos as $t) {
        $p = $t['id_libro'] ? null : ($paqPorCodigo[dvv_codigo_norm($t['codigo'])] ?? null);
        if (!$p) { $res[] = $t; continue; }
        $n = (int)$t['despachada'];
        if ($n <= 0) continue;
        $paqWo['paq'][$p['id']] = ($paqWo['paq'][$p['id']] ?? 0) + $n;
        foreach ($p['libros'] as $l) {
            $l = (int)$l;
            $paqWo['lib'][$l] = ($paqWo['lib'][$l] ?? 0) + $n;
            $extra[$l]['n'] = ($extra[$l]['n'] ?? 0) + $n;
            foreach (array_filter(explode(', ', (string)$t['documentos'])) as $d) $extra[$l]['docs'][$d] = $d;
            foreach (array_filter(explode(',', (string)$t['ids_documentos'])) as $d) $extra[$l]['ids'][$d] = $d;
        }
    }
    if (!$extra) return $res;
    $libros = $bdd->query("SELECT id, libro, TRIM(isbn) AS isbn, id_wo FROM libros WHERE id IN (" . implode(',', array_keys($extra)) . ")")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $devuelto = dvd_devuelto_agrupado($bdd, $pedido['clave']);
    $porProducto = [];
    foreach ($res as $i => $t) if ($t['producto_wo']) $porProducto[(int)$t['producto_wo']] = $i;
    foreach ($extra as $l => $x) {
        $pid = (int)($libros[$l]['id_wo'] ?? 0);
        if ($pid <= 0) continue;
        $i = $porProducto[$pid] ?? null;
        if ($i === null) {
            $res[] = ['producto_wo' => $pid, 'descripcion' => $libros[$l]['libro'], 'codigo' => (string)$libros[$l]['isbn'], 'despachada' => 0,
                      'documentos' => '', 'ids_documentos' => '', 'precio_wo' => null, 'clave' => $pid, 'id_libro' => $l,
                      'devuelta' => (int)($devuelto[$pid] ?? 0), 'disponible' => 0];
            $i = $porProducto[$pid] = array_key_last($res);
        }
        $t = &$res[$i];
        $t['despachada'] += $x['n'];
        $t['documentos'] = implode(', ', array_unique(array_merge(array_filter(explode(', ', $t['documentos'])), array_values($x['docs'] ?? []))));
        $t['ids_documentos'] = implode(',', array_unique(array_merge(array_filter(explode(',', $t['ids_documentos'])), array_values($x['ids'] ?? []))));
        $t['disponible'] = max(0, (int)$t['despachada'] - (int)$t['devuelta']);
        unset($t);
    }
    return $res;
}

/**
 * Grado de cada libro según la adopción del colegio en el período (2026-11-13, la usuaria): el de areas_objetivas
 * (en el grado "Otro" = 17, el grado específico id_grado_otro), no el del catálogo. Si el libro está adoptado en
 * varios grados se listan todos ("1 (Primero), 2 (Segundo)"). Devuelve [id_libro => texto]; sin adopción, sin entrada.
 * $orden (salida): [id_libro => id del primer grado] para ordenar por curso (grados.id va de Pre jardín a 11).
 */
function dvv_grados_adopcion(PDO $bdd, $idColegio, $idPeriodo, array $idsLibros, &$orden = null, &$planLector = null) {
    require_once __DIR__ . '/plan_lector.php'; // MATERIA_PLAN_LECTOR
    $orden = [];
    $planLector = []; // [id_libro => true] si es plan lector (materia del área o del libro), 2026-11-23
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsLibros))));
    if (!$ids || !$idColegio) return [];
    $st = $bdd->prepare("SELECT a.id_libro_eureka AS id_libro, g.id AS id_grado, g.grado, MAX(a.id_materia = " . MATERIA_PLAN_LECTOR . ") AS plan
                         FROM areas_objetivas a JOIN grados g ON g.id = IF(a.id_grado = 17 AND a.id_grado_otro > 0, a.id_grado_otro, a.id_grado)
                         WHERE a.id_colegio = ? AND a.id_periodo = ? AND a.id_libro_eureka IN (" . implode(',', $ids) . ")
                         GROUP BY a.id_libro_eureka, g.id, g.grado ORDER BY g.id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $res[(int)$r['id_libro']][] = $r['grado'];
        if (!isset($orden[(int)$r['id_libro']])) $orden[(int)$r['id_libro']] = (int)$r['id_grado']; // ORDER BY g.id: el primero es el menor
        if ((int)$r['plan']) $planLector[(int)$r['id_libro']] = true;
    }
    foreach ($bdd->query("SELECT id FROM libros WHERE id_materia = " . MATERIA_PLAN_LECTOR . " AND id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN) as $l) $planLector[(int)$l] = true;
    return array_map(fn($g) => implode(', ', $g), $res);
}

/**
 * Cursos de cada título (2026-11-24, la usuaria): un libro adoptado en varios cursos (p. ej. Jardín y Transición) se
 * pide por curso (libros_pedidos.cod_area), pero la remisión de WO no dice de qué curso sale cada unidad. En la
 * devolución el usuario reparte las unidades entre los cursos y se guarda una fila de libros_devol_v por curso.
 *  - solicitada: lo pedido en ese curso en los pedidos elegidos.
 *  - devuelta: lo ya devuelto de esos pedidos con esa área (reparto de libros_devol_v, devoluciones no anuladas).
 *  - max: solicitada − devuelta (si entre todos los cursos no alcanza el disponible del título, p. ej. porque se
 *    remisionó más de lo pedido, la diferencia se suma al curso con más unidades pedidas).
 * Solo entran los títulos pedidos en 2 o más cursos. Devuelve [id_libro (del detalle) => [['cod_area','grado','solicitada','devuelta','max']]],
 * ordenados por curso (Pre jardín → 11).
 */
function dvv_cursos_titulos(PDO $bdd, array $claves, $idColegio, array $titulos) {
    $claves = array_values(array_unique(array_filter(array_map('strval', $claves))));
    $disp = [];
    foreach ($titulos as $t) if ($t['id_libro']) $disp[(int)$t['id_libro']] = (int)$t['disponible'];
    if (!$claves || !$disp) return [];
    $idsPed = array_map(fn($c) => (int)substr($c, 1), $claves);

    $st = $bdd->query("SELECT lp.id_libro, lp.cod_area, SUM(lp.cantidad) AS n FROM libros_pedidos lp JOIN pedidos x ON x.codigo = lp.cod_pedido
                       WHERE x.id IN (" . implode(',', $idsPed) . ") AND COALESCE(lp.cod_area, '') <> ''
                       GROUP BY lp.id_libro, lp.cod_area HAVING n > 0");
    $pedido = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pedido) return [];

    $cond = implode(' OR ', array_fill(0, count($claves), 'FIND_IN_SET(?, d.ids_pedidos_venta)'));
    $st = $bdd->prepare("SELECT ld.id_libro, ld.cod_area, ld.reparto FROM libros_devol_v ld JOIN devoluciones_v d ON d.codigo = ld.cod_pedido
                         WHERE d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . " AND COALESCE(ld.cod_area, '') <> '' AND ($cond)");
    $st->execute($claves);
    $devol = $st->fetchAll(PDO::FETCH_ASSOC);

    // El mismo libro repetido en el catálogo se pasa al libros.id del título del detalle (como en dvv_detalle_varios).
    $cat = dvv_claves_catalogo($bdd, array_merge(array_keys($disp), array_column($pedido, 'id_libro'), array_column($devol, 'id_libro')));
    $tituloDeClave = [];
    foreach ($disp as $l => $_) if (isset($cat[$l])) $tituloDeClave[$cat[$l]] = $l;
    $canon = fn($l) => $tituloDeClave[$cat[(int)$l] ?? ''] ?? (int)$l;

    $sol = []; // id_libro => [cod_area => n]
    foreach ($pedido as $r) {
        $l = $canon($r['id_libro']);
        if (isset($disp[$l])) $sol[$l][(string)$r['cod_area']] = ($sol[$l][(string)$r['cod_area']] ?? 0) + (int)$r['n'];
    }
    $sol = array_filter($sol, fn($a) => count($a) > 1);
    if (!$sol) return [];

    $dev = [];
    foreach ($devol as $r) {
        $l = $canon($r['id_libro']);
        if (!isset($sol[$l])) continue;
        foreach (array_filter(explode(',', (string)$r['reparto'])) as $parte) {
            [$c, $cant] = explode(':', $parte) + ['', 0];
            if (in_array($c, $claves, true)) $dev[$l][(string)$r['cod_area']] = ($dev[$l][(string)$r['cod_area']] ?? 0) + (int)$cant;
        }
    }

    // Curso de cada área de la adopción (en el grado "Otro" = 17, el grado específico).
    $areas = [];
    foreach ($sol as $a) foreach ($a as $cod => $_) $areas[$cod] = true;
    $st = $bdd->prepare("SELECT a.codigo, g.id, g.grado FROM areas_objetivas a JOIN grados g ON g.id = IF(a.id_grado = 17 AND a.id_grado_otro > 0, a.id_grado_otro, a.id_grado)
                         WHERE a.id_colegio = ? AND a.codigo IN (" . implode(',', array_fill(0, count($areas), '?')) . ")");
    $st->execute(array_merge([(int)$idColegio], array_map('strval', array_keys($areas))));
    $grado = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $grado[(string)$r['codigo']] = ['id' => (int)$r['id'], 'grado' => (string)$r['grado']];

    $res = [];
    foreach ($sol as $l => $porArea) {
        $lista = [];
        foreach ($porArea as $cod => $n) {
            $ya = (int)($dev[$l][$cod] ?? 0);
            $lista[] = ['cod_area' => (string)$cod, 'grado' => $grado[$cod]['grado'] ?? ('Área ' . $cod), 'orden' => $grado[$cod]['id'] ?? 99,
                        'solicitada' => $n, 'devuelta' => $ya, 'max' => max(0, $n - $ya)];
        }
        usort($lista, fn($a, $b) => $a['orden'] <=> $b['orden'] ?: strcmp($a['cod_area'], $b['cod_area']));
        // Dos áreas del mismo curso: se distinguen por el código del área.
        $nombres = array_count_values(array_column($lista, 'grado'));
        foreach ($lista as &$c) if ($nombres[$c['grado']] > 1) $c['grado'] .= ' (' . $c['cod_area'] . ')';
        unset($c);
        $falta = $disp[$l] - array_sum(array_column($lista, 'max'));
        if ($falta > 0) {
            $mayor = array_keys(array_column($lista, 'solicitada'), max(array_column($lista, 'solicitada')))[0];
            $lista[$mayor]['max'] += $falta;
        }
        foreach ($lista as &$c) { $c['max'] = min($c['max'], $disp[$l]); unset($c['orden']); }
        unset($c);
        $res[$l] = $lista;
    }
    return $res;
}

/**
 * Lee el reparto por curso del formulario (curso[id_libro][cod_area], o suelto_curso en venta directa) y lo valida
 * contra los cursos del detalle (dvv_cursos_titulos). Devuelve ['cursos' => [id_libro => [cod_area => n]]] o ['error' => ...].
 */
function dvv_leer_cursos(array $detalle, array $post) {
    $porLibro = [];
    foreach ($detalle['titulos'] as $t) if ($t['id_libro']) $porLibro[(int)$t['id_libro']] = $t;
    $res = [];
    foreach ($post as $l => $areas) {
        $t = $porLibro[(int)$l] ?? null;
        if (!$t || empty($t['cursos']) || !is_array($areas)) return ['error' => 'Uno de los títulos no tiene reparto por curso; recarga la página e inténtalo de nuevo.'];
        $validos = array_column($t['cursos'], null, 'cod_area');
        foreach ($areas as $cod => $v) {
            $v = trim((string)$v);
            if (!preg_match('/^\d*$/', $v)) return ['error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
            $n = (int)$v;
            if (!$n) continue;
            $c = $validos[(string)$cod] ?? null;
            if (!$c) return ['error' => 'Uno de los cursos de "' . $t['descripcion'] . '" no está en los pedidos elegidos.'];
            if ($n > $c['max']) return ['error' => 'De "' . $t['descripcion'] . '" en ' . $c['grado'] . ' se pueden devolver máximo ' . $c['max'] . ' (pedidas ' . $c['solicitada'] . ', ya devueltas ' . $c['devuelta'] . ').'];
            $res[(int)$l][(string)$cod] = $n;
        }
    }
    return ['cursos' => $res];
}

/** Desglose de una devolución de venta directa: paquetes completos y libros sueltos (dvv_guardar_devolucion). */
function dvv_asegurar_tabla_vd(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS devoluciones_v_vd (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_devolucion INT NOT NULL,
        tipo VARCHAR(10) NOT NULL,
        id_paquete INT NULL,
        id_libro INT NULL,
        cantidad INT NOT NULL,
        KEY idx_devoluciones_v_vd (id_devolucion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Paquetes abiertos (2026-11-13, la usuaria): descuento y precio al que se vendió cada libro de un paquete abierto
    // (los que no volvieron) o, del que volvió suelto, su valor. Las unidades están en devoluciones_v_vd:
    // 'abierto' (id_paquete, cantidad = paquetes abiertos) y 'suelto_paq' (id_paquete, id_libro, cantidad = vuelve).
    $bdd->exec("CREATE TABLE IF NOT EXISTS devoluciones_v_vd_abiertos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_devolucion INT NOT NULL,
        id_paquete INT NOT NULL,
        id_libro INT NOT NULL,
        descuento DECIMAL(5,2) NULL,
        precio DECIMAL(14,2) NULL,
        KEY idx_devoluciones_v_vd_abiertos (id_devolucion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/**
 * Paquetes abiertos de una devolución de venta directa: [id_paquete => ['id_paquete','paquete','abiertos' (paquetes
 * abiertos), 'libros' => [id_libro => ['id_libro','libro','devueltas' (vuelven sueltos),'vendidas','descuento','precio']]]].
 * Cada libro del paquete abierto: vendidas = abiertos − devueltas.
 */
function dvv_paquetes_abiertos(PDO $bdd, $idDevolucion) {
    dvv_asegurar_tabla_vd($bdd);
    $res = [];
    $vuelven = [];
    foreach (dvv_desglose_vd($bdd, $idDevolucion) as $x) {
        if ($x['tipo'] === 'abierto') $res[(int)$x['id_paquete']] = ['id_paquete' => (int)$x['id_paquete'], 'paquete' => '', 'abiertos' => (int)$x['cantidad'], 'libros' => []];
        elseif ($x['tipo'] === 'suelto_paq' && $x['id_paquete']) $vuelven[(int)$x['id_paquete']][(int)$x['id_libro']] = (int)$x['cantidad'];
    }
    if (!$res) return [];
    $st = $bdd->prepare("SELECT a.id_paquete, a.id_libro, a.descuento, a.precio, l.libro, pq.paquete FROM devoluciones_v_vd_abiertos a
                         LEFT JOIN libros l ON l.id = a.id_libro LEFT JOIN paquetes_colegio pq ON pq.id = a.id_paquete
                         WHERE a.id_devolucion = ? ORDER BY a.id");
    $st->execute([(int)$idDevolucion]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = (int)$r['id_paquete'];
        if (!isset($res[$p])) continue;
        $l = (int)$r['id_libro'];
        $dev = (int)($vuelven[$p][$l] ?? 0);
        $res[$p]['paquete'] = (string)$r['paquete'];
        $res[$p]['libros'][$l] = ['id_libro' => $l, 'libro' => (string)$r['libro'], 'devueltas' => $dev, 'vendidas' => max(0, $res[$p]['abiertos'] - $dev),
                                  'descuento' => $r['descuento'] !== null ? (float)$r['descuento'] : null, 'precio' => $r['precio'] !== null ? (float)$r['precio'] : null];
    }
    return $res;
}

/**
 * Precios de referencia para precargar los campos de los libros sueltos de paquetes: [id_libro => ['lista' (precio
 * del libro), 'descuento' (% de la adopción: descuento_d, o descuento si es 0), 'venta' (precio venta padre)]].
 */
function dvv_precios_referencia(PDO $bdd, $idColegio, $idPeriodo, array $idsLibro) {
    $idsLibro = array_values(array_unique(array_filter(array_map('intval', $idsLibro))));
    if (!$idsLibro) return [];
    $res = [];
    foreach ($bdd->query("SELECT id, precio FROM libros WHERE id IN (" . implode(',', $idsLibro) . ")") as $r) {
        $res[(int)$r['id']] = ['lista' => (float)$r['precio'], 'descuento' => null, 'venta' => null];
    }
    $st = $bdd->prepare("SELECT id_libro, descuento, descuento_d, precio_venta_final FROM presupuestos
                         WHERE id_colegio = ? AND id_periodo = ? AND id_libro IN (" . implode(',', $idsLibro) . ") ORDER BY definido DESC, id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $visto = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $l = (int)$r['id_libro'];
        if (isset($visto[$l]) || !isset($res[$l])) continue;
        $visto[$l] = true;
        $desc = ((float)$r['descuento_d'] == 0) ? (float)$r['descuento'] : (float)$r['descuento_d'];
        $res[$l]['descuento'] = round($desc * 100, 2);
        $res[$l]['venta'] = (float)$r['precio_venta_final'] > 0 ? (float)$r['precio_venta_final'] : null;
    }
    return $res;
}

/** Número con hasta 2 decimales (coma o punto, como los descuentos de Adopciones): float; '' → null; inválido → false. */
function dvv_leer_decimal($v) {
    $v = str_replace(',', '.', trim((string)$v));
    if ($v === '') return null;
    return preg_match('/^\d+(\.\d{1,2})?$/', $v) ? (float)$v : false;
}

/** Desglose guardado de una devolución de venta directa: [['tipo' => 'paquete'|'suelto'|'suelto_paq','id_paquete','id_libro','cantidad']]. */
function dvv_desglose_vd(PDO $bdd, $idDevolucion) {
    dvv_asegurar_tabla_vd($bdd);
    $st = $bdd->prepare("SELECT tipo, id_paquete, id_libro, cantidad FROM devoluciones_v_vd WHERE id_devolucion = ? ORDER BY id");
    $st->execute([(int)$idDevolucion]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Venta directa: paquetes completos y libros sueltos para devolver, solo con lo remisionado en WO (2026-11-19).
 *   'paquetes' => [['id','paquete','libros','titulos','grado','max']] los paquetes que WO trae como producto de paquete;
 *                 max = paquetes remisionados − ya devueltos, sin pasar del disponible de sus libros
 *   'sueltos'  => [id_libro] títulos con disponible remisionados como libro
 *   'limite_paquete' / 'limite_suelto' => [id_libro => n] tope de lo que vino en paquete y de lo que vino suelto.
 */
function dvv_vd_estructura(PDO $bdd, array $claves, $idColegio, $idPeriodo, array $titulos, array $paqWo) {
    // 2026-11-19 (la usuaria): solo lo remisionado en WO. Parte paquete = lo que vino en productos de paquete de WO
    // (dvv_vd_paquetes_wo); parte suelta = el resto de lo despachado del título. Ya no se usa lo pedido en el CRM ni
    // hay paquetes abiertos.
    require_once __DIR__ . '/formatos_vd_datos.php'; // fvd_paquetes_colegio
    $disp = [];
    $base = [];
    foreach ($titulos as $t) {
        if (!$t['id_libro']) continue;
        $base[(int)$t['id_libro']] = (int)$t['base'];
        if ($t['disponible'] > 0) $disp[(int)$t['id_libro']] = (int)$t['disponible'];
    }
    $paquetesColegio = array_values(array_filter(fvd_paquetes_colegio($bdd, $idColegio, $idPeriodo), fn($p) => ($paqWo['paq'][$p['id']] ?? 0) > 0));
    // Libro repetido en el catálogo (2026-11-19): el paquete puede traer un libros.id y el título del detalle otro del
    // mismo producto (p. ej. 1725 y 1479, ambos producto WO 3438). Todo se pasa al libros.id del título del detalle por su
    // clave de catálogo (dvv_claves_catalogo); si no, el paquete no encontraba el disponible de ese libro.
    $idsCat = array_map(fn($t) => (int)$t['id_libro'], array_filter($titulos, fn($t) => $t['id_libro']));
    foreach ($paquetesColegio as $p) foreach ($p['libros'] as $l) $idsCat[] = (int)$l;
    $cat = dvv_claves_catalogo($bdd, $idsCat);
    $tituloDeClave = [];
    foreach ($titulos as $t) if ($t['id_libro'] && isset($cat[(int)$t['id_libro']])) $tituloDeClave[$cat[(int)$t['id_libro']]] = (int)$t['id_libro'];
    $canon = fn($l) => $tituloDeClave[$cat[(int)$l] ?? ''] ?? (int)$l;
    foreach ($paquetesColegio as &$pc) $pc['libros'] = array_map($canon, $pc['libros']);
    unset($pc);
    $enPaq = []; // id_libro => unidades remisionadas dentro de paquetes
    foreach ($paquetesColegio as $p) foreach ($p['libros'] as $l) $enPaq[$l] = ($enPaq[$l] ?? 0) + (int)$paqWo['paq'][$p['id']];
    $dev = dvv_devuelto_por_parte($bdd, $claves);

    $librosPaq = [];
    foreach ($paquetesColegio as $p) foreach ($p['libros'] as $l) $librosPaq[$l] = true;
    $limPaq = [];
    foreach ($enPaq as $l => $n) {
        $usado = (int)($dev['suelto_paq'][$l] ?? 0);
        foreach ($paquetesColegio as $p) if (in_array($l, $p['libros'], true)) $usado += (int)($dev['paquete'][$p['id']] ?? 0);
        $limPaq[$l] = max(0, min($n - $usado, $disp[$l] ?? 0));
    }
    $limSue = [];
    foreach ($librosPaq as $l => $_) {
        $limSue[$l] = max(0, min(max(0, ($base[$l] ?? 0) - ($enPaq[$l] ?? 0)) - (int)($dev['suelto'][$l] ?? 0), $disp[$l] ?? 0));
    }

    // Grado del paquete (paquetes_colegio.id_grado), no el de sus libros (2026-11-19).
    $gradoPaq = [];
    $idGradoPaq = [];
    if ($paquetesColegio) {
        foreach ($bdd->query("SELECT pq.id, pq.id_grado, g.grado FROM paquetes_colegio pq JOIN grados g ON g.id = pq.id_grado
                              WHERE pq.id IN (" . implode(',', array_map(fn($p) => (int)$p['id'], $paquetesColegio)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gradoPaq[(int)$r['id']] = (string)$r['grado'];
            $idGradoPaq[(int)$r['id']] = (int)$r['id_grado'];
        }
    }
    // Orden (2026-11-20, la usuaria): paquetes por curso y dentro del curso por nombre; los títulos de cada paquete
    // ("Cada paquete devuelve 1 unidad de: …") alfabéticos. Los sueltos siguen el orden de $titulos (curso y título).
    require_once __DIR__ . '/orden_texto.php';
    usort($paquetesColegio, fn($a, $b) => (($idGradoPaq[(int)$a['id']] ?? 99) <=> ($idGradoPaq[(int)$b['id']] ?? 99)) ?: orden_texto_cmp($a['paquete'], $b['paquete']));
    foreach ($paquetesColegio as &$pc) {
        $pares = array_map(null, $pc['libros'], $pc['titulos']);
        usort($pares, fn($x, $y) => orden_texto_cmp($x[1], $y[1]));
        $pc['libros'] = array_column($pares, 0);
        $pc['titulos'] = array_column($pares, 1);
    }
    unset($pc);
    $paquetes = [];
    foreach ($paquetesColegio as $p) {
        // Paquetes remisionados en WO − paquetes ya devueltos, sin pasar del disponible de cada libro del paquete.
        $max = (int)$paqWo['paq'][$p['id']] - (int)($dev['paquete'][$p['id']] ?? 0) - (int)($dev['abierto'][$p['id']] ?? 0);
        foreach ($p['libros'] as $l) $max = min($max, $limPaq[$l] ?? ($disp[$l] ?? 0));
        $paquetes[] = ['id' => $p['id'], 'paquete' => $p['paquete'], 'libros' => $p['libros'], 'titulos' => $p['titulos'], 'precio' => $p['precio'],
                       'grado' => $gradoPaq[(int)$p['id']] ?? '', 'max' => max(0, $max), 'max_abiertos' => 0];
    }
    $sueltos = [];
    foreach ($disp as $l => $n) {
        if (isset($librosPaq[$l]) ? $limSue[$l] > 0 : true) $sueltos[] = $l;
    }
    return ['paquetes' => $paquetes, 'sueltos' => $sueltos, 'libros_paquete' => [],
            'limite_paquete' => $limPaq ?: new stdClass(), 'limite_suelto' => $limSue ?: new stdClass(),
            'precios' => new stdClass()];
}

/**
 * Lo ya devuelto por parte (desglose devoluciones_v_vd) en las devoluciones no anuladas que incluyen alguno de estos
 * pedidos (devoluciones_v.ids_pedidos_venta): ['paquete' => [id_paquete => n], 'suelto' => [id_libro => n],
 * 'suelto_paq' => [id_libro => n]]. Una devolución de varios pedidos cuenta entera aunque solo se elija uno de ellos.
 */
function dvv_devuelto_por_parte(PDO $bdd, array $claves) {
    $res = ['paquete' => [], 'suelto' => [], 'suelto_paq' => [], 'abierto' => []];
    $claves = array_values(array_filter(array_map('strval', $claves)));
    if (!$claves || !$bdd->query("SHOW TABLES LIKE 'devoluciones_v_vd'")->fetch()) return $res;
    $st = $bdd->prepare("SELECT v.tipo, v.id_paquete, v.id_libro, SUM(v.cantidad) AS n
                         FROM devoluciones_v_vd v JOIN devoluciones_v d ON d.id = v.id_devolucion
                         WHERE d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . " AND ("
                         . implode(' OR ', array_fill(0, count($claves), "FIND_IN_SET(?, REPLACE(d.ids_pedidos_venta, ' ', ''))")) . ")
                         GROUP BY v.tipo, v.id_paquete, v.id_libro");
    $st->execute($claves);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($res[$r['tipo']])) continue;
        $k = in_array($r['tipo'], ['paquete', 'abierto'], true) ? (int)$r['id_paquete'] : (int)$r['id_libro'];
        $res[$r['tipo']][$k] = ($res[$r['tipo']][$k] ?? 0) + (int)$r['n'];
    }
    return $res;
}

/**
 * Área de la adopción (presupuestos.cod_area) que se guarda en libros_devol_v.cod_area, como hacía el formulario anterior
 * (php/devolucion.php); 2026-11-11, la usuaria. Con ella devolucion_colegio.php encuentra la adopción (descuento y grado).
 *  - Libro devuelto dentro de un paquete (desglose de venta directa): el área de la adopción del GRADO del paquete
 *    (areas_objetivas.id_grado_otro = paquetes_colegio.id_grado). Si volvió en paquetes de varios grados, el del paquete
 *    con más unidades (la devolución guarda una sola fila por libro).
 *  - Si no (suelto, sin desglose, o ninguna adopción de ese grado): la primera adopción definida del libro con área.
 *  - Sin adopción con área: '' (como antes).
 * Devuelve [id_libro => cod_area].
 */
function dvv_areas_devolucion(PDO $bdd, $idColegio, $idPeriodo, array $idsLibro, array $desglose) {
    $idsLibro = array_values(array_unique(array_filter(array_map('intval', $idsLibro))));
    if (!$idsLibro) return [];
    $st = $bdd->prepare("SELECT p.id_libro, p.cod_area, ao.id_grado_otro FROM presupuestos p
                         LEFT JOIN areas_objetivas ao ON ao.codigo = p.cod_area AND ao.id_colegio = p.id_colegio AND ao.id_periodo = p.id_periodo
                         WHERE p.id_colegio = ? AND p.id_periodo = ? AND COALESCE(p.cod_area, '') <> ''
                           AND p.id_libro IN (" . implode(',', $idsLibro) . ")
                         ORDER BY p.definido DESC, p.id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $adop = []; // id_libro => [['cod_area','grado']] en orden de preferencia
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $adop[(int)$r['id_libro']][] = ['cod_area' => (string)$r['cod_area'], 'grado' => (int)$r['id_grado_otro']];

    // Grado del paquete por libro: el del paquete con más unidades devueltas de ese libro.
    $gradoPaq = [];
    $idsPaq = array_values(array_unique(array_map(fn($x) => (int)$x['id_paquete'], array_filter($desglose, fn($x) => $x['tipo'] === 'paquete'))));
    if ($idsPaq) {
        $paqs = [];
        foreach ($bdd->query("SELECT pq.id, pq.id_grado, pl.id_libro FROM paquetes_colegio pq JOIN paquetes_colegio_libros pl ON pl.id_paquete = pq.id
                              WHERE pq.id IN (" . implode(',', $idsPaq) . ")") as $r) {
            $paqs[(int)$r['id']]['grado'] = (int)$r['id_grado'];
            $paqs[(int)$r['id']]['libros'][(int)$r['id_libro']] = true;
        }
        $unidades = []; // id_libro => [grado => unidades]
        foreach ($desglose as $x) {
            if ($x['tipo'] !== 'paquete' || !isset($paqs[(int)$x['id_paquete']])) continue;
            $p = $paqs[(int)$x['id_paquete']];
            foreach ($p['libros'] as $l => $_) $unidades[$l][$p['grado']] = ($unidades[$l][$p['grado']] ?? 0) + (int)$x['cantidad'];
        }
        foreach ($unidades as $l => $porGrado) { arsort($porGrado); $gradoPaq[$l] = (int)array_key_first($porGrado); }
    }

    $res = [];
    foreach ($idsLibro as $l) {
        $lista = $adop[$l] ?? [];
        $area = '';
        if (isset($gradoPaq[$l])) foreach ($lista as $a) if ($a['grado'] === $gradoPaq[$l]) { $area = $a['cod_area']; break; }
        if ($area === '' && $lista) $area = $lista[0]['cod_area'];
        $res[$l] = $area;
    }
    return $res;
}

/** Nombre de un título del detalle (para los mensajes de dvv_leer_vd). */
function dvv_vd_nombre(array $detalle, $idLibro) {
    foreach ($detalle['titulos'] as $t) if ((int)$t['id_libro'] === (int)$idLibro) return $t['descripcion'];
    return 'libro #' . (int)$idLibro;
}

/**
 * Lee lo que llega del formulario de venta directa (paquete[id], suelto[id_libro], abierto[id_paquete] con
 * suelto_paq / sp_desc / sp_precio[id_paquete][id_libro]) y lo pasa a
 * unidades por libro. Devuelve ['cantidades' => [id_libro => n], 'desglose' => [...]] o ['error' => ...].
 */
function dvv_leer_vd(array $detalle, array $post) {
    $vd = $detalle['vd'] ?? null;
    if (!$vd) return ['error' => 'Este colegio no es de venta directa.'];
    $entero = function ($v) { $v = trim((string)$v); return preg_match('/^\d*$/', $v) ? (int)$v : null; };
    // Sueltos repartidos por curso (suelto_curso[id_libro][cod_area], 2026-11-24): la suma es el suelto del libro.
    // Cada curso se valida en dvv_guardar_devolucion (dvv_leer_cursos).
    foreach ((array)($post['suelto_curso'] ?? []) as $l => $areas) {
        if ($entero($post['suelto'][$l] ?? '')) return ['error' => 'De "' . dvv_vd_nombre($detalle, $l) . '" llegaron cantidades con y sin curso; recarga la página e inténtalo de nuevo.'];
        $post['suelto'][$l] = (string)array_sum(array_map(fn($v) => (int)$entero($v), (array)$areas));
    }
    $paquetes = [];
    foreach ($vd['paquetes'] as $p) $paquetes[(int)$p['id']] = $p;
    $cant = [];
    $usoPaq = []; // id_libro => unidades por paquetes completos + sueltos de paquete
    $usoSue = []; // id_libro => unidades sueltas
    $desglose = [];
    foreach ((array)($post['paquete'] ?? []) as $id => $v) {
        $n = $entero($v);
        if ($n === null) return ['error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        if (!$n) continue;
        if (!isset($paquetes[(int)$id])) return ['error' => 'Uno de los paquetes no está en los pedidos elegidos.'];
        $p = $paquetes[(int)$id];
        if ($n > $p['max']) return ['error' => 'Del "' . $p['paquete'] . '" se pueden devolver máximo ' . $p['max'] . ' paquete(s) completo(s).'];
        foreach ($p['libros'] as $l) { $cant[$l] = ($cant[$l] ?? 0) + $n; $usoPaq[$l] = ($usoPaq[$l] ?? 0) + $n; }
        $desglose[] = ['tipo' => 'paquete', 'id_paquete' => (int)$id, 'id_libro' => null, 'cantidad' => $n];
    }
    foreach (['suelto' => $vd['sueltos']] as $tipo => $permitidos) {
        foreach ((array)($post[$tipo] ?? []) as $l => $v) {
            $n = $entero($v);
            if ($n === null) return ['error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
            if (!$n) continue;
            if (!in_array((int)$l, $permitidos, true)) {
                return ['error' => $tipo === 'suelto_paq' ? 'Solo se pueden añadir como sueltos los libros que vienen en los paquetes.' : 'Uno de los libros sueltos no está en los pedidos elegidos.'];
            }
            $cant[(int)$l] = ($cant[(int)$l] ?? 0) + $n;
            if ($tipo === 'suelto') $usoSue[(int)$l] = ($usoSue[(int)$l] ?? 0) + $n;
            else $usoPaq[(int)$l] = ($usoPaq[(int)$l] ?? 0) + $n;
            $desglose[] = ['tipo' => $tipo, 'id_paquete' => null, 'id_libro' => (int)$l, 'cantidad' => $n];
        }
    }
    // Paquetes abiertos (2026-11-13, la usuaria): abierto[id_paquete] = paquetes que se abrieron; de cada libro del paquete
    // suelto_paq[id_paquete][id_libro] = unidades que vuelven sueltas y sp_desc / sp_precio[id_paquete][id_libro] = descuento
    // y precio. Lo que no vuelve de un paquete abierto se vendió suelto a ese precio (obligatorio); el paquete ya no se cobra.
    $precios = [];
    $completos = [];
    foreach ($desglose as $x) if ($x['tipo'] === 'paquete') $completos[(int)$x['id_paquete']] = (int)$x['cantidad'];
    $abiertos = (array)($post['abierto'] ?? []);
    $vuelven = (array)($post['suelto_paq'] ?? []);
    foreach ($vuelven as $idPaq => $_) {
        if (!isset($abiertos[$idPaq])) return ['error' => 'Uno de los libros sueltos de paquete no tiene su paquete abierto.'];
    }
    foreach ($abiertos as $idPaq => $v) {
        $idPaq = (int)$idPaq;
        $nAb = $entero($v);
        if ($nAb === null) return ['error' => 'Los paquetes abiertos deben ser un número entero, sin decimales ni negativos.'];
        if (!$nAb) continue;
        $p = $paquetes[$idPaq] ?? null;
        if (!$p) return ['error' => 'Uno de los paquetes abiertos no está en los pedidos elegidos.'];
        if ($nAb + ($completos[$idPaq] ?? 0) > $p['max_abiertos']) {
            return ['error' => 'Del "' . $p['paquete'] . '" quedan ' . (int)$p['max_abiertos'] . ' paquete(s) por legalizar: entre completos devueltos (' . (int)($completos[$idPaq] ?? 0) . ') y abiertos (' . $nAb . ') no se puede pasar de ahí.'];
        }
        $libVuelven = (array)($vuelven[$idPaq] ?? []);
        foreach ($libVuelven as $l => $_) {
            if (!in_array((int)$l, $p['libros'], true)) return ['error' => 'Uno de los libros sueltos no pertenece al "' . $p['paquete'] . '".'];
        }
        // Cada libro que vuelve suelto sale de un paquete abierto distinto (el otro libro de ese paquete se vendió), 2026-11-13.
        $sumaVuelven = array_sum(array_map(fn($v) => (int)$entero($v), $libVuelven));
        if ($nAb < $sumaVuelven) {
            return ['error' => 'Del "' . $p['paquete'] . '" vuelven ' . $sumaVuelven . ' libro(s) suelto(s): cada uno sale de un paquete abierto, así que deben ser al menos ' . $sumaVuelven . ' abiertos (escribiste ' . $nAb . ').'];
        }
        $desglose[] = ['tipo' => 'abierto', 'id_paquete' => $idPaq, 'id_libro' => null, 'cantidad' => $nAb];
        foreach ($p['libros'] as $l) {
            $nombre = dvv_vd_nombre($detalle, $l);
            $n = $entero($libVuelven[$l] ?? '');
            if ($n === null) return ['error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
            if ($n > $nAb) return ['error' => 'De "' . $nombre . '" vuelven ' . $n . ' pero del "' . $p['paquete'] . '" se abrieron ' . $nAb . ' paquete(s).'];
            $desc = dvv_leer_decimal($post['sp_desc'][$idPaq][$l] ?? '');
            if ($desc === false || ($desc !== null && $desc > 100)) return ['error' => 'El descuento de "' . $nombre . '" debe ser un porcentaje entre 0 y 100, con máximo 2 decimales.'];
            $precio = dvv_leer_decimal($post['sp_precio'][$idPaq][$l] ?? '');
            if ($precio === false) return ['error' => 'El precio de "' . $nombre . '" debe ser un número, con máximo 2 decimales.'];
            if ($n < $nAb && !$precio) return ['error' => 'Ingresa el precio al que se vendió "' . $nombre . '" del "' . $p['paquete'] . '" abierto.'];
            if ($n) {
                $cant[$l] = ($cant[$l] ?? 0) + $n;
                $usoPaq[$l] = ($usoPaq[$l] ?? 0) + $n;
                $desglose[] = ['tipo' => 'suelto_paq', 'id_paquete' => $idPaq, 'id_libro' => $l, 'cantidad' => $n];
            }
            $precios[] = ['id_paquete' => $idPaq, 'id_libro' => $l, 'descuento' => $desc, 'precio' => $precio];
        }
    }
    // Tope por parte (dvv_vd_estructura): paquetes + sueltos de paquete ≤ parte paquete; sueltos ≤ parte suelta.
    $limPaq = (array)($vd['limite_paquete'] ?? []);
    $limSue = (array)($vd['limite_suelto'] ?? []);
    foreach ($usoPaq as $l => $n) {
        if (isset($limPaq[$l]) && $n > $limPaq[$l]) {
            return ['error' => 'De "' . dvv_vd_nombre($detalle, $l) . '" se pidieron en paquete solo ' . (int)$limPaq[$l] . ' unidad(es) por devolver (paquetes completos + sueltos de paquete); estás devolviendo ' . $n . '.'];
        }
    }
    foreach ($usoSue as $l => $n) {
        if (isset($limSue[$l]) && $n > $limSue[$l]) {
            return ['error' => 'De "' . dvv_vd_nombre($detalle, $l) . '" se pidieron sueltas solo ' . (int)$limSue[$l] . ' unidad(es) por devolver; estás devolviendo ' . $n . ' sueltas.'];
        }
    }
    return ['cantidades' => $cant, 'desglose' => $desglose, 'precios' => $precios];
}

/**
 * Guarda UNA devolución (parcial) de varios pedidos de venta del mismo colegio. $cantidades = [id_libro =>
 * cantidad]: el tope es lo disponible del libro sumado en todos los pedidos; lo devuelto se reparte entre
 * ellos, el más antiguo primero (libros_devol_v.reparto). Se recalcula todo con los pedidos bloqueados (FOR
 * UPDATE). $token evita registrar dos veces el mismo envío. $tipoDevolucion 'corte': además el tope del libro
 * es lo remisionado − todas las devoluciones del colegio (se vuelve a calcular dentro de la transacción).
 * Devuelve ['ok','error','id_devolucion','repetida'].
 */
function dvv_guardar_devolucion(PDO $bdd, array $claves, array $cantidades, $observaciones, $token, $tipoDevolucion = 'normal', ?array $docsSel = null, ?array $postVd = null, array $postCursos = []) {
    // $postCursos (2026-11-24): curso[id_libro][cod_area] del formulario normal; en venta directa llega en $postVd['suelto_curso'].
    // Venta directa (2026-11-10): $docsSel = documentos de WO elegidos; $postVd = paquete[]/suelto[]/suelto_paq[] del
    // formulario (dvv_leer_vd), que reemplaza a $cantidades; el desglose queda en devoluciones_v_vd.
    $tipoDevolucion = dvv_tipo_devolucion($tipoDevolucion);
    dvv_asegurar_tabla_vd($bdd); // antes de la transacción (DDL = commit implícito)
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return ['ok' => false, 'error' => 'El formulario no es válido; recarga la página e inténtalo de nuevo.'];

    $st = $bdd->prepare("SELECT id FROM devoluciones_v WHERE token_registro = ?");
    $st->execute([$token]);
    if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id_devolucion' => (int)$ya, 'repetida' => true];

    $detalle = dvv_detalle_varios($bdd, $claves, $tipoDevolucion, false, $docsSel);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    // Venta directa: máximo DVV_MAX_DEVOL_VD devoluciones por pedido (dvv_cierre_vd).
    foreach ($detalle['pedidos'] as $p) {
        if (($p['cierre']['tipo'] ?? '') === 'vd') {
            return ['ok' => false, 'error' => 'El ' . $p['etiqueta'] . ' ya tiene sus ' . DVV_MAX_DEVOL_VD . ' devoluciones de venta directa (#' . implode(', #', $p['cierre']['ids']) . '): en venta directa se registran máximo ' . DVV_MAX_DEVOL_VD . ' devoluciones por pedido. Si hay que corregir una, anúlala y regístrala de nuevo.'];
        }
    }
    $desgloseVd = [];
    $preciosVd = [];
    if ($postVd !== null) {
        $lv = dvv_leer_vd($detalle, $postVd);
        if (isset($lv['error'])) return ['ok' => false, 'error' => $lv['error']];
        $cantidades = $lv['cantidades'];
        $desgloseVd = $lv['desglose'];
        $preciosVd = $lv['precios'];
    }
    $incluidos = $detalle['incluidos'];
    if (!$incluidos) return ['ok' => false, 'error' => 'Ninguno de los pedidos elegidos tiene títulos por devolver.'];
    if (!$detalle['id_cliente']) return ['ok' => false, 'error' => 'Ninguno de los pedidos tiene cliente; no se puede registrar la devolución.'];
    $periodosPedidos = [];
    foreach ($claves as $c) {
        if ($pp = dvv_obtener_pedido($bdd, $c)) $periodosPedidos[] = (int)$pp['id_periodo'];
    }

    $porLibro = [];
    foreach ($detalle['titulos'] as $t) if ($t['id_libro']) $porLibro[(int)$t['id_libro']] = $t;
    // Reparto por curso (dvv_cursos_titulos): en el formulario normal la cantidad del libro es la suma de sus cursos.
    $lc = dvv_leer_cursos($detalle, $postVd !== null ? (array)($postVd['suelto_curso'] ?? []) : $postCursos);
    if (isset($lc['error'])) return ['ok' => false, 'error' => $lc['error']];
    $porCurso = $lc['cursos'];
    if ($postVd === null) {
        foreach ($postCursos as $l => $_) $cantidades[$l] = (string)array_sum($porCurso[(int)$l] ?? []);
    }
    $aDevolver = [];
    foreach ($cantidades as $idLibro => $cant) {
        if (!preg_match('/^\d*$/', trim((string)$cant))) return ['ok' => false, 'error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        $cant = (int)$cant;
        if ($cant === 0) continue;
        if (!isset($porLibro[(int)$idLibro])) return ['ok' => false, 'error' => 'Uno de los títulos no está en los pedidos elegidos (ni despachado en World Office ni en la solicitud del CRM).'];
        $aDevolver[(int)$idLibro] = $cant;
    }
    if (!$aDevolver) return ['ok' => false, 'error' => 'Ingresa al menos una cantidad a devolver.'];

    $fuentePorPedido = [];
    $docsLabel = [];
    $opsLabel = [];
    foreach ($detalle['pedidos'] as $p) {
        if (!in_array($p['clave'], $incluidos, true)) continue;
        $fuentePorPedido[$p['clave']] = $p['fuente'];
        if ($p['ops'] !== '') $opsLabel[] = $p['ops'];
        foreach ($p['documentos'] as $d) if ($docsSel === null || !empty($d['elegido'])) $docsLabel[$d['documento']] = $d['documento'];
    }

    // Área de la adopción de cada libro (libros_devol_v.cod_area, 2026-11-11; antes se guardaba vacía).
    $areas = dvv_areas_devolucion($bdd, (int)$detalle['id_colegio'], (int)$detalle['id_periodo'], array_keys($aDevolver), $desgloseVd);

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        // Bloqueo de los pedidos en orden de id: serializa devoluciones simultáneas de los mismos pedidos.
        $ids = array_map(fn($c) => (int)substr($c, 1), $incluidos);
        $bdd->prepare("SELECT id FROM pedidos WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id FOR UPDATE")->execute($ids);

        $devuelto = [];
        foreach ($incluidos as $clave) {
            if ($c = dvv_cierre($bdd, $clave)) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($clave) . ' ya está completo: se cerró con ' . (($c['tipo'] ?? '') === 'vd' ? 'sus ' . DVV_MAX_DEVOL_VD . ' devoluciones de venta directa (#' . implode(', #', $c['ids']) . ').' : 'la legalización #' . (int)$c['id'] . '.')];
            }
            $devuelto[$clave] = dvd_devuelto_agrupado($bdd, $clave, $fuentePorPedido[$clave] === 'crm');
        }
        // Corte: tope del colegio (remisionado − todas sus devoluciones), recalculado con los pedidos bloqueados.
        if ($tipoDevolucion === 'corte') {
            $devColegio = dvv_devuelto_colegio_por_clave($bdd, $detalle['id_colegio'], $periodosPedidos);
            foreach ($aDevolver as $idLibro => $cant) {
                $queda = max(0, $porLibro[$idLibro]['base'] - ($devColegio[$porLibro[$idLibro]['clave_catalogo']] ?? 0));
                if ($cant > $queda) {
                    $bdd->rollBack();
                    return ['ok' => false, 'error' => 'No puedes devolver ' . $cant . ' de "' . $porLibro[$idLibro]['descripcion'] . '": de lo remisionado al colegio quedan ' . $queda . ' sin devolver.'];
                }
            }
        }

        // Reparto por pedido, el más antiguo primero (las partes ya vienen en orden de pedido).
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
                // Documentos elegidos: no más de lo despachado en ellos.
                if ($pa['tope'] !== null && $pa['tope'] !== '') $disp = min($disp, (int)$pa['tope']);
                $disponibleTotal += $disp;
                $toma = min($resta, $disp);
                if ($toma <= 0) continue;
                $reparto[$idLibro][] = $pa + ['cantidad' => $toma];
                $devuelto[$pa['clave_pedido']][$pa['clave']] = $ya + $toma;
                $resta -= $toma;
            }
            if ($resta > 0) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'No puedes devolver ' . $cant . ' de "' . $t['descripcion'] . '": ' . ($docsSel !== null ? 'en las remisiones elegidas' : 'en los pedidos elegidos') . ' quedan ' . $disponibleTotal . ' disponibles.'];
            }
        }

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM devoluciones_v WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        $stL = $bdd->prepare("INSERT INTO libros_devol_v (cod_pedido, id_libro, cod_area, cantidad, producto_wo, cant_despachada, documentos_wo, reparto, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($aDevolver as $idLibro => $cant) {
            $t = $porLibro[$idLibro];
            // Una fila por curso (2026-11-24): las unidades repartidas por el usuario con su área; el resto (sin reparto,
            // o paquetes de venta directa) con el área de dvv_areas_devolucion. Mismo área = una sola fila.
            $filas = [];
            $resto = $cant;
            foreach ($porCurso[$idLibro] ?? [] as $cod => $n) {
                $n = min($n, $resto);
                if ($n <= 0) continue;
                $filas[$cod] = ($filas[$cod] ?? 0) + $n;
                $resto -= $n;
            }
            if ($resto > 0) { $a0 = (string)($areas[$idLibro] ?? ''); $filas[$a0] = ($filas[$a0] ?? 0) + $resto; }
            $productoWo = null;
            $despachada = null;
            $idsDocs = [];
            foreach ($t['partes'] as $pa) {
                if ($pa['cerrado'] || !isset($devuelto[$pa['clave_pedido']])) continue;
                if ($productoWo === null && $pa['producto_wo']) $productoWo = (int)$pa['producto_wo'];
                if ($pa['fuente'] === 'auto') $despachada = (int)$despachada + $pa['base'];
                foreach (array_filter(explode(',', $pa['ids_documentos'])) as $idDoc) $idsDocs[$idDoc] = $idDoc;
            }
            // El reparto por pedido se divide entre las filas en orden (cada fila lleva su parte, para no contarlo dos veces).
            $colaReparto = array_map(fn($pa) => ['clave' => $pa['clave_pedido'], 'n' => (int)$pa['cantidad'], 'wo' => (int)$pa['producto_wo']], $reparto[$idLibro]);
            $primera = true;
            foreach ($filas as $cod => $n) {
                $partes = [];
                $falta = $n;
                while ($falta > 0 && $colaReparto) {
                    $toma = min($falta, $colaReparto[0]['n']);
                    $partes[] = $colaReparto[0]['clave'] . ':' . $toma . ':' . $colaReparto[0]['wo'];
                    $colaReparto[0]['n'] -= $toma;
                    $falta -= $toma;
                    if ($colaReparto[0]['n'] <= 0) array_shift($colaReparto);
                }
                $stL->execute([$codigo, $idLibro, (string)$cod, $n, $productoWo, $primera ? $despachada : null, mb_substr(implode(',', $idsDocs), 0, 255) ?: null, mb_substr(implode(',', $partes), 0, 500)]);
                $primera = false;
            }
        }
        // Mismas columnas y estado inicial (Pendiente) que php/devolucion.php.
        $bdd->prepare("INSERT INTO devoluciones_v (codigo, id_periodo, id_colegio, id_usuario, observaciones, cliente, tipo, estado,
                                                   ids_pedidos_venta, ops_pedidos, documentos_wo, cierre, token_registro, tipo_devolucion, venta_directa, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'parcial', ?, ?, ?, NOW())")
            ->execute([$codigo, $detalle['id_periodo'], $detalle['id_colegio'], (int)$_SESSION['id'], $observaciones, $detalle['id_cliente'], $detalle['tipo'],
                       implode(',', $incluidos), mb_substr(implode(', ', $opsLabel), 0, 255), mb_substr(implode(', ', $docsLabel), 0, 500), $token, $tipoDevolucion,
                       (dvv_modo_vd() || dvv_colegio_es_vd($bdd, $detalle['id_colegio'], $detalle['id_periodo'])) ? 1 : 0]);
        $idDevolucion = (int)$bdd->lastInsertId();
        if ($desgloseVd) {
            $stV = $bdd->prepare("INSERT INTO devoluciones_v_vd (id_devolucion, tipo, id_paquete, id_libro, cantidad) VALUES (?, ?, ?, ?, ?)");
            foreach ($desgloseVd as $dv) $stV->execute([$idDevolucion, $dv['tipo'], $dv['id_paquete'], $dv['id_libro'], $dv['cantidad']]);
        }
        if ($preciosVd) {
            $stP = $bdd->prepare("INSERT INTO devoluciones_v_vd_abiertos (id_devolucion, id_paquete, id_libro, descuento, precio) VALUES (?, ?, ?, ?, ?)");
            foreach ($preciosVd as $pv) $stP->execute([$idDevolucion, $pv['id_paquete'], $pv['id_libro'], $pv['descuento'], $pv['precio']]);
        }

        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_devolucion' => $idDevolucion, 'repetida' => false];
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        // Otro envío con el mismo token ganó la carrera: se devuelve esa devolución.
        if ($e->getCode() === '23000') {
            $st = $bdd->prepare("SELECT id FROM devoluciones_v WHERE token_registro = ?");
            $st->execute([$token]);
            if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id_devolucion' => (int)$ya, 'repetida' => true];
        }
        error_log('Devolución de venta ' . implode(',', $incluidos) . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la devolución: ' . $e->getMessage()];
    }
}

/**
 * Precio de la adopción del colegio en el período por libro (presupuestos.definido = 1): precio del libro −
 * descuento, misma regla que devolucion_colegio.php / dvd_precios_pedido(): descuento_d, o descuento si
 * descuento_d es 0. Devuelve [id_libro => ['precio','descuento' (fracción),'precio_final']].
 */
function dvv_precios_adopcion(PDO $bdd, $idColegio, $idPeriodo) {
    $st = $bdd->prepare("SELECT p.id_libro, l.precio, p.descuento, p.descuento_d FROM presupuestos p JOIN libros l ON l.id = p.id_libro
                         WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = 1 ORDER BY p.id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idLibro = (int)$r['id_libro'];
        if (isset($res[$idLibro])) continue; // mismo libro en dos áreas: la primera fila
        $desc = ((float)$r['descuento_d'] == 0) ? (float)$r['descuento'] : (float)$r['descuento_d'];
        $precio = (float)$r['precio'];
        $res[$idLibro] = ['precio' => $precio, 'descuento' => $desc, 'precio_final' => round($precio - $precio * $desc, 2)];
    }
    return $res;
}

/**
 * "Saldos de colegio" (saldos_colegio.php, 2026-10-26): consolidado por título de un colegio en un período, con la
 * fórmula de la usuaria (corregida 2026-10-26: lo solicitado NO cuenta): saldo = remisionado − devoluciones de corte −
 * devoluciones físicas.
 *   remisionado remisiones CEUR y facturas FVE (las FVE cuentan, pedido de la usuaria 2026-10-26) de World Office de
 *               sus pedidos de venta Entregados (sin canal Venta directa; máx. DVD_MAX_PEDIDOS, los más recientes;
 *               dvv_detalle_varios en modo físico sin lo solicitado en el CRM)
 *   de corte    devoluciones_v del colegio en el período con tipo_devolucion = 'corte' (no anuladas)
 *   físicas     las demás devoluciones_v del colegio en el período (físicas, las del formulario manual anterior y las
 *               de venta directa; no anuladas)
 * Un libro repetido en el catálogo se cuenta una vez (dvv_claves_catalogo). Valor = saldo × precio de la adopción.
 * Devuelve ['ok','error','colegio','cliente','pedidos','omitidos','titulos' => [['id_libro','descripcion','codigo',
 * 'remisionada','dev_corte','dev_fisica','saldo','precio','descuento','precio_final','valor','adoptado']],
 * 'totales', 'sin_precio'].
 */
function dvv_saldo_colegio(PDO $bdd, $idColegio, $idPeriodo) {
    $idColegio = (int)$idColegio;
    $idPeriodo = (int)$idPeriodo;
    $claves = [];
    foreach (dvv_listar_pedidos($bdd, $idPeriodo) as $p) {
        if ((int)$p['id_colegio'] === $idColegio) $claves[] = $p['clave'];
    }
    if (!$claves) return ['ok' => false, 'error' => 'Este colegio no tiene pedidos de venta entregados en el período.'];
    $omitidos = max(0, count($claves) - DVD_MAX_PEDIDOS);
    $claves = array_slice($claves, 0, DVD_MAX_PEDIDOS); // vienen del más reciente al más antiguo

    // Remisionado (CEUR + FVE) por título, ya unido por clave de catálogo; sin documentos en WO no suma.
    $d = dvv_detalle_varios($bdd, $claves, 'normal', true);
    if (!$d['ok']) return $d;

    $filas = []; // clave de catálogo => fila
    $idsPorClave = []; // clave => [id_libro => true]
    $sumar = function ($idLibro, $campo, $cant, $clave) use (&$filas, &$idsPorClave) {
        if (!isset($filas[$clave])) $filas[$clave] = ['remisionada' => 0, 'dev_corte' => 0, 'dev_fisica' => 0];
        $filas[$clave][$campo] += $cant;
        $idsPorClave[$clave][(int)$idLibro] = true;
    };

    // Devoluciones del colegio en el período, separadas en de corte y físicas.
    $st = $bdd->prepare("SELECT ld.id_libro, IF(d.tipo_devolucion = 'corte', 1, 0) AS corte, SUM(ld.cantidad) AS cant
                         FROM libros_devol_v ld JOIN devoluciones_v d ON d.codigo = ld.cod_pedido
                         WHERE d.id_colegio = ? AND d.id_periodo = ? AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . "
                         GROUP BY ld.id_libro, corte");
    $st->execute([$idColegio, $idPeriodo]);
    $devoluciones = $st->fetchAll(PDO::FETCH_ASSOC);

    $clavesCat = dvv_claves_catalogo($bdd, array_column($devoluciones, 'id_libro'));
    foreach ($d['titulos'] as $t) {
        if ($t['id_libro']) $sumar($t['id_libro'], 'remisionada', (int)$t['base'], $t['clave_catalogo']);
    }
    foreach ($devoluciones as $r) $sumar($r['id_libro'], $r['corte'] ? 'dev_corte' : 'dev_fisica', (int)$r['cant'], $clavesCat[(int)$r['id_libro']]);

    // Libro que representa cada fila: el adoptado por el colegio (si hay), si no el menor id. Precio de la adopción.
    $adoptados = dvv_libros_adoptados($bdd, $idColegio, [$idPeriodo]);
    $preciosLibro = dvv_precios_adopcion($bdd, $idColegio, $idPeriodo);
    $precios = [];
    foreach (dvv_claves_catalogo($bdd, array_keys($preciosLibro)) as $idLibro => $clave) $precios[$clave] = $precios[$clave] ?? $preciosLibro[$idLibro];
    $repr = [];
    foreach ($idsPorClave as $clave => $idsL) {
        $idsL = array_keys($idsL);
        sort($idsL);
        $adop = array_values(array_filter($idsL, fn($i) => isset($adoptados[$i])));
        $repr[$clave] = $adop ? $adop[0] : $idsL[0];
    }
    $libros = [];
    if ($repr) {
        foreach ($bdd->query("SELECT id, libro, TRIM(isbn) AS isbn FROM libros WHERE id IN (" . implode(',', array_unique($repr)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $l) $libros[(int)$l['id']] = $l;
    }

    $titulos = [];
    $tot = ['remisionada' => 0, 'dev_corte' => 0, 'dev_fisica' => 0, 'saldo' => 0, 'valor' => 0.0];
    $sinPrecio = 0;
    foreach ($filas as $clave => $f) {
        $idLibro = $repr[$clave];
        $saldo = $f['remisionada'] - $f['dev_corte'] - $f['dev_fisica'];
        $pr = $precios[$clave] ?? null;
        $valor = $pr ? round($saldo * $pr['precio_final'], 2) : null;
        if (!$pr && $saldo != 0) $sinPrecio++;
        $titulos[] = $f + [
            'id_libro' => $idLibro, 'descripcion' => $libros[$idLibro]['libro'] ?? ('Libro #' . $idLibro), 'codigo' => $libros[$idLibro]['isbn'] ?? '',
            'saldo' => $saldo, 'precio' => $pr['precio'] ?? null, 'descuento' => $pr['descuento'] ?? null,
            'precio_final' => $pr['precio_final'] ?? null, 'valor' => $valor, 'adoptado' => (bool)$pr,
        ];
        foreach (['remisionada', 'dev_corte', 'dev_fisica', 'saldo'] as $k) $tot[$k] += $k === 'saldo' ? $saldo : $f[$k];
        $tot['valor'] += (float)$valor;
    }
    usort($titulos, fn($a, $b) => strcasecmp($a['descripcion'], $b['descripcion']));
    $tot['valor'] = round($tot['valor'], 2);
    return ['ok' => true, 'error' => null, 'colegio' => $d['colegio'], 'cliente' => $d['cliente'],
            'pedidos' => array_map(fn($p) => ['id' => $p['id'], 'ops' => $p['ops'], 'documentos' => array_column($p['documentos'], 'documento')], $d['pedidos']),
            'omitidos' => $omitidos, 'titulos' => $titulos, 'totales' => $tot, 'sin_precio' => $sinPrecio];
}
