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
 * de documento que tenga la OP en el CRM y cuántas devoluciones activas ya tienen.
 */
function dm_listar_muestreos_entregados(PDO $bdd) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $sql = "SELECT m.id, m.fecha, m.unidades_despachadas_wo, c.id AS id_colegio, c.colegio,
                   CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS solicitante,
                   GROUP_CONCAT(DISTINCT CONCAT(op.`año`, '-', op.id) ORDER BY op.id SEPARATOR ', ') AS ops,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(op.n_doc), '') ORDER BY op.id SEPARATOR ', ') AS docs_op,
                   (SELECT COUNT(*) FROM devoluciones d WHERE d.id_muestreo = m.id AND d.estado <> " . DM_ESTADO_DEVOL_ANULADA . ") AS n_devoluciones,
                   (SELECT COUNT(*) FROM muestreos_e me WHERE me.id_muestreo = m.id AND me.estado <> " . DM_ESTADO_LEGALIZ_ANULADA . ") AS n_legalizaciones
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
    // Pedidos cerrados a mano con una devolución/legalización "completa" activa.
    $cerrados = array_flip($bdd->query($modo === 'legalizacion'
        ? "SELECT DISTINCT id_muestreo FROM muestreos_e WHERE id_muestreo IS NOT NULL AND cierre = 'completa' AND estado <> " . DM_ESTADO_LEGALIZ_ANULADA
        : "SELECT DISTINCT id_muestreo FROM devoluciones WHERE id_muestreo IS NOT NULL AND cierre = 'completa' AND estado <> " . DM_ESTADO_DEVOL_ANULADA
    )->fetchAll(PDO::FETCH_COLUMN));

    $sql = $modo === 'legalizacion'
        ? "SELECT me.id_muestreo, SUM(lme.cantidad) FROM muestreos_e me JOIN libros_muestreos_e lme ON lme.cod_muestreo = me.codigo
           WHERE me.id_muestreo IS NOT NULL AND me.estado <> " . DM_ESTADO_LEGALIZ_ANULADA . " AND lme.producto_wo IS NOT NULL GROUP BY me.id_muestreo"
        : "SELECT d.id_muestreo, SUM(ld.cantidad) FROM devoluciones d JOIN libros_devol ld ON ld.cod_pedido = d.codigo
           WHERE d.id_muestreo IS NOT NULL AND d.estado <> " . DM_ESTADO_DEVOL_ANULADA . " AND ld.producto_wo IS NOT NULL GROUP BY d.id_muestreo";
    $registrado = $bdd->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
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
           LEFT JOIN usuarios u ON u.id = x.id_usuario WHERE x.id_muestreo = ? AND x.cierre = 'completa' AND x.estado <> " . DM_ESTADO_LEGALIZ_ANULADA
        : "SELECT x.id, x.created_at AS fecha, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario FROM devoluciones x
           LEFT JOIN usuarios u ON u.id = x.id_usuario WHERE x.id_muestreo = ? AND x.cierre = 'completa' AND x.estado <> " . DM_ESTADO_DEVOL_ANULADA;
    $stmt = $bdd->prepare($sql . " ORDER BY x.id LIMIT 1");
    $stmt->execute([(int)$idMuestreo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
 */
function dm_documentos_wo_de_ops(array $ops) {
    if (!$ops) return ['ok' => true, 'error' => null, 'documentos' => []];

    $opsPorId = [];
    $peticiones = [];
    foreach ($ops as $op) {
        $opsPorId[(int)$op['id']] = $op;
        // Formas en que aparece la OP en el concepto: "OP 2026-6146", "OP # 2026 - 6146" y, cuando el
        // año quedó mal escrito ("OP 2027*-6079"), "-6079;". Cada coincidencia se confirma después
        // con dm_op_de_concepto().
        $textos = ['OP ' . (int)$op['anio'] . '-' . (int)$op['id'], (int)$op['anio'] . ' - ' . (int)$op['id'], '-' . (int)$op['id'] . ';'];
        foreach (array_keys(WO_TIPOS_DESPACHO) as $tipo) {
            foreach ($textos as $i => $texto) {
                $peticiones["{$op['id']}|$tipo|$i"] = [
                    'endpoint' => '/inventarios/listarDocumentoSalidaAlmacen',
                    'metodo'   => 'POST',
                    'datos'    => cuerpo_listar_documentos_salida_almacen([
                        crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                        crear_filtro_api('moneda.id', '31', 4),
                        crear_filtro_api('concepto', $texto, 0, 1), // tipoFiltro 1 = LIKE
                    ], 0, 50),
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
    $stmt = $bdd->prepare("SELECT ld.producto_wo, SUM(ld.cantidad) AS devuelto
                           FROM libros_devol ld
                           JOIN devoluciones d ON d.codigo = ld.cod_pedido
                           WHERE d.id_muestreo = ? AND d.estado <> " . DM_ESTADO_DEVOL_ANULADA . " AND ld.producto_wo IS NOT NULL
                           GROUP BY ld.producto_wo");
    $stmt->execute([(int)$idMuestreo]);
    $res = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $res[(int)$r['producto_wo']] = (int)$r['devuelto'];
    return $res;
}

/** Unidades ya legalizadas por producto WO en legalizaciones activas del muestreo: [producto_wo => n]. */
function dm_legalizado_por_producto(PDO $bdd, $idMuestreo) {
    $stmt = $bdd->prepare("SELECT lme.producto_wo, SUM(lme.cantidad) AS legalizado
                           FROM libros_muestreos_e lme
                           JOIN muestreos_e me ON me.codigo = lme.cod_muestreo
                           WHERE me.id_muestreo = ? AND me.estado <> " . DM_ESTADO_LEGALIZ_ANULADA . " AND lme.producto_wo IS NOT NULL
                           GROUP BY lme.producto_wo");
    $stmt->execute([(int)$idMuestreo]);
    $res = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $res[(int)$r['producto_wo']] = (int)$r['legalizado'];
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
 * Todo lo que necesita la pantalla (y el guardado, que lo recalcula en el servidor): el muestreo,
 * sus OP, los documentos de WO encontrados y, por cada título despachado, cantidad despachada, ya
 * devuelta (o ya legalizada, en modo 'legalizacion') y disponible. Devuelve ['ok','error','muestreo',
 * 'ops','documentos','titulos' => [['producto_wo','descripcion','codigo','id_libro','libro',
 * 'despachada','devuelta' | 'legalizada','disponible','documentos' => 'REM RE22 8177, ...',
 * 'ids_documentos' => '5870,...'], ...]].
 */
function dm_detalle_devolucion(PDO $bdd, $idMuestreo, $modo = 'devolucion') {
    $muestreo = dm_obtener_muestreo($bdd, $idMuestreo);
    if (!$muestreo) return ['ok' => false, 'error' => 'El pedido de muestras no existe, no está Entregado o no está en tu alcance.'];

    $ops = dm_ops_muestreo($bdd, $idMuestreo);
    if (!$ops) return ['ok' => false, 'error' => 'El pedido de muestras #' . (int)$idMuestreo . ' no tiene OP asociada, no se puede cruzar con World Office.', 'muestreo' => $muestreo, 'ops' => []];

    $wo = dm_documentos_wo_de_ops($ops);
    if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error'], 'muestreo' => $muestreo, 'ops' => $ops];

    // Suma por producto WO a través de todos los documentos (ej. remisión + complemento).
    $porProducto = [];
    foreach ($wo['documentos'] as $doc) {
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

    $libros = dm_libros_por_producto($bdd, array_values($porProducto), $muestreo['codigo']);
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

    return ['ok' => true, 'error' => null, 'muestreo' => $muestreo, 'ops' => $ops,
            'documentos' => $wo['documentos'], 'titulos' => $titulos,
            'cierre' => dm_cierre_completo($bdd, $idMuestreo, $modo)];
}

/**
 * Guarda una devolución. $cantidades = [producto_wo => cantidad a devolver]. Recalcula el despacho
 * en World Office y lo ya devuelto (bloqueando el muestreo para que dos devoluciones simultáneas no
 * se pasen del máximo) y rechaza cualquier cantidad fuera de 0..disponible. $cierre = 'parcial' (el
 * pedido sigue abierto hasta agotar unidades) o 'completa' (lo cierra aunque queden unidades).
 * Devuelve ['ok','error','id_devolucion'].
 */
function dm_guardar_devolucion(PDO $bdd, $idMuestreo, array $cantidades, $observaciones, $archivo, $cierre) {
    if (!in_array($cierre, ['parcial', 'completa'], true)) return ['ok' => false, 'error' => 'Elige si la devolución es parcial o completa.'];
    $detalle = dm_detalle_devolucion($bdd, $idMuestreo);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    if ($detalle['cierre']) return ['ok' => false, 'error' => dm_mensaje_cerrado('devolucion', $detalle['cierre'])];

    $titulosPorProducto = [];
    foreach ($detalle['titulos'] as $t) $titulosPorProducto[$t['producto_wo']] = $t;

    $aDevolver = [];
    foreach ($cantidades as $pid => $cant) {
        $pid = (int)$pid;
        if (!preg_match('/^\d*$/', trim((string)$cant))) return ['ok' => false, 'error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        $cant = (int)$cant;
        if ($cant === 0) continue;
        if (!isset($titulosPorProducto[$pid])) return ['ok' => false, 'error' => 'Uno de los títulos no aparece despachado en World Office para este pedido.'];
        if (!$titulosPorProducto[$pid]['id_libro']) return ['ok' => false, 'error' => 'El título "' . $titulosPorProducto[$pid]['descripcion'] . '" no está en el catálogo de libros del CRM.'];
        $aDevolver[$pid] = $cant;
    }
    if (!$aDevolver) return ['ok' => false, 'error' => 'Ingresa al menos una cantidad a devolver.'];

    $muestreo = $detalle['muestreo'];
    $opsLabel = implode(', ', array_map(fn($o) => $o['anio'] . '-' . $o['id'], $detalle['ops']));
    // Solo el número que se ve en World Office (ej. "RE22 8165"); el id interno de WO queda en
    // libros_devol.documentos_wo (pedido por el usuario 2026-10-05: el "(WO 5829)" confundía).
    $docsLabel = implode(', ', array_map(fn($d) => $d['documento'], $detalle['documentos']));

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        // Bloqueo del muestreo: serializa devoluciones simultáneas del mismo pedido.
        $bdd->prepare("SELECT id FROM muestreos WHERE id = ? FOR UPDATE")->execute([(int)$idMuestreo]);
        if ($cierreActual = dm_cierre_completo($bdd, $idMuestreo, 'devolucion')) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => dm_mensaje_cerrado('devolucion', $cierreActual)];
        }
        $devuelto = dm_devuelto_por_producto($bdd, $idMuestreo);
        foreach ($aDevolver as $pid => $cant) {
            $t = $titulosPorProducto[$pid];
            $disponible = max(0, $t['despachada'] - ($devuelto[$pid] ?? 0));
            if ($cant > $disponible) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'No puedes devolver ' . $cant . ' de "' . $t['descripcion'] . '": se despacharon '
                    . $t['despachada'] . ', ya se devolvieron ' . ($devuelto[$pid] ?? 0) . ' y quedan ' . $disponible . ' disponibles.'];
            }
        }

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM devoluciones WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        // Mismo estado inicial que el formulario anterior (php/reg_devol.php): administración la deja
        // Recibida, el resto Pendiente.
        $tipoMuestras = dm_tipo_muestras_devolucion(array_map(fn($pid) => $titulosPorProducto[$pid]['tipo_ed'], array_keys($aDevolver)));
        $estado = ((int)$_SESSION['tipo'] === 1 || (int)$_SESSION['tipo'] === 2 || (int)$_SESSION['id'] === 19) ? 2 : 1;

        $stmtLibro = $bdd->prepare("INSERT INTO libros_devol (cod_pedido, id_libro, cantidad, producto_wo, cant_despachada, documentos_wo, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, NOW())");
        foreach ($aDevolver as $pid => $cant) {
            $t = $titulosPorProducto[$pid];
            $stmtLibro->execute([$codigo, $t['id_libro'], $cant, $pid, $t['despachada'], $t['ids_documentos']]);
        }

        $bdd->prepare("INSERT INTO devoluciones (codigo, tipo, id_periodo, persona, tipo_muestras, id_usuario, observaciones, archivo, estado,
                                                 id_colegio, id_muestreo, ops_muestreo, documentos_wo, cierre, created_at)
                       VALUES (?, '1', '1', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$codigo, (int)($muestreo['id_cliente'] ?? 0), $tipoMuestras, $_SESSION['id'], $observaciones, $archivo, $estado, $muestreo['id_colegio'],
                       (int)$idMuestreo, mb_substr($opsLabel, 0, 100), mb_substr($docsLabel, 0, 500), $cierre]);
        $idDevolucion = (int)$bdd->lastInsertId();

        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_devolucion' => $idDevolucion];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Devolución de muestras #' . (int)$idMuestreo . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la devolución: ' . $e->getMessage()];
    }
}
