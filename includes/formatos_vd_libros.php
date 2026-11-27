<?php
/**
 * /includes/formatos_vd_libros.php
 * Tabla de control POR GRADO del cierre de ventas del formato de venta directa (2026-11-26, la usuaria). Reemplaza, en el
 * cierre de dinero, la tabla de ventas separada en paquetes y libros sueltos y la pantalla aparte "Armar paquetes":
 *  - Una fila POR LIBRO, agrupadas por grado (encabezado de color por grado; amarillo si en el grado algún libro tiene
 *    Remisionado < Solicitado). Dentro del grado, primero los libros en el orden del paquete de la adopción, luego los
 *    demás en orden alfabético y al final el plan lector.
 *  - Libro pedido en 2 o más cursos (p. ej. JUGAR CON LETRAS en Jardín y Transición; 2026-11-27, la usuaria): UNA FILA
 *    POR CURSO, con lo pedido en ese curso (libros_pedidos.cod_area) y lo devuelto en ese curso (libros_devol_v.cod_area
 *    de las devoluciones del formato). Lo remisionado no dice de qué curso sale: se reparte como lo pedido y la diferencia
 *    va al curso con más pedido. Cada fila va en el paquete de SU curso.
 *  - Una fila POR PAQUETE en su grado (2026-11-27, la usuaria): Kits × Precio del paquete (paquetes_colegio.precio_final de
 *    la adopción, editable aquí sin cambiar la adopción) = Total kit. Ya no se reparte el precio del paquete entre sus
 *    libros. Los kits los ESCRIBE la persona (formatos_vd_kits); el botón "Proponer reparto" solo llena las casillas.
 *  - Por libro: Suelto × Precio suelto (precio de venta individual de la adopción, editable), también escrito a mano.
 *    Cada kit lleva 1 unidad de cada libro del paquete. Sin tope (2026-11-27, la usuaria): se cobra lo escrito aunque pase
 *    de lo vendido (se pudo enviar o vender algo que no se remisionó) o quede por debajo. "Sin inventario" = libros
 *    pagados que no había en bodega (nota del Excel).
 *  - Los formatos cerrados antes del reparto manual (libros_novedades ≠ FVDL_MANUAL) conservan el cálculo automático con
 *    el que se cerraron: kits = lo que alcanza el libro del paquete con menos vendidos y el resto suelto.
 *  - GRAN TOTAL = Σ kits × precio del paquete + Σ suelto × precio suelto; pasa a ser el total de venta del formato
 *    (formatos_vd.total_venta) con el que se cuadra el cierre de dinero.
 * Las cantidades se proponen con las filas del formato (lo remisionado y lo devuelto de la devolución) y quedan guardadas
 * en formatos_vd_libros; editar aquí NO cambia la devolución ni sus documentos. Si cambian las filas del formato (p. ej.
 * llega la 2a devolución) antes del cierre, se actualizan las cantidades que no se habían editado a mano.
 * Los formatos cerrados antes de este cambio (sin filas aquí) se siguen mostrando como antes.
 */

/** Versión del armado de filas: al cambiarla, los formatos abiertos se vuelven a proponer una vez (fvdl_huella_items). */
const FVDL_VERSION_FILAS = 2; // 2026-11-27: filas por curso de los libros pedidos en varios cursos

/** Tablas de las filas por libro y de los kits por paquete. Antes de cualquier transacción. */
function fvdl_asegurar_tabla(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    // curso: id del grado cuando el libro se pidió en varios cursos y va una fila por curso; 0 = una sola fila.
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_libros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        id_libro INT NOT NULL,
        curso INT NOT NULL DEFAULT 0,
        libro VARCHAR(255) NOT NULL,
        id_grado INT NULL,
        grado VARCHAR(100) NULL,
        plan_lector TINYINT NOT NULL DEFAULT 0,
        orden INT NOT NULL DEFAULT 0,
        paquetes VARCHAR(255) NULL,
        solicitado INT NOT NULL DEFAULT 0,
        remisionado INT NOT NULL DEFAULT 0,
        devuelto INT NOT NULL DEFAULT 0,
        kit INT NOT NULL DEFAULT 0,
        suelto INT NOT NULL DEFAULT 0,
        sin_inventario INT NOT NULL DEFAULT 0,
        precio_kit DECIMAL(14,2) NOT NULL DEFAULT 0,
        precio_suelto DECIMAL(14,2) NOT NULL DEFAULT 0,
        base_solicitado INT NOT NULL DEFAULT 0,
        base_remisionado INT NOT NULL DEFAULT 0,
        base_devuelto INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_formatos_vd_libros (id_formato, id_libro, curso),
        KEY idx_formatos_vd_libros_formato (id_formato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_libros LIKE 'curso'")->fetch()) {
        $bdd->exec("ALTER TABLE formatos_vd_libros ADD COLUMN curso INT NOT NULL DEFAULT 0 AFTER id_libro,
                    DROP INDEX uniq_formatos_vd_libros, ADD UNIQUE KEY uniq_formatos_vd_libros (id_formato, id_libro, curso)");
    }
    // Kits por paquete (2026-11-27): kits NULL = automáticos (sin novedades); precio NULL = el de la adopción.
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_kits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        id_paquete INT NOT NULL,
        kits INT NULL,
        precio DECIMAL(14,2) NULL,
        UNIQUE KEY uniq_formatos_vd_kits (id_formato, id_paquete)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'libros_huella'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd ADD COLUMN libros_huella VARCHAR(32) NULL"); } catch (Exception $e) { /* ya existe */ }
    }
    // ¿La venta tuvo novedades? (fvdl_novedades): NULL = automático.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'libros_novedades'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd ADD COLUMN libros_novedades TINYINT NULL"); } catch (Exception $e) { /* ya existe */ }
    }
}

