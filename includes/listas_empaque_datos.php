<?php
/**
 * /includes/listas_empaque_datos.php
 * Listas de empaque. Flujo actual (2026-09-28): se elige un cliente del CRM, se listan TODAS las
 * remisiones (REM) y facturas de venta (FV) que World Office tiene a su nombre
 * (le_documentos_cliente()), el usuario marca una o varias, se toman las cantidades que traen
 * (renglones de esos documentos, le_datos_documentos()) y el usuario las reparte en cajas. SIN
 * ninguna relación con pedidos ni OP del CRM (el usuario lo pidió así): no hay cruce contra lo
 * solicitado. Al guardar se asigna un consecutivo único y se congela una copia de todo (cliente,
 * documentos, cajas) para que el historial no cambie si después se modifica el documento en WO.
 *
 * Flujo anterior, por pedido (le_datos_pedido(), le_documentos_pedido(), le_documentos_crm(),
 * le_solicitado(), le_buscar_documentos_manual() y los avisos): ya no lo usa ninguna pantalla; se
 * deja por si se vuelve a necesitar. Lo que sigue describe ese cruce.
 *
 * Cruce CRM ↔ World Office (verificado en vivo 2026-09-25): el `concepto` de los documentos trae
 * "OP <año>-<id>;PED <id_pedido>; COL <colegio>; ...". Se busca en WO por concepto LIKE "OP año-id"
 * para cada OP no anulada del pedido, y el documento solo se acepta si alguna de las OP escritas en
 * su concepto es EXACTAMENTE una OP del pedido (el LIKE de WO también trae "OP 2026-59041" al
 * buscar "OP 2026-5904"). Se busca además por "PED <id>" solo para AVISAR de documentos que dicen
 * ser de este pedido pero cuya OP no está asociada a él en el CRM — esos no se pueden usar (ver
 * includes/matching_pedidos_wo.php: el número PED se escribe a mano y a veces está mal).
 *
 * OP agrupadas (op_pedidos_agrupados): una OP puede despachar varios pedidos a la vez, así que el
 * documento de WO trae lo de todos. En ese caso la lista cubre todos los pedidos de esas OP y lo
 * solicitado se compara contra la suma de todos ellos.
 *
 * Un documento de WO solo puede quedar en UNA lista de empaque activa: lo garantiza la llave única
 * (tipo_documento, id_wo, uso_activo) de listas_empaque_documentos — uso_activo=1 mientras la lista
 * está activa y NULL al anularla (MySQL permite varios NULL en una llave única), lo que libera el
 * documento para otra lista.
 */

require_once(__DIR__ . '/api_wo_cliente.php');
require_once(__DIR__ . '/prueba_post2.php');
require_once(__DIR__ . '/prueba_get2.php');
require_once(__DIR__ . '/matching_colegios.php');

const LE_TIPOS_WO = ['REM' => 'Remisión', 'FV' => 'Factura de Venta'];
const LE_OP_ESTADO_ANULADA = 4;
const LE_PEDIDO_ESTADO_ANULADO = 3;

