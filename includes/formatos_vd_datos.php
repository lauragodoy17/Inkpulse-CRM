<?php
/**
 * /includes/formatos_vd_datos.php
 * "Formato de venta directa" (menú Venta directa), pedido de la usuaria 2026-10-21: reemplaza el Excel con el
 * que se controla cada venta directa en un colegio. Se marcan los pedidos de venta Entregados de un colegio
 * cuyo canal de venta (Adopciones, recursos.id_canal) es "Venta directa" en el período del pedido.
 *
 * 2026-10-22: el formato es un FLUJO POR ETAPAS consecutivas (formatos_vd.etapa); cada una se habilita
 * cuando se confirma la anterior y el servidor no deja saltar etapas:
 *  1. Solicitud: datos generales de la venta y la solicitud por paquete/título (de los pedidos, no cambia).
 *  2. Entrega de bodega: aux. de bodega que entrega + firma, responsable de la venta + firma, total de cajas
 *     e inventario entregado (se propone lo despachado en WO armado en paquetes; editable).
 *  3. Devolución: unidades devueltas por fila (≤ entregado). En preparación = borrador guardado; al
 *     generarla se crea la legalización de siempre (devoluciones_v + saldo SAL-n, dvd_guardar_devolucion)
 *     ligada al formato y se CONFIRMA sola si el documento pasa la verificación (fvd_verificar_devolucion).
 *  4. Cierre del inventario: con la devolución documentada se CALCULA SOLO (fvd_avanzar_automatico, pedido
 *     de la usuaria 2026-10-22): vendidas = entregado − devueltas; inventario final = lo devuelto (sin
 *     novedades: las diferencias se explican en observaciones). Precio: paquete = paquetes_colegio.precio_final;
 *     título suelto = "Precio venta padre" de Adopciones (presupuestos.precio_venta_final). Si falta un precio
 *     se queda en esta etapa con la alerta y se vuelve a calcular al abrir el formato. Los precios quedan guardados.
 *     2026-11-23: si la fila salió en una remisión/factura de WO, manda el precio con el que salió (fvd_referencias).
 * Cada paso con firmas tiene su acta imprimible que arrastra lo anterior (ver_formato_venta_directa.php?acta=).
 *  5. Recepción en bodega: aux. que recibe + firma (independiente de la de entrega), fecha y hora y cantidad
 *     recibida por fila; si no cuadra con lo devuelto se exige justificación. Marca la devolución Recibido.
 *  6. Cierre de dinero: base, gastos, efectivo, datáfono, Bre-B; si la diferencia con el total de venta no es
 *     cero se exige justificarla para cerrar la operación (etapa 7).
 * Las firmas se dibujan en pantalla o se sube foto/PDF del papel firmado (formatos_vd_firmas, archivos en
 * adjuntos/formatos_vd/). Historial de etapas: historial_estados módulo 'formatos_vd_etapa' (estado = etapa).
 * Un formato se anula (con motivo, historial_estados módulo 'formatos_vd') solo antes de generar la devolución.
 * Mientras un formato esté activo sus pedidos no pueden entrar en otro.
 */
require_once __DIR__ . '/devoluciones_vd_datos.php';
require_once __DIR__ . '/historial_estados.php';
require_once __DIR__ . '/pedidos_venta_directa.php';
require_once __DIR__ . '/formatos_vd_libros.php'; // tabla por grado del cierre de ventas (2026-11-26)

const FVD_ESTADO_ACTIVO = 1;
const FVD_ESTADO_ANULADO = 3;
const FVD_MODULO_HISTORIAL = 'formatos_vd';
const FVD_MODULO_ETAPAS = 'formatos_vd_etapa';

const FVD_ETAPA_SOLICITUD = 1;
const FVD_ETAPA_ENTREGADO = 2;
const FVD_ETAPA_DEV_GENERADA = 3;
const FVD_ETAPA_DEV_CONFIRMADA = 4;
const FVD_ETAPA_CONCILIADO = 5;
const FVD_ETAPA_RECIBIDO = 6;
const FVD_ETAPA_CERRADO = 7;

/** Firmas del formato: tipo => quién firma. */
const FVD_FIRMAS = [
    'entrega_aux' => 'Auxiliar de bodega que entrega',
    'entrega_resp' => 'Responsable de la venta',
    'recibe_aux' => 'Auxiliar de bodega que recibe',
];
/** Tamaño máximo de la firma subida como archivo (foto/PDF). */
const FVD_FIRMA_MAX_BYTES = 8388608;

