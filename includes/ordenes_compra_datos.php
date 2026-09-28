<?php
/**
 * /includes/ordenes_compra_datos.php
 * Órdenes de compra (World Office, documentoTipo "OC"): recepción de productos y backorders.
 *
 * La orden y sus renglones SIEMPRE se leen de World Office (obtener_detalle_impresion_wo(), ver
 * includes/api_wo_ventas.php) y nunca se modifican allá. Lo que registra el CRM va en tablas
 * propias:
 *   oc_ordenes          copia del encabezado de la orden + estado general de recepción (para el
 *                       listado y los backorders sin volver a consultar WO)
 *   oc_productos        un registro por renglón de la orden: solicitado (de WO) y recibido acumulado
 *   oc_recepciones      cada entrega registrada (fecha, usuario, observaciones) — historial
 *   oc_recepcion_items  cantidades de cada producto en cada entrega
 *   oc_backorders       UNO por producto con faltante: se crea la primera vez que el acumulado
 *                       queda por debajo de lo solicitado y después solo se actualiza (nunca se
 *                       duplica ni se borra, ni al completarse)
 *
 * Un renglón se identifica por (orden, item, código): el número de renglón de WO + el código del
 * producto, así un mismo código en dos renglones (ej. dos bodegas) no se mezcla.
 *
 * Estados por producto (S = solicitado, R = recibido acumulado): R = 0 pendiente, 0 < R < S parcial,
 * R = S completado, R > S excedente. De la orden: algún excedente → con excedentes; nada recibido →
 * pendiente de recepción; todo completo → recibida completamente; si no → recepción parcial.
 */

require_once(__DIR__ . '/api_wo_ventas.php');

