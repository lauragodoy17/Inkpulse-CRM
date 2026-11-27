<?php
/**
 * Informe de existencias de World Office (reporte_existencias_wo.php) — pedido por la usuaria
 * 2026-11-27: todos los títulos de World Office con lo que hay en la bodega General (id 1) y en
 * Muestras General (id 4).
 *
 * World Office no da las existencias en el listado de inventario ni tiene un endpoint de existencias
 * de muchos productos (probados ~12, todos 404): hay que pedirlas producto por producto
 * (GET /inventarios/{id}/existencias/bodega), y World Office no atiende más de ~185 por segundo — con
 * ~4.850 títulos, ~26 s es el piso. Tampoco sirve actualizar solo lo que cambió: el listado de
 * documentos no deja ver compras, entradas de producto terminado ni devoluciones de remisión.
 *
 * Por eso la actualización corre EN SEGUNDO PLANO en una sola petición (ewo_actualizar(): listado
 * completo en paralelo + existencias con 100 en vuelo) y deja todo guardado en existencias_wo_cache;
 * mientras tanto el informe sigue mostrando la consulta anterior y consulta el avance en
 * existencias_wo_estado. Al abrir el informe, si los datos tienen más de EWO_MINUTOS_VIGENCIA
 * minutos, se actualiza solo.
 */
require_once __DIR__ . '/api_wo_inventario.php';

const EWO_POR_PAGINA_WO = 1000;     // títulos por página del listado de World Office
const EWO_FILAS_POR_REGISTRO = 1000; // filas por registro de la caché (max_allowed_packet es 1 MB)
const EWO_EN_VUELO = 100;           // peticiones de existencias simultáneas (más no acelera)
const EWO_MINUTOS_VIGENCIA = 30;
const EWO_BODEGA_GENERAL = 1;
const EWO_BODEGA_MUESTRAS = 4;

function ewo_crear_tabla(PDO $bdd) {
    static $listo = false;
    if ($listo) return;
    $listo = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS existencias_wo_cache (
        corrida VARCHAR(32) NOT NULL,
        pagina INT NOT NULL,
        paginas INT NOT NULL,
        filas MEDIUMTEXT NOT NULL,
        id_usuario INT NULL,
        fecha DATETIME NOT NULL,
        PRIMARY KEY (corrida, pagina)
    )");
    // Una sola fila (id = 1): la actualización en curso o la última que terminó.
    $bdd->exec("CREATE TABLE IF NOT EXISTS existencias_wo_estado (
        id TINYINT NOT NULL PRIMARY KEY,
        en_curso TINYINT(1) NOT NULL DEFAULT 0,
        hechos INT NOT NULL DEFAULT 0,
        total INT NOT NULL DEFAULT 0,
        error VARCHAR(255) NULL,
        id_usuario INT NULL,
        inicio DATETIME NULL,
        latido DATETIME NULL
    )");
}

/** La última consulta completa: ['fecha', 'minutos', 'usuario', 'filas'] o null si nunca se ha consultado. */
function ewo_ultima(PDO $bdd) {
    ewo_crear_tabla($bdd);
    $c = $bdd->query("SELECT corrida, MAX(fecha) fecha, MAX(id_usuario) id_usuario, TIMESTAMPDIFF(MINUTE, MAX(fecha), NOW()) minutos
                      FROM existencias_wo_cache GROUP BY corrida HAVING COUNT(*) >= MAX(paginas)
                      ORDER BY fecha DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $stmt = $bdd->prepare("SELECT filas FROM existencias_wo_cache WHERE corrida = ? ORDER BY pagina");
    $stmt->execute([$c['corrida']]);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $j) foreach (json_decode($j, true) ?: [] as $f) $filas[] = $f;
    $u = $bdd->prepare("SELECT CONCAT(nombres, ' ', apellidos) FROM usuarios WHERE id = ?");
    $u->execute([(int)$c['id_usuario']]);
    return ['fecha' => $c['fecha'], 'minutos' => (int)$c['minutos'], 'usuario' => (string)($u->fetchColumn() ?: ''), 'filas' => $filas];
}

/**
 * Estado de la actualización: ['en_curso', 'hechos', 'total', 'error', 'fecha_ultima']. Una
 * actualización sin latido hace más de 3 minutos se da por caída (se cerró el servidor, etc.).
 */
