<?php
/**
 * /php/sync_colocacion_wo_rapido.php?tipoDocumento=REM|FV|DREM|NCV|POS|RC
 * "Actualizar desde World Office" de reporte_colocacion.php — reemplaza a php/sync_colocacion_wo.php
 * y php/sync_colocacion_wo_ventas.php, que recorrían el listado de 20 en 20 desde el navegador y
 * reescribían todos los documentos. Mismo enfoque que php/sync_backorders_wo_rapido.php, pedido por
 * la usuaria 2026-11-03.
 *
 * En una sola pasada por tipo:
 *  1. Trae el listado COMPLETO de World Office (páginas de 500). La fila ya trae número, prefijo,
 *     fecha, concepto, tercero, senAnulado y fechaModificacion (confirmado en vivo 2026-11-03 para
 *     los seis tipos): no hace falta el detalle de cada documento. REM y DREM: solo prefijo CEUR.
 *  2. Anulados → se quitan de la caché (no suman ni restan).
 *  3. Vuelve a cruzar cada documento con su colegio, y solo escribe los que cambiaron.
 *  4. Valor neto: DREM/NCV/POS/RC ya lo traen en el listado. REM/FV necesitan los renglones, que se
 *     piden SOLO si el documento es nuevo o cambió su fechaModificacion; 8 a la vez, máximo
 *     MAX_RENGLONES_POR_LLAMADA por llamada — si quedan más, responde "faltan" y el navegador vuelve
 *     a llamar.
 *  5. Guardados que ya no salen en el listado completo → se quitan (REM/FV: solo si World Office
 *     responde NOT_FOUND al consultarlos por id, porque ahí sí hay cómo confirmarlo).
 *
 * Cruce y período: ver includes/colocacion_cruce.php. Calendario A va a 2026 o 2027 según la fecha
 * del documento. Calendario B y los sin cruzar conservan el período B que ya tenían (los nuevos
 * entran al último): antes cada sincronización reescribía el período de todos, así que crear un
 * período B nuevo habría movido a él todo el histórico. Los asignados a mano (asignado_manual = 1)
 * conservan colegio y período, y nunca se borran solos.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

set_time_limit(180);
// Libera el lock de sesión: esta petición hace varias llamadas a World Office y no debe bloquear
// otras pestañas del mismo usuario mientras corre.
session_write_close();

require_once("../includes/prueba_post2.php");
require_once("../includes/prueba_get2.php");
require_once("../includes/api_wo_cliente.php");
require_once("../includes/api_wo_ventas.php");
require_once("../includes/colocacion_cruce.php");
require_once("../includes/backorders_wo_anulados.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

const MAX_RENGLONES_POR_LLAMADA = 120;
const REGISTROS_POR_PAGINA_WO = 500;

/** Crea wo_documentos_colocacion o la lleva a la estructura actual. */
function colocacion_asegurar_tabla($bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS wo_documentos_colocacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        fecha DATE NOT NULL,
        concepto TEXT NULL,
        tercero_externo_id INT NULL,
        tercero_externo_nombre VARCHAR(255) NULL,
        id_colegio INT NULL,
        asignado_manual TINYINT(1) NOT NULL DEFAULT 0,
        colegio_extraido VARCHAR(255) NULL,
        valor_neto DECIMAL(14,2) NOT NULL DEFAULT 0,
        id_periodo INT NOT NULL,
        fecha_modificacion VARCHAR(25) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento),
        KEY idx_colegio_periodo (id_colegio, id_periodo)
    )");
    $columnas = $bdd->query("SHOW COLUMNS FROM wo_documentos_colocacion")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('asignado_manual', $columnas, true)) {
        $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD COLUMN asignado_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER id_colegio");
    }
    if (!in_array('fecha_modificacion', $columnas, true)) {
        $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD COLUMN fecha_modificacion VARCHAR(25) NULL AFTER id_periodo");
    }
    // Los ids de World Office son por módulo, no globales: la clave única debe ser compuesta.
    if ($bdd->query("SHOW INDEX FROM wo_documentos_colocacion WHERE Key_name = 'uniq_id_wo'")->fetch()) {
        $bdd->exec("ALTER TABLE wo_documentos_colocacion DROP INDEX uniq_id_wo");
        $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD UNIQUE KEY uniq_id_wo_tipo (id_wo, tipo_documento)");
    }
}

