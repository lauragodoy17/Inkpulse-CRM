<?php
/**
 * /includes/api_wo_inventario.php
 * Listado de inventario (productos/libros) en World Office.
 * Endpoint descubierto por prueba directa para esta cuenta (no está en el
 * catálogo público de developer.worldoffice.cloud): POST /inventarios/listarInventarios.
 * No requiere filtros obligatorios.
 */

require_once("api_wo_cliente.php");

function listar_inventarios($filtros = [], $pagina = 0, $registrosPorPagina = 20) {
    $endpoint = '/inventarios/listarInventarios';
    $cuerpo = [
        "columnaOrdenar" => "id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "canal" => 0,
        // El offset real de paginación lo controla registroInicial, NO "pagina"
        // (confirmado por prueba directa: con registroInicial fijo en 0, WO
        // devolvía siempre la página 0 sin importar el valor de "pagina" —
        // esto causaba filas duplicadas al paginar).
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
        "filtros" => array_values($filtros)
    ];
    return hacer_peticion_api($endpoint, 'POST', $cuerpo);
}

/**
 * Existencias (cantidad real en stock) de un producto, agrupadas por empresa.
 * Endpoint tomado de la documentación propia de esta integración (no del
 * catálogo público de developer.worldoffice.cloud):
 * GET /inventarios/{id}/existencias/empresa
 */
function consultar_existencias_por_empresa($idInventario) {
    return hacer_peticion_api('/inventarios/' . rawurlencode($idInventario) . '/existencias/empresa', 'GET', null);
}

/**
 * Existencias (cantidad real en stock) de un producto, agrupadas por bodega.
 * GET /inventarios/{id}/existencias/bodega
 * Bodegas confirmadas para esta cuenta (2026-08-04): id=1 "General",
 * id=4 "Muestras General". También puede aparecer id=52 "TEM 2026 COMER
 * MUESTRAS" en algunos productos (bodega temporal, no forma parte de la
 * clasificación 1/4 usada en libros.tipo — ver php/clasificar_libros_bodega.php).
 */
function consultar_existencias_por_bodega($idInventario) {
    return hacer_peticion_api('/inventarios/' . rawurlencode($idInventario) . '/existencias/bodega', 'GET', null);
}

/**
 * Existencia real vendible de un producto: solo bodega id=1 "General"
 * (excluye Muestras General id=4 y bodegas temporales como id=52, que no
 * son inventario disponible para despacho — ver php/clasificar_libros_bodega.php).
 * Devuelve null si la API respondió con error (para no interpretar un fallo
 * de conexión como "sin existencias").
 */
function existencia_bodega_general($idInventario) {
    $resp = consultar_existencias_por_bodega($idInventario);
    if (($resp['status'] ?? '') === 'error') return null;
    $filas = $resp['data']['content'] ?? [];
    foreach ($filas as $f) {
        if ((int)($f['id'] ?? 0) === 1) return (float)($f['cantidad'] ?? 0);
    }
    return 0.0;
}

/**
 * Clasifica varios productos según en qué bodega(s) de World Office tienen
 * existencias — mismo criterio que php/clasificar_libros_bodega.php (que lo
 * hace uno por uno para libros ya existentes): 1 si solo está en bodega
 * id=1 "General", 4 si solo está en id=4 "Muestras General", 3 si está en
 * ambas, 0 si no está en ninguna de las dos. Dispara las peticiones GET
 * /inventarios/{id}/existencias/bodega en paralelo (curl_multi), igual que
 * existencias_bodega_general_bulk() — se usa para precargar la bodega de un
 * libro que todavía no existe en el catálogo local (traído desde "Buscar
 * libros nuevos en World Office" en libros.php).
 * Devuelve [idInventario => 0|1|3|4|null] (null = la API falló para ese id).
 */
function clasificar_bodega_bulk(array $idsInventario, $concurrencia = 30) {
    $bodegaGeneral = 1;
    $bodegaMuestrasGeneral = 4;

    $ids = array_values(array_unique(array_filter($idsInventario, fn($v) => $v !== null && $v !== '')));
    $resultados = [];
    if (!$ids) return $resultados;

    foreach (array_chunk($ids, max(1, (int)$concurrencia)) as $lote) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($lote as $id) {
            $ch = curl_init(API_URL_BASE . '/inventarios/' . rawurlencode($id) . '/existencias/bodega');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: ' . API_TOKEN
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        $activos = null;
        do {
            $estado = curl_multi_exec($mh, $activos);
            if ($activos > 0) curl_multi_select($mh);
        } while ($activos > 0 && $estado === CURLM_OK);

        foreach ($handles as $id => $ch) {
            $respuesta = curl_multi_getcontent($ch);
            $data = json_decode($respuesta, true);
            $tipo = null;
            if (is_array($data) && ($data['status'] ?? '') !== 'error') {
                $enGeneral = false;
                $enMuestras = false;
                foreach ($data['data']['content'] ?? [] as $f) {
                    $idBodega = (int)($f['id'] ?? 0);
                    if ($idBodega === $bodegaGeneral) $enGeneral = true;
                    if ($idBodega === $bodegaMuestrasGeneral) $enMuestras = true;
                }
                $tipo = ($enGeneral && $enMuestras) ? 3 : ($enGeneral ? 1 : ($enMuestras ? 4 : 0));
            }
            $resultados[$id] = $tipo;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    return $resultados;
}

/**
 * Igual que existencia_bodega_general() pero para varios productos a la vez,
 * disparando las peticiones GET /inventarios/{id}/existencias/bodega en
 * paralelo (curl_multi) en vez de una por una. Con una lista de pedidos que
 * comparten pocos libros únicos, esto es lo que evita que la consulta tarde
 * "cantidad de libros × ~400ms" — con 20 libros únicos, en serie son ~8s,
 * en paralelo (lotes del tamaño de $concurrencia) baja a ~1-2s.
 * Devuelve [idInventario => cantidad|null] (null = la API falló para ese id).
 */
function existencias_bodega_general_bulk(array $idsInventario, $concurrencia = 30) {
    $ids = array_values(array_unique(array_filter($idsInventario, fn($v) => $v !== null && $v !== '')));
    $resultados = [];
    if (!$ids) return $resultados;

    foreach (array_chunk($ids, max(1, (int)$concurrencia)) as $lote) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($lote as $id) {
            $ch = curl_init(API_URL_BASE . '/inventarios/' . rawurlencode($id) . '/existencias/bodega');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: ' . API_TOKEN
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        $activos = null;
        do {
            $estado = curl_multi_exec($mh, $activos);
            if ($activos > 0) curl_multi_select($mh);
        } while ($activos > 0 && $estado === CURLM_OK);

        foreach ($handles as $id => $ch) {
            $respuesta = curl_multi_getcontent($ch);
            $data = json_decode($respuesta, true);
            $cantidad = null;
            if (is_array($data) && ($data['status'] ?? '') !== 'error') {
                $cantidad = 0.0;
                foreach ($data['data']['content'] ?? [] as $f) {
                    if ((int)($f['id'] ?? 0) === 1) { $cantidad = (float)($f['cantidad'] ?? 0); break; }
                }
            }
            $resultados[$id] = $cantidad;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    return $resultados;
}
