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
 *
 * Desde 2026-10-27 (pedido por la usuaria), igual que las devoluciones: se elige el colegio y se legalizan
 * de una vez todos sus pedidos pendientes en UNA legalización (muestreos_e.ids_muestreos +
 * libros_muestreos_e.reparto).
 */
require_once __DIR__ . '/devoluciones_muestras_datos.php';

/**
 * Las legalizaciones solo trabajan con remisiones de muestras: tipo REM, prefijo RE22 (pedido por el
 * usuario 2026-10-13). Nunca facturas FVE ni remisiones de otro prefijo (ej. CEUR).
 */
const LM_PREFIJO_WO = 'RE22';

/**
 * Detalle de UN pedido para legalizar. $fuente:
 *  - 'auto': lo despachado en World Office, buscando por la OP escrita en el concepto y solo en
 *    remisiones RE22.
 *  - 'crm': botón "Traer información del pedido" (pedido por el usuario 2026-10-13), para cuando WO no
 *    trae nada: los libros y cantidades de la solicitud de muestras tal como se montó en el CRM
 *    (ver dm_detalle_crm()).
 */
function lm_detalle_legalizacion(PDO $bdd, $idMuestreo, $fuente = 'auto') {
    return dm_detalle_por_fuente($bdd, $idMuestreo, 'legalizacion', $fuente, [LM_PREFIJO_WO]);
}

/**
 * Detalle de una legalización de VARIOS pedidos del mismo colegio (ver dm_detalle_varios()): una sola
 * búsqueda en WO, solo remisiones RE22, con los títulos sumados por libro. $fuentes = [id_muestreo =>
 * 'crm'] para los pedidos que se cargan con lo solicitado en el CRM.
 */
function lm_detalle_varios(PDO $bdd, array $ids, array $fuentes = []) {
    return dm_detalle_varios($bdd, $ids, $fuentes, 'legalizacion', [LM_PREFIJO_WO]);
}

/**
 * Guarda UNA legalización de varios pedidos de muestras del mismo colegio. $cantidades = [id_libro =>
 * cantidad a legalizar]: el tope es la suma de lo disponible del libro (despachado − legalizado) en todos
 * los pedidos elegidos. Lo legalizado se reparte entre los pedidos, el más antiguo primero
 * (dm_repartir_varios()), y queda en libros_muestreos_e.reparto. Se recalcula todo en el servidor con los
 * pedidos bloqueados, así que el tope no se salta desde el navegador.
 * $cierre = 'parcial' (siguen abiertos hasta agotar unidades) o 'completa' (cierra en este módulo todos los
 * pedidos incluidos aunque queden unidades).
 * Devuelve ['ok','error','id_legalizacion'].
 */
function lm_guardar_legalizacion_varios(PDO $bdd, array $ids, array $fuentes, array $cantidades, $observaciones, $archivo, $cierre) {
    if (!in_array($cierre, ['parcial', 'completa'], true)) return ['ok' => false, 'error' => 'Elige si la legalización es parcial o completa.'];
    $detalle = lm_detalle_varios($bdd, $ids, $fuentes);
    if (!$detalle['ok']) return ['ok' => false, 'error' => $detalle['error']];
    $prep = dm_preparar_varios($detalle, $cantidades, 'legalizacion');
    if (!$prep['ok']) return ['ok' => false, 'error' => $prep['error']];
    $incluidos = $prep['incluidos'];

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $rep = dm_repartir_varios($bdd, $prep, 'legalizacion');
        if (!$rep['ok']) return ['ok' => false, 'error' => $rep['error']];

        do {
            $codigo = '';
            for ($i = 0; $i < 10; $i++) $codigo .= random_int(0, 9);
            $ck = $bdd->prepare("SELECT 1 FROM muestreos_e WHERE codigo = ?");
            $ck->execute([$codigo]);
        } while ($ck->fetchColumn());

        // Mismo período que el formulario anterior (php/crear_muestreo.php, tp=2): el último creado.
        $idPeriodo = (int)$bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn();

        $stmtLibro = $bdd->prepare("INSERT INTO libros_muestreos_e (cod_muestreo, id_libro, cantidad, cantidad_aprob, id_grado_otro,
                                                                    producto_wo, cant_despachada, documentos_wo, reparto, created_at)
                                    VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, NOW())");
        foreach ($prep['a_registrar'] as $idLibro => $cant) {
            $stmtLibro->execute(array_merge([$codigo, $idLibro, $cant], dm_fila_libro_varios($prep, $idLibro, $rep['reparto'][$idLibro])));
        }

        // Estado 1 = activa (muestras_entregadas.php lista estado=1; al eliminarla pasa a 0).
        $bdd->prepare("INSERT INTO muestreos_e (codigo, id_periodo, id_colegio, id_usuario, observaciones, estado, archivo, tipo,
                                                id_muestreo, ids_muestreos, ops_muestreo, documentos_wo, cierre, created_at)
                       VALUES (?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, ?, ?, NOW())")
            ->execute([$codigo, $idPeriodo, $detalle['id_colegio'], $_SESSION['id'], $observaciones, $archivo,
                       $incluidos[0], implode(',', $incluidos), mb_substr(implode(', ', $prep['ops']), 0, 100), mb_substr(implode(', ', $prep['docs']), 0, 500), $cierre]);
        $idLegalizacion = (int)$bdd->lastInsertId();

        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id_legalizacion' => $idLegalizacion];
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Legalización de muestras #' . implode(',', $incluidos) . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar la legalización: ' . $e->getMessage()];
    }
}
