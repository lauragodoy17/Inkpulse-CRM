<?php
/**
 * /includes/devoluciones_muestras_datos.php
 * Módulo "Devoluciones" de muestras (devoluciones_muestras.php), pedido por el usuario 2026-10-05
 * para reemplazar el formulario manual devol_muestras_sa.php?tp=1:
 *   muestreo Entregado → su(s) OP → documento(s) FV/REM de World Office cuyo concepto trae
 *   "OP <año>-<id>" → títulos y cantidades realmente despachados → cantidades a devolver, con
 *   máximo = despachado − ya devuelto en devoluciones anteriores del mismo muestreo.
 *
 * Decisiones del usuario: solo solicitudes de MUESTREO (no pedidos sin adopción de muestras); sin
 * tablas nuevas — la devolución se guarda en las de siempre (`devoluciones` tipo=1 +
 * `libros_devol`), con columnas nuevas para la trazabilidad (ver dm_asegurar_columnas()).
 *
 * Cómo se reconoce el documento de WO: el concepto lo escribe el sistema al generar la OP, ej.
 * "OP 2026-6146; PED 1953; COL LICEO ...; MUESTRAS CAL A2027" (verificado en vivo 2026-10-05). Se
 * busca por la OP (número del CRM, no texto libre) y se confirma con una expresión regular que el
 * número sea EXACTO (LIKE "OP 2026-614" también trae la 6146). El "PED" del concepto lo escribe a
 * mano el usuario y los números de muestreo, pedido y pedido sin adopción se cruzan entre sí, así
 * que NO se usa para buscar: solo se muestra un aviso si no coincide con el muestreo.
 */
require_once __DIR__ . '/api_wo_documentos_op.php'; // listar_documentos_salida_almacen, crear_filtro_api, WO_TIPOS_DESPACHO

/** Estado "Entregado" de muestreos (tabla estados_pedidos). */
const DM_ESTADO_ENTREGADO = 4;
/** Estado "Anulado" de devoluciones: no cuenta como devuelto. */
const DM_ESTADO_DEVOL_ANULADA = 3;
/** Estado de legalizaciones (muestreos_e) eliminadas en muestras_entregadas.php: no cuentan como legalizadas. */
const DM_ESTADO_LEGALIZ_ANULADA = 0;

/**
 * Columnas de trazabilidad en las tablas existentes (sin tablas nuevas, decisión del usuario).
 * Las devoluciones y legalizaciones registradas con los formularios anteriores quedan con estas
 * columnas en NULL. Debe llamarse ANTES de abrir cualquier transacción (el DDL hace commit
 * implícito en MySQL).
 */
function dm_asegurar_columnas(PDO $bdd) {
    $alter = [
        "ALTER TABLE devoluciones ADD COLUMN id_muestreo INT NULL DEFAULT NULL",
        "ALTER TABLE devoluciones ADD COLUMN ops_muestreo VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones ADD COLUMN documentos_wo VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE devoluciones ADD INDEX idx_devoluciones_id_muestreo (id_muestreo)",
        "ALTER TABLE libros_devol ADD COLUMN producto_wo INT NULL DEFAULT NULL",
        "ALTER TABLE libros_devol ADD COLUMN cant_despachada INT NULL DEFAULT NULL",
        "ALTER TABLE libros_devol ADD COLUMN documentos_wo VARCHAR(255) NULL DEFAULT NULL",
        // Legalizaciones (legalizaciones_muestras.php), pedido por el usuario 2026-10-07.
        "ALTER TABLE muestreos_e ADD COLUMN id_muestreo INT NULL DEFAULT NULL",
        "ALTER TABLE muestreos_e ADD COLUMN ops_muestreo VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE muestreos_e ADD COLUMN documentos_wo VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE muestreos_e ADD INDEX idx_muestreos_e_id_muestreo (id_muestreo)",
        "ALTER TABLE libros_muestreos_e ADD COLUMN producto_wo INT NULL DEFAULT NULL",
        "ALTER TABLE libros_muestreos_e ADD COLUMN cant_despachada INT NULL DEFAULT NULL",
        "ALTER TABLE libros_muestreos_e ADD COLUMN documentos_wo VARCHAR(255) NULL DEFAULT NULL",
        // Unidades despachadas en WO (títulos del catálogo del CRM) del muestreo, guardadas cada vez
        // que se consulta su detalle: permiten saber en la lista si ya está Completado sin llamar a WO.
        "ALTER TABLE muestreos ADD COLUMN unidades_despachadas_wo INT NULL DEFAULT NULL",
        // 'parcial' o 'completa' (pedido por el usuario 2026-10-07): una completa cierra el pedido en
        // ese módulo aunque queden unidades. NULL en lo registrado antes de este cambio.
        "ALTER TABLE devoluciones ADD COLUMN cierre VARCHAR(10) NULL DEFAULT NULL",
        "ALTER TABLE muestreos_e ADD COLUMN cierre VARCHAR(10) NULL DEFAULT NULL",
        // Devolución de varios pedidos del mismo colegio a la vez (pedido por la usuaria 2026-10-15):
        // devoluciones.ids_muestreos = "1901,1910" (id_muestreo queda con el primero) y cada título
        // guarda en libros_devol.reparto cuánto salió de cada pedido: "1901:3:5870,1910:2:5870"
        // (pedido:cantidad:producto WO). NULL en lo registrado antes = todo del id_muestreo.
        "ALTER TABLE devoluciones ADD COLUMN ids_muestreos VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE libros_devol ADD COLUMN reparto VARCHAR(500) NULL DEFAULT NULL",
        // Lo mismo para las legalizaciones de varios pedidos (pedido por la usuaria 2026-10-27).
        "ALTER TABLE muestreos_e ADD COLUMN ids_muestreos VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE libros_muestreos_e ADD COLUMN reparto VARCHAR(500) NULL DEFAULT NULL",
    ];
    foreach ($alter as $sql) {
        try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
    }
}

/**
 * Quién puede usar el módulo: mismos usuarios que ven el menú Devoluciones (todos menos tipo 4, 5
 * y 8). Termina la ejecución si no hay sesión (php/aut.php redirige sin cortar).
 */
function dm_validar_acceso($json = false, $raiz = '') {
    $sesion = ($_SESSION['autentificado'] ?? '') === 'SI';
    if ($sesion && !in_array((int)($_SESSION['tipo'] ?? 0), [4, 5, 8], true)) return;
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $sesion ? 'No tienes acceso a este módulo.' : 'Tu sesión terminó, vuelve a iniciar sesión.']);
    } elseif ($sesion) {
        header('Location: ' . $raiz . 'index.php');
    } // sin sesión: php/aut.php ya mandó a iniciar sesión
    exit;
}

/**
 * Alcance de colegios por tipo de usuario — el mismo de devol_muestras_sa.php: tipo 1 y 2 todos;
 * tipo 3 solo su zona; los demás su zona o las de su zona madre. Devuelve [sql, params] para un
 * WHERE con el alias `c` (colegios).
 */