/** Tablas y columnas. Antes de cualquier transacción (DDL = commit implícito). */
function fvd_asegurar_tablas(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    dvd_asegurar_columnas($bdd);
    crear_tabla_historial_estados($bdd);
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consecutivo INT NOT NULL,
        id_colegio INT NOT NULL,
        id_periodo INT NOT NULL,
        ids_pedidos VARCHAR(500) NOT NULL,
        etapa TINYINT NOT NULL DEFAULT 1,
        fecha_venta DATE NOT NULL,
        hora_inicio TIME NULL,
        hora_fin TIME NULL,
        responsable VARCHAR(150) NULL,
        tel_responsable VARCHAR(40) NULL,
        contacto_colegio VARCHAR(150) NULL,
        tel_contacto VARCHAR(40) NULL,
        auxiliares VARCHAR(255) NULL,
        total_cajas INT NULL,
        direccion VARCHAR(255) NULL,
        aux_entrega VARCHAR(150) NULL,
        base_entregada DECIMAL(14,2) NOT NULL DEFAULT 0,
        gastos DECIMAL(14,2) NOT NULL DEFAULT 0,
        base_devuelta DECIMAL(14,2) NOT NULL DEFAULT 0,
        efectivo DECIMAL(14,2) NOT NULL DEFAULT 0,
        datafono DECIMAL(14,2) NOT NULL DEFAULT 0,
        breb DECIMAL(14,2) NOT NULL DEFAULT 0,
        suma_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_venta DECIMAL(14,2) NOT NULL DEFAULT 0,
        valor_inventario DECIMAL(14,2) NOT NULL DEFAULT 0,
        diferencia DECIMAL(14,2) NOT NULL DEFAULT 0,
        aux_recibe VARCHAR(150) NULL,
        recibido_at DATETIME NULL,
        observaciones TEXT NULL,
        obs_recepcion TEXT NULL,
        obs_cierre TEXT NULL,
        estado TINYINT NOT NULL DEFAULT 1,
        id_usuario INT NULL,
        token_registro VARCHAR(40) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_formatos_vd_consecutivo (consecutivo),
        UNIQUE KEY uniq_formatos_vd_token (token_registro),
        KEY idx_formatos_vd_colegio (id_colegio, id_periodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        orden INT NOT NULL DEFAULT 0,
        tipo VARCHAR(10) NOT NULL,
        id_paquete INT NULL,
        id_libro INT NULL,
        descripcion VARCHAR(255) NOT NULL,
        detalle TEXT NULL,
        solicitud INT NOT NULL DEFAULT 0,
        inv_propuesto INT NOT NULL DEFAULT 0,
        inv_inicial INT NOT NULL DEFAULT 0,
        devueltas INT NULL,
        inv_final INT NOT NULL DEFAULT 0,
        vendidas INT NOT NULL DEFAULT 0,
        recibidas INT NULL,
        precio DECIMAL(14,2) NOT NULL DEFAULT 0,
        precio_origen VARCHAR(30) NULL,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        libros VARCHAR(500) NULL,
        KEY idx_formatos_vd_items_formato (id_formato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Auxiliares de la venta con su valor por persona (paso 2, 2026-10-22); formatos_vd.auxiliares guarda los nombres.
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_auxiliares (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        orden INT NOT NULL DEFAULT 0,
        nombre VARCHAR(150) NOT NULL,
        valor DECIMAL(14,2) NOT NULL DEFAULT 0,
        KEY idx_formatos_vd_auxiliares (id_formato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $bdd->exec("CREATE TABLE IF NOT EXISTS formatos_vd_firmas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_formato INT NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        archivo VARCHAR(120) NOT NULL,
        origen VARCHAR(10) NOT NULL,
        id_usuario INT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_formatos_vd_firmas (id_formato, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    try { $bdd->exec("ALTER TABLE devoluciones_v ADD COLUMN id_formato_vd INT NULL DEFAULT NULL"); } catch (Exception $e) { /* ya existe */ }
    // Formato creado solo al registrar la devolución (2026-11-11, fvd_desde_devolucion): sin firmas de entrega ni recepción.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'automatico'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd ADD COLUMN automatico TINYINT NOT NULL DEFAULT 0 AFTER etapa"); } catch (Exception $e) { /* ya existe */ }
    }
    // Paquetes abiertos (2026-11-13): de la fila del paquete, cuántos se abrieron (no se cobran a precio de paquete;
    // sus libros van en filas FVD_TIPO_ABIERTO).
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'abiertos'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd_items ADD COLUMN abiertos INT NOT NULL DEFAULT 0 AFTER devueltas"); } catch (Exception $e) { /* ya existe */ }
    }
    // Producto de WO que cuenta como otro libro del pedido (dvd_equivalencias_pedido, 2026-11-19).
    $bdd->exec("CREATE TABLE IF NOT EXISTS pedidos_equivalencias_wo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pedido INT NOT NULL,
        producto_wo INT NOT NULL,
        id_libro INT NOT NULL,
        observacion VARCHAR(255) NULL,
        id_usuario INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pedido_producto (id_pedido, producto_wo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");
    // Armar paquetes (2026-11-19): fila del paquete = paquetes armados a mano con libros sueltos; fila del título =
    // unidades que se pasaron a paquetes (para poder deshacer el armado). Ver fvd_armar_paquetes.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'armados'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd_items ADD COLUMN armados INT NOT NULL DEFAULT 0 AFTER abiertos"); } catch (Exception $e) { /* ya existe */ }
    }
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'en_paquetes'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd_items ADD COLUMN en_paquetes INT NOT NULL DEFAULT 0 AFTER armados"); } catch (Exception $e) { /* ya existe */ }
    }
    // Precio editado a mano en el cierre de dinero (2026-11-19): manda sobre la lista de precios al recalcular.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'precio_manual'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd_items ADD COLUMN precio_manual DECIMAL(14,2) NULL AFTER precio_origen"); } catch (Exception $e) { /* ya existe */ }
    }
    // Formato con 2 devoluciones de venta directa (2026-11-24, la usuaria): devueltas_2 = unidades de la fila que volvieron en
    // la 2a devolución (ya incluidas en devueltas; solo informativo). Las VENDIDAS (entregado − todas las devoluciones, nunca
    // negativo) se pueden cobrar a dos precios: vendidas_2 = cuántas van a precio_2 (las escribe la usuaria en el cierre de
    // dinero, de 0 a vendidas); el resto va al precio de la fila. Total = (vendidas − vendidas_2) × precio + vendidas_2 × precio_2.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'devueltas_2'")->fetch()) {
        foreach (["ALTER TABLE formatos_vd_items ADD COLUMN devueltas_2 INT NOT NULL DEFAULT 0",
                  "ALTER TABLE formatos_vd_items ADD COLUMN precio_2 DECIMAL(14,2) NULL",
                  "ALTER TABLE formatos_vd_items ADD COLUMN precio_2_origen VARCHAR(30) NULL",
                  "ALTER TABLE formatos_vd_items ADD COLUMN precio_2_manual DECIMAL(14,2) NULL"] as $sql) {
            try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
        }
    }
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'vendidas_2'")->fetch()) {
        try { $bdd->exec("ALTER TABLE formatos_vd_items ADD COLUMN vendidas_2 INT NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ya existe */ }
    }
    // Referencias de la fila (2026-11-23, fvd_referencias): pedidos (ids), remisiones/facturas de WO y precio con que
    // salió en la remisión. NULL = aún no se consultó (fvd_asegurar_referencias); '' = sin datos.
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd_items LIKE 'remisiones'")->fetch()) {
        foreach (["ALTER TABLE formatos_vd_items ADD COLUMN pedidos_ref VARCHAR(255) NULL",
                  "ALTER TABLE formatos_vd_items ADD COLUMN remisiones VARCHAR(500) NULL",
                  "ALTER TABLE formatos_vd_items ADD COLUMN precio_remision DECIMAL(14,2) NULL"] as $sql) {
            try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
        }
    }
    // Tabla por grado del cierre de ventas: libros con Kit / Suelto y kits armados solos (2026-11-26, formatos_vd_libros.php).
    fvdl_asegurar_tabla($bdd);
    // Formatos creados antes del flujo por etapas (2026-10-22).
    if (!$bdd->query("SHOW COLUMNS FROM formatos_vd LIKE 'etapa'")->fetch()) {
        foreach (["ALTER TABLE formatos_vd ADD COLUMN etapa TINYINT NOT NULL DEFAULT 1 AFTER ids_pedidos",
                  "ALTER TABLE formatos_vd MODIFY responsable VARCHAR(150) NULL",
                  "ALTER TABLE formatos_vd ADD COLUMN recibido_at DATETIME NULL",
                  "ALTER TABLE formatos_vd ADD COLUMN obs_recepcion TEXT NULL",
                  "ALTER TABLE formatos_vd ADD COLUMN obs_cierre TEXT NULL",
                  "ALTER TABLE formatos_vd_items ADD COLUMN devueltas INT NULL AFTER inv_inicial",
                  "ALTER TABLE formatos_vd_items ADD COLUMN recibidas INT NULL AFTER vendidas",
                  "ALTER TABLE formatos_vd_items ADD COLUMN precio_origen VARCHAR(30) NULL AFTER precio"] as $sql) {
            try { $bdd->exec($sql); } catch (Exception $e) { /* ya existe */ }
        }
    }
}

/** "Formato venta directa No. 12". */
function fvd_numero($consecutivo) {
    return 'Formato venta directa No. ' . (int)$consecutivo;
}

/** En qué va el formato (lo que está pendiente). */
function fvd_etapa_texto($etapa) {
    return [
        FVD_ETAPA_SOLICITUD => 'Entrega de inventario pendiente',
        FVD_ETAPA_ENTREGADO => 'Devolución pendiente',
        FVD_ETAPA_DEV_GENERADA => 'Devolución generada · por confirmar',
        FVD_ETAPA_DEV_CONFIRMADA => 'Cierre de inventario pendiente',
        FVD_ETAPA_CONCILIADO => 'Recepción en bodega pendiente',
        FVD_ETAPA_RECIBIDO => 'Cierre de dinero pendiente',
        FVD_ETAPA_CERRADO => 'Operación cerrada',
    ][(int)$etapa] ?? '—';
}

/** Lo que pasó al llegar a cada etapa (historial). */
function fvd_etapa_evento($etapa) {
    return [
        FVD_ETAPA_SOLICITUD => 'Solicitud registrada',
        FVD_ETAPA_ENTREGADO => 'Inventario entregado por bodega y recibido por el responsable de la venta',
        FVD_ETAPA_DEV_GENERADA => 'Devolución generada en InkPulse',
        FVD_ETAPA_DEV_CONFIRMADA => 'Devolución confirmada',
        FVD_ETAPA_CONCILIADO => 'Inventario conciliado',
        FVD_ETAPA_RECIBIDO => 'Devolución recibida por bodega',
        FVD_ETAPA_CERRADO => 'Cierre de dinero confirmado · operación cerrada',
    ][(int)$etapa] ?? '—';
}

/** Clave de la fila: 'P12' (paquete) o 'L34' (título suelto). */
function fvd_clave_item(array $it) {
    if ($it['tipo'] === 'suelto_paq') return 'X' . (int)$it['id_libro']; // libro suelto de un paquete (FVD_TIPO_SUELTO_PAQ)
    if ($it['tipo'] === 'abierto') return 'A' . (int)$it['id_paquete'] . '_' . (int)$it['id_libro']; // libro de un paquete abierto (FVD_TIPO_ABIERTO)
    return $it['tipo'] === 'paquete' ? 'P' . (int)$it['id_paquete'] : 'L' . (int)$it['id_libro'];
}

/**
 * Pedidos en un formato activo (cualquier etapa): [clave => ['id','consecutivo']]. Un pedido en un formato
 * activo no puede entrar en otro; se continúa desde ese formato.
 */
function fvd_pendientes_por_pedido(PDO $bdd) {
    $res = [];
    foreach ($bdd->query("SELECT id, consecutivo, ids_pedidos FROM formatos_vd WHERE estado = " . FVD_ESTADO_ACTIVO) as $f) {
        foreach (array_filter(explode(',', (string)$f['ids_pedidos'])) as $c) $res[$c] = ['id' => (int)$f['id'], 'consecutivo' => (int)$f['consecutivo']];
    }
    return $res;
}

/** Devoluciones activas vinculadas al formato, de la más antigua a la más reciente: [['id','codigo','ids_pedidos','estado','fecha']]. */
function fvd_devoluciones_formato(PDO $bdd, $idFormato) {
    $st = $bdd->prepare("SELECT id, codigo, COALESCE(ids_pedidos, ids_pedidos_venta) AS ids_pedidos, estado, created_at AS fecha FROM devoluciones_v
                         WHERE id_formato_vd = ? AND estado <> " . DVD_ESTADO_DEVOL_ANULADA . " ORDER BY id");
    $st->execute([(int)$idFormato]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Legalización (devolución) activa del formato: la más reciente ['id','codigo','ids_pedidos','estado','fecha'] o null.
 * 2026-11-24 (la usuaria): en venta directa un pedido admite 2 devoluciones y ambas quedan en el mismo formato; además
 * trae 'ids' / 'codigos' / 'todas' (todas las activas) y 'texto' ("#120, #134") para mostrarlas.
 */
function fvd_legalizacion(PDO $bdd, $idFormato) {
    $devs = fvd_devoluciones_formato($bdd, $idFormato);
    if (!$devs) return null;
    $d = end($devs);
    $d['ids'] = array_map(fn($x) => (int)$x['id'], $devs);
    $d['codigos'] = array_column($devs, 'codigo');
    $d['todas'] = $devs;
    $d['texto'] = '#' . implode(', #', $d['ids']);
    return $d;
}

/** "la devolución #120" / "las devoluciones #120, #134" de la legalización del formato (fvd_legalizacion); '' sin ella. */
function fvd_texto_devoluciones($leg, $conArticulo = true) {
    if (!$leg) return '';
    $varias = count($leg['ids'] ?? []) > 1;
    return ($conArticulo ? ($varias ? 'las ' : 'la ') : '') . ($varias ? 'devoluciones ' : 'devolución ') . ($leg['texto'] ?? '#' . (int)$leg['id']);
}

/** Enlaces a las devoluciones del formato ("#120, #134"), HTML. */
function fvd_links_devoluciones($leg) {
    if (!$leg) return '';
    return implode(', ', array_map(fn($id) => '<a href="devolucion_colegio.php?id_pedido=' . (int)$id . '">#' . (int)$id . '</a>', $leg['ids'] ?? [(int)$leg['id']]));
}

/** Paquetes del colegio en el período con sus libros: [['id','paquete','codigo','precio','libros' => [id_libro,...], 'titulos' => [...]]]. */
function fvd_paquetes_colegio(PDO $bdd, $idColegio, $idPeriodo) {
    if (!$bdd->query("SHOW TABLES LIKE 'paquetes_colegio'")->fetch()) return [];
    $st = $bdd->prepare("SELECT id, paquete, codigo, precio_final FROM paquetes_colegio WHERE id_colegio = ? AND id_periodo = ? ORDER BY id_grado, numero, id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $paquetes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paquetes[(int)$p['id']] = ['id' => (int)$p['id'], 'paquete' => $p['paquete'], 'codigo' => $p['codigo'], 'precio' => (float)$p['precio_final'], 'libros' => [], 'titulos' => []];
    }
    if (!$paquetes) return [];
    $st = $bdd->query("SELECT pl.id_paquete, pl.id_libro, l.libro FROM paquetes_colegio_libros pl JOIN libros l ON l.id = pl.id_libro
                       WHERE pl.id_paquete IN (" . implode(',', array_keys($paquetes)) . ") ORDER BY pl.id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = &$paquetes[(int)$r['id_paquete']];
        if (!in_array((int)$r['id_libro'], $p['libros'], true)) { $p['libros'][] = (int)$r['id_libro']; $p['titulos'][] = $r['libro']; }
        unset($p);
    }
    return array_values(array_filter($paquetes, fn($p) => $p['libros']));
}

/**
 * Arma paquetes completos con unidades por libro: cada paquete, en orden, se lleva tantas unidades como
 * alcance el título que menos tenga, y se descuentan. Devuelve [[id_paquete => paquetes], [id_libro => sobrante]].
 */
function fvd_repartir_en_paquetes(array $unidades, array $paquetes) {
    $cuantos = [];
    foreach ($paquetes as $p) {
        $n = min(array_map(fn($l) => (int)($unidades[$l] ?? 0), $p['libros']));
        $cuantos[$p['id']] = max(0, $n);
        if ($n > 0) foreach ($p['libros'] as $l) $unidades[$l] -= $n;
    }
    return [$cuantos, array_filter($unidades, fn($n) => $n > 0)];
}

/**
 * Propuesta del formato para los pedidos elegidos: datos del colegio y filas por paquete/título con
 * solicitud e inventario a entregar propuesto (despachado WO o solicitado CRM). Devuelve ['ok','error',
 * 'id_colegio','id_periodo','colegio','cliente','claves','pedidos' => [...],'items' => [...], 'sin_catalogo' => n].
 */
function fvd_propuesta(PDO $bdd, array $claves) {
    $claves = array_values(array_unique(array_filter(array_map('dvd_clave_valida', $claves))));
    if (!$claves) return ['ok' => false, 'error' => 'Elige al menos un pedido.'];
    $pendientes = fvd_pendientes_por_pedido($bdd);
    foreach ($claves as $c) {
        if (isset($pendientes[$c])) return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($c) . ' ya está en el ' . fvd_numero($pendientes[$c]['consecutivo']) . ', que sigue activo.'];
    }
    $detalle = dvd_detalle_varios($bdd, $claves);
    if (!$detalle['ok']) return $detalle;
    if (!$detalle['incluidos']) return ['ok' => false, 'error' => 'Ninguno de los pedidos elegidos tiene títulos por legalizar.'];
    $claves = $detalle['incluidos'];

    // Unidades por libro: a entregar (despachado WO o solicitado CRM) y solicitud (libros_pedidos).
    $inicial = [];
    $nombreLibro = [];
    $sinCatalogo = 0;
    // 2026-11-19 (la usuaria): entregado = solo lo remisionado en WO. Un producto de paquete en WO (código =
    // paquetes_colegio.codigo) es la fila de ese paquete; los libros remisionados quedan como títulos sueltos (ya no se
    // arman paquetes con ellos ni se suma lo pedido en paquete en el CRM), igual que en la devolución (dvv_vd_paquetes_wo).
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_paquetes_por_codigo
    $paqPorCodigo = dvv_paquetes_por_codigo($bdd, $detalle['id_colegio'], $detalle['id_periodo']);
    $paqIni = [];
    foreach ($detalle['titulos'] as $t) {
        $pWo = $t['id_libro'] ? null : ($paqPorCodigo[dvv_codigo_norm($t['codigo'])] ?? null);
        if ($pWo) {
            foreach ($t['partes'] as $pa) {
                if ($pa['cerrado'] || !in_array($pa['clave_pedido'], $claves, true)) continue;
                $paqIni[$pWo['id']] = ($paqIni[$pWo['id']] ?? 0) + (int)$pa['base'];
            }
            continue;
        }
        if (!$t['id_libro']) { $sinCatalogo += (int)$t['base']; continue; }
        $id = (int)$t['id_libro'];
        $nombreLibro[$id] = $t['descripcion'];
        foreach ($t['partes'] as $pa) {
            if ($pa['cerrado'] || !in_array($pa['clave_pedido'], $claves, true)) continue;
            $inicial[$id] = ($inicial[$id] ?? 0) + (int)$pa['base'];
        }
    }
    $solicitud = [];
    $codigos = [];
    $infoPedidos = [];
    foreach ($claves as $c) {
        $p = dvd_obtener_pedido($bdd, $c);
        $codigos[] = (string)$p['codigo'];
        $infoPedidos[] = ['clave' => $c, 'id' => (int)$p['id'], 'fecha' => $p['fecha']];
    }
    $st = $bdd->prepare("SELECT lp.id_libro, SUM(lp.cantidad) AS n, l.libro FROM libros_pedidos lp JOIN libros l ON l.id = lp.id_libro
                         WHERE lp.cod_pedido IN (" . implode(',', array_fill(0, count($codigos), '?')) . ") GROUP BY lp.id_libro HAVING n > 0");
    $st->execute($codigos);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $solicitud[(int)$r['id_libro']] = (int)$r['n'];
        $nombreLibro[(int)$r['id_libro']] = $nombreLibro[(int)$r['id_libro']] ?? $r['libro'];
    }

    $paquetes = fvd_paquetes_colegio($bdd, $detalle['id_colegio'], $detalle['id_periodo']);
    // Solo los paquetes que se pidieron (libros_pedidos.id_paquete), como en la devolución (2026-11-13): si un libro está
    // en dos paquetes del colegio (ej. 8° y 9°), repartir entre todos lo ponía en el paquete equivocado y la devolución
    // ya no se podía vincular ("trae un paquete que no está en el formato").
    $st = $bdd->prepare("SELECT DISTINCT id_paquete FROM libros_pedidos WHERE id_paquete IS NOT NULL AND id_paquete > 0 AND cantidad > 0
                         AND cod_pedido IN (" . implode(',', array_fill(0, count($codigos), '?')) . ")");
    $st->execute($codigos);
    $paqPedidos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $paquetes = array_values(array_filter($paquetes, fn($p) => in_array((int)$p['id'], $paqPedidos, true) || !empty($paqIni[$p['id']])));
    // La solicitud sí se muestra por paquete (lo pedido); lo que sobra se pasa al libros.id del título remisionado del
    // mismo producto (libro repetido en el catálogo), para que quede en la misma fila.
    fvd_unificar_con_paquetes($bdd, $paquetes, $solicitud, $nombreLibro);
    [$paqSol, $sobraSol] = fvd_repartir_en_paquetes($solicitud, $paquetes);
    if ($inicial) fvd_unificar_con_paquetes($bdd, [['libros' => array_keys($inicial)]], $sobraSol, $nombreLibro);
    $sobraIni = array_filter($inicial, fn($n) => $n > 0);

    $items = [];
    foreach ($paquetes as $p) {
        $ini = $paqIni[$p['id']] ?? 0;
        $sol = $paqSol[$p['id']] ?? 0;
        if (!$ini && !$sol) continue;
        $items[] = ['clave' => 'P' . $p['id'], 'tipo' => 'paquete', 'id_paquete' => $p['id'], 'id_libro' => null,
                    'descripcion' => $p['paquete'], 'detalle' => implode(' + ', $p['titulos']), 'libros' => $p['libros'],
                    'solicitud' => $sol, 'inv_propuesto' => $ini];
    }
    $sueltos = array_keys($sobraIni + $sobraSol);
    usort($sueltos, fn($a, $b) => strcasecmp($nombreLibro[$a] ?? '', $nombreLibro[$b] ?? ''));
    foreach ($sueltos as $id) {
        $items[] = ['clave' => 'L' . $id, 'tipo' => 'titulo', 'id_paquete' => null, 'id_libro' => $id,
                    'descripcion' => $nombreLibro[$id] ?? ('Libro #' . $id), 'detalle' => '', 'libros' => [$id],
                    'solicitud' => (int)($sobraSol[$id] ?? 0), 'inv_propuesto' => (int)($sobraIni[$id] ?? 0)];
    }
    // Pedido, remisiones y precio de la remisión de cada fila (2026-11-23).
    foreach (fvd_referencias($bdd, $detalle, $items) as $i => $r) $items[$i] += $r;

    return ['ok' => true, 'error' => null, 'id_colegio' => $detalle['id_colegio'], 'id_periodo' => $detalle['id_periodo'],
            'colegio' => $detalle['colegio'], 'cliente' => $detalle['cliente'], 'claves' => $claves, 'pedidos' => $infoPedidos,
            'items' => $items, 'con_paquetes' => (bool)$paquetes, 'sin_catalogo' => $sinCatalogo];
}

/**
 * Libro repetido en el catálogo (2026-11-19): WO puede traer un libro con un libros.id (p. ej. 1479) y el paquete tenerlo
 * con otro del mismo producto (1725, ambos producto WO 3438). Las unidades de $unidades [id_libro => n] se pasan al
 * libros.id que usa el paquete cuando son el mismo producto (id_wo, o ISBN si no tiene), para que fvd_repartir_en_paquetes
 * las encuentre. Por referencia.
 */
function fvd_unificar_con_paquetes(PDO $bdd, array $paquetes, array &$unidades, array &$nombreLibro) {
    $enPaq = [];
    foreach ($paquetes as $p) foreach ($p['libros'] as $l) $enPaq[(int)$l] = true;
    $ids = array_unique(array_merge(array_keys($enPaq), array_map('intval', array_keys($unidades))));
    if (!$enPaq || !$unidades) return;
    $clave = [];
    foreach ($bdd->query("SELECT id, id_wo, TRIM(isbn) AS isbn FROM libros WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $clave[(int)$l['id']] = (int)$l['id_wo'] > 0 ? 'w' . (int)$l['id_wo'] : ((string)$l['isbn'] !== '' ? 'i' . $l['isbn'] : 'l' . (int)$l['id']);
    }
    $paqDeClave = [];
    foreach (array_keys($enPaq) as $l) if (isset($clave[$l]) && !isset($paqDeClave[$clave[$l]])) $paqDeClave[$clave[$l]] = $l;
    foreach (array_keys($unidades) as $l) {
        $l = (int)$l;
        if (isset($enPaq[$l]) || !isset($clave[$l]) || !isset($paqDeClave[$clave[$l]])) continue;
        $destino = $paqDeClave[$clave[$l]];
        $unidades[$destino] = ($unidades[$destino] ?? 0) + $unidades[$l];
        $nombreLibro[$destino] = $nombreLibro[$destino] ?? ($nombreLibro[$l] ?? null);
        unset($unidades[$l]);
    }
}

/**
 * Referencias de cada fila (2026-11-23, la usuaria): los pedidos de los que sale (columna Solicitud), las remisiones /
 * facturas de WO con las que salió (columna Inventario entregado) y el precio con el que salió en la remisión (precio de
 * venta de la fila, fvd_precios_actuales; si viene en varias con precios distintos, el promedio ponderado por cantidad).
 * $detalle = dvd_detalle_varios de los pedidos del formato; $items = filas del formato o de la propuesta.
 * Devuelve [índice de $items => ['pedidos_ref' => '2089,2090', 'remisiones' => 'CEUR 7751, ...', 'precio_remision' => float|null]].
 */
function fvd_referencias(PDO $bdd, array $detalle, array $items) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_paquetes_por_codigo, dvv_claves_catalogo
    $paqPorCodigo = dvv_paquetes_por_codigo($bdd, $detalle['id_colegio'], $detalle['id_periodo']);
    $todos = array_values(array_unique(array_map(fn($p) => (int)$p['id'], $detalle['pedidos'])));
    // Lo solicitado en el CRM: pedido por paquete / libro.
    $solicitados = [];
    if ($todos) {
        $st = $bdd->query("SELECT x.id AS id_pedido, lp.id_libro, lp.id_paquete FROM libros_pedidos lp JOIN pedidos x ON x.codigo = lp.cod_pedido
                           WHERE x.id IN (" . implode(',', $todos) . ") AND lp.cantidad > 0");
        $solicitados = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $idsLib = array_merge(array_column($solicitados, 'id_libro'), array_column($items, 'id_libro'));
    foreach ($detalle['titulos'] as $t) if ($t['id_libro']) $idsLib[] = (int)$t['id_libro'];
    $cat = dvv_claves_catalogo($bdd, $idsLib);
    $claveLib = fn($l) => 'L' . ($cat[(int)$l] ?? 'l' . (int)$l);

    $ref = [];
    $sumar = function ($k, $idPedido, array $docs, $base, $precio) use (&$ref) {
        $ref[$k] = $ref[$k] ?? ['pedidos' => [], 'docs' => [], 'n' => 0, 'valor' => 0];
        if ($idPedido) $ref[$k]['pedidos'][$idPedido] = $idPedido;
        foreach ($docs as $d) $ref[$k]['docs'][$d] = $d;
        if ($precio !== null && (float)$precio > 0 && $base > 0) { $ref[$k]['n'] += $base; $ref[$k]['valor'] += $base * (float)$precio; }
    };
    foreach ($detalle['titulos'] as $t) {
        if ($t['id_libro']) $k = $claveLib($t['id_libro']);
        else {
            $p = $paqPorCodigo[dvv_codigo_norm($t['codigo'])] ?? null; // producto de paquete en WO
            if (!$p) continue;
            $k = 'P' . (int)$p['id'];
        }
        foreach ($t['partes'] as $pa) {
            if ((int)$pa['base'] <= 0) continue;
            $sumar($k, (int)$pa['id_pedido'], array_filter(explode(', ', (string)($pa['documentos'] ?? ''))), (int)$pa['base'], $pa['precio_wo']['precio_final'] ?? null);
        }
    }
    foreach ($solicitados as $r) {
        $sumar((int)$r['id_paquete'] > 0 ? 'P' . (int)$r['id_paquete'] : $claveLib($r['id_libro']), (int)$r['id_pedido'], [], 0, null);
    }

    $res = [];
    foreach ($items as $i => $it) {
        $ks = [];
        if (in_array($it['tipo'], ['paquete', FVD_TIPO_ABIERTO], true)) $ks[] = 'P' . (int)$it['id_paquete'];
        elseif ($it['tipo'] === 'titulo') $ks[] = $claveLib($it['id_libro']);
        else { // libro suelto de un paquete: el de los paquetes que lo traen
            foreach ($items as $o) {
                $libros = is_array($o['libros']) ? $o['libros'] : explode(',', (string)$o['libros']);
                if ($o['tipo'] === 'paquete' && in_array((int)$it['id_libro'], array_map('intval', $libros), true)) $ks[] = 'P' . (int)$o['id_paquete'];
            }
        }
        $peds = [];
        $docs = [];
        foreach ($ks as $k) { $peds += $ref[$k]['pedidos'] ?? []; $docs += $ref[$k]['docs'] ?? []; }
        if (!$peds) $peds = array_combine($todos, $todos);
        ksort($peds);
        natcasesort($docs);
        $r = count($ks) === 1 ? ($ref[$ks[0]] ?? null) : null;
        $res[$i] = ['pedidos_ref' => implode(',', $peds), 'remisiones' => mb_substr(implode(', ', $docs), 0, 500),
                    // Solo paquetes y títulos remisionados; los libros de paquetes abiertos / sueltos siguen su propio precio.
                    'precio_remision' => in_array($it['tipo'], ['paquete', 'titulo'], true) && $r && $r['n'] > 0 ? round($r['valor'] / $r['n'], 2) : null];
    }
    return $res;
}

/**
 * Formatos guardados antes del 2026-11-23 o filas agregadas después (paquetes armados, libros de paquetes abiertos): consulta
 * una vez sus referencias (fvd_referencias) y las guarda. Si el formato sigue abierto con el cierre del inventario ya
 * calculado, lo recalcula con el precio de la remisión. Devuelve true si guardó algo (hay que volver a leer el formato).
 */
function fvd_asegurar_referencias(PDO $bdd, array $f) {
    // Se vuelve a consultar si ninguna fila tiene remisión (2026-11-23, formato No. 2: la remisión no traía la OP en WO);
    // así aparecen apenas se asocie en WO o en OP pendiente.
    if (!$f['items'] || trim((string)$f['ids_pedidos']) === '') return false; // consecutivo reservado sin pedidos (2026-11-24)
    $sinConsultar = array_filter($f['items'], fn($it) => $it['remisiones'] === null);
    $sinRemision = !array_filter($f['items'], fn($it) => (string)$it['remisiones'] !== '');
    if (!$sinConsultar && !$sinRemision) return false;
    $detalle = dvd_detalle_varios($bdd, explode(',', $f['ids_pedidos']));
    if (!$detalle['ok']) return false; // WO no respondió o el pedido ya no está en el alcance: se reintenta la próxima vez
    $refs = fvd_referencias($bdd, $detalle, $f['items']);
    $up = $bdd->prepare("UPDATE formatos_vd_items SET pedidos_ref = ?, remisiones = ?, precio_remision = ? WHERE id = ? AND id_formato = ?");
    $cambio = false;
    $cambioPrecio = false;
    foreach ($f['items'] as $i => $it) {
        $r = $refs[$i];
        $precioAntes = $it['precio_remision'] === null ? null : round((float)$it['precio_remision'], 2);
        if ($it['remisiones'] !== null && (string)$it['pedidos_ref'] === $r['pedidos_ref'] && (string)$it['remisiones'] === $r['remisiones'] && $precioAntes === $r['precio_remision']) continue;
        $up->execute([$r['pedidos_ref'], $r['remisiones'], $r['precio_remision'], (int)$it['id'], (int)$f['id']]);
        $cambio = true;
        if ($precioAntes !== $r['precio_remision']) $cambioPrecio = true;
    }
    if ($cambioPrecio && $f['activo'] && $f['etapa'] >= FVD_ETAPA_CONCILIADO && $f['etapa'] < FVD_ETAPA_CERRADO) fvd_recalcular_cierre($bdd, $f['id']);
    return $cambio;
}

/**
 * Recalcula vendidas, precios y totales de un formato con el cierre del inventario ya calculado (precios vigentes:
 * editado > remisión > lista). Devuelve ['ok','error'].
 */
function fvd_recalcular_cierre(PDO $bdd, $id) {
    $g = fvd_obtener($bdd, $id);
    [$filas, $t] = fvd_conciliar($g, fvd_precios_actuales($bdd, $g));
    if ($t['sin_precio']) {
        return ['ok' => false, 'error' => 'falta el precio de venta de ' . implode(', ', array_column(array_filter($filas, fn($x) => $x['sin_precio']), 'descripcion')) . '. Configúralo en Adopciones y vuelve a guardar.'];
    }
    // Con la tabla por grado del cierre (2026-11-26) el total de venta es su gran total.
    $gran = fvdl_gran_total($bdd, $g);
    if ($gran !== null) $t['total_venta'] = $gran;
    return fvd_en_transaccion($bdd, $g['id'], $g['etapa'], function () use ($bdd, $g, $filas, $t) {
        $up = $bdd->prepare("UPDATE formatos_vd_items SET vendidas = ?, vendidas_2 = ?, inv_final = ?, precio = ?, precio_origen = ?, precio_2 = ?, precio_2_origen = ?, total = ? WHERE id = ? AND id_formato = ?");
        foreach ($filas as $x) $up->execute([$x['vend'], $x['n2'], $x['fin'], (float)$x['precio_v'], $x['origen_v'], $x['precio2_v'], $x['origen2_v'], (float)$x['total_v'], (int)$x['id'], (int)$g['id']]);
        $bdd->prepare("UPDATE formatos_vd SET total_venta = ?, valor_inventario = ?, diferencia = ROUND(suma_total - ?, 2) WHERE id = ?")
            ->execute([$t['total_venta'], $t['valor_inventario'], $t['total_venta'], (int)$g['id']]);
        return null;
    }, 'recalcular cierre');
}

/** Número de la adopción del colegio en el período (presupuestos.conse, uno por colegio y período), o null. */
function fvd_numero_adopcion(PDO $bdd, $idColegio, $idPeriodo) {
    $st = $bdd->prepare("SELECT MAX(conse) FROM presupuestos WHERE id_colegio = ? AND id_periodo = ? AND definido = 1");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $n = $st->fetchColumn();
    return $n !== null && $n !== false && (string)$n !== '' && (string)$n !== '0' ? (string)$n : null;
}

/** "#2089, #2090" de formatos_vd_items.pedidos_ref. */
function fvd_pedidos_texto($ref) {
    return implode(', ', array_map(fn($id) => '#' . (int)$id, array_filter(explode(',', (string)$ref))));
}

/** Encabezado de la columna Solicitud del reporte (2026-11-23): los pedidos de Inkpulse del formato, "#2089, #2090". */
function fvd_encabezado_solicitud(array $f) {
    return implode(', ', array_map(fn($c) => '#' . (int)substr($c, 1), array_filter(explode(',', (string)$f['ids_pedidos']))));
}

/**
 * Encabezado de la columna Inventario entregado: las remisiones/facturas de WO de todas las filas ("CEUR 7751, CEUR 7760"),
 * "Sin remisión en WO" si se consultó y no hay, o '' si aún no se ha consultado.
 */
function fvd_encabezado_remisiones(array $f) {
    $docs = [];
    $consultado = false;
    foreach ($f['items'] as $it) {
        if ($it['remisiones'] === null) continue;
        $consultado = true;
        foreach (array_filter(explode(', ', (string)$it['remisiones'])) as $d) $docs[$d] = $d;
    }
    natcasesort($docs);
    return $docs ? implode(', ', $docs) : ($consultado ? 'Sin remisión en WO' : '');
}

require_once __DIR__ . '/plan_lector.php'; // MATERIA_PLAN_LECTOR, orden_texto_cmp
/** Materia "Plan lector" (materias.id). */
const FVD_MATERIA_PLAN_LECTOR = MATERIA_PLAN_LECTOR;

/**
 * Curso de cada libro según la ADOPCIÓN del colegio en el período (2026-11-23, la usuaria), con la misma regla de
 * php/adopcion_excel.php: si la línea de la adopción tiene área objetiva (presupuestos.cod_area) el curso es el del área
 * (areas_objetivas.id_grado_otro); si no, el del libro (libros.id_grado). Plan lector = área o libro con materia Plan lector.
 * Un libro adoptado en varios cursos queda en el menor. Se cruza por clave de catálogo (libros repetidos, dvv_claves_catalogo).
 * Libro que no está en la adopción: el curso y la materia del libro. Devuelve [id_libro => ['id_grado','grado','plan_lector']].
 */
function fvd_cursos_adopcion(PDO $bdd, $idColegio, $idPeriodo, array $idsLibro) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_claves_catalogo
    $idsLibro = array_values(array_unique(array_filter(array_map('intval', $idsLibro))));
    if (!$idsLibro) return [];
    $grados = $bdd->query("SELECT id, grado FROM grados")->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $bdd->prepare("SELECT p.id_libro, IF(COALESCE(ao.id_grado_otro, 0) > 0, ao.id_grado_otro, l.id_grado) AS id_grado,
                                (COALESCE(ao.id_materia, 0) = " . FVD_MATERIA_PLAN_LECTOR . " OR l.id_materia = " . FVD_MATERIA_PLAN_LECTOR . ") AS plan_lector
                         FROM presupuestos p JOIN libros l ON l.id = p.id_libro
                         LEFT JOIN areas_objetivas ao ON ao.codigo = p.cod_area AND p.cod_area <> '' AND ao.id_colegio = p.id_colegio AND ao.id_periodo = p.id_periodo
                         WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = 1");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $adop = $st->fetchAll(PDO::FETCH_ASSOC);
    $cat = dvv_claves_catalogo($bdd, array_merge($idsLibro, array_column($adop, 'id_libro')));
    $porClave = [];
    foreach ($adop as $r) {
        $k = $cat[(int)$r['id_libro']] ?? null;
        if ($k === null) continue;
        $g = (int)$r['id_grado'];
        if (!isset($porClave[$k]) || $g < $porClave[$k]['id_grado']) $porClave[$k] = ['id_grado' => $g, 'plan_lector' => (bool)$r['plan_lector']];
    }
    $libros = [];
    foreach ($bdd->query("SELECT id, id_grado, id_materia FROM libros WHERE id IN (" . implode(',', $idsLibro) . ")")->fetchAll(PDO::FETCH_ASSOC) as $l) $libros[(int)$l['id']] = $l;
    $res = [];
    foreach ($idsLibro as $id) {
        $x = $porClave[$cat[$id] ?? ''] ?? (isset($libros[$id]) ? ['id_grado' => (int)$libros[$id]['id_grado'], 'plan_lector' => (int)$libros[$id]['id_materia'] === FVD_MATERIA_PLAN_LECTOR] : null);
        if (!$x) continue;
        $res[$id] = $x + ['grado' => trim((string)($grados[$x['id_grado']] ?? ''))];
    }
    return $res;
}

/**
 * Filas del reporte (2026-11-23, la usuaria): paquetes; luego los títulos sueltos divididos por curso de la adopción
 * (fvd_cursos_adopcion); en cada curso los sueltos y después el PLAN LECTOR en su propio apartado; cada uno en orden
 * alfabético; al final los libros de paquetes abiertos / sueltos de paquete.
 * Devuelve [['curso' => 'Libros sueltos · 3 (Tercero)'] | ['item' => fila]].
 */
function fvd_filas_por_curso(PDO $bdd, array $f) {
    $items = $f['items'];
    $titulos = array_values(array_filter($items, fn($it) => $it['tipo'] === 'titulo'));
    if (!$titulos) return array_map(fn($it) => ['item' => $it], $items);
    $cursos = fvd_cursos_adopcion($bdd, $f['id_colegio'], $f['id_periodo'], array_column($titulos, 'id_libro'));
    $grupos = [];
    foreach ($titulos as $it) {
        $c = $cursos[(int)$it['id_libro']] ?? null;
        $conCurso = $c && $c['grado'] !== '';
        $plan = $c && $c['plan_lector'];
        // Orden: por curso (grados.id; sin curso al final) y en cada curso los libros sueltos antes que el plan lector.
        $k = sprintf('%09d-%d', $conCurso ? $c['id_grado'] : 999999999, $plan ? 1 : 0);
        $grupos[$k] = $grupos[$k] ?? ['curso' => ($plan ? 'Plan lector · ' : 'Libros sueltos · ') . ($conCurso ? $c['grado'] : 'Sin curso'), 'items' => []];
        $grupos[$k]['items'][] = $it;
    }
    ksort($grupos);
    $res = [];
    foreach ($items as $it) if ($it['tipo'] === 'paquete') $res[] = ['item' => $it];
    foreach ($grupos as $g) {
        usort($g['items'], fn($a, $b) => orden_texto_cmp($a['descripcion'], $b['descripcion'])); // alfabético sin distinguir tildes
        $res[] = ['curso' => $g['curso']];
        foreach ($g['items'] as $it) $res[] = ['item' => $it];
    }
    foreach ($items as $it) if (!in_array($it['tipo'], ['paquete', 'titulo'], true)) $res[] = ['item' => $it];
    return $res;
}

/** Formato con sus filas, firmas, colegio, período, legalización y quién lo registró, dentro del alcance del usuario; o null. */
function fvd_obtener(PDO $bdd, $id) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT f.*, c.colegio, c.dane, pe.periodo, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM formatos_vd f JOIN colegios c ON c.id = f.id_colegio
                         LEFT JOIN periodos pe ON pe.id = f.id_periodo
                         LEFT JOIN usuarios u ON u.id = f.id_usuario
                         WHERE f.id = ?" . $alcance);
    $st->execute(array_merge([(int)$id], $params));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;
    $st = $bdd->prepare("SELECT * FROM formatos_vd_items WHERE id_formato = ? ORDER BY orden, id");
    $st->execute([(int)$id]);
    $f['items'] = array_map(fn($it) => $it + ['clave' => fvd_clave_item($it)], $st->fetchAll(PDO::FETCH_ASSOC));
    $f['etapa'] = (int)$f['etapa'];
    $f['automatico'] = !empty($f['automatico']);
    $f['activo'] = (int)$f['estado'] === FVD_ESTADO_ACTIVO;
    $f['legalizacion'] = fvd_legalizacion($bdd, $id);
    $f['firmas'] = fvd_firmas($bdd, $id);
    $st = $bdd->prepare("SELECT nombre, valor FROM formatos_vd_auxiliares WHERE id_formato = ? ORDER BY orden, id");
    $st->execute([(int)$id]);
    $f['auxiliares_lista'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $f['auxiliares_total'] = array_sum(array_column($f['auxiliares_lista'], 'valor'));
    $f['numero_adopcion'] = fvd_numero_adopcion($bdd, $f['id_colegio'], $f['id_periodo']);
    // Se anula solo antes de generar la devolución.
    $f['anulable'] = $f['activo'] && $f['etapa'] <= FVD_ETAPA_ENTREGADO && !$f['legalizacion'];
    // Consecutivo reservado el día de la venta, aún sin pedidos (2026-11-24, includes/solicitudes_vd_datos.php): se llena al
    // registrar su primera devolución eligiendo esta venta.
    $f['reservado'] = trim((string)$f['ids_pedidos']) === '';
    return $f;
}

/**
 * Huella del estado del formato (2026-11-24, la usuaria: la hoja ver_formato_venta_directa.php se actualiza en vivo): cambia
 * cuando cambia algo que se muestra (etapa, datos, cierre de dinero, filas, devoluciones vinculadas). La hoja la consulta
 * cada pocos segundos (ajax/formatos_vd_avance.php) y, si cambió, se vuelve a pintar. null si el formato no existe.
 */
function fvd_huella(PDO $bdd, $idFormato) {
    $st = $bdd->prepare("SELECT * FROM formatos_vd WHERE id = ?");
    $st->execute([(int)$idFormato]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;
    $st = $bdd->prepare("SELECT * FROM formatos_vd_items WHERE id_formato = ? ORDER BY id");
    $st->execute([(int)$idFormato]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $bdd->prepare("SELECT id, estado FROM devoluciones_v WHERE id_formato_vd = ? ORDER BY id");
    $st->execute([(int)$idFormato]);
    $devs = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $bdd->prepare("SELECT tipo, nombre, created_at FROM formatos_vd_firmas WHERE id_formato = ? ORDER BY id");
    $st->execute([(int)$idFormato]);
    $firmas = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $bdd->prepare("SELECT nombre, valor FROM formatos_vd_auxiliares WHERE id_formato = ? ORDER BY id");
    $st->execute([(int)$idFormato]);
    $aux = $st->fetchAll(PDO::FETCH_ASSOC);
    $libros = array_values(fvdl_filas($bdd, $idFormato)); // tabla por grado del cierre (2026-11-26)
    return md5(json_encode([$f, $items, $devs, $firmas, $aux, $libros]));
}

/** Formatos dentro del alcance del usuario, los más recientes primero. */
function fvd_listar(PDO $bdd) {
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT f.id, f.consecutivo, f.etapa, f.fecha_venta, f.ids_pedidos, f.responsable, f.total_venta, f.suma_total, f.diferencia, f.estado,
                                f.created_at, c.colegio, pe.periodo, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                (SELECT SUM(i.vendidas) FROM formatos_vd_items i WHERE i.id_formato = f.id) AS vendidas,
                                (SELECT d.id FROM devoluciones_v d WHERE d.id_formato_vd = f.id AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . " ORDER BY d.id DESC LIMIT 1) AS id_devolucion,
                                (SELECT GROUP_CONCAT(d.id ORDER BY d.id) FROM devoluciones_v d WHERE d.id_formato_vd = f.id AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . ") AS ids_devolucion
                         FROM formatos_vd f JOIN colegios c ON c.id = f.id_colegio
                         LEFT JOIN periodos pe ON pe.id = f.id_periodo
                         LEFT JOIN usuarios u ON u.id = f.id_usuario
                         WHERE 1" . $alcance . " ORDER BY f.consecutivo DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Cambios de etapa y anulación del formato, en orden: [['estado','texto','fecha','nombre','motivo']]. */
function fvd_historial(PDO $bdd, $id) {
    $st = $bdd->prepare("SELECT h.modulo, h.estado, h.fecha, h.motivo, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS nombre
                         FROM historial_estados h LEFT JOIN usuarios u ON u.id = h.id_usuario
                         WHERE h.id_registro = ? AND (h.modulo = ? OR (h.modulo = ? AND h.estado = " . ESTADO_ANULADO . "))
                         ORDER BY h.fecha, h.id");
    $st->execute([(int)$id, FVD_MODULO_ETAPAS, FVD_MODULO_HISTORIAL]);
    return array_map(fn($h) => $h + ['texto' => $h['modulo'] === FVD_MODULO_HISTORIAL ? 'Formato anulado' : fvd_etapa_evento($h['estado'])],
                     $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Pesos sin decimales del formulario ("140.500" o "140500"); vacío = 0. Null si no es válido. */
function fvd_leer_pesos($v) {
    $v = str_replace(['.', ' ', '$'], '', trim((string)$v));
    if ($v === '') return 0;
    return preg_match('/^\d{1,12}$/', $v) ? (int)$v : null;
}

/** Texto corto del formulario (sin comillas, que rompen exportes viejos). */
function fvd_texto($v, $max) {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(["'", '"'], '', (string)$v))), 0, $max);
}

/** Texto largo (observaciones, justificaciones). */
function fvd_texto_largo($v) {
    return mb_substr(trim(str_replace(["'", '"'], '', (string)$v)), 0, 2000);
}

/** Entero de 0 a 999999 escrito en el formulario, o null. */
function fvd_entero($v) {
    $v = trim((string)$v);
    return preg_match('/^\d{1,6}$/', $v) ? (int)$v : null;
}

/** Datos generales (pasos 1 y 2): ['ok','error','datos']. */
function fvd_leer_generales(array $post) {
    $fecha = (string)($post['fecha_venta'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) return ['ok' => false, 'error' => 'La fecha de venta es obligatoria.'];
    $hora = function ($v) { $v = trim((string)$v); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null; };
    return ['ok' => true, 'error' => null, 'datos' => [
        'fecha_venta' => $fecha,
        'hora_inicio' => $hora($post['hora_inicio'] ?? ''),
        'hora_fin' => $hora($post['hora_fin'] ?? ''),
        'contacto_colegio' => fvd_texto($post['contacto_colegio'] ?? '', 150),
        'tel_contacto' => fvd_texto($post['tel_contacto'] ?? '', 40),
        'direccion' => fvd_texto($post['direccion'] ?? '', 255),
        'observaciones' => fvd_texto_largo($post['observaciones'] ?? ''),
    ]];
}

/** Bloquea el formato (FOR UPDATE) dentro de la transacción y verifica que siga activo en la etapa esperada. Error o null. */
function fvd_bloquear_en_etapa(PDO $bdd, $id, $etapa) {
    $st = $bdd->prepare("SELECT etapa, estado FROM formatos_vd WHERE id = ? FOR UPDATE");
    $st->execute([(int)$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['estado'] !== FVD_ESTADO_ACTIVO) return 'El formato no está activo.';
    if ((int)$r['etapa'] !== (int)$etapa) return 'El formato cambió de etapa mientras lo diligenciabas (ahora: ' . fvd_etapa_texto($r['etapa']) . '). Recarga la página.';
    return null;
}

/** Pasa el formato a la etapa $a y lo deja en el historial (usuario, fecha y hora). Dentro de la transacción. */
function fvd_pasar_etapa(PDO $bdd, $id, $a, $motivo = null) {
    $bdd->prepare("UPDATE formatos_vd SET etapa = ?, updated_at = NOW() WHERE id = ?")->execute([(int)$a, (int)$id]);
    registrar_historial_estado($bdd, FVD_MODULO_ETAPAS, $id, $a, (int)$_SESSION['id'], $motivo);
}

/** Ejecuta $fn dentro de una transacción con el formato bloqueado en la etapa $etapa. $fn devuelve error o null. */
function fvd_en_transaccion(PDO $bdd, $id, $etapa, callable $fn, $contexto) {
    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $error = fvd_bloquear_en_etapa($bdd, $id, $etapa) ?? $fn();
        if ($error) { $bdd->rollBack(); return ['ok' => false, 'error' => $error]; }
        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id' => (int)$id];
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        error_log('Formato venta directa (' . $contexto . ' ' . (int)$id . '): ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar. Intenta de nuevo.'];
    }
}

/* ───────────────────────────── Firmas ───────────────────────────── */

/** Firmas guardadas del formato: [tipo => ['nombre','archivo','url','origen','created_at','usuario']]. */
function fvd_firmas(PDO $bdd, $id) {
    $st = $bdd->prepare("SELECT fi.*, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario FROM formatos_vd_firmas fi
                         LEFT JOIN usuarios u ON u.id = fi.id_usuario WHERE fi.id_formato = ?");
    $st->execute([(int)$id]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $res[$r['tipo']] = $r + ['url' => 'adjuntos/formatos_vd/' . $r['archivo'], 'es_pdf' => substr($r['archivo'], -4) === '.pdf'];
    return $res;
}

/** Carpeta de las firmas (se crea si no existe). */
function fvd_dir_firmas() {
    $dir = dirname(__DIR__) . '/adjuntos/formatos_vd/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Lee la firma $tipo del formulario: un archivo subido (foto/PDF del papel firmado, firma_archivo[tipo]) o,
 * si no hay archivo, la firma dibujada en pantalla (firma_dibujo[tipo] = data:image/png;base64,...).
 * Las firmas son OBLIGATORIAS (2026-10-23, pedido de la usuaria; del 2026-10-22 al 23 fueron opcionales): sin dibujo ni
 * archivo devuelve error.
 * Devuelve ['ok','error','contenido','ext','origen'].
 */
function fvd_leer_firma(array $post, array $files, $tipo) {
    $etq = FVD_FIRMAS[$tipo];
    $f = $files['firma_archivo'] ?? null;
    $err = $f['error'][$tipo] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_NO_FILE) {
        if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'No se pudo subir el archivo de la firma (' . $etq . '). Intenta de nuevo.'];
        if ($f['size'][$tipo] > FVD_FIRMA_MAX_BYTES) return ['ok' => false, 'error' => 'El archivo de la firma (' . $etq . ') supera 8 MB.'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'][$tipo]);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'][$mime] ?? null;
        if (!$ext) return ['ok' => false, 'error' => 'La firma (' . $etq . ') debe ser una foto (JPG o PNG) o un PDF.'];
        return ['ok' => true, 'error' => null, 'contenido' => file_get_contents($f['tmp_name'][$tipo]), 'ext' => $ext, 'origen' => 'archivo'];
    }
    $dibujo = (string)($post['firma_dibujo'][$tipo] ?? '');
    if ($dibujo === '') return ['ok' => false, 'error' => 'Falta la firma de ' . $etq . ': fírmala en el recuadro o sube la foto/PDF del papel firmado.'];
    $png = preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dibujo, $m) ? base64_decode($m[1], true) : false;
    if ($png === false || strlen($png) > 2097152 || (new finfo(FILEINFO_MIME_TYPE))->buffer($png) !== 'image/png') {
        return ['ok' => false, 'error' => 'La firma dibujada (' . $etq . ') no es válida; bórrala y fírmala de nuevo.'];
    }
    return ['ok' => true, 'error' => null, 'contenido' => $png, 'ext' => 'png', 'origen' => 'dibujo'];
}

/** Escribe en disco las firmas leídas: [tipo => leida + 'archivo']. Si falla, borra lo escrito y devuelve null. */
function fvd_escribir_archivos_firma($idFormato, array $leidas) {
    $dir = fvd_dir_firmas();
    $escritas = [];
    foreach ($leidas as $tipo => $l) {
        $archivo = 'f' . (int)$idFormato . '_' . $tipo . '_' . bin2hex(random_bytes(6)) . '.' . $l['ext'];
        if (@file_put_contents($dir . $archivo, $l['contenido']) === false) {
            foreach ($escritas as $e) @unlink($dir . $e['archivo']);
            return null;
        }
        $escritas[$tipo] = $l + ['archivo' => $archivo];
    }
    return $escritas;
}

/** Registra las firmas escritas (dentro de la transacción). $nombres = [tipo => nombre de quien firma]. */
function fvd_registrar_firmas(PDO $bdd, $idFormato, array $escritas, array $nombres) {
    $st = $bdd->prepare("INSERT INTO formatos_vd_firmas (id_formato, tipo, nombre, archivo, origen, id_usuario, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    foreach ($escritas as $tipo => $e) $st->execute([(int)$idFormato, $tipo, $nombres[$tipo], $e['archivo'], $e['origen'], (int)$_SESSION['id']]);
}

/** Borra del disco firmas escritas que no quedaron registradas. */
function fvd_borrar_archivos_firma(array $escritas) {
    $dir = fvd_dir_firmas();
    foreach ($escritas as $e) @unlink($dir . $e['archivo']);
}

/* ───────────────────────────── Paso 1: solicitud ───────────────────────────── */

/**
 * Guarda un formato nuevo con la propuesta que se mostró ($prop, guardada en la sesión con el token del
 * formulario): datos generales y filas con la solicitud y el inventario propuesto. Los pedidos se bloquean
 * y se vuelve a verificar que no estén en otro formato activo. Devuelve ['ok','error','id','repetido'].
 */
function fvd_guardar_nuevo(PDO $bdd, array $prop, array $post, $token) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return ['ok' => false, 'error' => 'El formulario no es válido; vuelve a abrirlo.'];
    $st = $bdd->prepare("SELECT id FROM formatos_vd WHERE token_registro = ?");
    $st->execute([$token]);
    if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id' => (int)$ya, 'repetido' => true];

    // Lo que se digitó en los pedidos para Andrea Díaz es fijo: manda sobre lo enviado.
    $leido = fvd_leer_generales(post_con_datos_fijos_vd($bdd, $prop['claves'], $post));
    if (!$leido['ok']) return $leido;
    $d = $leido['datos'];
    if (!$prop['items']) return ['ok' => false, 'error' => 'El formato no tiene paquetes ni títulos.'];

    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->beginTransaction();
    try {
        $ids = array_map(fn($c) => (int)substr($c, 1), $prop['claves']);
        $bdd->prepare("SELECT id FROM pedidos WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id FOR UPDATE")->execute($ids);
        $pendientes = fvd_pendientes_por_pedido($bdd);
        foreach ($prop['claves'] as $c) {
            if (isset($pendientes[$c])) { $bdd->rollBack(); return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($c) . ' ya quedó en el ' . fvd_numero($pendientes[$c]['consecutivo']) . '.']; }
            if ($cierre = dvd_cierre_completo($bdd, $c)) { $bdd->rollBack(); return ['ok' => false, 'error' => 'El ' . dvd_etiqueta($c) . ' ya se legalizó (registro #' . (int)$cierre['id'] . ').']; }
        }
        $consecutivo = (int)$bdd->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM formatos_vd FOR UPDATE")->fetchColumn();
        $cols = array_keys($d);
        $bdd->prepare("INSERT INTO formatos_vd (consecutivo, id_colegio, id_periodo, ids_pedidos, etapa, " . implode(', ', $cols) . ", estado, id_usuario, token_registro, created_at)
                       VALUES (?, ?, ?, ?, " . FVD_ETAPA_SOLICITUD . ", " . implode(', ', array_fill(0, count($cols), '?')) . ", " . FVD_ESTADO_ACTIVO . ", ?, ?, NOW())")
            ->execute(array_merge([$consecutivo, $prop['id_colegio'], $prop['id_periodo'], implode(',', $prop['claves'])], array_values($d), [(int)$_SESSION['id'], $token]));
        $id = (int)$bdd->lastInsertId();
        fvd_insertar_items($bdd, $id, $prop['items']);
        registrar_historial_estado($bdd, FVD_MODULO_ETAPAS, $id, FVD_ETAPA_SOLICITUD, (int)$_SESSION['id']);
        $bdd->commit();
        return ['ok' => true, 'error' => null, 'id' => $id, 'repetido' => false];
    } catch (PDOException $e) {
        if ($bdd->inTransaction()) $bdd->rollBack();
        if ($e->getCode() === '23000') {
            $st = $bdd->prepare("SELECT id FROM formatos_vd WHERE token_registro = ?");
            $st->execute([$token]);
            if ($ya = $st->fetchColumn()) return ['ok' => true, 'error' => null, 'id' => (int)$ya, 'repetido' => true];
        }
        error_log('Formato venta directa: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el formato. Intenta de nuevo.'];
    }
}

/** Filas del formato desde la propuesta (fvd_propuesta). Dentro de la transacción. */
function fvd_insertar_items(PDO $bdd, $idFormato, array $items) {
    $stI = $bdd->prepare("INSERT INTO formatos_vd_items (id_formato, orden, tipo, id_paquete, id_libro, descripcion, detalle, solicitud, inv_propuesto, inv_inicial, libros,
                                                         pedidos_ref, remisiones, precio_remision)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (array_values($items) as $i => $it) {
        $stI->execute([(int)$idFormato, $i, $it['tipo'], $it['id_paquete'], $it['id_libro'], mb_substr($it['descripcion'], 0, 255), $it['detalle'],
                       (int)$it['solicitud'], (int)$it['inv_propuesto'], (int)$it['inv_propuesto'], implode(',', array_map('intval', $it['libros'])),
                       $it['pedidos_ref'] ?? null, isset($it['remisiones']) ? mb_substr($it['remisiones'], 0, 500) : null, $it['precio_remision'] ?? null]);
    }
}

/* ───────────────────────────── Paso 2: entrega de bodega ───────────────────────────── */

/**
 * Auxiliares de la venta del paso 2 (aux_nombre[] / aux_valor[]): nombre y valor por persona en pesos.
 * Las filas vacías se ignoran; un valor sin nombre es un error. Devuelve [['nombre','valor']] o ['error' => ...].
 */
function fvd_leer_auxiliares(array $post) {
    $nombres = (array)($post['aux_nombre'] ?? []);
    $valores = (array)($post['aux_valor'] ?? []);
    $res = [];
    foreach ($nombres as $i => $n) {
        $n = fvd_texto($n, 150);
        $v = fvd_leer_pesos($valores[$i] ?? '');
        if ($v === null) return ['error' => 'Valor del auxiliar ' . ($n !== '' ? '"' . $n . '"' : '#' . ($i + 1)) . ': escribe un valor en pesos, sin decimales.'];
        if ($n === '') { if ($v > 0) return ['error' => 'Escribe el nombre del auxiliar que tiene el valor $ ' . number_format($v, 0, ',', '.') . '.']; continue; }
        $res[] = ['nombre' => $n, 'valor' => $v];
    }
    if (count($res) > 30) return ['error' => 'Máximo 30 auxiliares.'];
    return $res;
}

/** "Ana ($ 50.000), Luis ($ 40.000)": auxiliares con su valor, o los nombres guardados si no hay lista. */
function fvd_auxiliares_texto(array $f) {
    if (empty($f['auxiliares_lista'])) return (string)$f['auxiliares'];
    return implode(', ', array_map(fn($a) => $a['nombre'] . ((float)$a['valor'] ? ' ($ ' . number_format($a['valor'], 0, ',', '.') . ')' : ''), $f['auxiliares_lista']));
}

/**
 * Confirma la entrega de bodega: datos generales (se pueden completar), aux. que entrega y responsable de
 * la venta con sus firmas, auxiliares de la venta con su valor, total de cajas e inventario entregado por
 * fila. Pasa a la etapa 2.
 */
function fvd_confirmar_entrega(PDO $bdd, array $f, array $post, array $files) {
    $post = post_con_datos_fijos_vd($bdd, explode(',', $f['ids_pedidos']), $post); // incluye el responsable del pedido
    $leido = fvd_leer_generales($post);
    if (!$leido['ok']) return $leido;
    $d = $leido['datos'];
    $d['aux_entrega'] = fvd_texto($post['aux_entrega'] ?? '', 150);
    $d['responsable'] = fvd_texto($post['responsable'] ?? '', 150);
    $d['tel_responsable'] = fvd_texto($post['tel_responsable'] ?? '', 40);
    $aux = fvd_leer_auxiliares($post);
    if (isset($aux['error'])) return ['ok' => false, 'error' => $aux['error']];
    $d['auxiliares'] = mb_substr(implode(', ', array_column($aux, 'nombre')), 0, 255);
    $cajas = fvd_entero($post['total_cajas'] ?? '');
    if ($d['aux_entrega'] === '') return ['ok' => false, 'error' => 'Escribe el auxiliar de bodega que entrega.'];
    if ($d['responsable'] === '') return ['ok' => false, 'error' => 'Escribe el responsable de la venta (quien recibe la mercancía).'];
    if ($cajas === null || $cajas < 1) return ['ok' => false, 'error' => 'Escribe el total de cajas entregadas (número entero mayor que cero).'];
    $d['total_cajas'] = $cajas;

    $entregado = [];
    foreach ($f['items'] as $it) {
        $n = fvd_entero($post['entregado'][$it['clave']] ?? '');
        if ($n === null) return ['ok' => false, 'error' => 'Revisa "' . $it['descripcion'] . '": el inventario entregado debe ser un número entero.'];
        $entregado[(int)$it['id']] = $n;
    }
    if (!array_sum($entregado)) return ['ok' => false, 'error' => 'El inventario entregado no puede quedar en cero.'];

    $leidas = [];
    foreach (['entrega_aux', 'entrega_resp'] as $tipo) {
        $l = fvd_leer_firma($post, $files, $tipo);
        if (!$l['ok']) return $l;
        $leidas[$tipo] = $l;
    }
    $escritas = fvd_escribir_archivos_firma($f['id'], $leidas);
    if ($escritas === null) return ['ok' => false, 'error' => 'No se pudieron guardar las firmas en el servidor. Intenta de nuevo.'];

    $res = fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_SOLICITUD, function () use ($bdd, $f, $d, $entregado, $escritas, $aux) {
        $bdd->prepare("UPDATE formatos_vd SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($d))) . " WHERE id = ?")
            ->execute(array_merge(array_values($d), [(int)$f['id']]));
        $bdd->prepare("DELETE FROM formatos_vd_auxiliares WHERE id_formato = ?")->execute([(int)$f['id']]);
        $st = $bdd->prepare("INSERT INTO formatos_vd_auxiliares (id_formato, orden, nombre, valor) VALUES (?, ?, ?, ?)");
        foreach ($aux as $i => $a) $st->execute([(int)$f['id'], $i, $a['nombre'], $a['valor']]);
        $st = $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = ? WHERE id = ? AND id_formato = ?");
        foreach ($entregado as $idItem => $n) $st->execute([$n, $idItem, (int)$f['id']]);
        $bdd->prepare("DELETE FROM formatos_vd_firmas WHERE id_formato = ? AND tipo IN ('entrega_aux', 'entrega_resp')")->execute([(int)$f['id']]);
        fvd_registrar_firmas($bdd, $f['id'], $escritas, ['entrega_aux' => $d['aux_entrega'], 'entrega_resp' => $d['responsable']]);
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_ENTREGADO);
        return null;
    }, 'entrega');
    if (!$res['ok']) fvd_borrar_archivos_firma($escritas);
    return $res;
}

/* ───────────────────────────── Paso 3: devolución ───────────────────────────── */

/*
 * 2026-11-10 (la usuaria): el formato YA NO registra la devolución. Se registra en Devoluciones de ventas
 * (devoluciones_ventas.php: remisiones elegidas, paquetes completos y libros sueltos) y en el paso 3 del formato se
 * VINCULA esa devolución: sus unidades pasan a las filas del formato y sigue el flujo de siempre (verificación,
 * cierre del inventario, recepción y dinero). fvd_guardar_devueltas / fvd_generar_devolucion quedan sin uso en pantalla.
 */

/** Devoluciones de Devoluciones de ventas que se pueden vincular al formato: activas, sin formato y con pedidos del formato. */
function fvd_devoluciones_para_vincular(PDO $bdd, array $f) {
    $claves = explode(',', $f['ids_pedidos']);
    $st = $bdd->prepare("SELECT d.id, d.codigo, d.ids_pedidos_venta, d.documentos_wo, d.created_at, d.observaciones,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                (SELECT COALESCE(SUM(lv.cantidad), 0) FROM libros_devol_v lv WHERE lv.cod_pedido = d.codigo) AS libros
                         FROM devoluciones_v d LEFT JOIN usuarios u ON u.id = d.id_usuario
                         WHERE d.id_colegio = ? AND d.id_formato_vd IS NULL AND d.ids_pedidos_venta IS NOT NULL
                           AND d.estado <> " . DVD_ESTADO_DEVOL_ANULADA . " ORDER BY d.id DESC");
    $st->execute([(int)$f['id_colegio']]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $peds = explode(',', $d['ids_pedidos_venta']);
        if (array_diff($peds, $claves)) continue; // tiene pedidos que no son del formato
        $res[] = $d + ['pedidos' => $peds];
    }
    return $res;
}

/**
 * Pasa una devolución de Devoluciones de ventas a las filas del formato: con su desglose de venta directa
 * (devoluciones_v_vd) paquete → fila del paquete, suelto → fila del título suelto (o, si el formato no la tiene,
 * libro suelto de paquete), suelto de paquete → fila FVD_TIPO_SUELTO_PAQ. Sin desglose (registrada por libro) se
 * arman paquetes completos con lo devuelto y lo que sobra va a los títulos sueltos. Devuelve
 * ['items' => [id_item => n], 'extra' => [id_libro => n]] o ['error' => ...].
 */
function fvd_devueltas_de_devolucion(PDO $bdd, array $f, array $dev) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_desglose_vd
    // Una devolución o la lista de todas las del formato (2026-11-24: 2 devoluciones de venta directa se suman).
    $devs = isset($dev['id']) ? [$dev] : array_values($dev);
    $porPaq = [];
    $porLibro = [];
    foreach ($f['items'] as $it) {
        if ($it['tipo'] === 'paquete') $porPaq[(int)$it['id_paquete']] = $it;
        elseif ($it['tipo'] === 'titulo') $porLibro[(int)$it['id_libro']] = $it;
    }
    $items = [];
    foreach ($f['items'] as $it) if (!fvd_item_de_devolucion($it)) $items[(int)$it['id']] = 0;
    $extra = [];
    $abiertos = [];    // id_item del paquete => paquetes abiertos
    $abiertosLib = []; // id_paquete => ['n' => abiertos, 'vuelven' => [id_libro => n]]
    $desglose = [];
    foreach ($devs as $dv) {
        $propio = dvv_desglose_vd($bdd, $dv['id']);
        if (!$propio) {
            // Registrada por libro: paquetes completos primero, el resto como sueltos.
            $st = $bdd->prepare("SELECT id_libro, SUM(cantidad) FROM libros_devol_v WHERE cod_pedido = ? GROUP BY id_libro");
            $st->execute([$dv['codigo']]);
            $libros = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
            $paqs = array_map(fn($it) => ['id' => (int)$it['id_paquete'], 'libros' => array_map('intval', array_filter(explode(',', (string)$it['libros'])))], array_values($porPaq));
            [$cuantos, $sobra] = fvd_repartir_en_paquetes($libros, $paqs);
            foreach ($cuantos as $idPaq => $n) if ($n) $propio[] = ['tipo' => 'paquete', 'id_paquete' => $idPaq, 'id_libro' => null, 'cantidad' => $n];
            foreach ($sobra as $l => $n) $propio[] = ['tipo' => 'suelto', 'id_paquete' => null, 'id_libro' => $l, 'cantidad' => $n];
        }
        $desglose = array_merge($desglose, $propio);
    }
    $librosPaq = fvd_libros_de_paquetes($bdd, $f);
    foreach ($desglose as $x) {
        $n = (int)$x['cantidad'];
        if ($x['tipo'] === 'paquete') {
            $it = $porPaq[(int)$x['id_paquete']] ?? null;
            if (!$it) return ['error' => 'La devolución trae un paquete que no está en el formato.'];
            $items[(int)$it['id']] += $n;
        } elseif ($x['tipo'] === 'abierto') {
            // Paquete abierto (2026-11-13): sale de la fila del paquete; sus libros van en filas propias.
            $it = $porPaq[(int)$x['id_paquete']] ?? null;
            if (!$it) return ['error' => 'La devolución trae un paquete abierto que no está en el formato.'];
            $abiertos[(int)$it['id']] = ($abiertos[(int)$it['id']] ?? 0) + $n;
            $abiertosLib[(int)$x['id_paquete']]['n'] = ($abiertosLib[(int)$x['id_paquete']]['n'] ?? 0) + $n;
        } elseif ($x['tipo'] === 'suelto_paq' && !empty($x['id_paquete'])) {
            $abiertosLib[(int)$x['id_paquete']]['vuelven'][(int)$x['id_libro']] = ($abiertosLib[(int)$x['id_paquete']]['vuelven'][(int)$x['id_libro']] ?? 0) + $n;
        } elseif ($x['tipo'] === 'suelto' && isset($porLibro[(int)$x['id_libro']])) {
            $items[(int)$porLibro[(int)$x['id_libro']]['id']] += $n;
        } elseif (isset($librosPaq[(int)$x['id_libro']])) {
            $extra[(int)$x['id_libro']] = ($extra[(int)$x['id_libro']] ?? 0) + $n;
        } else {
            return ['error' => 'La devolución trae un libro que no está en el formato (libro #' . (int)$x['id_libro'] . ').'];
        }
    }
    foreach ($f['items'] as $it) {
        $ab = $abiertos[(int)$it['id']] ?? 0;
        if (isset($items[(int)$it['id']]) && $items[(int)$it['id']] + $ab > (int)$it['inv_inicial']) {
            return ['error' => 'En "' . $it['descripcion'] . '" la devolución trae ' . $items[(int)$it['id']] . ($ab ? ' completo(s) y ' . $ab . ' abierto(s)' : '') . ' pero bodega entregó ' . (int)$it['inv_inicial'] . '.'];
        }
    }
    // Filas de los libros de cada paquete abierto: entregado = paquetes abiertos, devueltas = las que volvieron sueltas.
    $filasAbiertas = [];
    foreach ($abiertosLib as $idPaq => $x) {
        if (empty($x['n'])) return ['error' => 'La devolución trae un libro suelto de un paquete abierto sin el paquete.'];
        $it = $porPaq[$idPaq];
        foreach (array_filter(array_map('intval', explode(',', (string)$it['libros']))) as $l) {
            $filasAbiertas[] = ['id_paquete' => $idPaq, 'id_libro' => $l, 'paquete' => $it['descripcion'], 'entregado' => (int)$x['n'], 'devueltas' => (int)($x['vuelven'][$l] ?? 0)];
        }
        foreach (($x['vuelven'] ?? []) as $l => $_) {
            if (!in_array((int)$l, array_map('intval', explode(',', (string)$it['libros'])), true)) return ['error' => 'La devolución trae un libro que no está en el "' . $it['descripcion'] . '" del formato.'];
        }
    }
    return ['items' => $items, 'extra' => $extra, 'abiertos' => $abiertos, 'filas_abiertas' => $filasAbiertas];
}

/**
 * Vincula al formato una devolución registrada en Devoluciones de ventas (paso 3) y sigue el flujo automático.
 * 2026-11-24 (la usuaria): en venta directa un pedido admite 2 devoluciones (DVV_MAX_DEVOL_VD). La primera genera el
 * formato; la segunda se SUMA al mismo formato: las devueltas de cada fila pasan a ser lo de todas sus devoluciones
 * activas, así que bajan las vendidas y el total de venta. Si el formato ya iba más adelante (cierre del inventario,
 * recepción, dinero o incluso cerrado) vuelve a la devolución y se recalcula solo (fvd_avanzar_automatico); el cierre
 * de dinero se debe confirmar de nuevo con el total nuevo.
 */
function fvd_vincular_devolucion(PDO $bdd, array $f, $idDevolucion) {
    $dev = null;
    foreach (fvd_devoluciones_para_vincular($bdd, $f) as $d) if ((int)$d['id'] === (int)$idDevolucion) $dev = $d;
    if (!$dev) return ['ok' => false, 'error' => 'Esa devolución no se puede vincular: no existe, está anulada, ya tiene formato o tiene pedidos de otro formato.'];
    $previas = $f['legalizacion']['todas'] ?? [];
    if (!$previas && $f['etapa'] !== FVD_ETAPA_ENTREGADO) {
        return ['ok' => false, 'error' => 'El ' . fvd_numero($f['consecutivo']) . ' está en "' . fvd_etapa_texto($f['etapa']) . '"; la devolución se vincula después de la entrega de bodega.'];
    }
    if ($previas) {
        $motivo = 'Se sumó la devolución #' . (int)$dev['id'] . ' (con ' . $f['legalizacion']['texto'] . '): se descuentan sus unidades de lo vendido'
                . ($f['etapa'] > FVD_ETAPA_DEV_GENERADA ? '; se recalcula desde "' . fvd_etapa_texto($f['etapa']) . '"' : '');
    } else {
        $motivo = 'Devolución #' . (int)$dev['id'] . ' de Devoluciones de ventas';
    }
    $r = fvd_aplicar_devoluciones($bdd, $f, array_merge($previas, [$dev]), (int)$dev['id'], $motivo);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'error' => null, 'id' => (int)$f['id'], 'id_devolucion' => (int)$dev['id'], 'etapa' => $r['etapa'], 'agregada' => (bool)$previas];
}

/**
 * Pasa a las filas del formato lo de TODAS las devoluciones $devs (las ya vinculadas + $idNueva, que se vincula aquí),
 * deja el formato en "Devolución generada" y sigue el cálculo automático. Si con lo devuelto un libro ya no alcanza para
 * los paquetes armados (fvd_armar_paquetes), se deshace el armado (hay que volver a armarlos con lo vendido nuevo).
 * Devuelve ['ok','error','etapa','armado_deshecho'].
 */
function fvd_aplicar_devoluciones(PDO $bdd, array $f, array $devs, $idNueva, $motivo) {
    $deshacerArmado = false;
    $res = $devs ? fvd_devueltas_de_devolucion($bdd, $f, $devs) : ['items' => [], 'extra' => [], 'abiertos' => [], 'filas_abiertas' => []];
    if (isset($res['error'])) {
        // ¿Choca con el armado de paquetes? Se prueba con el formato sin armar.
        $hayArmado = array_filter($f['items'], fn($it) => (int)($it['en_paquetes'] ?? 0) > 0 || (int)($it['armados'] ?? 0) > 0);
        if (!$hayArmado) return ['ok' => false, 'error' => $res['error']];
        $fSin = $f;
        $fSin['items'] = [];
        foreach ($f['items'] as $it) {
            if ($it['tipo'] === 'titulo') $it['inv_inicial'] = (int)$it['inv_inicial'] + (int)($it['en_paquetes'] ?? 0);
            if ($it['tipo'] === 'paquete') {
                $it['inv_inicial'] = (int)$it['inv_inicial'] - (int)($it['armados'] ?? 0);
                if ($it['inv_inicial'] <= 0 && (int)$it['solicitud'] === 0) continue; // fila creada solo por el armado
            }
            $fSin['items'][] = $it;
        }
        $res = fvd_devueltas_de_devolucion($bdd, $fSin, $devs);
        if (isset($res['error'])) return ['ok' => false, 'error' => $res['error']];
        $deshacerArmado = true;
    }
    // Tramo de la 2a devolución (2026-11-24): lo de todas − lo de la primera, por fila.
    $d2 = ['items' => [], 'extra' => [], 'abiertas' => []];
    if (count($devs) > 1) {
        $res1 = fvd_devueltas_de_devolucion($bdd, $deshacerArmado ? $fSin : $f, [reset($devs)]);
        if (!isset($res1['error'])) {
            foreach ($res['items'] as $k => $n) $d2['items'][$k] = max(0, (int)$n - (int)($res1['items'][$k] ?? 0));
            foreach ($res['extra'] as $k => $n) $d2['extra'][$k] = max(0, (int)$n - (int)($res1['extra'][$k] ?? 0));
            $ab1 = [];
            foreach ($res1['filas_abiertas'] as $x) $ab1[$x['id_paquete'] . '_' . $x['id_libro']] = (int)$x['devueltas'];
            foreach ($res['filas_abiertas'] as $x) $d2['abiertas'][$x['id_paquete'] . '_' . $x['id_libro']] = max(0, (int)$x['devueltas'] - ($ab1[$x['id_paquete'] . '_' . $x['id_libro']] ?? 0));
        }
    }
    $librosPaq = fvd_libros_de_paquetes($bdd, $f);
    $r = fvd_en_transaccion($bdd, $f['id'], $f['etapa'], function () use ($bdd, $f, $idNueva, $res, $librosPaq, $deshacerArmado, $motivo, $d2) {
        $id = (int)$f['id'];
        if ($idNueva) {
            $st = $bdd->prepare("SELECT id_formato_vd, estado FROM devoluciones_v WHERE id = ? FOR UPDATE");
            $st->execute([(int)$idNueva]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d || $d['id_formato_vd'] !== null || (int)$d['estado'] === DVD_ESTADO_DEVOL_ANULADA) return 'La devolución cambió mientras la vinculabas. Recarga la página.';
        }
        if ($deshacerArmado) {
            // Igual que el paso 1 de fvd_armar_paquetes.
            $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial + en_paquetes, inv_propuesto = inv_propuesto + en_paquetes, en_paquetes = 0
                           WHERE id_formato = ? AND tipo = 'titulo' AND en_paquetes > 0")->execute([$id]);
            $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial - armados, inv_propuesto = GREATEST(0, inv_propuesto - armados), armados = 0
                           WHERE id_formato = ? AND tipo = 'paquete' AND armados > 0")->execute([$id]);
            $bdd->prepare("DELETE FROM formatos_vd_items WHERE id_formato = ? AND tipo = 'paquete' AND inv_inicial = 0 AND solicitud = 0")->execute([$id]);
        }
        $up = $bdd->prepare("UPDATE formatos_vd_items SET devueltas = ?, devueltas_2 = ?, abiertos = ? WHERE id = ? AND id_formato = ?");
        foreach ($res['items'] as $idItem => $n) $up->execute([$n, (int)($d2['items'][$idItem] ?? 0), (int)($res['abiertos'][$idItem] ?? 0), $idItem, $id]);
        $bdd->prepare("DELETE FROM formatos_vd_items WHERE id_formato = ? AND tipo IN (?, ?)")->execute([$id, FVD_TIPO_SUELTO_PAQ, FVD_TIPO_ABIERTO]);
        $ins = $bdd->prepare("INSERT INTO formatos_vd_items (id_formato, orden, tipo, id_paquete, id_libro, descripcion, detalle, solicitud, inv_propuesto, inv_inicial, devueltas, devueltas_2, libros)
                              VALUES (?, ?, ?, NULL, ?, ?, ?, 0, 0, 0, ?, ?, ?)");
        $orden = 1000;
        foreach ($res['extra'] as $l => $n) {
            $p = $librosPaq[$l];
            $ins->execute([$id, $orden++, FVD_TIPO_SUELTO_PAQ, $l, mb_substr($p['libro'], 0, 255), 'Libro suelto de: ' . implode(', ', array_unique($p['paquetes'])), $n, (int)($d2['extra'][$l] ?? 0), (string)$l]);
        }
        if ($res['filas_abiertas']) {
            $ids = array_unique(array_column($res['filas_abiertas'], 'id_libro'));
            $nombres = $bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR);
            $insA = $bdd->prepare("INSERT INTO formatos_vd_items (id_formato, orden, tipo, id_paquete, id_libro, descripcion, detalle, solicitud, inv_propuesto, inv_inicial, devueltas, devueltas_2, libros)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?)");
            $orden = 500;
            foreach ($res['filas_abiertas'] as $x) {
                $insA->execute([$id, $orden++, FVD_TIPO_ABIERTO, $x['id_paquete'], $x['id_libro'], mb_substr((string)($nombres[$x['id_libro']] ?? 'Libro #' . $x['id_libro']), 0, 255),
                                'Del ' . $x['paquete'] . ' abierto', $x['entregado'], $x['devueltas'], (int)($d2['abiertas'][$x['id_paquete'] . '_' . $x['id_libro']] ?? 0), (string)$x['id_libro']]);
            }
        }
        if ($idNueva) $bdd->prepare("UPDATE devoluciones_v SET id_formato_vd = ? WHERE id = ?")->execute([$id, (int)$idNueva]);
        $bdd->prepare("UPDATE formatos_vd SET updated_at = NOW() WHERE id = ?")->execute([$id]);
        fvd_pasar_etapa($bdd, $id, FVD_ETAPA_DEV_GENERADA, mb_substr($motivo . ($deshacerArmado ? '; se deshizo el armado de paquetes (vuelve a armarlos con lo vendido)' : ''), 0, 250));
        return null;
    }, 'vincular devolución');
    if (!$r['ok']) return $r;
    $etapa = fvd_avanzar_automatico($bdd, $f['id']);
    // Con el total de venta nuevo, la diferencia del cierre de dinero ya guardado se recalcula.
    if ($etapa >= FVD_ETAPA_CONCILIADO) {
        $bdd->prepare("UPDATE formatos_vd SET diferencia = ROUND(COALESCE(suma_total, 0) - COALESCE(total_venta, 0), 2) WHERE id = ? AND suma_total IS NOT NULL")->execute([(int)$f['id']]);
    }
    return ['ok' => true, 'error' => null, 'etapa' => $etapa, 'armado_deshecho' => $deshacerArmado];
}

/**
 * Armar paquetes (2026-11-19, la usuaria): WO remisiona por libro, así que después de la devolución se arman a mano, en el
 * formato, los paquetes de la adopción del colegio (paquetes_colegio) con lo VENDIDO de cada libro suelto (entregado −
 * devuelto). Un paquete solo se arma con TODOS sus libros de la adopción (cada paquete = 1 unidad de cada uno) y se cobra
 * a su precio; lo que no se arma queda como libro suelto a su precio venta padre.
 *   fila del paquete: inv_inicial += armados (todos vendidos); formatos_vd_items.armados = paquetes armados
 *   fila del libro:   inv_inicial −= unidades que se fueron a paquetes; formatos_vd_items.en_paquetes = esas unidades
 * Las devueltas por libro no cambian (la verificación contra la devolución sigue cuadrando). Se puede rehacer hasta el
 * cierre de dinero: cada vez se deshace el armado anterior y se aplica el nuevo.
 */

/** Libros de los paquetes pasados al libros.id de la fila del título del mismo producto (libro repetido en el catálogo). */
function fvd_paquetes_canonicos(PDO $bdd, array $paquetes, array $idsTitulos) {
    require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_claves_catalogo
    $ids = $idsTitulos;
    foreach ($paquetes as $p) foreach ($p['libros'] as $l) $ids[] = (int)$l;
    if (!$ids) return $paquetes;
    $cat = dvv_claves_catalogo($bdd, $ids);
    $deClave = [];
    foreach ($idsTitulos as $l) if (isset($cat[$l])) $deClave[$cat[$l]] = $l;
    foreach ($paquetes as &$p) $p['libros'] = array_map(fn($l) => $deClave[$cat[(int)$l] ?? ''] ?? (int)$l, $p['libros']);
    unset($p);
    return $paquetes;
}

/**
 * Datos de la pantalla "Armar paquetes": ['editable','paquetes' => [['id','paquete','grado','precio','armados','max',
 * 'faltan' => [nombres],'componentes' => [['id_libro','libro','precio_padre']]]],'libros' => [id_libro => ['libro',
 * 'vendido' (entregado − devuelto, sin armar),'precio_padre']]]. 'max' = paquetes que alcanzan si no se arma ningún otro.
 */
function fvd_armado_datos(PDO $bdd, array $f) {
    $libros = [];
    foreach ($f['items'] as $it) {
        if ($it['tipo'] !== 'titulo') continue;
        $e = (int)$it['inv_inicial'] + (int)($it['en_paquetes'] ?? 0);
        $libros[(int)$it['id_libro']] = ['libro' => $it['descripcion'], 'vendido' => max(0, $e - (int)$it['devueltas']), 'precio_padre' => null];
    }
    $armados = [];
    foreach ($f['items'] as $it) if ($it['tipo'] === 'paquete') $armados[(int)$it['id_paquete']] = (int)($it['armados'] ?? 0);
    $paquetes = fvd_paquetes_canonicos($bdd, fvd_paquetes_colegio($bdd, $f['id_colegio'], $f['id_periodo']), array_keys($libros));
    // Precio venta padre de los libros (el mismo cálculo del cierre: fvd_precios_actuales).
    $idsPrecio = array_keys($libros);
    foreach ($paquetes as $p) foreach ($p['libros'] as $l) $idsPrecio[] = (int)$l;
    $precios = !$idsPrecio ? [] : fvd_precios_actuales($bdd, ['items' => array_map(fn($l) => ['tipo' => 'titulo', 'id_libro' => $l, 'id_paquete' => null, 'clave' => 'L' . $l], array_values(array_unique($idsPrecio))),
                                           'id_colegio' => $f['id_colegio'], 'id_periodo' => $f['id_periodo'], 'legalizacion' => null]);
    foreach ($libros as $l => &$x) $x['precio_padre'] = $precios['L' . $l]['precio'] ?? null;
    unset($x);
    $grados = [];
    if ($paquetes) {
        foreach ($bdd->query("SELECT pq.id, g.grado FROM paquetes_colegio pq JOIN grados g ON g.id = pq.id_grado WHERE pq.id IN ("
                             . implode(',', array_map(fn($p) => (int)$p['id'], $paquetes)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $g) $grados[(int)$id] = $g;
    }
    $res = [];
    foreach ($paquetes as $p) {
        $comp = [];
        $faltan = [];
        $max = null;
        foreach ($p['libros'] as $i => $l) {
            $nombre = $libros[$l]['libro'] ?? ($p['titulos'][$i] ?? 'Libro #' . $l);
            $comp[] = ['id_libro' => $l, 'libro' => $nombre, 'precio_padre' => $precios['L' . $l]['precio'] ?? null];
            if (!isset($libros[$l]) || $libros[$l]['vendido'] <= 0) { $faltan[] = $nombre; $max = 0; continue; }
            $max = $max === null ? $libros[$l]['vendido'] : min($max, $libros[$l]['vendido']);
        }
        $res[] = ['id' => (int)$p['id'], 'paquete' => $p['paquete'], 'grado' => $grados[(int)$p['id']] ?? '', 'precio' => (float)$p['precio'],
                  'armados' => $armados[(int)$p['id']] ?? 0, 'max' => (int)$max, 'faltan' => $faltan, 'componentes' => $comp];
    }
    return ['editable' => $f['activo'] && $f['etapa'] >= FVD_ETAPA_DEV_GENERADA && $f['etapa'] < FVD_ETAPA_CERRADO,
            'paquetes' => $res, 'libros' => $libros];
}

/** Guarda el armado de paquetes ($post['armar'][id_paquete] = paquetes) y recalcula el cierre. Devuelve ['ok','error','id','etapa']. */
function fvd_armar_paquetes(PDO $bdd, array $f, array $post) {
    if (!$f['activo'] || $f['etapa'] < FVD_ETAPA_DEV_GENERADA || $f['etapa'] >= FVD_ETAPA_CERRADO) {
        return ['ok' => false, 'error' => 'Los paquetes se arman después de la devolución y antes de confirmar el cierre de dinero.'];
    }
    $datos = fvd_armado_datos($bdd, $f);
    $porId = [];
    foreach ($datos['paquetes'] as $p) $porId[$p['id']] = $p;
    $pedidos = [];
    foreach ((array)($post['armar'] ?? []) as $idPaq => $v) {
        $v = trim((string)$v);
        if ($v === '' || $v === '0') continue;
        if (!preg_match('/^\d+$/', $v)) return ['ok' => false, 'error' => 'Los paquetes armados deben ser números enteros, sin decimales ni negativos.'];
        $p = $porId[(int)$idPaq] ?? null;
        if (!$p) return ['ok' => false, 'error' => 'Uno de los paquetes no está en la adopción del colegio.'];
        if ($p['faltan']) return ['ok' => false, 'error' => 'El "' . $p['paquete'] . '" no se puede armar: le falta ' . implode(', ', $p['faltan']) . ' (no se vendió suelto en este formato).'];
        if ($p['precio'] <= 0) return ['ok' => false, 'error' => 'El "' . $p['paquete'] . '" no tiene precio: configúralo en Adopciones del colegio.'];
        $pedidos[(int)$idPaq] = (int)$v;
    }
    // Cada paquete usa 1 unidad de cada libro: entre todos no se pasa de lo vendido del libro.
    $uso = [];
    foreach ($pedidos as $idPaq => $n) foreach ($porId[$idPaq]['componentes'] as $c) $uso[$c['id_libro']] = ($uso[$c['id_libro']] ?? 0) + $n;
    foreach ($uso as $l => $n) {
        $vend = $datos['libros'][$l]['vendido'] ?? 0;
        if ($n > $vend) {
            $con = array_map(fn($id) => $porId[$id]['paquete'] . ' (' . $pedidos[$id] . ')', array_keys(array_filter($pedidos, fn($n2, $id) => in_array($l, array_column($porId[$id]['componentes'], 'id_libro'), true), ARRAY_FILTER_USE_BOTH)));
            return ['ok' => false, 'error' => 'De "' . $datos['libros'][$l]['libro'] . '" se vendieron ' . $vend . ' pero los paquetes armados usan ' . $n . ': ' . implode(', ', $con) . '.'];
        }
    }
    $r = fvd_en_transaccion($bdd, $f['id'], $f['etapa'], function () use ($bdd, $f, $pedidos, $porId, $uso) {
        $id = (int)$f['id'];
        // 1) Deshace el armado anterior.
        $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial + en_paquetes, inv_propuesto = inv_propuesto + en_paquetes, en_paquetes = 0
                       WHERE id_formato = ? AND tipo = 'titulo' AND en_paquetes > 0")->execute([$id]);
        $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial - armados, inv_propuesto = GREATEST(0, inv_propuesto - armados), armados = 0
                       WHERE id_formato = ? AND tipo = 'paquete' AND armados > 0")->execute([$id]);
        $bdd->prepare("DELETE FROM formatos_vd_items WHERE id_formato = ? AND tipo = 'paquete' AND inv_inicial = 0 AND COALESCE(devueltas, 0) = 0 AND solicitud = 0")->execute([$id]);
        // 2) Aplica el nuevo.
        $upL = $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial - ?, inv_propuesto = GREATEST(0, inv_propuesto - ?), en_paquetes = ?
                              WHERE id_formato = ? AND tipo = 'titulo' AND id_libro = ?");
        foreach ($uso as $l => $n) $upL->execute([$n, $n, $n, $id, $l]);
        $st = $bdd->prepare("SELECT id_paquete, id FROM formatos_vd_items WHERE id_formato = ? AND tipo = 'paquete'");
        $st->execute([$id]);
        $filaPaq = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        $upP = $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_inicial + ?, inv_propuesto = inv_propuesto + ?, armados = ? WHERE id = ?");
        $insP = $bdd->prepare("INSERT INTO formatos_vd_items (id_formato, orden, tipo, id_paquete, id_libro, descripcion, detalle, solicitud, inv_propuesto, inv_inicial, devueltas, armados, libros)
                               VALUES (?, ?, 'paquete', ?, NULL, ?, ?, 0, ?, ?, 0, ?, ?)");
        $orden = -1000; // antes de los títulos
        foreach ($pedidos as $idPaq => $n) {
            $p = $porId[$idPaq];
            if (isset($filaPaq[$idPaq])) { $upP->execute([$n, $n, $n, $filaPaq[$idPaq]]); continue; }
            $insP->execute([$id, $orden++, $idPaq, mb_substr($p['paquete'], 0, 255), implode(' + ', array_column($p['componentes'], 'libro')), $n, $n, $n,
                            implode(',', array_column($p['componentes'], 'id_libro'))]);
        }
        // Recepción ya registrada: los paquetes armados no traen nada devuelto.
        if ($f['etapa'] >= FVD_ETAPA_RECIBIDO) $bdd->prepare("UPDATE formatos_vd_items SET recibidas = 0 WHERE id_formato = ? AND tipo = 'paquete' AND recibidas IS NULL")->execute([$id]);
        $bdd->prepare("UPDATE formatos_vd SET updated_at = NOW() WHERE id = ?")->execute([$id]);
        return null;
    }, 'armar paquetes');
    if (!$r['ok']) return $r;
    // Si el cierre del inventario ya estaba calculado, se recalcula con el nuevo armado (precios vigentes). Va FUERA de
    // la transacción anterior: fvd_precios_actuales → dvv_paquetes_abiertos crea su tabla si no existe (CREATE TABLE =
    // commit implícito en MySQL), y dentro de la transacción eso daba "No se pudo guardar" (2026-11-19, formato 38).
    if ($f['etapa'] >= FVD_ETAPA_CONCILIADO) {
        $r = fvd_recalcular_cierre($bdd, $f['id']);
        if (!$r['ok']) return ['ok' => false, 'error' => 'Los paquetes se guardaron, pero no se pudo recalcular el total: ' . $r['error']];
    }
    return ['ok' => true, 'error' => null, 'id' => (int)$f['id'], 'etapa' => fvd_avanzar_automatico($bdd, $f['id'])];
}

/**
 * Libros sueltos de paquetes abiertos (2026-11-10, la usuaria): en la devolución, además de paquetes completos,
 * se puede devolver un libro suelto que venía DENTRO de un paquete entregado. Queda como fila tipo
 * FVD_TIPO_SUELTO_PAQ (clave 'X' + id_libro, inventario entregado 0) y en el cierre se RESTA del total de venta
 * a su "Precio venta padre": vendidas = −devueltas.
 */
const FVD_TIPO_SUELTO_PAQ = 'suelto_paq';

/**
 * Paquetes abiertos (2026-11-13, la usuaria): cuando de un paquete vuelve un libro suelto (registrado en Devoluciones
 * de ventas), ese paquete ya no se cobra a precio de paquete: sale de la fila del paquete (formatos_vd_items.abiertos)
 * y cada uno de sus libros va en una fila FVD_TIPO_ABIERTO (clave 'A' + paquete + '_' + libro) con entregado = paquetes
 * abiertos, devueltas = las que volvieron sueltas y vendidas = el resto, al precio puesto en la devolución
 * (devoluciones_v_vd_abiertos).
 */
const FVD_TIPO_ABIERTO = 'abierto';

/** Filas que salen de la devolución (no se entregan ni se diligencian a mano): sueltos de paquete y libros de paquetes abiertos. */
function fvd_item_de_devolucion(array $it) {
    return in_array($it['tipo'], [FVD_TIPO_SUELTO_PAQ, FVD_TIPO_ABIERTO], true);
}

/** Unidades vendidas de una fila: el suelto de paquete resta; el paquete descuenta además los abiertos. */
function fvd_vendidas_item(array $it) {
    if ($it['tipo'] === FVD_TIPO_SUELTO_PAQ) return -(int)$it['devueltas'];
    return max(0, (int)$it['inv_inicial'] - (int)$it['devueltas'] - ($it['tipo'] === 'paquete' ? (int)($it['abiertos'] ?? 0) : 0));
}

/**
 * Libros que se pueden devolver sueltos: los de los paquetes entregados en el formato.
 * [id_libro => ['id_libro','libro','entregado' (unidades del libro en los paquetes entregados),'paquetes' => [nombres]]].
 */
function fvd_libros_de_paquetes(PDO $bdd, array $f) {
    $res = [];
    foreach ($f['items'] as $it) {
        if ($it['tipo'] !== 'paquete' || (int)$it['inv_inicial'] <= 0) continue;
        foreach (array_filter(explode(',', (string)$it['libros'])) as $l) {
            $l = (int)$l;
            $res[$l] = $res[$l] ?? ['id_libro' => $l, 'libro' => '', 'entregado' => 0, 'paquetes' => []];
            $res[$l]['entregado'] += (int)$it['inv_inicial'];
            $res[$l]['paquetes'][] = $it['descripcion'];
        }
    }
    if ($res) {
        foreach ($bdd->query("SELECT id, libro FROM libros WHERE id IN (" . implode(',', array_keys($res)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $res[(int)$id]['libro'] = $n;
        uasort($res, fn($a, $b) => strcasecmp($a['libro'], $b['libro']));
    }
    return $res;
}

/**
 * Lee las unidades devueltas por fila (≤ entregado) y los libros sueltos de paquetes (post extra[id_libro]).
 * Devuelve ['items' => [id_item => n], 'extra' => [id_libro => n]] o ['error' => ...]. En borrador se pueden dejar
 * vacías (null). Un libro suelto de paquete no puede pasar de lo que queda de ese libro en los paquetes no devueltos.
 */
function fvd_leer_devueltas(PDO $bdd, array $f, array $post, $borrador = false) {
    $res = [];
    $paqDev = []; // id_libro => unidades que ya vuelven dentro de paquetes completos
    foreach ($f['items'] as $it) {
        if (fvd_item_de_devolucion($it)) continue; // sueltos de paquete: extra[]; paquetes abiertos: solo desde Devoluciones de ventas
        $txt = trim((string)($post['devueltas'][$it['clave']] ?? ''));
        if ($borrador && $txt === '') { $res[(int)$it['id']] = null; continue; }
        $n = fvd_entero($txt);
        if ($n === null) return ['error' => 'Revisa "' . $it['descripcion'] . '": las unidades devueltas deben ser un número entero (0 si no se devuelve nada).'];
        if ($n > (int)$it['inv_inicial']) return ['error' => 'En "' . $it['descripcion'] . '" se devuelven ' . $n . ' pero bodega entregó ' . (int)$it['inv_inicial'] . '.'];
        $res[(int)$it['id']] = $n;
        if ($it['tipo'] === 'paquete') foreach (array_filter(explode(',', (string)$it['libros'])) as $l) $paqDev[(int)$l] = ($paqDev[(int)$l] ?? 0) + $n;
    }
    $permitidos = fvd_libros_de_paquetes($bdd, $f);
    $extra = [];
    foreach ((array)($post['extra'] ?? []) as $idLibro => $txt) {
        $idLibro = (int)$idLibro;
        $txt = trim((string)$txt);
        if ($txt === '' || $txt === '0') continue;
        if (!isset($permitidos[$idLibro])) return ['error' => 'Solo se pueden devolver sueltos los libros que vienen dentro de los paquetes entregados.'];
        $n = fvd_entero($txt);
        $p = $permitidos[$idLibro];
        if ($n === null) return ['error' => 'Revisa el libro suelto "' . $p['libro'] . '": las unidades deben ser un número entero.'];
        $queda = $p['entregado'] - ($paqDev[$idLibro] ?? 0);
        if ($n > $queda) return ['error' => 'De "' . $p['libro'] . '" se entregaron ' . $p['entregado'] . ' en paquetes' . (($paqDev[$idLibro] ?? 0) ? ' y ' . $paqDev[$idLibro] . ' ya vuelven en paquetes completos' : '') . ': como suelto se pueden devolver máximo ' . max(0, $queda) . '.'];
        $extra[$idLibro] = $n;
    }
    return ['items' => $res, 'extra' => $extra];
}

/** Unidades devueltas pasadas a libros (cada paquete devuelve una unidad de cada uno de sus títulos): [id_libro => n]. */
function fvd_libros_devueltos(array $items) {
    $res = [];
    foreach ($items as $it) {
        if ((int)$it['devueltas'] <= 0) continue;
        foreach (array_filter(explode(',', (string)$it['libros'])) as $l) $res[(int)$l] = ($res[(int)$l] ?? 0) + (int)$it['devueltas'];
    }
    return $res;
}

/** Devolución en preparación: guarda las unidades devueltas sin generar el documento. */
function fvd_guardar_devueltas(PDO $bdd, array $f, array $post, $borrador = true) {
    $dev = fvd_leer_devueltas($bdd, $f, $post, $borrador);
    if (isset($dev['error'])) return ['ok' => false, 'error' => $dev['error']];
    $libros = fvd_libros_de_paquetes($bdd, $f);
    return fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_ENTREGADO, function () use ($bdd, $f, $dev, $libros) {
        $st = $bdd->prepare("UPDATE formatos_vd_items SET devueltas = ?, abiertos = 0 WHERE id = ? AND id_formato = ?");
        foreach ($dev['items'] as $idItem => $n) $st->execute([$n, $idItem, (int)$f['id']]);
        // Libros sueltos de paquetes: se reemplazan las filas con lo que llega (los paquetes abiertos solo vienen de una devolución vinculada).
        $bdd->prepare("DELETE FROM formatos_vd_items WHERE id_formato = ? AND tipo IN (?, ?)")->execute([(int)$f['id'], FVD_TIPO_SUELTO_PAQ, FVD_TIPO_ABIERTO]);
        $ins = $bdd->prepare("INSERT INTO formatos_vd_items (id_formato, orden, tipo, id_paquete, id_libro, descripcion, detalle, solicitud, inv_propuesto, inv_inicial, devueltas, libros)
                              VALUES (?, ?, ?, NULL, ?, ?, ?, 0, 0, 0, ?, ?)");
        $orden = 1000;
        foreach ($dev['extra'] as $idLibro => $n) {
            $p = $libros[$idLibro];
            $ins->execute([(int)$f['id'], $orden++, FVD_TIPO_SUELTO_PAQ, $idLibro, mb_substr($p['libro'], 0, 255),
                           'Libro suelto de: ' . implode(', ', array_unique($p['paquetes'])), $n, (string)$idLibro]);
        }
        $bdd->prepare("UPDATE formatos_vd SET updated_at = NOW() WHERE id = ?")->execute([(int)$f['id']]);
        return null;
    }, 'devolución borrador');
}

/**
 * Genera la devolución en InkPulse: guarda las unidades devueltas y registra la legalización de siempre
 * (devoluciones_v + saldo SAL-n, dvd_guardar_devolucion) con esas unidades pasadas a libros. Si todo se
 * vendió, la devolución va sin libros y todo queda en el saldo. Pasa a la etapa 3 en la misma transacción.
 */
function fvd_generar_devolucion(PDO $bdd, array $f, array $post) {
    $res = fvd_guardar_devueltas($bdd, $f, $post, false);
    if (!$res['ok']) return $res;
    $f = fvd_obtener($bdd, $f['id']);
    $cantidades = fvd_libros_devueltos($f['items']);
    $obs = fvd_numero($f['consecutivo']) . ($f['observaciones'] !== '' && $f['observaciones'] !== null ? ' · ' . $f['observaciones'] : '');
    $id = (int)$f['id'];
    $r = dvd_guardar_devolucion($bdd, explode(',', $f['ids_pedidos']), $cantidades, mb_substr($obs, 0, 1000), (string)($post['token'] ?? ''), $id,
        function () use ($bdd, $id) {
            $error = fvd_bloquear_en_etapa($bdd, $id, FVD_ETAPA_ENTREGADO);
            if ($error) return $error;
            fvd_pasar_etapa($bdd, $id, FVD_ETAPA_DEV_GENERADA);
            return null;
        }, true);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $etapa = fvd_avanzar_automatico($bdd, $id);
    return ['ok' => true, 'error' => null, 'id' => $id, 'id_devolucion' => $r['id_devolucion'], 'saldo' => $r['saldo'], 'etapa' => $etapa];
}

/**
 * Verifica que la devolución generada esté ligada al formato: activa, con los mismos pedidos y con
 * exactamente las unidades por libro que salen de las devueltas del formato.
 * Devuelve ['ok','checks' => [['ok','texto']],'devolucion','saldo'].
 */
function fvd_verificar_devolucion(PDO $bdd, array $f) {
    $checks = [];
    $d = $f['legalizacion'];
    $varias = $d && count($d['ids'] ?? []) > 1;
    $checks[] = ['ok' => (bool)$d, 'texto' => $d ? ($varias ? 'Devoluciones ' . $d['texto'] . ' registradas en InkPulse y activas (se suman).' : 'Devolución #' . (int)$d['id'] . ' registrada en InkPulse y activa.') : 'El formato no tiene una devolución activa (no se ha generado o se anuló).'];
    if (!$d) return ['ok' => false, 'checks' => $checks, 'devolucion' => null, 'saldo' => null];

    // 2026-11-24: con 2 devoluciones de venta directa se comparan los pedidos y las unidades de todas.
    $a = [];
    foreach ($d['todas'] ?? [$d] as $x) $a = array_merge($a, explode(',', str_replace(' ', '', $x['ids_pedidos'])));
    $a = array_values(array_unique($a)); sort($a);
    $b = explode(',', $f['ids_pedidos']); sort($b);
    // Desde 2026-11-10 la devolución se registra en Devoluciones de ventas con las remisiones elegidas: basta con que
    // sus pedidos sean del formato (puede no incluirlos todos).
    $dentro = !array_diff($a, $b);
    $checks[] = ['ok' => $dentro, 'texto' => $dentro ? ($a === $b ? 'Incluye exactamente los pedidos del formato.' : 'Sus pedidos (' . implode(', ', array_map('dvd_etiqueta', $a)) . ') son del formato.') : 'La devolución tiene pedidos que no son del formato.'];

    $codigos = $d['codigos'] ?? [$d['codigo']];
    $st = $bdd->prepare("SELECT lv.id_libro, SUM(lv.cantidad) AS n, l.libro FROM libros_devol_v lv LEFT JOIN libros l ON l.id = lv.id_libro
                         WHERE lv.cod_pedido IN (" . implode(',', array_fill(0, count($codigos), '?')) . ") GROUP BY lv.id_libro");
    $st->execute($codigos);
    $enDoc = [];
    $nombres = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $enDoc[(int)$r['id_libro']] = (int)$r['n']; $nombres[(int)$r['id_libro']] = $r['libro']; }
    $esperado = fvd_libros_devueltos($f['items']);
    $difs = [];
    foreach ($esperado + $enDoc as $l => $_) {
        if (($esperado[$l] ?? 0) !== ($enDoc[$l] ?? 0)) $difs[] = ($nombres[$l] ?? 'Libro #' . $l) . ': formato ' . ($esperado[$l] ?? 0) . ', documento ' . ($enDoc[$l] ?? 0);
    }
    $checks[] = ['ok' => !$difs, 'texto' => !$difs ? 'Las unidades por título ' . ($varias ? 'de los documentos' : 'del documento') . ' (' . array_sum($enDoc) . ') coinciden con las devueltas del formato.'
                                                 : 'Las unidades no coinciden: ' . implode('; ', $difs) . '.'];
    $ok = !in_array(false, array_column($checks, 'ok'), true);
    return ['ok' => $ok, 'checks' => $checks, 'devolucion' => $d, 'saldo' => dvd_saldo_de_devolucion($bdd, (int)$d['id'])];
}

/**
 * Detalle POR LIBRO de lo que se devuelve (2026-10-22: en el formato no se veía qué quedó en el documento):
 * cada paquete devuelto aporta una unidad de cada uno de sus títulos. Por libro: de qué filas del formato
 * sale ('origen'), lo que debe ir ('esperado'), lo que quedó en el documento de devolución (libros_devol_v)
 * y lo que quedó en el saldo SAL-n (saldos_devolucion_libros). Sin devolución, solo lo esperado.
 * Devuelve [['id_libro','libro','isbn','origen' => [texto],'esperado','en_documento','en_saldo']].
 */
function fvd_detalle_documento(PDO $bdd, array $f) {
    $res = [];
    foreach ($f['items'] as $it) {
        $n = (int)$it['devueltas'];
        foreach (array_filter(explode(',', (string)$it['libros'])) as $l) {
            $l = (int)$l;
            $res[$l] = $res[$l] ?? ['id_libro' => $l, 'origen' => [], 'esperado' => 0, 'en_documento' => 0, 'en_saldo' => 0];
            $res[$l]['esperado'] += $n;
            if ($n > 0) $res[$l]['origen'][] = $n . ' × ' . $it['descripcion'];
        }
    }
    $d = $f['legalizacion'];
    if ($d) {
        $codigos = $d['codigos'] ?? [$d['codigo']];
        $idsDev = $d['ids'] ?? [(int)$d['id']];
        $st = $bdd->prepare("SELECT id_libro, SUM(cantidad) FROM libros_devol_v WHERE cod_pedido IN (" . implode(',', array_fill(0, count($codigos), '?')) . ") GROUP BY id_libro");
        $st->execute($codigos);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $l => $n) {
            $res[(int)$l] = $res[(int)$l] ?? ['id_libro' => (int)$l, 'origen' => [], 'esperado' => 0, 'en_documento' => 0, 'en_saldo' => 0];
            $res[(int)$l]['en_documento'] = (int)$n;
        }
        $st = $bdd->prepare("SELECT sl.id_libro, SUM(sl.cantidad) FROM saldos_devolucion_libros sl JOIN saldos_devolucion s ON s.id = sl.id_saldo
                             WHERE s.id_devolucion IN (" . implode(',', array_map('intval', $idsDev)) . ") GROUP BY sl.id_libro");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $l => $n) {
            $res[(int)$l] = $res[(int)$l] ?? ['id_libro' => (int)$l, 'origen' => [], 'esperado' => 0, 'en_documento' => 0, 'en_saldo' => 0];
            $res[(int)$l]['en_saldo'] = (int)$n;
        }
    }
    if (!$res) return [];
    $st = $bdd->query("SELECT id, libro, isbn FROM libros WHERE id IN (" . implode(',', array_keys($res)) . ")");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $res[(int)$r['id']]['libro'] = $r['libro']; $res[(int)$r['id']]['isbn'] = $r['isbn']; }
    $res = array_values(array_filter($res, fn($r) => $r['esperado'] || $r['en_documento'] || $r['en_saldo']));
    usort($res, fn($a, $b) => strcasecmp($a['libro'] ?? '', $b['libro'] ?? ''));
    return $res;
}

/** Confirma la devolución generada (etapa 3 → 4) si la verificación pasa. */
function fvd_confirmar_devolucion(PDO $bdd, array $f) {
    $v = fvd_verificar_devolucion($bdd, $f);
    if (!$v['ok']) return ['ok' => false, 'error' => 'No se puede confirmar: ' . implode(' ', array_column(array_filter($v['checks'], fn($c) => !$c['ok']), 'texto'))];
    return fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_DEV_GENERADA, function () use ($bdd, $f, $v) {
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_DEV_CONFIRMADA, 'Devolución #' . (int)$v['devolucion']['id']);
        return null;
    }, 'confirmar devolución');
}

/* ───────────────────────────── Paso 4: cierre del inventario ───────────────────────────── */

/**
 * Precio de venta vigente de cada fila: paquete = paquetes_colegio.precio_final; título suelto = "Precio
 * venta padre" de Adopciones (presupuestos.precio_venta_final del colegio y período). [clave => ['precio' => float|null, 'origen']].
 */
function fvd_precios_actuales(PDO $bdd, array $f) {
    $paq = [];
    $lib = [];
    foreach ($f['items'] as $it) {
        if ($it['tipo'] === 'paquete') $paq[(int)$it['id_paquete']] = true; else $lib[(int)$it['id_libro']] = true;
    }
    $pp = [];
    if ($paq) foreach ($bdd->query("SELECT id, precio_final FROM paquetes_colegio WHERE id IN (" . implode(',', array_keys($paq)) . ")") as $r) $pp[(int)$r['id']] = (float)$r['precio_final'];
    $pl = [];
    if ($lib) {
        $st = $bdd->prepare("SELECT id_libro, MAX(precio_venta_final) AS p FROM presupuestos WHERE id_colegio = ? AND id_periodo = ?
                             AND id_libro IN (" . implode(',', array_keys($lib)) . ") GROUP BY id_libro");
        $st->execute([(int)$f['id_colegio'], (int)$f['id_periodo']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pl[(int)$r['id_libro']] = (float)$r['p'];
        // Libro repetido en el catálogo (2026-11-19): WO trae un libros.id (p. ej. 1479) y la adopción tiene el precio en
        // otro del mismo producto (1725). Sin precio propio se toma el del mismo producto adoptado.
        $sinPrecio = array_filter(array_keys($lib), fn($l) => empty($pl[$l]));
        if ($sinPrecio) {
            require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_claves_catalogo
            $st = $bdd->prepare("SELECT id_libro, MAX(precio_venta_final) AS p FROM presupuestos WHERE id_colegio = ? AND id_periodo = ? AND precio_venta_final > 0 GROUP BY id_libro");
            $st->execute([(int)$f['id_colegio'], (int)$f['id_periodo']]);
            $adop = array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
            $cat = dvv_claves_catalogo($bdd, array_merge($sinPrecio, array_keys($adop)));
            $porClave = [];
            foreach ($adop as $l => $p) if (isset($cat[(int)$l])) $porClave[$cat[(int)$l]] = max($porClave[$cat[(int)$l]] ?? 0, $p);
            foreach ($sinPrecio as $l) if (isset($cat[$l], $porClave[$cat[$l]])) $pl[$l] = $porClave[$cat[$l]];
        }
    }
    // Libros de paquetes abiertos (2026-11-13): el precio puesto en la devolución; sin él, el precio venta padre.
    $pDev = [];
    if (!empty($f['legalizacion']['id'])) {
        require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvv_paquetes_abiertos
        foreach ($f['legalizacion']['ids'] ?? [(int)$f['legalizacion']['id']] as $idDevol) {
            foreach (dvv_paquetes_abiertos($bdd, (int)$idDevol) as $idPaq => $pa) {
                foreach ($pa['libros'] as $l => $x) if ($x['precio'] > 0) $pDev[$idPaq . '_' . $l] = (float)$x['precio'];
            }
        }
    }
    $res = fvd_precios_base($bdd, $f, $pp, $pl, $pDev);
    // Tramo de la 2a devolución (2026-11-24): su precio editado o, si no, el mismo de la fila.
    foreach ($f['items'] as $it) {
        $m = isset($it['precio_2_manual']) && (float)$it['precio_2_manual'] > 0 ? (float)$it['precio_2_manual'] : null;
        $res[$it['clave']]['precio_2'] = $m ?? $res[$it['clave']]['precio'];
        $res[$it['clave']]['origen_2'] = $m !== null ? 'Precio editado' : 'Mismo precio de la fila';
    }
    return $res;
}

/** Precio de cada fila (fvd_precios_actuales, sin el tramo de la 2a devolución). */
function fvd_precios_base(PDO $bdd, array $f, array $pp, array $pl, array $pDev) {
    $res = [];
    foreach ($f['items'] as $it) {
        // Precio editado en el cierre de dinero (2026-11-19): se conserva aunque se vuelva a calcular (p. ej. al armar paquetes).
        if (isset($it['precio_manual']) && (float)$it['precio_manual'] > 0) {
            $res[$it['clave']] = ['precio' => (float)$it['precio_manual'], 'origen' => 'Precio editado'];
            continue;
        }
        if ($it['tipo'] === FVD_TIPO_ABIERTO && isset($pDev[(int)$it['id_paquete'] . '_' . (int)$it['id_libro']])) {
            $res[$it['clave']] = ['precio' => $pDev[(int)$it['id_paquete'] . '_' . (int)$it['id_libro']], 'origen' => 'Precio de la devolución'];
            continue;
        }
        // Precio con el que salió en la remisión de WO (2026-11-23, la usuaria; fvd_referencias).
        if (in_array($it['tipo'], ['paquete', 'titulo'], true) && isset($it['precio_remision']) && (float)$it['precio_remision'] > 0) {
            $res[$it['clave']] = ['precio' => (float)$it['precio_remision'], 'origen' => 'Precio de la remisión'];
            continue;
        }
        $p = $it['tipo'] === 'paquete' ? ($pp[(int)$it['id_paquete']] ?? 0) : ($pl[(int)$it['id_libro']] ?? 0);
        $res[$it['clave']] = ['precio' => $p > 0 ? $p : null, 'origen' => $it['tipo'] === 'paquete' ? 'Precio del paquete' : 'Precio venta padre'];
    }
    return $res;
}

/**
 * Conciliación por fila: vendidas = entregado − devueltas; inventario final = devueltas. Con $precios
 * (vigentes) o, si es null, los guardados. Devuelve [filas con 'vendidas','inv_final','precio','total','sin_precio'], totales.
 */
function fvd_conciliar(array $f, $precios = null) {
    $filas = [];
    $t = ['solicitud' => 0, 'entregado' => 0, 'devueltas' => 0, 'inv_final' => 0, 'vendidas' => 0, 'total_venta' => 0, 'valor_inventario' => 0, 'sin_precio' => 0];
    foreach ($f['items'] as $it) {
        $ent = (int)$it['inv_inicial'];
        $dev = (int)$it['devueltas'];
        // Libro suelto de un paquete abierto registrado en el formato: se resta del total de venta a su precio venta padre.
        // Libro de un paquete abierto (FVD_TIPO_ABIERTO): se vende suelto (entregado = paquetes abiertos − devueltas).
        $sueltoPaq = $it['tipo'] === FVD_TIPO_SUELTO_PAQ;
        $abierto = $it['tipo'] === FVD_TIPO_ABIERTO;
        $vend = fvd_vendidas_item($it);
        $precio = $precios === null ? (float)$it['precio'] : $precios[$it['clave']]['precio'];
        $origen = $precios === null ? $it['precio_origen'] : $precios[$it['clave']]['origen'];
        // 2 devoluciones (2026-11-24): dev2 = lo que volvió en la 2a (informativo). De las vendidas, n2 van a $precio2 y el
        // resto (vend1) a $precio; n2 entre 0 y las vendidas (si una devolución nueva baja las vendidas, n2 se ajusta).
        $dev2 = min($dev, (int)($it['devueltas_2'] ?? 0));
        $n2 = $precios !== null && isset($precios[$it['clave']]['cant_2']) ? (int)$precios[$it['clave']]['cant_2'] : (int)($it['vendidas_2'] ?? 0);
        $n2 = $dev2 > 0 ? min(max(0, $n2), max(0, $vend)) : 0; // sin unidades en la 2a devolución no hay precio 2
        $vend1 = $vend - $n2;
        $precio2 = $precios === null ? ((float)($it['precio_2'] ?? 0) ?: $precio) : ($precios[$it['clave']]['precio_2'] ?? $precio);
        $origen2 = $precios === null ? ($it['precio_2_origen'] ?? 'Mismo precio de la fila') : ($precios[$it['clave']]['origen_2'] ?? 'Mismo precio de la fila');
        $sinPrecio = (($sueltoPaq ? $dev > 0 : ($abierto ? $vend1 > 0 : $ent > 0)) && !$precio) || ($n2 > 0 && !$precio2);
        $total = $sinPrecio ? null : round($vend1 * (float)$precio + $n2 * (float)$precio2, 2);
        $filas[] = $it + ['entregado' => $ent, 'dev' => $dev, 'vend' => $vend, 'fin' => $dev, 'precio_v' => $precio, 'origen_v' => $origen,
                          'dev2' => $dev2, 'n2' => $n2, 'vend1' => $vend1, 'precio2_v' => $n2 ? $precio2 : null, 'origen2_v' => $n2 ? $origen2 : null,
                          'total_v' => $total, 'sin_precio' => $sinPrecio];
        // Los libros de un paquete abierto ya cuentan como entregados en la fila del paquete.
        if (!$abierto) { $t['solicitud'] += (int)$it['solicitud']; $t['entregado'] += $ent; }
        $t['devueltas'] += $dev; $t['inv_final'] += $dev; $t['vendidas'] += $vend;
        if ($total !== null) $t['total_venta'] += $total;
        if ($precio) $t['valor_inventario'] += $dev * $precio;
        if ($sinPrecio) $t['sin_precio']++;
    }
    $t['total_venta'] = round($t['total_venta'], 2);
    $t['valor_inventario'] = round($t['valor_inventario'], 2);
    return [$filas, $t];
}

/** Confirma el cierre del inventario (etapa 4 → 5): exige la devolución verificada y precio en todas las filas entregadas; guarda los precios. */
function fvd_confirmar_inventario(PDO $bdd, array $f, $motivo = null) {
    $v = fvd_verificar_devolucion($bdd, $f);
    if (!$v['ok']) return ['ok' => false, 'error' => 'La devolución ya no está verificada: ' . implode(' ', array_column(array_filter($v['checks'], fn($c) => !$c['ok']), 'texto'))];
    [$filas, $t] = fvd_conciliar($f, fvd_precios_actuales($bdd, $f));
    if ($t['sin_precio']) {
        $sin = array_column(array_filter($filas, fn($x) => $x['sin_precio']), 'descripcion');
        return ['ok' => false, 'error' => 'Falta el precio de venta de: ' . implode(', ', $sin) . '. Configúralo en Adopciones del colegio (precio del paquete o precio venta padre) y vuelve a intentarlo.'];
    }
    return fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_DEV_CONFIRMADA, function () use ($bdd, $f, $filas, $t, $motivo) {
        $st = $bdd->prepare("UPDATE formatos_vd_items SET vendidas = ?, vendidas_2 = ?, inv_final = ?, precio = ?, precio_origen = ?, precio_2 = ?, precio_2_origen = ?, total = ? WHERE id = ? AND id_formato = ?");
        foreach ($filas as $x) $st->execute([$x['vend'], $x['n2'], $x['fin'], (float)$x['precio_v'], $x['origen_v'], $x['precio2_v'], $x['origen2_v'], (float)$x['total_v'], (int)$x['id'], (int)$f['id']]);
        $bdd->prepare("UPDATE formatos_vd SET total_venta = ?, valor_inventario = ? WHERE id = ?")->execute([$t['total_venta'], $t['valor_inventario'], (int)$f['id']]);
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_CONCILIADO, $motivo);
        return null;
    }, 'cierre inventario');
}

/**
 * Con la devolución documentada el cierre se hace solo: si está generada y pasa la verificación se confirma
 * (etapa 3 → 4) y, si todas las filas entregadas tienen precio, se calcula y guarda el cierre del inventario
 * (etapa 4 → 5). Se llama al generar la devolución y al abrir el formato (por si luego se completó un precio).
 * Devuelve la etapa en la que queda el formato.
 */
function fvd_avanzar_automatico(PDO $bdd, $id) {
    $f = fvd_obtener($bdd, $id);
    if (!$f || !$f['activo']) return $f['etapa'] ?? 0;
    if ($f['etapa'] === FVD_ETAPA_DEV_GENERADA) {
        if (!fvd_confirmar_devolucion($bdd, $f)['ok']) return $f['etapa'];
        $f = fvd_obtener($bdd, $id);
    }
    if ($f['etapa'] === FVD_ETAPA_DEV_CONFIRMADA) {
        [, $t] = fvd_conciliar($f, fvd_precios_actuales($bdd, $f));
        if (!$t['sin_precio']) {
            fvd_confirmar_inventario($bdd, $f, 'Calculado automáticamente con la devolución #' . (int)($f['legalizacion']['id'] ?? 0));
            $f = fvd_obtener($bdd, $id);
        }
    }
    // Formato automático: la recepción en bodega no se diligencia (se toma lo devuelto) y queda el cierre de dinero.
    if ($f['etapa'] === FVD_ETAPA_CONCILIADO && $f['automatico']) {
        fvd_en_transaccion($bdd, $id, FVD_ETAPA_CONCILIADO, function () use ($bdd, $id) {
            $bdd->prepare("UPDATE formatos_vd_items SET recibidas = devueltas WHERE id_formato = ?")->execute([(int)$id]);
            fvd_pasar_etapa($bdd, $id, FVD_ETAPA_RECIBIDO, 'Automático: sin recepción en bodega (recibidas = devueltas)');
            return null;
        }, 'recepción automática');
        $f = fvd_obtener($bdd, $id);
    }
    return $f['etapa'];
}

/* ───────────────────────── Formato automático desde la devolución ───────────────────────── */

/**
 * Formato de venta directa desde una devolución registrada en Devoluciones de ventas (2026-11-11, la usuaria). Se llama
 * al guardar una devolución de venta directa (php/devoluciones_ventas_guardar.php):
 *  - Si sus pedidos ya están en UN formato activo, se vincula a ese (si el formato sigue en la solicitud, la entrega se
 *    da por hecha con lo despachado) y sigue el cálculo automático de siempre.
 *  - Si no, se crea un formato AUTOMÁTICO (formatos_vd.automatico = 1): datos generales de los pedidos, inventario
 *    entregado = lo despachado en WO (o lo solicitado), devolución vinculada, vendidas y total de venta calculados, y
 *    sin las firmas de entrega ni la recepción en bodega. Queda en el cierre de dinero, donde se llenan los
 *    auxiliares de la venta y el dinero (si falta un precio, queda en el cierre del inventario hasta que se configure).
 * La devolución ya quedó guardada: si algo falla aquí no se deshace, y se devuelve el error para avisarlo.
 * Devuelve ['ok','error','id' (formato),'accion' => 'creado'|'vinculado'|'ya_vinculada','etapa'].
 */
function fvd_desde_devolucion(PDO $bdd, $idDevolucion, $idFormatoElegido = 0) {
    $st = $bdd->prepare("SELECT id, codigo, ids_pedidos_venta, id_colegio, id_periodo, id_formato_vd, estado, created_at FROM devoluciones_v WHERE id = ?");
    $st->execute([(int)$idDevolucion]);
    $dev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dev || (int)$dev['estado'] === DVD_ESTADO_DEVOL_ANULADA || !$dev['ids_pedidos_venta']) return ['ok' => false, 'error' => 'La devolución no existe o está anulada.'];
    if ($dev['id_formato_vd']) return ['ok' => true, 'error' => null, 'id' => (int)$dev['id_formato_vd'], 'accion' => 'ya_vinculada', 'etapa' => null];
    $claves = array_values(array_filter(explode(',', str_replace(' ', '', $dev['ids_pedidos_venta']))));

    // 2026-11-24 (la usuaria): venta elegida al registrar la devolución = consecutivo reservado el día de la venta (sin
    // pedidos). Sus pedidos quedan en ese formato. Si los pedidos ya están en un formato, manda ese (sigue abajo).
    if ((int)$idFormatoElegido > 0 && !array_intersect_key(array_flip($claves), fvd_pendientes_por_pedido($bdd))) {
        require_once __DIR__ . '/solicitudes_vd_datos.php';
        $ll = svd_llenar_reservado($bdd, (int)$idFormatoElegido, $claves);
        if (!$ll['ok']) return $ll + ['id' => (int)$idFormatoElegido];
        $f = fvd_obtener($bdd, (int)$idFormatoElegido);
        $v = fvd_vincular_devolucion($bdd, $f, (int)$dev['id']);
        if (!$v['ok']) return ['ok' => false, 'error' => 'Los pedidos quedaron en el ' . fvd_numero($f['consecutivo']) . ', pero no se pudo vincular la devolución: ' . $v['error'], 'id' => (int)$f['id']];
        return ['ok' => true, 'error' => null, 'id' => (int)$f['id'], 'accion' => 'reservado', 'etapa' => $v['etapa']];
    }

    // ¿Sus pedidos ya están en un formato activo?
    $pendientes = fvd_pendientes_por_pedido($bdd);
    $formatos = [];
    foreach ($claves as $c) if (isset($pendientes[$c])) $formatos[$pendientes[$c]['id']] = $pendientes[$c];
    if (count($formatos) > 1) return ['ok' => false, 'error' => 'Los pedidos de la devolución están en varios formatos de venta directa; vincúlala a mano en el formato que corresponda.'];
    if ($formatos) {
        $f = fvd_obtener($bdd, array_key_first($formatos));
        if (!$f) return ['ok' => false, 'error' => 'El formato de estos pedidos no está en tu alcance.'];
        if (array_diff($claves, explode(',', $f['ids_pedidos']))) {
            return ['ok' => false, 'error' => 'La devolución tiene pedidos que no están en el ' . fvd_numero($f['consecutivo']) . '; vincúlala a mano.'];
        }
        if ($f['etapa'] === FVD_ETAPA_SOLICITUD) {
            // Sin entrega de bodega confirmada: se da por entregado lo despachado (inventario propuesto).
            $r = fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_SOLICITUD, function () use ($bdd, $f, $dev) {
                $bdd->prepare("UPDATE formatos_vd_items SET inv_inicial = inv_propuesto WHERE id_formato = ?")->execute([(int)$f['id']]);
                fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_ENTREGADO, 'Automático: entrega = lo despachado (sin firmas), al registrar la devolución #' . (int)$dev['id']);
                return null;
            }, 'entrega automática');
            if (!$r['ok']) return $r;
            $f = fvd_obtener($bdd, $f['id']);
        }
        // 2026-11-24: si el formato ya tiene devolución (1a de venta directa), esta 2a se suma y descuenta sus unidades.
        if ($f['etapa'] !== FVD_ETAPA_ENTREGADO && !$f['legalizacion']) {
            return ['ok' => false, 'error' => 'El ' . fvd_numero($f['consecutivo']) . ' está en "' . fvd_etapa_texto($f['etapa']) . '"; esta devolución no se vinculó.'];
        }
        $r = fvd_vincular_devolucion($bdd, $f, (int)$dev['id']);
        if (!$r['ok']) return $r + ['id' => (int)$f['id']];
        return ['ok' => true, 'error' => null, 'id' => (int)$f['id'], 'accion' => !empty($r['agregada']) ? 'agregado' : 'vinculado', 'etapa' => $r['etapa'],
                'reabierto' => $f['etapa'] >= FVD_ETAPA_CERRADO];
    }

    // Formato nuevo automático con la propuesta de siempre (filas por paquete/título, despachado WO o solicitado CRM).
    $prop = fvd_propuesta($bdd, $claves);
    if (!$prop['ok']) return $prop;
    if (array_diff($claves, $prop['claves'])) return ['ok' => false, 'error' => 'No todos los pedidos de la devolución pueden entrar en un formato (alguno ya se legalizó).'];
    $fijos = datos_fijos_formato_vd($bdd, $prop['claves']);
    $post = $fijos + ['fecha_venta' => date('Y-m-d', strtotime($dev['created_at']))]; // sin fecha de venta en el pedido: la de la devolución
    $nuevo = fvd_guardar_nuevo($bdd, $prop, $post, bin2hex(random_bytes(16)));
    if (!$nuevo['ok']) return $nuevo;
    $idF = (int)$nuevo['id'];
    $responsable = fvd_texto($fijos['responsable'] ?? responsable_venta_de_pedidos($bdd, $prop['claves']), 150);
    $r = fvd_en_transaccion($bdd, $idF, FVD_ETAPA_SOLICITUD, function () use ($bdd, $idF, $responsable, $dev) {
        // fvd_guardar_nuevo deja inv_inicial = inv_propuesto (lo despachado).
        $bdd->prepare("UPDATE formatos_vd SET automatico = 1, responsable = ?, updated_at = NOW() WHERE id = ?")->execute([$responsable !== '' ? $responsable : null, $idF]);
        fvd_pasar_etapa($bdd, $idF, FVD_ETAPA_ENTREGADO, 'Automático: entrega = lo despachado (sin firmas), con la devolución #' . (int)$dev['id']);
        return null;
    }, 'formato automático');
    if (!$r['ok']) return $r;
    fvd_completar_datos_venta($bdd, $idF); // jornada, contacto y dirección del colegio, responsable del pedido
    $f = fvd_obtener($bdd, $idF);
    $v = fvd_vincular_devolucion($bdd, $f, (int)$dev['id']);
    if (!$v['ok']) return ['ok' => false, 'error' => 'Se creó el ' . fvd_numero($f['consecutivo']) . ', pero no se pudo vincular la devolución: ' . $v['error'], 'id' => $idF];
    return ['ok' => true, 'error' => null, 'id' => $idF, 'accion' => 'creado', 'etapa' => $v['etapa']];
}

/**
 * Formato automático (2026-11-13, la usuaria): llena los datos de la venta que estén vacíos con datos_venta_vd()
 * (jornada de la adopción, contacto del colegio, dirección del colegio, responsable escrito al aprobar el pedido).
 * La fecha de venta pasa a ser la de la jornada (el formato automático se crea con la fecha de la devolución), solo
 * mientras no esté cerrado; un formato cerrado solo completa los datos vacíos.
 */
function fvd_completar_datos_venta(PDO $bdd, $idFormato) {
    $st = $bdd->prepare("SELECT id, id_colegio, id_periodo, ids_pedidos, fecha_venta, hora_inicio, hora_fin, responsable, tel_responsable,
                                contacto_colegio, tel_contacto, direccion, automatico, etapa, estado, created_at FROM formatos_vd WHERE id = ?");
    $st->execute([(int)$idFormato]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f || !(int)$f['automatico'] || (int)$f['estado'] !== FVD_ESTADO_ACTIVO) return false;
    $cerrado = (int)$f['etapa'] >= FVD_ETAPA_CERRADO;
    // Fechas de venta de los otros formatos activos del colegio: esas jornadas ya están atendidas.
    $st = $bdd->prepare("SELECT fecha_venta FROM formatos_vd WHERE id_colegio = ? AND id_periodo = ? AND estado = " . FVD_ESTADO_ACTIVO . " AND id <> ?");
    $st->execute([(int)$f['id_colegio'], (int)$f['id_periodo'], (int)$f['id']]);
    $ocupadas = $st->fetchAll(PDO::FETCH_COLUMN);
    $datos = datos_venta_vd($bdd, explode(',', $f['ids_pedidos']), date('Y-m-d', strtotime($f['created_at'])), $ocupadas);
    $set = [];
    foreach ($datos as $campo => $valor) {
        if ($campo === 'fecha_venta') { if (!$cerrado && $valor !== $f['fecha_venta'] && isset($datos['hora_inicio']) && $f['hora_inicio'] === null) $set[$campo] = $valor; continue; }
        if ($cerrado && in_array($campo, ['hora_inicio', 'hora_fin'], true)) continue; // la hora va con la fecha de la jornada
        if (trim((string)$f[$campo]) === '') $set[$campo] = $valor;
    }
    if (!$set) return false;
    $bdd->prepare("UPDATE formatos_vd SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . " WHERE id = ?")
        ->execute(array_merge(array_values($set), [(int)$f['id']]));
    return true;
}

/* ───────────────────────────── Paso 5: recepción en bodega ───────────────────────────── */

/**
 * Registra la recepción de la devolución en bodega (etapa 5 → 6): aux. que recibe + firma, fecha y hora,
 * cantidad recibida por fila. Si no cuadra con lo devuelto exige justificación. Marca la devolución
 * (devoluciones_v) como Recibido si seguía Pendiente.
 */
function fvd_confirmar_recepcion(PDO $bdd, array $f, array $post, array $files) {
    $aux = fvd_texto($post['aux_recibe'] ?? '', 150);
    if ($aux === '') return ['ok' => false, 'error' => 'Escribe el auxiliar de bodega que recibe.'];
    $fh = (string)($post['recibido_at'] ?? '');
    $ts = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $fh) ? strtotime($fh) : false;
    if (!$ts) return ['ok' => false, 'error' => 'Escribe la fecha y hora de recepción.'];
    // Cualquier hora de hoy (la recepción se puede registrar después de la hora en que se abrió la página); días futuros no.
    if (date('Y-m-d', $ts) > date('Y-m-d')) return ['ok' => false, 'error' => 'La fecha de recepción no puede ser un día futuro.'];
    $recibidas = [];
    $difs = [];
    foreach ($f['items'] as $it) {
        $n = fvd_entero($post['recibidas'][$it['clave']] ?? '');
        if ($n === null) return ['ok' => false, 'error' => 'Revisa "' . $it['descripcion'] . '": la cantidad recibida debe ser un número entero.'];
        $recibidas[(int)$it['id']] = $n;
        if ($n !== (int)$it['devueltas']) $difs[] = $it['descripcion'] . ' (devueltas ' . (int)$it['devueltas'] . ', recibidas ' . $n . ')';
    }
    $obs = fvd_texto_largo($post['obs_recepcion'] ?? '');
    if ($difs && $obs === '') return ['ok' => false, 'error' => 'Lo recibido no coincide con la devolución en: ' . implode('; ', $difs) . '. Revisa las cantidades o escribe la justificación de la diferencia.'];
    $l = fvd_leer_firma($post, $files, 'recibe_aux');
    if (!$l['ok']) return $l;
    $escritas = fvd_escribir_archivos_firma($f['id'], ['recibe_aux' => $l]);
    if ($escritas === null) return ['ok' => false, 'error' => 'No se pudo guardar la firma en el servidor. Intenta de nuevo.'];
    $idDev = (int)($f['legalizacion']['id'] ?? 0);

    $res = fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_CONCILIADO, function () use ($bdd, $f, $aux, $ts, $obs, $recibidas, $escritas, $idDev, $difs) {
        if (!$idDev || !fvd_legalizacion($bdd, $f['id'])) return 'La devolución del formato ya no está activa.';
        $bdd->prepare("UPDATE formatos_vd SET aux_recibe = ?, recibido_at = ?, obs_recepcion = ? WHERE id = ?")->execute([$aux, date('Y-m-d H:i:s', $ts), $obs, (int)$f['id']]);
        $st = $bdd->prepare("UPDATE formatos_vd_items SET recibidas = ? WHERE id = ? AND id_formato = ?");
        foreach ($recibidas as $idItem => $n) $st->execute([$n, $idItem, (int)$f['id']]);
        $bdd->prepare("DELETE FROM formatos_vd_firmas WHERE id_formato = ? AND tipo = 'recibe_aux'")->execute([(int)$f['id']]);
        fvd_registrar_firmas($bdd, $f['id'], $escritas, ['recibe_aux' => $aux]);
        // La devolución queda Recibido (estados_dev 2) como con el botón "Recibir" de devolucion_colegio.php.
        $bdd->prepare("UPDATE devoluciones_v SET estado = 2 WHERE id = ? AND estado = 1")->execute([$idDev]);
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_RECIBIDO, $difs ? 'Con diferencias: ' . mb_substr(implode('; ', $difs), 0, 250) : null);
        return null;
    }, 'recepción');
    if (!$res['ok']) fvd_borrar_archivos_firma($escritas);
    return $res;
}

/* ───────────────────────────── Paso 6: cierre de dinero ───────────────────────────── */

/**
 * Guarda el cierre de dinero; con $cerrar confirma el cierre (etapa 6 → 7), que exige justificar la
 * diferencia entre lo recaudado y el total de venta si no es cero.
 */
function fvd_guardar_dinero(PDO $bdd, array $f, array $post, $cerrar) {
    $d = [];
    foreach (['base_entregada' => 'Base entregada', 'gastos' => 'Gastos', 'efectivo' => 'Efectivo', 'datafono' => 'Datáfono', 'breb' => 'Bre-B'] as $k => $lbl) {
        $v = fvd_leer_pesos($post[$k] ?? '');
        if ($v === null) return ['ok' => false, 'error' => $lbl . ': escribe un valor en pesos, sin decimales.'];
        $d[$k] = $v;
    }
    if ($d['gastos'] > $d['base_entregada']) return ['ok' => false, 'error' => 'Los gastos no pueden ser más que la base entregada.'];
    // Precio aplicado editable (2026-11-19, la usuaria): $post['precio'][id del ítem]. Si cambia respecto al guardado queda
    // como precio editado (precio_manual, también manda al volver a calcular) y se recalculan los totales de venta.
    $precios = [];
    $manual = [];
    $manual2 = [];
    foreach ($f['items'] as $it) {
        $p = (float)$it['precio'];
        $origen = $it['precio_origen'];
        $manual[(int)$it['id']] = isset($it['precio_manual']) && $it['precio_manual'] !== null ? (float)$it['precio_manual'] : null;
        if (isset($post['precio'][$it['id']])) {
            $v = fvd_leer_pesos($post['precio'][$it['id']]);
            if ($v === null) return ['ok' => false, 'error' => 'Precio de "' . $it['descripcion'] . '": escribe un valor en pesos, sin decimales.'];
            if ($v != round($p)) {
                $p = (float)$v;
                $origen = 'Precio editado';
                $manual[(int)$it['id']] = $v > 0 ? (float)$v : null;
            }
        }
        // 2 devoluciones (2026-11-24): cant2[id] = vendidas a precio 2 (0 a las vendidas) y precio2[id]; si el precio 2
        // cambia queda como precio_2_manual.
        $vendFila = fvd_vendidas_item($it);
        $n2 = (int)($it['vendidas_2'] ?? 0);
        if (isset($post['cant2'][$it['id']])) {
            $txt = trim((string)$post['cant2'][$it['id']]);
            if ($txt !== '' && !preg_match('/^\d+$/', $txt)) return ['ok' => false, 'error' => 'Vendidas a precio 2 de "' . $it['descripcion'] . '": escribe un número entero.'];
            $n2 = (int)$txt;
            if ($n2 > max(0, $vendFila)) return ['ok' => false, 'error' => 'De "' . $it['descripcion'] . '" se vendieron ' . max(0, $vendFila) . ' unidad(es): a precio 2 no pueden ir ' . $n2 . '.'];
            // Solo las filas con unidades en la 2a devolución admiten precio 2 (2026-11-24, la usuaria).
            if ($n2 > 0 && (int)($it['devueltas_2'] ?? 0) <= 0) return ['ok' => false, 'error' => '"' . $it['descripcion'] . '" no tuvo unidades en la 2a devolución: no admite precio 2.'];
        }
        $p2 = (float)($it['precio_2'] ?? 0) ?: $p;
        $origen2 = $it['precio_2_origen'] ?? 'Mismo precio de la fila';
        $manual2[(int)$it['id']] = isset($it['precio_2_manual']) && $it['precio_2_manual'] !== null ? (float)$it['precio_2_manual'] : null;
        if (isset($post['precio2'][$it['id']])) {
            $v = fvd_leer_pesos($post['precio2'][$it['id']]);
            if ($v === null) return ['ok' => false, 'error' => 'Precio de la 2a devolución de "' . $it['descripcion'] . '": escribe un valor en pesos, sin decimales.'];
            if ($v != round($p2)) {
                $p2 = (float)$v;
                $origen2 = 'Precio editado';
                $manual2[(int)$it['id']] = $v > 0 ? (float)$v : null;
            }
        }
        $precios[$it['clave']] = ['precio' => $p > 0 ? $p : null, 'origen' => $origen, 'precio_2' => $p2 > 0 ? $p2 : null, 'origen_2' => $origen2, 'cant_2' => $n2];
    }
    [$filas, $t] = fvd_conciliar($f, $precios);
    // Tabla por grado del cierre de ventas (2026-11-26, formatos_vd_libros.php): cantidades y precios por libro escritos a
    // mano; su gran total (kits completos × precio del kit + sueltos × precio suelto) es el total de venta.
    $cl = null;
    $libros = fvdl_filas($bdd, $f['id']);
    if ($libros) {
        $leidos = fvdl_leer($libros, $post);
        if (isset($leidos['error'])) return ['ok' => false, 'error' => $leidos['error']];
        $libros = $leidos['filas'];
        // ¿La venta tuvo novedades? (casilla del cierre): sin novedades los kits se arman solos con lo vendido.
        $novedades = isset($post['cl_novedades']) ? (int)((string)$post['cl_novedades'] === '1') : null;
        $cl = fvdl_calcular($bdd, $f, $libros, $novedades);
        $d['libros_novedades'] = (int)$cl['novedades']; // Kit / Suelto / Sin inventario se guardan tal como se escribieron
        if ($cerrar && $cl['errores']) {
            return ['ok' => false, 'error' => 'Revisa la tabla del cierre antes de cerrar: ' . implode(' ', array_slice($cl['errores'], 0, 3)) . (count($cl['errores']) > 3 ? ' (y ' . (count($cl['errores']) - 3) . ' más)' : '')];
        }
    } elseif ($t['sin_precio']) {
        return ['ok' => false, 'error' => 'Falta el precio aplicado de: ' . implode(', ', array_column(array_filter($filas, fn($x) => $x['sin_precio']), 'descripcion')) . '.'];
    }
    $d['total_venta'] = $cl ? $cl['gran_total'] : $t['total_venta'];
    $d['valor_inventario'] = $t['valor_inventario'];
    $d['base_devuelta'] = $d['base_entregada'] - $d['gastos'];
    $d['suma_total'] = $d['efectivo'] + $d['datafono'] + $d['breb'];
    $d['diferencia'] = round($d['suma_total'] - (float)$d['total_venta'], 2);
    $d['obs_cierre'] = fvd_texto_largo($post['obs_cierre'] ?? '');
    // Responsable de la venta (2026-11-23, la usuaria): obligatorio para cerrar. Se propone el escrito al aprobar el pedido;
    // si no quedó, se escribe en el cierre. El que viene fijo del pedido (para Andrea Díaz) manda sobre lo enviado.
    if (array_key_exists('responsable', $post)) {
        $fijos = datos_fijos_formato_vd($bdd, explode(',', $f['ids_pedidos']));
        $resp = fvd_texto($fijos['responsable'] ?? $post['responsable'], 150);
        $d['responsable'] = $resp !== '' ? $resp : null;
        if (array_key_exists('tel_responsable', $post)) {
            $tel = fvd_texto($post['tel_responsable'], 40);
            $d['tel_responsable'] = $tel !== '' ? $tel : null;
        }
    }
    if ($cerrar && trim((string)(array_key_exists('responsable', $d) ? $d['responsable'] : $f['responsable'])) === '') {
        return ['ok' => false, 'error' => 'Escribe el responsable de la venta para cerrar la operación.'];
    }
    if ($cerrar && round($d['diferencia']) != 0 && $d['obs_cierre'] === '') {
        return ['ok' => false, 'error' => 'El recaudo no cuadra con el total de venta (diferencia $ ' . number_format($d['diferencia'], 0, ',', '.') . '). Justifica la diferencia para cerrar la operación.'];
    }
    // Formato automático (sin paso de entrega): los auxiliares de la venta se llenan en el cierre de dinero.
    $aux = null;
    if ($f['automatico']) {
        $aux = fvd_leer_auxiliares($post);
        if (isset($aux['error'])) return ['ok' => false, 'error' => $aux['error']];
        $d['auxiliares'] = mb_substr(implode(', ', array_column($aux, 'nombre')), 0, 255);
        // Total de cajas (2026-11-13): sin paso de entrega, se registra en el cierre de dinero.
        $txt = trim((string)($post['total_cajas'] ?? ''));
        $cajas = fvd_entero($txt);
        if ($txt !== '' && $cajas === null) return ['ok' => false, 'error' => 'Total de cajas: escribe un número entero.'];
        if ($cerrar && $cajas === null) return ['ok' => false, 'error' => 'Escribe el total de cajas para cerrar la operación.'];
        $d['total_cajas'] = $cajas;
    }
    return fvd_en_transaccion($bdd, $f['id'], FVD_ETAPA_RECIBIDO, function () use ($bdd, $f, $d, $cerrar, $aux, $filas, $manual, $manual2, $cl, $libros) {
        $bdd->prepare("UPDATE formatos_vd SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($d))) . ", updated_at = NOW() WHERE id = ?")
            ->execute(array_merge(array_values($d), [(int)$f['id']]));
        if ($cl) fvdl_guardar_filas($bdd, $f['id'], $libros);
        $up = $bdd->prepare("UPDATE formatos_vd_items SET vendidas_2 = ?, precio = ?, precio_origen = ?, precio_manual = ?, precio_2 = ?, precio_2_origen = ?, precio_2_manual = ?, total = ? WHERE id = ? AND id_formato = ?");
        foreach ($filas as $x) $up->execute([$x['n2'], (float)$x['precio_v'], $x['origen_v'], $manual[(int)$x['id']], $x['precio2_v'], $x['origen2_v'], $manual2[(int)$x['id']] ?? null, (float)$x['total_v'], (int)$x['id'], (int)$f['id']]);
        if ($aux !== null) {
            $bdd->prepare("DELETE FROM formatos_vd_auxiliares WHERE id_formato = ?")->execute([(int)$f['id']]);
            $st = $bdd->prepare("INSERT INTO formatos_vd_auxiliares (id_formato, orden, nombre, valor) VALUES (?, ?, ?, ?)");
            foreach ($aux as $i => $a) $st->execute([(int)$f['id'], $i, $a['nombre'], $a['valor']]);
        }
        if ($cerrar) fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_CERRADO, round($d['diferencia']) != 0 ? 'Diferencia $ ' . number_format($d['diferencia'], 0, ',', '.') . ' justificada' : null);
        return null;
    }, 'cierre dinero');
}

/* ───────────────────────────── Anulación ───────────────────────────── */

/** Anula un formato con motivo (libera sus pedidos). Solo antes de generar la devolución. */
function fvd_anular(PDO $bdd, $id, $motivo) {
    $f = fvd_obtener($bdd, $id);
    if (!$f) return ['ok' => false, 'error' => 'El formato no existe o no está en tu alcance.'];
    if (!$f['activo']) return ['ok' => false, 'error' => 'El formato ya está anulado.'];
    if ($f['legalizacion']) return ['ok' => false, 'error' => 'El formato ya tiene la devolución #' . (int)$f['legalizacion']['id'] . '; anula primero la devolución.'];
    if (!$f['anulable']) return ['ok' => false, 'error' => 'El formato ya pasó la etapa de devolución; no se puede anular.'];
    if ($motivo === '') return ['ok' => false, 'error' => 'Escribe el motivo de la anulación.'];
    $bdd->prepare("UPDATE formatos_vd SET estado = " . FVD_ESTADO_ANULADO . ", updated_at = NOW() WHERE id = ?")->execute([(int)$id]);
    registrar_historial_estado($bdd, FVD_MODULO_HISTORIAL, $id, ESTADO_ANULADO, (int)$_SESSION['id'], $motivo);
    return ['ok' => true, 'error' => null];
}

/** Formato de venta directa activo del que sale la devolución: ['id','consecutivo','etapa'] o null. */
function fvd_formato_de_devolucion(PDO $bdd, $idDevolucion) {
    $st = $bdd->prepare("SELECT f.id, f.consecutivo, f.etapa, f.automatico FROM devoluciones_v d JOIN formatos_vd f ON f.id = d.id_formato_vd WHERE d.id = ? AND f.estado = " . FVD_ESTADO_ACTIVO);
    $st->execute([(int)$idDevolucion]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Antes de anular una devolución en devolucion_colegio.php: si sale de un formato ya recibido en bodega o
 * cerrado no se puede (devuelve el error). Si no, null; tras anularla, fvd_tras_anular_devolucion().
 */
function fvd_bloqueo_anular_devolucion(PDO $bdd, $idDevolucion) {
    $f = fvd_formato_de_devolucion($bdd, $idDevolucion);
    // Formato automático: no hubo recepción en bodega; se bloquea solo con la operación cerrada.
    if ($f && !empty($f['automatico'])) {
        return (int)$f['etapa'] >= FVD_ETAPA_CERRADO ? 'Esta devolución es del ' . fvd_numero($f['consecutivo']) . ', que ya tiene la operación cerrada; no se puede anular.' : null;
    }
    if ($f && (int)$f['etapa'] >= FVD_ETAPA_RECIBIDO) {
        return 'Esta devolución ya se recibió en bodega en el ' . fvd_numero($f['consecutivo']) . ' (' . fvd_etapa_texto($f['etapa']) . '); no se puede anular.';
    }
    return null;
}

/**
 * Devolución anulada: su formato vuelve a "Devolución pendiente" (etapa 2) con las devueltas como borrador. Un formato
 * automático se creó con esa devolución: se anula también (sus pedidos quedan libres para la siguiente devolución).
 */
function fvd_tras_anular_devolucion(PDO $bdd, $idDevolucion) {
    $f = fvd_formato_de_devolucion($bdd, $idDevolucion);
    // 2026-11-24: el formato tiene otra devolución activa (venta directa admite 2): se recalcula solo con las que quedan.
    if ($f && (int)$f['etapa'] < FVD_ETAPA_CERRADO && ($restantes = fvd_devoluciones_formato($bdd, $f['id']))) {
        $fc = fvd_obtener($bdd, $f['id']);
        if ($fc && $fc['activo']) {
            $r = fvd_aplicar_devoluciones($bdd, $fc, $restantes, null, 'Se anuló la devolución #' . (int)$idDevolucion . '; el formato queda con ' . $fc['legalizacion']['texto']);
            if (!$r['ok']) error_log('Formato venta directa ' . (int)$f['id'] . ': no se recalculó tras anular la devolución #' . (int)$idDevolucion . ': ' . $r['error']);
        }
        return;
    }
    if ($f && !empty($f['automatico']) && (int)$f['etapa'] < FVD_ETAPA_CERRADO) {
        $bdd->prepare("UPDATE formatos_vd SET estado = " . FVD_ESTADO_ANULADO . ", updated_at = NOW() WHERE id = ?")->execute([(int)$f['id']]);
        registrar_historial_estado($bdd, FVD_MODULO_HISTORIAL, $f['id'], ESTADO_ANULADO, (int)$_SESSION['id'], 'Se anuló la devolución #' . (int)$idDevolucion . ' con la que se creó el formato automático');
        return;
    }
    if ($f && (int)$f['etapa'] >= FVD_ETAPA_DEV_GENERADA && (int)$f['etapa'] < FVD_ETAPA_RECIBIDO) {
        fvd_pasar_etapa($bdd, $f['id'], FVD_ETAPA_ENTREGADO, 'Se anuló la devolución #' . (int)$idDevolucion);
    }
}
