<?php
/**
 * /includes/legalizaciones_muestras_datos.php
 * Módulo "Legalizar muestras" (legalizaciones_muestras.php), pedido por el usuario 2026-10-07 para
 * reemplazar el formulario manual solicitar_muestreo.php?tp=2. Es el mismo flujo de
 * devoluciones_muestras.php (pedido de muestras Entregado → OP → documento de World Office →
 * títulos despachados), pero lo registrado se LEGALIZA en vez de devolverse.
 *
 * Se guarda en las tablas de siempre de las legalizaciones (`muestreos_e` + `libros_muestreos_e`,
 * que lista muestras_entregadas.php) con columnas de trazabilidad (ver dm_asegurar_columnas()).
 * Es independiente de las devoluciones (pedido por el usuario 2026-10-07): el tope es
 * despachado − legalizado, sin importar lo devuelto.
 */
require_once __DIR__ . '/devoluciones_muestras_datos.php';

/**
 * Las legalizaciones solo trabajan con remisiones de muestras: tipo REM, prefijo RE22 (pedido por el
 * usuario 2026-10-13). Nunca facturas FVE ni remisiones de otro prefijo (ej. CEUR).
 */
const LM_PREFIJO_WO = 'RE22';

/**
 * Detalle del pedido para legalizar. $fuente:
 *  - 'auto': lo despachado en World Office, buscando por la OP escrita en el concepto y solo en
 *    remisiones RE22.
 *  - 'crm': botón "Traer información del pedido" (pedido por el usuario 2026-10-13), para cuando WO no
 *    trae nada: los libros y cantidades de la solicitud de muestras tal como se montó en el CRM
 *    (libros_muestreos). Ver lm_detalle_crm().
 * El guardado vuelve a calcular con la misma fuente, así que el tope no se salta en ninguno de los dos.
 * Cada título trae 'clave' (producto WO en 'auto', id del libro en 'crm'): la del campo cantidad[...].
 */
function lm_detalle_legalizacion(PDO $bdd, $idMuestreo, $fuente = 'auto') {
    if ($fuente === 'crm') return lm_detalle_crm($bdd, $idMuestreo);
    $detalle = dm_detalle_devolucion($bdd, $idMuestreo, 'legalizacion', fn($ops) => dm_documentos_wo_de_ops($ops, LM_PREFIJO_WO));
    foreach ($detalle['titulos'] ?? [] as $i => $t) $detalle['titulos'][$i]['clave'] = $t['producto_wo'];
    $detalle['fuente'] = 'auto';
    return $detalle;
}

/**
 * Libros solicitados en el CRM (libros_muestreos.cantidad, lo que se montó desde el principio; no lo
 * despachado ni lo aprobado) con lo ya legalizado de cada libro en el pedido y lo disponible
 * (solicitado − legalizado). Lo legalizado se cuenta por libro en todas las legalizaciones activas del
 * pedido, vengan de WO o del CRM, para que el mismo libro no se legalice dos veces por caminos distintos.
 */