function dm_filtro_alcance_sql() {
    $tipo = (int)($_SESSION['tipo'] ?? 0);
    if ($tipo === 1 || $tipo === 2) return ['', []];
    $zona = (string)($_SESSION['zona'] ?? '');
    if ($tipo === 3) return [' AND c.cod_zona = ?', [$zona]];
    return [' AND (c.cod_zona = ? OR c.zona_madre = ?)', [$zona, $zona]];
}

/**
 * Muestreos en estado Entregado dentro del alcance del usuario, con sus OP (no anuladas), el número
 * de documento que tenga la OP en el CRM y cuántas devoluciones activas ya tienen. Con $idPeriodo,
 * solo los de ese período (muestreos.id_periodo).
 */
function dm_listar_muestreos_entregados(PDO $bdd, $idPeriodo = null) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    if ($idPeriodo) {
        $alcance .= ' AND m.id_periodo = ?';
        $params[] = (int)$idPeriodo;
    }
    $sql = "SELECT m.id, m.fecha, m.unidades_despachadas_wo, c.id AS id_colegio, c.colegio,
                   CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante,
                   GROUP_CONCAT(DISTINCT CONCAT(op.`año`, '-', op.id) ORDER BY op.id SEPARATOR ', ') AS ops,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(op.n_doc), '') ORDER BY op.id SEPARATOR ', ') AS docs_op,
                   (SELECT COUNT(*) FROM devoluciones d WHERE (d.id_muestreo = m.id OR FIND_IN_SET(m.id, d.ids_muestreos)) AND d.estado <> " . DM_ESTADO_DEVOL_ANULADA . ") AS n_devoluciones,
                   (SELECT COUNT(*) FROM muestreos_e me WHERE (me.id_muestreo = m.id OR FIND_IN_SET(m.id, me.ids_muestreos)) AND me.estado <> " . DM_ESTADO_LEGALIZ_ANULADA . ") AS n_legalizaciones
            FROM muestreos m
            JOIN colegios c ON c.id = m.id_colegio
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            LEFT JOIN ordenes_pedidos op ON op.id_muestreo = m.id AND op.estado <> 4
            WHERE m.estado = " . DM_ESTADO_ENTREGADO . $alcance . "
            GROUP BY m.id
            ORDER BY m.id DESC";
    $stmt = $bdd->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Marca cada muestreo de la lista con 'completado' (pedido por el usuario 2026-10-07): ya no queda
 * nada por devolver (modo 'devolucion') o por legalizar (modo 'legalizacion'). Cada módulo tiene su
 * propio estado. Como el tope es por título, sumar lo registrado y compararlo con el total despachado
 * basta: solo llegan a ser iguales si todos los títulos están completos. Los muestreos cuyo detalle
 * nunca se abrió (unidades_despachadas_wo NULL) no se marcan. Anular una devolución o eliminar una
 * legalización lo devuelve a pendiente solo, porque se calcula en vivo.
 */
function dm_marcar_completados(PDO $bdd, array $muestreos, $modo) {
    // Pedidos cerrados a mano con una devolución/legalización "completa" activa (una de varios pedidos
    // cierra todos los suyos).
    $sqlCerrados = $modo === 'legalizacion'
        ? "SELECT id_muestreo, ids_muestreos FROM muestreos_e WHERE id_muestreo IS NOT NULL AND cierre = 'completa' AND estado <> " . DM_ESTADO_LEGALIZ_ANULADA
        : "SELECT id_muestreo, ids_muestreos FROM devoluciones WHERE id_muestreo IS NOT NULL AND cierre = 'completa' AND estado <> " . DM_ESTADO_DEVOL_ANULADA;
    $cerrados = [];
    foreach ($bdd->query($sqlCerrados)->fetchAll(PDO::FETCH_ASSOC) as $d) {
        foreach (dm_ids_de_devolucion($d) as $id) $cerrados[$id] = true;
    }
    $registrado = [];
    foreach (dm_registrado_repartido($bdd, $modo) as $x) {
        if ($x['producto_wo'] !== null) $registrado[$x['id_muestreo']] = ($registrado[$x['id_muestreo']] ?? 0) + $x['cantidad'];
    }
    foreach ($muestreos as &$m) {
        $despachadas = $m['unidades_despachadas_wo'];
        $m['completado'] = isset($cerrados[$m['id']])
            || ($despachadas !== null && (int)$despachadas > 0 && (int)($registrado[$m['id']] ?? 0) >= (int)$despachadas);
    }
    unset($m);
    return $muestreos;
}

/**
 * Devolución (o legalización, en modo 'legalizacion') activa marcada como "completa" que cerró el
 * pedido en ese módulo: ['id','fecha','usuario'] o null.
 */
