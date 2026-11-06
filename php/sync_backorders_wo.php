<?php
/**
 * /php/sync_backorders_wo.php
 * Sincroniza, hacia las tablas locales `backorder_wo_docs` y
 * `backorder_wo_renglones`, SOLO los documentos de World Office (remisión o
 * factura de venta) que hacen falta para calcular el módulo de Backorders:
 * los que ya están escritos como "Núm. de documento" (`ordenes_pedidos.n_doc`)
 * en una orden de pedido Atendida (estado=2).
 *
 * A diferencia de php/sync_colocacion_wo.php (que trae y cruza TODO el
 * catálogo de REM/FV por colegio), acá no hace falta cruzar por colegio ni
 * traer todo el catálogo: el colegio/pedido ya se conocen de antemano por la
 * propia orden de pedido del CRM. Solo se guardan los documentos cuyo
 * "numero" coincide con un n_doc pendiente por resolver, y de esos sí se
 * guardan los renglones (libro + cantidad) — dato que sync_colocacion_wo.php
 * descarta (solo usa los renglones para sumar un valor neto agregado).
 *
 * Se pagina igual que sync_colocacion_wo.php (llamado en lotes desde
 * reporte_backorders.php) porque World Office no permite filtrar la lista
 * por "numero" (solo se probó y confirmó que funciona filtrar por
 * documentoTipo/moneda/prefijo — ver memory/project_wo_api.md), así que hay
 * que recorrer las páginas y comparar "numero" en el cliente.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

set_time_limit(120);
// Libera el lock de sesión apenas se confirma el acceso: esta sincronización hace varias llamadas a
// World Office por página y se llama repetidas veces seguidas desde el navegador — sin esto, cada
// llamada bloqueaba cualquier otra pestaña del mismo usuario mientras estuviera en curso.
session_write_close();

require_once("../includes/prueba_post2.php");
require_once("../includes/prueba_get2.php");
require_once("../includes/backorders_wo_anulados.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

try {
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo_documento VARCHAR(10) NOT NULL,
        numero VARCHAR(30) NOT NULL,
        id_wo INT NOT NULL,
        prefijo VARCHAR(20) NOT NULL,
        fecha DATE NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_tipo_numero (tipo_documento, numero)
    )");
    $bdd->exec("CREATE TABLE IF NOT EXISTS backorder_wo_renglones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_wo INT NOT NULL,
        tipo_documento VARCHAR(10) NOT NULL,
        id_libro INT NULL,
        isbn VARCHAR(100) NULL,
        descripcion VARCHAR(255) NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        KEY idx_doc (id_wo, tipo_documento),
        KEY idx_libro (id_libro)
    )");
} catch (Exception $e) {}
backorders_asegurar_columna_anulado($bdd);

// World Office divide las REM en prefijos distintos con numeración INDEPENDIENTE entre sí — al
// menos "RE22" y "CEUR" (ver memory/project_wo_api.md); un mismo "numero" puede repetirse en más
// de un prefijo (ej. remisión 5656: una de muestras y otra de venta, caso real reportado por el
// usuario 2026-09-11). Antes de agregar el filtro de prefijo más abajo, cualquier REM que haya
// quedado cacheada bajo un prefijo distinto a CEUR es del documento EQUIVOCADO (mismo "numero" que
// el correcto, pero otra remisión) — se borra junto con sus renglones para que el próximo sync la
// resuelva de nuevo, esta vez ya scoped a CEUR.
try {
    $idsMalCacheados = $bdd->query("SELECT id_wo FROM backorder_wo_docs WHERE tipo_documento = 'REM' AND prefijo != 'CEUR'")
                            ->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($idsMalCacheados)) {
        $phMal = implode(',', array_fill(0, count($idsMalCacheados), '?'));
        $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE tipo_documento = 'REM' AND id_wo IN ($phMal)")->execute($idsMalCacheados);
        $bdd->prepare("DELETE FROM backorder_wo_docs WHERE tipo_documento = 'REM' AND id_wo IN ($phMal)")->execute($idsMalCacheados);
    }
} catch (Exception $e) {}

/**
 * Resuelve qué IDs de `tipo_doc` (CRM) corresponden a "remisión de venta" y
 * cuáles a "factura de venta" por el texto de `descrip`, en vez de IDs fijos
 * — los IDs de esa tabla son datos sembrados a mano y podrían no coincidir
 * entre el ambiente de desarrollo y producción.
 */
function resolver_tipo_doc_ids_backorders($bdd) {
    $rows = $bdd->query("SELECT id, descrip FROM tipo_doc")->fetchAll(PDO::FETCH_ASSOC);
    $rem = []; $fv = [];
    foreach ($rows as $r) {
        $d = mb_strtoupper($r['descrip'], 'UTF-8');
        if (strpos($d, 'REMISION') !== false && strpos($d, 'VENTA') !== false
            && strpos($d, 'CORTESIA') === false && strpos($d, 'MUESTRA') === false) {
            $rem[] = (int)$r['id'];
        }
        if (strpos($d, 'FACTURA') !== false && strpos($d, 'VENTA') !== false
            && strpos($d, 'CORTE') === false) {
            $fv[] = (int)$r['id'];
        }
    }
    return ['REM' => $rem, 'FV' => $fv];
}

