<?php
/**
 * /includes/colocacion_cruce.php
 * Decide a qué colegio corresponde un documento de World Office en Colocación, y en qué período
 * queda. Reemplaza a emparejar_colegio() + cliente_coincide_con_recursos() en este módulo, pedido
 * por la usuaria 2026-11-03: colegios bien escritos quedaban sin cruzar.
 *
 * Medido contra las 355 asignaciones manuales de ese día: la validación anterior exigía que el
 * tercero fuera igual al cliente de `recursos`, pero 44 de 44 documentos con cliente distinto eran
 * del colegio correcto (el tercero suele ser un distribuidor o un padre de familia) y 2026 casi no
 * tiene clientes cargados. Por eso el cliente ya no descarta: solo ayuda a elegir entre homónimos.
 *
 * Orden del cruce, de la señal más confiable a la menos:
 *  1. OP del concepto → pedido o devolución del CRM → colegio (169 de 171 aciertos). Si el concepto
 *     también trae "PED" y no es de esa OP, la OP está mal escrita y no se usa.
 *  2. Texto del colegio que ya se asignó a mano a un único colegio: corrige homónimos y errores de
 *     escritura ("TERESIANO" → "Colegio Teresiano Bogotá") y aprende de cada asignación manual.
 *  3. Nombre idéntico, o el texto es una forma abreviada de un único colegio ("MARIE CURIE"). Si es
 *     al revés (el nombre del colegio cabe dentro de un texto más largo) se exige que el cliente
 *     coincida: "LICEO NUEVO CHILE" contiene "Nuevo Liceo" y no es ese colegio.
 *  4. Conceptos sin "COL": solo nombre idéntico de 2+ palabras ("VTA DTA; LEON MAGNO").
 */
require_once __DIR__ . '/matching_colegios.php';
require_once __DIR__ . '/matching_pedidos_wo.php';

/** Desde esta fecha, los documentos de colegios de Calendario A van a 2027 (dada por la usuaria). */
const COLOCACION_FECHA_INICIO_2027_CAL_A = '2026-11-01';

function colocacion_clave_nombre($texto) {
    return quitar_stopwords(normalizar_nombre_colegio($texto));
}

/** Nombre de persona o empresa sin importar el orden: "ARCOS CERON CESAR" = "CESAR ARCOS CERON". */
function colocacion_clave_tercero($texto) {
    $palabras = array_filter(explode(' ', normalizar_nombre_colegio($texto)), 'strlen');
    sort($palabras);
    return implode(' ', $palabras);
}

/** Id de la OP en el concepto: "OP 2026-5769", "OP:2026-5220", "OP-2026-5282", "OP # 2026 - 5761". */
function colocacion_extraer_op($concepto) {
    if (!preg_match('/\bOP\b[\s:.#-]*(?:OP[\s:.#-]*)?\d{4}\s*-\s*(\d+)/i', (string)$concepto, $m)) return null;
    return (int)$m[1];
}

/** Número de pedido en el concepto: "PED 1996", "PED1876". */
function colocacion_extraer_ped($concepto) {
    if (!preg_match('/\bPED\.?\s*(\d+)/i', (string)$concepto, $m)) return null;
    return (int)$m[1];
}

/** Nombre en un concepto sin "COL": "VTA DTA; LEON MAGNO", "PED 44354; INSTITUTO PEDAGOGICO NACIONAL". */
function colocacion_extraer_nombre_sin_col($concepto) {
    $texto = preg_replace('/\bOP\b[\s:.#-]*(?:OP[\s:.#-]*)?\d{4}\s*-\s*\d+/i', '', (string)$concepto);
    $texto = preg_replace('/\bPED\.?\s*\d+|\bVTA\s+DTA\b/i', ';', $texto);
    foreach (explode(';', $texto) as $parte) {
        if (trim($parte) !== '') return trim($parte);
    }
    return null;
}

