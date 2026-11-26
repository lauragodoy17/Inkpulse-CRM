<?php
/**
 * /includes/solicitudes_vd_datos.php
 * "Solicitudes de venta directa" (menú Venta directa, 2026-11-24 la usuaria): los pedidos de los colegios cuya adopción
 * tiene el canal "Venta directa - Eureka", por colegio, con sus fechas de venta (adopcion_vd_jornadas) y sus formatos.
 *
 * El consecutivo es de la VENTA, no de los pedidos: el día de cada venta (o después, si la fecha ya pasó) se RESERVA un
 * formato vacío (formatos_vd.ids_pedidos = '', sin filas) con su número, la fecha y el horario de esa venta. Solo se
 * reserva si el colegio tiene al menos un pedido no anulado. Un colegio con varias ventas tiene un número por venta.
 * Los pedidos entran al formato al registrar la devolución: en Devolución de venta directa se elige a qué venta
 * corresponde (svd_ventas_para_devolucion) y sus pedidos llenan ese formato (svd_llenar_reservado). Sin cron: la reserva se
 * hace la primera vez que alguien abre este apartado o la lista de formatos (svd_asignar_pendientes).
 */
require_once __DIR__ . '/formatos_vd_datos.php';
require_once __DIR__ . '/sobrecosto_canal.php';
require_once __DIR__ . '/adopcion_vd_datos.php';
require_once __DIR__ . '/pedidos_venta_directa.php';

const SVD_ESTADO_ANULADO = 3; // estados_pedidos: los anulados no se muestran ni cuentan para reservar

/** Períodos con colegios de venta directa dentro del alcance del usuario: [['id','periodo']], el más reciente primero. */
function svd_periodos(PDO $bdd) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT DISTINCT pe.id, pe.periodo FROM recursos r JOIN colegios c ON c.id = r.id_colegio JOIN periodos pe ON pe.id = r.id_periodo
                         WHERE " . sql_adopcion_tiene_canal([canal_andrea_id($bdd)]) . $alcance . " ORDER BY pe.id DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Colegios de venta directa del período (alcance del usuario) con sus pedidos (sin anulados), fechas de venta y formatos:
 * [['id_colegio','colegio','ciudad','id_periodo','periodo','pedidos' => [['id','clave','fecha','estado','estado_txt','solicitante',
 *   'formato' => [...]|null]], 'jornadas' => [['id','fecha','hi','hf','formato' => [...]|null]], 'formatos' => [['id','consecutivo',
 *   'etapa','fecha_venta','reservado']], 'proxima' => fecha|null]]. Solo colegios con al menos un pedido.
 */
