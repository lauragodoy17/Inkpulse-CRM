<?php
/**
 * /includes/formatos_vd_libros.php
 * Tabla de control POR GRADO del cierre de ventas del formato de venta directa (2026-11-26, la usuaria). Reemplaza, en el
 * cierre de dinero, la tabla de ventas separada en paquetes y libros sueltos y la pantalla aparte "Armar paquetes":
 *  - Una fila POR LIBRO, agrupadas por grado (encabezado de color por grado; amarillo si en el grado algún libro tiene
 *    Remisionado < Solicitado). Dentro del grado, primero los libros en el orden del paquete de la adopción, luego los
 *    demás en orden alfabético y al final el plan lector.
 *  - Columnas editables a mano: Solicitado, Remisionado, Devuelto, Kit, Suelto, Sin inventario, Precio kit y Precio suelto.
 *    Vendido = Remisionado − Devuelto (calculado) y siempre Vendido = Kit + Suelto.
 *  - Kits: se arman solos con la columna Kit. Cada paquete de la adopción (paquetes_colegio) lleva 1 unidad de cada uno de
 *    sus libros; kits completos del paquete = lo que alcanza el libro con menos unidades en Kit (en el orden de los
 *    paquetes, como fvd_repartir_en_paquetes). Unidades en Kit que no completan un paquete = inconsistencia (no se cierra).
 *  - Precios: Precio suelto = precio de venta individual de la adopción ("Precio venta padre", o el editado / de la
 *    remisión que ya tenía la fila del título). Precio kit = la parte del libro en el precio del paquete
 *    (paquetes_colegio_libros.precio_neto llevado a paquetes_colegio.precio_final), de modo que precio del kit = suma de
 *    los precios kit de sus libros. Ambos editables por fila; nunca se cambia la adopción.
 *  - Total kits = kits completos × precio del kit; total sueltos = Σ suelto × precio suelto; GRAN TOTAL = ambos. El gran
 *    total pasa a ser el total de venta del formato (formatos_vd.total_venta) con el que se cuadra el cierre de dinero.
 * Las cantidades se proponen con las filas del formato (lo remisionado y lo devuelto de la devolución) y quedan guardadas
 * en formatos_vd_libros; editar aquí NO cambia la devolución ni sus documentos. Si cambian las filas del formato (p. ej.
 * llega la 2a devolución) antes del cierre, se actualizan las cantidades que no se habían editado a mano.
 * Los formatos cerrados antes de este cambio (sin filas aquí) se siguen mostrando como antes.
 */

/** Tabla de las filas por libro y huella de las filas del formato con la que se propusieron. Antes de cualquier transacción. */
function fvdl_asegurar_tabla(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_libros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        id_libro INT NOT NULL,
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
        UNIQUE KEY uniq_formatos_vd_libros (id_formato, id_libro),
        KEY idx_formatos_vd_libros_formato (id_formato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'libros_huella'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd ADD COLUMN libros_huella VARCHAR(32) NULL"); } catch (Exception $e) { /* ya existe */ }
    }
    // ¿La venta tuvo novedades? (fvdl_novedades): NULL = automático.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'libros_novedades'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd ADD COLUMN libros_novedades TINYINT NULL"); } catch (Exception $e) { /* ya existe */ }
    }
}

/** Huella de las filas del formato de las que salen las cantidades propuestas. */
function fvdl_huella_items(array $f) {
    $x = [];
    foreach ($f['items'] as $it) {
        $x[] = [$it['id'], $it['tipo'], $it['id_paquete'], $it['id_libro'], $it['libros'], (int)$it['solicitud'], (int)$it['inv_inicial'],
                (int)$it['devueltas'], (int)($it['en_paquetes'] ?? 0), (int)($it['armados'] ?? 0), (int)($it['abiertos'] ?? 0)];
    }
    return md5(json_encode($x));
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
        foreach (['id', 'id_formato', 'id_libro', 'id_grado', 'plan_lector', 'orden', 'solicitado', 'remisionado', 'devuelto', 'kit', 'suelto', 'sin_inventario',
                  'base_solicitado', 'base_remisionado', 'base_devuelto'] as $k) $r[$k] = (int)$r[$k];
        $r['precio_kit'] = (float)$r['precio_kit'];
        $r['precio_suelto'] = (float)$r['precio_suelto'];
        $res[$r['id']] = $r;
    }
    return $res;
}

