<?php
/**
 * /includes/reporte_stock_datos.php
 * Datos para reporte_stock.php: por cada libro con pedidos pendientes o
 * aprobados (con adopción: pedidos/libros_pedidos; sin adopción:
 * pedidos2/libros_pedidos2) en un período, compara la cantidad pedida (suma
 * de lp.cantidad) contra la existencia real en la bodega General de World
 * Office — mismo umbral y misma consulta en bulk que includes/stock_bajo.php
 * (UMBRAL_STOCK_BAJO=50).
 *
 * "Pendientes/aprobados" usa el mismo criterio que ya define cada listado:
 * pedidos (con adopción) — estado 1=Pendiente, 2=Aprobado (ver
 * includes/lista_pedidos_query.php); pedidos2 (sin adopción) — 2=Aprobado,
 * y 1=Pendiente solo cuenta si además verify=1, porque así lo exige
 * lista_pedidos_sa.php para la pestaña "Pendientes".
 *
 * Además, solo se cuentan pedidos que efectivamente aparecen en esas listas:
 * lista_pedidos.php arma su tabla con JOIN (no LEFT JOIN) colegios→zonas→
 * usuarios (ver includes/lista_pedidos_query.php), así que un pedido cuyo
 * colegio no tenga cod_zona con una zona/usuario válidos queda "huérfano"
 * y jamás se ve ahí — a pedido explícito del usuario (2026-09-08), este
 * reporte replica esa misma condición (vía EXISTS, para no inflar el SUM de
 * cantidad_pedida con el fan-out que produciría un JOIN real si una zona
 * tiene más de un usuario) en vez de contar esos huérfanos. Mismo criterio
 * para pedidos2 con su propio JOIN usuarios de lista_pedidos_sa.php.
 */
require_once __DIR__ . '/stock_bajo.php';

/**
 * @param PDO    $bdd
 * @param string $origen 'pedidos' (con adopción) | 'pedidos2' (sin adopción)
 * @param int    $idUsuario 0 = todos los usuarios
 * @param int    $idPeriodo requerido; sin período no hay filas
 * @return array [['id_libro','libro','cantidad_pedida','existencia','stock_bajo'], ...]
 */
function reporte_stock_pedidos(PDO $bdd, $origen, $idUsuario, $idPeriodo) {
    $idPeriodo = intval($idPeriodo);
    if (!$idPeriodo) return [];

    if ($origen === 'pedidos2') {
        $sql = "SELECT l.id AS id_libro, l.libro, l.id_wo, SUM(lp.cantidad) AS cantidad_pedida
                FROM pedidos2 pe
                JOIN libros_pedidos2 lp ON lp.cod_pedido = pe.codigo
                JOIN libros l           ON l.id = lp.id_libro
                WHERE (pe.estado = '2' OR (pe.estado = '1' AND pe.verify = '1'))
                  AND pe.id_periodo = :periodo AND lp.cantidad != 0
                  AND EXISTS (SELECT 1 FROM usuarios u WHERE u.id = pe.id_usuario)";
    } else {
        $sql = "SELECT l.id AS id_libro, l.libro, l.id_wo, SUM(lp.cantidad) AS cantidad_pedida
                FROM pedidos pe
                JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
                JOIN libros l          ON l.id = lp.id_libro
                WHERE pe.estado IN ('1','2')
                  AND pe.id_periodo = :periodo AND lp.cantidad != 0
                  AND EXISTS (
                        SELECT 1 FROM colegios c
                        JOIN zonas z     ON z.codigo = c.cod_zona
                        JOIN usuarios u  ON u.cod_zona = z.codigo
                        WHERE c.id = pe.id_colegio
                      )";
    }

    $params = [':periodo' => $idPeriodo];
    $idUsuario = intval($idUsuario);
    if ($idUsuario > 0) {
        $sql .= " AND pe.id_usuario = :usuario";
        $params[':usuario'] = $idUsuario;
    }
    $sql .= " GROUP BY l.id, l.libro, l.id_wo ORDER BY l.libro";

    $req = $bdd->prepare($sql);
    $req->execute($params);
    $filas = $req->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) return [];

    $idsWo = array_column($filas, 'id_wo');
    $existencias = existencias_bodega_general_bulk($idsWo);

    $resultado = [];
    foreach ($filas as $f) {
        $existencia = ($f['id_wo'] !== null && $f['id_wo'] !== '') ? ($existencias[$f['id_wo']] ?? null) : null;
        $resultado[] = [
            'id_libro'        => (int)$f['id_libro'],
            'libro'           => $f['libro'],
            'cantidad_pedida' => (float)$f['cantidad_pedida'],
            'existencia'      => $existencia,
            'stock_bajo'      => $existencia !== null ? ($existencia < UMBRAL_STOCK_BAJO) : null,
        ];
    }
    return $resultado;
}