/** Listado completo de REM/FV: id_wo => fila. null si alguna página falla o quedó incompleto. */
function colocacion_listar_salida_almacen($tipo) {
    $filtros = [
        crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0, 0),
        crear_filtro_api('moneda.id', '31', 4, 0),
    ];
    if ($tipo === 'REM') $filtros[] = crear_filtro_api('prefijo.nombre', 'CEUR', 0, 0);

    $primera = listar_documentos_salida_almacen($filtros, 0, REGISTROS_POR_PAGINA_WO);
    if (($primera['status'] ?? '') !== 'OK' || !isset($primera['data']['content'])) return null;
    $paginas = [$primera];
    $peticiones = [];
    for ($p = 1; $p < (int)($primera['data']['totalPages'] ?? 1); $p++) {
        $peticiones[$p] = [
            'endpoint' => '/inventarios/listarDocumentoSalidaAlmacen',
            'metodo' => 'POST',
            'datos' => cuerpo_listar_documentos_salida_almacen($filtros, $p, REGISTROS_POR_PAGINA_WO),
        ];
    }
    foreach (hacer_peticiones_api_paralelo($peticiones) as $resp) {
        if (($resp['status'] ?? '') !== 'OK') return null;
        $paginas[] = $resp;
    }
    $lista = [];
    foreach ($paginas as $pag) {
        foreach ($pag['data']['content'] ?? [] as $row) {
            if (!empty($row['id'])) $lista[(int)$row['id']] = $row;
        }
    }
    return count($lista) >= (int)($primera['data']['totalElements'] ?? 0) ? $lista : null;
}

/** Listado completo de DREM/NCV/POS/RC: id_wo => fila. null si alguna página falla o quedó incompleto. */
function colocacion_listar_ventas($tipo) {
    $funciones = [
        'DREM' => 'listar_devoluciones_venta_wo',
        'NCV' => 'listar_notas_credito_venta_wo',
        'POS' => 'listar_facturas_pos_wo',
        'RC' => 'listar_recibos_caja_wo',
    ];
    $lista = [];
    $pagina = 0;
    do {
        $resp = $funciones[$tipo]($pagina, REGISTROS_POR_PAGINA_WO);
        if (($resp['status'] ?? '') !== 'OK' || !isset($resp['data']['content'])) return null;
        foreach ($resp['data']['content'] as $row) {
            if (!empty($row['id'])) $lista[(int)$row['id']] = $row;
        }
        $total = (int)($resp['data']['totalElements'] ?? 0);
        $pagina++;
    } while (count($lista) < $total && $pagina < 100);
    if (count($lista) < $total) return null;
    // Devoluciones: solo las de venta, prefijo CEUR, igual que REM (RE22 son de muestras — pedido por
    // la usuaria 2026-11-03). Se filtra aquí porque este listado es compartido con Backorders.
    if ($tipo === 'DREM') {
        $lista = array_filter($lista, fn($row) => trim((string)($row['prefijo'] ?? '')) === 'CEUR');
    }
    return $lista;
}

$inicio = microtime(true);
$tiposRenglones = ['REM', 'FV'];
$tiposValidos = array_merge($tiposRenglones, ['DREM', 'NCV', 'POS', 'RC']);
$tipoDocumento = in_array($_GET['tipoDocumento'] ?? '', $tiposValidos, true) ? $_GET['tipoDocumento'] : 'REM';
$usaRenglones = in_array($tipoDocumento, $tiposRenglones, true);

try {
    colocacion_asegurar_tabla($bdd);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'No se pudo preparar la tabla de colocación.']);
    exit;
}

// ── 1) Listado completo de World Office ──────────────────────────────────────────────────────
$lista = $usaRenglones ? colocacion_listar_salida_almacen($tipoDocumento) : colocacion_listar_ventas($tipoDocumento);
if ($lista === null) {
    // Sin el listado completo no se puede saber qué falta (eliminados): mejor no tocar nada.
    echo json_encode(['success' => false, 'message' => 'No se pudo traer el listado completo de ' . $tipoDocumento . ' de World Office.']);
    exit;
}

