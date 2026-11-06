<?php
/**
 * Consecutivos de backorder por período: "BK {PERIODO}-{NN}" (BK 2026B-01, BK 2027-01...), cada
 * período con su propia secuencia — pedido por la usuaria 2026-10-20.
 *
 * Reglas:
 *  - Un backorder = un pedido ORIGINAL (los pedidos nuevos generados para atenderlo nunca reciben
 *    consecutivo propio).
 *  - Solo se asigna cuando el pedido tiene una REM/FV CONFIRMADA en World Office (sincronizada, no
 *    anulada ni eliminada — ver calcular_lineas_backorder(), op['confirmado']) Y le queda algo
 *    pendiente por despachar. Un número escrito a mano en la OP que World Office no tiene no basta.
 *  - Una vez asignado no cambia nunca (ni si se completa, ni si se anulan documentos, ni al volver a
 *    consultar World Office) y nunca se reutiliza: los números solo crecen (MAX + 1).
 *  - Asignación segura ante procesos simultáneos: candado con nombre (GET_LOCK) + transacción, y
 *    como última barrera UNIQUE(id_pedido) y UNIQUE(id_periodo, numero).
 *  - Aplica a los períodos desde 2026 en adelante (incluye los futuros sin configurar nada).
 */

const BACKORDERS_PERIODO_INICIAL_CONSECUTIVOS = '2026';

