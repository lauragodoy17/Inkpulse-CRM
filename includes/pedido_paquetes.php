<?php
/**
 * Pedido de venta por paquetes y libros sueltos según el Tipo de adopción (pedido de la usuaria 2026-11-10).
 *
 * - "Libros Sueltos" (1) o sin tipo: se piden todos los títulos adoptados como libro suelto.
 * - "Paquetes" (2): se piden los paquetes guardados del colegio (paquetes_colegio); cada paquete pedido se guarda en
 *   libros_pedidos como un renglón por libro del paquete, con la cantidad del paquete y libros_pedidos.id_paquete.
 * - "Ambos" (3): paquetes + los títulos marcados "Libro suelto" en la adopción (presupuestos.venta_suelta).
 *
 * Lo usan solicitar_pedido.php (formulario) y php/pedido.php (guardado).
 */
require_once __DIR__ . '/paquetes_colegio.php';
require_once __DIR__ . '/orden_texto.php';
require_once __DIR__ . '/plan_lector.php'; // MATERIA_PLAN_LECTOR, plan_lector_comparar

/** libros_pedidos.id_paquete: paquete del que viene el renglón (NULL = libro suelto). */
function pedido_paquetes_asegurar(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    if ($bdd->query("SHOW COLUMNS FROM libros_pedidos LIKE 'id_paquete'")->fetch()) return;
    try {
        $bdd->exec("ALTER TABLE libros_pedidos ADD COLUMN id_paquete INT NULL DEFAULT NULL");
    } catch (Exception $e) {}
}

/** pedidos.tipo según el Tipo de adopción: 1 libros sueltos, 2 paquetes, 3 ambos. */
function pedido_tipo_segun_adopcion(PDO $bdd, $idColegio, $idPeriodo) {
    $t = tipo_adop_guardado($bdd, $idColegio, $idPeriodo);
    return in_array($t, [2, 3], true) ? $t : 1;
}

/** Texto del tipo de pedido (pedidos.tipo). */
function pedido_tipo_texto($tipo) {
    switch ((int)$tipo) {
        case 1: return 'Libros sueltos';
        case 2: return 'Paquetes';
        case 3: return 'Paquetes y libros sueltos';
        default: return 'Libros sueltos';
    }
}

/**
 * Títulos adoptados del colegio con lo que necesita el formulario, por id de presupuesto:
 * ['id_presup','id_libro','cod_area','libro','materia','grado','comp_act','venta_suelta','plataforma'].
 * comp_act = compradores activos (alumnos del grado × tasa de compra), igual que antes en solicitar_pedido.php.
 */