/** Precarga, una vez por sincronización, todo lo que necesita colocacion_cruzar_documento(). */
function colocacion_cargar_contexto_cruce($bdd) {
    $ctx = ['calendario' => [], 'clavePorColegio' => [], 'porNombre' => [], 'colegiosPorOp' => [], 'clientes' => [], 'aprendido' => [], 'cache' => [],
            'pedidosPorOp' => cargar_pedidos_por_op($bdd)];

    foreach ($bdd->query("SELECT id, colegio, id_calendario FROM colegios")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $ctx['calendario'][(int)$c['id']] = (int)$c['id_calendario'];
        $clave = colocacion_clave_nombre($c['colegio']);
        $ctx['clavePorColegio'][(int)$c['id']] = $clave;
        if ($clave !== '') $ctx['porNombre'][$clave][] = (int)$c['id'];
    }

    $consultasOp = [
        "SELECT o.id, p.id_colegio FROM ordenes_pedidos o JOIN pedidos p ON p.id = o.id_pedido WHERE o.id_pedido <> 0 AND p.id_colegio > 0",
        "SELECT a.op, p.id_colegio FROM op_pedidos_agrupados a JOIN pedidos p ON p.id = a.id_pedido WHERE p.id_colegio > 0",
        "SELECT o.id, d.id_colegio FROM ordenes_pedidos o JOIN devoluciones_v d ON d.id = o.id_devol_v WHERE d.id_colegio > 0",
    ];
    foreach ($consultasOp as $sql) {
        foreach ($bdd->query($sql)->fetchAll(PDO::FETCH_NUM) as [$op, $idColegio]) {
            $ctx['colegiosPorOp'][(int)$op][(int)$idColegio] = true;
        }
    }

    foreach ($bdd->query("SELECT r.id_colegio, cl.cliente FROM recursos r JOIN clientes cl ON cl.id = r.cliente WHERE r.cliente <> 0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['clientes'][(int)$r['id_colegio']][colocacion_clave_tercero($r['cliente'])] = true;
    }

    foreach ($bdd->query("SELECT colegio_extraido, id_colegio FROM wo_documentos_colocacion
        WHERE asignado_manual = 1 AND id_colegio IS NOT NULL AND colegio_extraido IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $clave = colocacion_clave_nombre($r['colegio_extraido']);
        if ($clave !== '') $ctx['aprendido'][$clave][(int)$r['id_colegio']] = true;
    }

    // Períodos por calendario: el último de B (el vigente, ver php/colocacion_asignar_colegio.php) y
    // 2026 y 2027 de A, que se eligen por la fecha del documento.
    $ctx['periodosB'] = [];
    $ctx['periodosA'] = [];
    foreach ($bdd->query("SELECT id, periodo, id_calendario FROM periodos WHERE id_calendario IN (1, 2) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ((int)$p['id_calendario'] === 2) $ctx['periodosB'][(int)$p['id']] = true;
        else $ctx['periodosA'][(string)$p['periodo']] = (int)$p['id'];
    }
    $idsB = array_keys($ctx['periodosB']);
    $ctx['ultimoPeriodoB'] = $idsB ? end($idsB) : 0;
    return $ctx;
}

/** Candidatos por nombre para un texto ya normalizado; guardados en caché porque el recorrido es caro. */
function colocacion_candidatos_nombre(array &$ctx, $clave, $soloExacto) {
    if (isset($ctx['porNombre'][$clave])) return $ctx['porNombre'][$clave];
    if ($soloExacto) return [];
    if (isset($ctx['cache'][$clave])) return $ctx['cache'][$clave];
    $candidatos = [];
    foreach ($ctx['porNombre'] as $nombre => $ids) {
        // Un nombre real de una sola palabra es genérico ("Bolivar", "Americano"): solo vale exacto.
        if (strpos($nombre, ' ') === false) continue;
        if (contiene_palabras_completas($nombre, $clave) || contiene_palabras_completas($clave, $nombre)) {
            foreach ($ids as $id) $candidatos[] = $id;
        }
    }
    return $ctx['cache'][$clave] = $candidatos;
}

function colocacion_resolver_texto(array &$ctx, $texto, $tercero, $soloExacto) {
    $clave = colocacion_clave_nombre($texto);
    if ($clave === '') return null;

    if (isset($ctx['aprendido'][$clave]) && count($ctx['aprendido'][$clave]) === 1) {
        return (int)array_key_first($ctx['aprendido'][$clave]);
    }

    $esExacto = isset($ctx['porNombre'][$clave]);
    $candidatos = colocacion_candidatos_nombre($ctx, $clave, $soloExacto);
    if (!$candidatos) return null;

    $claveTercero = colocacion_clave_tercero($tercero);
    $conCliente = array_values(array_filter($candidatos, fn($id) => isset($ctx['clientes'][$id][$claveTercero])));
    if (count($conCliente) === 1) return $conCliente[0];
    if (count($candidatos) !== 1) return null;

    if ($esExacto) return $soloExacto && strpos($clave, ' ') === false ? null : $candidatos[0];

    // Sin confirmación del cliente, una sola palabra es muy poca evidencia, y solo vale que el texto
    // sea una forma abreviada del nombre, no que el nombre quepa dentro de un texto más largo.
    if (strpos($clave, ' ') === false) return null;
    return contiene_palabras_completas($clave, $ctx['clavePorColegio'][$candidatos[0]]) ? $candidatos[0] : null;
}

/** Colegio según la OP del concepto, o null si la OP no existe, es de varios colegios o no cuadra con el PED. */
function colocacion_colegio_por_op(array $ctx, $concepto) {
    $op = colocacion_extraer_op($concepto);
    if ($op === null || !isset($ctx['colegiosPorOp'][$op]) || count($ctx['colegiosPorOp'][$op]) !== 1) return null;
    $ped = colocacion_extraer_ped($concepto);
    $pedidos = $ctx['pedidosPorOp'][$op] ?? [];
    if ($ped !== null && $pedidos && !in_array($ped, $pedidos, true)) return null;
    return (int)array_key_first($ctx['colegiosPorOp'][$op]);
}

/** Devuelve el id del colegio del documento, o null si queda sin cruzar. */
function colocacion_cruzar_documento(array &$ctx, $concepto, $textoColegio, $tercero) {
    $idColegio = colocacion_colegio_por_op($ctx, $concepto);
    if (!$idColegio && $textoColegio !== null && $textoColegio !== '') {
        $idColegio = colocacion_resolver_texto($ctx, $textoColegio, $tercero, false);
    } elseif (!$idColegio) {
        $nombre = colocacion_extraer_nombre_sin_col($concepto);
        if ($nombre !== null) $idColegio = colocacion_resolver_texto($ctx, $nombre, $tercero, true);
    }
    // Este reporte solo maneja Calendario A y B.
    return $idColegio && in_array($ctx['calendario'][$idColegio] ?? 0, [1, 2], true) ? $idColegio : null;
}

/**
 * Período de un documento de Calendario A según su fecha (regla de la usuaria 2026-11-03).
 * $periodosA: ['2026' => id, '2027' => id].
 */
function colocacion_periodo_calendario_a(array $periodosA, $fecha) {
    $periodo = (string)$fecha >= COLOCACION_FECHA_INICIO_2027_CAL_A ? '2027' : '2026';
    return $periodosA[$periodo] ?? null;
}

/**
 * Período de un documento ya cruzado o sin cruzar. Calendario A: por fecha. Calendario B y sin
 * cruzar: conserva el período B que ya tenía, para que crear un período B nuevo no mueva el histórico.
 */
function colocacion_periodo_documento(array $ctx, $idColegio, $fecha, $idPeriodoActual) {
    if ($idColegio && ($ctx['calendario'][$idColegio] ?? 0) === 1) {
        $idA = colocacion_periodo_calendario_a($ctx['periodosA'], $fecha);
        if ($idA) return $idA;
    }
    return isset($ctx['periodosB'][(int)$idPeriodoActual]) ? (int)$idPeriodoActual : $ctx['ultimoPeriodoB'];
}
