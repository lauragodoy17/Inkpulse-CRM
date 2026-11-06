<?php
/**
 * /php/sync_backorders_wo_rapido.php?tipoDocumento=REM|FV|DEV
 * "Actualizar desde World Office" de reporte_backorders.php — reemplaza a los dos botones
 * anteriores (php/sync_backorders_wo.php y php/sync_backorders_wo_catalogo.php, que recorrían el
 * listado de 20 en 20 desde el navegador y pedían el detalle de CADA documento uno por uno) —
 * pedido por la usuaria 2026-10-20 ("que vaya haciendo la validación mucho más rápido y que si
 * existe algún cambio lo vaya actualizando de una vez").
 *
 * En una sola pasada por tipo:
 *  1. Trae el listado COMPLETO de World Office (páginas de 500, en paralelo si hay más de una).
 *     La fila del listado ya trae número, prefijo, fecha, concepto, senAnulado y
 *     fechaModificacion: no hace falta el detalle de cada documento.
 *  2. Anulados → marcados, no cuentan (includes/backorders_wo_anulados.php).
 *  3. Catálogo: verifica cada documento contra un pedido por el concepto ("OP <año>-<id>" +
 *     "PED <n>", includes/matching_pedidos_wo.php) — los no verificados quedan para el panel
 *     "Documentos sin verificar".
 *  4. Renglones (libro + cantidad despachada) SOLO de los documentos que hacen falta y son nuevos o
 *     cambiaron en World Office (fechaModificacion distinta a la guardada): los que una OP tiene
 *     como "Núm. de documento" y los verificados contra un pedido. Se piden 8 a la vez; máximo
 *     MAX_RENGLONES_POR_LLAMADA por llamada — si quedan más, responde "faltan" y el navegador
 *     vuelve a llamar.
 *  5. Guardados que ya no salen en el listado → se consultan por id; solo si World Office responde
 *     NOT_FOUND se marcan eliminados (desaparecen del reporte).
 *
 * tipoDocumento=DEV: devoluciones de remisión (DREM) y notas crédito (NCV), que restan de lo
 * despachado — ver includes/backorders_wo_devoluciones.php. Es la última llamada: al terminar se
 * asignan los consecutivos BK.
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
require_once("../includes/matching_pedidos_wo.php");
require_once("../includes/matching_colegios.php");
require_once("../includes/backorders_datos.php");
require_once("../includes/backorders_wo_anulados.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

const MAX_RENGLONES_POR_LLAMADA = 120;
const REGISTROS_POR_PAGINA_WO = 500;

$inicio = microtime(true);
backorders_asegurar_indices($bdd);

if (($_GET['tipoDocumento'] ?? '') === 'DEV') {
    $resumen = backorders_sync_devoluciones($bdd);
    // Al terminar la última llamada, los backorders que ya tienen una REM/FV confirmada reciben su
    // consecutivo (los ya asignados no cambian).
    $resumen['consecutivos'] = $resumen['faltan'] === 0 ? count(backorders_asignar_consecutivos_todos($bdd)) : 0;
    echo json_encode(['success' => true, 'tipoDocumento' => 'DEV', 'resumen' => $resumen, 'ms' => round((microtime(true) - $inicio) * 1000)]);
    exit;
}
$tipoDocumento = ($_GET['tipoDocumento'] ?? '') === 'FV' ? 'FV' : 'REM';

try {
    backorders_crear_tablas_wo($bdd);
} catch (Exception $e) {}

// Reparación: un documento válido (anulado=0) nunca puede estar marcado eliminado. Pasaba cuando
// en World Office se borraba un documento y se creaba OTRO con el mismo número: el nuevo heredaba
// la fila (única por tipo+número) y la versión anterior del sync solo le quitaba "anulado" — así
// quedaba contando como despacho y sin revisarse nunca más (caso real REM 7703, 2026-10-20).
$bdd->exec("UPDATE backorder_wo_docs SET eliminado = 0 WHERE anulado = 0 AND eliminado = 1");

// REM: solo el prefijo de venta CEUR (un mismo número puede repetirse en otro prefijo, p. ej. de
// muestras — ver php/sync_backorders_wo.php). Cualquier REM guardada con otro prefijo es del
// documento equivocado: se borra para que se resuelva de nuevo.
if ($tipoDocumento === 'REM') {
    $idsMal = $bdd->query("SELECT id_wo FROM backorder_wo_docs WHERE tipo_documento = 'REM' AND prefijo != 'CEUR'")->fetchAll(PDO::FETCH_COLUMN);
    if ($idsMal) {
        $ph = implode(',', array_fill(0, count($idsMal), '?'));
        $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE tipo_documento = 'REM' AND id_wo IN ($ph)")->execute($idsMal);
        $bdd->prepare("DELETE FROM backorder_wo_docs WHERE tipo_documento = 'REM' AND id_wo IN ($ph)")->execute($idsMal);
    }
}

// ── 1) Listado completo de World Office ──────────────────────────────────────────────────────
$filtros = [
    crear_filtro_api('documentoTipo.codigoDocumento', $tipoDocumento, 0, 0),
    crear_filtro_api('moneda.id', '31', 4, 0),
];
if ($tipoDocumento === 'REM') $filtros[] = crear_filtro_api('prefijo.nombre', 'CEUR', 0, 0);

$primera = listar_documentos_salida_almacen($filtros, 0, REGISTROS_POR_PAGINA_WO);
if (($primera['status'] ?? 'error') !== 'OK') {
    echo json_encode(['success' => false, 'message' => 'Error consultando World Office: ' . ($primera['userMessage'] ?? $primera['mensaje_interno'] ?? 'desconocido')]);
    exit;
}
$filasWo = $primera['data']['content'] ?? [];
$totalPaginas = (int)($primera['data']['totalPages'] ?? 1);
if ($totalPaginas > 1) {
    $peticiones = [];
    for ($p = 1; $p < $totalPaginas; $p++) {
        $peticiones[$p] = [
            'endpoint' => '/inventarios/listarDocumentoSalidaAlmacen',
            'metodo' => 'POST',
            'datos' => cuerpo_listar_documentos_salida_almacen($filtros, $p, REGISTROS_POR_PAGINA_WO),
        ];
    }
    foreach (hacer_peticiones_api_paralelo($peticiones) as $resp) {
        if (($resp['status'] ?? '') !== 'OK') {
            // Sin el listado completo no se puede saber qué falta (eliminados): mejor no tocar nada.
            echo json_encode(['success' => false, 'message' => 'Error consultando World Office (página del listado).']);
            exit;
        }
        foreach ($resp['data']['content'] ?? [] as $row) $filasWo[] = $row;
    }
}
$lista = []; // id_wo => fila normalizada
foreach ($filasWo as $row) {
    if (empty($row['id'])) continue;
    $prefijo = $row['prefijo']['nombre'] ?? ($row['prefijo'] ?? '');
    $lista[(int)$row['id']] = [
        'id_wo' => (int)$row['id'],
        'numero' => trim((string)($row['numero'] ?? '')),
        'prefijo' => is_array($prefijo) ? '' : (string)$prefijo,
        'fecha' => backorders_fecha_wo($row['fecha'] ?? ''),
        'concepto' => (string)($row['concepto'] ?? ''),
        'anulado' => !empty($row['senAnulado']),
        'fecha_modificacion' => (string)($row['fechaModificacion'] ?? ''),
        'cliente' => is_array($row['terceroExterno'] ?? null) ? '' : trim((string)($row['terceroExterno'] ?? '')),
    ];
}

// ── Lo que ya hay guardado ────────────────────────────────────────────────────────────────────
$guardados = []; // id_wo => fila de backorder_wo_docs
$stmt = $bdd->prepare("SELECT id_wo, numero, anulado, eliminado, fecha_modificacion FROM backorder_wo_docs WHERE tipo_documento = ?");
$stmt->execute([$tipoDocumento]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $guardados[(int)$r['id_wo']] = $r;
$numerosValidos = []; // numero => id_wo de un documento guardado y válido
foreach ($guardados as $idWo => $g) if (!(int)$g['anulado']) $numerosValidos[$g['numero']] = $idWo;

$catalogo = []; // id_wo => [numero, id_pedido_verificado, asignado_manual, anulado, fecha_modificacion]
$stmt = $bdd->prepare("SELECT id_wo, numero, id_pedido_verificado, asignado_manual, anulado, fecha_modificacion, cliente FROM backorder_wo_catalogo WHERE tipo_documento = ?");
$stmt->execute([$tipoDocumento]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $catalogo[(int)$r['id_wo']] = $r;

// Números escritos como "Núm. de documento" en OPs Atendidas de este tipo.
$numerosOp = [];
$idsTipoDoc = array_keys(array_filter(resolver_tipo_doc_ids_backorders_datos($bdd), fn($t) => $t === $tipoDocumento));
if ($idsTipoDoc) {
    $ph = implode(',', array_fill(0, count($idsTipoDoc), '?'));
    $stmt = $bdd->prepare("SELECT DISTINCT n_doc FROM ordenes_pedidos WHERE estado = 2 AND n_doc IS NOT NULL AND n_doc != '' AND tipo_doc IN ($ph)");
    $stmt->execute($idsTipoDoc);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) $numerosOp[trim($n)] = true;
}

$pedidosPorOp = cargar_pedidos_por_op($bdd);

$stmtCatalogo = $bdd->prepare("
    INSERT INTO backorder_wo_catalogo
        (id_wo, tipo_documento, numero, prefijo, fecha, concepto, colegio_extraido, id_pedido_verificado, anulado, fecha_modificacion, cliente, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        numero = VALUES(numero), prefijo = VALUES(prefijo), fecha = VALUES(fecha), concepto = VALUES(concepto),
        colegio_extraido = VALUES(colegio_extraido),
        id_pedido_verificado = IF(asignado_manual = 1, id_pedido_verificado, VALUES(id_pedido_verificado)),
        anulado = VALUES(anulado), fecha_modificacion = VALUES(fecha_modificacion), cliente = VALUES(cliente),
        updated_at = NOW()
");

$resumen = ['documentos' => count($lista), 'nuevos' => 0, 'modificados' => 0, 'anulados' => 0, 'eliminados' => 0,
            'verificados' => 0, 'sinVerificar' => 0, 'errores' => 0, 'faltan' => 0];
$aTraer = []; // id_wo => 'nuevo'|'modificado'

// El catálogo solo se reescribe si el documento es nuevo, cambió en World Office o cambió su
// verificación contra un pedido — reescribir los ~350 en cada corrida tomaba ~20 s.
$guardarCatalogo = function ($idWo, $doc, $idPedidoVerificado, $anulado) use ($stmtCatalogo, $catalogo, $tipoDocumento) {
    $c = $catalogo[$idWo] ?? null;
    if ($c && (string)$c['fecha_modificacion'] === $doc['fecha_modificacion'] && (int)$c['anulado'] === $anulado
        && (string)$c['cliente'] === $doc['cliente']
        && ((int)$c['asignado_manual'] || (int)$c['id_pedido_verificado'] === (int)$idPedidoVerificado)) {
        return;
    }
    $stmtCatalogo->execute([$idWo, $tipoDocumento, $doc['numero'], $doc['prefijo'], $doc['fecha'], $doc['concepto'],
        extraer_colegio_de_concepto($doc['concepto']), $idPedidoVerificado, $anulado, $doc['fecha_modificacion'], $doc['cliente']]);
};

// ── 2) y 3) Anulados + catálogo + qué documentos necesitan renglones ─────────────────────────
$bdd->beginTransaction();
foreach ($lista as $idWo => $doc) {
    $g = $guardados[$idWo] ?? null;

    if ($doc['anulado']) {
        $guardarCatalogo($idWo, $doc, null, 1);
        // Solo hace falta marcarlo si estaba contando como despacho o lo pide una OP.
        if (($g && !(int)$g['anulado']) || (!$g && isset($numerosOp[$doc['numero']]))) {
            backorders_marcar_doc_anulado($bdd, $tipoDocumento, $doc['numero'], $idWo, $doc['prefijo'], $doc['fecha']);
            $resumen['anulados']++;
        }
        continue;
    }

    // Documentos de antes de la migración a World Office no se verifican contra ningún pedido
    // (ver BACKORDERS_FECHA_MIGRACION_WO).
    $idPedidoVerificado = ($doc['fecha'] && $doc['fecha'] >= BACKORDERS_FECHA_MIGRACION_WO)
        ? verificar_pedido_por_op_concepto($doc['concepto'], $pedidosPorOp)
        : null;
    $guardarCatalogo($idWo, $doc, $idPedidoVerificado, 0);
    $asignadoManual = !empty($catalogo[$idWo]['asignado_manual']) && !empty($catalogo[$idWo]['id_pedido_verificado']);
    $verificado = $idPedidoVerificado || $asignadoManual;
    $verificado ? $resumen['verificados']++ : $resumen['sinVerificar']++;

    // ¿Cuenta para backorders? Si una OP lo tiene como documento, o está verificado contra un pedido.
    $loNecesita = $verificado || isset($numerosOp[$doc['numero']]);
    if (!$loNecesita) continue;

    if (!$g || (int)$g['anulado']) {
        // Ya hay OTRO documento válido guardado con este mismo número: se respeta el guardado.
        if (!$g && isset($numerosValidos[$doc['numero']])) continue;
        $aTraer[$idWo] = 'nuevo';
    } elseif ((string)$g['fecha_modificacion'] !== $doc['fecha_modificacion']) {
        // Se editó en World Office desde la última vez (o se guardó antes de llevar este control).
        $aTraer[$idWo] = 'modificado';
    }
}
$bdd->commit();

// ── 4) Renglones de los nuevos/modificados, 8 a la vez ───────────────────────────────────────
$resumen['faltan'] = max(0, count($aTraer) - MAX_RENGLONES_POR_LLAMADA);
$aTraer = array_slice($aTraer, 0, MAX_RENGLONES_POR_LLAMADA, true);
if ($aTraer) {
    $peticiones = [];
    foreach (array_keys($aTraer) as $idWo) {
        $peticiones[$idWo] = [
            'endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $idWo,
            'metodo' => 'POST',
            'datos' => cuerpo_renglones_documento(0, 200),
        ];
    }
    $stmtDoc = $bdd->prepare("
        INSERT INTO backorder_wo_docs (tipo_documento, numero, id_wo, prefijo, fecha, anulado, eliminado, fecha_modificacion, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, 0, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE id_wo = VALUES(id_wo), prefijo = VALUES(prefijo), fecha = VALUES(fecha),
            anulado = 0, eliminado = 0, fecha_modificacion = VALUES(fecha_modificacion), updated_at = NOW()
    ");
    $stmtDel = $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE id_wo = ? AND tipo_documento = ?");
    $stmtRenglon = $bdd->prepare("
        INSERT INTO backorder_wo_renglones (id_wo, tipo_documento, id_libro, isbn, descripcion, cantidad, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    foreach (hacer_peticiones_api_paralelo($peticiones, 8) as $idWo => $resp) {
        if (($resp['status'] ?? '') !== 'OK') { $resumen['errores']++; continue; } // se reintenta la próxima vez
        $doc = $lista[$idWo];
        $bdd->beginTransaction();
        try {
            $stmtDoc->execute([$tipoDocumento, $doc['numero'], $idWo, $doc['prefijo'], $doc['fecha'], $doc['fecha_modificacion']]);
            $stmtDel->execute([$idWo, $tipoDocumento]);
            foreach ($resp['data']['content'] ?? [] as $r) {
                // OJO: inventario.id es el id de INVENTARIO de World Office (se cruza contra
                // libros.id_wo, no contra libros.id — ver includes/backorders_datos.php).
                $inv = $r['inventario'] ?? [];
                $stmtRenglon->execute([$idWo, $tipoDocumento, $inv['id'] ?? null, $inv['codigo'] ?? null, $inv['descripcion'] ?? null, (float)($r['cantidad'] ?? 0)]);
            }
            $bdd->commit();
            $aTraer[$idWo] === 'nuevo' ? $resumen['nuevos']++ : $resumen['modificados']++;
        } catch (Exception $e) {
            $bdd->rollBack();
            $resumen['errores']++;
        }
    }
}

// ── 5) Guardados que ya no salen en el listado: ¿eliminados de World Office? ─────────────────
// Se revisan tanto los documentos que cuentan para backorders (backorder_wo_docs) como los del
// catálogo (verificados o pendientes de verificar): un documento borrado no debe seguir
// descontando ni quedarse en "Documentos sin verificar".
$noVistos = []; // id_wo => numero
foreach ($guardados as $idWo => $g) {
    if (!(int)$g['eliminado'] && !isset($lista[$idWo])) $noVistos[$idWo] = $g['numero'];
}
foreach ($catalogo as $idWo => $c) {
    if (!(int)$c['anulado'] && !isset($lista[$idWo])) $noVistos[$idWo] = $c['numero'];
}
if ($noVistos) {
    $peticiones = [];
    foreach (array_slice(array_keys($noVistos), 0, 100) as $idWo) {
        $peticiones[$idWo] = ['endpoint' => '/inventarios/getDocumentoSalidaAlmacenId/' . $idWo, 'metodo' => 'GET'];
    }
    foreach (hacer_peticiones_api_paralelo($peticiones, 8) as $idWo => $resp) {
        // Solo un NOT_FOUND explícito confirma que se eliminó; cualquier otra respuesta no toca nada.
        if (($resp['status'] ?? '') === 'NOT_FOUND') {
            backorders_marcar_doc_anulado($bdd, $tipoDocumento, $noVistos[$idWo], $idWo, '', null, true);
            $resumen['eliminados']++;
        }
    }
}

echo json_encode([
    'success' => true,
    'tipoDocumento' => $tipoDocumento,
    'resumen' => $resumen,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