function lm_detalle_crm(PDO $bdd, $idMuestreo) {
    $muestreo = dm_obtener_muestreo($bdd, $idMuestreo);
    if (!$muestreo) return ['ok' => false, 'error' => 'El pedido de muestras no existe, no está Entregado o no está en tu alcance.'];

    $stmt = $bdd->prepare("SELECT lm.id_libro, SUM(lm.cantidad) AS solicitada, l.libro, TRIM(l.isbn) AS isbn, l.id_wo, l.tipo
                           FROM libros_muestreos lm
                           JOIN libros l ON l.id = lm.id_libro
                           WHERE lm.cod_muestreo = ?
                           GROUP BY lm.id_libro
                           HAVING solicitada > 0
                           ORDER BY l.libro");
    $stmt->execute([(string)$muestreo['codigo']]);
    $legalizado = lm_legalizado_por_libro($bdd, $idMuestreo);

    $titulos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $idLibro = (int)$l['id_libro'];
        $ya = $legalizado[$idLibro] ?? 0;
        $titulos[] = [
            'clave'       => $idLibro,
            'producto_wo' => (int)$l['id_wo'] ?: null,
            'descripcion' => $l['libro'],
            'codigo'      => (string)$l['isbn'],
            'id_libro'    => $idLibro,
            'libro'       => $l['libro'],
            'tipo_ed'     => dm_tipo_libro_ed($l['tipo']),
            'solicitada'  => (int)$l['solicitada'],
            'legalizada'  => $ya,
            'disponible'  => max(0, (int)$l['solicitada'] - $ya),
            'documentos'  => 'Solicitud CRM',
            'ids_documentos' => null,
        ];
    }
    if (!$titulos) return ['ok' => false, 'error' => 'El pedido de muestras #' . (int)$idMuestreo . ' no tiene libros registrados en el CRM.', 'muestreo' => $muestreo, 'ops' => dm_ops_muestreo($bdd, $idMuestreo)];

    return ['ok' => true, 'error' => null, 'fuente' => 'crm', 'muestreo' => $muestreo, 'ops' => dm_ops_muestreo($bdd, $idMuestreo),
            'documentos' => [], 'titulos' => $titulos, 'cierre' => dm_cierre_completo($bdd, $idMuestreo, 'legalizacion')];
}

