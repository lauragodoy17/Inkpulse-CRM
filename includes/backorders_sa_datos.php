<?php
/**
 * /includes/backorders_sa_datos.php
 * Módulo "Backorders sin adopción" (backorders_sa.php / backorders_sa_informe.php): solicitudes de
 * libros de un PEDIDO SIN ADOPCIÓN (pedidos2) y su cliente — desde 2026-10-20 se asocia el número
 * de pedido en vez de escoger el tercero suelto en World Office; el cliente sale del pedido. Mismo
 * seguimiento que los backorders de adopciones pero en tablas propias (no toca
 * includes/backorders_datos.php ni sus tablas).
 *
 *   backorders_sa             el backorder (id = consecutivo): libro, tercero, cantidad solicitada,
 *                             observaciones, quién/cuándo lo registró y, si se anuló, quién/cuándo/por qué.
 *   backorders_sa_atenciones  cada atención: cantidad, usuario, fecha y observaciones. Atender NO genera
 *                             pedidos (a pedido de la usuaria 2026-09-29): id_pedido2 queda NULL. Solo las
 *                             atenciones registradas antes de ese cambio traen un pedido sin adopción (pedidos2).
 *   backorders_sa_historial   auditoría de todo lo que le pasa al backorder (creación, atenciones, anulación).
 *
 * Nada se borra. La cantidad ATENDIDA es la suma de las atenciones, salvo las (antiguas) cuyo pedido
 * generado se anuló (pedidos2.estado = 3): esas vuelven a quedar pendientes. El estado se calcula
 * siempre a partir de eso (ver bsa_estado()), así nunca queda desincronizado.
 */

require_once(__DIR__ . '/api_wo_terceros.php');

const BSA_ESTADOS = [
    'pendiente' => 'Pendiente',
    'atendido'  => 'Atendido',
    'anulado'   => 'Anulado',
];

/** Solo administradores (1) y operadores (2), igual que "Backorders por pedido". */
function bsa_tiene_acceso() {
    return in_array(intval($_SESSION['tipo'] ?? 0), [1, 2], true);
}