/**
 * Paquetes de la adopción del colegio con sus libros en orden, su grado y el precio kit de cada libro (su parte del
 * precio del paquete; el último libro lleva el redondeo para que la suma dé exactamente el precio del paquete):
 * [id_paquete => ['id','paquete','id_grado','grado','precio','libros' => [id_libro => precio_kit]]].
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
 * Propuesta por libro con las filas del formato: [id_libro => ['libro','sol','rem','dev','kit']] (kit = unidades que ya se
 * vendían en paquete: paquetes remisionados como producto de paquete en WO y los armados a mano), con los libros de los
 * paquetes pasados al libros.id de la fila del título del mismo producto (libro repetido en el catálogo).
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
        $res[$l] = $res[$l] ?? ['libro' => null, 'sol' => 0, 'rem' => 0, 'dev' => 0, 'kit' => 0];
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
            $add($l, 'kit', (int)($it['en_paquetes'] ?? 0));
        } elseif ($it['tipo'] === 'paquete') {
            $rem = (int)$it['inv_inicial'] - (int)($it['armados'] ?? 0); // los armados vienen de los títulos
            $enKit = max(0, $rem - (int)$it['devueltas'] - (int)($it['abiertos'] ?? 0));
            foreach ($libros as $l) {
                $add($l, 'sol', (int)$it['solicitud']);
                $add($l, 'rem', max(0, $rem));
                $add($l, 'dev', (int)$it['devueltas']);
                $add($l, 'kit', $enKit);
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
 * nuevo y aparecen los libros nuevos; Kit / Suelto / Sin inventario solo llevan lo escrito a mano. Devuelve las filas.
 */