$tipoDocumento = ($_GET['tipoDocumento'] ?? '') === 'FV' ? 'FV' : 'REM';
$pagina        = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina     = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 20;
// Identificador del recorrido completo (lo genera reporte_backorders.php al pulsar el botón): sirve
// para saber, al final, qué documentos guardados no aparecieron en el listado (posibles eliminados).
$corrida       = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($_GET['corrida'] ?? ''));

$idsTipoDoc = resolver_tipo_doc_ids_backorders($bdd);
$idsRelevantes = $idsTipoDoc[$tipoDocumento];

$pendientes = []; // numero => true
if (!empty($idsRelevantes)) {
    $ph = implode(',', array_fill(0, count($idsRelevantes), '?'));
    $stmt = $bdd->prepare("SELECT DISTINCT n_doc FROM ordenes_pedidos
        WHERE estado = 2 AND n_doc IS NOT NULL AND n_doc != '' AND tipo_doc IN ($ph)");
    $stmt->execute($idsRelevantes);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) $pendientes[trim($n)] = true;
}

// Ya resueltos en un sync anterior: no hace falta volver a traer sus renglones. Pero los válidos
// (anulado=0) se siguen revisando en cada corrida por si los anularon en World Office después.
$cacheados = []; // id_wo => true (documentos válidos ya guardados)
$stmtYa = $bdd->prepare("SELECT numero, id_wo, anulado FROM backorder_wo_docs WHERE tipo_documento = ?");
$stmtYa->execute([$tipoDocumento]);
foreach ($stmtYa->fetchAll(PDO::FETCH_ASSOC) as $r) {
    unset($pendientes[trim($r['numero'])]);
    if (!(int)$r['anulado']) $cacheados[(int)$r['id_wo']] = true;
}

$filtros = [
    crear_filtro_api('documentoTipo.codigoDocumento', $tipoDocumento, 0, 0),
    crear_filtro_api('moneda.id', '31', 4, 0),
];
if ($tipoDocumento === 'REM') {
    // CEUR es el prefijo real de venta dentro de REM (mismo criterio ya usado y validado en
    // php/sync_colocacion_wo.php, ahí llamado "REM-CEUR") — sin este filtro, la API puede devolver
    // primero la remisión de OTRO prefijo (ej. de muestras) con el mismo "numero", y el sync la
    // guardaría por error en vez de la de venta.
    $filtros[] = crear_filtro_api('prefijo.nombre', 'CEUR', 0, 0);
}

$inicio = microtime(true);
$totalPaginas = 0;
$totalDocumentos = 0;
$revisadosEnPagina = 0;
$encontrados = [];
$anulados = [];
$errores = [];