function pedido_libros_adoptados(PDO $bdd, $idColegio, $idPeriodo) {
    $idColegio = (int)$idColegio;
    $idPeriodo = (int)$idPeriodo;
    $st = $bdd->prepare("SELECT p.id AS id_presup, l.id AS id_libro, l.id_grado, l.libro, p.tasa_compra, p.tasa_compra_d, m.materia,
                                p.cod_area, COALESCE(p.venta_suelta, 0) AS venta_suelta,
                                (SELECT ao.id_grado_otro FROM areas_objetivas ao WHERE ao.codigo = p.cod_area AND p.cod_area <> '' LIMIT 1) AS id_grado_otro,
                                (l.id_materia = " . MATERIA_PLAN_LECTOR . " OR EXISTS (SELECT 1 FROM areas_objetivas ao2 WHERE ao2.codigo = p.cod_area AND p.cod_area <> ''
                                    AND ao2.id_materia = " . MATERIA_PLAN_LECTOR . ")) AS plan_lector
                         FROM libros l
                         JOIN presupuestos p ON l.id = p.id_libro
                         JOIN materias m ON l.id_materia = m.id
                         WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = '1'");
    $st->execute([$idColegio, $idPeriodo]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);

    $grados = [];
    foreach ($bdd->query("SELECT id, grado FROM grados") as $g) $grados[(int)$g['id']] = $g['grado'];
    $st_alm = $bdd->prepare("SELECT id_grado, SUM(alumnos) AS alumnos FROM grados_paralelos WHERE id_periodo = ? AND id_colegio = ? GROUP BY id_grado");
    $st_alm->execute([$idPeriodo, $idColegio]);
    $alumnos = [];
    foreach ($st_alm->fetchAll(PDO::FETCH_ASSOC) as $a) $alumnos[(int)$a['id_grado']] = (float)$a['alumnos'];
    // Plataforma ya marcada en otro pedido del período (mismo criterio que antes).
    $st_plat = $bdd->prepare("SELECT lp.plataforma FROM libros_pedidos lp JOIN pedidos p ON p.codigo = lp.cod_pedido WHERE lp.id_libro = ? AND p.id_periodo = ? LIMIT 1");

    $out = [];
    foreach ($filas as $f) {
        $idGrado = (int)$f['id_grado_otro'] ?: (int)$f['id_grado'];
        $tasa = ((float)$f['tasa_compra_d'] == 0.0) ? (float)$f['tasa_compra'] : (float)$f['tasa_compra_d'];
        $st_plat->execute([$f['id_libro'], $idPeriodo]);
        $out[(int)$f['id_presup']] = [
            'id_presup'    => (int)$f['id_presup'],
            'id_libro'     => (int)$f['id_libro'],
            'cod_area'     => (string)$f['cod_area'],
            'libro'        => $f['libro'],
            'materia'      => $f['materia'],
            'id_grado'     => $idGrado,
            'grado'        => $grados[$idGrado] ?? '—',
            'comp_act'     => (int)floor(round(($alumnos[$idGrado] ?? 0) * $tasa, 6)),
            'venta_suelta' => (int)$f['venta_suelta'] === 1,
            'plataforma'   => (int)$st_plat->fetchColumn() !== 0,
            'plan_lector'  => (bool)$f['plan_lector'],
        ];
    }
    // Por curso (grados.id va de Pre jardín a 11) y dentro del curso alfabético por título, sin distinguir tildes
    // (2026-11-19 / 2026-11-20). 2026-11-23: el plan lector va al final, también por curso y alfabético.
    // Se conservan las claves (id de presupuesto), que usan los paquetes para buscar sus libros.
    uasort($out, fn($a, $b) => plan_lector_comparar(['plan' => $a['plan_lector'], 'grado' => $a['id_grado'], 'titulo' => $a['libro']],
                                                     ['plan' => $b['plan_lector'], 'grado' => $b['id_grado'], 'titulo' => $b['libro']]));
    return $out;
}

/**
 * Lo que se puede pedir según el Tipo de adopción:
 * ['tipo_adop', 'tipo_pedido', 'paquetes' => [['id','paquete','grado','precio_final','comp_act','libros' => [...]]],
 *  'sueltos' => [libros de pedido_libros_adoptados]].
 * comp_act del paquete = el menor de sus libros (cada libro del paquete sale con la cantidad del paquete);
 * 0 = sin límite, como en los libros.
 */
function pedido_opciones(PDO $bdd, $idColegio, $idPeriodo) {
    crear_tablas_paquetes($bdd);
    $tipoAdop = tipo_adop_guardado($bdd, $idColegio, $idPeriodo);
    $libros = pedido_libros_adoptados($bdd, $idColegio, $idPeriodo);
    $res = ['tipo_adop' => $tipoAdop, 'tipo_pedido' => pedido_tipo_segun_adopcion($bdd, $idColegio, $idPeriodo), 'paquetes' => [], 'sueltos' => []];

    if (in_array($tipoAdop, [2, 3], true)) {
        $st = $bdd->prepare("SELECT pc.id, pc.paquete, pc.precio_final, pc.id_grado, g.grado FROM paquetes_colegio pc LEFT JOIN grados g ON g.id = pc.id_grado
                             WHERE pc.id_colegio = ? AND pc.id_periodo = ? ORDER BY pc.id_grado, pc.numero");
        $st->execute([(int)$idColegio, (int)$idPeriodo]);
        $st_lib = $bdd->prepare("SELECT pl.id_presupuesto, pl.id_libro, l.libro FROM paquetes_colegio_libros pl JOIN libros l ON l.id = pl.id_libro WHERE pl.id_paquete = ? ORDER BY l.libro");
        // Paquetes por curso y dentro del curso por nombre; los libros de cada paquete, alfabéticos (2026-11-20).
        $paqs = $st->fetchAll(PDO::FETCH_ASSOC);
        usort($paqs, fn($a, $b) => (((int)$a['id_grado'] ?: 99) <=> ((int)$b['id_grado'] ?: 99)) ?: orden_texto_cmp($a['paquete'], $b['paquete']));
        foreach ($paqs as $p) {
            $st_lib->execute([(int)$p['id']]);
            $libs = [];
            $limites = [];
            foreach ($st_lib->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $info = $libros[(int)$l['id_presupuesto']] ?? null;
                $libs[] = ['libro' => $l['libro'], 'materia' => $info['materia'] ?? ''];
                if ($info && $info['comp_act'] > 0) $limites[] = $info['comp_act'];
            }
            usort($libs, fn($a, $b) => orden_texto_cmp($a['libro'], $b['libro']));
            if (!$libs) continue;
            $res['paquetes'][] = [
                'id' => (int)$p['id'], 'paquete' => $p['paquete'], 'grado' => $p['grado'] ?? '',
                'precio_final' => (float)$p['precio_final'], 'comp_act' => $limites ? min($limites) : 0, 'libros' => $libs,
            ];
        }
    }

    if ($tipoAdop === 3) {
        $res['sueltos'] = array_values(array_filter($libros, fn($l) => $l['venta_suelta']));
    } elseif ($tipoAdop !== 2) {
        $res['sueltos'] = array_values($libros);
    }
    return $res;
}

/**
 * Paquetes del formulario ("id_paquete/cantidad/plataforma") → renglones de libros_pedidos:
 * [['id_libro','cod_area','id_presup','cantidad','plataforma','id_paquete']]. Solo paquetes del colegio y período
 * y con cantidad > 0.
 */
function pedido_lineas_de_paquetes(PDO $bdd, $idColegio, $idPeriodo, array $valores) {
    $st_paq = $bdd->prepare("SELECT id FROM paquetes_colegio WHERE id = ? AND id_colegio = ? AND id_periodo = ?");
    $st_lib = $bdd->prepare("SELECT pl.id_libro, pl.id_presupuesto, COALESCE(p.cod_area, '') AS cod_area
                             FROM paquetes_colegio_libros pl LEFT JOIN presupuestos p ON p.id = pl.id_presupuesto
                             WHERE pl.id_paquete = ?");
    $out = [];
    foreach ($valores as $v) {
        $partes = array_pad(explode('/', (string)$v), 3, '0');
        $idPaq = (int)$partes[0];
        $cant = (int)$partes[1];
        $plat = (int)$partes[2] ? 1 : 0;
        if ($idPaq <= 0 || $cant <= 0) continue;
        $st_paq->execute([$idPaq, (int)$idColegio, (int)$idPeriodo]);
        if (!$st_paq->fetchColumn()) continue;
        $st_lib->execute([$idPaq]);
        foreach ($st_lib->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $out[] = ['id_libro' => (int)$l['id_libro'], 'cod_area' => (string)$l['cod_area'], 'id_presup' => (int)$l['id_presupuesto'],
                      'cantidad' => $cant, 'plataforma' => $plat, 'id_paquete' => $idPaq];
        }
    }
    return $out;
}