/** Huella de las filas del formato (y de sus devoluciones) de las que salen las cantidades propuestas. */
function fvdl_huella_items(array $f) {
    $x = [];
    foreach ($f['items'] as $it) {
        $x[] = [$it['id'], $it['tipo'], $it['id_paquete'], $it['id_libro'], $it['libros'], (int)$it['solicitud'], (int)$it['inv_inicial'],
                (int)$it['devueltas'], (int)($it['en_paquetes'] ?? 0), (int)($it['armados'] ?? 0), (int)($it['abiertos'] ?? 0)];
    }
    return md5(json_encode([FVDL_VERSION_FILAS, $f['legalizacion']['ids'] ?? [], $x]));
}

/** La tabla por grado se usa (se crea y se edita) desde el cierre de dinero; un formato cerrado solo la muestra si ya la tiene. */
function fvdl_aplica(array $f) {
    return $f['activo'] && !$f['reservado'] && $f['etapa'] >= FVD_ETAPA_RECIBIDO && $f['etapa'] < FVD_ETAPA_CERRADO;
}

/** Filas guardadas del formato: [id => fila]. */
function fvdl_filas(PDO $bdd, $idFormato) {
    fvdl_asegurar_tabla($bdd);
    $st = $bdd->prepare("SELECT * FROM formatos_vd_libros WHERE id_formato = ? ORDER BY orden, id");
    $st->execute([(int)$idFormato]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['id', 'id_formato', 'id_libro', 'curso', 'id_grado', 'plan_lector', 'orden', 'solicitado', 'remisionado', 'devuelto', 'kit', 'suelto', 'sin_inventario',
                  'base_solicitado', 'base_remisionado', 'base_devuelto'] as $k) $r[$k] = (int)$r[$k];
        $r['precio_kit'] = (float)$r['precio_kit'];
        $r['precio_suelto'] = (float)$r['precio_suelto'];
        $res[$r['id']] = $r;
    }
    return $res;
}

/** Kits guardados por paquete: [id_paquete => ['kits' => int|null, 'precio' => float|null]]. */
function fvdl_kits(PDO $bdd, $idFormato) {
    fvdl_asegurar_tabla($bdd);
    $st = $bdd->prepare("SELECT id_paquete, kits, precio FROM formatos_vd_kits WHERE id_formato = ?");
    $st->execute([(int)$idFormato]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $res[(int)$r['id_paquete']] = ['kits' => $r['kits'] === null ? null : (int)$r['kits'], 'precio' => $r['precio'] === null ? null : (float)$r['precio']];
    }
    return $res;
}

/**
 * Paquetes de la adopción del colegio con sus libros en orden, su grado y su precio (paquetes_colegio.precio_final):
 * [id_paquete => ['id','paquete','id_grado','grado','precio','libros' => [id_libro => parte del precio]]]. La parte de
 * cada libro (precio_neto llevado al precio del paquete; el último libro lleva el redondeo) ya no se cobra por libro: solo
 * sirve como precio de referencia del libro en el Excel.
 */
