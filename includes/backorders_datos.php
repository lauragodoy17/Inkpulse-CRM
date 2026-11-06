<?php
/**
 * /includes/backorders_datos.php
 * Calcula el backorder (diferencia entre lo solicitado en un pedido y lo
 * realmente despachado según la remisión/factura de World Office) por
 * libro, reproduciendo a mano lo que antes se armaba en
 * C:\Users\USUARIO\Downloads\BACKORDER.xlsx.
 *
 * Cadena de datos usada (todo ya existente en el CRM, sin inventar ningún
 * cruce por texto/nombre):
 *   pedidos + libros_pedidos        -> cantidad SOLICITADA por libro
 *   ordenes_pedidos (id_pedido)     -> OP directa de ese pedido
 *   op_pedidos_agrupados            -> OP agrupada (varios pedidos en una sola OP)
 *   ordenes_pedidos.n_doc           -> número de la remisión/factura real (World Office)
 *   backorder_wo_docs + ...renglones -> cantidad DESPACHADA por libro según World Office
 *     (cacheado por php/sync_backorders_wo.php, ver ese archivo)
 *
 * El "# OP" que se muestra en el reporte sale de TODA OP asociada al pedido en el CRM (cualquier
 * estado salvo Anulada), no solo de las que ya tienen n_doc: una OP recién creada (Pendiente) sigue
 * "asociada" al pedido aunque todavía no tenga documento de despacho, y debe verse como tal en vez de
 * quedar vacía. Esto es independiente de qué OPs sí se usan para descontar cantidad despachada
 * (esas necesitan n_doc + estado Atendida + tipo REM/FV, ver más abajo).
 *
 * Solo se resuelven remisión (REM) y factura de venta (FV) — los mismos dos
 * tipos de documento que reconoce el sync. Un pedido cuya OP fue atendida con
 * otro tipo de documento (ej. una factura POS) no tiene forma de aportar
 * cantidad despachada por libro todavía (World Office no expone renglones
 * por documento POS de forma confirmada, ver memory/project_wo_api.md), así
 * que esa OP simplemente no descuenta nada — se documenta como limitación
 * conocida, no como bug.
 */

require_once(__DIR__ . '/matching_colegios.php');
// extraer_op_id_de_concepto() / extraer_ped_de_concepto() / cargar_pedidos_por_op(): motivo de
// los "Documentos sin verificar".
require_once(__DIR__ . '/matching_pedidos_wo.php');
// existencias_bodega_general_bulk() + UMBRAL_STOCK_BAJO, mismo origen que ya usa
// includes/reporte_stock_datos.php para "Existencias" — bodega General de World Office, en bulk.
require_once(__DIR__ . '/stock_bajo.php');
// Documentos REM/FV anulados en World Office: no cuentan como despacho.
require_once(__DIR__ . '/backorders_wo_anulados.php');
// Consecutivos "BK {PERIODO}-{NN}" de los backorders con documento confirmado.
require_once(__DIR__ . '/backorders_consecutivos.php');
// Devoluciones de remisión y notas crédito: lo devuelto deja de contar como despachado.
require_once(__DIR__ . '/backorders_wo_devoluciones.php');
// Paquetes: el renglón de un paquete en la REM/FV se abre en sus libros.
require_once(__DIR__ . '/backorders_paquetes.php');

/** "REM 123" / "FV 45", con "(anulada)" si World Office la anuló (las eliminadas ni se listan). */
function etiqueta_doc_backorder(array $op) {
    return $op['tipo_wo'] . ' ' . $op['n_doc'] . (!empty($op['anulado']) ? ' (anulada)' : '');
}

/**
 * Fecha en que se empezó a usar World Office (dada por el usuario, 2026-09-11). Una orden de pedido
 * Atendida (estado=2) con fecha_entrega ANTERIOR a esta fecha no tiene traza confiable en World
 * Office — CON o SIN "Núm. de documento": aunque tenga uno escrito, un documento tan viejo puede no
 * existir ahí o repetirse entre prefijos/series distintas (confirmado con casos reales, ver
 * php/sync_backorders_wo.php). Contarla como 100% pendiente (si no tiene documento) o confiar en su
 * número (si sí lo tiene) es igual de poco confiable — se trata toda OP de antes de esta fecha como
 * no verificable, no como backorder real.
 */
const BACKORDERS_FECHA_MIGRACION_WO = '2026-07-01';

/**
 * Períodos (texto de `periodos.periodo`) que maneja el módulo "Backorders por pedido"
 * (reporte_backorders_pedido.php): sus pedidos son los únicos que se pueden buscar, consultar y
 * atender desde ahí — pedido por el usuario 2026-09-29.
 */
const BACKORDERS_PERIODOS_PEDIDO = ['2026', '2026B', '2027'];

/**
 * Períodos (texto de `periodos.periodo`) que se ofrecen en el selector de período de
 * reporte_backorders.php (Relación de pendientes y reporte por título) — pedido por la usuaria
 * 2026-10-19: solo 2026B y 2027.
 */
const BACKORDERS_PERIODOS_REPORTE = ['2026B', '2027'];

