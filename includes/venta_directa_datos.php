<?php
/**
 * /includes/venta_directa_datos.php
 * Módulo "Venta directa" (pedido de la usuaria 2026-10-14): mismo flujo que Pedidos de venta
 * (colegio + adopción, aprobar, procesar, facturación, despacho, entregado, anular con motivo),
 * pero con sus PROPIAS tablas para no mezclar registros con `pedidos`:
 *   pedidos_vd          — encabezado (mismas columnas útiles que `pedidos`)
 *   libros_pedidos_vd   — títulos del pedido (ligados por id, no por un código aleatorio)
 * Sin "Agrupar pedidos". La factura/remisión de World Office se reconoce porque su concepto trae "VD-<id>"
 * (wo_documentos_por_venta_directa en includes/wo_documentos_por_op.php), así no se cruza con los números de
 * pedidos, muestreos ni pedidos sin adopción.
 *
 * OP de venta directa (pedido de la usuaria 2026-10-14: "diferentes a las que ya se llevan"): usan la misma
 * tabla ordenes_pedidos y el mismo flujo de despacho (op_pendiente.php, formato_op.php), pero:
 *   - numeración PROPIA: ordenes_pedidos.consecutivo_vd (1, 2, 3...) → se muestran como "OPVD-<n>"
 *     (vd_etiqueta_op), nunca como "OP <año>-<id>";
 *   - ordenes_pedidos.id_pedido_vd liga la OP con su venta directa (0 en las demás OP);
 *   - lista propia (lista_op.php?vd=1); la lista de OP de siempre no las muestra;
 *   - en WO se buscan por "VD-<id>", no por "OP <año>-<id>" (ajax/wo_buscar_documento_op.php).
 *
 * Estados: los mismos de `estados_pedidos` (1 Pendiente, 2 Aprobado, 3 Anulado, 4 Entregado,
 * 5 Procesando, 6 Facturación, 7 En despacho) y el mismo mapeo ?tp= de lista_pedidos.php.
 */

const VD_MODULO_HISTORIAL = 'venta_directa';   // historial_estados.modulo
const VD_ESTADO_PENDIENTE = 1;
const VD_ESTADO_APROBADO = 2;
const VD_ESTADO_ANULADO = 3;
const VD_ESTADO_ENTREGADO = 4;
const VD_ESTADO_PROCESANDO = 5;
const VD_ESTADO_FACTURACION = 6;
const VD_ESTADO_DESPACHO = 7;

/** ?tp= de las listas → estado (igual que lista_pedidos_estado_val()). */
const VD_TP_ESTADO = [2 => 1, 3 => 2, 4 => 4, 5 => 3, 6 => 5, 7 => 6, 8 => 7];

/**
 * Crea las tablas si faltan. Barato cuando ya existen (una sola consulta).
 * Debe llamarse ANTES de abrir transacciones (el DDL hace commit implícito en MySQL).
 */