function svd_listar(PDO $bdd, $idPeriodo) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $cond = sql_adopcion_tiene_canal([canal_andrea_id($bdd)]);
    $params = array_merge([(int)$idPeriodo], $params);
    $st = $bdd->prepare("SELECT x.id, x.fecha, x.estado, ep.estado AS estado_txt, x.id_colegio, x.id_periodo, c.colegio, c.ciudad, pe.periodo,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante
                         FROM pedidos x
                         JOIN colegios c ON c.id = x.id_colegio
                         JOIN recursos r ON r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo
                         JOIN periodos pe ON pe.id = x.id_periodo
                         LEFT JOIN estados_pedidos ep ON ep.id = x.estado
                         LEFT JOIN usuarios u ON u.id = x.id_usuario
                         WHERE x.id_periodo = ? AND x.estado <> " . SVD_ESTADO_ANULADO . " AND $cond" . $alcance . "
                         ORDER BY c.colegio, x.id");
    $st->execute($params);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $k = (int)$p['id_colegio'];
        if (!isset($res[$k])) {
            $res[$k] = ['id_colegio' => $k, 'colegio' => $p['colegio'], 'ciudad' => $p['ciudad'], 'id_periodo' => (int)$p['id_periodo'],
                        'periodo' => $p['periodo'], 'pedidos' => [], 'jornadas' => [], 'formatos' => [], 'proxima' => null];
        }
        $res[$k]['pedidos'][] = ['id' => (int)$p['id'], 'clave' => 'v' . (int)$p['id'], 'fecha' => $p['fecha'], 'estado' => (int)$p['estado'],
                                 'estado_txt' => (string)$p['estado_txt'], 'solicitante' => trim((string)$p['solicitante']), 'formato' => null];
    }
    if (!$res) return [];
    $ids = implode(',', array_keys($res));

    // Formatos activos de esos colegios en el período y a qué pedidos pertenecen.
    $porPedido = [];
    foreach ($bdd->query("SELECT id, consecutivo, etapa, ids_pedidos, id_colegio, fecha_venta FROM formatos_vd
                          WHERE estado = " . FVD_ESTADO_ACTIVO . " AND id_periodo = " . (int)$idPeriodo . " AND id_colegio IN ($ids) ORDER BY fecha_venta, consecutivo") as $f) {
        $fx = ['id' => (int)$f['id'], 'consecutivo' => (int)$f['consecutivo'], 'etapa' => (int)$f['etapa'], 'fecha_venta' => $f['fecha_venta'],
               'reservado' => trim((string)$f['ids_pedidos']) === ''];
        $res[(int)$f['id_colegio']]['formatos'][] = $fx;
        foreach (array_filter(explode(',', (string)$f['ids_pedidos'])) as $c) $porPedido[$c] = $fx;
    }
    foreach ($res as &$col) foreach ($col['pedidos'] as &$p) $p['formato'] = $porPedido[$p['clave']] ?? null;
    unset($col, $p);

    // Fechas de venta y su formato (adopcion_vd_atendidas: el formato cuya fecha de venta coincide).
    adopcion_vd_asegurar($bdd);
    $jor = $bdd->query("SELECT id, id_colegio, id_periodo, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf
                        FROM adopcion_vd_jornadas WHERE id_periodo = " . (int)$idPeriodo . " AND id_colegio IN ($ids) AND cancelada = 0 ORDER BY fecha, hora_inicio")->fetchAll(PDO::FETCH_ASSOC);
    $atendidas = adopcion_vd_atendidas($bdd, $jor);
    $hoy = date('Y-m-d');
    foreach ($jor as $j) {
        $f = $atendidas[$j['id']] ?? null;
        $res[(int)$j['id_colegio']]['jornadas'][] = ['id' => (int)$j['id'], 'fecha' => $j['fecha'], 'hi' => $j['hi'], 'hf' => $j['hf'],
            'formato' => $f ? ['id' => (int)$f['id'], 'consecutivo' => (int)$f['consecutivo']] : null];
        if ($j['fecha'] >= $hoy && ($res[(int)$j['id_colegio']]['proxima'] === null)) $res[(int)$j['id_colegio']]['proxima'] = $j['fecha'];
    }
    return array_values($res);
}

/** Órdenes de la lista de solicitudes (2026-11-26, la usuaria). */
const SVD_ORDENES = ['proxima' => 'Próxima venta primero', 'antiguo' => 'Pedido más antiguo → más reciente', 'reciente' => 'Pedido más reciente → más antiguo'];

/**
 * Filtra y ordena las filas de svd_listar() (2026-11-26, la usuaria): $idColegio = 0 todos; $orden (SVD_ORDENES):
 *  - 'proxima': primero los que venden hoy, luego las próximas ventas, luego los que ya vendieron y al final los sin fecha;
 *  - 'antiguo' / 'reciente': por la fecha de los pedidos (dentro de cada colegio también); el colegio va según su pedido
 *    más antiguo (o más reciente).
 */
function svd_filtrar_ordenar(array $colegios, $idColegio = 0, $orden = 'proxima') {
    if ($idColegio) $colegios = array_values(array_filter($colegios, fn($c) => $c['id_colegio'] === (int)$idColegio));
    $clave_p = fn($p) => $p['fecha'] . sprintf('|%010d', $p['id']);
    if ($orden === 'antiguo' || $orden === 'reciente') {
        $dir = $orden === 'antiguo' ? 1 : -1;
        foreach ($colegios as &$c) usort($c['pedidos'], fn($a, $b) => $dir * strcmp($clave_p($a), $clave_p($b)));
        unset($c);
        usort($colegios, fn($a, $b) => $dir * strcmp($clave_p($a['pedidos'][0]), $clave_p($b['pedidos'][0])) ?: strcasecmp($a['colegio'], $b['colegio']));
        return $colegios;
    }
    usort($colegios, function ($a, $b) {
        $ka = $a['proxima'] ?? ($a['jornadas'] ? '9998' : '9999');
        $kb = $b['proxima'] ?? ($b['jornadas'] ? '9998' : '9999');
        return strcmp($ka, $kb) ?: strcasecmp($a['colegio'], $b['colegio']);
    });
    return $colegios;
}

/**
 * Comparativo en unidades POR PEDIDO de un colegio (2026-11-24, la usuaria), todo en libros:
 *  - solicitado: lo pedido (libros_pedidos.cantidad; un paquete cuenta cada libro);
 *  - remisionado: lo despachado en World Office (remisiones CEUR / facturas FVE de sus OP, los productos de paquete pasados
 *    a sus libros); solo para pedidos Entregados (null si el pedido aún no se entrega). Un documento de una OP agrupada que
 *    comparten varios pedidos cuenta una vez, en el primero (dvv_detalle_varios);
 *  - devuelto: todas las devoluciones activas del pedido (dvd_devuelto);
 *  - vendido: remisionado − devuelto (nunca negativo; null si no hay remisionado).
 * $col = una fila de svd_listar(). Devuelve ['ok','error','pedidos' => [id => ['solicitado','remisionado','devuelto','vendido',
 * 'sin_remision']], 'total' => [...]].
 */
function svd_resumen_unidades(PDO $bdd, array $col) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_detalle_varios
    $ids = array_map(fn($p) => (int)$p['id'], $col['pedidos']);
    $res = ['ok' => true, 'error' => null, 'pedidos' => [], 'total' => ['solicitado' => 0, 'remisionado' => 0, 'devuelto' => 0, 'vendido' => 0]];
    if (!$ids) return $res;
    $sol = array_map('intval', $bdd->query("SELECT x.id, COALESCE(SUM(lp.cantidad), 0) FROM pedidos x LEFT JOIN libros_pedidos lp ON lp.cod_pedido = x.codigo
                                            WHERE x.id IN (" . implode(',', $ids) . ") GROUP BY x.id")->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach ($col['pedidos'] as $p) {
        $dev = 0;
        foreach (dvd_devuelto($bdd, $p['clave']) as $x) $dev += (int)$x['cantidad'];
        $res['pedidos'][$p['id']] = ['solicitado' => (int)($sol[$p['id']] ?? 0), 'remisionado' => null, 'devuelto' => $dev, 'vendido' => null, 'sin_remision' => false];
    }
    $entregados = array_values(array_map(fn($p) => $p['clave'], array_filter($col['pedidos'], fn($p) => $p['estado'] === DVD_ESTADO_ENTREGADO)));
    foreach (array_chunk($entregados, DVD_MAX_PEDIDOS) as $grupo) {
        $d = dvv_detalle_varios($bdd, $grupo, 'normal', true);
        if (!$d['ok']) return ['ok' => false, 'error' => $d['error']] + $res;
        foreach ($d['pedidos'] as $pi) {
            $x = &$res['pedidos'][(int)$pi['id']];
            $x['remisionado'] = (int)$pi['unidades'];
            $x['sin_remision'] = !empty($pi['sin_documentos']);
            $x['vendido'] = max(0, $x['remisionado'] - $x['devuelto']);
            unset($x);
        }
    }
    foreach ($res['pedidos'] as $x) foreach ($res['total'] as $k => $_) $res['total'][$k] += (int)$x[$k];
    return $res;
}

/**
 * Reserva el consecutivo de las ventas que ya llegaron: cada fecha de venta HOY o pasada, de un colegio con pedidos, que
 * aún no tiene formato, recibe uno vacío (svd_reservar), en orden de fecha. Devuelve [['id','consecutivo','colegio','fecha']].
 */
function svd_asignar_pendientes(PDO $bdd, $idPeriodo) {
    $creados = [];
    $hoy = date('Y-m-d');
    foreach (svd_listar($bdd, $idPeriodo) as $col) {
        foreach ($col['jornadas'] as $j) {
            if ($j['fecha'] > $hoy || $j['formato']) continue;
            $r = svd_reservar($bdd, $col['id_colegio'], $col['id_periodo'], $j);
            if ($r && empty($r['repetido'])) $creados[] = $r + ['colegio' => $col['colegio'], 'fecha' => $j['fecha']];
        }
    }
    return $creados;
}

/**
 * Lo mismo que svd_asignar_pendientes, solo para un colegio (2026-11-25: al ver los estados de sus fechas de venta en la
 * adopción cerrada). Reserva solo si el colegio tiene el canal de venta directa y al menos un pedido no anulado.
 */
function svd_asignar_colegio(PDO $bdd, $idColegio, $idPeriodo) {
    adopcion_vd_asegurar($bdd);
    $st = $bdd->prepare("SELECT COUNT(*) FROM pedidos x JOIN recursos r ON r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo
                         WHERE x.id_colegio = ? AND x.id_periodo = ? AND x.estado <> " . SVD_ESTADO_ANULADO . " AND " . sql_adopcion_tiene_canal([canal_andrea_id($bdd)]));
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    if (!(int)$st->fetchColumn()) return [];
    $st = $bdd->prepare("SELECT id, id_colegio, id_periodo, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf
                         FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ? AND cancelada = 0 ORDER BY fecha, hora_inicio");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $jor = $st->fetchAll(PDO::FETCH_ASSOC);
    $atendidas = adopcion_vd_atendidas($bdd, $jor);
    $hoy = date('Y-m-d');
    $creados = [];
    foreach ($jor as $j) {
        if ($j['fecha'] > $hoy || isset($atendidas[$j['id']])) continue;
        $r = svd_reservar($bdd, $idColegio, $idPeriodo, $j);
        if ($r && empty($r['repetido'])) $creados[] = $r;
    }
    return $creados;
}

/**
 * Formato vacío con el siguiente consecutivo para la venta $jornada del colegio (automático, sin pedidos ni filas, en la
 * solicitud). El token sale del colegio, el período y la fecha: no se reserva dos veces la misma venta (tampoco si se
 * anuló a mano). Devuelve ['id','consecutivo','repetido'] o null si falla.
 */
function svd_reservar(PDO $bdd, $idColegio, $idPeriodo, array $jornada) {
    fvd_asegurar_tablas($bdd);
    $token = md5('svd-reserva|' . (int)$idColegio . '|' . (int)$idPeriodo . '|' . $jornada['fecha']);
    $st = $bdd->prepare("SELECT id, consecutivo FROM formatos_vd WHERE token_registro = ?");
    $st->execute([$token]);
    if ($ya = $st->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$ya['id'], 'consecutivo' => (int)$ya['consecutivo'], 'repetido' => true];
    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $consecutivo = (int)$bdd->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM formatos_vd FOR UPDATE")->fetchColumn();
        $bdd->prepare("INSERT INTO formatos_vd (consecutivo, id_colegio, id_periodo, ids_pedidos, etapa, automatico, fecha_venta, hora_inicio, hora_fin,
                                                estado, id_usuario, token_registro, created_at)
                       VALUES (?, ?, ?, '', " . FVD_ETAPA_SOLICITUD . ", 1, ?, ?, ?, " . FVD_ESTADO_ACTIVO . ", ?, ?, NOW())")
            ->execute([$consecutivo, (int)$idColegio, (int)$idPeriodo, $jornada['fecha'], $jornada['hi'] . ':00', $jornada['hf'] . ':00', (int)($_SESSION['id'] ?? 0), $token]);
        $id = (int)$bdd->lastInsertId();
        registrar_historial_estado($bdd, FVD_MODULO_ETAPAS, $id, FVD_ETAPA_SOLICITUD, (int)($_SESSION['id'] ?? 0),
            'Consecutivo reservado el día de la venta (' . date('d/m/Y', strtotime($jornada['fecha'])) . '); los pedidos entran con la devolución');
        $bdd->commit();
        return ['id' => $id, 'consecutivo' => $consecutivo, 'repetido' => false];
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        $st->execute([$token]);
        if ($ya = $st->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$ya['id'], 'consecutivo' => (int)$ya['consecutivo'], 'repetido' => true];
        error_log('Reserva de formato de venta directa: ' . $e->getMessage());
        return null;
    }
}

/**
 * Ventas (formatos) a las que puede ir una devolución de venta directa con los pedidos $claves del colegio:
 *  - si alguno de los pedidos ya está en un formato, solo ese ('fijo'); pedidos en formatos distintos, o unos en un
 *    formato y otros sin formato, no se pueden devolver juntos ('error');
 *  - si no, los consecutivos reservados (sin pedidos) del colegio en el período ('reservados'), por fecha de venta.
 * Devuelve ['fijo' => ['id','consecutivo','fecha_venta']|null, 'reservados' => [...], 'error' => string|null].
 */
function svd_ventas_para_devolucion(PDO $bdd, $idColegio, $idPeriodo, array $claves) {
    $res = ['fijo' => null, 'reservados' => [], 'error' => null];
    $formatos = [];
    $st = $bdd->prepare("SELECT id, consecutivo, fecha_venta, ids_pedidos FROM formatos_vd WHERE estado = " . FVD_ESTADO_ACTIVO . " AND id_colegio = ? AND id_periodo = ? ORDER BY fecha_venta, consecutivo");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $porPedido = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $fx = ['id' => (int)$f['id'], 'consecutivo' => (int)$f['consecutivo'], 'fecha_venta' => $f['fecha_venta']];
        if (trim((string)$f['ids_pedidos']) === '') { $res['reservados'][] = $fx; continue; }
        foreach (explode(',', $f['ids_pedidos']) as $c) $porPedido[$c] = $fx;
    }
    $enFormato = [];
    $sinFormato = [];
    foreach ($claves as $c) {
        if (isset($porPedido[$c])) $enFormato[$porPedido[$c]['id']] = $porPedido[$c]; else $sinFormato[] = $c;
    }
    if (count($enFormato) > 1) {
        $res['error'] = 'Los pedidos elegidos están en formatos distintos (' . implode(', ', array_map(fn($f) => fvd_numero($f['consecutivo']), $enFormato)) . '): regístralos en devoluciones separadas.';
    } elseif ($enFormato && $sinFormato) {
        $f = reset($enFormato);
        $res['error'] = 'El ' . implode(', ', array_map('dvd_etiqueta', $sinFormato)) . ' no está en el ' . fvd_numero($f['consecutivo']) . ' como los demás pedidos elegidos: regístralo en otra devolución.';
    } elseif ($enFormato) {
        $res['fijo'] = reset($enFormato);
    }
    return $res;
}

/**
 * Valida la venta elegida al guardar la devolución ($idFormato; 0 = ninguna). Con consecutivos reservados disponibles es
 * obligatorio elegir uno. Devuelve el error o null.
 */
function svd_validar_venta(PDO $bdd, $idFormato, $idColegio, $idPeriodo, array $claves) {
    $v = svd_ventas_para_devolucion($bdd, $idColegio, $idPeriodo, $claves);
    if ($v['error']) return $v['error'];
    if ($v['fijo']) return null; // va al formato donde ya están sus pedidos
    if (!$v['reservados']) return null; // sin ventas reservadas: se crea el formato como antes
    if (!$idFormato) return 'Elige a qué venta (formato) corresponde esta devolución.';
    foreach ($v['reservados'] as $f) if ($f['id'] === (int)$idFormato) return null;
    return 'La venta elegida ya no está disponible (ya tiene pedidos o se anuló). Recarga la página y elige otra.';
}

/**
 * Llena el consecutivo reservado $idFormato con los pedidos $claves (los de la devolución): filas con lo despachado en WO
 * (fvd_propuesta), datos de la venta que falten y entrega dada por hecha (sin firmas); queda listo para vincular la
 * devolución. La fecha y el horario de la venta reservada no cambian. Devuelve ['ok','error'].
 */
function svd_llenar_reservado(PDO $bdd, $idFormato, array $claves) {
    $f = fvd_obtener($bdd, $idFormato);
    if (!$f || !$f['activo'] || !$f['reservado']) return ['ok' => false, 'error' => 'La venta elegida ya no está disponible (ya tiene pedidos o se anuló).'];
    $prop = fvd_propuesta($bdd, $claves);
    if (!$prop['ok']) return $prop;
    if ((int)$prop['id_colegio'] !== (int)$f['id_colegio'] || (int)$prop['id_periodo'] !== (int)$f['id_periodo']) return ['ok' => false, 'error' => 'La venta elegida es de otro colegio o período.'];
    $fijos = datos_fijos_formato_vd($bdd, $prop['claves']);
    $responsable = fvd_texto($fijos['responsable'] ?? responsable_venta_de_pedidos($bdd, $prop['claves']), 150);
    $r = fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_SOLICITUD, function () use ($bdd, $f, $prop, $responsable) {
        $st = $bdd->prepare("SELECT ids_pedidos FROM formatos_vd WHERE id = ?");
        $st->execute([(int)$f['id']]);
        if (trim((string)$st->fetchColumn()) !== '') return 'La venta elegida ya recibió pedidos de otra devolución. Recarga la página.';
        $bdd->prepare("UPDATE formatos_vd SET ids_pedidos = ?, automatico = 1, responsable = COALESCE(responsable, ?), updated_at = NOW() WHERE id = ?")
            ->execute([implode(',', $prop['claves']), $responsable !== '' ? $responsable : null, (int)$f['id']]);
        fvd_insertar_items($bdd, $f['id'], $prop['items']);
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_ENTREGADO, 'Pedidos ' . implode(', ', array_map('dvd_etiqueta', $prop['claves'])) . ' de la devolución; entrega = lo despachado (sin firmas)');
        return null;
    }, 'llenar consecutivo reservado');
    if (!$r['ok']) return $r;
    fvd_completar_datos_venta($bdd, $f['id']); // contacto, dirección y responsable que falten (la fecha y hora ya están)
    return ['ok' => true, 'error' => null];
}