// Debe llamarse ANTES de abrir cualquier transacción: en MySQL todo DDL hace commit implícito.
function le_crear_tablas(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS listas_empaque (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consecutivo INT NOT NULL,
        id_pedido INT NOT NULL,
        pedidos VARCHAR(500) NOT NULL DEFAULT '',
        ops VARCHAR(500) NOT NULL DEFAULT '',
        colegio VARCHAR(255) NOT NULL DEFAULT '',
        empresa VARCHAR(255) NOT NULL DEFAULT '',
        cliente VARCHAR(255) NOT NULL DEFAULT '',
        cliente_id_wo INT NULL,
        ciudad VARCHAR(150) NOT NULL DEFAULT '',
        direccion VARCHAR(500) NOT NULL DEFAULT '',
        peso_neto DECIMAL(10,2) NULL,
        empacado_por VARCHAR(150) NOT NULL DEFAULT '',
        total_unidades INT NOT NULL DEFAULT 0,
        total_cajas INT NOT NULL DEFAULT 0,
        cruce_json MEDIUMTEXT NULL,
        estado TINYINT NOT NULL DEFAULT 1,
        motivo_anulacion VARCHAR(500) NULL,
        id_usuario_anula INT NULL,
        fecha_anulacion DATETIME NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_consecutivo (consecutivo),
        KEY idx_pedido (id_pedido),
        KEY idx_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS listas_empaque_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_lista INT NOT NULL,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        prefijo VARCHAR(20) NOT NULL DEFAULT '',
        numero VARCHAR(30) NOT NULL DEFAULT '',
        fecha_doc DATE NULL,
        concepto TEXT NULL,
        uso_activo TINYINT NULL DEFAULT 1,
        UNIQUE KEY uniq_doc_activo (tipo_documento, id_wo, uso_activo),
        KEY idx_lista (id_lista)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Cómo se relacionó el documento con el pedido: 'concepto' (OP en el concepto de WO), 'crm'
    // (registrado en la OP del CRM) o 'manual' (buscado por número y asociado a mano). `motivo` quedó sin uso (se quitó el 2026-09-25).
    // Desde 2026-09-28 los documentos se eligen de la lista del cliente: origen 'cliente'.
    if (!$bdd->query("SHOW COLUMNS FROM listas_empaque_documentos LIKE 'origen'")->fetch()) {
        $bdd->exec("ALTER TABLE listas_empaque_documentos ADD COLUMN origen VARCHAR(10) NOT NULL DEFAULT 'concepto', ADD COLUMN motivo VARCHAR(500) NULL");
    }
    // Cliente del CRM con el que se armó la lista (NULL en las anteriores, que se armaban por pedido).
    if (!$bdd->query("SHOW COLUMNS FROM listas_empaque LIKE 'id_cliente'")->fetch()) {
        $bdd->exec("ALTER TABLE listas_empaque ADD COLUMN id_cliente INT NULL AFTER id_pedido");
    }
    // Persona que recibe el despacho (texto libre, opcional; 2026-09-28).
    if (!$bdd->query("SHOW COLUMNS FROM listas_empaque LIKE 'persona_recibe'")->fetch()) {
        $bdd->exec("ALTER TABLE listas_empaque ADD COLUMN persona_recibe VARCHAR(150) NOT NULL DEFAULT '' AFTER direccion");
    }
    $bdd->exec("CREATE TABLE IF NOT EXISTS listas_empaque_cajas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_lista INT NOT NULL,
        numero INT NOT NULL,
        peso DECIMAL(10,2) NULL,
        UNIQUE KEY uniq_lista_caja (id_lista, numero)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bdd->exec("CREATE TABLE IF NOT EXISTS listas_empaque_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_lista INT NOT NULL,
        caja INT NOT NULL,
        id_inventario_wo INT NOT NULL,
        codigo VARCHAR(100) NOT NULL DEFAULT '',
        descripcion VARCHAR(255) NOT NULL DEFAULT '',
        id_libro INT NULL,
        cantidad INT NOT NULL,
        KEY idx_lista (id_lista)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Fila única con el último consecutivo usado; se bloquea con FOR UPDATE al guardar para que dos
    // usuarios guardando a la vez nunca reciban el mismo número (la llave única es el respaldo).
    $bdd->exec("CREATE TABLE IF NOT EXISTS listas_empaque_consecutivo (
        id TINYINT PRIMARY KEY,
        ultimo INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $bdd->exec("INSERT IGNORE INTO listas_empaque_consecutivo (id, ultimo) VALUES (1, 0)");
}

function le_formatear_consecutivo($n) {
    return str_pad((string)(int)$n, 3, '0', STR_PAD_LEFT);
}

function le_etiqueta_op($id, $anio) {
    return 'OP ' . ((int)$anio ?: date('Y')) . '-' . (int)$id;
}

/** Todas las OP escritas en un concepto de WO ("OP 2026-5904" → 5904), puede haber más de una. */
function le_ops_en_concepto($concepto) {
    preg_match_all('/\bOP\.?\s*\d{4}\s*-\s*(\d+)/i', (string)$concepto, $m);
    return array_map('intval', $m[1] ?? []);
}

function le_ped_en_concepto($concepto) {
    return preg_match('/\bPED\.?\s*(\d+)/i', (string)$concepto, $m) ? (int)$m[1] : null;
}

/**
 * Buscador de clientes (tabla clientes) con id de World Office, que es por donde se buscan sus
 * documentos. Ya no exige pedidos en el CRM (2026-09-28): las cuentas de muestras de asesores
 * ("AA27- JAIRO RICO - MUESTRA") no tienen pedidos pero sí remisiones en WO.
 */
function le_buscar_clientes(PDO $bdd, $q) {
    $q = trim((string)$q);
    $where = "cl.id_wo > 0";
    $params = [];
    if ($q !== '') {
        $where .= " AND (cl.cliente LIKE ? OR cl.documento LIKE ?)";
        $params = ['%' . $q . '%', '%' . $q . '%'];
    }
    $stmt = $bdd->prepare("SELECT cl.id, cl.cliente, cl.documento FROM clientes cl WHERE $where ORDER BY cl.cliente LIMIT 30");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * TODAS las remisiones y facturas que World Office tiene a nombre del cliente del CRM: filtro
 * `terceroExterno.id` = clientes.id_wo (verificado 2026-09-28: Alexander Nuñez, id_wo 7313 → sus 25
 * REM; FV sin coincidencias responde BAD_REQUEST). No se filtra por nombre porque el CRM lo guarda
 * en otro orden ("NUÑEZ ESCOBAR ALEXANDER" vs. "ALEXANDER NUÑEZ ESCOBAR" en WO).
 *
 * Sin ninguna relación con pedidos ni OP del CRM (2026-09-28, pedido del usuario): solo los
 * documentos tal como están en WO. Solo `bloqueo` si está anulado en WO o ya está en otra lista de
 * empaque activa.
 *
 * $idsCliente puede ser uno o varios clientes (2026-09-28, pedido del usuario): se consultan todos a
 * la vez y cada documento trae `id_cliente` y `cliente_crm` (nombre en el CRM).
 */
function le_documentos_cliente(PDO $bdd, $idsCliente) {
    $idsCliente = array_values(array_unique(array_filter(array_map('intval', (array)$idsCliente))));
    if (!$idsCliente) return ['ok' => false, 'error' => 'Selecciona al menos un cliente.', 'documentos' => []];
    $in = implode(',', $idsCliente);
    $clientes = [];
    foreach ($bdd->query("SELECT id, cliente, id_wo FROM clientes WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $c) $clientes[(int)$c['id']] = $c;
    foreach ($idsCliente as $id) {
        if (!isset($clientes[$id])) return ['ok' => false, 'error' => 'Uno de los clientes no existe.', 'documentos' => []];
        if ((int)$clientes[$id]['id_wo'] <= 0) return ['ok' => false, 'error' => $clientes[$id]['cliente'] . ' no tiene su id de World Office en el CRM, así que no se pueden buscar sus documentos.', 'documentos' => []];
    }

    $consultas = [];
    foreach ($idsCliente as $id) {
        foreach (array_keys(LE_TIPOS_WO) as $tipo) $consultas["$id:$tipo"] = [$tipo, [crear_filtro_api('terceroExterno.id', (string)(int)$clientes[$id]['id_wo'], 4, 0)]];
    }
    $docs = [];
    foreach (le_listar_wo_paralelo($consultas) as $k => $filas) {
        [$id, $tipo] = explode(':', $k);
        foreach ($filas as $row) {
            $d = le_base_documento($tipo, $row);
            // Dos clientes del CRM pueden tener el mismo id_wo: el documento sale una sola vez.
            if (!isset($docs[$d['clave']])) $docs[$d['clave']] = $d + ['id_cliente' => (int)$id, 'cliente_crm' => $clientes[(int)$id]['cliente']];
        }
    }
    if (!$docs) return ['ok' => true, 'error' => null, 'documentos' => []];

    $usados = le_documentos_usados($bdd, array_keys($docs));
    $out = [];
    foreach ($docs as $clave => $d) {
        $d['usado_en'] = $usados[$clave] ?? null;
        // Solo se bloquea lo que no se puede despachar: anulado en WO o ya en otra lista activa.
        $d['bloqueo'] = $d['anulado'] ? 'Anulado en World Office'
            : ($d['usado_en'] ? 'Ya está en la lista ' . le_formatear_consecutivo($d['usado_en']) : null);
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['fecha'], $a['fecha']) ?: $b['id_wo'] - $a['id_wo']);
    return ['ok' => true, 'error' => null, 'documentos' => $out];
}

/**
 * OP no anuladas del pedido (directas por ordenes_pedidos.id_pedido o agrupadas), y todos los
 * pedidos que esas OP despachan (el mismo pedido + los que comparten OP agrupada).
 */
function le_ops_y_pedidos(PDO $bdd, $idPedido) {
    $idPedido = (int)$idPedido;
    $stmt = $bdd->prepare("SELECT o.id, o.`año` AS anio, o.estado, o.n_doc
                           FROM ordenes_pedidos o
                           WHERE o.estado != " . LE_OP_ESTADO_ANULADA . "
                             AND (o.id_pedido = ? OR o.id IN (SELECT op FROM op_pedidos_agrupados WHERE id_pedido = ?))
                           ORDER BY o.id");
    $stmt->execute([$idPedido, $idPedido]);
    $ops = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $ops[(int)$o['id']] = [
            'id' => (int)$o['id'],
            'etiqueta' => le_etiqueta_op($o['id'], $o['anio']),
            'estado' => (int)$o['estado'],
            'n_doc' => (string)$o['n_doc'],
        ];
    }

    $pedidos = [$idPedido => true];
    if ($ops) {
        $in = implode(',', array_keys($ops));
        foreach ($bdd->query("SELECT id_pedido FROM ordenes_pedidos WHERE id IN ($in) AND id_pedido > 0
                              UNION SELECT id_pedido FROM op_pedidos_agrupados WHERE op IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $pedidos[(int)$p] = true;
        }
    }
    $pedidos = array_keys($pedidos);
    sort($pedidos);
    return ['ops' => $ops, 'pedidos' => $pedidos];
}

/** Encabezado del pedido seleccionado. */
function le_info_pedido(PDO $bdd, $idPedido) {
    $stmt = $bdd->prepare("SELECT p.id, p.codigo, p.fecha, p.dir_ent, p.observaciones, ep.estado,
                                  c.colegio, cl.cliente, cl.ciudad AS cliente_ciudad
                           FROM pedidos p
                           JOIN colegios c ON c.id = p.id_colegio
                           LEFT JOIN clientes cl ON cl.id = p.cliente
                           LEFT JOIN estados_pedidos ep ON ep.id = p.estado
                           WHERE p.id = ?");
    $stmt->execute([(int)$idPedido]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Cantidades solicitadas por libro, sumadas entre todos los pedidos dados. */
function le_solicitado(PDO $bdd, array $idsPedido) {
    if (!$idsPedido) return [];
    $in = implode(',', array_map('intval', $idsPedido));
    $rows = $bdd->query("SELECT lp.id_libro, l.libro, l.isbn, SUM(lp.cantidad) AS cantidad
                         FROM libros_pedidos lp
                         JOIN pedidos p ON p.codigo = lp.cod_pedido
                         JOIN libros l ON l.id = lp.id_libro
                         WHERE p.id IN ($in)
                         GROUP BY lp.id_libro, l.libro, l.isbn
                         ORDER BY l.libro")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id_libro' => (int)$r['id_libro'],
        'libro' => $r['libro'],
        'isbn' => (string)$r['isbn'],
        'cantidad' => (int)$r['cantidad'],
    ], $rows);
}

/** Documentos de WO que ya están en una lista de empaque activa: ["TIPO:id_wo" => consecutivo]. */
function le_documentos_usados(PDO $bdd, array $claves) {
    if (!$claves) return [];
    $conds = [];
    $params = [];
    foreach ($claves as $c) {
        [$tipo, $id] = explode(':', $c);
        $conds[] = "(d.tipo_documento = ? AND d.id_wo = ?)";
        $params[] = $tipo;
        $params[] = (int)$id;
    }
    $stmt = $bdd->prepare("SELECT d.tipo_documento, d.id_wo, l.consecutivo
                           FROM listas_empaque_documentos d JOIN listas_empaque l ON l.id = d.id_lista
                           WHERE d.uso_activo = 1 AND (" . implode(' OR ', $conds) . ")");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['tipo_documento'] . ':' . $r['id_wo']] = (int)$r['consecutivo'];
    return $out;
}

/**
 * Varias búsquedas en listarDocumentoSalidaAlmacen A LA VEZ, cada una con todas sus páginas (hasta
 * 10): primero la página 0 de todas, luego la 1 de las que siguen, etc.
 * $consultas: [clave => [tipo, [filtros extra]]] (el tipo y la moneda se agregan aquí).
 * Devuelve [clave => filas]. Sin coincidencias WO responde BAD_REQUEST: la clave queda vacía.
 */
function le_listar_wo_paralelo(array $consultas, $porPagina = 50) {
    $filas = [];
    foreach ($consultas as $k => $_) $filas[$k] = [];
    $vivas = $consultas;
    for ($pagina = 0; $vivas && $pagina < 10; $pagina++) {
        $peticiones = [];
        foreach ($vivas as $k => [$tipo, $filtros]) {
            $peticiones[$k] = ['endpoint' => '/inventarios/listarDocumentoSalidaAlmacen', 'metodo' => 'POST',
                'datos' => cuerpo_listar_documentos_salida_almacen(array_merge([
                    crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                    crear_filtro_api('moneda.id', '31', 4),
                ], $filtros), $pagina, $porPagina)];
        }
        $resps = hacer_peticiones_api_paralelo($peticiones);
        foreach (array_keys($vivas) as $k) {
            $r = $resps[$k] ?? [];
            if (($r['status'] ?? '') !== 'OK') { unset($vivas[$k]); continue; }
            foreach ($r['data']['content'] ?? [] as $row) $filas[$k][] = $row;
            if (!empty($r['data']['last']) || empty($r['data']['content'])) unset($vivas[$k]);
        }
    }
    return $filas;
}

/** Filtro "concepto LIKE texto" para le_listar_wo_paralelo(). */
function le_filtro_concepto($texto) {
    return [crear_filtro_api('concepto', $texto, 0, 1)];
}

/**
 * Renglones de un documento consolidados por producto de WO (un mismo producto puede venir en
 * varios renglones). Cada ítem se liga a TODOS los libros cuyo libros.id_wo sea su id de
 * inventario o cuyo ISBN sea su código (`ids_libro`): el catálogo tiene el mismo título repetido
 * con distinta etiqueta (ej. "CAT/26" y "PLAN LECTOR", 117 ISBN repetidos al 2026-09-25) y el
 * pedido puede usar cualquiera de las copias — ligarlo a una sola hacía que el cruce mostrara el
 * libro como "no se despachó" y "no estaba en el pedido" a la vez (reportado con el pedido 1933).
 */
function le_items_documento(PDO $bdd, $idWo, $primeraPagina = null) {
    $items = [];
    for ($pagina = 0; $pagina < 10; $pagina++) {
        // La primera página puede venir ya consultada (le_completar_documentos(), en paralelo).
        $resp = $pagina === 0 && $primeraPagina !== null ? $primeraPagina : obtener_renglones_documento($idWo, $pagina, 200);
        if (($resp['status'] ?? '') !== 'OK') return null;
        foreach ($resp['data']['content'] ?? [] as $r) {
            $inv = $r['inventario'] ?? [];
            $idInv = (int)($inv['id'] ?? 0);
            if ($idInv <= 0) continue;
            if (!isset($items[$idInv])) {
                $items[$idInv] = [
                    'id_inventario' => $idInv,
                    'codigo' => (string)($inv['codigo'] ?? ''),
                    'descripcion' => (string)($inv['descripcion'] ?? ''),
                    'cantidad' => 0,
                    'id_libro' => null,
                    'ids_libro' => [],
                ];
            }
            $items[$idInv]['cantidad'] += (float)($r['cantidad'] ?? 0);
        }
        if (!empty($resp['data']['last']) || empty($resp['data']['content'])) break;
    }

    if ($items) {
        $stmtLibros = $bdd->prepare("SELECT id FROM libros WHERE id_wo = ? OR (isbn <> '' AND isbn = ?) ORDER BY id");
        foreach ($items as &$it) {
            $it['cantidad'] = round($it['cantidad'], 2);
            $stmtLibros->execute([$it['id_inventario'], $it['codigo']]);
            $it['ids_libro'] = array_map('intval', $stmtLibros->fetchAll(PDO::FETCH_COLUMN));
            $it['id_libro'] = $it['ids_libro'][0] ?? null;
        }
        unset($it);
    }
    return array_values($items);
}

/** Datos básicos de un documento a partir de su fila del listado de WO. */
function le_base_documento($tipo, array $row) {
    return [
        'clave' => $tipo . ':' . (int)$row['id'],
        'id_wo' => (int)$row['id'],
        'tipo' => $tipo,
        'tipo_label' => LE_TIPOS_WO[$tipo],
        'prefijo' => (string)($row['prefijo'] ?? ''),
        'numero' => (string)($row['numero'] ?? ''),
        'documento' => trim(($row['prefijo'] ?? '') . ' ' . ($row['numero'] ?? '')),
        'fecha' => le_fecha_wo($row['fecha'] ?? ''),
        'concepto' => (string)($row['concepto'] ?? ''),
        'tercero' => (string)($row['terceroExterno'] ?? ''),
        'anulado' => !empty($row['senAnulado']),
    ];
}

/** Agrega cliente, ciudad, empresa e ítems (detalle + renglones de WO) a un documento. */
function le_completar_documento(PDO $bdd, array $d, $det = null, $renglones = null) {
    if ($det === null) $det = obtener_documento_salida_por_id($d['id_wo']);
    // El detalle responde status "ACCEPTED" (no "OK" como el listado).
    $data = in_array($det['status'] ?? '', ['OK', 'ACCEPTED'], true) && is_array($det['data'] ?? null) ? $det['data'] : [];
    $d['empresa'] = (string)($data['empresa']['nombre'] ?? '');
    $d['cliente'] = (string)($data['terceroExterno']['nombreCompleto'] ?? $d['tercero']);
    $d['cliente_id_wo'] = isset($data['terceroExterno']['id']) ? (int)$data['terceroExterno']['id'] : null;
    $d['ciudad'] = (string)($data['direccionTerceroExterno']['ciudad'] ?? '');
    $d['direccion_wo'] = (string)($data['direccionTerceroExterno']['direccion'] ?? '');
    $items = le_items_documento($bdd, $d['id_wo'], $renglones);
    $d['error_items'] = $items === null;
    $d['items'] = $items ?? [];
    return $d;
}

/** le_completar_documento() para varios documentos, con el detalle y los renglones de todos a la vez. */
function le_completar_documentos(PDO $bdd, array $docs) {
    $peticiones = [];
    foreach ($docs as $k => $d) {
        $peticiones["det:$k"] = ['endpoint' => '/inventarios/getDocumentoSalidaAlmacenId/' . (int)$d['id_wo'], 'metodo' => 'GET', 'datos' => null];
        $peticiones["ren:$k"] = ['endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . (int)$d['id_wo'], 'metodo' => 'POST', 'datos' => cuerpo_renglones_documento(0, 200)];
    }
    $resps = hacer_peticiones_api_paralelo($peticiones);
    foreach ($docs as $k => &$d) $d = le_completar_documento($bdd, $d, $resps["det:$k"] ?? [], $resps["ren:$k"] ?? []);
    unset($d);
    return $docs;
}

/** Un documento REM/FV por su id de WO (se prueba en cada tipo). Devuelve ['tipo', 'row'] o null. */
function le_buscar_wo_por_id($idWo, array $tipos = null) {
    foreach ($tipos ?? array_keys(LE_TIPOS_WO) as $tipo) {
        $resp = listar_documentos_salida_almacen([
            crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
            crear_filtro_api('moneda.id', '31', 4),
            crear_filtro_api('id', (string)(int)$idWo, 4, 0),
        ], 0, 5);
        foreach (($resp['status'] ?? '') === 'OK' ? ($resp['data']['content'] ?? []) : [] as $row) {
            if ((int)$row['id'] === (int)$idWo) return ['tipo' => $tipo, 'row' => $row];
        }
    }
    return null;
}

/**
 * "CEUR 7654", "RE22-7862", "FVE2452" o solo "7654" → ['prefijo' => 'CEUR'|'', 'numero' => '7654'],
 * o null si el texto no tiene esa forma.
 */
function le_parsear_numero_documento($texto) {
    if (!preg_match('/^\s*(?:([A-Za-z]+\d*)[\s\-]+|([A-Za-z]+))?(\d+)\s*$/', (string)$texto, $m)) return null;
    return ['prefijo' => strtoupper(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')), 'numero' => $m[3]];
}

/** Filtros "número exacto" (y prefijo exacto, si se da) para le_listar_wo_paralelo(). */
function le_filtros_numero($prefijo, $numero) {
    $filtros = [crear_filtro_api('numero', (string)$numero, 4, 0)];
    if ($prefijo !== '') $filtros[] = crear_filtro_api('prefijo.nombre', $prefijo, 0, 0);
    return $filtros;
}

/** Documentos REM/FV con ese número (y prefijo, si se da). Devuelve [['tipo', 'row'], ...]. */
function le_buscar_wo_por_numero($prefijo, $numero) {
    $consultas = [];
    foreach (array_keys(LE_TIPOS_WO) as $tipo) $consultas[$tipo] = [$tipo, le_filtros_numero($prefijo, $numero)];
    $out = [];
    foreach (le_listar_wo_paralelo($consultas, 20) as $tipo => $filas) {
        foreach ($filas as $row) $out[] = ['tipo' => $tipo, 'row' => $row];
    }
    return $out;
}

/**
 * Documentos que el CRM tiene REGISTRADOS en las OP del pedido (alternativa A, 2026-09-25): el que
 * se eligió al marcar la OP como despachada en op_pendiente.php (historial_documento_op.id_wo) y,
 * para OP más viejas, el "Núm. de documento" escrito en la OP (ordenes_pedidos.n_doc, ej.
 * "CEUR 7654"), que se busca en WO por prefijo + número. Ese vínculo lo hizo una persona, así que
 * vale aunque el concepto del documento en WO tenga la OP mal escrita o no la tenga.
 *
 * $yaEncontrados ([clave => ['tipo', 'row']], ej. lo hallado por concepto) no se vuelve a buscar
 * en WO: se toma de ahí. Lo que falta se consulta todo a la vez.
 * Devuelve [clave => ['tipo', 'row', 'op' => id_op]].
 */
function le_documentos_crm(PDO $bdd, array $ops, array $yaEncontrados = []) {
    if (!$ops) return [];
    $in = implode(',', array_map('intval', array_keys($ops)));
    $porNumero = [];
    foreach ($yaEncontrados as $clave => $e) $porNumero[strtoupper((string)($e['row']['prefijo'] ?? '')) . ' ' . ($e['row']['numero'] ?? '')][] = $clave;

    $out = [];
    $consultas = [];
    $opDeConsulta = [];
    try {
        $filas = $bdd->query("SELECT DISTINCT opid, id_wo FROM historial_documento_op WHERE opid IN ($in) AND id_wo > 0")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $filas = []; // la tabla la crea op_pendiente.php la primera vez que se registra un documento
    }
    foreach ($filas as $f) {
        $id = (int)$f['id_wo'];
        // id_wo no dice si es REM o FV (los ids se repiten entre tipos) y se prefiere REM, como
        // en le_buscar_wo_por_id(): solo se evita la consulta si la REM con ese id ya está.
        if (isset($yaEncontrados["REM:$id"])) {
            if (!isset($out["REM:$id"])) $out["REM:$id"] = $yaEncontrados["REM:$id"] + ['op' => (int)$f['opid']];
            continue;
        }
        foreach (array_keys(LE_TIPOS_WO) as $tipo) {
            $consultas["id:$id:$tipo"] = [$tipo, [crear_filtro_api('id', (string)$id, 4, 0)]];
            $opDeConsulta["id:$id:$tipo"] = (int)$f['opid'];
        }
    }
    $textos = [];
    foreach ($bdd->query("SELECT id, n_doc FROM ordenes_pedidos WHERE id IN ($in) AND n_doc <> ''")->fetchAll(PDO::FETCH_ASSOC) as $f) $textos[] = $f;
    try {
        foreach ($bdd->query("SELECT DISTINCT opid AS id, n_doc FROM historial_documento_op WHERE opid IN ($in) AND n_doc <> ''")->fetchAll(PDO::FETCH_ASSOC) as $f) $textos[] = $f;
    } catch (PDOException $e) {}
    $vistos = [];
    foreach ($textos as $f) {
        $p = le_parsear_numero_documento($f['n_doc']);
        // Sin prefijo el número solo es ambiguo (se repite entre series), no se usa.
        if (!$p || $p['prefijo'] === '' || isset($vistos[$p['prefijo'] . $p['numero']])) continue;
        $vistos[$p['prefijo'] . $p['numero']] = true;
        // El prefijo es de un solo tipo (CEUR/RE22 = REM, FVE = FV): si ya se encontró, no se busca.
        if (isset($porNumero[$p['prefijo'] . ' ' . $p['numero']])) {
            foreach ($porNumero[$p['prefijo'] . ' ' . $p['numero']] as $clave) {
                if (!isset($out[$clave])) $out[$clave] = $yaEncontrados[$clave] + ['op' => (int)$f['id']];
            }
            continue;
        }
        foreach (array_keys(LE_TIPOS_WO) as $tipo) {
            $k = 'num:' . $p['prefijo'] . ' ' . $p['numero'] . ':' . $tipo;
            $consultas[$k] = [$tipo, le_filtros_numero($p['prefijo'], $p['numero'])];
            $opDeConsulta[$k] = (int)$f['id'];
        }
    }
    if (!$consultas) return $out;

    $res = le_listar_wo_paralelo($consultas, 20);
    // Primero los id (REM antes que FV), igual que antes con le_buscar_wo_por_id().
    foreach ($filas as $f) {
        $id = (int)$f['id_wo'];
        foreach (array_keys(LE_TIPOS_WO) as $tipo) {
            $fila = null;
            foreach ($res["id:$id:$tipo"] ?? [] as $row) if ((int)$row['id'] === $id) { $fila = $row; break; }
            if ($fila) {
                if (!isset($out["$tipo:$id"])) $out["$tipo:$id"] = ['tipo' => $tipo, 'row' => $fila, 'op' => (int)$f['opid']];
                break;
            }
        }
    }
    foreach ($res as $k => $rows) {
        if (strpos($k, 'num:') !== 0) continue;
        $tipo = substr($k, strrpos($k, ':') + 1);
        foreach ($rows as $row) {
            $clave = $tipo . ':' . $row['id'];
            if (!isset($out[$clave])) $out[$clave] = ['tipo' => $tipo, 'row' => $row, 'op' => $opDeConsulta[$k]];
        }
    }
    return $out;
}

/**
 * Documentos de WO del pedido: los VÁLIDOS con su cliente, ciudad e ítems, y los que se
 * encontraron por "PED <id>" pero NO se pueden usar (con el motivo). Un documento es válido si
 * alguna OP de su concepto es OP del pedido (origen "concepto") o si está registrado en una OP del
 * pedido en el CRM (origen "crm", ver le_documentos_crm()); nunca si está anulado. Cada válido
 * indica si ya está en otra lista de empaque activa.
 */
function le_documentos_pedido(PDO $bdd, $idPedido, array $ops) {
    $idPedido = (int)$idPedido;
    $encontrados = []; // "TIPO:id" => ['tipo', 'row']
    // Todas las búsquedas por concepto a la vez: cada OP y "PED <id>", en REM y FV.
    $consultas = [];
    foreach (array_keys(LE_TIPOS_WO) as $tipo) {
        foreach ($ops as $op) $consultas["op:{$op['id']}:$tipo"] = [$tipo, le_filtro_concepto($op['etiqueta'])];
        $consultas["ped:$tipo"] = [$tipo, le_filtro_concepto('PED ' . $idPedido)];
    }
    $res = le_listar_wo_paralelo($consultas);
    foreach ($consultas as $k => [$tipo]) {
        if (strpos($k, 'op:') !== 0) continue;
        foreach ($res[$k] as $row) $encontrados[$tipo . ':' . $row['id']] = ['tipo' => $tipo, 'row' => $row];
    }
    foreach (array_keys(LE_TIPOS_WO) as $tipo) {
        foreach ($res["ped:$tipo"] as $row) {
            $clave = $tipo . ':' . $row['id'];
            if (!isset($encontrados[$clave])) $encontrados[$clave] = ['tipo' => $tipo, 'row' => $row];
        }
    }
    $delCrm = le_documentos_crm($bdd, $ops, $encontrados);
    foreach ($delCrm as $clave => $r) {
        if (!isset($encontrados[$clave])) $encontrados[$clave] = ['tipo' => $r['tipo'], 'row' => $r['row']];
    }

    $validos = [];
    $descartados = [];
    foreach ($encontrados as $clave => $e) {
        $base = le_base_documento($e['tipo'], $e['row']);
        $opsConcepto = le_ops_en_concepto($base['concepto']);
        $opsDelPedido = array_values(array_intersect($opsConcepto, array_keys($ops)));
        $enCrm = isset($delCrm[$clave]);
        if ($base['anulado']) {
            // Solo se informa si el documento anulado sí era de este pedido.
            if ($opsDelPedido || $enCrm) $descartados[] = $base + ['motivo' => 'El documento está anulado en World Office.'];
            continue;
        }
        if (!$opsDelPedido && !$enCrm) {
            // Llegó por "PED <id>" (o por un LIKE más amplio) pero su OP no es de este pedido.
            if (le_ped_en_concepto($base['concepto']) === $idPedido) {
                $descartados[] = $base + ['motivo' => $opsConcepto
                    ? 'El concepto dice PED ' . $idPedido . ', pero su OP (' . implode(', ', $opsConcepto) . ') no está asociada a este pedido en el CRM.'
                    : 'El concepto dice PED ' . $idPedido . ', pero no trae ninguna OP para verificarlo.'];
            }
            continue;
        }
        $origen = $opsDelPedido ? 'concepto' : 'crm';
        // Registrado en la OP del CRM, pero su concepto nombra OTRA OP: puede ser un error al
        // registrarlo (visto en datos reales, pedido 2004) o un error de digitación en WO. Se ofrece,
        // pero desmarcado y con aviso, para que no entre sin que alguien lo revise.
        $revisar = $origen === 'crm' && $opsConcepto;
        $validos[$clave] = $base + [
            'ops' => $opsDelPedido ?: [$delCrm[$clave]['op']],
            'origen' => $origen,
            'revisar' => $revisar,
            'aviso_origen' => $revisar
                ? 'Su concepto en World Office dice OP ' . implode(', ', $opsConcepto) . ', no la OP de este pedido. Aparece porque alguien lo registró en la OP '
                  . ($ops[$delCrm[$clave]['op']]['etiqueta'] ?? $delCrm[$clave]['op']) . ' del CRM. Márcalo solo si confirmas que es de este despacho.'
                : null,
            'origen_label' => $origen === 'concepto'
                ? 'OP en el concepto'
                : 'Registrado en la OP ' . ($ops[$delCrm[$clave]['op']]['etiqueta'] ?? $delCrm[$clave]['op']) . ' del CRM (su concepto en World Office no trae la OP)',
        ];
    }

    $usados = le_documentos_usados($bdd, array_keys($validos));
    $validos = le_completar_documentos($bdd, $validos);
    foreach ($validos as $clave => &$d) $d['usado_en'] = $usados[$clave] ?? null;
    unset($d);

    usort($descartados, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    $validos = array_values($validos);
    usort($validos, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return ['validos' => $validos, 'descartados' => $descartados];
}

/**
 * Búsqueda manual por número (alternativa B, 2026-09-25) cuando el documento correcto no aparece:
 * devuelve los REM/FV con ese número, completos y con sus avisos, para que el usuario elija uno.
 * Los anulados vienen marcados y no se pueden usar.
 */
function le_buscar_documentos_manual(PDO $bdd, $idPedido, $texto) {
    $p = le_parsear_numero_documento($texto);
    if (!$p) return ['ok' => false, 'error' => 'Escribe el número del documento, con o sin prefijo (ej. CEUR 7654 o FVE 2452).', 'documentos' => []];
    $rel = le_ops_y_pedidos($bdd, $idPedido);
    $ctx = le_contexto_avisos($bdd, $rel['pedidos']);
    $solicitado = le_solicitado($bdd, $rel['pedidos']);
    $res = le_buscar_wo_por_numero($p['prefijo'], $p['numero']);
    $docs = [];
    foreach ($res as $r) $docs[] = le_base_documento($r['tipo'], $r['row']);
    $usados = le_documentos_usados($bdd, array_column($docs, 'clave'));
    $vigentes = array_filter($docs, fn($d) => !$d['anulado']);
    foreach (le_completar_documentos($bdd, $vigentes) as $i => $completo) $docs[$i] = $completo;
    foreach ($docs as &$d) {
        if (!$d['anulado']) $d['avisos'] = le_avisos_documento($d, $ctx, $solicitado);
        $d['usado_en'] = $usados[$d['clave']] ?? null;
        $d['origen'] = 'manual';
        $d['origen_label'] = 'Asociado manualmente';
    }
    unset($d);
    return ['ok' => true, 'error' => null, 'documentos' => $docs];
}

/** Documento puntual por su clave "TIPO:id" (para validar al guardar uno asociado a mano). */
function le_documento_por_clave(PDO $bdd, $clave) {
    if (!preg_match('/^(REM|FV):(\d+)$/', (string)$clave, $m)) return null;
    $r = le_buscar_wo_por_id((int)$m[2], [$m[1]]);
    if (!$r) return null;
    $d = le_base_documento($r['tipo'], $r['row']);
    return $d['anulado'] ? $d : le_completar_documento($bdd, $d);
}

/** Colegios y clientes de los pedidos, normalizados, para los avisos de le_avisos_documento(). */
function le_contexto_avisos(PDO $bdd, array $idsPedido) {
    $ctx = ['colegios' => [], 'clientes' => []];
    if (!$idsPedido) return $ctx;
    $in = implode(',', array_map('intval', $idsPedido));
    foreach ($bdd->query("SELECT c.colegio, cl.cliente, cl.id_wo FROM pedidos p JOIN colegios c ON c.id = p.id_colegio
                          LEFT JOIN clientes cl ON cl.id = p.cliente WHERE p.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['colegios'][] = ['nombre' => $r['colegio'], 'norm' => quitar_stopwords(normalizar_nombre_colegio($r['colegio']))];
        if ($r['cliente'] !== null) $ctx['clientes'][] = ['nombre' => $r['cliente'], 'id_wo' => (int)$r['id_wo'], 'norm' => le_norm_cliente($r['cliente'])];
    }
    return $ctx;
}

/**
 * Nombre de cliente comparable: sin el prefijo de temporada de las cuentas de asesor ("AA26-",
 * "AA27-" son la misma persona en temporadas distintas, con distinto id en WO) ni "venta(s)"
 * (en el CRM a veces está cortado: "...- VENTA").
 */
function le_norm_cliente($nombre) {
    $n = normalizar_nombre_colegio($nombre);
    $n = preg_replace('/^aa\d+\s*/', '', $n);
    $n = preg_replace('/\b(ventas?)\b/', '', $n);
    return trim(preg_replace('/\s+/', ' ', $n));
}

/**
 * Señales de que un documento podría NO ser de este pedido: cliente distinto al del pedido, colegio
 * del concepto distinto al del pedido, o la mayoría de sus unidades en productos que el pedido no
 * tiene. Solo avisan; no impiden usarlo.
 */
function le_avisos_documento(array $d, array $ctx, array $solicitado) {
    $avisos = [];
    if ($ctx['clientes'] && ($d['cliente'] ?? '') !== '') {
        $normDoc = le_norm_cliente($d['cliente']);
        $coincide = false;
        foreach ($ctx['clientes'] as $c) {
            if (($c['id_wo'] && $c['id_wo'] === ($d['cliente_id_wo'] ?? null)) || ($c['norm'] !== '' && $normDoc !== ''
                && ($c['norm'] === $normDoc || contiene_palabras_completas($c['norm'], $normDoc) || contiene_palabras_completas($normDoc, $c['norm'])))) {
                $coincide = true;
                break;
            }
        }
        if (!$coincide) $avisos[] = 'El cliente del documento (' . $d['cliente'] . ') no es el cliente del pedido (' . implode(', ', array_unique(array_column($ctx['clientes'], 'nombre'))) . ').';
    }
    $colConcepto = extraer_colegio_de_concepto($d['concepto'] ?? '');
    if ($colConcepto !== null && $ctx['colegios']) {
        $normDoc = quitar_stopwords(normalizar_nombre_colegio($colConcepto));
        $coincide = false;
        foreach ($ctx['colegios'] as $c) {
            if ($c['norm'] === $normDoc || ($normDoc !== '' && (contiene_palabras_completas($normDoc, $c['norm']) || contiene_palabras_completas($c['norm'], $normDoc)))) {
                $coincide = true;
                break;
            }
        }
        if (!$coincide) $avisos[] = 'El concepto dice "COL ' . $colConcepto . '", pero el colegio del pedido es ' . implode(', ', array_unique(array_column($ctx['colegios'], 'nombre'))) . '.';
    }
    $librosPedido = array_flip(array_column($solicitado, 'id_libro'));
    $total = 0;
    $fuera = 0;
    foreach ($d['items'] ?? [] as $it) {
        $total += $it['cantidad'];
        $enPedido = false;
        foreach ($it['ids_libro'] ?? [] as $idL) if (isset($librosPedido[$idL])) { $enPedido = true; break; }
        if (!$enPedido) $fuera += $it['cantidad'];
    }
    if ($total > 0 && $fuera / $total > 0.5) $avisos[] = 'La mayoría de sus unidades (' . ($fuera + 0) . ' de ' . ($total + 0) . ') son de productos que no están en el pedido.';
    return $avisos;
}

/** "11/09/2026" (listado de WO) → "2026-09-11"; deja igual lo que ya venga en yyyy-mm-dd. */
function le_fecha_wo($f) {
    $f = (string)$f;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $f, $m)) return "$m[3]-$m[2]-$m[1]";
    return substr($f, 0, 10);
}

/** Todo lo que necesita la pantalla para un pedido. */
function le_datos_pedido(PDO $bdd, $idPedido) {
    $info = le_info_pedido($bdd, $idPedido);
    if (!$info) return null;
    $rel = le_ops_y_pedidos($bdd, $idPedido);
    $docs = $rel['ops'] ? le_documentos_pedido($bdd, $idPedido, $rel['ops']) : ['validos' => [], 'descartados' => []];
    $solicitado = le_solicitado($bdd, $rel['pedidos']);
    $ctx = le_contexto_avisos($bdd, $rel['pedidos']);
    foreach ($docs['validos'] as &$d) $d['avisos'] = array_merge($d['aviso_origen'] ? [$d['aviso_origen']] : [], le_avisos_documento($d, $ctx, $solicitado));
    unset($d);
    return [
        'pedido' => $info,
        'ops' => array_values($rel['ops']),
        'pedidos' => $rel['pedidos'],
        'solicitado' => $solicitado,
        'documentos' => $docs['validos'],
        'descartados' => $docs['descartados'],
    ];
}

/**
 * Todo lo que necesita la pantalla (y el guardado) para los documentos que el usuario marcó de un
 * cliente: se vuelven a listar los del cliente en WO (no se confía en lo que manda el navegador),
 * se validan (del cliente, no anulados, no en otra lista activa) y se completan con cliente, ciudad
 * e ítems. Nada de pedidos ni OP del CRM: la lista es solo lo que traen los documentos.
 */
function le_datos_documentos(PDO $bdd, $idsCliente, array $claves) {
    $claves = array_values(array_unique(array_map('strval', $claves)));
    if (!$claves) return ['ok' => false, 'error' => 'Selecciona al menos una remisión o factura.'];
    $lista = le_documentos_cliente($bdd, $idsCliente);
    if (!$lista['ok']) return ['ok' => false, 'error' => $lista['error']];
    $porClave = array_column($lista['documentos'], null, 'clave');
    $docs = [];
    foreach ($claves as $c) {
        if (!isset($porClave[$c])) return ['ok' => false, 'error' => "El documento $c no está a nombre de los clientes elegidos en World Office."];
        if ($porClave[$c]['bloqueo']) return ['ok' => false, 'error' => $porClave[$c]['documento'] . ': ' . $porClave[$c]['bloqueo'] . '.'];
        $docs[$c] = $porClave[$c];
    }
    $docs = le_completar_documentos($bdd, $docs);
    foreach ($docs as $d) {
        if ($d['error_items']) return ['ok' => false, 'error' => "No se pudieron consultar los productos de {$d['documento']} en World Office. Intenta de nuevo."];
    }
    $docs = array_values($docs);
    usort($docs, fn($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: $a['id_wo'] - $b['id_wo']);
    return ['ok' => true, 'error' => null, 'documentos' => $docs];
}

/**
 * Consolida los ítems de los documentos elegidos (sin duplicar: un mismo producto en varios
 * documentos se suma una sola vez por producto) y los cruza contra lo solicitado.
 * Devuelve ['despachado' => [id_inventario => ítem], 'cruce' => [líneas]].
 */
function le_consolidar(array $documentos, array $solicitado) {
    $despachado = [];
    foreach ($documentos as $d) {
        foreach ($d['items'] as $it) {
            $k = (int)$it['id_inventario'];
            if (!isset($despachado[$k])) $despachado[$k] = $it + ['documentos' => []];
            else $despachado[$k]['cantidad'] += $it['cantidad'];
            $despachado[$k]['documentos'][] = $d['documento'];
        }
    }

    $cruce = [];
    $solPorLibro = [];
    foreach ($solicitado as $s) $solPorLibro[$s['id_libro']] = $s;
    $librosCubiertos = [];
    foreach ($despachado as $k => $it) {
        // Suma lo solicitado de cualquier copia del libro en el catálogo (ver le_items_documento);
        // cada libro del pedido se cuenta una sola vez aunque dos productos de WO apunten a él.
        $sol = 0;
        foreach ($it['ids_libro'] ?? [] as $idL) {
            if (!isset($solPorLibro[$idL]) || isset($librosCubiertos[$idL])) continue;
            $sol += $solPorLibro[$idL]['cantidad'];
            $librosCubiertos[$idL] = true;
            // Se guarda el libro que de verdad está en el pedido, no una copia cualquiera.
            if (!isset($solPorLibro[$despachado[$k]['id_libro']])) $despachado[$k]['id_libro'] = $idL;
        }
        $cruce[] = [
            'id_inventario' => $it['id_inventario'],
            'codigo' => $it['codigo'],
            'descripcion' => $it['descripcion'],
            'solicitada' => $sol,
            'despachada' => $it['cantidad'],
            'diferencia' => round($it['cantidad'] - $sol, 2),
        ];
    }
    foreach ($solicitado as $s) {
        if (isset($librosCubiertos[$s['id_libro']])) continue;
        $cruce[] = [
            'id_inventario' => null,
            'codigo' => $s['isbn'],
            'descripcion' => $s['libro'],
            'solicitada' => $s['cantidad'],
            'despachada' => 0,
            'diferencia' => -$s['cantidad'],
        ];
    }
    return ['despachado' => $despachado, 'cruce' => $cruce];
}

/**
 * Valida y guarda una lista de empaque. $entrada viene del navegador, pero NADA de lo que define
 * el despacho se toma de ahí: documentos, cliente, ciudad e ítems se vuelven a consultar en World
 * Office en este momento; del navegador solo se toman los clientes, qué documentos eligió, el reparto
 * por cajas, ciudad, dirección, pesos y "Empacado por". Desde 2026-09-28 la lista se arma solo con
 * documentos del cliente, sin relación con pedidos ni OP: id_pedido = 0 y pedidos/ops/colegio vacíos.
 * Devuelve ['ok' => bool, 'error' => string|null, 'id' => int].
 */
function le_guardar(PDO $bdd, array $entrada, $idUsuario) {
    le_crear_tablas($bdd);
    $idsCliente = array_values(array_filter(array_map('intval', (array)($entrada['clientes'] ?? []))));
    if (!$idsCliente) return ['ok' => false, 'error' => 'Selecciona al menos un cliente.'];

    $datos = le_datos_documentos($bdd, $idsCliente, (array)($entrada['documentos'] ?? []));
    if (!$datos['ok']) return ['ok' => false, 'error' => $datos['error']];
    $docs = $datos['documentos'];

    $cons = le_consolidar($docs, []);
    $despachado = $cons['despachado'];
    if (!$despachado) return ['ok' => false, 'error' => 'Los documentos elegidos no tienen productos.'];

    // Reparto por cajas: cada caja con al menos un producto, cantidades enteras > 0, y la suma por
    // producto EXACTAMENTE igual a lo despachado.
    $cajasIn = array_values((array)($entrada['cajas'] ?? []));
    if (!$cajasIn) return ['ok' => false, 'error' => 'Agrega al menos una caja.'];
    $asignado = [];
    $cajas = [];
    foreach ($cajasIn as $i => $c) {
        $num = $i + 1;
        $peso = trim((string)($c['peso'] ?? ''));
        if ($peso !== '' && (!is_numeric($peso) || (float)$peso < 0)) return ['ok' => false, 'error' => "El peso de la caja $num no es válido."];
        $items = [];
        foreach ((array)($c['items'] ?? []) as $it) {
            $k = (int)($it['id_inventario'] ?? 0);
            $cant = $it['cantidad'] ?? 0;
            if (!isset($despachado[$k])) return ['ok' => false, 'error' => "La caja $num tiene un producto que no está en los documentos elegidos."];
            if (!is_numeric($cant) || (float)$cant <= 0 || floor((float)$cant) != (float)$cant) return ['ok' => false, 'error' => "La caja $num tiene una cantidad no válida para {$despachado[$k]['descripcion']}."];
            $items[$k] = ($items[$k] ?? 0) + (int)$cant;
            $asignado[$k] = ($asignado[$k] ?? 0) + (int)$cant;
        }
        if (!$items) return ['ok' => false, 'error' => "La caja $num está vacía. Asígnale productos o elimínala."];
        $cajas[] = ['numero' => $num, 'peso' => $peso === '' ? null : round((float)$peso, 2), 'items' => $items];
    }
    foreach ($despachado as $k => $it) {
        $a = $asignado[$k] ?? 0;
        if (abs($a - $it['cantidad']) > 0.001) {
            return ['ok' => false, 'error' => "{$it['descripcion']}: se despacharon " . ($it['cantidad'] + 0) . " y en las cajas hay $a."];
        }
    }

    $pesoNeto = trim((string)($entrada['peso_neto'] ?? ''));
    if ($pesoNeto !== '' && (!is_numeric($pesoNeto) || (float)$pesoNeto < 0)) return ['ok' => false, 'error' => 'El peso neto no es válido.'];
    // La ciudad la puede escribir el usuario (se sugiere la de WO en pantalla).
    $ciudad = mb_substr(trim((string)($entrada['ciudad'] ?? '')), 0, 150);
    $direccion = mb_substr(trim((string)($entrada['direccion'] ?? '')), 0, 500);
    if ($direccion === '') return ['ok' => false, 'error' => 'Escribe la dirección de entrega.'];
    $personaRecibe = mb_substr(trim((string)($entrada['persona_recibe'] ?? '')), 0, 150);
    $empacadoPor = mb_substr(trim((string)($entrada['empacado_por'] ?? '')), 0, 150);
    if ($empacadoPor === '') return ['ok' => false, 'error' => 'Escribe quién empacó.'];

    $primero = $docs[0];
    $totalUnidades = (int)array_sum($asignado);
    // Los documentos pueden ser de varios clientes: `cliente` guarda todos los nombres (como están en
    // WO) y id_cliente / cliente_id_wo solo se llenan si es uno solo.
    $nombres = array_values(array_unique(array_filter(array_map(fn($d) => trim($d['cliente']), $docs))));
    $idsUsados = array_values(array_unique(array_column($docs, 'id_cliente')));
    $idsWoUsados = array_values(array_unique(array_column($docs, 'cliente_id_wo')));

    try {
        $bdd->beginTransaction();
        $consecutivo = (int)$bdd->query("SELECT ultimo FROM listas_empaque_consecutivo WHERE id = 1 FOR UPDATE")->fetchColumn() + 1;
        $bdd->prepare("UPDATE listas_empaque_consecutivo SET ultimo = ? WHERE id = 1")->execute([$consecutivo]);

        $bdd->prepare("INSERT INTO listas_empaque
            (consecutivo, id_pedido, id_cliente, pedidos, ops, colegio, empresa, cliente, cliente_id_wo, ciudad, direccion,
             persona_recibe, peso_neto, empacado_por, total_unidades, total_cajas, cruce_json, id_usuario, fecha)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute([
            // Sin pedidos, OP, colegio ni cruce: la lista no tiene relación con el CRM (2026-09-28).
            $consecutivo, 0, count($idsUsados) === 1 ? $idsUsados[0] : null, '', '',
            '', $primero['empresa'], mb_substr(implode(', ', $nombres), 0, 255), count($idsWoUsados) === 1 ? $idsWoUsados[0] : null,
            $ciudad, $direccion, $personaRecibe, $pesoNeto === '' ? null : round((float)$pesoNeto, 2), $empacadoPor,
            $totalUnidades, count($cajas), '[]', (int)$idUsuario,
        ]);
        $idLista = (int)$bdd->lastInsertId();

        $insDoc = $bdd->prepare("INSERT INTO listas_empaque_documentos (id_lista, id_wo, tipo_documento, prefijo, numero, fecha_doc, concepto, origen, uso_activo)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($docs as $d) {
            $insDoc->execute([$idLista, $d['id_wo'], $d['tipo'], $d['prefijo'], $d['numero'], $d['fecha'] ?: null, $d['concepto'], 'cliente']);
        }
        $insCaja = $bdd->prepare("INSERT INTO listas_empaque_cajas (id_lista, numero, peso) VALUES (?, ?, ?)");
        $insItem = $bdd->prepare("INSERT INTO listas_empaque_items (id_lista, caja, id_inventario_wo, codigo, descripcion, id_libro, cantidad) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($cajas as $c) {
            $insCaja->execute([$idLista, $c['numero'], $c['peso']]);
            foreach ($c['items'] as $k => $cant) {
                $it = $despachado[$k];
                $insItem->execute([$idLista, $c['numero'], $k, $it['codigo'], mb_substr($it['descripcion'], 0, 255), $it['id_libro'], $cant]);
            }
        }
        $bdd->commit();
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        // 23000 = llave única: otro usuario guardó una lista con alguno de estos documentos al mismo tiempo.
        if ($e->getCode() === '23000') return ['ok' => false, 'error' => 'Alguno de los documentos acaba de quedar en otra lista de empaque. Vuelve a seleccionar el cliente.'];
        return ['ok' => false, 'error' => 'No se pudo guardar la lista de empaque.'];
    }
    return ['ok' => true, 'error' => null, 'id' => $idLista, 'consecutivo' => le_formatear_consecutivo($consecutivo)];
}

/** Lista guardada completa (encabezado, documentos, cajas con sus ítems), para ver/PDF. */
function le_obtener_lista(PDO $bdd, $idLista) {
    $stmt = $bdd->prepare("SELECT l.*, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                  CONCAT(TRIM(ua.nombres), ' ', TRIM(ua.apellidos)) AS usuario_anula
                           FROM listas_empaque l
                           LEFT JOIN usuarios u ON u.id = l.id_usuario
                           LEFT JOIN usuarios ua ON ua.id = l.id_usuario_anula
                           WHERE l.id = ?");
    $stmt->execute([(int)$idLista]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$l) return null;

    $stmt = $bdd->prepare("SELECT id_wo, tipo_documento, prefijo, numero, fecha_doc, concepto, origen FROM listas_empaque_documentos WHERE id_lista = ? ORDER BY fecha_doc, id");
    $stmt->execute([$l['id']]);
    $l['documentos'] = array_map(fn($d) => $d + [
        'tipo_label' => LE_TIPOS_WO[$d['tipo_documento']] ?? $d['tipo_documento'],
        'documento' => trim($d['prefijo'] . ' ' . $d['numero']),
        'origen_label' => ['concepto' => 'OP en el concepto de World Office', 'crm' => 'Registrado en la OP del CRM', 'manual' => 'Asociado manualmente',
                           'cliente' => 'Documento del cliente en World Office'][$d['origen']] ?? $d['origen'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $bdd->prepare("SELECT numero, peso FROM listas_empaque_cajas WHERE id_lista = ? ORDER BY numero");
    $stmt->execute([$l['id']]);
    $cajas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $cajas[(int)$c['numero']] = ['numero' => (int)$c['numero'], 'peso' => $c['peso'], 'items' => [], 'unidades' => 0];
    $stmt = $bdd->prepare("SELECT caja, codigo, descripcion, cantidad FROM listas_empaque_items WHERE id_lista = ? ORDER BY caja, descripcion");
    $stmt->execute([$l['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $n = (int)$it['caja'];
        if (!isset($cajas[$n])) $cajas[$n] = ['numero' => $n, 'peso' => null, 'items' => [], 'unidades' => 0];
        $cajas[$n]['items'][] = $it;
        $cajas[$n]['unidades'] += (int)$it['cantidad'];
    }
    $l['cajas'] = array_values($cajas);
    $l['cruce'] = json_decode((string)$l['cruce_json'], true) ?: [];
    $l['consecutivo_fmt'] = le_formatear_consecutivo($l['consecutivo']);
    return $l;
}

/** Historial con filtros opcionales (consecutivo, pedido, cliente, rango de fechas). */
function le_historial(PDO $bdd, array $f) {
    $where = ['1=1'];
    $params = [];
    if (($f['consecutivo'] ?? '') !== '' && ctype_digit(ltrim((string)$f['consecutivo'], '0') ?: '0')) {
        $where[] = 'l.consecutivo = ?';
        $params[] = (int)$f['consecutivo'];
    }
    if (($f['pedido'] ?? '') !== '' && ctype_digit((string)$f['pedido'])) {
        // Busca en todos los pedidos que cubre la lista (OP agrupadas), no solo el seleccionado.
        $where[] = "(l.id_pedido = ? OR FIND_IN_SET(?, REPLACE(l.pedidos, ' ', '')))";
        $params[] = (int)$f['pedido'];
        $params[] = (string)(int)$f['pedido'];
    }
    if (trim($f['cliente'] ?? '') !== '') {
        $where[] = '(l.cliente LIKE ? OR l.colegio LIKE ?)';
        $params[] = '%' . trim($f['cliente']) . '%';
        $params[] = '%' . trim($f['cliente']) . '%';
    }
    if (($f['desde'] ?? '') !== '') { $where[] = 'DATE(l.fecha) >= ?'; $params[] = $f['desde']; }
    if (($f['hasta'] ?? '') !== '') { $where[] = 'DATE(l.fecha) <= ?'; $params[] = $f['hasta']; }

    $stmt = $bdd->prepare("SELECT l.id, l.consecutivo, l.id_pedido, l.pedidos, l.ops, l.colegio, l.cliente, l.ciudad,
                                  l.total_unidades, l.total_cajas, l.estado, l.fecha,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                  (SELECT GROUP_CONCAT(CONCAT(d.prefijo, ' ', d.numero) ORDER BY d.id SEPARATOR ', ')
                                   FROM listas_empaque_documentos d WHERE d.id_lista = l.id) AS documentos
                           FROM listas_empaque l
                           LEFT JOIN usuarios u ON u.id = l.id_usuario
                           WHERE " . implode(' AND ', $where) . "
                           ORDER BY l.consecutivo DESC LIMIT 300");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Anula una lista: queda en el historial marcada como anulada y libera sus documentos de WO. */
function le_anular(PDO $bdd, $idLista, $motivo, $idUsuario) {
    $motivo = mb_substr(trim((string)$motivo), 0, 500);
    if ($motivo === '') return ['ok' => false, 'error' => 'Escribe el motivo de la anulación.'];
    try {
        $bdd->beginTransaction();
        $stmt = $bdd->prepare("UPDATE listas_empaque SET estado = 0, motivo_anulacion = ?, id_usuario_anula = ?, fecha_anulacion = NOW() WHERE id = ? AND estado = 1");
        $stmt->execute([$motivo, (int)$idUsuario, (int)$idLista]);
        if ($stmt->rowCount() === 0) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'La lista no existe o ya estaba anulada.'];
        }
        $bdd->prepare("UPDATE listas_empaque_documentos SET uso_activo = NULL WHERE id_lista = ?")->execute([(int)$idLista]);
        $bdd->commit();
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        return ['ok' => false, 'error' => 'No se pudo anular la lista.'];
    }
    return ['ok' => true, 'error' => null];
}