function dm_cierre_completo(PDO $bdd, $idMuestreo, $modo) {
    $sql = $modo === 'legalizacion'
        ? "SELECT x.id, x.created_at AS fecha, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario FROM muestreos_e x
           LEFT JOIN usuarios u ON u.id = x.id_usuario WHERE (x.id_muestreo = ? OR FIND_IN_SET(?, x.ids_muestreos)) AND x.cierre = 'completa' AND x.estado <> " . DM_ESTADO_LEGALIZ_ANULADA
        : "SELECT x.id, x.created_at AS fecha, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario FROM devoluciones x
           LEFT JOIN usuarios u ON u.id = x.id_usuario WHERE (x.id_muestreo = ? OR FIND_IN_SET(?, x.ids_muestreos)) AND x.cierre = 'completa' AND x.estado <> " . DM_ESTADO_DEVOL_ANULADA;
    $stmt = $bdd->prepare($sql . " ORDER BY x.id LIMIT 1");
    $stmt->execute([(int)$idMuestreo, (int)$idMuestreo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Pedidos de muestras de una devolución o legalización (fila con id_muestreo e ids_muestreos): [id, ...]. */
function dm_ids_de_devolucion(array $devolucion) {
    $ids = array_filter(array_map('intval', explode(',', (string)($devolucion['ids_muestreos'] ?? ''))));
    return $ids ? array_values($ids) : array_filter([(int)($devolucion['id_muestreo'] ?? 0)]);
}

/**
 * Unidades devueltas en devoluciones activas vinculadas a pedidos de muestras, repartidas por pedido
 * según libros_devol.reparto (ver dm_asegurar_columnas()); sin reparto, todo es del id_muestreo de la
 * devolución. Con $idMuestreo solo las de ese pedido. Devuelve [['id_muestreo','producto_wo',
 * 'id_libro','cantidad'], ...].
 */
function dm_devuelto_repartido(PDO $bdd, $idMuestreo = null) {
    return dm_registrado_repartido($bdd, 'devolucion', $idMuestreo);
}

/**
 * Igual que dm_devuelto_repartido() para el modo dado: 'devolucion' (devoluciones + libros_devol) o
 * 'legalizacion' (muestreos_e + libros_muestreos_e, con libros_muestreos_e.reparto).
 */
function dm_registrado_repartido(PDO $bdd, $modo, $idMuestreo = null) {
    $sql = $modo === 'legalizacion'
        ? "SELECT d.id_muestreo, ld.reparto, ld.producto_wo, ld.id_libro, ld.cantidad
           FROM libros_muestreos_e ld JOIN muestreos_e d ON d.codigo = ld.cod_muestreo
           WHERE d.id_muestreo IS NOT NULL AND d.estado <> " . DM_ESTADO_LEGALIZ_ANULADA
        : "SELECT d.id_muestreo, ld.reparto, ld.producto_wo, ld.id_libro, ld.cantidad
           FROM libros_devol ld JOIN devoluciones d ON d.codigo = ld.cod_pedido
           WHERE d.id_muestreo IS NOT NULL AND d.estado <> " . DM_ESTADO_DEVOL_ANULADA;
    $params = [];
    if ($idMuestreo !== null) {
        $sql .= " AND (d.id_muestreo = ? OR FIND_IN_SET(?, d.ids_muestreos))";
        $params = [(int)$idMuestreo, (int)$idMuestreo];
    }
    $stmt = $bdd->prepare($sql);
    $stmt->execute($params);
    $res = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (trim((string)$r['reparto']) === '') {
            $res[] = ['id_muestreo' => (int)$r['id_muestreo'], 'producto_wo' => $r['producto_wo'] === null ? null : (int)$r['producto_wo'],
                      'id_libro' => (int)$r['id_libro'], 'cantidad' => (int)$r['cantidad']];
            continue;
        }
        foreach (explode(',', $r['reparto']) as $parte) {
            [$idM, $cant, $pid] = array_map('intval', explode(':', $parte) + [0, 0, 0]);
            $res[] = ['id_muestreo' => $idM, 'producto_wo' => $pid ?: null, 'id_libro' => (int)$r['id_libro'], 'cantidad' => $cant];
        }
    }
    if ($idMuestreo !== null) $res = array_values(array_filter($res, fn($x) => $x['id_muestreo'] === (int)$idMuestreo));
    return $res;
}

/** Mensaje para un pedido ya cerrado con una devolución/legalización completa. */
function dm_mensaje_cerrado($modo, array $cierre) {
    $leg = $modo === 'legalizacion';
    return 'Este pedido ya está Completado: se cerró con la ' . ($leg ? 'legalización' : 'devolución') . ' completa #' . (int)$cierre['id']
        . ($cierre['usuario'] ? ' de ' . trim($cierre['usuario']) : '') . '. Ya no se pueden registrar más ' . ($leg ? 'legalizaciones' : 'devoluciones') . '.';
}

/** Muestreo Entregado dentro del alcance del usuario, o null. */
function dm_obtener_muestreo(PDO $bdd, $idMuestreo) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    // Cliente del muestreo = el de su OP (la cuenta de muestras del asesor, ej. "AA27- CARLOS
    // MANJARRES - MUESTRAS"); la devolución queda a nombre de ese mismo cliente (pedido por el
    // usuario 2026-10-05). El muestreo no guarda cliente propio.
    $stmt = $bdd->prepare("SELECT m.id, m.codigo, m.fecha, m.id_colegio, m.tipo, c.colegio,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante,
                                  cl.id AS id_cliente, cl.cliente
                           FROM muestreos m
                           JOIN colegios c ON c.id = m.id_colegio
                           LEFT JOIN usuarios u ON u.id = m.id_usuario
                           LEFT JOIN clientes cl ON cl.id = (SELECT op.cliente FROM ordenes_pedidos op
                                                             WHERE op.id_muestreo = m.id AND op.estado <> 4 AND op.cliente > 0
                                                             ORDER BY op.id LIMIT 1)
                           WHERE m.id = ? AND m.estado = " . DM_ESTADO_ENTREGADO . $alcance);
    $stmt->execute(array_merge([(int)$idMuestreo], $params));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** OP (no anuladas) del muestreo: [['id', 'anio', 'n_doc'], ...]. */
function dm_ops_muestreo(PDO $bdd, $idMuestreo) {
    $stmt = $bdd->prepare("SELECT id, `año` AS anio, n_doc FROM ordenes_pedidos WHERE id_muestreo = ? AND estado <> 4 ORDER BY id");
    $stmt->execute([(int)$idMuestreo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Número de OP en un concepto de WO; acepta "OP 2026-6146", "OP # 2026 - 6146", "OP. 2026-6146" y
 * errores de escritura en el año como "OP 2027*-6079" (caso real, pedido de muestras #1910).
 */
function dm_op_de_concepto($concepto) {
    return preg_match('/\bOP\.?\s*#?\s*\d{4}\W{0,3}-\s*(\d+)/i', (string)$concepto, $m) ? (int)$m[1] : null;
}

/** Número "PED" de un concepto de WO (texto escrito a mano, solo informativo). */
function dm_ped_de_concepto($concepto) {
    return preg_match('/\bPED\.?\s*#?\s*(\d+)/i', (string)$concepto, $m) ? (int)$m[1] : null;
}

/**
 * Documentos FV/REM de World Office (no anulados) cuya OP en el concepto es una de $ops, con sus
 * renglones. Todas las consultas van en paralelo. Devuelve ['ok', 'error', 'documentos' => [
 * ['id','tipo','documento','fecha','concepto','op','ped','renglones' => [...]], ...]].
 * Con $prefijos (ej. ['RE22'] en legalizaciones, ['RE22', 'CEUR'] en devoluciones) solo se consultan
 * remisiones (REM) de esos prefijos: con uno solo el filtro va en la petición a WO, y en todos los casos
 * se vuelve a comprobar en cada documento recibido.
 */
function dm_documentos_wo_de_ops(array $ops, ?array $prefijos = null) {
    if (!$ops) return ['ok' => true, 'error' => null, 'documentos' => []];

    $tipos = $prefijos === null ? array_keys(WO_TIPOS_DESPACHO) : ['REM'];
    $opsPorId = [];
    $peticiones = [];
    foreach ($ops as $op) {
        $opsPorId[(int)$op['id']] = $op;
        // Formas en que aparece la OP en el concepto: "OP 2026-6146", "OP # 2026 - 6146" y, cuando el
        // año quedó mal escrito ("OP 2027*-6079"), "-6079;". Cada coincidencia se confirma después
        // con dm_op_de_concepto().
        $textos = ['OP ' . (int)$op['anio'] . '-' . (int)$op['id'], (int)$op['anio'] . ' - ' . (int)$op['id'], '-' . (int)$op['id'] . ';'];
        foreach ($tipos as $tipo) {
            foreach ($textos as $i => $texto) {
                $filtros = [
                    crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                    crear_filtro_api('moneda.id', '31', 4),
                    crear_filtro_api('concepto', $texto, 0, 1), // tipoFiltro 1 = LIKE
                ];
                if ($prefijos !== null && count($prefijos) === 1) $filtros[] = crear_filtro_api('prefijo.nombre', $prefijos[0], 0, 0);
                $peticiones["{$op['id']}|$tipo|$i"] = [
                    'endpoint' => '/inventarios/listarDocumentoSalidaAlmacen',
                    'metodo'   => 'POST',
                    'datos'    => cuerpo_listar_documentos_salida_almacen($filtros, 0, 50),
                ];
            }
        }
    }

    $respuestas = hacer_peticiones_api_paralelo($peticiones);
    $documentos = [];
    $hayRespuesta = false;
    foreach ($respuestas as $clave => $resp) {
        $tipo = explode('|', $clave)[1];
        $status = $resp['status'] ?? '';
        // Sin coincidencias WO responde BAD_REQUEST: no es un error de conexión.
        if ($status === 'OK' || $status === 'BAD_REQUEST') $hayRespuesta = true;
        if ($status !== 'OK') continue;
        foreach ($resp['data']['content'] ?? [] as $row) {
            if (!empty($row['senAnulado'])) continue;
            if ($prefijos !== null && !in_array(strtoupper(trim((string)($row['prefijo'] ?? ''))), $prefijos, true)) continue;
            $opConcepto = dm_op_de_concepto($row['concepto'] ?? '');
            if ($opConcepto === null || !isset($opsPorId[$opConcepto])) continue; // OP distinta (ej. 6146 vs 614)
            $documentos[(int)$row['id']] = [
                'id'        => (int)$row['id'],
                'tipo'      => $tipo,
                'documento' => trim(($row['prefijo'] ?? '') . ' ' . ($row['numero'] ?? '')),
                'fecha'     => $row['fecha'] ?? '',
                'concepto'  => $row['concepto'] ?? '',
                'op'        => $opConcepto,
                'op_label'  => $opsPorId[$opConcepto]['anio'] . '-' . $opConcepto,
                'ped'       => dm_ped_de_concepto($row['concepto'] ?? ''),
                'renglones' => [],
            ];
        }
    }
    if (!$hayRespuesta) {
        return ['ok' => false, 'error' => 'No se pudo consultar World Office. Intenta de nuevo en unos minutos.', 'documentos' => []];
    }

    // Renglones de cada documento, también en paralelo.
    $pet = [];
    foreach ($documentos as $id => $_) {
        $pet[$id] = ['endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $id, 'metodo' => 'POST',
                     'datos' => cuerpo_renglones_documento(0, 200)];
    }
    foreach (hacer_peticiones_api_paralelo($pet) as $id => $resp) {
        if (($resp['status'] ?? '') !== 'OK') {
            return ['ok' => false, 'error' => 'No se pudieron leer los títulos del documento ' . $documentos[$id]['documento'] . ' en World Office.', 'documentos' => []];
        }
        foreach ($resp['data']['content'] ?? [] as $r) {
            $documentos[$id]['renglones'][] = [
                'producto_wo' => (int)($r['inventario']['id'] ?? 0),
                'descripcion' => $r['inventario']['descripcion'] ?? '',
                'codigo'      => trim((string)($r['inventario']['codigo'] ?? '')),
                'cantidad'    => (float)($r['cantidad'] ?? 0),
                // Precio del renglón: valorUnitario bruto y porcentajeDescuento en fracción (WO no da el neto).
                'valor_unitario' => (float)($r['valorUnitario'] ?? 0),
                'descuento'   => (float)($r['porcentajeDescuento'] ?? 0),
            ];
        }
    }

    usort($documentos, fn($a, $b) => $a['id'] <=> $b['id']);
    return ['ok' => true, 'error' => null, 'documentos' => array_values($documentos)];
}

/**
 * "E" (estudiante) o "D" (docente) de un libro según libros.tipo — misma regla que la columna Tipo
 * de muestreo_colegio.php: tipo 1 o 3 = E, cualquier otro = D.
 */
function dm_tipo_libro_ed($tipoLibro) {
    return in_array((int)$tipoLibro, [1, 3], true) ? 'E' : 'D';
}

/**
 * devoluciones.tipo_muestras según los títulos devueltos (pedido por el usuario 2026-10-05): todos
 * "E" = 2 Estudiante, todos "D" = 1 Docente, mezcla = 3 Ambos.
 */
function dm_tipo_muestras_devolucion(array $tiposEd) {
    $tiposEd = array_unique($tiposEd);
    if (count($tiposEd) > 1) return 3;
    return reset($tiposEd) === 'E' ? 2 : 1;
}

/**
 * Libro del CRM para cada producto de WO: por libros.id_wo y, si no, por ISBN. El catálogo tiene
 * títulos repetidos (varios libros con el mismo id_wo/ISBN): se prefiere el que estaba en la
 * solicitud de muestras; si no, el de menor id. Devuelve [producto_wo => ['id','libro','isbn']].
 */
function dm_libros_por_producto(PDO $bdd, array $productos, $codMuestreo) {
    $idsWo = array_values(array_unique(array_filter(array_map(fn($p) => (int)$p['producto_wo'], $productos))));
    $isbns = array_values(array_unique(array_filter(array_map(fn($p) => (string)$p['codigo'], $productos))));
    if (!$idsWo && !$isbns) return [];

    $cond = [];
    $params = [(string)$codMuestreo];
    if ($idsWo) { $cond[] = 'l.id_wo IN (' . implode(',', array_fill(0, count($idsWo), '?')) . ')'; $params = array_merge($params, $idsWo); }
    if ($isbns) { $cond[] = 'TRIM(l.isbn) IN (' . implode(',', array_fill(0, count($isbns), '?')) . ')'; $params = array_merge($params, $isbns); }
    $stmt = $bdd->prepare("SELECT l.id, l.libro, TRIM(l.isbn) AS isbn, l.id_wo, l.tipo,
                                  EXISTS(SELECT 1 FROM libros_muestreos lm WHERE lm.cod_muestreo = ? AND lm.id_libro = l.id) AS en_muestreo
                           FROM libros l WHERE " . implode(' OR ', $cond) . "
                           ORDER BY en_muestreo DESC, l.id ASC");
    $stmt->execute($params);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $res = [];
    foreach ($productos as $p) {
        $pid = (int)$p['producto_wo'];
        foreach ([fn($l) => $pid && (int)$l['id_wo'] === $pid, fn($l) => $p['codigo'] !== '' && $l['isbn'] === $p['codigo']] as $coincide) {
            foreach ($libros as $l) {
                if ($coincide($l)) { $res[$pid] = ['id' => (int)$l['id'], 'libro' => $l['libro'], 'isbn' => $l['isbn'], 'tipo_ed' => dm_tipo_libro_ed($l['tipo'])]; break 2; }
            }
        }
    }
    return $res;
}

/** Unidades ya devueltas por producto WO en devoluciones activas del muestreo: [producto_wo => n]. */
function dm_devuelto_por_producto(PDO $bdd, $idMuestreo) {
    $res = [];
    foreach (dm_devuelto_repartido($bdd, $idMuestreo) as $x) {
        if ($x['producto_wo'] !== null) $res[$x['producto_wo']] = ($res[$x['producto_wo']] ?? 0) + $x['cantidad'];
    }
    return $res;
}

/** Unidades ya legalizadas por producto WO en legalizaciones activas del muestreo: [producto_wo => n]. */
function dm_legalizado_por_producto(PDO $bdd, $idMuestreo) {
    $res = [];
    foreach (dm_registrado_repartido($bdd, 'legalizacion', $idMuestreo) as $x) {
        if ($x['producto_wo'] !== null) $res[$x['producto_wo']] = ($res[$x['producto_wo']] ?? 0) + $x['cantidad'];
    }
    return $res;
}

/**
 * Devoluciones y legalizaciones son independientes (pedido por el usuario 2026-10-07): cada una
 * tiene su propio saldo sobre lo despachado. Modo 'devolucion' = despachado − devuelto; modo
 * 'legalizacion' = despachado − legalizado. Devuelve [producto_wo => unidades ya registradas].
 */
function dm_registrado_por_producto(PDO $bdd, $idMuestreo, $modo) {
    return $modo === 'legalizacion' ? dm_legalizado_por_producto($bdd, $idMuestreo) : dm_devuelto_por_producto($bdd, $idMuestreo);
}

/**
 * Las devoluciones buscan en todas las remisiones de muestras (REM), prefijos RE22 y CEUR, y nunca en
 * facturas (pedido por el usuario 2026-10-13).
 */
const DM_PREFIJOS_DEVOLUCION = ['RE22', 'CEUR'];

/**
 * Detalle según de dónde salen los títulos (pedido por el usuario 2026-10-13):
 *  - 'auto': lo despachado en World Office, solo en remisiones con los $prefijos dados.
 *  - 'crm': botón "Traer información del pedido", para cuando WO no trae nada: lo solicitado en el CRM
 *    (ver dm_detalle_crm()).
 * Cada título trae 'clave' (producto WO en 'auto', id del libro en 'crm'), la del campo cantidad[...],
 * y la respuesta trae 'fuente' para que el guardado recalcule con la misma.
 */
function dm_detalle_por_fuente(PDO $bdd, $idMuestreo, $modo, $fuente, array $prefijos) {
    if ($fuente === 'crm') return dm_detalle_crm($bdd, $idMuestreo, $modo);
    $detalle = dm_detalle_devolucion($bdd, $idMuestreo, $modo, fn($ops) => dm_documentos_wo_de_ops($ops, $prefijos));
    foreach ($detalle['titulos'] ?? [] as $i => $t) $detalle['titulos'][$i]['clave'] = $t['producto_wo'];
    $detalle['fuente'] = 'auto';
    return $detalle;
}

/**
 * Libros solicitados en el CRM (libros_muestreos.cantidad, lo que se montó desde el principio; no lo
 * despachado ni lo aprobado) con lo ya devuelto (o legalizado) de cada libro y lo disponible
 * (solicitado − registrado). Lo registrado se cuenta por libro en todas las devoluciones (o
 * legalizaciones) activas del pedido, vengan de WO o del CRM, para no registrar dos veces el mismo libro
 * por caminos distintos.
 */
function dm_detalle_crm(PDO $bdd, $idMuestreo, $modo) {
    $muestreo = dm_obtener_muestreo($bdd, $idMuestreo);
    if (!$muestreo) return ['ok' => false, 'error' => 'El pedido de muestras no existe, no está Entregado o no está en tu alcance.'];
    $ops = dm_ops_muestreo($bdd, $idMuestreo);

    $stmt = $bdd->prepare("SELECT lm.id_libro, SUM(lm.cantidad) AS solicitada, l.libro, TRIM(l.isbn) AS isbn, l.id_wo, l.tipo
                           FROM libros_muestreos lm
                           JOIN libros l ON l.id = lm.id_libro
                           WHERE lm.cod_muestreo = ?
                           GROUP BY lm.id_libro
                           HAVING solicitada > 0
                           ORDER BY l.libro");
    $stmt->execute([(string)$muestreo['codigo']]);
    $registrado = dm_registrado_por_libro($bdd, $idMuestreo, $modo);
    $campo = $modo === 'legalizacion' ? 'legalizada' : 'devuelta';

    $titulos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $idLibro = (int)$l['id_libro'];
        $ya = $registrado[$idLibro] ?? 0;
        $titulos[] = [
            'clave'       => $idLibro,
            'producto_wo' => (int)$l['id_wo'] ?: null,
            'descripcion' => $l['libro'],
            'codigo'      => (string)$l['isbn'],
            'id_libro'    => $idLibro,
            'libro'       => $l['libro'],
            'tipo_ed'     => dm_tipo_libro_ed($l['tipo']),
            'solicitada'  => (int)$l['solicitada'],
            $campo        => $ya,
            'disponible'  => max(0, (int)$l['solicitada'] - $ya),
            'documentos'  => 'Solicitud CRM',
            'ids_documentos' => null,
        ];
    }
    if (!$titulos) return ['ok' => false, 'error' => 'El pedido de muestras #' . (int)$idMuestreo . ' no tiene libros registrados en el CRM.', 'muestreo' => $muestreo, 'ops' => $ops];

    return ['ok' => true, 'error' => null, 'fuente' => 'crm', 'muestreo' => $muestreo, 'ops' => $ops,
            'documentos' => [], 'titulos' => $titulos, 'cierre' => dm_cierre_completo($bdd, $idMuestreo, $modo)];
}

/** Unidades ya devueltas (o legalizadas) por libro del CRM en registros activos del muestreo: [id_libro => n]. */
function dm_registrado_por_libro(PDO $bdd, $idMuestreo, $modo) {
    $res = [];
    foreach (dm_registrado_repartido($bdd, $modo, $idMuestreo) as $x) $res[$x['id_libro']] = ($res[$x['id_libro']] ?? 0) + $x['cantidad'];
    return $res;
}

/**
 * Todo lo que necesita la pantalla (y el guardado, que lo recalcula en el servidor): el muestreo,
 * sus OP, los documentos de WO encontrados y, por cada título despachado, cantidad despachada, ya
 * devuelta (o ya legalizada, en modo 'legalizacion') y disponible. Devuelve ['ok','error','muestreo',
 * 'ops','documentos','titulos' => [['producto_wo','descripcion','codigo','id_libro','libro',
 * 'despachada','devuelta' | 'legalizada','disponible','documentos' => 'REM RE22 8177, ...',
 * 'ids_documentos' => '5870,...'], ...]].
 * $buscarDocumentos(array $ops, array $muestreo) reemplaza la búsqueda de documentos en WO (las
 * legalizaciones solo usan remisiones RE22, ver lm_detalle_legalizacion()); sin él, dm_documentos_wo_de_ops().
 */
function dm_detalle_devolucion(PDO $bdd, $idMuestreo, $modo = 'devolucion', ?callable $buscarDocumentos = null) {
    $muestreo = dm_obtener_muestreo($bdd, $idMuestreo);
    if (!$muestreo) return ['ok' => false, 'error' => 'El pedido de muestras no existe, no está Entregado o no está en tu alcance.'];

    $ops = dm_ops_muestreo($bdd, $idMuestreo);
    if (!$ops) return ['ok' => false, 'error' => 'El pedido de muestras #' . (int)$idMuestreo . ' no tiene OP asociada, no se puede cruzar con World Office.', 'muestreo' => $muestreo, 'ops' => []];

    $wo = $buscarDocumentos ? $buscarDocumentos($ops, $muestreo) : dm_documentos_wo_de_ops($ops);
    if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error'], 'muestreo' => $muestreo, 'ops' => $ops];

    return ['ok' => true, 'error' => null, 'muestreo' => $muestreo, 'ops' => $ops,
            'documentos' => $wo['documentos'],
            'titulos' => dm_titulos_de_documentos($bdd, $idMuestreo, $muestreo['codigo'], $wo['documentos'], $modo),
            'cierre' => dm_cierre_completo($bdd, $idMuestreo, $modo)];
}

/**
 * Títulos despachados de un muestreo a partir de sus documentos de WO (con renglones): suma por producto
 * WO entre todos los documentos (ej. tres remisiones con el mismo libro = una sola fila con la suma), lo
 * ya devuelto (o legalizado) y lo disponible. Ver el formato de 'titulos' en dm_detalle_devolucion().
 */
function dm_titulos_de_documentos(PDO $bdd, $idMuestreo, $codMuestreo, array $documentos, $modo) {
    $porProducto = [];
    foreach ($documentos as $doc) {
        foreach ($doc['renglones'] as $r) {
            $pid = $r['producto_wo'];
            if (!$pid || $r['cantidad'] <= 0) continue;
            if (!isset($porProducto[$pid])) {
                $porProducto[$pid] = ['producto_wo' => $pid, 'descripcion' => $r['descripcion'], 'codigo' => $r['codigo'],
                                      'despachada' => 0, 'documentos' => [], 'ids_documentos' => []];
            }
            $porProducto[$pid]['despachada'] += (int)round($r['cantidad']);
            $porProducto[$pid]['documentos'][$doc['id']] = $doc['documento'];
            $porProducto[$pid]['ids_documentos'][$doc['id']] = $doc['id'];
        }
    }

    $libros = dm_libros_por_producto($bdd, array_values($porProducto), $codMuestreo);
    $registrado = dm_registrado_por_producto($bdd, $idMuestreo, $modo);
    $campo = $modo === 'legalizacion' ? 'legalizada' : 'devuelta';

    $titulos = [];
    foreach ($porProducto as $pid => $p) {
        $ya = $registrado[$pid] ?? 0;
        $titulos[] = $p + [
            'id_libro'   => $libros[$pid]['id'] ?? null,
            'libro'      => $libros[$pid]['libro'] ?? null,
            'tipo_ed'    => $libros[$pid]['tipo_ed'] ?? null,
            $campo       => $ya,
            'disponible' => max(0, $p['despachada'] - $ya),
        ];
    }
    foreach ($titulos as &$t) {
        $t['documentos'] = implode(', ', $t['documentos']);
        $t['ids_documentos'] = implode(',', $t['ids_documentos']);
    }
    unset($t);
    usort($titulos, fn($a, $b) => strcasecmp($a['descripcion'], $b['descripcion']));

    // Total despachado de los títulos que se pueden registrar (los que no están en el catálogo del
    // CRM no se pueden devolver ni legalizar): base del estado Completado de la lista.
    $totalDespachado = array_sum(array_map(fn($t) => $t['id_libro'] ? $t['despachada'] : 0, $titulos));
    if ($totalDespachado > 0) {
        $bdd->prepare("UPDATE muestreos SET unidades_despachadas_wo = ? WHERE id = ? AND NOT (unidades_despachadas_wo <=> ?)")
            ->execute([$totalDespachado, (int)$idMuestreo, $totalDespachado]);
    }
    return $titulos;
}


/** Máximo de pedidos en una misma devolución o legalización (cada uno suma consultas a World Office). */
const DM_MAX_PEDIDOS_DEVOLUCION = 30;

/**
 * Detalle de una devolución de VARIOS pedidos de muestras del mismo colegio (pedido por la usuaria
 * 2026-10-15): se busca en World Office una sola vez con las OP de todos los pedidos y los títulos se
 * suman por libro del CRM entre todas las remisiones (tres remisiones con el mismo libro = una fila con
 * la suma, que es lo que se puede devolver).
 * $fuentes = [id_muestreo => 'crm'] para los pedidos que se cargan con "Traer información del pedido"
 * (lo solicitado en el CRM, dm_detalle_crm()); los demás salen de WO.
 * $modo = 'legalizacion' (pedido por la usuaria 2026-10-27): lo mismo para legalizar, con su propio saldo
 * (despachado − legalizado) y los $prefijos de las legalizaciones (ver lm_detalle_varios()).
 * Cada título trae 'partes' (de qué pedido y producto WO sale cada saldo, en orden de pedido), que usa
 * dm_repartir_varios() para repartir lo registrado. Los pedidos ya cerrados con una devolución (o
 * legalización) completa se muestran pero no suman disponible.
 * Devuelve ['ok','error','colegio','id_colegio','cliente','id_cliente','pedidos' => [resumen por
 * pedido], 'titulos' => [...], 'incluidos' => [ids de pedidos con algo por registrar]].
 */
function dm_detalle_varios(PDO $bdd, array $ids, array $fuentes = [], $modo = 'devolucion', ?array $prefijos = null) {
    $leg = $modo === 'legalizacion';
    $campo = $leg ? 'legalizada' : 'devuelta';
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    sort($ids);
    if (!$ids) return ['ok' => false, 'error' => 'Elige al menos un pedido de muestras.'];
    if (count($ids) > DM_MAX_PEDIDOS_DEVOLUCION) return ['ok' => false, 'error' => 'Puedes ' . ($leg ? 'legalizar' : 'devolver') . ' hasta ' . DM_MAX_PEDIDOS_DEVOLUCION . ' pedidos a la vez.'];

    $pedidos = [];
    $opsAuto = [];
    $idColegio = null;
    foreach ($ids as $id) {
        $m = dm_obtener_muestreo($bdd, $id);
        if (!$m) return ['ok' => false, 'error' => 'El pedido de muestras #' . $id . ' no existe, no está Entregado o no está en tu alcance.'];
        if ($idColegio !== null && (int)$m['id_colegio'] !== $idColegio) return ['ok' => false, 'error' => 'Todos los pedidos de una ' . ($leg ? 'legalización' : 'devolución') . ' deben ser del mismo colegio.'];
        $idColegio = (int)$m['id_colegio'];
        $fuente = ($fuentes[$id] ?? '') === 'crm' ? 'crm' : 'auto';
        $ops = dm_ops_muestreo($bdd, $id);
        $pedidos[$id] = ['muestreo' => $m, 'ops' => $ops, 'fuente' => $fuente, 'cierre' => dm_cierre_completo($bdd, $id, $modo)];
        if ($fuente === 'auto') foreach ($ops as $op) $opsAuto[] = $op;
    }

    // Una sola búsqueda en WO con las OP de todos los pedidos; cada documento vuelve a su pedido por la OP.
    $docsPorOp = [];
    if ($opsAuto) {
        $wo = dm_documentos_wo_de_ops($opsAuto, $prefijos ?? DM_PREFIJOS_DEVOLUCION);
        if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error']];
        foreach ($wo['documentos'] as $doc) $docsPorOp[(int)$doc['op']][] = $doc;
    }

    $agrupados = [];
    $resumen = [];
    $incluidos = [];
    $cliente = null;
    foreach ($pedidos as $id => $p) {
        $m = $p['muestreo'];
        if (!$cliente && !empty($m['id_cliente'])) $cliente = $m;
        $info = ['id' => $id, 'fecha' => $m['fecha'] ? date('d/m/Y', strtotime($m['fecha'])) : '', 'solicitante' => $m['solicitante'],
                 'ops' => implode(', ', array_map(fn($o) => $o['anio'] . '-' . $o['id'], $p['ops'])), 'fuente' => $p['fuente'],
                 'cierre' => $p['cierre'], 'documentos' => [], 'error' => null, 'sin_documentos' => false, 'unidades' => 0, 'disponible' => 0];
        $titulos = [];
        if ($p['fuente'] !== 'crm') {
            $docs = [];
            foreach ($p['ops'] as $op) foreach ($docsPorOp[(int)$op['id']] ?? [] as $doc) $docs[] = $doc;
            if ($docs) {
                $titulos = dm_titulos_de_documentos($bdd, $id, $m['codigo'], $docs, $modo);
                foreach ($titulos as $i => $t) $titulos[$i]['clave'] = $t['producto_wo'];
            } else {
                // Sin OP o sin remisión en WO: se toma de una vez lo solicitado en el CRM, como si hubieran
                // dado "Traer información del pedido" (pedido por la usuaria 2026-10-16). El guardado vuelve
                // a pasar por aquí, así que usa la misma fuente.
                $info['sin_documentos'] = true;
                $info['sin_op'] = !$p['ops'];
                $info['crm_automatico'] = true;
                $p['fuente'] = $info['fuente'] = 'crm';
            }
            foreach ($docs as $doc) {
                unset($doc['renglones']);
                $doc['ped_distinto'] = $doc['ped'] !== null && (int)$doc['ped'] !== $id;
                $info['documentos'][] = $doc;
            }
        }
        if ($p['fuente'] === 'crm') {
            $d = dm_detalle_crm($bdd, $id, $modo);
            if ($d['ok']) $titulos = $d['titulos']; else $info['error'] = $d['error'];
        }

        $crm = $p['fuente'] === 'crm';
        foreach ($titulos as $t) {
            $base = $crm ? (int)$t['solicitada'] : (int)$t['despachada'];
            $disponible = $p['cierre'] ? 0 : (int)$t['disponible'];
            $info['unidades'] += $base;
            if ($t['id_libro']) $info['disponible'] += $disponible;
            $k = $t['id_libro'] ? 'L' . $t['id_libro'] : 'W' . $t['producto_wo'];
            if (!isset($agrupados[$k])) {
                $agrupados[$k] = ['clave' => $t['id_libro'] ?: null, 'id_libro' => $t['id_libro'] ?: null, 'descripcion' => $t['descripcion'],
                                  'codigo' => $t['codigo'], 'tipo_ed' => $t['tipo_ed'], 'base' => 0, $campo => 0, 'disponible' => 0,
                                  'documentos' => [], 'pedidos' => [], 'partes' => []];
            }
            $a = &$agrupados[$k];
            $a['base'] += $base;
            $a[$campo] += (int)$t[$campo];
            $a['disponible'] += $disponible;
            foreach ($crm ? ['Solicitud CRM #' . $id] : explode(', ', $t['documentos']) as $etq) $a['documentos'][$etq] = $etq;
            $a['pedidos'][$id] = ['id' => $id, 'base' => ($a['pedidos'][$id]['base'] ?? 0) + $base,
                                  $campo => ($a['pedidos'][$id][$campo] ?? 0) + (int)$t[$campo],
                                  'disponible' => ($a['pedidos'][$id]['disponible'] ?? 0) + $disponible];
            $a['partes'][] = ['id_muestreo' => $id, 'fuente' => $p['fuente'], 'clave' => (int)$t['clave'], 'producto_wo' => $t['producto_wo'] ?: null,
                              'base' => $base, 'cerrado' => (bool)$p['cierre'], 'ids_documentos' => (string)$t['ids_documentos']];
            unset($a);
            if ($t['id_libro'] && !$p['cierre']) $incluidos[$id] = $id;
        }
        $resumen[] = $info;
    }

    $titulos = array_values($agrupados);
    foreach ($titulos as &$t) {
        $t['documentos'] = implode(', ', $t['documentos']);
        $t['pedidos'] = array_values($t['pedidos']);
    }
    unset($t);
    usort($titulos, fn($a, $b) => strcasecmp($a['descripcion'], $b['descripcion']));
    $primero = reset($pedidos)['muestreo'];

    return ['ok' => true, 'error' => null, 'colegio' => $primero['colegio'], 'id_colegio' => $idColegio,
            'cliente' => $cliente['cliente'] ?? null, 'id_cliente' => (int)($cliente['id_cliente'] ?? 0),
            'pedidos' => $resumen, 'titulos' => $titulos, 'incluidos' => array_values($incluidos)];
}

/**
 * Primera parte del guardado de varios pedidos, común a devoluciones y legalizaciones (antes de abrir la
 * transacción): valida $cantidades = [id_libro => cantidad] contra los títulos de $detalle
 * (dm_detalle_varios()). Devuelve ['ok','error','incluidos','a_registrar' => [id_libro => n],
 * 'por_libro' => [id_libro => título], 'fuentes' => [id pedido => fuente], 'ops' => [...], 'docs' => [...]].
 */
function dm_preparar_varios(array $detalle, array $cantidades, $modo) {
    $verbo = $modo === 'legalizacion' ? 'legalizar' : 'devolver';
    $incluidos = $detalle['incluidos'];
    if (!$incluidos) return ['ok' => false, 'error' => 'Ninguno de los pedidos elegidos tiene títulos por ' . $verbo . '.'];

    $porLibro = [];
    foreach ($detalle['titulos'] as $t) if ($t['id_libro']) $porLibro[(int)$t['id_libro']] = $t;

    $aRegistrar = [];
    foreach ($cantidades as $idLibro => $cant) {
        if (!preg_match('/^\d*$/', trim((string)$cant))) return ['ok' => false, 'error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        $cant = (int)$cant;
        if ($cant === 0) continue;
        if (!isset($porLibro[(int)$idLibro])) return ['ok' => false, 'error' => 'Uno de los títulos no está en los pedidos elegidos (ni despachado en World Office ni en la solicitud del CRM).'];
        $aRegistrar[(int)$idLibro] = $cant;
    }
    if (!$aRegistrar) return ['ok' => false, 'error' => 'Ingresa al menos una cantidad a ' . $verbo . '.'];

    $fuentes = [];
    $docs = [];
    $ops = [];
    foreach ($detalle['pedidos'] as $p) {
        if (!in_array($p['id'], $incluidos, true)) continue;
        $fuentes[$p['id']] = $p['fuente'];
        if ($p['ops'] !== '') $ops[] = $p['ops'];
        // Solo el número que se ve en World Office (ej. "RE22 8165"), sin el id interno.
        foreach ($p['documentos'] as $d) $docs[$d['documento']] = $d['documento'];
    }
    return ['ok' => true, 'error' => null, 'incluidos' => $incluidos, 'a_registrar' => $aRegistrar, 'por_libro' => $porLibro,
            'fuentes' => $fuentes, 'ops' => $ops, 'docs' => array_values($docs)];
}

/**
 * Segunda parte, DENTRO de la transacción: bloquea los pedidos (FOR UPDATE, en orden de id, serializa
 * registros simultáneos de los mismos pedidos), revisa que ninguno se haya cerrado y reparte cada cantidad
 * entre los pedidos en orden (el más antiguo primero) hasta agotar el saldo actual de cada uno.
 * Devuelve ['ok','error','reparto' => [id_libro => [partes con 'cantidad']]]; si falla ya hizo rollBack.
 */
function dm_repartir_varios(PDO $bdd, array $prep, $modo) {
    $incluidos = $prep['incluidos'];
    $bdd->prepare("SELECT id FROM muestreos WHERE id IN (" . implode(',', array_fill(0, count($incluidos), '?')) . ") ORDER BY id FOR UPDATE")
        ->execute($incluidos);
    $registrado = [];
    foreach ($incluidos as $id) {
        if ($cierreActual = dm_cierre_completo($bdd, $id, $modo)) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'Pedido #' . $id . ': ' . dm_mensaje_cerrado($modo, $cierreActual)];
        }
        $registrado[$id] = $prep['fuentes'][$id] === 'crm' ? dm_registrado_por_libro($bdd, $id, $modo) : dm_registrado_por_producto($bdd, $id, $modo);
    }

    // Las partes ya vienen en orden de pedido.
    $reparto = [];
    foreach ($prep['a_registrar'] as $idLibro => $cant) {
        $t = $prep['por_libro'][$idLibro];
        $resta = $cant;
        $disponibleTotal = 0;
        $reparto[$idLibro] = [];
        foreach ($t['partes'] as $pa) {
            if ($pa['cerrado'] || !isset($registrado[$pa['id_muestreo']])) continue;
            $ya = $registrado[$pa['id_muestreo']][$pa['clave']] ?? 0;
            $disp = max(0, $pa['base'] - $ya);
            $disponibleTotal += $disp;
            $toma = min($resta, $disp);
            if ($toma <= 0) continue;
            $reparto[$idLibro][] = $pa + ['cantidad' => $toma];
            $registrado[$pa['id_muestreo']][$pa['clave']] = $ya + $toma;
            $resta -= $toma;
        }
        if ($resta > 0) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => 'No puedes ' . ($modo === 'legalizacion' ? 'legalizar ' : 'devolver ') . $cant . ' de "' . $t['descripcion']
                . '": en los pedidos elegidos quedan ' . $disponibleTotal . ' disponibles.'];
        }
    }
    return ['ok' => true, 'error' => null, 'reparto' => $reparto];
}

/**
 * Columnas de la fila de un libro (una por libro; el reparto por pedido va en la columna reparto, ver
 * dm_asegurar_columnas()): [producto_wo, cant_despachada, documentos_wo, reparto]. Desde el CRM:
 * producto_wo = libros.id_wo y sin cantidad despachada.
 */
function dm_fila_libro_varios(array $prep, $idLibro, array $repartoLibro) {
    $productoWo = null;
    $despachada = null;
    $idsDocs = [];
    foreach ($prep['por_libro'][$idLibro]['partes'] as $pa) {
        if ($pa['cerrado'] || !in_array($pa['id_muestreo'], $prep['incluidos'], true)) continue;
        if ($productoWo === null && $pa['producto_wo']) $productoWo = (int)$pa['producto_wo'];
        if ($pa['fuente'] === 'auto') $despachada = (int)$despachada + $pa['base'];
        foreach (array_filter(explode(',', $pa['ids_documentos'])) as $idDoc) $idsDocs[$idDoc] = $idDoc;
    }
    $partes = array_map(fn($pa) => $pa['id_muestreo'] . ':' . $pa['cantidad'] . ':' . (int)$pa['producto_wo'], $repartoLibro);
    return [$productoWo, $despachada, mb_substr(implode(',', $idsDocs), 0, 255) ?: null, implode(',', $partes)];
}

/**
 * Guarda UNA devolución de varios pedidos de muestras del mismo colegio. $cantidades = [id_libro =>
 * cantidad a devolver]: el tope es la suma de lo disponible del libro en todos los pedidos elegidos.
 * Lo devuelto se reparte entre los pedidos (dm_repartir_varios()) y queda en libros_devol.reparto para que
 * el saldo y el estado Completado de cada pedido sigan cuadrando. Se recalcula todo en el servidor.
 * $cierre = 'parcial' o 'completa' (cierra todos los pedidos incluidos aunque queden unidades).
 * Devuelve ['ok','error','id_devolucion'].
 */
function dm_guardar_devolucion_varios(PDO $bdd, array $ids, array $fuentes, array $cantidades, $observaciones, $archivo, $cierre) {
    if (!in_array($cierre, ['parcial', 'completa'], true)) return ['ok' => false, 'error' => 'Elige si la devolución es parcial o completa.'];
    $detalle = dm_detalle_varios($bdd, $ids, $fuentes);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    $prep = dm_preparar_varios($detalle, $cantidades, 'devolucion');
    if (!$prep['ok']) return ['ok' => false, 'error' => $prep['error']];
    $incluidos = $prep['incluidos'];

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $rep = dm_repartir_varios($bdd, $prep, 'devolucion');
        if (!$rep['ok']) return ['ok' => false, 'error' => $rep['error']];

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM devoluciones WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        // Mismo estado inicial que el formulario anterior (php/reg_devol.php): administración la deja
        // Recibida, el resto Pendiente.
        $tipoMuestras = dm_tipo_muestras_devolucion(array_map(fn($idLibro) => $prep['por_libro'][$idLibro]['tipo_ed'], array_keys($prep['a_registrar'])));
        $estado = ((int)$_SESSION['tipo'] === 1 || (int)$_SESSION['tipo'] === 2 || (int)$_SESSION['id'] === 19) ? 2 : 1;

        // Una fila por libro (vista_devol y la OP de la devolución la leen así).
        $stmtLibro = $bdd->prepare("INSERT INTO libros_devol (cod_pedido, id_libro, cantidad, producto_wo, cant_despachada, documentos_wo, reparto, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($prep['a_registrar'] as $idLibro => $cant) {
            $stmtLibro->execute(array_merge([$codigo, $idLibro, $cant], dm_fila_libro_varios($prep, $idLibro, $rep['reparto'][$idLibro])));
        }

        $bdd->prepare("INSERT INTO devoluciones (codigo, tipo, id_periodo, persona, tipo_muestras, id_usuario, observaciones, archivo, estado,
                                                 id_colegio, id_muestreo, ids_muestreos, ops_muestreo, documentos_wo, cierre, created_at)
                       VALUES (?, '1', '1', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$codigo, $detalle['id_cliente'], $tipoMuestras, $_SESSION['id'], $observaciones, $archivo, $estado, $detalle['id_colegio'],
                       $incluidos[0], implode(',', $incluidos), mb_substr(implode(', ', $prep['ops']), 0, 100), mb_substr(implode(', ', $prep['docs']), 0, 500), $cierre]);
        $idDevolucion = (int)$bdd->lastInsertId();

        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_devolucion' => $idDevolucion];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Devolución de muestras #' . implode(',', $incluidos) . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la devolución: ' . $e->getMessage()];
    }
}