/**
 * Variante "detallado" del reporte de stock — solo para pedidos con adopción
 * (origen "pedidos"; no aplica a "sin adopción", a pedido explícito del
 * usuario 2026-09-08). Mismas columnas que reporte_stock_pedidos() pero sin
 * agrupar por libro a través de todos los colegios: cada fila es
 * colegio+libro, con Empresa/Zona/Asesor calculados con el mismo criterio
 * (y las mismas limitaciones) que ya usa ajax/lista_pedidos_data.php — si
 * tipo del usuario de la zona es 3 (asesor real), Empresa/Zona salen de
 * partir "Empresa/Zona" en zonas.zona y el Asesor es ese usuario; si no,
 * Empresa es zonas.zona completo, Zona sale de sub_zonas y el Asesor es
 * colegios.responsable.
 *
 * @return array [['colegio','empresa','zona','asesor','id_libro','libro','cantidad_pedida','existencia','stock_bajo'], ...]
 */
function reporte_stock_pedidos_detallado(PDO $bdd, $idUsuario, $idPeriodo) {
    $idPeriodo = intval($idPeriodo);
    if (!$idPeriodo) return [];

    $sql = "SELECT c.id AS id_colegio, c.colegio, c.sub_zona, c.responsable, z.zona,
                   (SELECT u2.tipo FROM usuarios u2 WHERE u2.cod_zona = z.codigo ORDER BY u2.id LIMIT 1) AS u_tipo,
                   (SELECT CONCAT(TRIM(u2.nombres), ' ', TRIM(u2.apellidos)) FROM usuarios u2 WHERE u2.cod_zona = z.codigo ORDER BY u2.id LIMIT 1) AS u_nombre,
                   l.id AS id_libro, l.libro, l.id_wo, SUM(lp.cantidad) AS cantidad_pedida
            FROM pedidos pe
            JOIN colegios c        ON c.id = pe.id_colegio
            JOIN zonas z           ON z.codigo = c.cod_zona
            JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
            JOIN libros l          ON l.id = lp.id_libro
            WHERE pe.estado IN ('1','2')
              AND pe.id_periodo = :periodo AND lp.cantidad != 0
              AND EXISTS (SELECT 1 FROM usuarios u WHERE u.cod_zona = z.codigo)";

    $params = [':periodo' => $idPeriodo];
    $idUsuario = intval($idUsuario);
    if ($idUsuario > 0) {
        $sql .= " AND pe.id_usuario = :usuario";
        $params[':usuario'] = $idUsuario;
    }
    $sql .= " GROUP BY c.id, c.colegio, c.sub_zona, c.responsable, z.zona, l.id, l.libro, l.id_wo
              ORDER BY c.colegio, l.libro";

    $req = $bdd->prepare($sql);
    $req->execute($params);
    $filas = $req->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) return [];

    $subZonasMap = [];
    foreach ($bdd->query("SELECT id, sub_zona FROM sub_zonas")->fetchAll(PDO::FETCH_ASSOC) as $sz)
        $subZonasMap[$sz['id']] = $sz['sub_zona'];

    $idsWo = array_column($filas, 'id_wo');
    $existencias = existencias_bodega_general_bulk($idsWo);

    $resultado = [];
    foreach ($filas as $f) {
        $tipoU = intval($f['u_tipo'] ?? 0);
        if ($tipoU === 3) {
            $partes  = explode('/', $f['zona'] ?? '');
            $empresa = trim($partes[0] ?? '');
            $zonaTxt = trim($partes[1] ?? '');
            $asesor  = trim($f['u_nombre'] ?? '');
        } else {
            $empresa = $f['zona'] ?? '';
            $zonaTxt = $subZonasMap[$f['sub_zona']] ?? '—';
            $asesor  = $f['responsable'] ?? '—';
        }

        $existencia = ($f['id_wo'] !== null && $f['id_wo'] !== '') ? ($existencias[$f['id_wo']] ?? null) : null;
        $resultado[] = [
            'colegio'         => $f['colegio'],
            'empresa'         => $empresa,
            'zona'            => $zonaTxt,
            'asesor'          => $asesor,
            'id_libro'        => (int)$f['id_libro'],
            'libro'           => $f['libro'],
            'cantidad_pedida' => (float)$f['cantidad_pedida'],
            'existencia'      => $existencia,
            'stock_bajo'      => $existencia !== null ? ($existencia < UMBRAL_STOCK_BAJO) : null,
        ];
    }
    return $resultado;
}
