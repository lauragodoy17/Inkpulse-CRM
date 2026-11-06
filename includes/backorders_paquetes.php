<?php
/**
 * /includes/backorders_paquetes.php
 * Paquetes en backorders (pedido por la usuaria 2026-10-26): la API de World Office no trae la
 * ficha técnica de un paquete — en la REM/FV (o DREM/NC) el paquete es UN renglón cuyo código de
 * producto es el mismo código que genera el CRM (paquetes_colegio.codigo = DANE + curso + año, con
 * "-2" para el segundo paquete del curso). Ese renglón se abre en los libros del paquete
 * (paquetes_colegio_libros): N paquetes despachados = N unidades de cada libro.
 *
 * Se usa la composición ACTUAL del paquete: la adopción se cierra antes de despachar y ya no se
 * pueden cambiar ni precios ni componentes (decisión de la usuaria 2026-10-26, sin foto aparte).
 */

/** Código de producto normalizado para comparar contra paquetes_colegio.codigo. */
function backorders_codigo_paquete($codigo) {
    return strtoupper(trim((string)$codigo));
}

/**
 * Clave de libro para cruzar despachos: el id de inventario de World Office (libros.id_wo). Un
 * libro sin id_wo usa -libros.id: solo los paquetes llenan esa clave (sus componentes se conocen
 * por libros.id), así un libro sin id_wo también se descuenta cuando sale dentro de un paquete.
 */
function backorders_clave_libro($idLibroWo, $idLibro) {
    return (int)$idLibroWo > 0 ? (int)$idLibroWo : -(int)$idLibro;
}

/**
 * Paquetes del CRM por código: codigo => ['id', 'paquete', 'codigo', 'id_colegio', 'id_periodo',
 * 'libros' => [['id_libro', 'libro', 'isbn', 'clave'], ...]]. Sin tabla (nadie armó paquetes), vacío.
 */
function backorders_paquetes_por_codigo($bdd) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $sql = "SELECT pc.id, pc.paquete, pc.codigo, pc.id_colegio, pc.id_periodo, pl.id_libro, l.libro, l.isbn, l.id_wo
                FROM paquetes_colegio pc
                JOIN paquetes_colegio_libros pl ON pl.id_paquete = pc.id
                JOIN libros l ON l.id = pl.id_libro
                ORDER BY pc.id_grado, pc.numero, pc.id, pl.id";
        foreach ($bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codigo = backorders_codigo_paquete($r['codigo']);
            if ($codigo === '') continue;
            if (!isset($cache[$codigo])) {
                $cache[$codigo] = [
                    'id' => (int)$r['id'], 'paquete' => $r['paquete'], 'codigo' => $r['codigo'],
                    'id_colegio' => (int)$r['id_colegio'], 'id_periodo' => (int)$r['id_periodo'], 'libros' => [],
                ];
            }
            $cache[$codigo]['libros'][] = [
                'id_libro' => (int)$r['id_libro'], 'libro' => $r['libro'], 'isbn' => $r['isbn'] ?? '',
                'clave' => backorders_clave_libro($r['id_wo'], $r['id_libro']),
            ];
        }
    } catch (Exception $e) { $cache = []; }
    return $cache;
}

/** El paquete del CRM de un código de producto de World Office, o null. */
function backorders_paquete_de_codigo($bdd, $codigo) {
    $codigo = backorders_codigo_paquete($codigo);
    if ($codigo === '') return null;
    return backorders_paquetes_por_codigo($bdd)[$codigo] ?? null;
}

/**
 * Backorder por paquete de un colegio + período, para el detalle de reporte_backorders_pedido.php:
 * cada paquete con sus libros y lo pendiente del colegio de cada uno. $items = items del detalle
 * (cantidades por libro del colegio, ya con devoluciones); $despachados = [id_paquete => unidades
 * del paquete en las REM/FV]. Solo paquetes con algún libro en los pedidos del colegio.
 */
function backorders_detalle_paquetes($bdd, $idColegio, $idPeriodo, array $items, array $despachados) {
    $porLibro = [];
    foreach ($items as $it) $porLibro[(int)$it['id_libro']] = $it;
    $resultado = [];
    foreach (backorders_paquetes_por_codigo($bdd) as $p) {
        if ($p['id_colegio'] !== (int)$idColegio || $p['id_periodo'] !== (int)$idPeriodo) continue;
        $libros = [];
        $pendiente = 0.0;
        foreach ($p['libros'] as $lib) {
            $it = $porLibro[$lib['id_libro']] ?? null;
            if ($it === null) continue;
            $libros[] = [
                'id_libro' => $lib['id_libro'], 'libro' => $lib['libro'], 'isbn' => $lib['isbn'],
                'solicitada' => $it['solicitada'], 'despachada' => $it['despachada'], 'pendiente' => $it['pendiente'],
            ];
            $pendiente += $it['pendiente'];
        }
        if (!$libros) continue;
        $resultado[] = [
            'id_paquete' => $p['id'], 'paquete' => $p['paquete'], 'codigo' => $p['codigo'],
            'despachados' => round((float)($despachados[$p['id']] ?? 0), 2),
            'pendiente' => round($pendiente, 2),
            'libros' => $libros,
        ];
    }
    return $resultado;
}