function ewo_estado(PDO $bdd) {
    ewo_crear_tabla($bdd);
    $e = $bdd->query("SELECT en_curso, hechos, total, error, TIMESTAMPDIFF(SECOND, latido, NOW()) seg FROM existencias_wo_estado WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $ultima = $bdd->query("SELECT MAX(fecha) FROM existencias_wo_cache")->fetchColumn();
    $enCurso = $e && (int)$e['en_curso'] && (int)$e['seg'] < 180;
    return [
        'en_curso' => $enCurso,
        'hechos' => $e ? (int)$e['hechos'] : 0,
        'total' => $e ? (int)$e['total'] : 0,
        'error' => $e && !$enCurso ? $e['error'] : null,
        'fecha_ultima' => $ultima ?: null,
    ];
}

/**
 * Solo lo que World Office clasifica como Producto (libros y paquetes) o Consignación o Depósito: el
 * inventario también trae servicios, gastos, diferidos y activos fijos ("Servicio de energía eléctrica",
 * "Honorarios revisoría fiscal"...), que no son títulos (~450 de ~5.300 en 2026-11).
 */
function ewo_es_titulo(array $it) {
    $c = $it['inventarioClasificacion'] ?? [];
    $nombre = is_array($c) ? (string)($c['nombre'] ?? '') : (string)$c;
    return (bool)preg_match('/^(Producto|Consignaci)/i', $nombre);
}

/** ¿Es un paquete? En World Office los paquetes llevan "PAQ" en el código o en el nombre. */
function ewo_es_paquete($codigo, $descripcion) {
    return (bool)preg_match('/PAQ/i', (string)$codigo) || (bool)preg_match('/^\s*(PAQ\b|PAQUETE\b)/i', (string)$descripcion);
}

/**
 * Existencias por bodega de muchos productos con una ventana deslizante: siempre hay $enVuelo
 * peticiones en curso (apenas una termina entra la siguiente), en vez de lotes que esperan al más
 * lento como existencias_por_bodega_bulk(). $alAvanzar($hechos) se llama cada 200 respuestas.
 * Devuelve [idInventario => [idBodega => cantidad]|null]; los que fallan se reintentan una vez.
 */
function ewo_existencias_rapido(array $ids, $enVuelo = EWO_EN_VUELO, callable $alAvanzar = null) {
    $pendientes = array_values(array_unique(array_filter($ids, fn($v) => $v !== null && $v !== '')));
    $res = [];
    $hechos = 0;
    for ($intento = 0; $intento < 2 && $pendientes; $intento++) {
        $mh = curl_multi_init();
        $activos = []; // (int)handle => id
        $cola = $pendientes;
        $fallidos = [];
        $agregar = function () use (&$cola, &$activos, $mh) {
            $id = array_shift($cola);
            $ch = wo_crear_handle('/inventarios/' . rawurlencode($id) . '/existencias/bodega', 'GET');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_multi_add_handle($mh, $ch);
            $activos[(int)$ch] = $id;
        };
        while ($cola && count($activos) < $enVuelo) $agregar();
        do {
            curl_multi_exec($mh, $corriendo);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = $activos[(int)$ch];
                $data = json_decode((string)curl_multi_getcontent($ch), true);
                $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($info['result'] === CURLE_OK && $codigo < 400 && is_array($data) && ($data['status'] ?? '') !== 'error') {
                    $porBodega = [];
                    foreach ($data['data']['content'] ?? [] as $f) $porBodega[(int)($f['id'] ?? 0)] = (float)($f['cantidad'] ?? 0);
                    $res[$id] = $porBodega;
                    if ($alAvanzar && ++$hechos % 200 === 0) $alAvanzar($hechos);
                } else {
                    $fallidos[] = $id;
                    $res[$id] = null;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($activos[(int)$ch]);
                if ($cola) $agregar();
            }
            if ($activos) curl_multi_select($mh, 1.0);
        } while ($activos);
        curl_multi_close($mh);
        $pendientes = $fallidos;
    }
    return $res;
}

/** Todos los títulos del listado de World Office (páginas en paralelo), o ['error' => mensaje]. */
function ewo_listar_todos() {
    $primera = listar_inventarios([], 0, EWO_POR_PAGINA_WO);
    if (($primera['status'] ?? '') !== 'OK') {
        return ['error' => 'Error consultando World Office: ' . ($primera['userMessage'] ?? $primera['mensaje_interno'] ?? 'desconocido')];
    }
    $todos = $primera['data']['content'] ?? [];
    $paginas = (int)($primera['data']['totalPages'] ?? 1);
    if ($paginas > 1) {
        $peticiones = [];
        for ($p = 1; $p < $paginas; $p++) {
            $peticiones[$p] = ['endpoint' => '/inventarios/listarInventarios', 'metodo' => 'POST', 'datos' => [
                "columnaOrdenar" => "id", "pagina" => $p, "registrosPorPagina" => EWO_POR_PAGINA_WO, "orden" => "DESC",
                "canal" => 0, "registroInicial" => $p * EWO_POR_PAGINA_WO, "filtros" => [],
            ]];
        }
        foreach (hacer_peticiones_api_paralelo($peticiones) as $resp) {
            if (($resp['status'] ?? '') !== 'OK') return ['error' => 'Error consultando World Office (página del listado).'];
            foreach ($resp['data']['content'] ?? [] as $it) $todos[] = $it;
        }
    }
    return array_values(array_filter($todos, 'ewo_es_titulo'));
}

/**
 * Actualización completa desde World Office, en una sola petición. Solo una a la vez (bloqueo de
 * MySQL): si ya hay una en curso devuelve ['en_curso' => true] sin hacer nada. Al terminar guarda la
 * consulta y borra las anteriores. Devuelve ['ok' => true, 'titulos', 'segundos'] o ['error' => ...].
 */
function ewo_actualizar(PDO $bdd, $idUsuario) {
    ewo_crear_tabla($bdd);
    if ((int)$bdd->query("SELECT GET_LOCK('existencias_wo_actualizar', 0)")->fetchColumn() !== 1) {
        return ['en_curso' => true];
    }
    $inicio = microtime(true);
    $marcar = $bdd->prepare("REPLACE INTO existencias_wo_estado (id, en_curso, hechos, total, error, id_usuario, inicio, latido)
                             VALUES (1, ?, ?, ?, ?, ?, NOW(), NOW())");
    $latido = $bdd->prepare("UPDATE existencias_wo_estado SET hechos = ?, latido = NOW() WHERE id = 1");
    $marcar->execute([1, 0, 0, null, $idUsuario ?: null]);
    try {
        $items = ewo_listar_todos();
        if (isset($items['error'])) throw new RuntimeException($items['error']);
        $bdd->prepare("UPDATE existencias_wo_estado SET total = ?, latido = NOW() WHERE id = 1")->execute([count($items)]);

        $existencias = ewo_existencias_rapido(array_column($items, 'id'), EWO_EN_VUELO, function ($hechos) use ($latido) {
            $latido->execute([$hechos]);
        });

        $filas = [];
        foreach ($items as $it) {
            $grupo = $it['grupo'][0] ?? ($it['grupo'] ?? []);
            $porBodega = $existencias[$it['id']] ?? null;
            $filas[] = [
                'codigo' => trim((string)($it['codigo'] ?? '')),
                'titulo' => trim((string)($it['descripcion'] ?? '')),
                'editorial' => is_array($grupo) ? trim((string)($grupo['nombreGrupo'] ?? '')) : '',
                'activo' => !empty($it['senActivo']),
                'paquete' => ewo_es_paquete($it['codigo'] ?? '', $it['descripcion'] ?? ''),
                'general' => $porBodega === null ? null : (float)($porBodega[EWO_BODEGA_GENERAL] ?? 0),
                'muestras' => $porBodega === null ? null : (float)($porBodega[EWO_BODEGA_MUESTRAS] ?? 0),
            ];
        }

        $corrida = date('YmdHis') . bin2hex(random_bytes(4));
        $trozos = array_chunk($filas, EWO_FILAS_POR_REGISTRO);
        $guardar = $bdd->prepare("INSERT INTO existencias_wo_cache (corrida, pagina, paginas, filas, id_usuario, fecha) VALUES (?, ?, ?, ?, ?, NOW())");
        $bdd->beginTransaction();
        foreach ($trozos as $i => $trozo) {
            $guardar->execute([$corrida, $i, count($trozos), json_encode($trozo, JSON_UNESCAPED_UNICODE), $idUsuario ?: null]);
        }
        $bdd->prepare("DELETE FROM existencias_wo_cache WHERE corrida <> ?")->execute([$corrida]);
        $bdd->commit();

        $marcar->execute([0, count($filas), count($filas), null, $idUsuario ?: null]);
        return ['ok' => true, 'titulos' => count($filas), 'segundos' => round(microtime(true) - $inicio)];
    } catch (Throwable $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        $marcar->execute([0, 0, 0, mb_substr($e->getMessage(), 0, 250), $idUsuario ?: null]);
        return ['error' => $e->getMessage()];
    } finally {
        $bdd->query("SELECT RELEASE_LOCK('existencias_wo_actualizar')");
    }
}