function backorders_asegurar_tabla_consecutivos($bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_consecutivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pedido INT NOT NULL,
        id_periodo INT NOT NULL,
        periodo VARCHAR(20) NOT NULL,
        numero INT NOT NULL,
        consecutivo VARCHAR(40) NOT NULL,
        fecha_asignacion DATETIME NOT NULL,
        tipo_documento VARCHAR(10) NULL,
        numero_documento VARCHAR(30) NULL,
        id_wo INT NULL,
        UNIQUE KEY uq_pedido (id_pedido),
        UNIQUE KEY uq_periodo_numero (id_periodo, numero),
        KEY idx_periodo (id_periodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function backorders_formatear_consecutivo($periodo, $numero) {
    return 'BK ' . $periodo . '-' . str_pad((string)(int)$numero, 2, '0', STR_PAD_LEFT);
}

/** [id_pedido => consecutivo formateado] de los pedidos dados que ya tienen uno. */
function backorders_obtener_consecutivos($bdd, array $idsPedido) {
    backorders_asegurar_tabla_consecutivos($bdd);
    $idsPedido = array_values(array_unique(array_map('intval', $idsPedido)));
    if (!$idsPedido) return [];
    $ph = implode(',', array_fill(0, count($idsPedido), '?'));
    $stmt = $bdd->prepare("SELECT id_pedido, consecutivo FROM backorder_consecutivos WHERE id_pedido IN ($ph)");
    $stmt->execute($idsPedido);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Ids de los períodos que llevan consecutivo: desde 2026 en adelante (por id de período). */
function backorders_ids_periodos_consecutivos($bdd) {
    $stmt = $bdd->prepare("SELECT id FROM periodos WHERE id >= (SELECT MIN(id) FROM periodos WHERE periodo = ?) ORDER BY id");
    $stmt->execute([BACKORDERS_PERIODO_INICIAL_CONSECUTIVOS]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Asigna consecutivo a los pedidos de $lineas (calcular_lineas_backorder()) que ya califican y aún
 * no lo tienen. Orden dentro de cada período: fecha del primer documento confirmado, luego id del
 * pedido. Devuelve [id_pedido => consecutivo] de los asignados en esta llamada.
 */
function backorders_asignar_consecutivos($bdd, array $lineas) {
    // Dentro de la transacción de otro proceso (p. ej. php/backorders_generar_pedido.php) no se
    // asigna: un error aquí revertiría el trabajo del llamador. Se asigna en la próxima consulta.
    if ($bdd->inTransaction()) return [];
    backorders_asegurar_tabla_consecutivos($bdd);
    $periodosValidos = array_flip(backorders_ids_periodos_consecutivos($bdd));

    // Candidatos: pedido con pendiente > 0 y al menos un documento confirmado.
    $candidatos = []; // id_pedido => ['id_periodo', 'pendiente', 'doc' => op]
    foreach ($lineas as $l) {
        if (!isset($periodosValidos[(int)($l['id_periodo'] ?? 0)])) continue;
        $idP = (int)$l['id_pedido'];
        if (!isset($candidatos[$idP])) $candidatos[$idP] = ['id_periodo' => (int)$l['id_periodo'], 'pendiente' => 0.0, 'doc' => null];
        $candidatos[$idP]['pendiente'] += (float)$l['pendiente'];
        foreach ($l['ops'] ?? [] as $op) {
            if (empty($op['confirmado'])) continue;
            // Preferir un documento del propio pedido sobre uno de sus pedidos nuevos.
            $propio = (int)($op['de_pedido'] ?? $idP) === $idP;
            $actual = $candidatos[$idP]['doc'];
            if (!$actual || ($propio && (int)($actual['de_pedido'] ?? $idP) !== $idP)) $candidatos[$idP]['doc'] = $op;
        }
    }
    $candidatos = array_filter($candidatos, fn($c) => $c['doc'] && $c['pendiente'] > 0);
    if (!$candidatos) return [];
    $yaTienen = backorders_obtener_consecutivos($bdd, array_keys($candidatos));
    $candidatos = array_diff_key($candidatos, $yaTienen);
    if (!$candidatos) return [];

    // Datos del documento (id en World Office y fecha) para registrar el origen y ordenar.
    $docs = [];
    foreach ($bdd->query("SELECT tipo_documento, numero, id_wo, fecha FROM backorder_wo_docs WHERE anulado = 0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $docs[$r['tipo_documento'] . '|' . $r['numero']] = $r;
    }
    foreach ($candidatos as $idP => $c) {
        $d = $docs[$c['doc']['tipo_wo'] . '|' . $c['doc']['n_doc']] ?? null;
        $candidatos[$idP]['id_wo'] = $d ? (int)$d['id_wo'] : null;
        $candidatos[$idP]['fecha_doc'] = $d['fecha'] ?? '9999-12-31';
    }
    uksort($candidatos, function ($a, $b) use ($candidatos) {
        return [$candidatos[$a]['fecha_doc'], $a] <=> [$candidatos[$b]['fecha_doc'], $b];
    });

    $nombresPeriodo = $bdd->query("SELECT id, periodo FROM periodos")->fetchAll(PDO::FETCH_KEY_PAIR);
    $asignados = [];
    if ((int)$bdd->query("SELECT GET_LOCK('backorder_consecutivos', 15)")->fetchColumn() !== 1) return [];
    try {
        $bdd->beginTransaction();
        $stmtExiste = $bdd->prepare("SELECT 1 FROM backorder_consecutivos WHERE id_pedido = ?");
        $stmtMax = $bdd->prepare("SELECT COALESCE(MAX(numero), 0) FROM backorder_consecutivos WHERE id_periodo = ? FOR UPDATE");
        $stmtIns = $bdd->prepare("INSERT INTO backorder_consecutivos
            (id_pedido, id_periodo, periodo, numero, consecutivo, fecha_asignacion, tipo_documento, numero_documento, id_wo)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
        $siguiente = []; // id_periodo => próximo número
        foreach ($candidatos as $idP => $c) {
            $stmtExiste->execute([$idP]);
            if ($stmtExiste->fetchColumn()) continue; // lo asignó otro proceso mientras tanto
            $idPer = $c['id_periodo'];
            if (!isset($siguiente[$idPer])) {
                $stmtMax->execute([$idPer]);
                $siguiente[$idPer] = (int)$stmtMax->fetchColumn() + 1;
            }
            $periodo = $nombresPeriodo[$idPer] ?? (string)$idPer;
            $consecutivo = backorders_formatear_consecutivo($periodo, $siguiente[$idPer]);
            if (!$stmtIns->execute([$idP, $idPer, $periodo, $siguiente[$idPer], $consecutivo,
                $c['doc']['tipo_wo'], $c['doc']['n_doc'], $c['id_wo']])) {
                continue; // no se insertó: no se consume el número
            }
            $asignados[$idP] = $consecutivo;
            $siguiente[$idPer]++;
        }
        $bdd->commit();
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        $asignados = [];
    } finally {
        $bdd->query("SELECT RELEASE_LOCK('backorder_consecutivos')");
    }
    return $asignados;
}

/**
 * Revisa TODOS los períodos con consecutivo y asigna los que falten. Se llama al terminar
 * "Actualizar desde World Office" y al asignar un documento a mano (que es cuando un pedido puede
 * pasar a tener documento confirmado).
 */
function backorders_asignar_consecutivos_todos($bdd) {
    $asignados = [];
    foreach (backorders_ids_periodos_consecutivos($bdd) as $idPeriodo) {
        $calc = calcular_lineas_backorder($bdd, $idPeriodo);
        $asignados += backorders_asignar_consecutivos($bdd, $calc['lineas']);
    }
    return $asignados;
}