/** Unidades ya legalizadas por libro del CRM en legalizaciones activas del muestreo: [id_libro => n]. */
function lm_legalizado_por_libro(PDO $bdd, $idMuestreo) {
    $stmt = $bdd->prepare("SELECT lme.id_libro, SUM(lme.cantidad)
                           FROM libros_muestreos_e lme
                           JOIN muestreos_e me ON me.codigo = lme.cod_muestreo
                           WHERE me.id_muestreo = ? AND me.estado <> " . DM_ESTADO_LEGALIZ_ANULADA . "
                           GROUP BY lme.id_libro");
    $stmt->execute([(int)$idMuestreo]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Guarda una legalización. $cantidades = [clave del título => cantidad a legalizar]. Recalcula lo
 * despachado en World Office (o lo solicitado en el CRM, fuente 'crm') y lo ya legalizado con el muestreo bloqueado, igual que dm_guardar_devolucion().
 * $cierre = 'parcial' (sigue abierto hasta agotar unidades) o 'completa' (cierra el pedido en este
 * módulo aunque queden unidades). $fuente = la de lm_detalle_legalizacion() con que se cargó la pantalla.
 * Devuelve ['ok','error','id_legalizacion'].
 */
function lm_guardar_legalizacion(PDO $bdd, $idMuestreo, array $cantidades, $observaciones, $archivo, $cierre, $fuente = 'auto') {
    if (!in_array($cierre, ['parcial', 'completa'], true)) return ['ok' => false, 'error' => 'Elige si la legalización es parcial o completa.'];
    $detalle = lm_detalle_legalizacion($bdd, $idMuestreo, $fuente);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    if ($detalle['cierre']) return ['ok' => false, 'error' => dm_mensaje_cerrado('legalizacion', $detalle['cierre'])];

    $crm = $detalle['fuente'] === 'crm';
    $titulosPorProducto = [];
    foreach ($detalle['titulos'] as $t) $titulosPorProducto[$t['clave']] = $t;

    $aLegalizar = [];
    foreach ($cantidades as $pid => $cant) {
        $pid = (int)$pid;
        if (!preg_match('/^\d*$/', trim((string)$cant))) return ['ok' => false, 'error' => 'Las cantidades deben ser números enteros, sin decimales ni negativos.'];
        $cant = (int)$cant;
        if ($cant === 0) continue;
        if (!isset($titulosPorProducto[$pid])) return ['ok' => false, 'error' => $crm ? 'Uno de los títulos no está en la solicitud de este pedido en el CRM.' : 'Uno de los títulos no aparece despachado en World Office para este pedido.'];
        if (!$titulosPorProducto[$pid]['id_libro']) return ['ok' => false, 'error' => 'El título "' . $titulosPorProducto[$pid]['descripcion'] . '" no está en el catálogo de libros del CRM.'];
        $aLegalizar[$pid] = $cant;
    }
    if (!$aLegalizar) return ['ok' => false, 'error' => 'Ingresa al menos una cantidad a legalizar.'];

    $muestreo = $detalle['muestreo'];
    $opsLabel = implode(', ', array_map(fn($o) => $o['anio'] . '-' . $o['id'], $detalle['ops']));
    $docsLabel = implode(', ', array_map(fn($d) => $d['documento'], $detalle['documentos']));

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        // Bloqueo del muestreo: serializa legalizaciones simultáneas del mismo pedido.
        $bdd->prepare("SELECT id FROM muestreos WHERE id = ? FOR UPDATE")->execute([(int)$idMuestreo]);
        if ($cierreActual = dm_cierre_completo($bdd, $idMuestreo, 'legalizacion')) {
            $bdd->rollBack();
            return ['ok' => false, 'error' => dm_mensaje_cerrado('legalizacion', $cierreActual)];
        }
        $legalizado = $crm ? lm_legalizado_por_libro($bdd, $idMuestreo) : dm_legalizado_por_producto($bdd, $idMuestreo);
        foreach ($aLegalizar as $pid => $cant) {
            $t = $titulosPorProducto[$pid];
            $base = $crm ? $t['solicitada'] : $t['despachada'];
            $disponible = max(0, $base - ($legalizado[$pid] ?? 0));
            if ($cant > $disponible) {
                $bdd->rollBack();
                return ['ok' => false, 'error' => 'No puedes legalizar ' . $cant . ' de "' . $t['descripcion'] . '": se ' . ($crm ? 'solicitaron ' : 'despacharon ')
                    . $base . ', ya se legalizaron ' . ($legalizado[$pid] ?? 0) . ' y quedan ' . $disponible . ' disponibles.'];
            }
        }

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM muestreos_e WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        // Mismo período que el formulario anterior (php/crear_muestreo.php, tp=2): el último creado.
        $idPeriodo = (int)$bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn();

        $stmtLibro = $bdd->prepare("INSERT INTO libros_muestreos_e (cod_muestreo, id_libro, cantidad, cantidad_aprob, id_grado_otro,
                                                                    producto_wo, cant_despachada, documentos_wo, created_at)
                                    VALUES (?, ?, ?, 0, 0, ?, ?, ?, NOW())");
        foreach ($aLegalizar as $pid => $cant) {
            $t = $titulosPorProducto[$pid];
            // Desde el CRM: producto_wo = libros.id_wo (para que también cuente en el saldo de lo despachado
            // en WO) y sin cantidad despachada ni documento de WO.
            $stmtLibro->execute([$codigo, $t['id_libro'], $cant, $t['producto_wo'], $crm ? null : $t['despachada'], $t['ids_documentos']]);
        }

        // Estado 1 = activa (muestras_entregadas.php lista estado=1; al eliminarla pasa a 0).
        $bdd->prepare("INSERT INTO muestreos_e (codigo, id_periodo, id_colegio, id_usuario, observaciones, estado, archivo, tipo,
                                                id_muestreo, ops_muestreo, documentos_wo, cierre, created_at)
                       VALUES (?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, ?, NOW())")
            ->execute([$codigo, $idPeriodo, $muestreo['id_colegio'], $_SESSION['id'], $observaciones, $archivo,
                       (int)$idMuestreo, mb_substr($opsLabel, 0, 100), mb_substr($docsLabel, 0, 500), $cierre]);
        $idLegalizacion = (int)$bdd->lastInsertId();

        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_legalizacion' => $idLegalizacion];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Legalización de muestras #' . (int)$idMuestreo . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la legalización: ' . $e->getMessage()];
    }
}