function fvdl_paquetes(PDO $bdd, $idColegio, $idPeriodo) {
    if (!$bdd->query("SHOW TABLES LIKE 'paquetes_colegio'")->fetch()) return [];
    $st = $bdd->prepare("SELECT pq.id, pq.paquete, pq.id_grado, g.grado, pq.precio_final FROM paquetes_colegio pq LEFT JOIN grados g ON g.id = pq.id_grado
                         WHERE pq.id_colegio = ? AND pq.id_periodo = ? ORDER BY pq.id_grado, pq.numero, pq.id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $res[(int)$p['id']] = ['id' => (int)$p['id'], 'paquete' => $p['paquete'], 'id_grado' => (int)$p['id_grado'], 'grado' => trim((string)$p['grado']),
                               'precio' => (float)$p['precio_final'], 'libros' => [], 'netos' => []];
    }
    if (!$res) return [];
    foreach ($bdd->query("SELECT id_paquete, id_libro, precio_neto FROM paquetes_colegio_libros WHERE id_paquete IN (" . implode(',', array_keys($res)) . ") ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = &$res[(int)$r['id_paquete']];
        if (!isset($p['netos'][(int)$r['id_libro']])) $p['netos'][(int)$r['id_libro']] = (float)$r['precio_neto'];
        unset($p);
    }
    foreach ($res as &$p) {
        $n = count($p['netos']);
        $suma = array_sum($p['netos']);
        $acum = 0;
        $i = 0;
        foreach ($p['netos'] as $l => $neto) {
            $i++;
            $parte = $i === $n ? $p['precio'] - $acum : round($suma > 0 ? $p['precio'] * $neto / $suma : $p['precio'] / $n);
            $p['libros'][$l] = max(0, $parte);
            $acum += $parte;
        }
        unset($p['netos']);
    }
    unset($p);
    return array_filter($res, fn($p) => $p['libros']);
}

/**
 * Reparte $total entre las claves de $vals (los valores de partida) para que sumen exactamente $total: lo que falta se suma
 * a la clave con más $peso; lo que sobra se quita empezando por la de más $peso, sin bajar de $piso[clave].
 */
function fvdl_ajustar_reparto(array $vals, $total, array $peso, array $piso = []) {
    $dif = (int)$total - array_sum($vals);
    if (!$vals || $dif === 0) return $vals;
    $orden = array_keys($peso);
    usort($orden, fn($a, $b) => $peso[$b] <=> $peso[$a]);
    if ($dif > 0) { $vals[$orden[0]] += $dif; return $vals; }
    foreach ($orden as $k) {
        $quita = min(-$dif, max(0, $vals[$k] - (int)($piso[$k] ?? 0)));
        $vals[$k] -= $quita;
        $dif += $quita;
        if ($dif === 0) break;
    }
    return $vals;
}

/**
 * Propuesta por libro (y por curso) con las filas del formato: ["id_libro:curso" => ['id_libro','curso','grado_curso',
 * 'libro','sol','rem','dev']], con los libros de los paquetes pasados al libros.id de la fila del título del mismo producto
 * (libro repetido en el catálogo). curso = 0 salvo en los libros pedidos en 2 o más cursos (fvdl_cursos_base).
 */
function fvdl_base_items(PDO $bdd, array $f) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_claves_catalogo
    $ids = [];
    foreach ($f['items'] as $it) foreach (array_filter(explode(',', (string)$it['libros'])) as $l) $ids[] = (int)$l;
    foreach ($f['items'] as $it) if ($it['id_libro']) $ids[] = (int)$it['id_libro'];
    $cat = dvv_claves_catalogo($bdd, $ids);
    // Libro representante de cada producto: el de la fila del título si existe.
    $rep = [];
    foreach ($f['items'] as $it) if ($it['tipo'] === 'titulo' && isset($cat[(int)$it['id_libro']])) $rep[$cat[(int)$it['id_libro']]] = (int)$it['id_libro'];
    $canon = function ($l) use ($cat, &$rep) {
        $k = $cat[(int)$l] ?? 'l' . (int)$l;
        if (!isset($rep[$k])) $rep[$k] = (int)$l;
        return $rep[$k];
    };
    $res = [];
    $add = function ($l, $campo, $n, $nombre = null) use (&$res, $canon) {
        $l = $canon($l);
        $res[$l] = $res[$l] ?? ['libro' => null, 'sol' => 0, 'rem' => 0, 'dev' => 0];
        $res[$l][$campo] += $n;
        if ($nombre !== null && $res[$l]['libro'] === null) $res[$l]['libro'] = $nombre;
    };
    foreach ($f['items'] as $it) {
        $libros = array_filter(explode(',', (string)$it['libros']));
        if ($it['tipo'] === 'titulo') {
            $l = (int)$it['id_libro'];
            $add($l, 'sol', (int)$it['solicitud'], $it['descripcion']);
            $add($l, 'rem', (int)$it['inv_inicial'] + (int)($it['en_paquetes'] ?? 0)); // deshace el armado anterior
            $add($l, 'dev', (int)$it['devueltas']);
        } elseif ($it['tipo'] === 'paquete') {
            $rem = (int)$it['inv_inicial'] - (int)($it['armados'] ?? 0); // los armados vienen de los títulos
            foreach ($libros as $l) {
                $add($l, 'sol', (int)$it['solicitud']);
                $add($l, 'rem', max(0, $rem));
                $add($l, 'dev', (int)$it['devueltas']);
            }
        } elseif ($it['tipo'] === FVD_TIPO_ABIERTO || $it['tipo'] === FVD_TIPO_SUELTO_PAQ) {
            // Libro de un paquete ya contado en la fila del paquete: solo suma lo que volvió suelto.
            $add((int)$it['id_libro'], 'dev', (int)$it['devueltas']);
        }
    }
    $sinNombre = array_keys(array_filter($res, fn($x) => $x['libro'] === null));
    if ($sinNombre) foreach ($bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', $sinNombre) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $res[(int)$id]['libro'] = $n;
    foreach ($res as $l => &$x) $x['libro'] = $x['libro'] ?? 'Libro #' . $l;
    unset($x);

    $cursos = fvdl_cursos_base($bdd, $f, array_keys($res), $canon);
    $out = [];
    foreach ($res as $l => $x) {
        if (!isset($cursos[$l])) {
            $out["$l:0"] = $x + ['id_libro' => $l, 'curso' => 0, 'grado_curso' => null];
            continue;
        }
        // Una fila por curso: lo pedido y lo devuelto de cada curso, ajustados para que sumen lo del libro; lo remisionado
        // se reparte como lo pedido (World Office no dice de qué curso sale cada unidad).
        $c = $cursos[$l];
        $peso = array_map(fn($y) => $y['sol'], $c);
        $sol = fvdl_ajustar_reparto($peso, $x['sol'], $peso);
        $dev = fvdl_ajustar_reparto(array_map(fn($y) => $y['dev'], $c), $x['dev'], $peso);
        $rem = fvdl_ajustar_reparto($sol, $x['rem'], $peso, $dev);
        foreach ($c as $g => $y) {
            $out["$l:$g"] = ['libro' => $x['libro'], 'sol' => $sol[$g], 'rem' => $rem[$g], 'dev' => $dev[$g], 'id_libro' => $l, 'curso' => $g, 'grado_curso' => $y['grado']];
        }
    }
    return $out;
}

/**
 * Cursos de los libros pedidos en 2 o más cursos de distinto grado (2026-11-27, la usuaria): lo pedido por curso en los
 * pedidos del formato (libros_pedidos.cod_area) y lo devuelto por curso en sus devoluciones no anuladas
 * (libros_devol_v.cod_area). Cada área va al grado de la adopción (en el grado "Otro" = 17, el grado específico); dos áreas
 * del mismo grado se suman. Devuelve [id_libro (de la tabla) => [id_grado => ['grado','sol','dev']]] ordenado por grado.
 */
function fvdl_cursos_base(PDO $bdd, array $f, array $idsTabla, callable $canon) {
    $idsPed = array_values(array_filter(array_map(fn($c) => (int)substr(trim($c), 1), explode(',', (string)$f['ids_pedidos']))));
    if (!$idsPed || !$idsTabla) return [];
    $pedido = $bdd->query("SELECT lp.id_libro, lp.cod_area, SUM(lp.cantidad) AS n FROM libros_pedidos lp JOIN pedidos x ON x.codigo = lp.cod_pedido
                           WHERE x.id IN (" . implode(',', $idsPed) . ") AND COALESCE(lp.cod_area, '') <> ''
                           GROUP BY lp.id_libro, lp.cod_area HAVING n > 0")->fetchAll(PDO::FETCH_ASSOC);
    if (!$pedido) return [];
    $st = $bdd->prepare("SELECT ld.id_libro, ld.cod_area, SUM(ld.cantidad) AS n FROM libros_devol_v ld JOIN devoluciones_v d ON d.codigo = ld.cod_pedido
                         WHERE d.id_formato_vd = ? AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . " AND COALESCE(ld.cod_area, '') <> ''
                         GROUP BY ld.id_libro, ld.cod_area");
    $st->execute([(int)$f['id']]);
    $devol = $st->fetchAll(PDO::FETCH_ASSOC);

    $areas = [];
    foreach (array_merge($pedido, $devol) as $r) $areas[(string)$r['cod_area']] = true;
    $st = $bdd->prepare("SELECT a.codigo, g.id, g.grado FROM areas_objetivas a JOIN grados g ON g.id = IF(a.id_grado = 17 AND a.id_grado_otro > 0, a.id_grado_otro, a.id_grado)
                         WHERE a.id_colegio = ? AND a.codigo IN (" . implode(',', array_fill(0, count($areas), '?')) . ")");
    $st->execute(array_merge([(int)$f['id_colegio']], array_map('strval', array_keys($areas))));
    $grado = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $grado[(string)$r['codigo']] = ['id' => (int)$r['id'], 'grado' => trim((string)$r['grado'])];

    $enTabla = array_flip($idsTabla);
    $res = [];
    foreach ($pedido as $r) {
        $l = $canon($r['id_libro']);
        $g = $grado[(string)$r['cod_area']] ?? null;
        if (!isset($enTabla[$l]) || !$g) continue;
        $res[$l][$g['id']] = $res[$l][$g['id']] ?? ['grado' => $g['grado'], 'sol' => 0, 'dev' => 0];
        $res[$l][$g['id']]['sol'] += (int)$r['n'];
    }
    $res = array_filter($res, fn($c) => count($c) > 1);
    foreach ($devol as $r) {
        $l = $canon($r['id_libro']);
        $g = $grado[(string)$r['cod_area']] ?? null;
        if ($g && isset($res[$l][$g['id']])) $res[$l][$g['id']]['dev'] += (int)$r['n'];
    }
    foreach ($res as &$c) ksort($c);
    unset($c);
    return $res;
}

/** Paquetes de la adopción con sus libros pasados al libros.id de la tabla (mismo producto del catálogo). */
function fvdl_paquetes_canonicos(PDO $bdd, array $paquetes, array $idsTabla) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php';
    $ids = $idsTabla;
    foreach ($paquetes as $p) foreach (array_keys($p['libros']) as $l) $ids[] = (int)$l;
    if (!$ids) return $paquetes;
    $cat = dvv_claves_catalogo($bdd, $ids);
    $deClave = [];
    foreach ($idsTabla as $l) if (isset($cat[$l])) $deClave[$cat[$l]] = $l;
    foreach ($paquetes as &$p) {
        $nuevo = [];
        foreach ($p['libros'] as $l => $precio) $nuevo[$deClave[$cat[(int)$l] ?? ''] ?? (int)$l] = $precio;
        $p['libros'] = $nuevo;
    }
    unset($p);
    return $paquetes;
}

/**
 * Crea o actualiza las filas por libro del formato con la propuesta (fvdl_base_items). Primera vez: todas las columnas
 * propuestas. Después, solo si cambiaron las filas del formato: las cantidades que no se editaron a mano toman el valor
 * nuevo y aparecen los libros nuevos; Suelto / Sin inventario solo llevan lo escrito a mano. Devuelve las filas.
 */
function fvdl_sincronizar(PDO $bdd, array $f) {
    fvdl_asegurar_tabla($bdd);
    $filas = fvdl_filas($bdd, $f['id']);
    if (!fvdl_aplica($f)) return $filas;
    $huella = fvdl_huella_items($f);
    // Suelto / Sin inventario arrancan en 0 (2026-11-26, la usuaria): solo llevan lo que se escriba con novedades. Las
    // filas creadas antes con valores propuestos se limpian mientras el cierre no se haya guardado (libros_novedades NULL).
    if ($filas && ($f['libros_novedades'] ?? null) === null && array_filter($filas, fn($r) => $r['kit'] || $r['suelto'] || $r['sin_inventario'])) {
        $bdd->prepare("UPDATE formatos_vd_libros SET kit = 0, suelto = 0, sin_inventario = 0 WHERE id_formato = ?")->execute([(int)$f['id']]);
        $filas = fvdl_filas($bdd, $f['id']);
    }
    if ($filas && (string)($f['libros_huella'] ?? '') === $huella) return $filas;
    $prop = fvdl_propuesta($bdd, $f);
    fvdl_escribir($bdd, $f, $filas, $prop, $huella);
    return fvdl_filas($bdd, $f['id']);
}

/**
 * Propuesta por libro y curso (sin guardar), ya ordenada: ["id_libro:curso" => ['id_libro','curso','libro','id_grado',
 * 'grado','plan_lector','pos','paquetes','sol','rem','dev','precio_suelto']]. La fila de un curso va solo en los paquetes
 * de ese grado.
 */
function fvdl_propuesta(PDO $bdd, array $f) {
    $base = fvdl_base_items($bdd, $f);
    $idsLibro = array_values(array_unique(array_map(fn($b) => $b['id_libro'], $base)));
    $paquetes = fvdl_paquetes_canonicos($bdd, fvdl_paquetes($bdd, $f['id_colegio'], $f['id_periodo']), $idsLibro);
    $cursos = fvd_cursos_adopcion($bdd, $f['id_colegio'], $f['id_periodo'], $idsLibro);
    // Precio suelto: el de la fila del título (editado > remisión > precio venta padre); los libros que solo venían en
    // paquete, el precio venta padre de la adopción.
    $fake = ['items' => [], 'id_colegio' => $f['id_colegio'], 'id_periodo' => $f['id_periodo'], 'legalizacion' => null];
    foreach ($f['items'] as $it) if ($it['tipo'] === 'titulo') $fake['items'][] = $it;
    $conTitulo = array_map(fn($it) => (int)$it['id_libro'], $fake['items']);
    foreach ($idsLibro as $l) if (!in_array($l, $conTitulo, true)) $fake['items'][] = ['tipo' => 'titulo', 'id_libro' => $l, 'id_paquete' => null, 'clave' => 'L' . $l];
    $precios = $fake['items'] ? fvd_precios_actuales($bdd, $fake) : [];
    // Paquetes de cada libro en orden de la adopción, con su grado y la posición del libro.
    $enPaq = []; // id_libro => [['id','id_grado','grado','pos']]
    $pos = 0;
    foreach ($paquetes as $p) foreach (array_keys($p['libros']) as $l) $enPaq[$l][] = ['id' => $p['id'], 'id_grado' => $p['id_grado'], 'grado' => $p['grado'], 'pos' => $pos++];
    $prop = [];
    foreach ($base as $k => $b) {
        $l = $b['id_libro'];
        $c = $cursos[$l] ?? null;
        $lista = $enPaq[$l] ?? [];
        if ($b['curso']) $lista = array_values(array_filter($lista, fn($p) => $p['id_grado'] === $b['curso']));
        $i = $lista[0] ?? null;
        $idGrado = $b['curso'] ?: ($i ? $i['id_grado'] : ($c['id_grado'] ?? null));
        $grado = $b['curso'] ? $b['grado_curso'] : ($i ? $i['grado'] : ($c['grado'] ?? null));
        $prop[$k] = ['id_libro' => $l, 'curso' => $b['curso'], 'libro' => mb_substr($b['libro'], 0, 255), 'id_grado' => $idGrado, 'grado' => $grado,
                     'plan_lector' => $c && $c['plan_lector'] ? 1 : 0, 'pos' => $i ? $i['pos'] : null,
                     'paquetes' => implode(',', array_column($lista, 'id')),
                     'sol' => $b['sol'], 'rem' => $b['rem'], 'dev' => $b['dev'], 'precio_suelto' => (float)($precios['L' . $l]['precio'] ?? 0)];
    }
    // Orden: por grado (sin grado al final); en el grado los libros del paquete en su orden, luego los demás por nombre y el plan lector al final.
    uksort($prop, function ($a, $b) use ($prop) {
        $x = $prop[$a]; $y = $prop[$b];
        $gx = $x['id_grado'] ?: PHP_INT_MAX; $gy = $y['id_grado'] ?: PHP_INT_MAX;
        if ($gx !== $gy) return $gx <=> $gy;
        if ($x['plan_lector'] !== $y['plan_lector']) return $x['plan_lector'] <=> $y['plan_lector'];
        if (($x['pos'] === null) !== ($y['pos'] === null)) return $x['pos'] === null ? 1 : -1;
        if ($x['pos'] !== null) return $x['pos'] <=> $y['pos'];
        return function_exists('orden_texto_cmp') ? orden_texto_cmp($x['libro'], $y['libro']) : strcasecmp($x['libro'], $y['libro']);
    });
    return $prop;
}

/** Escribe la propuesta sobre las filas guardadas (ver fvdl_sincronizar). */
function fvdl_escribir(PDO $bdd, array $f, array $filas, array $prop, $huella) {
    $porClave = [];
    foreach ($filas as $r) $porClave[$r['id_libro'] . ':' . $r['curso']] = $r;
    $porCurso = []; // libros que ahora van una fila por curso
    foreach ($prop as $x) if ($x['curso']) $porCurso[$x['id_libro']] = true;
    $bdd->beginTransaction();
    try {
        $ins = $bdd->prepare("INSERT INTO formatos_vd_libros (id_formato, id_libro, curso, libro, id_grado, grado, plan_lector, orden, paquetes, solicitado, remisionado, devuelto,
                                     kit, suelto, precio_kit, precio_suelto, base_solicitado, base_remisionado, base_devuelto)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)");
        $up = $bdd->prepare("UPDATE formatos_vd_libros SET libro = ?, id_grado = ?, grado = ?, plan_lector = ?, orden = ?, paquetes = ?, solicitado = ?, remisionado = ?, devuelto = ?,
                                    base_solicitado = ?, base_remisionado = ?, base_devuelto = ? WHERE id = ?");
        $del = $bdd->prepare("DELETE FROM formatos_vd_libros WHERE id = ?");
        $orden = 0;
        foreach ($prop as $k => $x) {
            $orden++;
            $r = $porClave[$k] ?? null;
            if (!$r) {
                $ins->execute([(int)$f['id'], $x['id_libro'], $x['curso'], $x['libro'], $x['id_grado'], $x['grado'], $x['plan_lector'], $orden, $x['paquetes'], $x['sol'], $x['rem'], $x['dev'],
                               $x['precio_suelto'], $x['sol'], $x['rem'], $x['dev']]);
                continue;
            }
            // Lo editado a mano se conserva; lo demás toma el valor nuevo.
            $sol = $r['solicitado'] === $r['base_solicitado'] ? $x['sol'] : $r['solicitado'];
            $rem = $r['remisionado'] === $r['base_remisionado'] ? $x['rem'] : $r['remisionado'];
            $dev = $r['devuelto'] === $r['base_devuelto'] ? $x['dev'] : $r['devuelto'];
            $up->execute([$x['libro'], $x['id_grado'], $x['grado'], $x['plan_lector'], $orden, $x['paquetes'], $sol, $rem, $dev, $x['sol'], $x['rem'], $x['dev'], $r['id']]);
        }
        foreach ($porClave as $k => $r) {
            if (isset($prop[$k])) continue;
            // La fila única de un libro que ahora va por curso se quita siempre (si no, sus unidades se contarían dos veces);
            // los demás libros que ya no están en el formato, solo si no se tocaron.
            $intacta = $r['solicitado'] === $r['base_solicitado'] && $r['remisionado'] === $r['base_remisionado'] && $r['devuelto'] === $r['base_devuelto'];
            if ($intacta || isset($porCurso[$r['id_libro']])) $del->execute([$r['id']]);
        }
        $bdd->prepare("UPDATE formatos_vd SET libros_huella = ? WHERE id = ?")->execute([$huella, (int)$f['id']]);
        $bdd->commit();
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Formato venta directa ' . (int)$f['id'] . ': no se armaron las filas por libro: ' . $e->getMessage());
    }
}

/** formatos_vd.libros_novedades de los formatos guardados con el reparto manual de kits y sueltos (2026-11-27). */
const FVDL_MANUAL = 2;

/**
 * ¿El reparto de kits y sueltos lo escribe la persona? Sí en todo formato abierto y en los cerrados desde el 2026-11-27
 * (libros_novedades = FVDL_MANUAL); los cerrados antes conservan el cálculo automático con el que se cerraron.
 */
function fvdl_es_manual(array $f) {
    return (int)$f['etapa'] < FVD_ETAPA_CERRADO || (int)($f['libros_novedades'] ?? -1) === FVDL_MANUAL;
}

/**
 * ¿La venta tuvo novedades? (2026-11-26, la usuaria). Solo para los formatos cerrados antes del reparto manual. Solo con novedades se escriben los Kits, Suelto y Sin inventario.
 * formatos_vd.libros_novedades: 1 / 0 elegido en el cierre; NULL = automático: sí cuando algún libro se remisionó por
 * debajo de lo solicitado (World Office).
 */
function fvdl_novedades(array $f, array $filas) {
    if (isset($f['libros_novedades']) && $f['libros_novedades'] !== null && $f['libros_novedades'] !== '') return (bool)(int)$f['libros_novedades'];
    foreach ($filas as $r) if ((int)$r['remisionado'] < (int)$r['solicitado']) return true;
    return false;
}

/**
 * Cálculo de la tabla (el mismo que hace la pantalla en vivo). $filas = fvdl_filas (o con los valores enviados); $kits =
 * fvdl_kits (o lo enviado); null = lo guardado.
 *  - Reparto manual (fvdl_es_manual): kits de cada paquete = los escritos (0 si no); Suelto = el escrito. Sin tope: lo que
 *    falte para lo vendido queda en 'sin_asignar'.
 *  - Formatos cerrados con el cálculo anterior: kits con novedades los escritos o, si no, solos (lo que alcanza el libro
 *    con menos vendidos sin kit, si todos sus libros están en la tabla); Suelto = el escrito o lo que sobra, y debía
 *    cumplirse En kits + Suelto = Vendido + Sin inventario.
 *  - Total kit = kits × precio del paquete (el escrito o el de la adopción); total suelto = Suelto × precio suelto.
 *  - Reparto manual (2026-11-27, la usuaria): total sin inventario = Sin inventario × precio suelto, y suma al gran total.
 *    Un kit con un libro del que se vendió menos (kits_sin_stock) es un AVISO, no bloquea el cierre.
 * Devuelve ['manual','novedades','filas' => [id => fila],'grados' => [['clave','id_grado','grado','faltante','filas','paquetes' =>
 * [kit],'total_kit','total_suelto','kits']],'paquetes' => [kit],'total_kit','total_suelto','gran_total','kits','errores'];
 * cada fila con 'vendido','en_kit','suelto_ef','total_suelto','total_sinv','kits_sin_stock','faltante'; 'total_sinv' y 'avisos' en el
 * total; cada kit ['id','paquete','id_grado','kits',
 * 'auto','precio','precio_adopcion','total','libros' => [['fila','nombre','pk']]].
 */
function fvdl_calcular(PDO $bdd, array $f, array $filas, $novedades = null, $kits = null) {
    // Reparto MANUAL (2026-11-27, la usuaria): la persona escribe los kits de cada paquete y el suelto de cada libro; la
    // única restricción es no pasar de lo vendido (+ sin inventario) y lo que quede sin asignar se deja pasar. Los formatos
    // cerrados antes con el cálculo automático (libros_novedades distinto de FVDL_MANUAL) se siguen mostrando como se cerraron.
    $manual = fvdl_es_manual($f);
    $novedades = $manual ? true : ($novedades === null ? fvdl_novedades($f, $filas) : (bool)$novedades);
    $kits = $kits ?? fvdl_kits($bdd, $f['id']);
    $paquetes = fvdl_paquetes_canonicos($bdd, fvdl_paquetes($bdd, $f['id_colegio'], $f['id_periodo']), array_values(array_unique(array_map(fn($r) => (int)$r['id_libro'], $filas))));
    // Fila de cada libro en cada paquete (una fila por curso va solo en el paquete de su curso).
    $enPaq = []; // id_paquete => [id_libro => id fila]
    foreach ($filas as $id => $r) foreach (array_filter(explode(',', (string)$r['paquetes'])) as $p) $enPaq[(int)$p][(int)$r['id_libro']] = $id;
    $faltanNombre = [];
    foreach ($paquetes as $p) foreach (array_keys($p['libros']) as $l) if (!isset($enPaq[$p['id']][$l])) $faltanNombre[$l] = true;
    $nombres = $faltanNombre ? $bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', array_keys($faltanNombre)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $errores = [];
    $libre = [];
    foreach ($filas as $id => &$r) {
        $r['vendido'] = (int)$r['remisionado'] - (int)$r['devuelto'];
        if (!$novedades) $r['sin_inventario'] = 0;
        $libre[$id] = max(0, $r['vendido']);
        $r['en_kit'] = 0;
    }
    unset($r);
    $kitsPaq = [];
    foreach ($paquetes as $p) {
        $libros = [];
        foreach ($p['libros'] as $l => $pk) {
            $id = $enPaq[$p['id']][$l] ?? null;
            $libros[] = ['fila' => $id, 'nombre' => $id !== null ? $filas[$id]['libro'] : ($nombres[$l] ?? 'Libro #' . $l), 'pk' => (float)$pk];
        }
        $presentes = array_values(array_filter(array_column($libros, 'fila'), fn($x) => $x !== null));
        if (!$presentes) continue;
        $auto = count($presentes) === count($libros) ? min(array_map(fn($id) => $libre[$id], $presentes)) : 0;
        $cfg = $kits[$p['id']] ?? ['kits' => null, 'precio' => null];
        $n = $manual ? (int)($cfg['kits'] ?? 0) : ($novedades && $cfg['kits'] !== null ? (int)$cfg['kits'] : $auto);
        $precio = $cfg['precio'] !== null ? (float)$cfg['precio'] : $p['precio'];
        foreach ($presentes as $id) {
            $libre[$id] -= min($libre[$id], $n);
            $filas[$id]['en_kit'] += $n;
        }
        if ($n > 0 && $precio <= 0) $errores[] = '"' . $p['paquete'] . '": falta el precio del paquete.';
        $kitsPaq[$p['id']] = ['id' => $p['id'], 'paquete' => $p['paquete'], 'id_grado' => $p['id_grado'], 'kits' => $n, 'auto' => $auto,
                              'precio' => $precio, 'precio_adopcion' => $p['precio'], 'total' => round($n * $precio, 2), 'libros' => $libros];
    }
    $grados = [];
    $tot = ['total_kit' => 0, 'total_suelto' => 0, 'total_sinv' => 0, 'kits' => 0];
    foreach ($filas as $id => &$r) {
        $vend = max(0, $r['vendido']);
        $n = $r['libro'];
        $sinv = (int)$r['sin_inventario'];
        $sobra = max(0, $vend + $sinv - $r['en_kit']);
        $r['sin_asignar'] = 0;
        if ($manual) {
            $r['suelto_ef'] = (int)$r['suelto'];
            // Lo escrito en Sin inventario sale de las unidades sueltas que van quedando (2026-11-27, la usuaria).
            $asignado = $r['en_kit'] + $r['suelto_ef'] + $sinv;
            // Sin tope (2026-11-27, la usuaria): se puede haber enviado o vendido algo que no se remisionó.
            $r['sin_asignar'] = max(0, $vend - $asignado);
            // Kits con este libro por encima de lo vendido: se vendieron kits sin inventario de él (aviso). Lo escrito en
            // Sin inventario lo cubre (2026-11-27, la usuaria): ya no hay aviso si En kits <= Vendido + Sin inventario.
            $r['kits_sin_stock'] = max(0, $r['en_kit'] - $vend - $sinv);
        } else {
        $r['suelto_ef'] = $novedades && (int)$r['suelto'] > 0 ? (int)$r['suelto'] : $sobra;
        if ($r['vendido'] >= 0 && $r['en_kit'] + $r['suelto_ef'] !== $vend + $sinv) {
            $errores[] = '"' . $n . '": se vendieron ' . $vend . ($sinv ? ' y ' . $sinv . ' sin inventario' : '') . ', pero van ' . $r['en_kit'] . ' en kits y '
                . $r['suelto_ef'] . ' sueltos (' . ($r['en_kit'] + $r['suelto_ef']) . '). '
                . ($r['en_kit'] > $vend + $sinv ? 'Si los kits salieron sin este libro, anota la diferencia en Sin inventario; si no, baja los kits del paquete.' : 'El Suelto debe ser ' . $sobra . '.');
        }
        }
        $r['kits_sin_stock'] = $r['kits_sin_stock'] ?? 0;
        $r['total_suelto'] = round($r['suelto_ef'] * (float)$r['precio_suelto'], 2);
        // Vendido sin inventario × precio (2026-11-27, la usuaria). Los cerrados con el cálculo anterior no lo cobraban.
        $r['total_sinv'] = $manual ? round($sinv * (float)$r['precio_suelto'], 2) : 0;
        $r['faltante'] = (int)$r['remisionado'] < (int)$r['solicitado'];
        // Devuelto mayor que remisionado ya no bloquea (2026-11-27, la usuaria): se pudo enviar algo que no se remisionó.
        if (($r['suelto_ef'] > 0 || ($manual && $sinv > 0)) && (float)$r['precio_suelto'] <= 0) $errores[] = '"' . $n . '": falta el precio suelto.';
        $k = $r['id_grado'] ? 'g' . (int)$r['id_grado'] : 'sin';
        $grados[$k] = $grados[$k] ?? ['clave' => $k, 'id_grado' => (int)$r['id_grado'], 'grado' => $r['id_grado'] ? ((string)$r['grado'] !== '' ? $r['grado'] : 'Grado ' . (int)$r['id_grado']) : 'Sin grado',
                                      'faltante' => false, 'filas' => [], 'paquetes' => [], 'total_kit' => 0, 'total_suelto' => 0, 'total_sinv' => 0, 'kits' => 0];
        $grados[$k]['filas'][] = $r;
        if ($r['faltante']) $grados[$k]['faltante'] = true;
        $grados[$k]['total_suelto'] += $r['total_suelto'];
        $tot['total_suelto'] += $r['total_suelto'];
        $grados[$k]['total_sinv'] += $r['total_sinv'];
        $tot['total_sinv'] += $r['total_sinv'];
    }
    unset($r);
    // Aviso (2026-11-27, la usuaria): kits vendidos con libros que no tenían inventario.
    $avisos = [];
    foreach ($kitsPaq as &$kp) {
        $kp['sin_stock'] = [];
        if ($manual && $kp['kits'] > 0) foreach ($kp['libros'] as $l) {
            if ($l['fila'] === null) { $kp['sin_stock'][] = $l['nombre'] . ' (no se remisionó)'; continue; }
            $fl = $filas[$l['fila']];
            if ($fl['kits_sin_stock'] > 0) $kp['sin_stock'][] = $l['nombre'] . ' (se vendieron ' . max(0, $fl['vendido']) . ')';
        }
        if ($kp['sin_stock']) $avisos[] = 'Estás colocando que vendiste kits sin inventario: "' . $kp['paquete'] . '" (' . $kp['kits'] . ' kit' . ($kp['kits'] === 1 ? '' : 's') . ') lleva ' . implode(', ', $kp['sin_stock']) . '.';
    }
    unset($kp);
    foreach ($kitsPaq as &$kp) {
        $k = 'g' . (int)$kp['id_grado'];
        // El paquete va en el grado de la adopción; si no hay filas de ese grado, en el de su primer libro.
        if (!isset($grados[$k])) {
            $primera = array_values(array_filter(array_column($kp['libros'], 'fila'), fn($x) => $x !== null))[0];
            $k = $filas[$primera]['id_grado'] ? 'g' . (int)$filas[$primera]['id_grado'] : 'sin';
        }
        $kp['grado_clave'] = $k;
        $grados[$k]['paquetes'][] = $kp;
        $grados[$k]['total_kit'] += $kp['total'];
        $grados[$k]['kits'] += $kp['kits'];
        $tot['total_kit'] += $kp['total'];
        $tot['kits'] += $kp['kits'];
    }
    unset($kp);
    uasort($grados, fn($a, $b) => ($a['id_grado'] ?: PHP_INT_MAX) <=> ($b['id_grado'] ?: PHP_INT_MAX));
    $tot['total_kit'] = round($tot['total_kit'], 2);
    $tot['total_suelto'] = round($tot['total_suelto'], 2);
    $tot['total_sinv'] = round($tot['total_sinv'], 2);
    return ['manual' => $manual, 'novedades' => $novedades, 'filas' => $filas, 'grados' => array_values($grados), 'paquetes' => array_values($kitsPaq),
            'total_kit' => $tot['total_kit'], 'total_suelto' => $tot['total_suelto'], 'total_sinv' => $tot['total_sinv'],
            'gran_total' => round($tot['total_kit'] + $tot['total_suelto'] + $tot['total_sinv'], 2),
            'kits' => $tot['kits'], 'errores' => $errores, 'avisos' => $avisos];
}

/**
 * Lee la tabla enviada en el cierre de dinero ($post['cl'][id][sol|rem|dev|suelto|sinv|ps]) sobre las filas guardadas.
 * Devuelve ['filas' => [id => fila con los valores nuevos]] o ['error' => texto].
 */
function fvdl_leer(array $filas, array $post) {
    $campos = ['sol' => 'solicitado', 'rem' => 'remisionado', 'dev' => 'devuelto', 'suelto' => 'suelto', 'sinv' => 'sin_inventario'];
    $nombres = ['sol' => 'Solicitado', 'rem' => 'Remisionado', 'dev' => 'Devuelto', 'suelto' => 'Suelto', 'sinv' => 'Sin inventario'];
    foreach ((array)($post['cl'] ?? []) as $id => $v) {
        $id = (int)$id;
        if (!isset($filas[$id])) return ['error' => 'La tabla del cierre cambió mientras la editabas. Recarga el formato.'];
        foreach ($campos as $k => $col) {
            if (!isset($v[$k])) continue;
            $n = fvd_entero($v[$k]);
            if ($n === null) return ['error' => '"' . $filas[$id]['libro'] . '": ' . $nombres[$k] . ' debe ser un número entero, sin decimales ni negativos.'];
            $filas[$id][$col] = $n;
        }
        if (isset($v['ps'])) {
            $p = fvd_leer_pesos($v['ps']);
            if ($p === null) return ['error' => '"' . $filas[$id]['libro'] . '": el precio suelto debe ser un valor en pesos, sin decimales.'];
            $filas[$id]['precio_suelto'] = (float)$p;
        }
    }
    return ['filas' => $filas];
}

/**
 * Lee los kits por paquete enviados en el cierre ($post['clk'][id_paquete][kits|precio]) sobre los guardados ($kits =
 * fvdl_kits). Sin novedades los kits quedan automáticos (null). Devuelve ['kits' => [...]] o ['error' => texto].
 */
function fvdl_leer_kits(array $kits, array $post, $novedades) {
    foreach ((array)($post['clk'] ?? []) as $idPaq => $v) {
        $idPaq = (int)$idPaq;
        if ($idPaq <= 0) continue;
        $cfg = $kits[$idPaq] ?? ['kits' => null, 'precio' => null];
        if (isset($v['kits'])) {
            $n = fvd_entero($v['kits']);
            if ($n === null) return ['error' => 'Los kits de cada paquete deben ser un número entero, sin decimales ni negativos.'];
            $cfg['kits'] = $n;
        }
        if (isset($v['precio'])) {
            $p = fvd_leer_pesos($v['precio']);
            if ($p === null) return ['error' => 'El precio del paquete debe ser un valor en pesos, sin decimales.'];
            $cfg['precio'] = (float)$p;
        }
        $kits[$idPaq] = $cfg;
    }
    if (!$novedades) foreach ($kits as &$cfg) $cfg['kits'] = null;
    unset($cfg);
    return ['kits' => $kits];
}

/** Guarda las filas y los kits por paquete (dentro de la transacción del cierre de dinero). */
function fvdl_guardar_filas(PDO $bdd, $idFormato, array $filas, array $kits = []) {
    $up = $bdd->prepare("UPDATE formatos_vd_libros SET solicitado = ?, remisionado = ?, devuelto = ?, suelto = ?, sin_inventario = ?, precio_suelto = ?
                         WHERE id = ? AND id_formato = ?");
    foreach ($filas as $r) {
        $up->execute([(int)$r['solicitado'], (int)$r['remisionado'], (int)$r['devuelto'], (int)$r['suelto'], (int)$r['sin_inventario'],
                      (float)$r['precio_suelto'], (int)$r['id'], (int)$idFormato]);
    }
    $upK = $bdd->prepare("INSERT INTO formatos_vd_kits (id_formato, id_paquete, kits, precio) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE kits = VALUES(kits), precio = VALUES(precio)");
    foreach ($kits as $idPaq => $cfg) $upK->execute([(int)$idFormato, (int)$idPaq, $cfg['kits'], $cfg['precio']]);
}

/** Gran total guardado de la tabla por grado del formato, o null si el formato no la usa. */
function fvdl_gran_total(PDO $bdd, array $f) {
    $filas = fvdl_filas($bdd, $f['id']);
    if (!$filas) return null;
    return fvdl_calcular($bdd, $f, $filas)['gran_total'];
}
