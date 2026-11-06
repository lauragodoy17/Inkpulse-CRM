<?php
/**
 * Devoluciones de remisión (DREM) y notas crédito de venta (NCV) de World Office en backorders —
 * pedido por la usuaria 2026-10-20: lo devuelto deja de contar como despachado (vuelve a quedar
 * pendiente).
 *
 * Lo devuelto afecta al COLEGIO en general (colegio + período + libro), no a un pedido — regla de
 * la usuaria 2026-10-20: los pedidos se agrupan por colegio con sus cantidades y cada devolución
 * se resta de ese total (backorders_grupos_colegio()). Cómo se liga cada documento a su colegio:
 *  - Nota crédito: World Office trae la factura a la que se le hizo (getDocumentoId → docAsociado,
 *    id de la FV) y si afecta inventario (documentoTipoNotaCredito.efectoEnInventario). Solo cuentan
 *    las de devolución (efecto = 1); las de descuento no mueven cantidades. El colegio es el del
 *    pedido que tiene esa FV (la FV trae OP/PED en su concepto).
 *  - Devolución de remisión (y nota crédito sin factura asociada): no trae a qué remisión
 *    corresponde. El concepto trae la OP de la DEVOLUCIÓN del CRM ("OP 2026-6196; RB 967; ..."); esa
 *    OP (ordenes_pedidos.id_devol_v) lleva a la devolución (devoluciones_v, estados 2 Recibido y 4
 *    En proceso), que tiene el colegio + período.
 *  Las DREM de prefijo RE22 son devoluciones de MUESTRAS (sus OP son de devoluciones de muestras):
 *  no se les traen renglones. De las notas crédito solo cuentan las de prefijo DMC (dado por la
 *  usuaria 2026-10-20; las PNC no).
 *
 * Caché: backorder_wo_devoluciones (encabezado) + backorder_wo_devol_renglones (libro + cantidad;
 * id_libro = id de INVENTARIO de World Office, igual que backorder_wo_renglones). Las anuladas se
 * conservan con anulado=1 y no cuentan; las que ya no salen en el listado completo (eliminadas) se
 * borran de la caché.
 */
require_once __DIR__ . '/api_wo_ventas.php';
require_once __DIR__ . '/api_wo_cliente.php';
require_once __DIR__ . '/backorders_paquetes.php';

/** Prefijo de las notas crédito de venta que cuentan. */
const BACKORDERS_NC_PREFIJO = 'DMC';

/** Estados de devoluciones_v que cuentan: 2 Recibido, 4 En proceso. */
const BACKORDERS_DEVOL_ESTADOS = [2, 4];

function backorders_devol_crear_tablas($bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_devoluciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        prefijo VARCHAR(20) NOT NULL DEFAULT '',
        numero VARCHAR(30) NOT NULL,
        fecha DATE NULL,
        concepto TEXT NULL,
        cliente VARCHAR(255) NULL,
        anulado TINYINT(1) NOT NULL DEFAULT 0,
        op_ref INT NULL,
        id_fv_asociada INT NULL,
        efecto_inventario TINYINT(1) NOT NULL DEFAULT 1,
        detalle_ok TINYINT(1) NOT NULL DEFAULT 0,
        fecha_modificacion VARCHAR(25) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento),
        KEY idx_op_ref (op_ref),
        KEY idx_fv (id_fv_asociada)
    )");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_devol_renglones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        id_libro INT NULL,
        isbn VARCHAR(100) NULL,
        descripcion VARCHAR(255) NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
        KEY idx_doc (id_wo, tipo_documento)
    )");
}

/** Id de la OP en el concepto: "OP 2026-6196", "OP:2026-5698", "OP:OP:2026-5526". */
function backorders_devol_extraer_op($concepto) {
    if (!preg_match('/\bOP\s*[:.]?\s*(?:OP\s*[:.]?\s*)?\d{4}\s*-\s*(\d+)/i', (string)$concepto, $m)) return null;
    return (int)$m[1];
}

/** "DEV CEUR 8" / "NC DMC 664". */
function backorders_devol_etiqueta($tipo, $prefijo, $numero) {
    return ($tipo === 'NCV' ? 'NC' : 'DEV') . ' ' . trim($prefijo . ' ' . $numero);
}