function fvdl_sincronizar(PDO $bdd, array $f) {
    fvdl_asegurar_tabla($bdd);
    $filas = fvdl_filas($bdd, $f['id']);
    if (!fvdl_aplica($f)) return $filas;
    $huella = fvdl_huella_items($f);
    // Kit / Suelto / Sin inventario arrancan en 0 (2026-11-26, la usuaria): solo llevan lo que se escriba con novedades. Las
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
 * Propuesta por libro (sin guardar), ya ordenada: [id_libro => ['libro','id_grado','grado','plan_lector','pos','paquetes',
 * 'sol','rem','dev','kit','suelto','precio_kit','precio_suelto']].
 */
function fvdl_propuesta(PDO $bdd, array $f) {
    $base = fvdl_base_items($bdd, $f);
    $paquetes = fvdl_paquetes_canonicos($bdd, fvdl_paquetes($bdd, $f['id_colegio'], $f['id_periodo']), array_keys($base));
    $cursos = fvd_cursos_adopcion($bdd, $f['id_colegio'], $f['id_periodo'], array_keys($base));
    // Precio suelto: el de la fila del título (editado > remisión > precio venta padre); los libros que solo venían en
    // paquete, el precio venta padre de la adopción.
    $fake = ['items' => [], 'id_colegio' => $f['id_colegio'], 'id_periodo' => $f['id_periodo'], 'legalizacion' => null];
    foreach ($f['items'] as $it) if ($it['tipo'] === 'titulo') $fake['items'][] = $it;
    $conTitulo = array_map(fn($it) => (int)$it['id_libro'], $fake['items']);
    foreach (array_keys($base) as $l) if (!in_array($l, $conTitulo, true)) $fake['items'][] = ['tipo' => 'titulo', 'id_libro' => $l, 'id_paquete' => null, 'clave' => 'L' . $l];
    $precios = $fake['items'] ? fvd_precios_actuales($bdd, $fake) : [];
    // Paquetes, grado, orden y precio kit de cada libro.
    $info = [];
    $pos = 0;
    foreach ($paquetes as $p) {
        foreach ($p['libros'] as $l => $pk) {
            if (!isset($base[$l])) continue;
            $info[$l] = $info[$l] ?? ['paquetes' => [], 'precio_kit' => $pk, 'id_grado' => $p['id_grado'], 'grado' => $p['grado'], 'pos' => $pos++];
            $info[$l]['paquetes'][] = $p['id'];
        }
    }
    $prop = [];
    foreach ($base as $l => $b) {
        $c = $cursos[$l] ?? null;
        $i = $info[$l] ?? null;
        $prop[$l] = ['libro' => mb_substr($b['libro'], 0, 255), 'id_grado' => $i ? $i['id_grado'] : ($c['id_grado'] ?? null),
                     'grado' => $i ? $i['grado'] : ($c['grado'] ?? null), 'plan_lector' => $c && $c['plan_lector'] ? 1 : 0,
                     'pos' => $i ? $i['pos'] : null, 'paquetes' => $i ? implode(',', $i['paquetes']) : '',
                     'sol' => $b['sol'], 'rem' => $b['rem'], 'dev' => $b['dev'], 'kit' => 0, 'suelto' => 0,
                     'precio_kit' => $i ? (float)$i['precio_kit'] : 0, 'precio_suelto' => (float)($precios['L' . $l]['precio'] ?? 0)];
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
    $porLibro = [];
    foreach ($filas as $r) $porLibro[$r['id_libro']] = $r;
    $bdd->beginTransaction();
    try {
        $ins = $bdd->prepare("INSERT INTO formatos_vd_libros (id_formato, id_libro, libro, id_grado, grado, plan_lector, orden, paquetes, solicitado, remisionado, devuelto,
                                     kit, suelto, precio_kit, precio_suelto, base_solicitado, base_remisionado, base_devuelto)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $up = $bdd->prepare("UPDATE formatos_vd_libros SET libro = ?, id_grado = ?, grado = ?, plan_lector = ?, orden = ?, paquetes = ?, solicitado = ?, remisionado = ?, devuelto = ?,
                                    kit = ?, suelto = ?, base_solicitado = ?, base_remisionado = ?, base_devuelto = ? WHERE id = ?");
        $orden = 0;
        foreach ($prop as $l => $x) {
            $orden++;
            $r = $porLibro[$l] ?? null;
            if (!$r) {
                $ins->execute([(int)$f['id'], $l, $x['libro'], $x['id_grado'], $x['grado'], $x['plan_lector'], $orden, $x['paquetes'], $x['sol'], $x['rem'], $x['dev'],
                               $x['kit'], $x['suelto'], $x['precio_kit'], $x['precio_suelto'], $x['sol'], $x['rem'], $x['dev']]);
                continue;
            }
            // Lo editado a mano se conserva; lo demás toma el valor nuevo.
            $sol = $r['solicitado'] === $r['base_solicitado'] ? $x['sol'] : $r['solicitado'];
            $rem = $r['remisionado'] === $r['base_remisionado'] ? $x['rem'] : $r['remisionado'];
            $dev = $r['devuelto'] === $r['base_devuelto'] ? $x['dev'] : $r['devuelto'];
            $up->execute([$x['libro'], $x['id_grado'], $x['grado'], $x['plan_lector'], $orden, $x['paquetes'], $sol, $rem, $dev, $r['kit'], $r['suelto'],
                          $x['sol'], $x['rem'], $x['dev'], $r['id']]);
        }
        // Libros que ya no están en el formato y no se tocaron: se quitan.
        foreach ($porLibro as $l => $r) {
            if (isset($prop[$l])) continue;
            if ($r['solicitado'] === $r['base_solicitado'] && $r['remisionado'] === $r['base_remisionado'] && $r['devuelto'] === $r['base_devuelto']) {
                $bdd->prepare("DELETE FROM formatos_vd_libros WHERE id = ?")->execute([$r['id']]);
            }
        }
        $bdd->prepare("UPDATE formatos_vd SET libros_huella = ? WHERE id = ?")->execute([$huella, (int)$f['id']]);
        $bdd->commit();
    } catch (Exception $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Formato venta directa ' . (int)$f['id'] . ': no se armaron las filas por libro: ' . $e->getMessage());
    }
}

/**
 * ¿La venta tuvo novedades? (2026-11-26, la usuaria). Solo con novedades se ven y se escriben Kit / Suelto / Sin inventario.
 * formatos_vd.libros_novedades: 1 / 0 elegido en el cierre; NULL = automático: sí cuando algún libro se remisionó por
 * debajo de lo solicitado (World Office).
 */
function fvdl_novedades(array $f, array $filas) {
    if (isset($f['libros_novedades']) && $f['libros_novedades'] !== null && $f['libros_novedades'] !== '') return (bool)(int)$f['libros_novedades'];
    foreach ($filas as $r) if ((int)$r['remisionado'] < (int)$r['solicitado']) return true;
    return false;
}

/**
 * Cálculo de la tabla (el mismo que hace la pantalla en vivo). $filas = fvdl_filas (o con los valores enviados).
 * Kit / Suelto / Sin inventario son lo ESCRITO a mano (arrancan en 0 y no se llenan solos, 2026-11-26 la usuaria). Lo que
 * se cobra sale así:
 *  - Kits de cada paquete (en el orden de la adopción): con novedades y algún libro del paquete con Kit escrito, el MAYOR
 *    Kit escrito entre sus libros (el paquete sale aunque solo un libro diga Kit = 1: se vendió sin stock de los demás).
 *    Si no (sin novedades, o ningún libro del paquete con Kit), se arma solo con lo vendido: lo que alcanza el libro con
 *    menos vendidas, solo si todos sus libros están en la tabla.
 *  - Suelto cobrado de cada libro = lo vendido que no fue en kits; con novedades, si se escribió Suelto, ese (debe cuadrar).
 *  - Sin inventario es informativo (nota del Excel); sin novedades cuenta 0.
 * Precio del kit = suma de los precios kit de sus libros (el de la tabla o, si el libro no está, su parte en la adopción).
 * Total precio kit de la fila = kits de sus paquetes × su precio kit (la suma de las filas = kits × precio del paquete).
 * Devuelve ['novedades','filas' => [id => fila],'grados' => [['clave','id_grado','grado','faltante','filas','paquetes' =>
 * [['id','paquete','kits','precio','total']],'total_kit','total_suelto','kits']],'total_kit','total_suelto','gran_total',
 * 'kits','errores','paquetes' (para la pantalla)]; cada fila con 'vendido','en_kit','suelto_ef','total_kit','total_suelto','faltante'.
 */
function fvdl_calcular(PDO $bdd, array $f, array $filas, $novedades = null) {
    $novedades = $novedades === null ? fvdl_novedades($f, $filas) : (bool)$novedades;
    $paquetes = fvdl_paquetes_canonicos($bdd, fvdl_paquetes($bdd, $f['id_colegio'], $f['id_periodo']), array_map(fn($r) => (int)$r['id_libro'], $filas));
    $porLibro = [];
    foreach ($filas as $id => $r) $porLibro[(int)$r['id_libro']] = $id;
    $nombres = [];
    $faltanNombre = [];
    foreach ($paquetes as $p) foreach (array_keys($p['libros']) as $l) if (!isset($porLibro[$l])) $faltanNombre[$l] = true;
    if ($faltanNombre) foreach ($bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', array_keys($faltanNombre)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) as $l => $n) $nombres[(int)$l] = $n;
    $errores = [];
    $libre = [];
    foreach ($filas as $id => &$r) {
        $r['vendido'] = (int)$r['remisionado'] - (int)$r['devuelto'];
        if (!$novedades) $r['sin_inventario'] = 0;
        $libre[$id] = max(0, $r['vendido']);
        $r['en_kit'] = 0;
        $r['total_kit'] = 0;
    }
    unset($r);
    $kitsPaq = [];
    foreach ($paquetes as $p) {
        $presentes = array_values(array_filter(array_map(fn($l) => $porLibro[$l] ?? null, array_keys($p['libros'])), fn($x) => $x !== null));
        if (!$presentes) continue;
        $escrito = $novedades ? max(array_map(fn($id) => (int)$filas[$id]['kit'], $presentes)) : 0;
        if ($escrito > 0) $n = $escrito;
        elseif (count($presentes) === count($p['libros'])) $n = min(array_map(fn($id) => $libre[$id], $presentes));
        else continue; // automático: solo con todos sus libros en la tabla
        if ($n <= 0) continue;
        $precio = 0;
        foreach ($p['libros'] as $l => $pkAdop) {
            $id = $porLibro[$l] ?? null;
            if ($id === null) { $precio += $pkAdop; continue; }
            $precio += (float)$filas[$id]['precio_kit'];
            $libre[$id] -= min($libre[$id], $n);
            $filas[$id]['en_kit'] += $n;
            $filas[$id]['total_kit'] += $n * (float)$filas[$id]['precio_kit'];
        }
        $kitsPaq[$p['id']] = ['id' => $p['id'], 'paquete' => $p['paquete'], 'kits' => $n, 'precio' => $precio, 'total' => round($n * $precio, 2), 'id_grado' => $p['id_grado']];
    }
    $grados = [];
    $tot = ['total_kit' => 0, 'total_suelto' => 0, 'kits' => 0];
    foreach ($filas as $id => &$r) {
        $vend = $r['vendido'];
        $n = $r['libro'];
        $r['suelto_ef'] = $libre[$id]; // lo vendido que no fue en kits
        if ($novedades && (int)$r['suelto'] > 0) {
            if ((int)$r['suelto'] !== $libre[$id]) $errores[] = '"' . $n . '": se vendieron ' . max(0, $vend) . ($r['en_kit'] ? ' y ' . min(max(0, $vend), $r['en_kit']) . ' van en kits' : '') . ': el Suelto debe ser ' . $libre[$id] . ' (escribiste ' . (int)$r['suelto'] . ').';
            $r['suelto_ef'] = (int)$r['suelto'];
        }
        $r['total_kit'] = round($r['total_kit'], 2);
        $r['total_suelto'] = round($r['suelto_ef'] * (float)$r['precio_suelto'], 2);
        $r['faltante'] = (int)$r['remisionado'] < (int)$r['solicitado'];
        if ($vend < 0) $errores[] = '"' . $n . '": lo devuelto (' . (int)$r['devuelto'] . ') es más que lo remisionado (' . (int)$r['remisionado'] . ').';
        if ($novedades && (int)$r['kit'] > 0 && trim((string)$r['paquetes']) === '') $errores[] = '"' . $n . '" no hace parte de ningún paquete de la adopción: no puede ir en Kit.';
        if ($r['suelto_ef'] > 0 && (float)$r['precio_suelto'] <= 0) $errores[] = '"' . $n . '": falta el precio suelto.';
        if ($r['total_kit'] > 0 && (float)$r['precio_kit'] <= 0) $errores[] = '"' . $n . '": falta el precio kit.';
        $k = $r['id_grado'] ? 'g' . (int)$r['id_grado'] : 'sin';
        $grados[$k] = $grados[$k] ?? ['clave' => $k, 'id_grado' => (int)$r['id_grado'], 'grado' => $r['id_grado'] ? ((string)$r['grado'] !== '' ? $r['grado'] : 'Grado ' . (int)$r['id_grado']) : 'Sin grado',
                                      'faltante' => false, 'filas' => [], 'paquetes' => [], 'total_kit' => 0, 'total_suelto' => 0, 'kits' => 0];
        $grados[$k]['filas'][] = $r;
        if ($r['faltante']) $grados[$k]['faltante'] = true;
        $grados[$k]['total_suelto'] += $r['total_suelto'];
        $tot['total_suelto'] += $r['total_suelto'];
    }
    unset($r);
    foreach ($kitsPaq as $kp) {
        $k = 'g' . (int)$kp['id_grado'];
        // El paquete va en el grado de la adopción; si no hay filas de ese grado, en el primero.
        if (!isset($grados[$k])) foreach ($grados as $gk => $g) { $k = $gk; break; }
        $grados[$k]['paquetes'][] = $kp;
        $grados[$k]['total_kit'] += $kp['total'];
        $grados[$k]['kits'] += $kp['kits'];
        $tot['total_kit'] += $kp['total'];
        $tot['kits'] += $kp['kits'];
    }
    uasort($grados, fn($a, $b) => ($a['id_grado'] ?: PHP_INT_MAX) <=> ($b['id_grado'] ?: PHP_INT_MAX));
    $tot['total_kit'] = round($tot['total_kit'], 2);
    $tot['total_suelto'] = round($tot['total_suelto'], 2);
    return ['novedades' => $novedades, 'filas' => $filas, 'grados' => array_values($grados), 'total_kit' => $tot['total_kit'], 'total_suelto' => $tot['total_suelto'],
            'gran_total' => round($tot['total_kit'] + $tot['total_suelto'], 2), 'kits' => $tot['kits'], 'errores' => $errores,
            // Para la pantalla: cada paquete con las filas de sus libros (null si el libro no está en la tabla), su parte de la
            // adopción en el precio kit y su nombre.
            'paquetes' => array_values(array_map(fn($p) => ['id' => $p['id'], 'paquete' => $p['paquete'], 'id_grado' => $p['id_grado'],
                'libros' => array_map(fn($l) => ['fila' => $porLibro[$l] ?? null, 'pk' => (float)$p['libros'][$l], 'nombre' => isset($porLibro[$l]) ? $filas[$porLibro[$l]]['libro'] : ($nombres[$l] ?? 'Libro #' . $l)], array_keys($p['libros']))],
                $paquetes))];
}

/**
 * Lee la tabla enviada en el cierre de dinero ($post['cl'][id][sol|rem|dev|kit|suelto|sinv|pk|ps]) sobre las filas guardadas.
 * Devuelve ['filas' => [id => fila con los valores nuevos]] o ['error' => texto].
 */
function fvdl_leer(array $filas, array $post) {
    $campos = ['sol' => 'solicitado', 'rem' => 'remisionado', 'dev' => 'devuelto', 'kit' => 'kit', 'suelto' => 'suelto', 'sinv' => 'sin_inventario'];
    $nombres = ['sol' => 'Solicitado', 'rem' => 'Remisionado', 'dev' => 'Devuelto', 'kit' => 'Kit', 'suelto' => 'Suelto', 'sinv' => 'Sin inventario'];
    foreach ((array)($post['cl'] ?? []) as $id => $v) {
        $id = (int)$id;
        if (!isset($filas[$id])) return ['error' => 'La tabla del cierre cambió mientras la editabas. Recarga el formato.'];
        foreach ($campos as $k => $col) {
            if (!isset($v[$k])) continue;
            $n = fvd_entero($v[$k]);
            if ($n === null) return ['error' => '"' . $filas[$id]['libro'] . '": ' . $nombres[$k] . ' debe ser un número entero, sin decimales ni negativos.'];
            $filas[$id][$col] = $n;
        }
        foreach (['pk' => 'precio_kit', 'ps' => 'precio_suelto'] as $k => $col) {
            if (!isset($v[$k])) continue;
            $p = fvd_leer_pesos($v[$k]);
            if ($p === null) return ['error' => '"' . $filas[$id]['libro'] . '": el ' . ($k === 'pk' ? 'precio kit' : 'precio suelto') . ' debe ser un valor en pesos, sin decimales.'];
            $filas[$id][$col] = (float)$p;
        }
    }
    return ['filas' => $filas];
}

/** Guarda las filas (dentro de la transacción del cierre de dinero). */
function fvdl_guardar_filas(PDO $bdd, $idFormato, array $filas) {
    $up = $bdd->prepare("UPDATE formatos_vd_libros SET solicitado = ?, remisionado = ?, devuelto = ?, kit = ?, suelto = ?, sin_inventario = ?, precio_kit = ?, precio_suelto = ?
                         WHERE id = ? AND id_formato = ?");
    foreach ($filas as $r) {
        $up->execute([(int)$r['solicitado'], (int)$r['remisionado'], (int)$r['devuelto'], (int)$r['kit'], (int)$r['suelto'], (int)$r['sin_inventario'],
                      (float)$r['precio_kit'], (float)$r['precio_suelto'], (int)$r['id'], (int)$idFormato]);
    }
}

/** Gran total guardado de la tabla por grado del formato, o null si el formato no la usa. */
function fvdl_gran_total(PDO $bdd, array $f) {
    $filas = fvdl_filas($bdd, $f['id']);
    if (!$filas) return null;
    return fvdl_calcular($bdd, $f, $filas)['gran_total'];
}