function obtener_ids_periodos_backorders_pedido($bdd, array $periodos = BACKORDERS_PERIODOS_PEDIDO) {
    static $cache = [];
    $clave = implode('|', $periodos);
    if (!isset($cache[$clave])) {
        $ph = implode(',', array_fill(0, count($periodos), '?'));
        $stmt = $bdd->prepare("SELECT id FROM periodos WHERE periodo IN ($ph)");
        $stmt->execute(array_values($periodos));
        $cache[$clave] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cache[$clave];
}

function periodo_permitido_backorders_pedido($bdd, $idPeriodo) {
    return in_array((int)$idPeriodo, obtener_ids_periodos_backorders_pedido($bdd), true);
}

/**
 * Trazabilidad de "Atender backorder — generar pedido nuevo" (php/backorders_generar_pedido.php).
 *
 * Cada renglón es un libro de un pedido NUEVO generado para atender el backorder de un pedido
 * ORIGINAL. El pedido original ya NO se modifica (libros_pedidos.cantidad conserva lo que se pidió
 * originalmente): lo generado se descuenta del saldo pendiente al calcularlo (ver
 * calcular_lineas_backorder()). Un pedido nuevo anulado (estado=3) deja de descontar, así que su
 * cantidad vuelve a quedar pendiente en el original sin tocar nada más.
 *
 * Snapshots guardados al generar (para no perder el contexto aunque después cambien los datos):
 *   solicitada_origen      cantidad pedida originalmente para ese libro
 *   despachada_al_generar  lo despachado (World Office) en ese momento — base para no contar dos
 *                          veces lo que después despache el pedido nuevo en la misma OP
 *   pendiente_antes        saldo pendiente justo antes de generar
 */
function backorders_asegurar_tablas($bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;

    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_pedidos_generados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pedido_origen INT NOT NULL,
        id_pedido_nuevo INT NOT NULL,
        id_op INT NULL,
        id_libro INT NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL,
        solicitada_origen DECIMAL(12,2) NULL,
        despachada_al_generar DECIMAL(12,2) NULL,
        pendiente_antes DECIMAL(12,2) NULL,
        id_usuario INT NULL,
        fecha DATETIME NOT NULL,
        origen_registro VARCHAR(10) NOT NULL DEFAULT 'app',
        UNIQUE KEY uq_nuevo_libro (id_pedido_nuevo, id_libro),
        KEY idx_origen (id_pedido_origen),
        KEY idx_op (id_op)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_migraciones (
        clave VARCHAR(64) PRIMARY KEY,
        fecha DATETIME NOT NULL,
        detalle LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Colegio + período marcado a mano como completado (botón "Marcar como completado" de
    // reporte_backorders_pedido.php): sus pedidos salen de todo el módulo aunque se deban unidades.
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_colegios_completados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_colegio INT NOT NULL,
        id_periodo INT NOT NULL,
        id_usuario INT NULL,
        motivo VARCHAR(500) NULL,
        fecha DATETIME NOT NULL,
        UNIQUE KEY uq_colegio_periodo (id_colegio, id_periodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Vinculación automática de libros con World Office por ISBN (ver backorders_vincular_libros_wo()).
    $bdd->exec("CREATE TABLE IF NOT EXISTS libros_wo_vinculos_auto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_libro INT NOT NULL,
        isbn VARCHAR(60) NOT NULL,
        id_wo INT NOT NULL,
        origen VARCHAR(20) NOT NULL,
        fecha DATETIME NOT NULL,
        KEY idx_libro (id_libro)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $bdd->exec("CREATE TABLE IF NOT EXISTS libros_wo_isbn_sin_match (
        isbn VARCHAR(60) PRIMARY KEY,
        fecha DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    backorders_migrar_generados_legado($bdd);
}

/**
 * Vincula con World Office (libros.id_wo) los libros que no lo tienen, usando su ISBN — pedido por
 * la usuaria 2026-11-06: la consulta por ISBN sí encontraba el producto, pero backorders mostraba
 * "—" en Existencias porque solo usa libros.id_wo. Ese mismo id_wo es el que cruza los renglones
 * de las REM/FV, así que sin él tampoco se contaba lo despachado de esos libros.
 *   1) Otro libro del CRM con el mismo ISBN ya vinculado (libros duplicados, p. ej. "ALL SORTS,
 *      SB 1°…" y "ALL SORTS ELT PRIMARY LATAM LVL1…"): se copia su id_wo, sin llamar a la API.
 *      Solo si todos los vinculados con ese ISBN tienen el mismo id_wo.
 *   2) Si no, se busca en World Office por código exacto = ISBN; solo se usa si hay exactamente un
 *      producto. Los ISBN que no existen allá (p. ej. los "…DG" digitales) se anotan en
 *      libros_wo_isbn_sin_match y no se vuelven a consultar en 7 días.
 * Solo toca libros con id_wo = 0, y cada vínculo queda en libros_wo_vinculos_auto.
 * Devuelve [id_libro => id_wo] de los que se vincularon.
 */
function backorders_vincular_libros_wo($bdd, array $idsLibro) {
    $idsLibro = array_values(array_unique(array_filter(array_map('intval', $idsLibro))));
    if (!$idsLibro) return [];
    backorders_asegurar_tablas($bdd);

    $ph = implode(',', array_fill(0, count($idsLibro), '?'));
    $stmt = $bdd->prepare("SELECT id, TRIM(isbn) AS isbn FROM libros WHERE id IN ($ph) AND id_wo = 0 AND TRIM(IFNULL(isbn, '')) <> ''");
    $stmt->execute($idsLibro);
    $porIsbn = []; // isbn => [id_libro, ...]
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $porIsbn[$r['isbn']][] = (int)$r['id'];
    if (!$porIsbn) return [];

    $isbns = array_keys($porIsbn);
    $phI = implode(',', array_fill(0, count($isbns), '?'));
    $encontrado = []; // isbn => [id_wo, origen]

    // 1) Libro gemelo del CRM con el mismo ISBN ya vinculado.
    $stmt = $bdd->prepare("SELECT TRIM(isbn) AS isbn, MIN(id_wo) AS id_wo, COUNT(DISTINCT id_wo) AS n
                           FROM libros WHERE id_wo > 0 AND TRIM(isbn) IN ($phI) GROUP BY TRIM(isbn)");
    $stmt->execute($isbns);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['n'] === 1) $encontrado[$r['isbn']] = [(int)$r['id_wo'], 'crm_isbn'];
    }

    // 2) World Office por código exacto, salvo los que hace menos de 7 días no se encontraron.
    $faltan = array_values(array_diff($isbns, array_keys($encontrado)));
    if ($faltan) {
        $phF = implode(',', array_fill(0, count($faltan), '?'));
        $stmt = $bdd->prepare("SELECT isbn FROM libros_wo_isbn_sin_match WHERE isbn IN ($phF) AND fecha > NOW() - INTERVAL 7 DAY");
        $stmt->execute($faltan);
        $faltan = array_values(array_diff($faltan, $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    if ($faltan) {
        require_once(__DIR__ . '/api_wo_inventario.php');
        $sinMatch = $bdd->prepare("INSERT INTO libros_wo_isbn_sin_match (isbn, fecha) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE fecha = NOW()");
        foreach (buscar_ids_inventario_por_codigo_bulk($faltan) as $isbn => $idWo) {
            if ($idWo === null) continue; // falló la consulta: se reintenta en la próxima carga
            if ($idWo > 0) $encontrado[$isbn] = [$idWo, 'wo_isbn'];
            else $sinMatch->execute([$isbn]);
        }
    }

    $vinculados = [];
    $upd = $bdd->prepare("UPDATE libros SET id_wo = ? WHERE id = ? AND id_wo = 0");
    $log = $bdd->prepare("INSERT INTO libros_wo_vinculos_auto (id_libro, isbn, id_wo, origen, fecha) VALUES (?, ?, ?, ?, NOW())");
    foreach ($encontrado as $isbn => [$idWo, $origen]) {
        foreach ($porIsbn[$isbn] as $idLibro) {
            $upd->execute([$idWo, $idLibro]);
            if ($upd->rowCount() > 0) {
                $log->execute([$idLibro, $isbn, $idWo, $origen]);
                $vinculados[$idLibro] = $idWo;
            }
        }
    }
    return $vinculados;
}

/**
 * Marca un colegio + período como completado (pedido por la usuaria 2026-10-29): deja de aparecer
 * en la búsqueda y de contar en todo el módulo de backorders (ver el filtro en
 * calcular_lineas_backorder()), aunque todavía se deban unidades.
 */
function backorders_marcar_colegio_completado($bdd, $idColegio, $idPeriodo, $idUsuario, $motivo) {
    backorders_asegurar_tablas($bdd);
    $bdd->prepare("INSERT INTO backorder_colegios_completados (id_colegio, id_periodo, id_usuario, motivo, fecha)
                   VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE id_usuario = VALUES(id_usuario), motivo = VALUES(motivo), fecha = NOW()")
        ->execute([(int)$idColegio, (int)$idPeriodo, $idUsuario ?: null, $motivo !== '' ? $motivo : null]);
}

/**
 * Migración única de los pedidos generados con la lógica anterior (antes de 2026-09-29), que
 * RESTABA la cantidad movida de libros_pedidos.cantidad del pedido original — así se perdía lo
 * que se había pedido originalmente, y si el pedido nuevo se anulaba esa cantidad nunca volvía.
 *
 * Esos pedidos se reconocen por la observación que la lógica anterior les ponía ("Backorder
 * pendiente del pedido #N") + estar agrupados (op_pedidos_agrupados) en una OP del pedido N. Si
 * se generaron en cadena (un pedido nuevo creado a partir de otro pedido nuevo) se ligan al pedido
 * original de la cadena. Para cada pedido original se le devuelve a su línea todo lo que se le
 * restó (la suma de las cantidades actuales de todos sus descendientes, anulados incluidos, porque
 * la lógica anterior nunca devolvía nada), y se registra cada pedido nuevo en
 * backorder_pedidos_generados con su cantidad actual. Queda un registro con el detalle exacto
 * de lo cambiado en backorder_migraciones (clave 'generados_legado').
 */
function backorders_migrar_generados_legado($bdd) {
    $ya = $bdd->query("SELECT COUNT(*) FROM backorder_migraciones WHERE clave = 'generados_legado'")->fetchColumn();
    if ($ya) return;

    $candidatos = $bdd->query("SELECT DISTINCT p.id, p.codigo, p.estado, p.fecha, p.observaciones, a.op
                               FROM pedidos p
                               JOIN op_pedidos_agrupados a ON a.id_pedido = p.id
                               WHERE p.observaciones LIKE '%Backorder pendiente del pedido #%'
                               ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);

    $stmtPedidosDeOp = $bdd->prepare("SELECT id_pedido FROM ordenes_pedidos WHERE id = ?
                                      UNION SELECT id_pedido FROM op_pedidos_agrupados WHERE op = ?");
    $padre = [];  // id_hijo => id_padre
    $hijos = [];  // id_hijo => fila
    foreach ($candidatos as $c) {
        if (!preg_match('/Backorder pendiente del pedido #(\d+)/', $c['observaciones'], $m)) continue;
        $idPadre = (int)$m[1];
        $idHijo = (int)$c['id'];
        if ($idPadre === $idHijo || isset($padre[$idHijo])) continue;
        $stmtPedidosDeOp->execute([$c['op'], $c['op']]);
        $pedidosOp = array_map('intval', $stmtPedidosDeOp->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($idPadre, $pedidosOp, true)) continue; // el "padre" no está en esa OP: no es de este flujo
        $padre[$idHijo] = $idPadre;
        $hijos[$idHijo] = $c;
    }

    $detalle = ['restaurados' => [], 'registrados' => []];
    $bdd->beginTransaction();
    try {
        if (!empty($hijos)) {
            $raiz = function ($id) use ($padre) {
                $visto = [];
                while (isset($padre[$id]) && !isset($visto[$id])) { $visto[$id] = true; $id = $padre[$id]; }
                return $id;
            };
            $stmtCodigo = $bdd->prepare("SELECT codigo FROM pedidos WHERE id = ?");
            $stmtLineas = $bdd->prepare("SELECT id_libro, SUM(cantidad) AS cantidad FROM libros_pedidos WHERE cod_pedido = ? GROUP BY id_libro");
            $stmtLineaRaiz = $bdd->prepare("SELECT id, cantidad FROM libros_pedidos WHERE cod_pedido = ? AND id_libro = ? ORDER BY id LIMIT 1");
            $stmtRestaurar = $bdd->prepare("UPDATE libros_pedidos SET cantidad = cantidad + ? WHERE id = ?");
            $stmtRegistrar = $bdd->prepare("INSERT IGNORE INTO backorder_pedidos_generados
                (id_pedido_origen, id_pedido_nuevo, id_op, id_libro, cantidad, fecha, origen_registro)
                VALUES (?, ?, ?, ?, ?, ?, 'legado')");

            $devolverPorRaiz = []; // id_raiz => [id_libro => cantidad]
            foreach ($hijos as $idHijo => $h) {
                $idRaiz = $raiz($idHijo);
                $stmtLineas->execute([$h['codigo']]);
                foreach ($stmtLineas->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $idLibro = (int)$l['id_libro'];
                    $cant = (float)$l['cantidad'];
                    $devolverPorRaiz[$idRaiz][$idLibro] = ($devolverPorRaiz[$idRaiz][$idLibro] ?? 0) + $cant;
                    $stmtRegistrar->execute([$idRaiz, $idHijo, (int)$h['op'], $idLibro, $cant, $h['fecha']]);
                    $detalle['registrados'][] = ['origen' => $idRaiz, 'nuevo' => $idHijo, 'id_libro' => $idLibro, 'cantidad' => $cant, 'estado_nuevo' => (int)$h['estado']];
                }
            }
            foreach ($devolverPorRaiz as $idRaiz => $porLibro) {
                $stmtCodigo->execute([$idRaiz]);
                $codigoRaiz = $stmtCodigo->fetchColumn();
                if (!$codigoRaiz) continue;
                foreach ($porLibro as $idLibro => $cant) {
                    $stmtLineaRaiz->execute([$codigoRaiz, $idLibro]);
                    $linea = $stmtLineaRaiz->fetch(PDO::FETCH_ASSOC);
                    if (!$linea || $cant <= 0) continue;
                    $stmtRestaurar->execute([$cant, $linea['id']]);
                    $detalle['restaurados'][] = ['pedido' => $idRaiz, 'id_linea' => (int)$linea['id'], 'id_libro' => $idLibro,
                                                 'antes' => (float)$linea['cantidad'], 'despues' => (float)$linea['cantidad'] + $cant];
                }
            }
        }
        $bdd->prepare("INSERT INTO backorder_migraciones (clave, fecha, detalle) VALUES ('generados_legado', NOW(), ?)")
            ->execute([json_encode($detalle)]);
        $bdd->commit();
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        throw $e;
    }

    // Base de despacho de los registros migrados = lo despachado HOY para ese pedido+libro (no hay
    // forma de saber cuánto era al momento de generarlos). Es el supuesto conservador: cuenta lo
    // generado como aún no despachado, así nunca se sobreestima el saldo (ni se permite generar de
    // más sobre un backorder que ya estaba cubierto).
    $origenes = array_values(array_unique(array_map(fn($r) => $r['origen'], $detalle['registrados'])));
    if (!empty($origenes)) {
        $calc = calcular_lineas_backorder($bdd, null, null, null, null, $origenes);
        $stmtBase = $bdd->prepare("UPDATE backorder_pedidos_generados SET despachada_al_generar = ?
                                   WHERE id_pedido_origen = ? AND id_libro = ? AND despachada_al_generar IS NULL");
        foreach ($calc['lineas'] as $l) {
            $stmtBase->execute([$l['despachada'], $l['id_pedido'], $l['id_libro']]);
        }
    }
}

/**
 * Pedidos nuevos generados (backorder_pedidos_generados) relacionados con una lista de pedidos:
 *   hijos          id_pedido_nuevo => id_pedido_origen   (todos, cualquier estado — para excluirlos
 *                                                         como columnas propias del reporte)
 *   hijosActivos   id_pedido_origen => [id_pedido_nuevo] (no anulados — sus despachos se suman al original)
 *   generada       id_pedido_origen => [id_libro => cantidad generada en pedidos no anulados]
 *   base           id_pedido_origen => [id_libro => despachada_al_generar mínima de los no anulados]
 */
function backorders_familias($bdd, array $idsPedido) {
    $vacio = ['hijos' => [], 'hijosActivos' => [], 'generada' => [], 'base' => []];
    if (empty($idsPedido)) return $vacio;
    backorders_asegurar_tablas($bdd);
    $ph = implode(',', array_fill(0, count($idsPedido), '?'));
    $stmt = $bdd->prepare("SELECT g.id_pedido_origen, g.id_pedido_nuevo, g.id_libro, g.cantidad, g.despachada_al_generar, p.estado
                           FROM backorder_pedidos_generados g
                           JOIN pedidos p ON p.id = g.id_pedido_nuevo
                           WHERE g.id_pedido_origen IN ($ph) OR g.id_pedido_nuevo IN ($ph)");
    $stmt->execute(array_merge(array_values($idsPedido), array_values($idsPedido)));
    $res = $vacio;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $orig = (int)$r['id_pedido_origen'];
        $nuevo = (int)$r['id_pedido_nuevo'];
        $res['hijos'][$nuevo] = $orig;
        if ((int)$r['estado'] === 3) continue;
        $res['hijosActivos'][$orig][$nuevo] = $nuevo;
        $lib = (int)$r['id_libro'];
        $res['generada'][$orig][$lib] = ($res['generada'][$orig][$lib] ?? 0) + (float)$r['cantidad'];
        if ($r['despachada_al_generar'] !== null) {
            $b = (float)$r['despachada_al_generar'];
            $res['base'][$orig][$lib] = isset($res['base'][$orig][$lib]) ? min($res['base'][$orig][$lib], $b) : $b;
        }
    }
    return $res;
}

/**
 * % de cumplimiento de un backorder = lo DESPACHADO en World Office (remisión/factura asociada) ÷ lo
 * solicitado. Lo generado en pedidos nuevos NO cuenta como cumplido hasta que se despache: sin
 * ningún documento asociado queda en 0% — decisión del usuario 2026-09-29. Mismo criterio en las
 * tres vistas (relación de pendientes, backorder general de libros, backorder por pedido) y en
 * sus Excel.
 */
function porcentaje_cumplimiento_backorder($solicitada, $despachada) {
    $solicitada = (float)$solicitada;
    if ($solicitada <= 0) return 100.0;
    return round(max(0, min(100, (float)$despachada / $solicitada * 100)), 1);
}

function resolver_tipo_doc_ids_backorders_datos($bdd) {
    $rows = $bdd->query("SELECT id, descrip FROM tipo_doc")->fetchAll(PDO::FETCH_ASSOC);
    $mapa = []; // id_tipo_doc => 'REM'|'FV'
    foreach ($rows as $r) {
        $d = mb_strtoupper($r['descrip'], 'UTF-8');
        if (strpos($d, 'REMISION') !== false && strpos($d, 'VENTA') !== false
            && strpos($d, 'CORTESIA') === false && strpos($d, 'MUESTRA') === false) {
            $mapa[(int)$r['id']] = 'REM';
        } elseif (strpos($d, 'FACTURA') !== false && strpos($d, 'VENTA') !== false
            && strpos($d, 'CORTE') === false) {
            $mapa[(int)$r['id']] = 'FV';
        }
    }
    return $mapa;
}

/**
 * Períodos disponibles para el selector del reporte, más cuál está "activo" hoy (CURDATE() dentro
 * de fecha_inicio/fecha_fin — ver includes/periodos_fechas.php). Si ninguno cubre la fecha actual
 * (huecos entre calendarios, o períodos sin fecha calculada todavía), cae al de id más alto.
 */
function obtener_periodos_backorders($bdd) {
    $ph = implode(',', array_fill(0, count(BACKORDERS_PERIODOS_REPORTE), '?'));
    $stmt = $bdd->prepare("SELECT p.id, p.periodo, p.fecha_inicio, p.fecha_fin, c.calendario
                              FROM periodos p JOIN calendarios c ON c.id = p.id_calendario
                              WHERE p.periodo IN ($ph)
                              ORDER BY p.id DESC");
    $stmt->execute(BACKORDERS_PERIODOS_REPORTE);
    $periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activo = null;
    foreach ($periodos as $p) {
        if ($p['fecha_inicio'] && $p['fecha_fin'] && $p['fecha_inicio'] <= date('Y-m-d') && date('Y-m-d') <= $p['fecha_fin']) {
            $activo = (int)$p['id'];
            break;
        }
    }
    if ($activo === null && !empty($periodos)) $activo = (int)$periodos[0]['id'];
    return ['periodos' => $periodos, 'periodoActivo' => $activo];
}

/**
 * Usuarios para el selector "Usuario" del reporte, agrupados igual que en index.php/
 * dashboard_asesores_stats.php: promotores ("Eureka": tipo=3 o id=69 por excepción de negocio),
 * distribuidores (tipo=6) y otros (admins/operadores sin territorio, tipo IN (1,4,10)). Los
 * promotores/distribuidores se listan siempre que estén activos (aunque no hayan digitado
 * ningún pedido ellos mismos — igual les aplica el backorder de los colegios de su zona, ver
 * obtener_datos_backorders()); "otros" solo se listan si de verdad registraron algún pedido.
 */
function obtener_usuarios_backorders($bdd, array $idsConPendiente) {
    // Solo se listan usuarios (promotor/distribuidor/otro) que de verdad tienen algo pendiente por
    // surtir ahora mismo — antes salían TODOS los activos aunque no se les debiera nada, pedido por
    // el usuario 2026-09-18 ("que solo salgan a los que se les debe algo"). $idsConPendiente ya viene
    // calculado (mismo criterio "responsable" que agrupa el reporte, ver calcular_lineas_backorder())
    // así que aquí no hace falta repetir ese cálculo, solo filtrar por él.
    if (empty($idsConPendiente)) {
        return ['promotores' => [], 'distribuidores' => [], 'otros' => []];
    }
    $ph = implode(',', array_fill(0, count($idsConPendiente), '?'));

    $stmtProm = $bdd->prepare("SELECT id, nombres, apellidos FROM usuarios WHERE (tipo=3 OR id=69) AND act=1 AND id IN ($ph) ORDER BY nombres, apellidos");
    $stmtProm->execute($idsConPendiente);
    $promotores = $stmtProm->fetchAll(PDO::FETCH_ASSOC);

    $stmtDistr = $bdd->prepare("SELECT id, nombres, apellidos FROM usuarios WHERE tipo=6 AND act=1 AND id IN ($ph) ORDER BY nombres, apellidos");
    $stmtDistr->execute($idsConPendiente);
    $distribuidores = $stmtDistr->fetchAll(PDO::FETCH_ASSOC);

    $stmtOtros = $bdd->prepare("SELECT DISTINCT u.id, u.nombres, u.apellidos
                           FROM pedidos p
                           JOIN usuarios u ON u.id = p.id_usuario
                           WHERE p.estado != 3 AND u.tipo IN (1, 4, 10) AND u.id <> 69 AND u.id IN ($ph)
                           ORDER BY u.nombres, u.apellidos");
    $stmtOtros->execute($idsConPendiente);
    $otros = $stmtOtros->fetchAll(PDO::FETCH_ASSOC);

    return ['promotores' => $promotores, 'distribuidores' => $distribuidores, 'otros' => $otros];
}

/**
 * IDs de usuario (responsable) con algo pendiente por surtir, acotado al RANGO DE FECHAS del
 * calendario de un período (fecha_inicio/fecha_fin de `periodos`, ver
 * includes/periodos_fechas.php) en vez del id_periodo literal de cada pedido — pedido por el
 * usuario 2026-09-18. Cada pedido candidato se calcula aislado (mismo motivo que los buscadores
 * de cliente/pedido, ver el comentario sobre $idsPedidoLista en calcular_lineas_backorder()), así
 * que el selector "Usuario" queda consistente con lo que se ve al abrir cualquiera de esos
 * pedidos. Sin rango de fechas (período sin calendario con regla, ver calcular_rango_periodo())
 * no se filtra por fecha — se consideran todos los pedidos no Anulados.
 */
function obtener_usuarios_con_pendiente_en_rango($bdd, $fechaInicio, $fechaFin) {
    $condiciones = 'estado != 3';
    $params = [];
    if ($fechaInicio && $fechaFin) {
        $condiciones .= ' AND fecha BETWEEN ? AND ?';
        $params = [$fechaInicio, $fechaFin];
    }
    $stmt = $bdd->prepare("SELECT id FROM pedidos WHERE $condiciones");
    $stmt->execute($params);
    $idsPedidos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($idsPedidos)) return [];

    $calc = calcular_lineas_backorder($bdd, null, null, null, null, $idsPedidos);
    $idsUsuarios = [];
    foreach ($calc['lineas'] as $l) {
        if ($l['pendiente'] > 0 && !empty($l['id_usuario'])) $idsUsuarios[(int)$l['id_usuario']] = true;
    }
    return array_keys($idsUsuarios);
}

/**
 * Núcleo compartido del cálculo de backorder: trae, línea por línea (pedido + libro), cuánto se
 * solicitó, cuánto se despachó (según World Office) y cuánto queda pendiente — sin agrupar
 * todavía. obtener_datos_backorders() lo agrupa por COLEGIO (una columna por colegio; antes por pedido, como el
 * Excel manual original); obtener_datos_backorders_por_titulo() lo agrupa por TÍTULO y, dentro
 * de cada uno, por usuario que lo pidió. Se extrajo a una sola función para no duplicar toda
 * esta lógica (con todos sus casos borde ya resueltos a pulso: id_wo puente con World Office,
 * pre-migración, OPs agrupadas, etc.) en dos sitios que se desincronizarían con el tiempo.
 *
 * A diferencia de obtener_datos_backorders() (que descarta las líneas ya 100% despachadas),
 * aquí se devuelven TODAS las líneas no-preMigración, con pendiente=0 para las ya cumplidas —
 * el reporte por título necesita ver el cuadro completo (cuánto se pidió/despachó en total) para
 * calcular el % de cumplimiento, no solo lo que falta.
 *
 * Las devoluciones de remisión y notas crédito NO se aplican aquí (por pedido): afectan al colegio en
 * general — ver backorders_grupos_colegio() en includes/backorders_wo_devoluciones.php.
 */
function calcular_lineas_backorder($bdd, $idPeriodo = null, $idUsuario = null, $idPedidoUnico = null, $idCliente = null, array $idsPedidoLista = null, $idColegio = null) {
    // Filtro opcional por una LISTA puntual de pedidos (a diferencia de $idPedidoUnico, que es uno
    // solo) — usado por los buscadores de pedido/cliente (php/backorders_buscar_pedido*.php) para
    // saber cuáles de sus resultados YA encontrados por texto (colegio/cliente/# pedido) tienen algo
    // pendiente, sin tener que calcular el backorder de TODO el historial de pedidos en cada tecla
    // escrita (ver el comentario más abajo sobre por qué el reporte general sí se acota por período).
    if ($idsPedidoLista !== null && empty($idsPedidoLista)) {
        return ['lineas' => [], 'sinSincronizar' => [], 'docsAnulados' => [], 'despachoPedidosNuevos' => [], 'preMigracion' => [], 'opsAsociadasPorPedido' => []];
    }

    $mapaTipoDoc = resolver_tipo_doc_ids_backorders_datos($bdd);
    backorders_asegurar_tablas($bdd); // backorder_colegios_completados, usada en el filtro de abajo

    // 1) Líneas de pedido: cantidad solicitada por libro. Se excluyen los pedidos Anulados (estado=3,
    //    ver estados_pedidos) — un pedido cancelado no tiene nada pendiente por despachar. Se filtra
    //    por período (p.id_periodo) para no traer TODO el historial de pedidos de una — con "todo
    //    pedido, cualquier estado" sin acotar por período, el reporte llegó a tener ~1900 columnas (una
    //    por pedido) y tardaba más de 100s en generarse en Excel.
    //
    //    El "responsable" de cada línea (usado para agrupar en obtener_datos_backorders_por_titulo())
    //    tampoco es "quién digitó el pedido" (p.id_usuario) — es el dueño de la ZONA del colegio
    //    (colegios.cod_zona = usuarios.cod_zona, con owner.tipo IN (3,6) o id=69), exactamente el mismo
    //    dato que pedido_colegio.php muestra como "Promotor / Empresa" en la vista de un pedido. Un
    //    operador/admin puede digitar el pedido por otra persona, así que p.id_usuario casi nunca es
    //    quien realmente "es dueño" de ese colegio — reportado por el usuario 2026-09-16 (pedido #1987,
    //    "LA LLORONA": el reporte mostraba usuario "—" con p.id_usuario=0, pero la ficha del pedido sí
    //    mostraba "Hector Morales" como Promotor/Empresa, resuelto por zona). Si el colegio no tiene
    //    dueño de zona activo, se cae a p.id_usuario como respaldo (mejor un nombre aproximado que nada).
    //
    //    El filtro opcional por usuario NO es siempre "quién digitó el pedido" (p.id_usuario): para un
    //    asesor/distribuidor real (tipo 3/6) o Hector Morales (id=69, excepción de negocio), se filtra
    //    por la ZONA del colegio (colegios.cod_zona = usuarios.cod_zona del asesor) — mismo criterio
    //    ya usado en php/dashboard_asesores_stats.php: un colegio siempre debe sumarle al mismo
    //    asesor/distribuidor sin importar quién haya digitado cada pedido (a veces lo digita un
    //    operador/admin por él). Filtrar por p.id_usuario aquí dejaba fuera pedidos reales de su zona
    //    que otra persona había digitado — reportado por el usuario 2026-09-16 (con Hector Morales,
    //    veía 2 pedidos con backorder en el informe general pero el filtro solo mostraba 1). Para
    //    cualquier otro tipo de usuario (admins/operadores sin territorio) no hay zona que aplicar, así
    //    que se sigue usando el criterio literal p.id_usuario.
    $filtroUsuarioSql = '';
    if ($idUsuario) {
        $tipoUsuarioFiltro = (int)$bdd->query("SELECT tipo FROM usuarios WHERE id = " . (int)$idUsuario)->fetchColumn();
        $filtroPorZona = in_array($tipoUsuarioFiltro, [3, 6], true) || (int)$idUsuario === 69;
        $filtroUsuarioSql = $filtroPorZona
            ? " AND c.cod_zona = (SELECT cod_zona FROM usuarios WHERE id = :id_usuario)"
            : " AND p.id_usuario = :id_usuario";
    }

    // Filtro opcional por cliente (catálogo World Office, tabla `clientes`) — a diferencia del
    // filtro por usuario, este es un match literal por id: el cliente de un pedido es un dato fijo
    // que viene directo de World Office, no depende de zona/territorio de nadie.
    $filtroClienteSql = $idCliente ? " AND p.cliente = :id_cliente" : "";
    // Filtro opcional por colegio (todos sus pedidos, repartiendo documentos compartidos igual que
    // el reporte general) — lo usa el detalle por pedido para mostrar el total del colegio.
    if ($idColegio) $filtroClienteSql .= " AND p.id_colegio = :id_colegio";

    $filtroListaSql = '';
    $paramsLista = [];
    if ($idsPedidoLista !== null) {
        $nombresLista = [];
        foreach (array_values($idsPedidoLista) as $i => $idP) {
            $nombre = ':idlista' . $i;
            $nombresLista[] = $nombre;
            $paramsLista[$nombre] = (int)$idP;
        }
        $filtroListaSql = " AND p.id IN (" . implode(',', $nombresLista) . ")";
    }

    $sqlLineas = "SELECT lp.id_libro, lp.cantidad, l.libro, l.isbn, l.id_wo AS libro_id_wo,
                         p.id AS id_pedido, p.codigo AS cod_pedido, p.estado AS estado_pedido, p.id_usuario, p.cliente AS id_cliente, p.id_periodo,
                         c.id AS id_colegio,
                         c.colegio, cl.cliente AS cliente_nombre,
                         owner.id AS id_responsable,
                         CONCAT(TRIM(owner.nombres), ' ', TRIM(owner.apellidos)) AS responsable_nombre,
                         CONCAT(TRIM(u2.nombres), ' ', TRIM(u2.apellidos)) AS usuario_nombre
                  FROM libros_pedidos lp
                  JOIN pedidos p ON p.codigo = lp.cod_pedido
                  JOIN libros l ON l.id = lp.id_libro
                  JOIN colegios c ON c.id = p.id_colegio
                  LEFT JOIN clientes cl ON cl.id = p.cliente
                  LEFT JOIN usuarios u2 ON u2.id = p.id_usuario
                  LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> ''
                       AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)
                  WHERE p.estado != 3
                    AND NOT EXISTS (SELECT 1 FROM backorder_colegios_completados bcc
                                    WHERE bcc.id_colegio = p.id_colegio AND bcc.id_periodo = p.id_periodo)"
                  . ($idPeriodo ? " AND p.id_periodo = :id_periodo" : "")
                  . ($idPedidoUnico ? " AND p.id = :id_pedido_unico" : "")
                  . $filtroListaSql
                  . $filtroUsuarioSql
                  . $filtroClienteSql . "
                  ORDER BY p.id, l.libro";
    $stmtLineas = $bdd->prepare($sqlLineas);
    if ($idPeriodo) $stmtLineas->bindValue(':id_periodo', $idPeriodo, PDO::PARAM_INT);
    if ($idPedidoUnico) $stmtLineas->bindValue(':id_pedido_unico', $idPedidoUnico, PDO::PARAM_INT);
    foreach ($paramsLista as $nombre => $valor) $stmtLineas->bindValue($nombre, $valor, PDO::PARAM_INT);
    if ($idUsuario) $stmtLineas->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    if ($idCliente) $stmtLineas->bindValue(':id_cliente', $idCliente, PDO::PARAM_INT);
    if ($idColegio) $stmtLineas->bindValue(':id_colegio', $idColegio, PDO::PARAM_INT);
    $stmtLineas->execute();
    $lineas = $stmtLineas->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lineas)) return ['lineas' => [], 'sinSincronizar' => [], 'docsAnulados' => [], 'despachoPedidosNuevos' => [], 'preMigracion' => [], 'opsAsociadasPorPedido' => []];

    // Libros sin id_wo: se intenta vincularlos por ISBN antes de cruzar documentos y existencias.
    $idsSinWo = [];
    foreach ($lineas as $l) if ((int)$l['libro_id_wo'] === 0) $idsSinWo[(int)$l['id_libro']] = true;
    if ($idsSinWo) {
        $vinculados = backorders_vincular_libros_wo($bdd, array_keys($idsSinWo));
        if ($vinculados) {
            foreach ($lineas as &$l) {
                if (isset($vinculados[(int)$l['id_libro']])) $l['libro_id_wo'] = $vinculados[(int)$l['id_libro']];
            }
            unset($l);
        }
    }

    // 1b) Pedidos NUEVOS generados para atender el backorder de otro pedido (ver
    //     backorders_asegurar_tablas()): no son un backorder propio sino parte del pedido original,
    //     así que no se listan como pedido independiente — su cantidad se descuenta del saldo del
    //     original (paso 4) y lo que despachen se le suma al original (paso 2e).
    $familias = backorders_familias($bdd, array_values(array_unique(array_map(fn($l) => (int)$l['id_pedido'], $lineas))));
    $lineas = array_values(array_filter($lineas, fn($l) => !isset($familias['hijos'][(int)$l['id_pedido']])));
    if (empty($lineas)) return ['lineas' => [], 'sinSincronizar' => [], 'docsAnulados' => [], 'despachoPedidosNuevos' => [], 'preMigracion' => [], 'opsAsociadasPorPedido' => []];

    $idsPedido = array_values(array_unique(array_map(fn($l) => (int)$l['id_pedido'], $lineas)));
    $ph = implode(',', array_fill(0, count($idsPedido), '?'));
    // Para buscar documentos de despacho se incluyen también los pedidos nuevos (no anulados) de
    // cada original.
    $origenPorHijo = []; // id_pedido_nuevo => id_pedido_origen (solo de los originales de este cálculo)
    foreach ($idsPedido as $idP) {
        foreach ($familias['hijosActivos'][$idP] ?? [] as $idHijo) $origenPorHijo[$idHijo] = $idP;
    }
    $idsPedidoOps = array_values(array_unique(array_merge($idsPedido, array_keys($origenPorHijo))));
    $phOps = implode(',', array_fill(0, count($idsPedidoOps), '?'));

    // 2) OPs atendidas (estado=2) ligadas a esos pedidos, directas o agrupadas, de tipo REM/FV.
    $opsPorPedido = []; // id_pedido => [ ['op_id'=>, 'n_doc'=>, 'tipo_wo'=>, 'fecha'=>], ... ]
    $stmtDirectas = $bdd->prepare("SELECT id_pedido, id AS op_id, n_doc, tipo_doc, fecha_entrega
                                    FROM ordenes_pedidos
                                    WHERE estado = 2 AND n_doc IS NOT NULL AND n_doc != '' AND id_pedido IN ($phOps)");
    $stmtDirectas->execute($idsPedidoOps);
    foreach ($stmtDirectas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tipoWo = $mapaTipoDoc[(int)$row['tipo_doc']] ?? null;
        if (!$tipoWo) continue;
        $opsPorPedido[(int)$row['id_pedido']][] = [
            'op_id' => (int)$row['op_id'], 'n_doc' => trim($row['n_doc']), 'tipo_wo' => $tipoWo, 'fecha' => $row['fecha_entrega'],
        ];
    }
    $stmtAgrupadas = $bdd->prepare("SELECT a.id_pedido, o.id AS op_id, o.n_doc, o.tipo_doc, o.fecha_entrega
                                     FROM op_pedidos_agrupados a
                                     JOIN ordenes_pedidos o ON o.id = a.op
                                     WHERE o.estado = 2 AND o.n_doc IS NOT NULL AND o.n_doc != '' AND a.id_pedido IN ($phOps)");
    $stmtAgrupadas->execute($idsPedidoOps);
    foreach ($stmtAgrupadas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tipoWo = $mapaTipoDoc[(int)$row['tipo_doc']] ?? null;
        if (!$tipoWo) continue;
        $opsPorPedido[(int)$row['id_pedido']][] = [
            'op_id' => (int)$row['op_id'], 'n_doc' => trim($row['n_doc']), 'tipo_wo' => $tipoWo, 'fecha' => $row['fecha_entrega'],
        ];
    }

    // 2c) OPs asociadas a estos pedidos en el CRM (cualquier estado salvo Anulada), directas o
    //     agrupadas — se usan solo para mostrar el "# OP" del reporte, independiente de si esa OP ya
    //     tiene documento de despacho (n_doc) o no. Sin esto, un pedido con una OP recién creada
    //     (Pendiente, sin n_doc todavía) quedaba sin ningún número de OP en el reporte aunque el CRM
    //     sí la tenga asociada — mismo criterio "estado != 4" ya usado en pedido_colegio.php para
    //     saber si un pedido "tiene OP". Reportado por el usuario 2026-09-11.
    $opsAsociadasPorPedido = []; // id_pedido => [op_id, ...]
    $stmtOpsDirectas = $bdd->prepare("SELECT id_pedido, id AS op_id FROM ordenes_pedidos WHERE estado != 4 AND id_pedido IN ($ph)");
    $stmtOpsDirectas->execute($idsPedido);
    foreach ($stmtOpsDirectas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $opsAsociadasPorPedido[(int)$row['id_pedido']][] = (int)$row['op_id'];
    }
    $stmtOpsAgrupadas = $bdd->prepare("SELECT a.id_pedido, o.id AS op_id
                                        FROM op_pedidos_agrupados a
                                        JOIN ordenes_pedidos o ON o.id = a.op
                                        WHERE o.estado != 4 AND a.id_pedido IN ($ph)");
    $stmtOpsAgrupadas->execute($idsPedido);
    foreach ($stmtOpsAgrupadas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $opsAsociadasPorPedido[(int)$row['id_pedido']][] = (int)$row['op_id'];
    }

    // 2d) Documentos REM/FV que php/sync_backorders_wo_catalogo.php verificó contra un pedido
    //     específico (cruzando "OP <año>-<id>" y "PED <numero>" del concepto contra el propio CRM,
    //     ver includes/matching_pedidos_wo.php) — resuelve el caso en que la OP de un pedido nunca
    //     quedó con "Núm. de documento" asociado, así que el paso 2 de arriba nunca la habría
    //     encontrado. Se suman a $opsPorPedido igual que una OP directa/agrupada (sin op_id real,
    //     no hay ninguna OP que mostrar en "# OP" para estas) para que el paso 3 las use exactamente
    //     igual al calcular despachado. El sync ya excluye documentos de antes de la migración a
    //     World Office, así que no hace falta filtrar de nuevo por fecha acá.
    //     `backorder_wo_catalogo` la crea ese sync — puede no existir todavía si nadie lo ha
    //     corrido, así que la consulta se envuelve en try/catch (igual que cualquier caché opcional
    //     que depende de una sincronización manual previa) en vez de romper todo el reporte.
    try {
        // Sin los anulados/eliminados: un documento borrado en World Office y vuelto a crear con el
        // MISMO número (caso real REM 7703, 2026-10-20) dejaba en el catálogo la versión vieja ligada
        // a otro pedido, y el número se le atribuía a ese pedido en vez de al del documento vigente.
        $stmtCatalogoVerificado = $bdd->prepare("SELECT id_pedido_verificado, tipo_documento, numero, fecha
                                                  FROM backorder_wo_catalogo
                                                  WHERE id_pedido_verificado IN ($phOps) AND anulado = 0");
        $stmtCatalogoVerificado->execute($idsPedidoOps);
        foreach ($stmtCatalogoVerificado->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $opsPorPedido[(int)$row['id_pedido_verificado']][] = [
                'op_id' => null, 'n_doc' => trim($row['numero']), 'tipo_wo' => $row['tipo_documento'], 'fecha' => $row['fecha'],
            ];
        }
    } catch (Exception $e) {}

    // 2e) Lo despachado por los pedidos nuevos cuenta para su pedido original (sin repetir un
    //     documento que ya estuviera en la lista: el pedido nuevo va agrupado en la MISMA OP).
    //     Cada documento queda marcado con el pedido del que viene ('de_pedido'): los del pedido
    //     NUEVO (su REM/FV trae "OP <la misma>; PED <pedido nuevo>") son la columna «En pedidos
    //     nuevos» — pedido por la usuaria 2026-10-20. Si un documento aparece en los dos (el
    //     "Núm. de documento" de la OP compartida), cuenta como del original.
    foreach ($opsPorPedido as $idP => $ops) {
        foreach ($ops as $k => $op) $opsPorPedido[$idP][$k]['de_pedido'] = $idP;
    }
    foreach ($origenPorHijo as $idHijo => $idOrigen) {
        foreach ($opsPorPedido[$idHijo] ?? [] as $op) $opsPorPedido[$idOrigen][] = $op;
        unset($opsPorPedido[$idHijo]);
    }
    foreach ($opsPorPedido as $idP => $ops) {
        $unicos = [];
        foreach ($ops as $op) $unicos[$op['tipo_wo'] . '|' . $op['n_doc']] = $unicos[$op['tipo_wo'] . '|' . $op['n_doc']] ?? $op;
        $opsPorPedido[$idP] = array_values($unicos);
    }

    // 2b) Pedidos con alguna OP Atendida de ANTES de la migración a World Office (ver
    //     BACKORDERS_FECHA_MIGRACION_WO) — CON o SIN "Núm. de documento". Aunque tenga un número
    //     escrito, un documento tan viejo no tiene traza confiable en World Office (puede no existir
    //     ahí, o repetirse entre prefijos/series distintas como pasó con la remisión 5656 del caso
    //     CEUR 7637 — ver commit anterior) — pedido real: OP con n_doc "3723" de un pedido igual de
    //     viejo (colegio Campoalegre) reportado por el usuario 2026-09-11 como no confiable pese a
    //     tener documento. Se excluyen del cálculo de backorder (en vez de contarlos como 100%
    //     pendiente, o de confiar en un documento no verificable) y se listan aparte para revisión
    //     manual.
    $preMigracionPorPedido = []; // id_pedido => fecha_entrega
    $stmtPreMigDirectas = $bdd->prepare("SELECT id_pedido, fecha_entrega
                                          FROM ordenes_pedidos
                                          WHERE estado = 2 AND fecha_entrega < ? AND id_pedido IN ($ph)");
    $stmtPreMigDirectas->execute(array_merge([BACKORDERS_FECHA_MIGRACION_WO], $idsPedido));
    foreach ($stmtPreMigDirectas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $preMigracionPorPedido[(int)$row['id_pedido']] = $row['fecha_entrega'];
    }
    $stmtPreMigAgrupadas = $bdd->prepare("SELECT a.id_pedido, o.fecha_entrega
                                           FROM op_pedidos_agrupados a
                                           JOIN ordenes_pedidos o ON o.id = a.op
                                           WHERE o.estado = 2 AND o.fecha_entrega < ? AND a.id_pedido IN ($ph)");
    $stmtPreMigAgrupadas->execute(array_merge([BACKORDERS_FECHA_MIGRACION_WO], $idsPedido));
    foreach ($stmtPreMigAgrupadas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idP = (int)$row['id_pedido'];
        if (!isset($preMigracionPorPedido[$idP])) $preMigracionPorPedido[$idP] = $row['fecha_entrega'];
    }

    // 3) Cantidad despachada por libro, para cada (tipo, numero) de documento realmente usado arriba.
    //    Se saltan los pedidos ya marcados como preMigración (2b): aunque tengan un n_doc escrito, no
    //    se van a usar (el pedido entero se excluye en el paso 4) — pedirle a World Office que
    //    resuelva ese documento sería trabajo de sync innecesario y generaría avisos de
    //    "sinSincronizar" para documentos que ya no importan.
    $pares = []; // "TIPO|numero" => ['tipo'=>, 'numero'=>]
    foreach ($opsPorPedido as $idPedidoOps => $ops) {
        if (isset($preMigracionPorPedido[$idPedidoOps])) continue;
        foreach ($ops as $op) $pares[$op['tipo_wo'] . '|' . $op['n_doc']] = ['tipo' => $op['tipo_wo'], 'numero' => $op['n_doc']];
    }
    // Se lee la tabla de caché completa (sin filtrar por los pares usados arriba): por construcción,
    // php/sync_backorders_wo.php SOLO inserta ahí documentos que sí hacían falta, así que ya viene
    // acotada de por sí. Filtrar además con un WHERE de un "OR" por cada (tipo, numero) distinto (que
    // puede llegar a cientos o miles según cuántas OPs Atendidas haya) es un antipatrón de MySQL que
    // volvía esta consulta extremadamente lenta en producción y llegó a agotar el tiempo de ejecución
    // de TODA la sesión (PHP bloquea el archivo de sesión mientras una petición está en curso, así que
    // cualquier otra pestaña del mismo usuario quedaba esperando y terminaba en el mismo timeout).
    // Los documentos anulados en World Office no despacharon nada: ya no tienen renglones (ver
    // includes/backorders_wo_anulados.php) y además se excluyen aquí explícitamente.
    backorders_asegurar_columna_anulado($bdd);
    // Un renglón con código de paquete del CRM cuenta como esa cantidad de cada libro del paquete
    // (ver includes/backorders_paquetes.php); las claves son las de backorders_clave_libro().
    $despachado = []; // "TIPO|numero" => [clave_libro => cantidad]
    $despachoPaquetesDoc = []; // "TIPO|numero" => [id_paquete => cantidad]
    $sqlDespacho = "SELECT d.tipo_documento, d.numero, r.id_libro, r.isbn, SUM(r.cantidad) AS cantidad
                     FROM backorder_wo_docs d
                     JOIN backorder_wo_renglones r ON r.id_wo = d.id_wo AND r.tipo_documento = d.tipo_documento
                     WHERE (r.id_libro IS NOT NULL OR r.isbn IS NOT NULL) AND d.anulado = 0
                     GROUP BY d.tipo_documento, d.numero, r.id_libro, r.isbn";
    foreach ($bdd->query($sqlDespacho)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $claveDoc = $row['tipo_documento'] . '|' . $row['numero'];
        $cantidad = (float)$row['cantidad'];
        $paquete = backorders_paquete_de_codigo($bdd, $row['isbn']);
        if ($paquete) {
            $despachoPaquetesDoc[$claveDoc][$paquete['id']] = ($despachoPaquetesDoc[$claveDoc][$paquete['id']] ?? 0) + $cantidad;
            foreach ($paquete['libros'] as $lib) {
                $despachado[$claveDoc][$lib['clave']] = ($despachado[$claveDoc][$lib['clave']] ?? 0) + $cantidad;
            }
        } elseif ($row['id_libro'] !== null) {
            $despachado[$claveDoc][(int)$row['id_libro']] = ($despachado[$claveDoc][(int)$row['id_libro']] ?? 0) + $cantidad;
        }
    }
    // Documentos que sí quedaron pendientes de sincronizar (aún no se corrió el sync, o World Office
    // no tiene ese número) — se informan aparte para que el usuario sepa que el backorder de esas
    // filas puede estar sobreestimado mientras no se sincronicen.
    $stmtResueltos = $bdd->query("SELECT tipo_documento, numero FROM backorder_wo_docs");
    $resueltos = [];
    foreach ($stmtResueltos->fetchAll(PDO::FETCH_ASSOC) as $row) $resueltos[$row['tipo_documento'] . '|' . $row['numero']] = true;
    $sinSincronizar = [];
    foreach ($pares as $clave => $p) if (!isset($resueltos[$clave])) $sinSincronizar[] = $p;

    // Documentos usados por estos pedidos que World Office anuló: se marcan en cada OP (para
    // mostrarlos como "(anulada)") y se informan aparte para revisar la OP. Los ELIMINADOS de World
    // Office desaparecen por completo del reporte (ni en "Documento" ni en el aviso) — pedido por
    // la usuaria 2026-10-20; su fila en backorder_wo_docs se conserva solo para que el sync no los
    // vuelva a buscar ni salgan como "sin sincronizar".
    $anuladosDoc = []; // "TIPO|numero" => eliminado (bool)
    foreach ($bdd->query("SELECT tipo_documento, numero, eliminado FROM backorder_wo_docs WHERE anulado = 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $anuladosDoc[$row['tipo_documento'] . '|' . $row['numero']] = (bool)$row['eliminado'];
    }
    $docsAnulados = [];
    $docsPedidosNuevos = []; // id_pedido_nuevo => ["TIPO|numero" => ['documento', 'confirmado', 'anulado']]
    foreach ($opsPorPedido as $idP => $ops) {
        foreach ($ops as $k => $op) {
            $clave = $op['tipo_wo'] . '|' . $op['n_doc'];
            if (!empty($anuladosDoc[$clave])) { // eliminado de World Office
                unset($opsPorPedido[$idP][$k]);
                continue;
            }
            $opsPorPedido[$idP][$k]['anulado'] = isset($anuladosDoc[$clave]);
            // Confirmado = el documento existe en World Office (ya sincronizado) y no está anulado.
            $opsPorPedido[$idP][$k]['confirmado'] = isset($resueltos[$clave]) && !isset($anuladosDoc[$clave]);
            // Documentos de World Office de cada pedido NUEVO (trazabilidad en reporte_backorders_pedido.php).
            $dePedido = (int)($op['de_pedido'] ?? $idP);
            if ($dePedido !== (int)$idP) {
                $docsPedidosNuevos[$dePedido][$clave] = [
                    'documento' => $op['tipo_wo'] . ' ' . $op['n_doc'],
                    'confirmado' => $opsPorPedido[$idP][$k]['confirmado'],
                    'anulado' => isset($anuladosDoc[$clave]),
                ];
            }
            if (isset($anuladosDoc[$clave]) && !isset($preMigracionPorPedido[$idP])) {
                $docsAnulados[$clave]['tipo'] = $op['tipo_wo'];
                $docsAnulados[$clave]['numero'] = $op['n_doc'];
                $docsAnulados[$clave]['pedidos'][$idP] = $idP;
                if ($op['op_id']) $docsAnulados[$clave]['ops'][$op['op_id']] = $op['op_id'];
            }
        }
        $opsPorPedido[$idP] = array_values($opsPorPedido[$idP]);
    }
    $docsAnulados = array_values(array_map(fn($d) => [
        'tipo' => $d['tipo'], 'numero' => $d['numero'],
        'pedidos' => array_values($d['pedidos']), 'ops' => array_values($d['ops'] ?? []),
    ], $docsAnulados));

    // 4) Solicitada/despachada/pendiente por pedido+libro. Se descuenta la dispersión de todas las
    //    OPs atendidas de ese pedido, acumulando cantidad ya usada por libro para no volver a
    //    descontar el mismo renglón si dos pedidos comparten una OP agrupada.
    $usadoPorDoc = []; // "TIPO|numero" => [id_libro => cantidad_ya_asignada]
    $generadaAplicada = []; // "id_pedido|id_libro" => true
    // Lo confirmado en World Office para cada pedido NUEVO: id_pedido_nuevo => ['cantidad', 'docs', 'libros']
    $despachoPedidosNuevos = [];
    $lineasResultado = [];
    $preMigracion = []; // pedidos excluidos por 2b, para informar aparte
    $pedidosPreMigYaListados = [];

    foreach ($lineas as $l) {
        $idPedido = (int)$l['id_pedido'];
        // OJO: backorder_wo_renglones.id_libro NO es libros.id — es el id de inventario que World
        // Office trae en cada renglón (`inventario.id`), que vive en un espacio de ids totalmente
        // distinto al de la tabla `libros` del CRM (confirmado con un caso real 2026-09-11: una
        // remisión con id de renglón 3202 se estaba comparando contra libros_pedidos.id_libro=2367,
        // el libros.id real del mismo título "AULA MAXIMA INICIAL LIBRO" — nunca coincidían, así que
        // el despacho nunca se descontaba y el pedido mostraba backorder aunque ya estuviera
        // completo). El puente correcto es `libros.id_wo` (mismo id de inventario de World Office,
        // ver memory/project_wo_api.md) — por eso el paso 1 ya trae `l.id_wo AS libro_id_wo`.
        $idLibroWo = (int)($l['libro_id_wo'] ?? 0);
        $solicitada = (float)$l['cantidad'];

        if (isset($preMigracionPorPedido[$idPedido])) {
            if (!isset($pedidosPreMigYaListados[$idPedido])) {
                $pedidosPreMigYaListados[$idPedido] = true;
                $preMigracion[] = [
                    'id_pedido' => $idPedido,
                    'cliente' => $l['cliente_nombre'] ?: '',
                    'colegio' => $l['colegio'],
                    'fecha_entrega' => $preMigracionPorPedido[$idPedido],
                ];
            }
            continue;
        }

        $dispTotal = 0.0;
        $dispPedidosNuevos = 0.0;
        $ops = $opsPorPedido[$idPedido] ?? [];
        // Un libro sin `id_wo` mapeado (~26% del catálogo, ver memory/project_wo_api.md) no tiene
        // forma de cruzarse contra un renglón suelto de World Office — su clave (-libros.id, ver
        // backorders_clave_libro()) solo la llenan los paquetes que lo incluyen.
        $claveLibro = backorders_clave_libro($idLibroWo, $l['id_libro']);
        foreach ($ops as $op) {
            $clave = $op['tipo_wo'] . '|' . $op['n_doc'];
            // En modo "lista puntual de pedidos" ($idsPedidoLista, usado por los buscadores) cada
            // pedido se calcula AISLADO — sin repartir lo despachado de un documento compartido con
            // otro pedido del mismo lote — para que coincida exactamente con lo que después muestra
            // la ficha individual (obtener_backorder_pedido(), que siempre calcula un pedido solo,
            // nunca junto a otros). Sin esto, un pedido agrupado en la misma OP que otro podía
            // aparecer en el buscador como "con algo pendiente" (por quedar de segundo en el reparto
            // del lote) y, al abrirlo, la ficha mostraba todo ya despachado — reportado por el
            // usuario 2026-09-18. El reporte general (sin $idsPedidoLista) sigue repartiendo entre
            // pedidos hermanos como siempre, para no duplicar el despachado en sus totales.
            $claveUso = $idsPedidoLista !== null ? ($idPedido . '|' . $clave) : $clave;
            $disponibleEnDoc = $despachado[$clave][$claveLibro] ?? 0.0;
            $yaUsado = $usadoPorDoc[$claveUso][$claveLibro] ?? 0.0;
            $tomar = max(0.0, $disponibleEnDoc - $yaUsado);
            $dispTotal += $tomar;
            $usadoPorDoc[$claveUso][$claveLibro] = $yaUsado + $tomar;
            $dePedido = (int)($op['de_pedido'] ?? $idPedido);
            if ($dePedido !== $idPedido && $tomar > 0) {
                $dispPedidosNuevos += $tomar;
                $despachoPedidosNuevos[$dePedido]['cantidad'] = ($despachoPedidosNuevos[$dePedido]['cantidad'] ?? 0) + $tomar;
                $despachoPedidosNuevos[$dePedido]['libros'][(int)$l['id_libro']] = ($despachoPedidosNuevos[$dePedido]['libros'][(int)$l['id_libro']] ?? 0) + $tomar;
            }
        }


        // Responsable = dueño de zona del colegio (ver comentario arriba); si el colegio no tiene
        // uno activo, se cae a quien digitó el pedido, y si tampoco hay eso, "—".
        $responsableNombre = trim($l['responsable_nombre'] ?? '');
        $usuarioDigitoNombre = trim($l['usuario_nombre'] ?? '');
        if ($responsableNombre !== '') {
            $idResponsable = (int)$l['id_responsable'];
            $nombreResponsable = $responsableNombre;
        } elseif ($usuarioDigitoNombre !== '') {
            $idResponsable = (int)($l['id_usuario'] ?? 0);
            $nombreResponsable = $usuarioDigitoNombre;
        } else {
            $idResponsable = 0;
            $nombreResponsable = '—';
        }

        // Cantidades (pedido por la usuaria 2026-10-20, reemplaza la regla 2026-09-29 de descontar
        // del saldo lo generado en el CRM):
        //   despachada            todo lo que tiene REM/FV en World Office (del original o de sus
        //                         pedidos nuevos) — lo único que cuenta como despacho. Lo devuelto
        //                         se resta por colegio, no aquí (backorders_grupos_colegio()).
        //   generada              «En pedidos nuevos»: la parte de lo despachado que viene de
        //                         documentos de los pedidos NUEVOS (cruzados por OP + PED nuevo).
        //   generada_crm          lo que el CRM metió en pedidos nuevos (no anulados).
        //   pendiente_sincronizacion  lo de generada_crm que aún no tiene documento en World Office.
        //   pendiente             pendiente de DESPACHO = solicitado − despachado (lo creado en el
        //                         CRM sin documento NO lo rebaja: crear un pedido no es entregarlo).
        //   pendiente_gestion     lo pendiente que todavía no está en ningún pedido nuevo — es el
        //                         máximo que se puede generar en un pedido nuevo.
        //   excedente             despachado por encima de lo solicitado (para revisión).
        // La cantidad del CRM se aplica una sola vez por pedido+libro aunque el libro se repita.
        $generadaCrm = 0.0;
        $claveGen = $idPedido . '|' . (int)$l['id_libro'];
        if (!isset($generadaAplicada[$claveGen])) {
            $generadaAplicada[$claveGen] = true;
            $generadaCrm = (float)($familias['generada'][$idPedido][(int)$l['id_libro']] ?? 0);
        }
        $pendienteDespacho = max(0.0, $solicitada - $dispTotal);
        $pendienteSincronizacion = max(0.0, $generadaCrm - $dispPedidosNuevos);

        $lineasResultado[] = [
            'id_pedido' => $idPedido,
            'id_periodo' => (int)$l['id_periodo'],
            'id_usuario' => $idResponsable,
            'usuario_nombre' => $nombreResponsable,
            'colegio' => $l['colegio'],
            'id_colegio' => (int)$l['id_colegio'],
            'cliente' => $l['cliente_nombre'] ?: '',
            'id_cliente' => (int)($l['id_cliente'] ?? 0),
            'libro' => $l['libro'],
            'isbn' => $l['isbn'] ?? '',
            'id_libro' => (int)$l['id_libro'],
            'libro_id_wo' => $idLibroWo > 0 ? $idLibroWo : null,
            // Se antepone el tipo de documento (REM = remisión, FV = factura de venta) a cada
            // número: mismo código corto ya usado en el panel "Documentos sin verificar".
            'ops' => $ops,
            'op_ids' => $opsAsociadasPorPedido[$idPedido] ?? [],
            'solicitada' => $solicitada,
            'despachada' => round($dispTotal, 2),
            // Remisionado / facturado: lo de las REM/FV relacionadas (por pedido es lo mismo que
            // despachada; las devoluciones se restan por colegio).
            'remisionada' => round($dispTotal, 2),
            'generada' => round($dispPedidosNuevos, 2),
            'generada_crm' => round($generadaCrm, 2),
            'pendiente_sincronizacion' => round($pendienteSincronizacion, 2),
            'pendiente' => round($pendienteDespacho, 2),
            'pendiente_gestion' => round(max(0.0, $pendienteDespacho - $pendienteSincronizacion), 2),
            'excedente' => round(max(0.0, $dispTotal - $solicitada), 2),
            // Pedidos nuevos (no anulados) generados para atender el backorder de este pedido.
            'pedidos_generados' => array_values($familias['hijosActivos'][$idPedido] ?? []),
        ];
    }

    // Unidades de cada paquete en los documentos de estos pedidos (cada documento una sola vez).
    $despachoPaquetes = []; // id_paquete => cantidad
    $docsPaquete = [];
    foreach ($opsPorPedido as $idP => $ops) {
        if (isset($preMigracionPorPedido[$idP])) continue;
        foreach ($ops as $op) {
            $clave = $op['tipo_wo'] . '|' . $op['n_doc'];
            if (isset($docsPaquete[$clave])) continue;
            $docsPaquete[$clave] = true;
            foreach ($despachoPaquetesDoc[$clave] ?? [] as $idPaquete => $cantidad) {
                $despachoPaquetes[$idPaquete] = ($despachoPaquetes[$idPaquete] ?? 0) + $cantidad;
            }
        }
    }

    return [
        'lineas' => $lineasResultado,
        'despachoPaquetes' => $despachoPaquetes,
        'sinSincronizar' => array_values($sinSincronizar),
        'docsAnulados' => $docsAnulados,
        'despachoPedidosNuevos' => $despachoPedidosNuevos,
        'docsPedidosNuevos' => $docsPedidosNuevos,
        'preMigracion' => $preMigracion,
        'opsAsociadasPorPedido' => $opsAsociadasPorPedido,
    ];
}

/**
 * Solo las líneas de pedidos con algún documento (REM/FV) confirmado en World Office — en todo el
 * reporte de backorders (detalle de backorders, detalle del colegio y su buscador, cumplimiento por
 * cliente, backorders por colegio y backorder general de libros) un pedido sin documento asociado
 * no aparece ni suma (pedido por la usuaria 2026-10-20).
 */
function backorders_lineas_con_documento(array $lineas) {
    $conDoc = [];
    foreach ($lineas as $l) {
        foreach ($l['ops'] as $op) if (!empty($op['confirmado'])) { $conDoc[$l['id_pedido']] = true; break; }
    }
    return array_values(array_filter($lineas, fn($l) => isset($conDoc[$l['id_pedido']])));
}

/**
 * Agrupa calcular_lineas_backorder() por COLEGIO + período — una columna por colegio con backorder
 * y documento confirmado (desde 2026-10-20; antes una por pedido), ver php/backorders_excel.php.
 * Descarta lo ya 100% despachado: este reporte es "qué falta por surtir", no el cuadro completo.
 */
function obtener_datos_backorders($bdd, $idPeriodo = null, $idUsuario = null, $idCliente = null) {
    $calc = calcular_lineas_backorder($bdd, $idPeriodo, $idUsuario, null, $idCliente);
    if (empty($calc['lineas'])) {
        return ['columnas' => [], 'filas' => [], 'sinSincronizar' => $calc['sinSincronizar'], 'docsAnulados' => $calc['docsAnulados'], 'preMigracion' => $calc['preMigracion']];
    }
    // Un pedido de este listado puede haber pasado a tener documento confirmado sin pasar por la
    // sincronización (p. ej. se escribió en la OP el número de una REM ya sincronizada): se le
    // asigna su consecutivo aquí mismo — nunca cambia uno ya asignado.
    backorders_asignar_consecutivos($bdd, $calc['lineas']);
    $consecutivos = backorders_obtener_consecutivos($bdd, array_column($calc['lineas'], 'id_pedido'));

    // Una columna por COLEGIO + período (pedido por la usuaria 2026-10-20): sus pedidos sumados por
    // libro, con las devoluciones de remisión y notas crédito restadas al colegio, y SOLO colegios
    // con algún documento (REM/FV) confirmado en World Office.
    $lineasDoc = backorders_lineas_con_documento($calc['lineas']);
    $meta = []; // clave => datos del colegio sacados de las líneas por pedido
    foreach ($lineasDoc as $l) {
        $clave = 'c' . $l['id_colegio'] . '_' . $l['id_periodo'];
        if (!isset($meta[$clave])) {
            $meta[$clave] = ['docs' => [], 'ops' => [], 'clientes' => [], 'pedidos' => [], 'generados' => [], 'confirmado' => false];
        }
        $m = &$meta[$clave];
        $m['pedidos'][$l['id_pedido']] = (int)$l['id_pedido'];
        foreach ($l['ops'] as $op) {
            $m['docs'][etiqueta_doc_backorder($op)] = true;
            if (!empty($op['confirmado'])) $m['confirmado'] = true;
        }
        foreach ($l['op_ids'] as $id) $m['ops'][$id] = $id;
        foreach ($l['pedidos_generados'] as $id) $m['generados'][$id] = $id;
        if ($l['cliente'] !== '') $m['clientes'][$l['cliente']] = true;
        unset($m);
    }

    $columnas = []; // clave => metadata
    $celdas = [];   // clave => [libro => ['pendiente'=>, 'solicitada'=>]]
    $titulos = [];  // libro => true (para el orden de filas)
    $tituloIdWo = []; // libro => id_wo (para consultar existencias en bulk al final)
    $tituloIsbn = []; // libro => ISBN

    foreach (backorders_grupos_colegio($bdd, $lineasDoc) as $g) {
        $clave = 'c' . $g['id_colegio'] . '_' . $g['id_periodo'];
        if ($g['pendiente'] <= 0 || empty($meta[$clave]['confirmado'])) continue;
        if (!isset($tituloIdWo[$g['libro']]) && $g['libro_id_wo']) $tituloIdWo[$g['libro']] = $g['libro_id_wo'];
        if (empty($tituloIsbn[$g['libro']]) && $g['isbn'] !== '') $tituloIsbn[$g['libro']] = $g['isbn'];

        if (!isset($columnas[$clave])) {
            $m = $meta[$clave];
            $pedidos = array_values($m['pedidos']);
            sort($pedidos);
            $opIds = array_values($m['ops']);
            sort($opIds);
            $consec = array_values(array_filter(array_map(fn($id) => $consecutivos[$id] ?? '', $pedidos)));
            $columnas[$clave] = [
                'clave' => $clave,
                'id_colegio' => $g['id_colegio'],
                'id_periodo' => $g['id_periodo'],
                'consecutivo' => implode(', ', $consec),
                'documento' => implode(', ', array_keys($m['docs'])),
                'cliente' => implode(', ', array_keys($m['clientes'])),
                'colegio' => $g['colegio'],
                'pedidos' => $pedidos,
                'pedidos_txt' => implode(', ', array_map(fn($id) => '#' . $id, $pedidos)),
                'op' => implode(', ', $opIds),
                'op_ids' => $opIds,
                'pedidos_generados' => array_values($m['generados']),
                'docs_devolucion' => [],
                'total' => 0,
                'total_solicitada' => 0,
                'total_despachada' => 0,
            ];
        }
        foreach ($g['docs_devolucion'] as $d) {
            if (!in_array($d, $columnas[$clave]['docs_devolucion'], true)) $columnas[$clave]['docs_devolucion'][] = $d;
        }
        $celdas[$clave][$g['libro']] = [
            'pendiente'  => ($celdas[$clave][$g['libro']]['pendiente'] ?? 0) + $g['pendiente'],
            'solicitada' => ($celdas[$clave][$g['libro']]['solicitada'] ?? 0) + $g['solicitada'],
        ];
        $columnas[$clave]['total'] += $g['pendiente'];
        $columnas[$clave]['total_solicitada'] += $g['solicitada'];
        $columnas[$clave]['total_despachada'] += min(max(0, $g['despachada']), $g['solicitada']);
        $titulos[$g['libro']] = true;
    }
    // Documento: solo las REM/FV de los pedidos. Las devoluciones / notas crédito del colegio ya no
    // se listan aquí (pedido por la usuaria 2026-11-06), aunque se siguen restando del pendiente;
    // quedan en $col['docs_devolucion'] por si se necesitan.
    foreach ($columnas as &$col) {
        $col['documento'] = implode(', ', array_values(array_unique(array_filter(explode(', ', $col['documento'])))));
    }
    unset($col);
    uasort($columnas, fn($a, $b) => strcasecmp($a['colegio'], $b['colegio']));

    $nombresTitulos = array_keys($titulos);
    sort($nombresTitulos, SORT_STRING);

    // Existencia real en bodega General de World Office por título — en UN solo bulk (paralelo)
    // para todos los títulos con backorder, no uno por uno (ver includes/api_wo_inventario.php).
    // Mismo dato/umbral (UMBRAL_STOCK_BAJO=50) que reporte_stock.php.
    $existenciasPorIdWo = existencias_bodega_general_bulk(array_values($tituloIdWo));

    $filas = [];
    foreach ($nombresTitulos as $titulo) {
        $fila = ['titulo' => $titulo, 'isbn' => $tituloIsbn[$titulo] ?? '', 'valores' => [], 'total' => 0, 'total_solicitada' => 0];
        foreach ($columnas as $clave => $col) {
            $v = $celdas[$clave][$titulo] ?? null;
            if ($v && $v['pendiente'] > 0) {
                $fila['valores'][$clave] = $v;
                $fila['total'] += $v['pendiente'];
                $fila['total_solicitada'] += $v['solicitada'];
            }
        }
        $idWo = $tituloIdWo[$titulo] ?? null;
        $existencia = $idWo ? ($existenciasPorIdWo[$idWo] ?? null) : null;
        $fila['existencia'] = $existencia;
        $fila['stock_bajo'] = $existencia !== null ? ($existencia < UMBRAL_STOCK_BAJO) : null;
        $filas[] = $fila;
    }

    return [
        'columnas' => array_values($columnas),
        'filas' => $filas,
        'sinSincronizar' => $calc['sinSincronizar'],
        'docsAnulados' => $calc['docsAnulados'],
        'preMigracion' => $calc['preMigracion'],
    ];
}

/**
 * Agrupa calcular_lineas_backorder() por TÍTULO y, dentro de cada uno, por usuario que lo pidió
 * — para ver de un vistazo cuánto pidió cada promotor/distribuidor de un mismo libro (ej. "Manuel
 * Correa pidió 600, Jairo pidió 500") y qué tan cumplido está en total, sin tener que revisar
 * pedido por pedido. El % de cumplimiento (despachado en World Office ÷ solicitado, ver
 * porcentaje_cumplimiento_backorder()) es la referencia para decidir
 * a qué título darle prioridad cuando llegue mercancía nueva: entre menos cumplido, más urgente.
 * Solo se listan títulos con algo todavía pendiente (sumando todos sus pedidos) — uno ya 100%
 * despachado no necesita priorizarse. Las cantidades (del título y de cada usuario) son solo de
 * los colegios que aún tienen pendiente ese libro.
 */
function obtener_datos_backorders_por_titulo($bdd, $idPeriodo = null, $idUsuario = null) {
    $calc = calcular_lineas_backorder($bdd, $idPeriodo, $idUsuario);
    // Solo pedidos con documento (REM/FV) confirmado: uno sin documento no aparece ni suma.
    $calc['lineas'] = backorders_lineas_con_documento($calc['lineas']);

    // Se suma por colegio + libro (con lo devuelto ya restado al colegio, ver
    // backorders_grupos_colegio()) y luego por título y responsable.
    $titulos = []; // libro => ['titulo'=>, 'solicitada'=>, 'despachada'=>, 'pendiente'=>, 'usuarios'=>[id_usuario=>{...}]]
    foreach (backorders_grupos_colegio($bdd, $calc['lineas']) as $l) {
        // Solo lo que es backorder (pedido por la usuaria 2026-10-29): un colegio + libro ya
        // despachado completo no se lista ni suma en el título ni en el usuario. Así tampoco el
        // excedente de un colegio compensa lo que le falta a otro.
        if ($l['pendiente'] <= 0) continue;
        $titulo = $l['libro'];
        if (!isset($titulos[$titulo])) {
            $titulos[$titulo] = ['titulo' => $titulo, 'isbn' => '', 'id_wo' => null, 'solicitada' => 0.0, 'despachada' => 0.0,
                                 'remisionada' => 0.0, 'devuelta' => 0.0, 'pendiente' => 0.0, 'usuarios' => []];
        }
        if (!$titulos[$titulo]['id_wo'] && $l['libro_id_wo']) $titulos[$titulo]['id_wo'] = $l['libro_id_wo'];
        if ($titulos[$titulo]['isbn'] === '' && $l['isbn'] !== '') $titulos[$titulo]['isbn'] = $l['isbn'];
        $idUsr = $l['id_usuario'];
        if (!isset($titulos[$titulo]['usuarios'][$idUsr])) {
            $titulos[$titulo]['usuarios'][$idUsr] = [
                'id_usuario' => $idUsr, 'usuario' => $l['usuario_nombre'],
                'solicitada' => 0.0, 'despachada' => 0.0, 'remisionada' => 0.0, 'devuelta' => 0.0, 'pendiente' => 0.0,
            ];
        }
        foreach (['solicitada', 'despachada', 'remisionada', 'devuelta', 'pendiente'] as $k) {
            $titulos[$titulo]['usuarios'][$idUsr][$k] += $l[$k];
            $titulos[$titulo][$k] += $l[$k];
        }
    }

    // Existencia en bodega General de World Office, en un solo bulk para los títulos con pendiente
    // — mismo dato/umbral que la "Relación de pendientes" (obtener_datos_backorders()).
    $idsWo = [];
    foreach ($titulos as $t) {
        if (round($t['pendiente'], 2) > 0 && $t['id_wo']) $idsWo[$t['id_wo']] = $t['id_wo'];
    }
    $existenciasPorIdWo = $idsWo ? existencias_bodega_general_bulk(array_values($idsWo)) : [];

    $resultado = [];
    foreach ($titulos as $t) {
        if (round($t['pendiente'], 2) <= 0) continue; // ya 100% surtido, no hay nada que priorizar
        $existencia = $t['id_wo'] ? ($existenciasPorIdWo[$t['id_wo']] ?? null) : null;

        $usuarios = array_values($t['usuarios']);
        foreach ($usuarios as &$u) {
            $u['solicitada'] = round($u['solicitada'], 2);
            $u['despachada'] = round($u['despachada'], 2);
            $u['remisionada'] = round($u['remisionada'], 2);
            $u['devuelta'] = round($u['devuelta'], 2);
            $u['pendiente'] = round($u['pendiente'], 2);
            $u['cumplimiento'] = porcentaje_cumplimiento_backorder($u['solicitada'], $u['despachada']);
        }
        unset($u);
        // Dentro de un mismo título, primero quien tiene más unidades pendientes.
        usort($usuarios, fn($a, $b) => $b['pendiente'] <=> $a['pendiente']);

        $resultado[] = [
            'titulo' => $t['titulo'],
            'isbn' => $t['isbn'],
            'solicitada' => round($t['solicitada'], 2),
            'despachada' => round($t['despachada'], 2),
            // Remisionado / facturado (REM/FV) y devuelto (DREM/NC): despachada = remisionada − devuelta.
            'remisionada' => round($t['remisionada'], 2),
            'devuelta' => round($t['devuelta'], 2),
            'pendiente' => round($t['pendiente'], 2),
            'cumplimiento' => porcentaje_cumplimiento_backorder($t['solicitada'], $t['despachada']),
            'existencia' => $existencia,
            'stock_bajo' => $existencia !== null ? ($existencia < UMBRAL_STOCK_BAJO) : null,
            'usuarios' => $usuarios,
        ];
    }

    // Prioridad: menor % de cumplimiento primero (lo que menos se ha surtido es lo más urgente al
    // llegar mercancía); a igual %, el que tenga más unidades pendientes en total.
    usort($resultado, function ($a, $b) {
        if ($a['cumplimiento'] != $b['cumplimiento']) return $a['cumplimiento'] <=> $b['cumplimiento'];
        return $b['pendiente'] <=> $a['pendiente'];
    });

    return ['titulos' => $resultado, 'sinSincronizar' => $calc['sinSincronizar'], 'preMigracion' => $calc['preMigracion']];
}

/**
 * Consolidado de cumplimiento de despacho POR CLIENTE (sección "Cumplimiento por cliente" de
 * reporte_backorders.php). Sale de las mismas líneas de calcular_lineas_backorder(), así que ya:
 *   - excluye pedidos Anulados y los pre-migración a World Office,
 *   - no lista los pedidos nuevos generados desde un backorder: su despacho se suma al original
 *     (no se cuentan dos veces ni las solicitadas ni las despachadas),
 *   - cuenta como despachado SOLO lo que trae una REM/FV real de World Office.
 * Aquí, a diferencia de la "Relación de pendientes", lo generado en pedidos nuevos NO descuenta el
 * pendiente: pendiente = solicitado − despachado (lo generado aún no se ha entregado).
 *
 * Se suma por cliente + libro antes de comparar: lo despachado de más en un libro (excedente) no
 * compensa lo que falta de otro. Despachado válido = min(despachado, solicitado); la diferencia
 * se reporta como excedente para revisión. % = despachado válido ÷ solicitado (consolidado, no
 * promedio de pedidos); null si el cliente no tiene unidades solicitadas.
 *
 * Cliente = cliente World Office del pedido (pedidos.cliente); si el pedido no tiene, el colegio.
 */
function obtener_cumplimiento_por_cliente($bdd, $idPeriodo = null) {
    $calc = calcular_lineas_backorder($bdd, $idPeriodo);
    // Solo pedidos con documento (REM/FV) confirmado: uno sin documento no aparece ni suma.
    $calc['lineas'] = backorders_lineas_con_documento($calc['lineas']);

    $clientes = []; // clave => datos del cliente
    $claveCliente = fn($l) => $l['id_cliente'] > 0 ? 'cl' . $l['id_cliente'] : 'co' . $l['id_colegio'];
    $nuevoCliente = fn($l) => [
        'cliente' => $l['id_cliente'] > 0 && $l['cliente'] !== '' ? $l['cliente'] : $l['colegio'],
        'sin_cliente_wo' => $l['id_cliente'] <= 0,
        'pedidos' => [], 'libros' => [],
    ];
    // Cantidades por colegio + libro, con lo devuelto ya restado al colegio (backorders_grupos_colegio()).
    // Solo colegios con algo pendiente, igual que "Backorders por colegio" (pedido por la usuaria
    // 2026-10-22): un colegio del cliente ya despachado completo no suma solicitadas ni remisionadas.
    $grupos = backorders_grupos_colegio($bdd, $calc['lineas']);
    $colegioPendiente = [];
    foreach ($grupos as $l) {
        if ($l['pendiente'] > 0) $colegioPendiente[$l['id_colegio'] . '|' . $l['id_periodo']] = true;
    }
    foreach ($grupos as $l) {
        if (!isset($colegioPendiente[$l['id_colegio'] . '|' . $l['id_periodo']])) continue;
        $clave = $claveCliente($l);
        if (!isset($clientes[$clave])) $clientes[$clave] = $nuevoCliente($l);
        $c = &$clientes[$clave];
        // Pedidos pendientes: los del colegio en un libro que el colegio aún tiene pendiente (lo
        // devuelto se resta al colegio, no a un pedido).
        if ($l['pendiente'] > 0) foreach ($l['pedidos'] as $idP) $c['pedidos'][$idP] = true;
        $c['libros'][$l['id_libro']]['solicitada'] = ($c['libros'][$l['id_libro']]['solicitada'] ?? 0) + $l['solicitada'];
        $c['libros'][$l['id_libro']]['despachada'] = ($c['libros'][$l['id_libro']]['despachada'] ?? 0) + $l['despachada'];
        $c['remisionada'] = ($c['remisionada'] ?? 0) + $l['remisionada'];
        $c['devuelta'] = ($c['devuelta'] ?? 0) + $l['devuelta'];
        unset($c);
    }

    $resultado = [];
    foreach ($clientes as $c) {
        $solicitada = $despachada = $excedente = 0.0;
        foreach ($c['libros'] as $lib) {
            $solicitada += $lib['solicitada'];
            $despachada += min($lib['despachada'], $lib['solicitada']);
            $excedente += max(0, $lib['despachada'] - $lib['solicitada']);
        }
        $resultado[] = [
            'cliente' => $c['cliente'],
            'sin_cliente_wo' => $c['sin_cliente_wo'],
            'pedidos_con_pendiente' => count($c['pedidos']),
            'solicitada' => round($solicitada, 2),
            'despachada' => round($despachada, 2),
            // Remisionado / facturado (REM/FV) y devuelto (DREM/NC), tal cual, sin topar.
            'remisionada' => round($c['remisionada'] ?? 0, 2),
            'devuelta' => round($c['devuelta'] ?? 0, 2),
            'pendiente' => round(max(0, $solicitada - $despachada), 2),
            'excedente' => round($excedente, 2),
            'cumplimiento' => $solicitada > 0 ? round($despachada / $solicitada * 100, 1) : null,
        ];
    }
    usort($resultado, fn($a, $b) => strcasecmp($a['cliente'], $b['cliente']));

    return ['clientes' => $resultado, 'sinSincronizar' => $calc['sinSincronizar'], 'preMigracion' => $calc['preMigracion']];
}

/**
 * Backorders POR COLEGIO (sección "Backorders por colegio" de reporte_backorders.php) — pedido por
 * la usuaria 2026-10-20: los pedidos del colegio se agrupan con sus cantidades y cada devolución de
 * remisión o nota crédito se resta del total del colegio (backorders_grupos_colegio()), sin
 * repartirla entre pedidos. Solo colegios y libros con algo pendiente.
 */
function obtener_backorders_por_colegio($bdd, $idPeriodo = null) {
    $calc = calcular_lineas_backorder($bdd, $idPeriodo);
    // Solo pedidos con documento (REM/FV) confirmado: uno sin documento no aparece ni suma.
    $calc['lineas'] = backorders_lineas_con_documento($calc['lineas']);
    $colegios = [];
    foreach (backorders_grupos_colegio($bdd, $calc['lineas']) as $g) {
        $k = $g['id_colegio'] . '|' . $g['id_periodo'];
        if (!isset($colegios[$k])) {
            $colegios[$k] = [
                'id_colegio' => $g['id_colegio'], 'id_periodo' => $g['id_periodo'], 'colegio' => $g['colegio'],
                'cliente' => $g['cliente'], 'responsable' => $g['usuario_nombre'],
                'pedidos' => [], 'docs_devolucion' => [], 'libros' => [],
                'solicitada' => 0.0, 'remisionada' => 0.0, 'devuelta' => 0.0, 'pendiente' => 0.0, 'despachada_valida' => 0.0,
            ];
        }
        $c = &$colegios[$k];
        foreach ($g['pedidos'] as $idP) $c['pedidos'][$idP] = $idP;
        foreach ($g['docs_devolucion'] as $d) $c['docs_devolucion'][$d] = $d;
        foreach (['solicitada', 'remisionada', 'devuelta', 'pendiente'] as $campo) $c[$campo] += $g[$campo];
        // Lo despachado de más en un libro no compensa lo que falta de otro.
        $c['despachada_valida'] += min(max(0.0, $g['despachada']), $g['solicitada']);
        if ($g['pendiente'] > 0) {
            $c['libros'][] = array_intersect_key($g, array_flip(['libro', 'isbn', 'solicitada', 'remisionada', 'devuelta', 'pendiente', 'cumplimiento']));
        }
        unset($c);
    }

    $resultado = [];
    foreach ($colegios as $c) {
        if (round($c['pendiente'], 2) <= 0) continue;
        $pedidos = array_values($c['pedidos']);
        sort($pedidos);
        usort($c['libros'], fn($a, $b) => $b['pendiente'] <=> $a['pendiente'] ?: strcmp($a['libro'], $b['libro']));
        $resultado[] = [
            'id_colegio' => $c['id_colegio'], 'id_periodo' => $c['id_periodo'], 'colegio' => $c['colegio'], 'cliente' => $c['cliente'], 'responsable' => $c['responsable'],
            'pedidos' => $pedidos,
            'docs_devolucion' => array_values($c['docs_devolucion']),
            'solicitada' => round($c['solicitada'], 2),
            'remisionada' => round($c['remisionada'], 2),
            'devuelta' => round($c['devuelta'], 2),
            'pendiente' => round($c['pendiente'], 2),
            'cumplimiento' => $c['solicitada'] > 0 ? round($c['despachada_valida'] / $c['solicitada'] * 100, 1) : null,
            'libros' => $c['libros'],
        ];
    }
    usort($resultado, fn($a, $b) => strcasecmp($a['colegio'], $b['colegio']));
    return ['colegios' => $resultado, 'sinSincronizar' => $calc['sinSincronizar'], 'preMigracion' => $calc['preMigracion']];
}

/**
 * Aplica a obtener_cumplimiento_por_cliente() los mismos filtros/orden que la vista web
 * (renderClientes() en reporte_backorders.php), para que el Excel salga igual a lo que se ve.
 * Solo clientes con unidades pendientes: uno ya 100% despachado no tiene backorder.
 * $orden: 'pct_asc' (default) | 'pct_desc' | 'pendiente_desc' | 'cliente'.
 */
function filtrar_cumplimiento_por_cliente(array $clientes, $buscar = '', $orden = 'pct_asc') {
    $buscar = mb_strtolower(trim($buscar), 'UTF-8');
    $clientes = array_values(array_filter($clientes, function ($c) use ($buscar) {
        if (!($c['pendiente'] > 0)) return false;
        return $buscar === '' || mb_strpos(mb_strtolower($c['cliente'], 'UTF-8'), $buscar) !== false;
    }));
    // N/A siempre al final, sin importar el sentido del orden por %.
    usort($clientes, function ($a, $b) use ($orden) {
        if ($orden === 'pct_desc') return (($b['cumplimiento'] ?? -1) <=> ($a['cumplimiento'] ?? -1)) ?: ($b['pendiente'] <=> $a['pendiente']);
        if ($orden === 'pendiente_desc') return ($b['pendiente'] <=> $a['pendiente']) ?: strcasecmp($a['cliente'], $b['cliente']);
        if ($orden === 'cliente') return strcasecmp($a['cliente'], $b['cliente']);
        return (($a['cumplimiento'] ?? 1000) <=> ($b['cumplimiento'] ?? 1000)) ?: ($b['pendiente'] <=> $a['pendiente']);
    });
    return $clientes;
}

/**
 * Detalle de backorder de UN pedido específico (todos sus libros con solicitado/despachado/
 * pendiente/% cumplimiento), para el módulo de búsqueda "por pedido individual" —
 * reporte_backorders_pedido.php. Devuelve null si el pedido no existe.
 */
function obtener_backorder_pedido($bdd, $idPedido) {
    $idPedido = (int)$idPedido;
    if ($idPedido <= 0) return null;

    // Mismo criterio "Promotor/Empresa" (dueño de zona del colegio) que pedido_colegio.php y el
    // resto de este archivo, con respaldo a quien digitó el pedido — ver comentario en
    // calcular_lineas_backorder().
    $stmt = $bdd->prepare(
        "SELECT p.id, p.codigo, p.fecha, p.estado, ep.estado AS estado_nombre,
                p.id_colegio, p.id_periodo, p.id_usuario AS id_usuario_digito, p.cliente AS id_cliente,
                p.tipo, p.fac_rem, p.dir_ent, p.observaciones, p.fecha_r,
                c.colegio, cl.cliente AS cliente_nombre,
                CONCAT(TRIM(owner.nombres), ' ', TRIM(owner.apellidos)) AS responsable_nombre,
                CONCAT(TRIM(u2.nombres), ' ', TRIM(u2.apellidos)) AS usuario_nombre
         FROM pedidos p
         JOIN colegios c ON c.id = p.id_colegio
         LEFT JOIN clientes cl ON cl.id = p.cliente
         LEFT JOIN estados_pedidos ep ON ep.id = p.estado
         LEFT JOIN usuarios u2 ON u2.id = p.id_usuario
         LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> ''
              AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)
         WHERE p.id = ?"
    );
    $stmt->execute([$idPedido]);
    $ped = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ped) return null;

    $responsableZona = trim($ped['responsable_nombre'] ?? '');
    $usuarioDigito = trim($ped['usuario_nombre'] ?? '');
    $responsable = $responsableZona !== '' ? $responsableZona : ($usuarioDigito !== '' ? $usuarioDigito : '—');

    // Un pedido generado para atender el backorder de otro no tiene backorder propio: todo se
    // consulta desde su pedido original (ver backorders_asegurar_tablas()).
    backorders_asegurar_tablas($bdd);
    $stmtOrigen = $bdd->prepare("SELECT id_pedido_origen FROM backorder_pedidos_generados WHERE id_pedido_nuevo = ? LIMIT 1");
    $stmtOrigen->execute([$idPedido]);
    $idPedidoOrigenDeEste = $stmtOrigen->fetchColumn();

    $calc = calcular_lineas_backorder($bdd, null, null, $idPedido);

    $esPreMigracion = false;
    foreach ($calc['preMigracion'] as $pm) {
        if ((int)$pm['id_pedido'] === $idPedido) { $esPreMigracion = true; break; }
    }

    // Formato del pedido original por libro (para "Atender backorder"): precio, descuento del
    // presupuesto del colegio y descuento aprobado de la línea — mismo cálculo que
    // pedido_colegio.php (descuento_d si está definido, si no descuento).
    $stmtFormato = $bdd->prepare(
        "SELECT lp.id_libro, l.isbn, l.precio, lp.descuento_aprob, pr.descuento, pr.descuento_d
         FROM libros_pedidos lp
         JOIN libros l ON l.id = lp.id_libro
         LEFT JOIN presupuestos pr ON pr.id_colegio = ? AND pr.id_libro = lp.id_libro
              AND COALESCE(lp.cod_area, '') = COALESCE(pr.cod_area, '')
              AND pr.id_periodo = ? AND pr.definido = 1
         WHERE lp.cod_pedido = ?
         ORDER BY lp.id"
    );
    $stmtFormato->execute([$ped['id_colegio'], $ped['id_periodo'], $ped['codigo']]);
    $formatoPorLibro = [];
    foreach ($stmtFormato->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $idLib = (int)$f['id_libro'];
        if (isset($formatoPorLibro[$idLib])) continue;
        $desc = ($f['descuento_d'] === null || $f['descuento_d'] === '0.0000') ? (float)$f['descuento'] : (float)$f['descuento_d'];
        $formatoPorLibro[$idLib] = [
            'isbn' => $f['isbn'],
            'precio' => (float)$f['precio'],
            'descuento_pct' => round($desc * 100, 2),
            'descuento_aprob' => $f['descuento_aprob'] !== null && $f['descuento_aprob'] !== '' ? (float)$f['descuento_aprob'] : null,
        ];
    }

    // Cantidades del COLEGIO (todos sus pedidos del período, con las devoluciones de remisión y
    // notas crédito restadas al colegio) — pedido por la usuaria 2026-10-20: el detalle ya no es por
    // pedido sino por colegio. Un pedido nuevo (generado desde otro) se sigue mostrando desde su
    // original; uno pre-migración no tiene traza.
    $lineasColegio = [];
    $gruposColegio = [];
    if (!$idPedidoOrigenDeEste && !$esPreMigracion) {
        $lineasColegio = backorders_lineas_con_documento(
            calcular_lineas_backorder($bdd, (int)$ped['id_periodo'], null, null, null, null, (int)$ped['id_colegio'])['lineas']);
        $gruposColegio = backorders_grupos_colegio($bdd, $lineasColegio);
    }
    $porLibroCol = []; // id_libro => sumas de las líneas del colegio
    foreach ($lineasColegio as $l) {
        foreach (['generada', 'generada_crm', 'pendiente_sincronizacion'] as $k) {
            $porLibroCol[$l['id_libro']][$k] = ($porLibroCol[$l['id_libro']][$k] ?? 0) + $l[$k];
        }
    }
    $librosDelPedido = array_fill_keys(array_column($calc['lineas'], 'id_libro'), true);

    $items = [];
    $totalSolicitada = 0.0;
    $totalDespachada = 0.0;
    $totalGenerada = 0.0;
    $totalPendiente = 0.0;
    $totales = ['generada_crm' => 0.0, 'pendiente_sincronizacion' => 0.0, 'pendiente_gestion' => 0.0, 'excedente' => 0.0, 'remisionada' => 0.0, 'devuelta' => 0.0];
    $pedidosColegio = [];
    $docsDevolucion = [];
    foreach ($gruposColegio as $g) {
        $col = $porLibroCol[$g['id_libro']] ?? [];
        $pendSync = (float)($col['pendiente_sincronizacion'] ?? 0);
        $formato = $formatoPorLibro[$g['id_libro']] ?? ['isbn' => $g['isbn'], 'precio' => 0.0, 'descuento_pct' => 0.0, 'descuento_aprob' => null];
        $item = [
            'id_libro' => $g['id_libro'],
            'libro' => $g['libro'],
            'isbn' => $formato['isbn'],
            'precio' => $formato['precio'],
            'descuento_pct' => $formato['descuento_pct'],
            'descuento_aprob' => $formato['descuento_aprob'],
            // Solo los libros de ESTE pedido se pueden pedir en "Atender backorder" (el pedido nuevo
            // copia sus líneas); el tope es lo pendiente del colegio para ese libro.
            'en_pedido' => isset($librosDelPedido[$g['id_libro']]),
            'solicitada' => $g['solicitada'],
            'remisionada' => $g['remisionada'],
            'devuelta' => $g['devuelta'],
            'despachada' => $g['despachada'],
            'generada' => round(min((float)($col['generada'] ?? 0), max(0, $g['despachada'])), 2),
            'generada_crm' => round((float)($col['generada_crm'] ?? 0), 2),
            'pendiente_sincronizacion' => round($pendSync, 2),
            'pendiente' => $g['pendiente'],
            'pendiente_gestion' => round(max(0.0, $g['pendiente'] - $pendSync), 2),
            'excedente' => $g['excedente'],
            'cumplimiento' => $g['cumplimiento'],
        ];
        $items[] = $item;
        foreach ($totales as $k => $v) $totales[$k] += $item[$k];
        $totalSolicitada += $item['solicitada'];
        $totalDespachada += min(max(0, $item['despachada']), $item['solicitada']);
        $totalGenerada += $item['generada'];
        $totalPendiente += $item['pendiente'];
        foreach ($g['pedidos'] as $idP) $pedidosColegio[$idP] = $idP;
        foreach ($g['docs_devolucion'] as $d) $docsDevolucion[$d] = $d;
    }
    sort($pedidosColegio);

    $ops = [];
    $opIds = [];
    foreach ($calc['lineas'] as $l) {
        if (empty($ops)) { $ops = $l['ops']; $opIds = $l['op_ids']; }
        if ($gruposColegio) continue;
        // Sin cálculo por colegio (pedido nuevo o pre-migración): las líneas del propio pedido.
        $cumplLinea = porcentaje_cumplimiento_backorder($l['solicitada'], $l['despachada']);
        $formato = $formatoPorLibro[$l['id_libro']] ?? ['isbn' => '', 'precio' => 0.0, 'descuento_pct' => 0.0, 'descuento_aprob' => null];
        $items[] = [
            'id_libro' => $l['id_libro'],
            'libro' => $l['libro'],
            'isbn' => $formato['isbn'],
            'precio' => $formato['precio'],
            'descuento_pct' => $formato['descuento_pct'],
            'descuento_aprob' => $formato['descuento_aprob'],
            'solicitada' => $l['solicitada'],
            'despachada' => $l['despachada'],
            'en_pedido' => true,
            'remisionada' => $l['remisionada'],
            'devuelta' => 0,
            'generada' => $l['generada'],
            'generada_crm' => $l['generada_crm'],
            'pendiente_sincronizacion' => $l['pendiente_sincronizacion'],
            'pendiente' => $l['pendiente'],
            'pendiente_gestion' => $l['pendiente_gestion'],
            'excedente' => $l['excedente'],
            'cumplimiento' => $cumplLinea,
        ];
        foreach ($totales as $k => $v) $totales[$k] += $l[$k] ?? 0;
        $totalSolicitada += $l['solicitada'];
        $totalDespachada += $l['despachada'];
        $totalGenerada += $l['generada'];
        $totalPendiente += $l['pendiente'];
    }
    usort($items, fn($a, $b) => strcmp($a['libro'], $b['libro']));

    // Solo los documentos del propio pedido (los de sus pedidos nuevos salen en la trazabilidad).
    $ndocs = array_values(array_unique(array_map('etiqueta_doc_backorder',
        array_filter($ops, fn($o) => (int)($o['de_pedido'] ?? $idPedido) === $idPedido))));

    backorders_asignar_consecutivos($bdd, $calc['lineas']);
    $consecutivo = backorders_obtener_consecutivos($bdd, [$idPedidoOrigenDeEste ?: $idPedido]);
    $consecutivo = $consecutivo[(int)($idPedidoOrigenDeEste ?: $idPedido)] ?? '';

    // Trazabilidad de los pedidos nuevos: lo registrado en el CRM al generarlos + lo que World
    // Office confirma de cada uno (documentos y unidades cruzadas por OP + PED del pedido nuevo).
    $historial = obtener_historial_generados_backorder($bdd, [$idPedido]);
    foreach ($historial as &$g) {
        $idNuevo = $g['id_pedido_nuevo'];
        $wo = $calc['despachoPedidosNuevos'][$idNuevo] ?? [];
        $docsWo = array_values($calc['docsPedidosNuevos'][$idNuevo] ?? []);
        $g['consecutivo'] = $consecutivo;
        $g['docs_wo'] = $docsWo;
        $g['cantidad_wo'] = round((float)($wo['cantidad'] ?? 0), 2);
        // Pendiente de sincronización: generado en el CRM pero sin ningún documento confirmado en
        // World Office todavía (no suma como cantidad confirmada).
        $g['sincronizado'] = (bool)array_filter($docsWo, fn($d) => $d['confirmado']);
        foreach ($g['libros'] as &$lib) $lib['cantidad_wo'] = round((float)($wo['libros'][$lib['id_libro']] ?? 0), 2);
        unset($lib);
    }
    unset($g);

    return [
        'id_pedido' => $idPedido,
        // "BK {PERIODO}-{NN}" del backorder (del original si este es un pedido nuevo). Vacío
        // mientras no tenga una REM/FV confirmada en World Office.
        'consecutivo' => $consecutivo,
        'colegio' => $ped['colegio'],
        'cliente' => $ped['cliente_nombre'] ?: '',
        'responsable' => $responsable,
        'fecha' => $ped['fecha'],
        'estado' => $ped['estado_nombre'] ?: '',
        'documento' => implode(', ', $ndocs),
        // Pedidos del colegio en el período (las cantidades son del colegio completo) y las
        // devoluciones de remisión / notas crédito que se le restaron.
        'pedidos_colegio' => array_values($pedidosColegio),
        'docs_devolucion' => array_values($docsDevolucion),
        'op' => implode(', ', array_values(array_unique($opIds))),
        'op_ids' => array_values(array_unique($opIds)),
        'id_periodo' => (int)$ped['id_periodo'],
        'periodo_permitido' => periodo_permitido_backorders_pedido($bdd, $ped['id_periodo']),
        'generado_desde' => $idPedidoOrigenDeEste ? (int)$idPedidoOrigenDeEste : null,
        'esPreMigracion' => $esPreMigracion,
        'items' => $items,
        'historial' => $historial,
        'total_solicitada' => round($totalSolicitada, 2),
        'total_despachada' => round($totalDespachada, 2),
        'total_remisionada' => round($totales['remisionada'], 2),
        'total_devuelta' => round($totales['devuelta'], 2),
        'total_generada' => round($totalGenerada, 2),
        'total_generada_crm' => round($totales['generada_crm'], 2),
        'total_sincronizacion' => round($totales['pendiente_sincronizacion'], 2),
        'total_pendiente' => round($totalPendiente, 2),
        'total_pendiente_gestion' => round($totales['pendiente_gestion'], 2),
        'total_excedente' => round($totales['excedente'], 2),
        'cumplimiento' => porcentaje_cumplimiento_backorder($totalSolicitada, $totalDespachada),
        // Campos del pedido original que hereda TAL CUAL el pedido nuevo de "Atender backorder" (se
        // muestran sin poder editarse; solo la cantidad es editable) — ver
        // php/backorders_generar_pedido.php.
        'origen' => [
            'codigo' => $ped['codigo'],
            'id_colegio' => (int)$ped['id_colegio'],
            'id_periodo' => (int)$ped['id_periodo'],
            'id_usuario' => (int)$ped['id_usuario_digito'],
            'id_cliente' => (int)$ped['id_cliente'],
            'tipo' => $ped['tipo'],
            'fac_rem' => $ped['fac_rem'],
            'dir_ent' => $ped['dir_ent'],
            'observaciones' => $ped['observaciones'],
            'fecha_r' => $ped['fecha_r'],
        ],
    ];
}

/**
 * Detalle de backorder de un COLEGIO en un período (reporte_backorders_pedido.php, desde
 * 2026-10-20 se busca y se consulta por colegio, no por pedido): todos sus pedidos sumados por
 * libro, con las devoluciones de remisión y notas crédito restadas al colegio.
 *
 * Las cantidades por libro (items) salen de obtener_backorder_pedido() del pedido BASE (que ya las
 * calcula por colegio); el encabezado se reemplaza por el del colegio: consecutivos BK y fecha de
 * generación del backorder (asignación del primer consecutivo), documentos, OPs, pedidos y la
 * trazabilidad de los pedidos nuevos de todos sus pedidos. "pedidos_base" son los pedidos desde los
 * que se puede atender el backorder (el pedido nuevo copia uno de ellos).
 * null si el colegio no tiene pedidos con backorder calculable en ese período.
 */
function obtener_backorder_colegio($bdd, $idColegio, $idPeriodo) {
    $calc = calcular_lineas_backorder($bdd, (int)$idPeriodo, null, null, null, null, (int)$idColegio);
    $calc['lineas'] = backorders_lineas_con_documento($calc['lineas']);
    if (empty($calc['lineas'])) return null;

    $idsPedidos = array_values(array_unique(array_map(fn($l) => (int)$l['id_pedido'], $calc['lineas'])));
    sort($idsPedidos);
    backorders_asignar_consecutivos($bdd, $calc['lineas']);
    $ph = implode(',', array_fill(0, count($idsPedidos), '?'));
    $stmt = $bdd->prepare("SELECT id_pedido, consecutivo, fecha_asignacion FROM backorder_consecutivos WHERE id_pedido IN ($ph) ORDER BY fecha_asignacion, numero");
    $stmt->execute($idsPedidos);
    $consecutivos = []; // id_pedido => ['consecutivo', 'fecha']
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $consecutivos[(int)$r['id_pedido']] = ['consecutivo' => $r['consecutivo'], 'fecha' => $r['fecha_asignacion']];

    // Pedido base: el primero con consecutivo (o el primero del colegio).
    $idBase = $consecutivos ? array_key_first($consecutivos) : $idsPedidos[0];
    $det = obtener_backorder_pedido($bdd, $idBase);
    if ($det === null) return null;

    $docs = [];
    $opIds = [];
    $clientes = [];
    foreach ($calc['lineas'] as $l) {
        foreach ($l['ops'] as $op) $docs[etiqueta_doc_backorder($op)] = true;
        foreach ($l['op_ids'] as $id) $opIds[$id] = $id;
        if ($l['cliente'] !== '') $clientes[$l['cliente']] = true;
    }
    sort($opIds);

    // Trazabilidad de los pedidos nuevos de TODOS los pedidos del colegio.
    $historial = obtener_historial_generados_backorder($bdd, $idsPedidos);
    foreach ($historial as &$g) {
        $idNuevo = $g['id_pedido_nuevo'];
        $wo = $calc['despachoPedidosNuevos'][$idNuevo] ?? [];
        $docsWo = array_values($calc['docsPedidosNuevos'][$idNuevo] ?? []);
        $g['consecutivo'] = $consecutivos[$g['id_pedido_origen']]['consecutivo'] ?? '';
        $g['docs_wo'] = $docsWo;
        $g['cantidad_wo'] = round((float)($wo['cantidad'] ?? 0), 2);
        $g['sincronizado'] = (bool)array_filter($docsWo, fn($d) => $d['confirmado']);
        foreach ($g['libros'] as &$lib) $lib['cantidad_wo'] = round((float)($wo['libros'][$lib['id_libro']] ?? 0), 2);
        unset($lib);
    }
    unset($g);

    // Pedidos desde los que se puede atender (con OP activa).
    $pedidosBase = [];
    foreach ($idsPedidos as $idP) {
        $ops = $calc['opsAsociadasPorPedido'][$idP] ?? [];
        if (!$ops) continue;
        $pedidosBase[] = ['id_pedido' => $idP, 'consecutivo' => $consecutivos[$idP]['consecutivo'] ?? '', 'op' => implode(', ', array_unique($ops))];
    }

    $stmtPer = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
    $stmtPer->execute([(int)$idPeriodo]);

    return array_merge($det, [
        'id_colegio' => (int)$idColegio,
        'periodo' => (string)$stmtPer->fetchColumn(),
        'id_pedido_base' => $idBase,
        'consecutivo' => implode(', ', array_column($consecutivos, 'consecutivo')),
        // Fecha en que se generó el backorder (asignación de su primer consecutivo BK).
        'fecha_backorder' => $consecutivos ? substr(reset($consecutivos)['fecha'], 0, 10) : null,
        'cliente' => implode(', ', array_keys($clientes)) ?: $det['cliente'],
        'documento' => implode(', ', array_keys($docs)),
        'op' => implode(', ', $opIds),
        'op_ids' => array_values($opIds),
        'pedidos_colegio' => $idsPedidos,
        'pedidos_base' => $pedidosBase,
        'historial' => $historial,
        // Backorder por paquete: libros de cada paquete con lo pendiente del colegio.
        'paquetes' => backorders_detalle_paquetes($bdd, $idColegio, $idPeriodo, $det['items'], $calc['despachoPaquetes'] ?? []),
    ]);
}

/**
 * Documentos REM/FV del catálogo completo (php/sync_backorders_wo_catalogo.php) que la
 * verificación automática por OP+PED (ver includes/matching_pedidos_wo.php) no pudo resolver
 * contra ningún pedido, y que tampoco se les ha asignado un colegio a mano todavía — para el panel
 * "Documentos sin verificar" de reporte_backorders.php (mismo patrón que el panel "sin cruzar" de
 * Colocación). Se envuelve en try/catch porque la tabla la crea ese sync — puede no existir
 * todavía si nadie lo ha corrido.
 */
function obtener_backorders_sin_verificar($bdd) {
    backorders_asegurar_columna_anulado($bdd);
    try {
        // Los anulados en World Office no despacharon nada: no hay nada que asignar. Los del
        // 30/06/2026 o anteriores no se muestran (pedido por la usuaria 2026-10-20): siguen en el
        // catálogo, solo no salen en este panel.
        $filas = $bdd->query("SELECT id_wo, tipo_documento, numero, fecha, concepto, colegio_extraido, cliente
                               FROM backorder_wo_catalogo
                               WHERE id_pedido_verificado IS NULL AND asignado_manual = 0 AND anulado = 0
                                 AND fecha > '2026-06-30'
                               ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    if (empty($filas)) return $filas;

    // Filtro pedido por el usuario 2026-09-11 ("para que no salga tanta cosa"): solo mostrar
    // documentos que (1) sí traen un colegio identificable en el concepto (texto "COL ..."), y
    // (2) ese colegio tiene presupuesto REGISTRADO en el CRM (`presupuestos.pre_definido = 1` —
    // mismo criterio que "Presupuesto Registrado en CRM" en Colocación/dashboard_presupuestos_stats,
    // el usuario lo llamó "columna presupuesto=1"). Documentos sin ningún "COL " en el concepto
    // (típicamente cierres de caja/"CORTE ..." genéricos sin colegio, mismo patrón ya confirmado
    // para POS en Colocación — ver memory/project_colocacion_modulo.md) también se ocultan, a
    // pedido explícito del usuario ("excluye esos que dicen de corte o que en ningún momento tiene
    // la abreviatura de COL porque no son relevantes") — nunca se van a poder verificar contra un
    // pedido ni tienen nada útil que revisar a mano.
    $colegiosPrecargados = cargar_todos_los_colegios($bdd);
    $colegiosConPresupuesto = array_fill_keys(
        $bdd->query("SELECT DISTINCT id_colegio FROM presupuestos WHERE pre_definido = 1")->fetchAll(PDO::FETCH_COLUMN),
        true
    );

    $filas = array_values(array_filter($filas, function ($f) use ($colegiosPrecargados, $colegiosConPresupuesto) {
        if (empty($f['colegio_extraido'])) return false;
        // Sin restricción de calendario (a diferencia de Colocación): Backorders no está acotado
        // a un solo calendario, ver includes/matching_colegios.php.
        $idColegio = emparejar_colegio($f['colegio_extraido'], $colegiosPrecargados, [], null);
        return $idColegio !== null && isset($colegiosConPresupuesto[$idColegio]);
    }));

    // Referencia de OP/pedido que trae el concepto y MOTIVO por el que no se pudo cruzar (misma
    // verificación que la sincronización, ver includes/matching_pedidos_wo.php) — pedido por la
    // usuaria 2026-10-20. Estos documentos nunca reciben consecutivo BK hasta que se asignen.
    $pedidosPorOp = cargar_pedidos_por_op($bdd);
    foreach ($filas as &$f) {
        $op = extraer_op_id_de_concepto($f['concepto']);
        $ped = extraer_ped_de_concepto($f['concepto']);
        $f['ref_op'] = $op;
        $f['ref_pedido'] = $ped;
        if ($f['fecha'] && $f['fecha'] < BACKORDERS_FECHA_MIGRACION_WO) {
            $f['motivo'] = 'Documento anterior a la migración a World Office';
        } elseif ($op === null && $ped === null) {
            $f['motivo'] = 'El concepto no trae número de OP ni de pedido';
        } elseif ($op === null) {
            $f['motivo'] = 'El concepto no trae número de OP';
        } elseif ($ped === null) {
            $f['motivo'] = 'El concepto no trae número de pedido (PED)';
        } elseif (empty($pedidosPorOp[$op])) {
            $f['motivo'] = 'La OP ' . $op . ' no existe en el CRM o no tiene pedidos';
        } else {
            $f['motivo'] = 'El pedido ' . $ped . ' no pertenece a la OP ' . $op . ' (sus pedidos: #' . implode(', #', array_unique($pedidosPorOp[$op])) . ')';
        }
    }
    unset($f);
    return $filas;
}

/**
 * Historial de pedidos nuevos generados para atender el backorder de los pedidos originales
 * dados (o, con $idOp, de todos los ligados a esa OP), agrupado por pedido nuevo — con estado,
 * fecha, OP, quién lo generó y la cantidad de cada libro. Incluye los anulados (marcados como
 * tales): no descuentan del saldo, pero son parte de la historia.
 */
function obtener_historial_generados_backorder($bdd, array $idsPedidoOrigen, $idOp = null) {
    backorders_asegurar_tablas($bdd);
    $where = [];
    $params = [];
    if (!empty($idsPedidoOrigen)) {
        $where[] = 'g.id_pedido_origen IN (' . implode(',', array_fill(0, count($idsPedidoOrigen), '?')) . ')';
        $params = array_map('intval', array_values($idsPedidoOrigen));
    }
    if ($idOp !== null) {
        $where[] = 'g.id_op = ?';
        $params[] = (int)$idOp;
    }
    if (empty($where)) return [];
    $stmt = $bdd->prepare("SELECT g.id_pedido_origen, g.id_pedido_nuevo, g.id_op, g.id_libro, g.cantidad,
                                  g.solicitada_origen, g.despachada_al_generar, g.pendiente_antes, g.fecha, g.origen_registro,
                                  l.libro, l.isbn, p.estado, ep.estado AS estado_nombre,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                           FROM backorder_pedidos_generados g
                           JOIN pedidos p ON p.id = g.id_pedido_nuevo
                           LEFT JOIN estados_pedidos ep ON ep.id = p.estado
                           LEFT JOIN libros l ON l.id = g.id_libro
                           LEFT JOIN usuarios u ON u.id = g.id_usuario
                           WHERE " . implode(' OR ', $where) . "
                           ORDER BY g.fecha, g.id_pedido_nuevo, l.libro");
    $stmt->execute($params);
    $porPedido = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idNuevo = (int)$r['id_pedido_nuevo'];
        if (!isset($porPedido[$idNuevo])) {
            $porPedido[$idNuevo] = [
                'id_pedido_nuevo' => $idNuevo,
                'id_pedido_origen' => (int)$r['id_pedido_origen'],
                'id_op' => $r['id_op'] !== null ? (int)$r['id_op'] : null,
                'fecha' => $r['fecha'],
                'estado' => $r['estado_nombre'] ?: '',
                'estado_id' => (int)$r['estado'],
                'anulado' => (int)$r['estado'] === 3,
                'usuario' => trim($r['usuario'] ?? '') ?: ($r['origen_registro'] === 'legado' ? '(registro anterior)' : '—'),
                'total' => 0.0,
                'libros' => [],
            ];
        }
        $porPedido[$idNuevo]['libros'][] = [
            'id_libro' => (int)$r['id_libro'],
            'libro' => $r['libro'] ?: ('Libro #' . $r['id_libro']),
            'isbn' => $r['isbn'] ?? '',
            'cantidad' => (float)$r['cantidad'],
            'pendiente_antes' => $r['pendiente_antes'] !== null ? (float)$r['pendiente_antes'] : null,
        ];
        $porPedido[$idNuevo]['total'] += (float)$r['cantidad'];
    }
    return array_values($porPedido);
}

/**
 * Pedidos relacionados con una OP, para la sección "Pedidos relacionados" de op_pendiente.php:
 * cada pedido ORIGINAL ligado a la OP (directo, agrupado, u origen de un pedido nuevo generado
 * dentro de ella) con lo solicitado originalmente, despachado, generado en pedidos nuevos y
 * pendiente por libro, más el historial de pedidos nuevos generados desde él (con su fecha, estado
 * y OP). Un pedido nuevo ligado a la OP se muestra dentro de su pedido original, no aparte.
 */
function obtener_pedidos_relacionados_op($bdd, $idOp) {
    $idOp = (int)$idOp;
    if ($idOp <= 0) return [];
    backorders_asegurar_tablas($bdd);

    $stmt = $bdd->prepare("SELECT id_pedido FROM ordenes_pedidos WHERE id = ? AND id_pedido <> 0
                           UNION SELECT id_pedido FROM op_pedidos_agrupados WHERE op = ? AND tipo = 1
                           UNION SELECT id_pedido_origen FROM backorder_pedidos_generados WHERE id_op = ?");
    $stmt->execute([$idOp, $idOp, $idOp]);
    $idsPedidosOp = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    if (empty($idsPedidosOp)) return [];

    // Un pedido nuevo se reemplaza por su pedido original.
    $familias = backorders_familias($bdd, $idsPedidosOp);
    $originales = [];
    foreach ($idsPedidosOp as $idP) {
        $originales[$familias['hijos'][$idP] ?? $idP] = true;
    }
    $originales = array_keys($originales);

    $ph = implode(',', array_fill(0, count($originales), '?'));
    $stmtPed = $bdd->prepare("SELECT p.id, p.fecha, p.estado, ep.estado AS estado_nombre, c.colegio, cl.cliente
                              FROM pedidos p
                              JOIN colegios c ON c.id = p.id_colegio
                              LEFT JOIN clientes cl ON cl.id = p.cliente
                              LEFT JOIN estados_pedidos ep ON ep.id = p.estado
                              WHERE p.id IN ($ph) ORDER BY p.id");
    $stmtPed->execute($originales);
    $pedidos = [];
    foreach ($stmtPed->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pedidos[(int)$p['id']] = [
            'id_pedido' => (int)$p['id'],
            'fecha' => $p['fecha'],
            'estado' => $p['estado_nombre'] ?: '',
            'estado_id' => (int)$p['estado'],
            'colegio' => $p['colegio'],
            'cliente' => $p['cliente'] ?: '',
            'esPreMigracion' => false,
            'items' => [],
            'total_solicitada' => 0.0, 'total_despachada' => 0.0, 'total_generada' => 0.0, 'total_pendiente' => 0.0,
            'historial' => [],
        ];
    }

    // Cada pedido se calcula aislado (modo lista), igual que su ficha individual.
    $calc = calcular_lineas_backorder($bdd, null, null, null, null, array_keys($pedidos));
    foreach ($calc['preMigracion'] as $pm) {
        if (isset($pedidos[(int)$pm['id_pedido']])) $pedidos[(int)$pm['id_pedido']]['esPreMigracion'] = true;
    }
    foreach ($calc['lineas'] as $l) {
        $idP = $l['id_pedido'];
        if (!isset($pedidos[$idP])) continue;
        $pedidos[$idP]['items'][] = [
            'libro' => $l['libro'], 'solicitada' => $l['solicitada'], 'despachada' => $l['despachada'],
            'generada' => $l['generada'], 'pendiente' => $l['pendiente'],
        ];
        $pedidos[$idP]['total_solicitada'] += $l['solicitada'];
        $pedidos[$idP]['total_despachada'] += $l['despachada'];
        $pedidos[$idP]['total_generada'] += $l['generada'];
        $pedidos[$idP]['total_pendiente'] += $l['pendiente'];
    }
    foreach (obtener_historial_generados_backorder($bdd, array_keys($pedidos)) as $g) {
        if (isset($pedidos[$g['id_pedido_origen']])) $pedidos[$g['id_pedido_origen']]['historial'][] = $g;
    }
    foreach ($pedidos as &$p) {
        usort($p['items'], fn($a, $b) => strcmp($a['libro'], $b['libro']));
        foreach (['total_solicitada', 'total_despachada', 'total_generada', 'total_pendiente'] as $k) $p[$k] = round($p[$k], 2);
    }
    unset($p);
    return array_values($pedidos);
}