/** Listado completo de un tipo (DREM/NCV). null si alguna página falla. */
function backorders_devol_listar($tipo) {
    $filas = [];
    $pagina = 0;
    do {
        $resp = $tipo === 'NCV' ? listar_notas_credito_venta_wo($pagina, 500) : listar_devoluciones_venta_wo($pagina, 500);
        if (($resp['status'] ?? '') !== 'OK' || !isset($resp['data']['content'])) return null;
        foreach ($resp['data']['content'] as $row) $filas[] = $row;
        $total = (int)($resp['data']['totalElements'] ?? 0);
        $pagina++;
    } while (count($filas) < $total && $pagina < 50);
    return count($filas) >= $total ? $filas : null;
}

/**
 * Código de producto de World Office (ISBN, a veces con sufijo) → id de inventario de World Office.
 * Primero lo ya visto en renglones de REM/FV, luego libros.isbn → libros.id_wo, y por último la API.
 */
function backorders_devol_resolver_codigos($bdd, array $codigos) {
    $codigos = array_values(array_unique(array_filter(array_map('trim', $codigos), 'strlen')));
    $mapa = [];
    if (!$codigos) return $mapa;
    $ph = implode(',', array_fill(0, count($codigos), '?'));
    $stmt = $bdd->prepare("SELECT isbn, MAX(id_libro) FROM backorder_wo_renglones WHERE id_libro IS NOT NULL AND isbn IN ($ph) GROUP BY isbn");
    $stmt->execute($codigos);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$c, $id]) $mapa[$c] = (int)$id;
    $faltan = array_values(array_diff($codigos, array_keys($mapa)));
    if ($faltan) {
        $ph = implode(',', array_fill(0, count($faltan), '?'));
        $stmt = $bdd->prepare("SELECT isbn, MAX(id_wo) FROM libros WHERE id_wo > 0 AND isbn IN ($ph) GROUP BY isbn");
        $stmt->execute($faltan);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$c, $id]) $mapa[$c] = (int)$id;
    }
    $faltan = array_values(array_diff($codigos, array_keys($mapa)));
    if ($faltan) {
        $peticiones = [];
        foreach ($faltan as $c) {
            $peticiones[$c] = ['endpoint' => '/inventarios/listarInventarios', 'metodo' => 'POST', 'datos' => [
                'columnaOrdenar' => 'id', 'pagina' => 0, 'registrosPorPagina' => 5, 'orden' => 'DESC', 'canal' => 0,
                'registroInicial' => 0, 'filtros' => [crear_filtro_api('codigo', $c, 0, 0)],
            ]];
        }
        foreach (hacer_peticiones_api_paralelo($peticiones, 8) as $c => $resp) {
            foreach ($resp['data']['content'] ?? [] as $inv) {
                if (trim((string)($inv['codigo'] ?? '')) === $c) { $mapa[$c] = (int)$inv['id']; break; }
            }
        }
    }
    return $mapa;
}

/**
 * Sincroniza DREM y NCV: listado completo, anuladas, eliminadas, y el detalle (renglones y, en
 * notas crédito, factura asociada) de las nuevas o modificadas — máximo $maxDetalles por llamada.
 */