const OC_ESTADOS_PRODUCTO = ['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'completado' => 'Completado', 'excedente' => 'Excedente'];
const OC_ESTADOS_ORDEN = [
    'pendiente'  => 'Pendiente de recepción',
    'parcial'    => 'Recepción parcial',
    'completa'   => 'Recibida completamente',
    'excedentes' => 'Con excedentes',
];

// Debe llamarse ANTES de abrir cualquier transacción: en MySQL todo DDL hace commit implícito.
function oc_crear_tablas(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS oc_ordenes (
        id_wo INT PRIMARY KEY,
        prefijo VARCHAR(20) NOT NULL DEFAULT '',
        numero VARCHAR(30) NOT NULL DEFAULT '',
        fecha DATE NULL,
        proveedor VARCHAR(255) NOT NULL DEFAULT '',
        nit_proveedor VARCHAR(50) NOT NULL DEFAULT '',
        empresa VARCHAR(255) NOT NULL DEFAULT '',
        concepto TEXT NULL,
        responsable VARCHAR(255) NOT NULL DEFAULT '',
        estado_recepcion VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        total_solicitado DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_recibido DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_pendiente DECIMAL(14,2) NOT NULL DEFAULT 0,
        fecha_actualizacion DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS oc_productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_orden_wo INT NOT NULL,
        item INT NOT NULL,
        codigo VARCHAR(100) NOT NULL DEFAULT '',
        isbn VARCHAR(50) NOT NULL DEFAULT '',
        id_libro INT NULL,
        descripcion VARCHAR(255) NOT NULL DEFAULT '',
        unidad VARCHAR(30) NOT NULL DEFAULT '',
        cantidad_solicitada DECIMAL(12,2) NOT NULL DEFAULT 0,
        cantidad_recibida DECIMAL(12,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_renglon (id_orden_wo, item, codigo),
        KEY idx_orden (id_orden_wo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS oc_recepciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_orden_wo INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        observaciones VARCHAR(500) NOT NULL DEFAULT '',
        total_unidades DECIMAL(14,2) NOT NULL DEFAULT 0,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token),
        KEY idx_orden (id_orden_wo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS oc_recepcion_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_recepcion INT NOT NULL,
        id_producto INT NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
        recibido_acumulado DECIMAL(12,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_item (id_recepcion, id_producto),
        KEY idx_producto (id_producto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS oc_backorders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_orden_wo INT NOT NULL,
        id_producto INT NOT NULL,
        cantidad_solicitada DECIMAL(12,2) NOT NULL DEFAULT 0,
        cantidad_recibida DECIMAL(12,2) NOT NULL DEFAULT 0,
        cantidad_pendiente DECIMAL(12,2) NOT NULL DEFAULT 0,
        estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        fecha_generacion DATETIME NOT NULL,
        id_usuario_genero INT NOT NULL,
        fecha_actualizacion DATETIME NOT NULL,
        id_usuario_actualizo INT NOT NULL,
        UNIQUE KEY uniq_producto (id_producto),
        KEY idx_orden (id_orden_wo),
        KEY idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function oc_num($v) { return $v === null || $v === '' ? 0.0 : round((float)$v, 2); }

/** "28/09/2026" (formato de WO) → "2026-09-28"; null si no tiene esa forma. */
function oc_fecha_iso($f) {
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', (string)$f, $m)) return "$m[3]-$m[2]-$m[1]";
    return preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$f) ? substr($f, 0, 10) : null;
}

/** Unidades que solo admiten cantidades enteras (los libros vienen en "und"). */
function oc_unidad_entera($unidad) {
    return in_array(mb_strtolower(trim((string)$unidad)), ['', 'und', 'un', 'unidad', 'unidades', 'uni', 'ud', 'uds'], true);
}

function oc_estado_producto($solicitado, $recibido) {
    if ($recibido > $solicitado) return 'excedente';
    if ($recibido <= 0) return $solicitado > 0 ? 'pendiente' : 'completado';
    return $recibido < $solicitado ? 'parcial' : 'completado';
}

/** Estado general de la orden a partir de los estados de sus productos. */
function oc_estado_orden(array $estadosProducto) {
    if (!$estadosProducto) return 'pendiente';
    if (in_array('excedente', $estadosProducto, true)) return 'excedentes';
    $distintos = array_unique($estadosProducto);
    if ($distintos === ['completado']) return 'completa';
    if ($distintos === ['pendiente']) return 'pendiente';
    return 'parcial';
}

/** Clave estable de un renglón para el formulario: "item|código". */
function oc_clave_renglon($item, $codigo) { return (int)$item . '|' . (string)$codigo; }

/**
 * La orden tal como está en World Office: encabezado y renglones normalizados. Solo acepta
 * documentos tipo OC (el id de WO es de encabezados de documento, compartido entre tipos).
 */
function oc_orden_wo(PDO $bdd, $idWo) {
    $resp = obtener_detalle_impresion_wo((int)$idWo);
    if ($resp['status'] !== 'OK') return ['ok' => false, 'error' => 'No se pudo consultar la orden en World Office. ' . ($resp['mensaje_interno'] ?? '')];
    $enc = $resp['data']['encabezado'] ?? [];
    $pie = $resp['data']['piePagina'] ?? [];
    if (($enc['docTipo'] ?? '') !== 'OC') return ['ok' => false, 'error' => 'El documento no es una orden de compra en World Office.'];

    $stmtLibro = $bdd->prepare("SELECT id, isbn FROM libros WHERE isbn <> '' AND isbn = ? ORDER BY id LIMIT 1");
    $items = [];
    foreach ($resp['data']['detalles'] ?? [] as $i => $d) {
        $codigo = trim((string)($d['codigo'] ?? ''));
        $item = (int)($d['item'] ?? ($i + 1));
        // En los libros el código de WO es el ISBN; se confirma contra el catálogo del CRM o por su forma.
        $libro = null;
        if ($codigo !== '') { $stmtLibro->execute([$codigo]); $libro = $stmtLibro->fetch(PDO::FETCH_ASSOC) ?: null; }
        $esIsbn = $libro !== null || preg_match('/^(97[89]\d{10}|\d{9}[\dX])$/i', $codigo);
        $items[] = [
            'clave'          => oc_clave_renglon($item, $codigo),
            'item'           => $item,
            'codigo'         => $codigo,
            'isbn'           => $esIsbn ? $codigo : '',
            'id_libro'       => $libro ? (int)$libro['id'] : null,
            'descripcion'    => (string)($d['descripcion'] ?? ''),
            'bodega'         => (string)($d['bodega'] ?? ''),
            'unidad'         => (string)($d['unidadMedida'] ?? ''),
            'cantidad'       => oc_num($d['cantidad'] ?? 0),
            'valor_unitario' => oc_num($d['valorUnitario'] ?? 0),
            'total'          => oc_num($d['totalConIva'] ?? ($d['total'] ?? 0)),
        ];
    }
    $iva = 0.0;
    foreach ($pie['impuestos'] ?? [] as $imp) $iva += oc_num($imp['valor'] ?? 0);
    return [
        'ok' => true,
        'error' => null,
        'orden' => [
            'id_wo'       => (int)$idWo,
            'prefijo'     => trim((string)($enc['prefijo'] ?? '')),
            'numero'      => trim((string)($enc['numero'] ?? '')),
            'documento'   => trim(($enc['prefijo'] ?? '') . ' ' . ($enc['numero'] ?? '')),
            'fecha'       => (string)($enc['fechaFactura'] ?? ''),
            'fecha_iso'   => oc_fecha_iso($enc['fechaFactura'] ?? ''),
            'proveedor'   => (string)($enc['cliente'] ?? ''),
            'nit'         => (string)($enc['identificacionCliente'] ?? ''),
            'empresa'     => (string)($enc['empresa'] ?? ''),
            'concepto'    => (string)($enc['concepto'] ?? ''),
            'responsable' => (string)($enc['responsable'] ?? ''),
            'forma_pago'  => (string)($enc['formaPago'] ?? ''),
            'anulada'     => !empty($enc['anulado']),
            'total'       => oc_num($pie['totalFactura'] ?? 0),
            'iva'         => $iva,
        ],
        'items' => $items,
    ];
}

/**
 * Todo lo que necesita la vista de detalle: la orden de WO, cada renglón con lo recibido en el
 * CRM (acumulado, pendiente, estado), el resumen, el historial de recepciones y la "versión"
 * (cantidad de recepciones) que el formulario devuelve al guardar para detectar entregas
 * registradas por otra persona mientras tanto.
 */
function oc_datos_orden(PDO $bdd, $idWo) {
    $wo = oc_orden_wo($bdd, $idWo);
    if (!$wo['ok']) return $wo;
    $idWo = (int)$idWo;

    $stmt = $bdd->prepare("SELECT * FROM oc_productos WHERE id_orden_wo = ?");
    $stmt->execute([$idWo]);
    $enCrm = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) $enCrm[oc_clave_renglon($p['item'], $p['codigo'])] = $p;

    $stmt = $bdd->prepare("SELECT id_producto, id, estado, cantidad_pendiente, fecha_generacion FROM oc_backorders WHERE id_orden_wo = ?");
    $stmt->execute([$idWo]);
    $backorders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) $backorders[(int)$b['id_producto']] = $b;

    $productos = [];
    $estados = [];
    $res = ['solicitado' => 0.0, 'recibido' => 0.0, 'pendiente' => 0.0, 'excedente' => 0.0];
    foreach ($wo['items'] as $it) {
        $p = $enCrm[$it['clave']] ?? null;
        $recibido = $p ? oc_num($p['cantidad_recibida']) : 0.0;
        $estado = oc_estado_producto($it['cantidad'], $recibido);
        $estados[] = $estado;
        $pendiente = max(0, round($it['cantidad'] - $recibido, 2));
        $excedente = max(0, round($recibido - $it['cantidad'], 2));
        $res['solicitado'] += $it['cantidad'];
        $res['recibido'] += $recibido;
        $res['pendiente'] += $pendiente;
        $res['excedente'] += $excedente;
        $bo = $p ? ($backorders[(int)$p['id']] ?? null) : null;
        $productos[] = $it + [
            'id_producto' => $p ? (int)$p['id'] : null,
            'recibido'    => $recibido,
            'pendiente'   => $pendiente,
            'excedente'   => $excedente,
            'estado'      => $estado,
            'entero'      => oc_unidad_entera($it['unidad']),
            'backorder'   => $bo ? ['id' => (int)$bo['id'], 'estado' => $bo['estado'], 'fecha_generacion' => $bo['fecha_generacion']] : null,
        ];
    }
    $recibioAlgo = $res['recibido'] > 0;
    $estadoOrden = $recibioAlgo || in_array('excedente', $estados, true) ? oc_estado_orden($estados) : 'pendiente';

    $recepciones = oc_historial_recepciones($bdd, $idWo);
    return [
        'ok' => true,
        'error' => null,
        'orden' => $wo['orden'] + ['estado_recepcion' => $estadoOrden, 'estado_recepcion_label' => OC_ESTADOS_ORDEN[$estadoOrden]],
        'productos' => $productos,
        'resumen' => $res,
        'recepciones' => $recepciones,
        'version' => count($recepciones),
    ];
}

/** Recepciones de una orden, de la más reciente a la más antigua, con sus cantidades. */
function oc_historial_recepciones(PDO $bdd, $idWo) {
    $stmt = $bdd->prepare("SELECT r.id, r.fecha, r.observaciones, r.total_unidades,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                           FROM oc_recepciones r LEFT JOIN usuarios u ON u.id = r.id_usuario
                           WHERE r.id_orden_wo = ? ORDER BY r.fecha DESC, r.id DESC");
    $stmt->execute([(int)$idWo]);
    $recepciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$recepciones) return [];
    $in = implode(',', array_map('intval', array_column($recepciones, 'id')));
    $items = [];
    foreach ($bdd->query("SELECT ri.id_recepcion, ri.cantidad, ri.recibido_acumulado, p.codigo, p.descripcion, p.cantidad_solicitada
                          FROM oc_recepcion_items ri JOIN oc_productos p ON p.id = ri.id_producto
                          WHERE ri.id_recepcion IN ($in) ORDER BY p.item")->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $items[(int)$it['id_recepcion']][] = [
            'codigo' => $it['codigo'], 'descripcion' => $it['descripcion'],
            'cantidad' => oc_num($it['cantidad']), 'acumulado' => oc_num($it['recibido_acumulado']),
            'solicitado' => oc_num($it['cantidad_solicitada']),
        ];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'fecha' => $r['fecha'], 'usuario' => trim((string)$r['usuario']),
        'observaciones' => $r['observaciones'], 'total_unidades' => oc_num($r['total_unidades']),
        'items' => $items[(int)$r['id']] ?? [],
    ], $recepciones);
}

/**
 * Guarda una recepción. $entrada (del navegador): token (único por formulario), version (cantidad
 * de recepciones que había al abrir la orden), cantidades [clave "item|código" => cantidad],
 * observaciones. Lo solicitado se vuelve a leer de WO en este momento; del navegador solo se toman
 * las cantidades recibidas. Todo (recepción, acumulados, backorders y estado de la orden) va en una
 * sola transacción con la fila de la orden bloqueada: o queda todo o no queda nada.
 */
function oc_guardar_recepcion(PDO $bdd, $idWo, array $entrada, $idUsuario) {
    oc_crear_tablas($bdd);
    $idWo = (int)$idWo;
    $idUsuario = (int)$idUsuario;
    $token = (string)($entrada['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) return ['ok' => false, 'error' => 'Solicitud no válida. Recarga la orden.'];
    $version = (int)($entrada['version'] ?? -1);
    $observaciones = mb_substr(trim((string)($entrada['observaciones'] ?? '')), 0, 500);
    $cantidadesIn = (array)($entrada['cantidades'] ?? []);

    $wo = oc_orden_wo($bdd, $idWo);
    if (!$wo['ok']) return $wo;
    if ($wo['orden']['anulada']) return ['ok' => false, 'error' => 'La orden de compra está anulada en World Office; no se puede recibir.'];
    if (!$wo['items']) return ['ok' => false, 'error' => 'La orden no tiene productos en World Office.'];

    // Validación de cantidades: una por cada renglón de la orden, número ≥ 0 (entero si la unidad es entera).
    $cantidades = [];
    foreach ($wo['items'] as $it) {
        $v = trim((string)($cantidadesIn[$it['clave']] ?? ''));
        if ($v === '') return ['ok' => false, 'error' => "Escribe la cantidad recibida de {$it['descripcion']} (0 si no llegó)."];
        if (!is_numeric($v) || (float)$v < 0) return ['ok' => false, 'error' => "La cantidad de {$it['descripcion']} debe ser un número igual o mayor a 0."];
        if (oc_unidad_entera($it['unidad']) && floor((float)$v) != (float)$v) return ['ok' => false, 'error' => "La cantidad de {$it['descripcion']} debe ser un número entero."];
        if (round((float)$v, 2) != (float)$v) return ['ok' => false, 'error' => "La cantidad de {$it['descripcion']} admite máximo 2 decimales."];
        $cantidades[$it['clave']] = round((float)$v, 2);
    }
    $o = $wo['orden'];

    try {
        $bdd->beginTransaction();
        // La fila de la orden se crea si no existe y se bloquea: dos recepciones de la misma orden
        // nunca se guardan a la vez.
        $bdd->prepare("INSERT IGNORE INTO oc_ordenes (id_wo) VALUES (?)")->execute([$idWo]);
        $bdd->prepare("SELECT id_wo FROM oc_ordenes WHERE id_wo = ? FOR UPDATE")->execute([$idWo]);

        $stmt = $bdd->prepare("SELECT COUNT(*) FROM oc_recepciones WHERE token = ?");
        $stmt->execute([$token]);
        if ((int)$stmt->fetchColumn() > 0) {
            $bdd->rollBack();
            return ['ok' => false, 'ya_guardada' => true, 'error' => 'Esta recepción ya se había guardado; no se registró de nuevo.'];
        }
        $stmt = $bdd->prepare("SELECT COUNT(*) FROM oc_recepciones WHERE id_orden_wo = ?");
        $stmt->execute([$idWo]);
        if ((int)$stmt->fetchColumn() !== $version) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'Otra persona registró una recepción de esta orden mientras la tenías abierta. Recarga la orden para ver las cantidades actualizadas.'];
        }

        $bdd->prepare("UPDATE oc_ordenes SET prefijo = ?, numero = ?, fecha = ?, proveedor = ?, nit_proveedor = ?, empresa = ?, concepto = ?, responsable = ? WHERE id_wo = ?")
            ->execute([$o['prefijo'], $o['numero'], $o['fecha_iso'], mb_substr($o['proveedor'], 0, 255), mb_substr($o['nit'], 0, 50),
                       mb_substr($o['empresa'], 0, 255), $o['concepto'], mb_substr($o['responsable'], 0, 255), $idWo]);

        // Renglones: se crean la primera vez y se actualiza lo solicitado según WO (el acumulado no se toca aquí).
        $upsert = $bdd->prepare("INSERT INTO oc_productos (id_orden_wo, item, codigo, isbn, id_libro, descripcion, unidad, cantidad_solicitada)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE isbn = VALUES(isbn), id_libro = VALUES(id_libro), descripcion = VALUES(descripcion),
                                                         unidad = VALUES(unidad), cantidad_solicitada = VALUES(cantidad_solicitada)");
        foreach ($wo['items'] as $it) {
            $upsert->execute([$idWo, $it['item'], $it['codigo'], $it['isbn'], $it['id_libro'], mb_substr($it['descripcion'], 0, 255), $it['unidad'], $it['cantidad']]);
        }
        $stmt = $bdd->prepare("SELECT id, item, codigo, cantidad_solicitada, cantidad_recibida FROM oc_productos WHERE id_orden_wo = ? FOR UPDATE");
        $stmt->execute([$idWo]);
        $productos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) $productos[oc_clave_renglon($p['item'], $p['codigo'])] = $p;

        $bdd->prepare("INSERT INTO oc_recepciones (id_orden_wo, token, observaciones, total_unidades, id_usuario, fecha) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$idWo, $token, $observaciones, array_sum($cantidades), $idUsuario]);
        $idRecepcion = (int)$bdd->lastInsertId();

        $insItem = $bdd->prepare("INSERT INTO oc_recepcion_items (id_recepcion, id_producto, cantidad, recibido_acumulado) VALUES (?, ?, ?, ?)");
        $updProd = $bdd->prepare("UPDATE oc_productos SET cantidad_recibida = ? WHERE id = ?");
        $selBo = $bdd->prepare("SELECT id FROM oc_backorders WHERE id_producto = ?");
        $insBo = $bdd->prepare("INSERT INTO oc_backorders (id_orden_wo, id_producto, cantidad_solicitada, cantidad_recibida, cantidad_pendiente, estado,
                                                           fecha_generacion, id_usuario_genero, fecha_actualizacion, id_usuario_actualizo)
                                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?)");
        $updBo = $bdd->prepare("UPDATE oc_backorders SET cantidad_solicitada = ?, cantidad_recibida = ?, cantidad_pendiente = ?, estado = ?,
                                                         fecha_actualizacion = NOW(), id_usuario_actualizo = ? WHERE id = ?");
        $estados = [];
        $tot = ['solicitado' => 0.0, 'recibido' => 0.0, 'pendiente' => 0.0];
        $backordersNuevos = 0;
        foreach ($wo['items'] as $it) {
            $p = $productos[$it['clave']];
            $solicitado = oc_num($p['cantidad_solicitada']);
            $acumulado = round(oc_num($p['cantidad_recibida']) + $cantidades[$it['clave']], 2);
            $pendiente = max(0, round($solicitado - $acumulado, 2));
            $estado = oc_estado_producto($solicitado, $acumulado);
            $estados[] = $estado;
            $tot['solicitado'] += $solicitado;
            $tot['recibido'] += $acumulado;
            $tot['pendiente'] += $pendiente;

            $insItem->execute([$idRecepcion, $p['id'], $cantidades[$it['clave']], $acumulado]);
            $updProd->execute([$acumulado, $p['id']]);

            // Backorder: se crea solo cuando hay faltante y no existía; si ya existe, se actualiza
            // siempre (también a completado/excedente) para conservar su seguimiento.
            $selBo->execute([$p['id']]);
            $idBo = $selBo->fetchColumn();
            if ($idBo) {
                $updBo->execute([$solicitado, $acumulado, $pendiente, $estado, $idUsuario, (int)$idBo]);
            } elseif ($pendiente > 0) {
                $insBo->execute([$idWo, $p['id'], $solicitado, $acumulado, $pendiente, $estado, $idUsuario, $idUsuario]);
                $backordersNuevos++;
            }
        }
        $estadoOrden = $tot['recibido'] > 0 || in_array('excedente', $estados, true) ? oc_estado_orden($estados) : 'pendiente';
        $bdd->prepare("UPDATE oc_ordenes SET estado_recepcion = ?, total_solicitado = ?, total_recibido = ?, total_pendiente = ?, fecha_actualizacion = NOW() WHERE id_wo = ?")
            ->execute([$estadoOrden, $tot['solicitado'], $tot['recibido'], $tot['pendiente'], $idWo]);
        $bdd->commit();
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        // 23000 = llave única: el mismo formulario se envió dos veces casi al mismo tiempo.
        if ($e->getCode() === '23000') return ['ok' => false, 'ya_guardada' => true, 'error' => 'Esta recepción ya se había guardado; no se registró de nuevo.'];
        return ['ok' => false, 'error' => 'No se pudo guardar la recepción. No se registró ningún cambio; intenta de nuevo.'];
    }
    return [
        'ok' => true, 'error' => null, 'id_recepcion' => $idRecepcion,
        'estado_recepcion' => $estadoOrden, 'estado_recepcion_label' => OC_ESTADOS_ORDEN[$estadoOrden],
        'backorders_nuevos' => $backordersNuevos, 'pendiente' => $tot['pendiente'],
    ];
}

/** Estado de recepción guardado de varias órdenes: [id_wo => estado]; las que no están, pendientes. */
function oc_estados_recepcion(PDO $bdd, array $idsWo) {
    $idsWo = array_values(array_filter(array_map('intval', $idsWo)));
    if (!$idsWo) return [];
    $in = implode(',', $idsWo);
    $out = [];
    foreach ($bdd->query("SELECT id_wo, estado_recepcion FROM oc_ordenes WHERE id_wo IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id_wo']] = $r['estado_recepcion'];
    return $out;
}

/** Todos los backorders (también los completados), con los datos de su orden y producto. */
function oc_listar_backorders(PDO $bdd) {
    $filas = $bdd->query("SELECT b.id, b.id_orden_wo, b.cantidad_solicitada, b.cantidad_recibida, b.cantidad_pendiente, b.estado,
                                 b.fecha_generacion, b.fecha_actualizacion,
                                 o.prefijo, o.numero, o.fecha AS fecha_orden, o.proveedor,
                                 p.codigo, p.isbn, p.descripcion, p.unidad,
                                 CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario_genero
                          FROM oc_backorders b
                          JOIN oc_productos p ON p.id = b.id_producto
                          LEFT JOIN oc_ordenes o ON o.id_wo = b.id_orden_wo
                          LEFT JOIN usuarios u ON u.id = b.id_usuario_genero
                          ORDER BY b.fecha_generacion DESC, b.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'id_orden_wo' => (int)$r['id_orden_wo'],
        'orden' => trim($r['prefijo'] . ' ' . $r['numero']), 'fecha_orden' => $r['fecha_orden'], 'proveedor' => $r['proveedor'],
        'producto' => $r['descripcion'], 'codigo' => $r['codigo'], 'isbn' => $r['isbn'], 'unidad' => $r['unidad'],
        'solicitada' => oc_num($r['cantidad_solicitada']), 'recibida' => oc_num($r['cantidad_recibida']), 'pendiente' => oc_num($r['cantidad_pendiente']),
        'estado' => $r['estado'], 'estado_label' => OC_ESTADOS_PRODUCTO[$r['estado']] ?? $r['estado'],
        'fecha_generacion' => $r['fecha_generacion'], 'fecha_actualizacion' => $r['fecha_actualizacion'], 'usuario_genero' => trim((string)$r['usuario_genero']),
    ], $filas);
}

/** Historial de un backorder: cada recepción de su producto, con lo recibido y el acumulado. */
function oc_historial_backorder(PDO $bdd, $idBackorder) {
    $stmt = $bdd->prepare("SELECT b.*, p.codigo, p.descripcion, p.unidad, o.prefijo, o.numero, o.proveedor,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario_genero
                           FROM oc_backorders b JOIN oc_productos p ON p.id = b.id_producto
                           LEFT JOIN oc_ordenes o ON o.id_wo = b.id_orden_wo
                           LEFT JOIN usuarios u ON u.id = b.id_usuario_genero
                           WHERE b.id = ?");
    $stmt->execute([(int)$idBackorder]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) return ['ok' => false, 'error' => 'El backorder no existe.'];
    $stmt = $bdd->prepare("SELECT r.fecha, r.observaciones, ri.cantidad, ri.recibido_acumulado,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                           FROM oc_recepcion_items ri JOIN oc_recepciones r ON r.id = ri.id_recepcion
                           LEFT JOIN usuarios u ON u.id = r.id_usuario
                           WHERE ri.id_producto = ? ORDER BY r.fecha, r.id");
    $stmt->execute([(int)$b['id_producto']]);
    $solicitado = oc_num($b['cantidad_solicitada']);
    $movs = array_map(fn($m) => [
        'fecha' => $m['fecha'], 'usuario' => trim((string)$m['usuario']), 'observaciones' => $m['observaciones'],
        'cantidad' => oc_num($m['cantidad']), 'acumulado' => oc_num($m['recibido_acumulado']),
        'pendiente' => max(0, round($solicitado - oc_num($m['recibido_acumulado']), 2)),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    return [
        'ok' => true, 'error' => null,
        'backorder' => [
            'id' => (int)$b['id'], 'id_orden_wo' => (int)$b['id_orden_wo'], 'orden' => trim($b['prefijo'] . ' ' . $b['numero']),
            'proveedor' => $b['proveedor'], 'producto' => $b['descripcion'], 'codigo' => $b['codigo'], 'unidad' => $b['unidad'],
            'solicitada' => $solicitado, 'recibida' => oc_num($b['cantidad_recibida']), 'pendiente' => oc_num($b['cantidad_pendiente']),
            'estado' => $b['estado'], 'estado_label' => OC_ESTADOS_PRODUCTO[$b['estado']] ?? $b['estado'],
            'fecha_generacion' => $b['fecha_generacion'], 'usuario_genero' => trim((string)$b['usuario_genero']),
        ],
        'movimientos' => $movs,
    ];
}