// DDL: llamar ANTES de abrir cualquier transacción (en MySQL todo DDL hace commit implícito).
function bsa_asegurar_tablas(PDO $bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorders_sa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_libro INT NOT NULL,
        id_cliente INT NULL,
        tercero_id_wo INT NOT NULL,
        tercero_nombre VARCHAR(255) NOT NULL,
        tercero_identificacion VARCHAR(50) NOT NULL DEFAULT '',
        cantidad_solicitada INT NOT NULL,
        observaciones TEXT NULL,
        fecha_registro DATETIME NOT NULL,
        id_usuario_registro INT NOT NULL,
        anulado TINYINT(1) NOT NULL DEFAULT 0,
        fecha_anulacion DATETIME NULL,
        id_usuario_anulacion INT NULL,
        motivo_anulacion TEXT NULL,
        KEY idx_libro (id_libro),
        KEY idx_tercero (tercero_id_wo),
        KEY idx_fecha (fecha_registro)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorders_sa_atenciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_backorder INT NOT NULL,
        id_pedido2 INT NULL,
        cantidad INT NOT NULL,
        pendiente_antes INT NOT NULL,
        observaciones TEXT NULL,
        fecha DATETIME NOT NULL,
        id_usuario INT NOT NULL,
        KEY idx_backorder (id_backorder),
        KEY idx_pedido2 (id_pedido2)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorders_sa_historial (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_backorder INT NOT NULL,
        accion VARCHAR(30) NOT NULL,
        detalle TEXT NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL,
        KEY idx_backorder (id_backorder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Pedido sin adopción (pedidos2) al que pertenece el backorder (2026-10-20). Los registrados
    // antes de ese cambio quedan con NULL (solo tienen el tercero).
    if (!$bdd->query("SHOW COLUMNS FROM backorders_sa LIKE 'id_pedido2'")->fetch()) {
        $bdd->exec("ALTER TABLE backorders_sa ADD COLUMN id_pedido2 INT NULL AFTER id_libro, ADD KEY idx_pedido2 (id_pedido2)");
    }

    // Instalaciones creadas cuando atender generaba un pedido: id_pedido2 era obligatorio.
    $col = $bdd->query("SHOW COLUMNS FROM backorders_sa_atenciones LIKE 'id_pedido2'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strtoupper($col['Null']) === 'NO') {
        $bdd->exec("ALTER TABLE backorders_sa_atenciones MODIFY id_pedido2 INT NULL");
    }
}

function bsa_registrar_historial(PDO $bdd, $idBackorder, $accion, $detalle) {
    $bdd->prepare("INSERT INTO backorders_sa_historial (id_backorder, accion, detalle, id_usuario, fecha) VALUES (?, ?, ?, ?, NOW())")
        ->execute([(int)$idBackorder, $accion, $detalle, intval($_SESSION['id'] ?? 0)]);
}

/**
 * Estado a partir de lo solicitado/atendido y la marca de anulación. Una atención parcial sigue
 * "pendiente" (con menos saldo) hasta completar lo solicitado — no hay estado intermedio, a pedido
 * de la usuaria 2026-09-29.
 */
function bsa_estado($solicitada, $atendida, $anulado) {
    if ($anulado) return 'anulado';
    if ($atendida >= $solicitada) return 'atendido';
    return 'pendiente';
}

/**
 * Pedidos sin adopción ya REGISTRADOS ("consignados") en backorders: los que tienen al menos un
 * backorder no anulado. [id_pedido2 => [id_backorder, ...]]. Se deriva de backorders_sa (no hay
 * marca aparte): si se anulan todos sus backorders, el pedido vuelve a quedar libre. Pedido por la
 * usuaria 2026-10-20: al guardar el backorder el pedido queda registrado y no se vuelve a ofrecer.
 */
function bsa_pedidos2_registrados(PDO $bdd, array $idsPedido2 = null) {
    bsa_asegurar_tablas($bdd);
    $sql = "SELECT id_pedido2, id FROM backorders_sa WHERE id_pedido2 IS NOT NULL AND anulado = 0";
    $params = [];
    if ($idsPedido2 !== null) {
        if (!$idsPedido2) return [];
        $sql .= " AND id_pedido2 IN (" . implode(',', array_fill(0, count($idsPedido2), '?')) . ")";
        $params = array_map('intval', $idsPedido2);
    }
    $stmt = $bdd->prepare($sql . " ORDER BY id");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id_pedido2']][] = (int)$r['id'];
    return $out;
}

/** Etiqueta del tipo de pedido sin adopción (pedidos2.tipo), igual que pedido_sa.php. */
function bsa_tipo_pedido2($tipo) {
    return [1 => 'Venta', 2 => 'Muestras'][(int)$tipo] ?? 'Sin adopción';
}

/**
 * Buscador de pedidos sin adopción (pedidos2) para asociarlos a un backorder: por número de pedido,
 * colegio o cliente (nombre o identificación). Solo pedidos no anulados y CON cliente — el backorder
 * queda relacionado con el número de pedido y su cliente. Sin texto, los más recientes.
 */
function bsa_buscar_pedidos2(PDO $bdd, $texto, $limite = 30) {
    $texto = trim((string)$texto);
    bsa_asegurar_tablas($bdd);
    // Los ya registrados en backorders (con algún backorder no anulado) no se vuelven a ofrecer.
    $where = 'p.estado <> 3 AND p.cliente IS NOT NULL AND p.cliente <> 0
              AND NOT EXISTS (SELECT 1 FROM backorders_sa b WHERE b.id_pedido2 = p.id AND b.anulado = 0)';
    $params = [];
    if ($texto !== '') {
        $num = ltrim($texto, '#');
        $where .= ' AND (p.colegio LIKE ? OR c.cliente LIKE ? OR c.documento LIKE ?' . (ctype_digit($num) ? ' OR p.id = ?' : '') . ')';
        $params = ['%' . $texto . '%', '%' . $texto . '%', '%' . $texto . '%'];
        if (ctype_digit($num)) $params[] = (int)$num;
    }
    $stmt = $bdd->prepare("SELECT p.id, p.colegio, p.tipo, DATE(p.fecha) AS fecha, ep.estado AS estado,
                                  c.cliente, c.documento
                           FROM pedidos2 p
                           JOIN clientes c ON c.id = p.cliente
                           LEFT JOIN estados_pedidos ep ON ep.id = p.estado
                           WHERE $where
                           ORDER BY p.id DESC LIMIT " . (int)$limite);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Libros cargados en un pedido sin adopción (libros_pedidos2): [id_libro => ['libro','isbn','cantidad']].
 * Cantidad = la aprobada si el pedido ya la tiene, si no la solicitada; un libro repetido se suma.
 * Sirve para precargar las líneas del backorder y como TOPE: un backorder no puede pedir más de lo
 * que se solicitó en el pedido (pedido por la usuaria 2026-10-20).
 */
function bsa_libros_pedido2(PDO $bdd, $idPedido2) {
    $stmt = $bdd->prepare("SELECT l.id, l.libro, l.isbn,
                                  SUM(IF(lp.cantidad_aprob REGEXP '^[0-9]+$' AND lp.cantidad_aprob > 0, lp.cantidad_aprob, lp.cantidad)) AS cantidad
                           FROM pedidos2 p
                           JOIN libros_pedidos2 lp ON lp.cod_pedido = p.codigo
                           JOIN libros l ON l.id = lp.id_libro
                           WHERE p.id = ?
                           GROUP BY l.id, l.libro, l.isbn
                           HAVING cantidad > 0
                           ORDER BY MIN(lp.id)");
    $stmt->execute([(int)$idPedido2]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $out[(int)$l['id']] = ['libro' => $l['libro'], 'isbn' => (string)$l['isbn'], 'cantidad' => (int)$l['cantidad']];
    }
    return $out;
}

/**
 * Pedido sin adopción y su cliente, confirmados en el CRM (no se confía en lo que mande el
 * navegador). Devuelve ['error' => '...'] si no se puede usar.
 */
function bsa_pedido2_confirmado(PDO $bdd, $idPedido2) {
    $stmt = $bdd->prepare("SELECT p.id, p.estado, p.colegio, p.tipo, p.cliente AS id_cliente,
                                  c.cliente, c.documento, c.id_wo
                           FROM pedidos2 p LEFT JOIN clientes c ON c.id = p.cliente
                           WHERE p.id = ?");
    $stmt->execute([(int)$idPedido2]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['error' => 'El pedido sin adopción #' . (int)$idPedido2 . ' no existe.'];
    if ((int)$p['estado'] === 3) return ['error' => 'El pedido #' . $p['id'] . ' está anulado.'];
    if (empty($p['id_cliente']) || $p['cliente'] === null) return ['error' => 'El pedido #' . $p['id'] . ' no tiene cliente asociado.'];
    return [
        'id_pedido2' => (int)$p['id'],
        'colegio' => (string)$p['colegio'],
        'tipo' => bsa_tipo_pedido2($p['tipo']),
        'id_cliente' => (int)$p['id_cliente'],
        'nombre' => trim((string)$p['cliente']),
        'identificacion' => trim((string)$p['documento']),
        'id_wo' => (int)$p['id_wo'],
    ];
}

/**
 * Busca terceros EN VIVO en World Office (listarTerceros): por identificación si el texto es
 * numérico, si no por nombre (contiene). Devuelve [['id_wo','nombre','identificacion','ciudad'], ...]
 * o null si World Office no respondió.
 */
function bsa_buscar_terceros_wo($texto, $limite = 20) {
    $texto = trim($texto);
    if (mb_strlen($texto) < 2) return [];
    $filtro = ctype_digit($texto)
        ? crear_filtro_api('identificacion', $texto, 0, 1)
        : crear_filtro_api('nombreCompleto', mb_strtoupper($texto, 'UTF-8'), 0, 1);
    $r = listar_terceros([$filtro], 0, $limite, 'ASC');
    $status = $r['status'] ?? 'error';
    if ($status === 'NOT_FOUND') return []; // World Office responde así cuando no hay coincidencias
    if (!in_array($status, ['OK', 'ACCEPTED'], true)) return null;
    $out = [];
    foreach ($r['data']['content'] ?? [] as $f) {
        $out[] = [
            'id_wo' => (int)($f['id'] ?? 0),
            'nombre' => trim((string)($f['nombreCompleto'] ?? '')),
            'identificacion' => trim((string)($f['identificacion'] ?? '')),
            'ciudad' => trim((string)($f['ubicacionCiudad'] ?? '')),
        ];
    }
    return $out;
}

/**
 * Datos confirmados de un tercero directo desde World Office (no se confía en lo que mande el
 * navegador) y su id en la tabla local `clientes` (catálogo World Office del CRM, el mismo que usan
 * los pedidos): se busca por id_wo, luego por documento, y si no existe se registra — mismo
 * formato que php/cruzar_terceros.php. Devuelve null si World Office no lo encuentra.
 */
function bsa_tercero_confirmado(PDO $bdd, $idWo) {
    $idWo = (int)$idWo;
    if ($idWo <= 0) return null;
    $r = consultar_tercero($idWo);
    $d = $r['data'] ?? null;
    if (!in_array($r['status'] ?? '', ['OK', 'ACCEPTED'], true) || !$d) return null;
    $nombre = trim((string)($d['nombreCompleto'] ?? ''));
    $identificacion = trim((string)($d['identificacion'] ?? ''));
    $ciudad = trim((string)($d['ciudad']['nombre'] ?? ''));
    if ($nombre === '') return null;

    $stmt = $bdd->prepare("SELECT id FROM clientes WHERE id_wo = ? ORDER BY id LIMIT 1");
    $stmt->execute([$idWo]);
    $idCliente = $stmt->fetchColumn();
    if (!$idCliente && $identificacion !== '') {
        $stmt = $bdd->prepare("SELECT id FROM clientes WHERE TRIM(documento) = ? ORDER BY id LIMIT 1");
        $stmt->execute([$identificacion]);
        $idCliente = $stmt->fetchColumn();
    }
    if (!$idCliente) {
        $bdd->prepare("INSERT INTO clientes (cliente, documento, direccion, telefonos, ciudad, id_wo, created_at, updated_at)
                       VALUES (?, ?, '', '', ?, ?, NOW(), NOW())")
            ->execute([$nombre, $identificacion, $ciudad, $idWo]);
        $idCliente = $bdd->lastInsertId();
    }
    return ['id_wo' => $idWo, 'nombre' => $nombre, 'identificacion' => $identificacion, 'id_cliente' => (int)$idCliente];
}

/**
 * Backorders con sus cantidades, estado, atenciones e historial, filtrados. $f:
 *   libro (texto: título o ISBN), tercero (texto: nombre o identificación), estado (clave de
 *   BSA_ESTADOS), desde / hasta (Y-m-d, sobre la fecha de registro), id (uno puntual).
 * Devuelve ['filas' => [...], 'totales' => [...]].
 */
function bsa_listar(PDO $bdd, array $f = []) {
    bsa_asegurar_tablas($bdd);
    $where = ['1=1'];
    $params = [];
    if (!empty($f['id'])) { $where[] = 'b.id = ?'; $params[] = (int)$f['id']; }
    if (($f['libro'] ?? '') !== '') {
        $where[] = '(l.libro LIKE ? OR l.isbn LIKE ?)';
        $params[] = '%' . $f['libro'] . '%';
        $params[] = '%' . $f['libro'] . '%';
    }
    if (($f['tercero'] ?? '') !== '') {
        // Cliente (nombre o identificación) o número del pedido sin adopción.
        $num = ltrim($f['tercero'], '#');
        $where[] = '(b.tercero_nombre LIKE ? OR b.tercero_identificacion LIKE ?' . (ctype_digit($num) ? ' OR b.id_pedido2 = ?' : '') . ')';
        $params[] = '%' . $f['tercero'] . '%';
        $params[] = '%' . $f['tercero'] . '%';
        if (ctype_digit($num)) $params[] = (int)$num;
    }
    if (($f['desde'] ?? '') !== '') { $where[] = 'b.fecha_registro >= ?'; $params[] = $f['desde'] . ' 00:00:00'; }
    if (($f['hasta'] ?? '') !== '') { $where[] = 'b.fecha_registro <= ?'; $params[] = $f['hasta'] . ' 23:59:59'; }

    $stmt = $bdd->prepare("SELECT b.*, l.libro, l.isbn, p2.estado AS estado_pedido2,
                                  CONCAT(TRIM(ur.nombres), ' ', TRIM(ur.apellidos)) AS usuario_registro,
                                  CONCAT(TRIM(ua.nombres), ' ', TRIM(ua.apellidos)) AS usuario_anulacion,
                                  (SELECT COALESCE(SUM(a.cantidad), 0) FROM backorders_sa_atenciones a
                                     LEFT JOIN pedidos2 p ON p.id = a.id_pedido2
                                    WHERE a.id_backorder = b.id AND (a.id_pedido2 IS NULL OR p.estado <> 3)) AS cantidad_atendida
                           FROM backorders_sa b
                           LEFT JOIN libros l ON l.id = b.id_libro
                           LEFT JOIN pedidos2 p2 ON p2.id = b.id_pedido2
                           LEFT JOIN usuarios ur ON ur.id = b.id_usuario_registro
                           LEFT JOIN usuarios ua ON ua.id = b.id_usuario_anulacion
                           WHERE " . implode(' AND ', $where) . "
                           ORDER BY b.id DESC");
    $stmt->execute($params);

    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $sol = (int)$b['cantidad_solicitada'];
        $at = (int)$b['cantidad_atendida'];
        $estado = bsa_estado($sol, $at, (int)$b['anulado'] === 1);
        if (($f['estado'] ?? '') !== '' && $f['estado'] !== $estado) continue;
        // Vista "Administrar": los ya atendidos salen de la lista (siguen en el informe).
        if (!empty($f['ocultar_atendidos']) && $estado === 'atendido') continue;
        $filas[(int)$b['id']] = [
            'id' => (int)$b['id'],
            'id_libro' => (int)$b['id_libro'],
            'libro' => $b['libro'] ?? ('Libro #' . $b['id_libro']),
            'isbn' => (string)($b['isbn'] ?? ''),
            'id_cliente' => $b['id_cliente'] !== null ? (int)$b['id_cliente'] : null,
            'id_pedido2' => $b['id_pedido2'] !== null ? (int)$b['id_pedido2'] : null,
            'estado_pedido2' => $b['estado_pedido2'] !== null ? (int)$b['estado_pedido2'] : null,
            'tercero_id_wo' => (int)$b['tercero_id_wo'],
            'tercero' => $b['tercero_nombre'],
            'tercero_identificacion' => $b['tercero_identificacion'],
            'cantidad_solicitada' => $sol,
            'cantidad_atendida' => $at,
            // Un backorder anulado ya no tiene nada pendiente por atender.
            'cantidad_pendiente' => $estado === 'anulado' ? 0 : max(0, $sol - $at),
            'estado' => $estado,
            'estado_label' => BSA_ESTADOS[$estado],
            'observaciones' => (string)$b['observaciones'],
            'fecha_registro' => $b['fecha_registro'],
            'usuario_registro' => trim($b['usuario_registro'] ?? '') ?: '—',
            'fecha_anulacion' => $b['fecha_anulacion'],
            'usuario_anulacion' => trim($b['usuario_anulacion'] ?? ''),
            'motivo_anulacion' => (string)$b['motivo_anulacion'],
            'atenciones' => [],
            'historial' => [],
        ];
    }

    if ($filas) {
        $ids = array_keys($filas);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmtA = $bdd->prepare("SELECT a.*, p.estado AS estado_pedido, ep.estado AS estado_pedido_nombre,
                                       CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                                FROM backorders_sa_atenciones a
                                LEFT JOIN pedidos2 p ON p.id = a.id_pedido2
                                LEFT JOIN estados_pedidos ep ON ep.id = p.estado
                                LEFT JOIN usuarios u ON u.id = a.id_usuario
                                WHERE a.id_backorder IN ($ph) ORDER BY a.id");
        $stmtA->execute($ids);
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $filas[(int)$a['id_backorder']]['atenciones'][] = [
                'id_pedido2' => $a['id_pedido2'] !== null ? (int)$a['id_pedido2'] : null,
                'cantidad' => (int)$a['cantidad'],
                'pendiente_antes' => (int)$a['pendiente_antes'],
                'fecha' => $a['fecha'],
                'usuario' => trim($a['usuario'] ?? '') ?: '—',
                'estado_pedido' => $a['estado_pedido_nombre'] ?: '',
                'estado_pedido_id' => (int)$a['estado_pedido'],
                'pedido_anulado' => $a['id_pedido2'] !== null && (int)$a['estado_pedido'] === 3,
                'observaciones' => (string)$a['observaciones'],
            ];
        }
        $stmtH = $bdd->prepare("SELECT h.*, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                                FROM backorders_sa_historial h LEFT JOIN usuarios u ON u.id = h.id_usuario
                                WHERE h.id_backorder IN ($ph) ORDER BY h.id");
        $stmtH->execute($ids);
        foreach ($stmtH->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $filas[(int)$h['id_backorder']]['historial'][] = [
                'accion' => $h['accion'], 'detalle' => (string)$h['detalle'],
                'usuario' => trim($h['usuario'] ?? '') ?: '—', 'fecha' => $h['fecha'],
            ];
        }
    }

    $filas = array_values($filas);
    $totales = ['backorders' => count($filas), 'solicitada' => 0, 'atendida' => 0, 'pendiente' => 0,
                'por_estado' => array_fill_keys(array_keys(BSA_ESTADOS), 0)];
    foreach ($filas as $r) {
        $totales['solicitada'] += $r['cantidad_solicitada'];
        $totales['atendida'] += $r['cantidad_atendida'];
        $totales['pendiente'] += $r['cantidad_pendiente'];
        $totales['por_estado'][$r['estado']]++;
    }
    return ['filas' => $filas, 'totales' => $totales];
}

function bsa_filtros_desde_request(array $src) {
    $fecha = function ($v) {
        $v = trim((string)$v);
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : '';
    };
    $estado = trim((string)($src['estado'] ?? ''));
    return [
        'libro' => trim((string)($src['libro'] ?? '')),
        'tercero' => trim((string)($src['tercero'] ?? '')),
        'estado' => isset(BSA_ESTADOS[$estado]) ? $estado : '',
        'desde' => $fecha($src['desde'] ?? ''),
        'hasta' => $fecha($src['hasta'] ?? ''),
        'ocultar_atendidos' => ($src['ocultar_atendidos'] ?? '') === '1',
    ];
}