function backorders_sync_devoluciones($bdd, $maxDetalles = 40) {
    backorders_devol_crear_tablas($bdd);
    $resumen = ['documentos' => 0, 'nuevos' => 0, 'modificados' => 0, 'anulados' => 0, 'eliminados' => 0, 'errores' => 0, 'faltan' => 0];
    // Notas crédito de otro prefijo guardadas antes de esta regla: sin renglones.
    $bdd->prepare("DELETE r FROM backorder_wo_devol_renglones r
                   JOIN backorder_wo_devoluciones d ON d.id_wo = r.id_wo AND d.tipo_documento = r.tipo_documento
                   WHERE d.tipo_documento = 'NCV' AND d.prefijo <> ?")->execute([BACKORDERS_NC_PREFIJO]);
    $bdd->prepare("UPDATE backorder_wo_devoluciones SET detalle_ok = 1 WHERE tipo_documento = 'NCV' AND prefijo <> ?")->execute([BACKORDERS_NC_PREFIJO]);

    $guardados = [];
    foreach ($bdd->query("SELECT id_wo, tipo_documento, anulado, detalle_ok, fecha_modificacion FROM backorder_wo_devoluciones")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $guardados[$r['tipo_documento'] . '|' . $r['id_wo']] = $r;
    }

    $upsert = $bdd->prepare("
        INSERT INTO backorder_wo_devoluciones
            (id_wo, tipo_documento, prefijo, numero, fecha, concepto, cliente, anulado, op_ref, detalle_ok, fecha_modificacion, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE prefijo = VALUES(prefijo), numero = VALUES(numero), fecha = VALUES(fecha), concepto = VALUES(concepto),
            cliente = VALUES(cliente), anulado = VALUES(anulado), op_ref = VALUES(op_ref), detalle_ok = VALUES(detalle_ok),
            fecha_modificacion = VALUES(fecha_modificacion), updated_at = NOW()
    ");
    $delRenglones = $bdd->prepare("DELETE FROM backorder_wo_devol_renglones WHERE id_wo = ? AND tipo_documento = ?");

    $aTraer = []; // "TIPO|id" => ['tipo', 'id_wo', 'estado' => nuevo|modificado]
    foreach (['DREM', 'NCV'] as $tipo) {
        $filas = backorders_devol_listar($tipo);
        if ($filas === null) { $resumen['errores']++; continue; }
        $vistos = [];
        $bdd->beginTransaction();
        foreach ($filas as $row) {
            if (empty($row['id'])) continue;
            $idWo = (int)$row['id'];
            $clave = $tipo . '|' . $idWo;
            $vistos[$clave] = true;
            $resumen['documentos']++;
            $g = $guardados[$clave] ?? null;
            $anulado = !empty($row['senAnulado']) ? 1 : 0;
            $prefijo = (string)($row['prefijo'] ?? '');
            $fm = (string)($row['fechaModificacion'] ?? '');
            $cambio = !$g || (string)$g['fecha_modificacion'] !== $fm || (int)$g['anulado'] !== $anulado;
            if (!$cambio) {
                if (!(int)$g['detalle_ok'] && !$anulado) $aTraer[$clave] = ['tipo' => $tipo, 'id_wo' => $idWo, 'estado' => 'modificado'];
                continue;
            }
            // Devoluciones de muestras (RE22), notas crédito que no son DMC y anuladas: sin renglones, no cuentan.
            $sinDetalle = $anulado || ($tipo === 'DREM' && $prefijo === 'RE22') || ($tipo === 'NCV' && $prefijo !== BACKORDERS_NC_PREFIJO);
            $upsert->execute([$idWo, $tipo, $prefijo, (string)($row['numero'] ?? ''), backorders_fecha_wo($row['fecha'] ?? ''),
                (string)($row['concepto'] ?? ''), trim((string)($row['terceroExterno'] ?? '')), $anulado,
                backorders_devol_extraer_op($row['concepto'] ?? ''), $sinDetalle ? 1 : 0, $fm]);
            if ($sinDetalle) {
                $delRenglones->execute([$idWo, $tipo]);
                if ($anulado && $g && !(int)$g['anulado']) $resumen['anulados']++;
            } else {
                $aTraer[$clave] = ['tipo' => $tipo, 'id_wo' => $idWo, 'estado' => $g ? 'modificado' : 'nuevo'];
            }
        }
        // Eliminadas de World Office: ya no salen en el listado completo.
        foreach ($guardados as $clave => $g) {
            if ($g['tipo_documento'] !== $tipo || isset($vistos[$clave])) continue;
            $bdd->prepare("DELETE FROM backorder_wo_devoluciones WHERE id_wo = ? AND tipo_documento = ?")->execute([(int)$g['id_wo'], $tipo]);
            $delRenglones->execute([(int)$g['id_wo'], $tipo]);
            $resumen['eliminados']++;
        }
        $bdd->commit();
    }

    $resumen['faltan'] = max(0, count($aTraer) - $maxDetalles);
    $aTraer = array_slice($aTraer, 0, $maxDetalles, true);
    if (!$aTraer) return $resumen;

    $insRenglon = $bdd->prepare("INSERT INTO backorder_wo_devol_renglones (id_wo, tipo_documento, id_libro, isbn, descripcion, cantidad) VALUES (?, ?, ?, ?, ?, ?)");
    $marcar = $bdd->prepare("UPDATE backorder_wo_devoluciones SET detalle_ok = 1, id_fv_asociada = ?, efecto_inventario = ?, updated_at = NOW() WHERE id_wo = ? AND tipo_documento = ?");
    $guardar = function ($tipo, $idWo, array $renglones, $idFv, $efecto) use ($bdd, $insRenglon, $marcar, $delRenglones) {
        $bdd->beginTransaction();
        $delRenglones->execute([$idWo, $tipo]);
        foreach ($renglones as $r) $insRenglon->execute([$idWo, $tipo, $r['id_libro'], $r['isbn'], $r['descripcion'], $r['cantidad']]);
        $marcar->execute([$idFv, $efecto, $idWo, $tipo]);
        $bdd->commit();
    };

    // Notas crédito: encabezado (factura asociada + tipo de nota) y renglones, en paralelo.
    $peticiones = [];
    foreach ($aTraer as $clave => $d) {
        if ($d['tipo'] !== 'NCV') continue;
        $peticiones[$clave . '|doc'] = ['endpoint' => '/documentos/getDocumentoId/' . $d['id_wo'], 'metodo' => 'GET'];
        $peticiones[$clave . '|ren'] = ['endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $d['id_wo'], 'metodo' => 'POST',
            'datos' => ['columnaOrdenar' => 'id', 'pagina' => 0, 'registrosPorPagina' => 300, 'orden' => 'ASC', 'canal' => 0, 'registroInicial' => 0, 'filtros' => []]];
    }
    $respuestas = hacer_peticiones_api_paralelo($peticiones, 8);
    foreach ($aTraer as $clave => $d) {
        if ($d['tipo'] !== 'NCV') continue;
        $doc = $respuestas[$clave . '|doc'] ?? [];
        $ren = $respuestas[$clave . '|ren'] ?? [];
        if (!in_array($doc['status'] ?? '', ['OK', 'ACCEPTED'], true) || ($ren['status'] ?? '') !== 'OK') { $resumen['errores']++; continue; }
        $data = $doc['data'] ?? [];
        $asociado = $data['docAsociado'] ?? null;
        $idFv = ($asociado && ($asociado['documentoTipo']['codigoDocumento'] ?? '') === 'FV' && (int)($asociado['id'] ?? 0) > 0) ? (int)$asociado['id'] : null;
        $efecto = (int)($data['documentoTipoNotaCredito']['efectoEnInventario'] ?? 1) ? 1 : 0;
        $renglones = [];
        foreach ($ren['data']['content'] ?? [] as $r) {
            $inv = $r['inventario'] ?? [];
            $renglones[] = ['id_libro' => $inv['id'] ?? null, 'isbn' => $inv['codigo'] ?? null, 'descripcion' => $inv['descripcion'] ?? null, 'cantidad' => (float)($r['cantidad'] ?? 0)];
        }
        try {
            $guardar('NCV', $d['id_wo'], $renglones, $idFv, $efecto);
            $d['estado'] === 'nuevo' ? $resumen['nuevos']++ : $resumen['modificados']++;
        } catch (Exception $e) { if ($bdd->inTransaction()) $bdd->rollBack(); $resumen['errores']++; }
    }

    // Devoluciones de remisión: solo el formato de impresión trae sus renglones (por código, sin id
    // de inventario: se resuelve aparte).
    foreach ($aTraer as $clave => $d) {
        if ($d['tipo'] !== 'DREM') continue;
        $resp = obtener_detalle_impresion_wo($d['id_wo']);
        if (($resp['status'] ?? '') !== 'OK') { $resumen['errores']++; continue; }
        $detalles = $resp['data']['detalles'] ?? [];
        $mapa = backorders_devol_resolver_codigos($bdd, array_map(fn($x) => (string)($x['codigo'] ?? ''), $detalles));
        $renglones = [];
        foreach ($detalles as $x) {
            $codigo = trim((string)($x['codigo'] ?? ''));
            $renglones[] = ['id_libro' => $mapa[$codigo] ?? null, 'isbn' => $codigo, 'descripcion' => $x['descripcion'] ?? null, 'cantidad' => (float)($x['cantidad'] ?? 0)];
        }
        try {
            $guardar('DREM', $d['id_wo'], $renglones, null, 1);
            $d['estado'] === 'nuevo' ? $resumen['nuevos']++ : $resumen['modificados']++;
        } catch (Exception $e) { if ($bdd->inTransaction()) $bdd->rollBack(); $resumen['errores']++; }
    }
    return $resumen;
}

/**
 * Documentos válidos (no anulados, que afectan inventario) con sus libros: "TIPO|id" => [...].
 * 'libros' va por clave de backorders_clave_libro(); un paquete devuelto se abre en sus libros.
 */
function backorders_devol_documentos($bdd) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    // Sin CREATE TABLE aquí: un DDL hace commit implícito de la transacción que esté abierta (p. ej.
    // al generar un pedido nuevo). Si la tabla aún no existe (nadie ha sincronizado), no hay nada.
    try {
        $sql = "SELECT d.id_wo, d.tipo_documento, d.prefijo, d.numero, d.fecha, d.op_ref, d.id_fv_asociada, r.id_libro, r.isbn, SUM(r.cantidad) AS cantidad
                FROM backorder_wo_devoluciones d
                JOIN backorder_wo_devol_renglones r ON r.id_wo = d.id_wo AND r.tipo_documento = d.tipo_documento
                WHERE d.anulado = 0 AND d.efecto_inventario = 1 AND (r.id_libro IS NOT NULL OR r.isbn IS NOT NULL)
                  AND (d.tipo_documento <> 'NCV' OR d.prefijo = '" . BACKORDERS_NC_PREFIJO . "')
                GROUP BY d.id_wo, d.tipo_documento, r.id_libro, r.isbn
                ORDER BY d.fecha, d.id_wo";
        foreach ($bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clave = $r['tipo_documento'] . '|' . $r['id_wo'];
            if (!isset($cache[$clave])) {
                $cache[$clave] = [
                    'clave' => $clave,
                    'documento' => backorders_devol_etiqueta($r['tipo_documento'], $r['prefijo'], $r['numero']),
                    'op_ref' => $r['op_ref'] !== null ? (int)$r['op_ref'] : null,
                    'id_fv_asociada' => $r['id_fv_asociada'] !== null ? (int)$r['id_fv_asociada'] : null,
                    'libros' => [],
                ];
            }
            $paquete = backorders_paquete_de_codigo($bdd, $r['isbn']);
            if ($paquete) {
                foreach ($paquete['libros'] as $lib) {
                    $cache[$clave]['libros'][$lib['clave']] = ($cache[$clave]['libros'][$lib['clave']] ?? 0) + (float)$r['cantidad'];
                }
            } elseif ($r['id_libro'] !== null) {
                $cache[$clave]['libros'][(int)$r['id_libro']] = ($cache[$clave]['libros'][(int)$r['id_libro']] ?? 0) + (float)$r['cantidad'];
            }
        }
    } catch (Exception $e) { $cache = []; }
    return $cache;
}

/**
 * Notas crédito ligadas a una factura: "FV|numero" => [documentos]. El número sale de
 * backorder_wo_docs (la FV ya sincronizada para backorders, por su id de World Office).
 */
function backorders_devol_por_factura($bdd) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $docs = array_filter(backorders_devol_documentos($bdd), fn($d) => $d['id_fv_asociada']);
    if (!$docs) return $cache;
    $ids = array_values(array_unique(array_column($docs, 'id_fv_asociada')));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $bdd->prepare("SELECT id_wo, numero FROM backorder_wo_docs WHERE tipo_documento = 'FV' AND anulado = 0 AND id_wo IN ($ph)");
    $stmt->execute($ids);
    $numeroPorId = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($docs as $d) {
        if (isset($numeroPorId[$d['id_fv_asociada']])) $cache['FV|' . $numeroPorId[$d['id_fv_asociada']]][] = $d;
    }
    return $cache;
}

/**
 * Devoluciones sin factura asociada (DREM y NC sin factura) agrupadas por colegio + período de la
 * devolución del CRM a la que apunta su OP: "id_colegio|id_periodo" => [documentos].
 */
function backorders_devol_por_colegio_periodo($bdd) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $docs = array_filter(backorders_devol_documentos($bdd), fn($d) => !$d['id_fv_asociada'] && $d['op_ref']);
    if (!$docs) return $cache;
    $ops = array_values(array_unique(array_column($docs, 'op_ref')));
    $ph = implode(',', array_fill(0, count($ops), '?'));
    $phEstados = implode(',', BACKORDERS_DEVOL_ESTADOS);
    $stmt = $bdd->prepare("SELECT o.id, d.id_colegio, d.id_periodo
                           FROM ordenes_pedidos o
                           JOIN devoluciones_v d ON d.id = o.id_devol_v
                           WHERE o.id IN ($ph) AND o.estado != 4 AND d.estado IN ($phEstados) AND d.id_colegio > 0 AND d.id_periodo > 0");
    $stmt->execute($ops);
    $grupoPorOp = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $grupoPorOp[(int)$r['id']] = $r['id_colegio'] . '|' . $r['id_periodo'];
    foreach ($docs as $d) {
        if (isset($grupoPorOp[$d['op_ref']])) $cache[$grupoPorOp[$d['op_ref']]][] = $d;
    }
    return $cache;
}

/**
 * Agrupa líneas de calcular_lineas_backorder() por colegio + período + libro (sumando todos los
 * pedidos del colegio) y le resta a cada grupo lo devuelto: devoluciones de remisión y notas crédito
 * sin factura por la devolución del CRM, notas crédito con factura por el colegio del pedido de esa
 * FV. Nunca se devuelve más de lo remisionado/facturado del grupo.
 *
 * Cada grupo: id_colegio, colegio, id_periodo, id_libro, libro, isbn, id_usuario, usuario_nombre,
 * cliente, id_cliente, pedidos [ids], solicitada, remisionada, devuelta, despachada (= remisionada −
 * devuelta), pendiente, excedente, cumplimiento, docs_devolucion [etiquetas].
 */
function backorders_grupos_colegio($bdd, array $lineas) {
    $grupos = [];    // "colegio|periodo|id_libro" => grupo
    $fvGrupo = [];   // "FV|numero" => "colegio|periodo"
    $porLibroWo = []; // "colegio|periodo" => [clave_libro => [claves de grupo]] (backorders_clave_libro())
    foreach ($lineas as $l) {
        $cp = $l['id_colegio'] . '|' . $l['id_periodo'];
        $k = $cp . '|' . $l['id_libro'];
        if (!isset($grupos[$k])) {
            $grupos[$k] = [
                'id_colegio' => $l['id_colegio'], 'colegio' => $l['colegio'], 'id_periodo' => $l['id_periodo'],
                'id_libro' => $l['id_libro'], 'libro' => $l['libro'], 'isbn' => $l['isbn'], 'libro_id_wo' => $l['libro_id_wo'],
                'id_usuario' => $l['id_usuario'], 'usuario_nombre' => $l['usuario_nombre'],
                'cliente' => $l['cliente'], 'id_cliente' => $l['id_cliente'],
                'pedidos' => [], 'solicitada' => 0.0, 'remisionada' => 0.0, 'devuelta' => 0.0, 'docs_devolucion' => [],
            ];
            $porLibroWo[$cp][backorders_clave_libro($l['libro_id_wo'], $l['id_libro'])][] = $k;
        }
        $grupos[$k]['pedidos'][$l['id_pedido']] = $l['id_pedido'];
        $grupos[$k]['solicitada'] += $l['solicitada'];
        $grupos[$k]['remisionada'] += $l['remisionada'];
        foreach ($l['ops'] as $op) {
            if ($op['tipo_wo'] === 'FV' && empty($op['anulado'])) $fvGrupo['FV|' . $op['n_doc']] = $cp;
        }
    }

    // Documentos de devolución por colegio + período (sin repetir un documento).
    $docsPorCp = [];
    foreach (backorders_devol_por_colegio_periodo($bdd) as $cp => $docs) {
        foreach ($docs as $d) $docsPorCp[$cp][$d['clave']] = $d;
    }
    foreach (backorders_devol_por_factura($bdd) as $fv => $ncs) {
        if (!isset($fvGrupo[$fv])) continue;
        foreach ($ncs as $d) $docsPorCp[$fvGrupo[$fv]][$d['clave']] = $d;
    }
    foreach ($docsPorCp as $cp => $docs) {
        foreach ($docs as $d) {
            foreach ($d['libros'] as $idLibroWo => $cantidad) {
                foreach ($porLibroWo[$cp][$idLibroWo] ?? [] as $k) {
                    $tomar = min($cantidad, $grupos[$k]['remisionada'] - $grupos[$k]['devuelta']);
                    if ($tomar <= 0) continue;
                    $grupos[$k]['devuelta'] += $tomar;
                    $grupos[$k]['docs_devolucion'][$d['documento']] = $d['documento'];
                    $cantidad -= $tomar;
                    if ($cantidad <= 0) break;
                }
            }
        }
    }

    foreach ($grupos as &$g) {
        $neto = $g['remisionada'] - $g['devuelta'];
        $g['pedidos'] = array_values($g['pedidos']);
        sort($g['pedidos']);
        $g['docs_devolucion'] = array_values($g['docs_devolucion']);
        $g['solicitada'] = round($g['solicitada'], 2);
        $g['remisionada'] = round($g['remisionada'], 2);
        $g['devuelta'] = round($g['devuelta'], 2);
        $g['despachada'] = round($neto, 2);
        $g['pendiente'] = round(max(0.0, $g['solicitada'] - $neto), 2);
        $g['excedente'] = round(max(0.0, $neto - $g['solicitada']), 2);
        $g['cumplimiento'] = porcentaje_cumplimiento_backorder($g['solicitada'], $neto);
    }
    unset($g);
    return array_values($grupos);
}