// ── Lo que ya hay guardado ────────────────────────────────────────────────────────────────────
$guardados = []; // id_wo => fila de wo_documentos_colocacion
$stmt = $bdd->prepare("SELECT id_wo, prefijo, numero, fecha, concepto, tercero_externo_nombre, id_colegio, asignado_manual,
        colegio_extraido, valor_neto, id_periodo, fecha_modificacion
    FROM wo_documentos_colocacion WHERE tipo_documento = ?");
$stmt->execute([$tipoDocumento]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $guardados[(int)$r['id_wo']] = $r;

$ctxCruce = colocacion_cargar_contexto_cruce($bdd);

$stmtUpsert = $bdd->prepare("
    INSERT INTO wo_documentos_colocacion
        (id_wo, tipo_documento, prefijo, numero, fecha, concepto, tercero_externo_id, tercero_externo_nombre, id_colegio, colegio_extraido, valor_neto, id_periodo, fecha_modificacion, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        prefijo = VALUES(prefijo), numero = VALUES(numero), fecha = VALUES(fecha), concepto = VALUES(concepto),
        tercero_externo_id = VALUES(tercero_externo_id), tercero_externo_nombre = VALUES(tercero_externo_nombre),
        id_colegio = IF(asignado_manual = 1, id_colegio, VALUES(id_colegio)),
        colegio_extraido = VALUES(colegio_extraido), valor_neto = VALUES(valor_neto),
        id_periodo = IF(asignado_manual = 1, id_periodo, VALUES(id_periodo)),
        fecha_modificacion = VALUES(fecha_modificacion), updated_at = NOW()
");
$stmtBorrar = $bdd->prepare("DELETE FROM wo_documentos_colocacion WHERE id_wo = ? AND tipo_documento = ? AND asignado_manual = 0");

$resumen = ['documentos' => count($lista), 'nuevos' => 0, 'modificados' => 0, 'anulados' => 0, 'eliminados' => 0,
            'emparejados' => 0, 'sinCruzar' => 0, 'errores' => 0, 'faltan' => 0];

$guardarDocumento = function ($doc, $valorNeto) use ($stmtUpsert, $tipoDocumento, &$resumen) {
    $stmtUpsert->execute([$doc['id_wo'], $tipoDocumento, $doc['prefijo'], $doc['numero'], $doc['fecha'], $doc['concepto'],
        $doc['tercero_id'], $doc['tercero'], $doc['id_colegio'], $doc['colegio_extraido'], $valorNeto, $doc['id_periodo'], $doc['fecha_modificacion']]);
    $doc['es_nuevo'] ? $resumen['nuevos']++ : $resumen['modificados']++;
};

// ── 2) y 3) Anulados + cruce + qué documentos cambiaron ──────────────────────────────────────
$aTraer = []; // id_wo => documento que necesita renglones
$bdd->beginTransaction();
try {
    foreach ($lista as $idWo => $row) {
        $g = $guardados[$idWo] ?? null;
        $manual = $g && (int)$g['asignado_manual'] === 1;

        if (!empty($row['senAnulado'])) {
            if ($g && !$manual) {
                $stmtBorrar->execute([$idWo, $tipoDocumento]);
                $resumen['anulados']++;
            }
            continue;
        }

        $concepto = (string)($row['concepto'] ?? '');
        $fecha = backorders_fecha_wo($row['fecha'] ?? '') ?? ($g['fecha'] ?? date('Y-m-d'));
        $tercero = is_array($row['terceroExterno'] ?? null) ? null : ($row['terceroExterno'] ?? null);
        $colegioExtraido = extraer_colegio_de_concepto($concepto);
        if ($manual) {
            $idColegio = $g['id_colegio'] !== null ? (int)$g['id_colegio'] : null;
            $idPeriodo = (int)$g['id_periodo'];
        } else {
            $idColegio = colocacion_cruzar_documento($ctxCruce, $concepto, $colegioExtraido, $tercero);
            $idPeriodo = colocacion_periodo_documento($ctxCruce, $idColegio, $fecha, $g['id_periodo'] ?? 0);
        }
        $idColegio ? $resumen['emparejados']++ : $resumen['sinCruzar']++;

        $prefijo = $row['prefijo']['nombre'] ?? ($row['prefijo'] ?? '');
        $doc = [
            'id_wo' => $idWo,
            'prefijo' => is_array($prefijo) ? '' : (string)$prefijo,
            'numero' => trim((string)($row['numero'] ?? '')),
            'fecha' => $fecha,
            'concepto' => $concepto,
            'tercero_id' => $row['idTerceroExterno'] ?? null,
            'tercero' => $tercero,
            'id_colegio' => $idColegio,
            'colegio_extraido' => $colegioExtraido,
            'id_periodo' => $idPeriodo,
            'fecha_modificacion' => (string)($row['fechaModificacion'] ?? ''),
            'es_nuevo' => !$g,
        ];

        $editado = !$g || (string)$g['fecha_modificacion'] !== $doc['fecha_modificacion'];
        if ($usaRenglones && $editado) {
            $aTraer[$idWo] = $doc;
            continue;
        }

        if ($usaRenglones) {
            $valorNeto = (float)$g['valor_neto'];
        } else {
            // RC (partida doble) no trae 'valorTotal' — trae valorCredito/valorDebito, iguales entre sí.
            $valorNeto = $tipoDocumento === 'RC'
                ? (float)($row['valorCredito'] ?? $row['valorDebito'] ?? 0)
                : (float)($row['valorTotal'] ?? 0);
        }

        $cambio = $editado
            || (string)$g['prefijo'] !== $doc['prefijo']
            || (string)$g['numero'] !== $doc['numero']
            || (string)$g['fecha'] !== $doc['fecha']
            || (string)$g['concepto'] !== $doc['concepto']
            || (string)$g['tercero_externo_nombre'] !== (string)$doc['tercero']
            || (int)$g['id_colegio'] !== (int)$doc['id_colegio']
            || (int)$g['id_periodo'] !== (int)$doc['id_periodo']
            || (string)$g['colegio_extraido'] !== (string)$doc['colegio_extraido']
            || abs((float)$g['valor_neto'] - $valorNeto) >= 0.01;
        if ($cambio) $guardarDocumento($doc, $valorNeto);
    }
    $bdd->commit();
} catch (Exception $e) {
    $bdd->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error guardando los documentos de ' . $tipoDocumento . '.']);
    exit;
}

// ── 4) Renglones de los REM/FV nuevos o modificados, 8 a la vez ──────────────────────────────
$resumen['faltan'] = max(0, count($aTraer) - MAX_RENGLONES_POR_LLAMADA);
$aTraer = array_slice($aTraer, 0, MAX_RENGLONES_POR_LLAMADA, true);
if ($aTraer) {
    $peticiones = [];
    foreach (array_keys($aTraer) as $idWo) {
        $peticiones[$idWo] = [
            'endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $idWo,
            'metodo' => 'POST',
            'datos' => cuerpo_renglones_documento(0, 300),
        ];
    }
    foreach (hacer_peticiones_api_paralelo($peticiones, 8) as $idWo => $resp) {
        if (($resp['status'] ?? '') !== 'OK') { $resumen['errores']++; continue; } // se reintenta la próxima vez
        $valorNeto = 0.0;
        foreach ($resp['data']['content'] ?? [] as $r) {
            $bruto = (float)($r['cantidad'] ?? 0) * (float)($r['valorUnitario'] ?? 0);
            $valorNeto += $bruto - ($bruto * (float)($r['porcentajeDescuento'] ?? 0));
        }
        try {
            $guardarDocumento($aTraer[$idWo], $valorNeto);
        } catch (Exception $e) {
            $resumen['errores']++;
        }
    }
}

// ── 5) Guardados que ya no salen en el listado: ¿eliminados de World Office? ─────────────────
$noVistos = [];
foreach ($guardados as $idWo => $g) {
    if (!isset($lista[$idWo]) && (int)$g['asignado_manual'] === 0) $noVistos[] = $idWo;
}
if ($noVistos && $usaRenglones) {
    $peticiones = [];
    foreach (array_slice($noVistos, 0, 100) as $idWo) {
        $peticiones[$idWo] = ['endpoint' => '/inventarios/getDocumentoSalidaAlmacenId/' . $idWo, 'metodo' => 'GET'];
    }
    $noVistos = [];
    foreach (hacer_peticiones_api_paralelo($peticiones, 8) as $idWo => $resp) {
        // Solo un NOT_FOUND explícito confirma que se eliminó; cualquier otra respuesta no toca nada.
        if (($resp['status'] ?? '') === 'NOT_FOUND') $noVistos[] = $idWo;
    }
}
// Un listado vacío con documentos guardados es sospechoso: no se borra nada con esa base.
if ($lista) {
    foreach ($noVistos as $idWo) {
        $stmtBorrar->execute([$idWo, $tipoDocumento]);
        $resumen['eliminados'] += $stmtBorrar->rowCount();
    }
}

echo json_encode([
    'success' => true,
    'tipoDocumento' => $tipoDocumento,
    'resumen' => $resumen,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