function vd_asegurar_tablas(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;

    if (!$bdd->query("SHOW COLUMNS FROM ordenes_pedidos LIKE 'consecutivo_vd'")->fetch()) {
        foreach (["ALTER TABLE ordenes_pedidos ADD COLUMN id_pedido_vd INT NOT NULL DEFAULT 0, ADD INDEX idx_op_pedido_vd (id_pedido_vd)",
                  "ALTER TABLE ordenes_pedidos ADD COLUMN consecutivo_vd INT NULL DEFAULT NULL, ADD UNIQUE INDEX uniq_op_consecutivo_vd (consecutivo_vd)"] as $sql) {
            try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
        }
    }
    if ($bdd->query("SHOW TABLES LIKE 'libros_pedidos_vd'")->fetchColumn()) return;

    $bdd->exec("CREATE TABLE IF NOT EXISTS pedidos_vd (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_periodo INT NOT NULL,
        id_colegio INT NOT NULL,
        id_usuario INT NOT NULL,
        fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_r DATE NULL,
        dir_ent VARCHAR(500) NOT NULL DEFAULT '',
        observaciones TEXT NULL,
        cliente INT NOT NULL DEFAULT 0,
        fac_rem INT NOT NULL DEFAULT 0,
        tipo INT NOT NULL DEFAULT 0,
        estado INT NOT NULL DEFAULT 1,
        fecha_impre DATETIME NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        KEY idx_vd_colegio (id_colegio, id_periodo),
        KEY idx_vd_estado (estado),
        KEY idx_vd_usuario (id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $bdd->exec("CREATE TABLE IF NOT EXISTS libros_pedidos_vd (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pedido_vd INT NOT NULL,
        id_libro INT NOT NULL,
        cod_area VARCHAR(12) CHARACTER SET utf8 COLLATE utf8_spanish_ci NOT NULL DEFAULT '', -- como presupuestos.cod_area
        cantidad INT NOT NULL DEFAULT 0,
        cantidad_aprob INT NULL,
        descuento_aprob DECIMAL(6,2) NULL,
        plataforma TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        KEY idx_lvd_pedido (id_pedido_vd),
        KEY idx_lvd_libro (id_libro),
        CONSTRAINT fk_lvd_pedido FOREIGN KEY (id_pedido_vd) REFERENCES pedidos_vd(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Cortar si no hay sesión (php/aut.php redirige sin exit). Mismos usuarios que ven el menú Pedidos. */
function vd_validar_acceso($json = false, $raiz = '') {
    $sesion = ($_SESSION['autentificado'] ?? '') === 'SI';
    if ($sesion && !in_array((int)($_SESSION['tipo'] ?? 0), [4, 5, 8], true)) return;
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $sesion ? 'No tienes acceso a este módulo.' : 'Tu sesión terminó, vuelve a iniciar sesión.']);
    } elseif ($sesion) {
        header('Location: ' . $raiz . 'index.php');
    }
    exit;
}

/** Quién aprueba / cambia estados: los mismos de pedido_colegio.php ($can_act). */
function vd_puede_gestionar() {
    $tipo = (int)($_SESSION['tipo'] ?? 0);
    return in_array($tipo, [1, 2, 10], true) || (int)($_SESSION['id'] ?? 0) === 21;
}

/** Asesores y distribuidores (tipo 3/6) solo ven y solicitan lo suyo, como en ver_pedidos.php. */
function vd_es_asesor() {
    return in_array((int)($_SESSION['tipo'] ?? 0), [3, 6], true);
}

/** Etiqueta visible del número, para no confundirlo con un pedido de venta: "VD-15". */
function vd_numero($id) {
    return 'VD-' . (int)$id;
}

/**
 * Alcance por usuario para listas (alias pe = pedidos_vd, c = colegios). Igual que
 * lista_pedidos_query_parts(): tipo 10 ve todo; el resto su zona o zona madre; asesores
 * (tipo 3/6) además solo lo que pidieron ellos.
 */
function vd_alcance_sql() {
    $tipo = (int)($_SESSION['tipo'] ?? 0);
    if (vd_es_asesor()) return [' AND pe.id_usuario = ?', [(int)$_SESSION['id']]];
    if ($tipo === 10 || $tipo === 1 || $tipo === 2) return ['', []];
    $zona = (string)($_SESSION['zona'] ?? '');
    return [' AND (c.cod_zona = ? OR c.zona_madre = ?)', [$zona, $zona]];
}

/** Libros adoptados (presupuesto definido) del colegio/período, con compradores activos. */
function vd_libros_disponibles(PDO $bdd, $idColegio, $idPeriodo) {
    $stmt = $bdd->prepare(
        "SELECT l.id, l.id_grado, l.libro, p.tasa_compra, p.tasa_compra_d, m.materia, p.cod_area,
                (SELECT ao.id_grado_otro FROM areas_objetivas ao WHERE ao.codigo = p.cod_area AND p.cod_area <> '' LIMIT 1) AS id_grado_otro
         FROM libros l
         JOIN presupuestos p ON l.id = p.id_libro
         JOIN materias m ON l.id_materia = m.id
         WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = '1'
         ORDER BY l.libro");
    $stmt->execute([(int)$idColegio, (int)$idPeriodo]);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grados = $bdd->query("SELECT id, grado FROM grados")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stAlm = $bdd->prepare("SELECT COALESCE(SUM(alumnos), 0) FROM grados_paralelos WHERE id_grado = ? AND id_periodo = ? AND id_colegio = ?");
    foreach ($libros as &$l) {
        $idGrado = (int)$l['id_grado_otro'] ?: (int)$l['id_grado'];
        $stAlm->execute([$idGrado, (int)$idPeriodo, (int)$idColegio]);
        $alumnos = (float)$stAlm->fetchColumn();
        $tasa = ((float)$l['tasa_compra_d'] == 0.0) ? (float)$l['tasa_compra'] : (float)$l['tasa_compra_d'];
        $l['grado'] = $grados[$idGrado] ?? '—';
        $l['compradores'] = (int)floor($alumnos * $tasa);
        $l['clave'] = $l['cod_area'] !== '' ? $l['cod_area'] : (string)$l['id'];
    }
    unset($l);
    return $libros;
}

/**
 * Crea el pedido. $lineas = [['id_libro','cod_area','cantidad','plataforma'], ...]; solo se aceptan
 * libros adoptados del colegio/período y cantidades entre 1 y los compradores activos (si hay).
 * Devuelve ['ok','error','id'].
 */
function vd_crear_pedido(PDO $bdd, array $datos, array $lineas) {
    $idColegio = (int)($datos['id_colegio'] ?? 0);
    $idPeriodo = (int)($datos['id_periodo'] ?? 0);
    $cliente = (int)($datos['cliente'] ?? 0);
    $facRem = (int)($datos['fac_rem'] ?? 0);
    if (!$idColegio || !$idPeriodo) return ['ok' => false, 'error' => 'Falta el colegio o el período.'];
    if ($cliente <= 0) return ['ok' => false, 'error' => 'Selecciona el cliente.'];
    if (!in_array($facRem, [1, 2], true)) return ['ok' => false, 'error' => 'Indica si es factura o remisión.'];
    $fechaR = (string)($datos['fecha_r'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaR)) return ['ok' => false, 'error' => 'La fecha de recogida no es válida.'];
    $dirEnt = trim((string)($datos['dir_ent'] ?? ''));
    if ($dirEnt === '') return ['ok' => false, 'error' => 'Escribe la dirección de entrega.'];

    $disponibles = [];
    foreach (vd_libros_disponibles($bdd, $idColegio, $idPeriodo) as $l) $disponibles[$l['id'] . '|' . $l['cod_area']] = $l;

    $validas = [];
    foreach ($lineas as $ln) {
        $cant = (int)($ln['cantidad'] ?? 0);
        if ($cant <= 0) continue;
        $clave = (int)$ln['id_libro'] . '|' . (string)($ln['cod_area'] ?? '');
        if (!isset($disponibles[$clave])) return ['ok' => false, 'error' => 'Uno de los libros no está adoptado en este colegio para el período.'];
        $max = $disponibles[$clave]['compradores'];
        if ($max > 0 && $cant > $max) {
            return ['ok' => false, 'error' => 'La cantidad de "' . $disponibles[$clave]['libro'] . '" no puede ser mayor a los compradores activos (' . $max . ').'];
        }
        $validas[] = ['id_libro' => (int)$ln['id_libro'], 'cod_area' => (string)($ln['cod_area'] ?? ''), 'cantidad' => $cant, 'plataforma' => !empty($ln['plataforma']) ? 1 : 0];
    }
    if (!$validas) return ['ok' => false, 'error' => 'Ingresa la cantidad de al menos un libro.'];

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $bdd->prepare("INSERT INTO pedidos_vd (id_periodo, id_colegio, id_usuario, fecha_r, dir_ent, observaciones, cliente, fac_rem, tipo, estado, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . VD_ESTADO_PENDIENTE . ", NOW())")
            ->execute([$idPeriodo, $idColegio, (int)$_SESSION['id'], $fechaR, mb_substr($dirEnt, 0, 500),
                       mb_substr(trim((string)($datos['observaciones'] ?? '')), 0, 300), $cliente, $facRem, (int)($datos['tipo'] ?? 0)]);
        $id = (int)$bdd->lastInsertId();
        $st = $bdd->prepare("INSERT INTO libros_pedidos_vd (id_pedido_vd, id_libro, cod_area, cantidad, plataforma, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($validas as $v) $st->execute([$id, $v['id_libro'], $v['cod_area'], $v['cantidad'], $v['plataforma']]);
        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id' => $id];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Venta directa: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el pedido: ' . $e->getMessage()];
    }
}

/** Encabezado del pedido con colegio, zona, usuario y estado, dentro del alcance del usuario; o null. */
function vd_obtener_pedido(PDO $bdd, $id) {
    [$alcance, $params] = vd_alcance_sql();
    $stmt = $bdd->prepare(
        "SELECT pe.*, pe.tipo AS petipo, z.codigo AS codzona, z.zona, c.colegio, c.sub_zona, c.responsable, c.cod_zona,
                cal.calendario, per.periodo, e.estado AS estado_nombre, cl.cliente AS cliente_nombre, cl.id_wo AS cliente_id_wo,
                CONCAT(TRIM(us.nombres), ' ', TRIM(us.apellidos)) AS solicitante,
                (SELECT u.tipo FROM usuarios u WHERE u.cod_zona = z.codigo LIMIT 1) AS tipo_zona,
                (SELECT CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) FROM usuarios u WHERE u.cod_zona = z.codigo LIMIT 1) AS usuario_zona
         FROM pedidos_vd pe
         JOIN colegios c ON c.id = pe.id_colegio
         LEFT JOIN zonas z ON z.codigo = c.cod_zona
         LEFT JOIN calendarios cal ON cal.id = c.id_calendario
         LEFT JOIN periodos per ON per.id = pe.id_periodo
         LEFT JOIN estados_pedidos e ON e.id = pe.estado
         LEFT JOIN clientes cl ON cl.id = pe.cliente
         LEFT JOIN usuarios us ON us.id = pe.id_usuario
         WHERE pe.id = ?" . $alcance);
    $stmt->execute(array_merge([(int)$id], $params));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Títulos del pedido con precio, descuento del presupuesto y valor (misma fórmula que
 * pedido_colegio.php: precio − precio × descuento_d, o descuento si descuento_d es 0).
 */
function vd_libros_pedido(PDO $bdd, array $pedido) {
    $stmt = $bdd->prepare(
        "SELECT lv.id AS lpid, lv.id_libro AS libroid, lv.cod_area, lv.cantidad, lv.cantidad_aprob, lv.descuento_aprob, lv.plataforma,
                l.libro, l.isbn, l.precio, l.id_grado, l.id_wo, m.materia,
                (SELECT p.descuento FROM presupuestos p WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.id_libro = lv.id_libro
                   AND COALESCE(p.cod_area, '') = lv.cod_area LIMIT 1) AS descuento,
                (SELECT p.descuento_d FROM presupuestos p WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.id_libro = lv.id_libro
                   AND COALESCE(p.cod_area, '') = lv.cod_area LIMIT 1) AS descuento_d,
                (SELECT ao.id_grado_otro FROM areas_objetivas ao WHERE ao.codigo = lv.cod_area AND lv.cod_area <> '' LIMIT 1) AS id_grado_otro
         FROM libros_pedidos_vd lv
         JOIN libros l ON l.id = lv.id_libro
         LEFT JOIN materias m ON m.id = l.id_materia
         WHERE lv.id_pedido_vd = ? AND lv.cantidad <> 0
         ORDER BY lv.id");
    $c = (int)$pedido['id_colegio'];
    $p = (int)$pedido['id_periodo'];
    $stmt->execute([$c, $p, $c, $p, (int)$pedido['id']]);
    $grados = $bdd->query("SELECT id, grado FROM grados")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ubic = $bdd->prepare("SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion, lu.posicion
                           FROM lugares l JOIN ubicaciones u ON l.id = u.id_lugar JOIN libros_ubicaciones lu ON u.id = lu.ubicacion
                           WHERE lu.id_libro = ?");
    $libros = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lb) {
        $ubic->execute([$lb['libroid']]);
        $ubi = [];
        foreach ($ubic->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $ubi[] = ((int)$u['id_tipo'] === 1) ? $u['lugar'] . $u['piso'] . ' Pallet ' . $u['ubicacion'] . ' ' . $u['posicion'] : $u['lugar'] . ' Bandeja ' . $u['ubicacion'];
        }
        $desc = ((string)$lb['descuento_d'] === '0.0000' || $lb['descuento_d'] === null) ? (float)$lb['descuento'] : (float)$lb['descuento_d'];
        $precioFact = (float)$lb['precio'] - (float)$lb['precio'] * $desc;
        $lb['grado'] = $grados[(int)$lb['id_grado_otro'] ?: (int)$lb['id_grado']] ?? '—';
        $lb['ubi'] = implode(', ', $ubi);
        $lb['desc_pct'] = $desc * 100;
        $lb['precio_fact'] = $precioFact;
        $lb['v_venta'] = $precioFact * (int)$lb['cantidad'];
        $libros[] = $lb;
    }
    return $libros;
}

/**
 * Números de venta directa escritos en un concepto de WO: "VD-15", "VD - 15", "vd-15". El número debe
 * terminar ahí (VD-15 no es VD-150) y "OPVD-3" no cuenta como venta directa 3.
 */
function vd_ids_en_concepto($concepto) {
    preg_match_all('/(?<![A-Z0-9])VD\s*-\s*(\d+)(?!\d)/i', (string)$concepto, $m);
    return array_map('intval', $m[1]);
}

/** Número visible de una OP de venta directa: "OPVD-7". */
function vd_etiqueta_op($consecutivo) {
    return 'OPVD-' . (int)$consecutivo;
}

/** OP activa (no anulada) de la venta directa: ['id','consecutivo_vd','etiqueta','n_doc','estado'] o null. */
function vd_op_activa(PDO $bdd, $idPedido) {
    $st = $bdd->prepare("SELECT id, consecutivo_vd, n_doc, estado FROM ordenes_pedidos WHERE id_pedido_vd = ? AND estado <> 4 ORDER BY id LIMIT 1");
    $st->execute([(int)$idPedido]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
    if ($op) $op['etiqueta'] = vd_etiqueta_op($op['consecutivo_vd']);
    return $op ?: null;
}

/**
 * Asigna a la OP recién creada el siguiente consecutivo de venta directa (numeración propia, independiente
 * del id de ordenes_pedidos). El índice UNIQUE impide repetir un número si dos se crean a la vez: se reintenta.
 */
function vd_asignar_consecutivo_op(PDO $bdd, $idOp, $idPedidoVd) {
    for ($intento = 0; $intento < 5; $intento++) {
        try {
            $sig = (int)$bdd->query("SELECT COALESCE(MAX(consecutivo_vd), 0) + 1 FROM ordenes_pedidos")->fetchColumn();
            $bdd->prepare("UPDATE ordenes_pedidos SET id_pedido_vd = ?, consecutivo_vd = ? WHERE id = ?")->execute([(int)$idPedidoVd, $sig, (int)$idOp]);
            return $sig;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
        }
    }
    throw new RuntimeException('No se pudo asignar el número de OP de venta directa.');
}

/**
 * Cambia el estado validando que venga del estado anterior correcto (evita saltos y dobles clics).
 * $accion: aprobar | procesar | facturacion | despacho | entregado | anular. Devuelve ['ok','error','estado'].
 */
function vd_cambiar_estado(PDO $bdd, $id, $accion, array $extra = []) {
    $transiciones = [
        'aprobar'     => [[VD_ESTADO_PENDIENTE], VD_ESTADO_APROBADO],
        'procesar'    => [[VD_ESTADO_APROBADO], VD_ESTADO_PROCESANDO],
        'facturacion' => [[VD_ESTADO_PROCESANDO], VD_ESTADO_FACTURACION],
        'despacho'    => [[VD_ESTADO_FACTURACION], VD_ESTADO_DESPACHO],
        'entregado'   => [[VD_ESTADO_DESPACHO], VD_ESTADO_ENTREGADO],
        'anular'      => [[VD_ESTADO_PENDIENTE, VD_ESTADO_APROBADO, VD_ESTADO_PROCESANDO], VD_ESTADO_ANULADO],
    ];
    if (!isset($transiciones[$accion])) return ['ok' => false, 'error' => 'Acción no reconocida.'];
    [$desde, $hacia] = $transiciones[$accion];

    // Igual que pedido_colegio.php: en Procesando solo administración (y usuarios 21/25) puede anular.
    if ($accion === 'anular' && !in_array((int)$_SESSION['tipo'], [1], true) && !in_array((int)$_SESSION['id'], [21, 25], true)) {
        $desde = [VD_ESTADO_PENDIENTE, VD_ESTADO_APROBADO];
    }

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $st = $bdd->prepare("SELECT estado FROM pedidos_vd WHERE id = ? FOR UPDATE");
        $st->execute([(int)$id]);
        $actual = $st->fetchColumn();
        if ($actual === false) { $bdd->rollBack(); return ['ok' => false, 'error' => 'El pedido no existe.']; }
        if (!in_array((int)$actual, $desde, true)) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'El pedido ' . vd_numero($id) . ' ya cambió de estado; recarga la página.'];
        }

        if ($accion === 'aprobar') vd_guardar_aprobados($bdd, $id, $extra['lineas'] ?? []);
        if (array_key_exists('observaciones', $extra)) {
            $bdd->prepare("UPDATE pedidos_vd SET observaciones = ? WHERE id = ?")->execute([mb_substr((string)$extra['observaciones'], 0, 300), (int)$id]);
        }
        $bdd->prepare("UPDATE pedidos_vd SET estado = ?, updated_at = NOW() WHERE id = ?")->execute([$hacia, (int)$id]);
        registrar_historial_estado($bdd, VD_MODULO_HISTORIAL, $id, $hacia, (int)$_SESSION['id'], $accion === 'anular' ? ($extra['motivo'] ?? null) : null);
        $bdd->commit();
        return ['ok' => true, 'error' => null, 'estado' => $hacia];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        return ['ok' => false, 'error' => 'No se pudo cambiar el estado: ' . $e->getMessage()];
    }
}

/** Cantidad y descuento aprobados por línea: [lpid => ['cantidad' => , 'descuento' => ]]. Vacío = NULL. */
function vd_guardar_aprobados(PDO $bdd, $idPedido, array $lineas) {
    $st = $bdd->prepare("UPDATE libros_pedidos_vd SET cantidad_aprob = ?, descuento_aprob = ?, updated_at = NOW() WHERE id = ? AND id_pedido_vd = ?");
    foreach ($lineas as $lpid => $ln) {
        $cant = trim((string)($ln['cantidad'] ?? ''));
        $desc = trim(str_replace(',', '.', (string)($ln['descuento'] ?? '')));
        $st->execute([
            $cant === '' ? null : max(0, (int)$cant),
            $desc === '' ? null : max(0, min(100, (float)$desc)),
            (int)$lpid, (int)$idPedido,
        ]);
    }
}

/** Correo de aviso de solicitud nueva — mismo remitente y destinatarios que php/pedido.php. */
function vd_notificar_solicitud(PDO $bdd, $idPedido) {
    $base = dirname(__DIR__);
    require_once $base . '/lib/PHPMailer/src/Exception.php';
    require_once $base . '/lib/PHPMailer/src/PHPMailer.php';
    require_once $base . '/lib/PHPMailer/src/SMTP.php';

    $st = $bdd->prepare("SELECT c.colegio, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS promotor
                         FROM pedidos_vd pe JOIN colegios c ON c.id = pe.id_colegio LEFT JOIN usuarios u ON u.id = pe.id_usuario WHERE pe.id = ?");
    $st->execute([(int)$idPedido]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'somoseureka.com.co';
        $mail->SMTPAuth = true;
        $mail->SMTPAutoTLS = false;
        $mail->Username = 'crm@somoseureka.com.co';
        $mail->Password = 'cRm14356$';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
        $mail->addAddress('felipe.vargas@somoseureka.com.co', 'felipe.vargas@somoseureka.com.co');
        $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
        $mail->addCC('comercial@somoseureka.com.co');
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Solicitud de venta directa ' . vd_numero($idPedido);
        $mail->Body = '<p style="font-size: 17px;">' . htmlspecialchars($d['promotor']) . ' hizo la solicitud de venta directa ' . vd_numero($idPedido)
            . ' para: ' . htmlspecialchars($d['colegio']) . '. Haz clic <a href="https://crm.somoseureka.com.co/venta_directa.php?id=' . (int)$idPedido . '">aquí</a> para revisarla</p>';
        $mail->send();
    } catch (\Throwable $e) {
        error_log('Venta directa ' . vd_numero($idPedido) . ': no se envió el correo: ' . $e->getMessage());
    }
}