// Se consulta World Office aunque no quede ningún número pendiente: hay que recorrer la lista
// completa para detectar documentos ya guardados que se hayan anulado después.
if (empty($pendientes) && empty($cacheados)) {
    $totalPaginas = 0;
} else {
    $respuestaLista = listar_documentos_salida_almacen($filtros, $pagina, $porPagina);
    if (($respuestaLista['status'] ?? 'error') !== 'OK') {
        echo json_encode(['success' => false, 'message' => 'Error consultando World Office: ' . ($respuestaLista['userMessage'] ?? 'desconocido')]);
        exit;
    }
    $pag = $respuestaLista['data'] ?? [];
    $documentos = $pag['content'] ?? [];
    $totalPaginas = (int)($pag['totalPages'] ?? 0);
    $totalDocumentos = (int)($pag['totalElements'] ?? 0);
    $revisadosEnPagina = count($documentos);

    $stmtDoc = $bdd->prepare("
        INSERT INTO backorder_wo_docs (tipo_documento, numero, id_wo, prefijo, fecha, anulado, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
        ON DUPLICATE KEY UPDATE id_wo = VALUES(id_wo), prefijo = VALUES(prefijo), fecha = VALUES(fecha), anulado = 0, eliminado = 0, updated_at = NOW()
    ");
    $stmtDelRenglones = $bdd->prepare("DELETE FROM backorder_wo_renglones WHERE id_wo = ? AND tipo_documento = ?");
    $stmtRenglon = $bdd->prepare("
        INSERT INTO backorder_wo_renglones (id_wo, tipo_documento, id_libro, isbn, descripcion, cantidad, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    // Documentos guardados (válidos o anulados) que sí aparecen en esta página del listado: siguen
    // existiendo en World Office.
    if ($corrida !== '') {
        $vistos = [];
        foreach ($documentos as $doc) if (isset($doc['id'])) $vistos[] = (int)$doc['id'];
        if ($vistos) {
            $phV = implode(',', array_fill(0, count($vistos), '?'));
            $bdd->prepare("UPDATE backorder_wo_docs SET ultima_corrida = ? WHERE tipo_documento = ? AND id_wo IN ($phV)")
                ->execute(array_merge([$corrida, $tipoDocumento], $vistos));
        }
    }

    foreach ($documentos as $doc) {
        $idWo = $doc['id'] ?? null;
        if (!$idWo) continue;
        // Nada pendiente ni guardado que revisar con este documento: no gastar más llamadas.
        if (empty($pendientes) && !isset($cacheados[(int)$idWo])) continue;

        $numero = trim((string)($doc['numero'] ?? ''));
        $prefijo = $doc['prefijo']['nombre'] ?? ($doc['prefijo'] ?? '');
        $fecha = $doc['fecha'] ?? null;

        // Anulado en World Office: no despachó nada. Si ya estaba guardado (o era uno de los que
        // se buscaban), queda marcado como anulado y sin renglones.
        if (!empty($doc['senAnulado'])) {
            if (isset($cacheados[(int)$idWo]) || ($numero !== '' && isset($pendientes[$numero]))) {
                backorders_marcar_doc_anulado($bdd, $tipoDocumento, $numero, $idWo, $prefijo, $fecha);
                $anulados[] = ['numero' => $numero, 'id_wo' => (int)$idWo];
                unset($cacheados[(int)$idWo]);
                if ($numero !== '') unset($pendientes[$numero]);
            }
            continue;
        }
        if (isset($cacheados[(int)$idWo])) continue; // ya guardado y sigue válido

        if ($numero === '') {
            // La fila del listado no trae "numero": se pide el detalle solo en ese caso.
            $detalle = obtener_documento_salida_por_id($idWo);
            if (($detalle['status'] ?? 'error') !== 'OK') { $errores[] = ['id_wo' => $idWo, 'motivo' => 'detalle']; continue; }
            $d = $detalle['data'] ?? [];
            $numero = trim((string)($d['numero'] ?? ''));
            $prefijo = $d['prefijo']['nombre'] ?? '';
            $fecha = $d['fecha'] ?? null;
        }
        if ($numero === '' || !isset($pendientes[$numero])) continue;

        $renglones = obtener_renglones_documento($idWo, 0, 100);
        $filas = $renglones['data']['content'] ?? [];

        $stmtDoc->execute([$tipoDocumento, $numero, $idWo, is_array($prefijo) ? '' : $prefijo, $fecha]);
        $stmtDelRenglones->execute([$idWo, $tipoDocumento]);
        foreach ($filas as $r) {
            $inv = $r['inventario'] ?? [];
            // OJO: $inv['id'] es el id de INVENTARIO de World Office, no `libros.id` del CRM — son
            // espacios de ids distintos (confirmado con un caso real 2026-09-11, ver
            // includes/backorders_datos.php). El puente correcto es `libros.id_wo` (mismo id de
            // inventario), que es justo por eso lo que compara obtener_datos_backorders() contra
            // esta columna — no cambiarlo a `libros.id` sin actualizar también ese cruce.
            $stmtRenglon->execute([
                $idWo,
                $tipoDocumento,
                $inv['id'] ?? null,
                $inv['codigo'] ?? null,
                $inv['descripcion'] ?? null,
                (float)($r['cantidad'] ?? 0),
            ]);
        }

        $encontrados[] = ['numero' => $numero, 'id_wo' => (int)$idWo, 'renglones' => count($filas)];
        if ($corrida !== '') {
            $bdd->prepare("UPDATE backorder_wo_docs SET ultima_corrida = ? WHERE tipo_documento = ? AND id_wo = ?")
                ->execute([$corrida, $tipoDocumento, (int)$idWo]);
        }
        unset($pendientes[$numero]);
    }
}

// Última página de un recorrido completo: los guardados que no aparecieron en ninguna página se
// confirman uno por uno contra World Office (ver backorders_detectar_eliminados()). Solo con
// recorrido completo — si se detiene a mitad, no se marca nada.
$eliminados = [];
if ($corrida !== '' && $totalPaginas > 0 && $pagina + 1 >= $totalPaginas) {
    $eliminados = backorders_detectar_eliminados($bdd, $tipoDocumento, $corrida);
}

echo json_encode([
    'success' => true,
    'tipoDocumento' => $tipoDocumento,
    'pagina' => $pagina,
    'totalPaginas' => $totalPaginas,
    'totalDocumentos' => $totalDocumentos,
    'revisadosEnPagina' => $revisadosEnPagina,
    'encontrados' => $encontrados,
    'anulados' => $anulados,
    'eliminados' => $eliminados,
    'pendientesRestantes' => count($pendientes),
    'errores' => $errores,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
