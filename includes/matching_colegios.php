<?php
/**
 * /includes/matching_colegios.php
 * Cruza el texto de "colegio" que trae el concepto de un documento de World
 * Office (ej. "COL GIMNASIO LOS FARALLONES VALLE DE LILI") contra el nombre
 * real en `colegios.colegio` (ej. "Gimnasio Los Farallones Valle Del Lili").
 * No siempre coinciden literal: World Office abrevia/omite palabras como
 * "del" ("VALLE DE LILI" vs "Valle Del Lili"), así que la comparación se
 * hace normalizada y sin stopwords.
 */

function normalizar_nombre_colegio($texto) {
    $texto = trim((string)$texto);
    $texto = mb_strtolower($texto, 'UTF-8');
    $reemplazos = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
    $texto = strtr($texto, $reemplazos);
    $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim($texto);
}

function quitar_stopwords($normalizado) {
    // "col"/"colegio" se quitan también: algunos colegios tienen ese prefijo como parte
    // literal de su nombre en la BD (ej. "COL COLOMBO FRANCES (Cal B)"), y como el texto del
    // concepto siempre trae su propio "COL " que ya se separó en extraer_colegio_de_concepto(),
    // dejarlo aquí duplicaba la palabra solo de un lado y rompía la coincidencia.
    $stopwords = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'col', 'colegio'];
    $palabras = explode(' ', $normalizado);
    $palabras = array_filter($palabras, function ($p) use ($stopwords) {
        return $p !== '' && !in_array($p, $stopwords, true);
    });
    return implode(' ', $palabras);
}

/**
 * Extrae el nombre de colegio de un concepto de World Office. El patrón más común es
 * "OP 2026-...; PED ...; COL <NOMBRE>; ENVIAR A ...; ..." (el colegio en la 3ra posición),
 * pero también aparece seguido, sin punto y coma propio, al final del texto:
 * "...; PEDIDO DE VENTA CAL A2026; COL <NOMBRE>" o "...; PEDIDO DE VENTA COL <NOMBRE>".
 * En ambos casos "COL " es la única mención real del colegio en el concepto, así que se toma
 * la ÚLTIMA (evita confundir con un "COL" que apareciera antes por casualidad en el texto de
 * la dirección). Devuelve null si el concepto no trae ningún "COL " seguido de texto.
 */
function extraer_colegio_de_concepto($concepto) {
    if (!preg_match_all('/\bCOL\.?\s+([^;]+)/i', (string)$concepto, $coincidencias)) {
        return null;
    }
    $texto = trim(end($coincidencias[1]));
    return $texto !== '' ? $texto : null;
}

/**
 * Precarga TODOS los colegios (cualquier calendario/zona) normalizados sin stopwords, una sola
 * vez por sincronización. Aunque este reporte es solo de Calendario B, el cruce necesita ver el
 * universo COMPLETO de colegios, no solo los de Calendario B — si se restringe de entrada, el
 * matcher queda ciego a que existe un colegio de Calendario A con nombre más específico/exacto
 * (ej. "Gimnasio Moderno Castilla", Calendario A) y termina absorbiendo el documento por
 * contención dentro de un colegio de Calendario B con nombre más genérico y solo parcialmente
 * parecido ("Gimnasio Moderno") — confirmado en datos reales, ver
 * memory/project_colocacion_modulo.md. `emparejar_colegio()` decide al final si el ganador es
 * de Calendario B (se asigna) o no (queda sin cruzar, fuera de alcance del reporte).
 * Devuelve [id_colegio => ['norm' => texto_normalizado_sin_stopwords, 'calendario' => int]].
 */
function cargar_todos_los_colegios($bdd) {
    $stmt = $bdd->query("SELECT id, colegio, id_calendario FROM colegios");
    $colegios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $norm = quitar_stopwords(normalizar_nombre_colegio($fila['colegio']));
        if ($norm !== '') {
            $colegios[(int)$fila['id']] = ['norm' => $norm, 'calendario' => (int)$fila['id_calendario']];
        }
    }
    return $colegios;
}

/**
 * Precarga el conjunto de nombres de colegio (normalizados sin stopwords) que están DUPLICADOS
 * en TODA la tabla `colegios` (cualquier calendario/zona), no solo dentro de Calendario B — ej.
 * "Colegio San Mateo Apóstol" existe tanto en Calendario B (zona de un asesor) como en Calendario
 * A (zona de un distribuidor, en otra ciudad); "Colegio Andino" existe dos veces solo dentro de
 * Calendario B, en dos zonas distintas. Con un nombre así, el texto del concepto de World Office
 * ("COL SAN MATEO APOSTOL") no alcanza para saber a cuál de los dos colegios reales pertenece
 * el documento — cruzarlo igual con el único candidato de Calendario B es adivinar, y puede
 * atribuirle a un asesor la factura/cliente de un colegio homónimo que en realidad es de otra
 * zona o de otro distribuidor (confirmado en datos reales, ver memory/project_colocacion_modulo.md).
 * Devuelve un arreglo [texto_normalizado => true] para consulta O(1) en emparejar_colegio().
 */
function cargar_nombres_colegio_duplicados($bdd) {
    $stmt = $bdd->query("SELECT colegio FROM colegios");
    $conteo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $norm = quitar_stopwords(normalizar_nombre_colegio($fila['colegio']));
        if ($norm === '') continue;
        $conteo[$norm] = ($conteo[$norm] ?? 0) + 1;
    }
    return array_fill_keys(array_keys(array_filter($conteo, fn($n) => $n > 1)), true);
}

/**
 * Precarga el cliente registrado en `recursos` (id_colegio + id_periodo) para el período que se
 * está sincronizando — segunda validación pedida por el usuario 2026-09-02: no basta con que el
 * texto del concepto matchee el colegio, el tercero externo del documento de World Office también
 * debe coincidir con el cliente que el CRM tiene asignado ahí para ese colegio+período. Si
 * `recursos` no tiene cliente para ese colegio, o el nombre no coincide, el documento se deja sin
 * cruzar en vez de asignarlo solo por colegio — aunque hoy `recursos` esté prácticamente vacío
 * para el período activo y esto deje casi todo sin cruzar hasta que se cargue manualmente (decisión
 * explícita del usuario, confirmada sabiendo ese impacto).
 * Devuelve [id_colegio => nombre_cliente].
 */
function cargar_clientes_recursos($bdd, $idPeriodo) {
    $stmt = $bdd->prepare("SELECT r.id_colegio, cl.cliente FROM recursos r JOIN clientes cl ON cl.id = r.cliente
        WHERE r.id_periodo = ? AND r.cliente != 0");
    $stmt->execute([$idPeriodo]);
    $clientes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $clientes[(int)$fila['id_colegio']] = $fila['cliente'];
    }
    return $clientes;
}

/**
 * Compara el tercero externo de World Office contra el cliente de `recursos` para el colegio ya
 * emparejado por texto — mismo criterio de comparación (trim + minúsculas, sin normalización de
 * acentos) que ya usaba includes/colocacion_datos.php para decidir qué cliente mostrar en pantalla.
 * true solo si `recursos` tiene un cliente para ese colegio Y el nombre coincide con el tercero.
 */
function cliente_coincide_con_recursos($idColegio, $terceroExternoNombre, array $clientesRecursos) {
    if ($idColegio === null || !isset($clientesRecursos[$idColegio])) return false;
    if ($terceroExternoNombre === null || trim((string)$terceroExternoNombre) === '') return false;
    return mb_strtolower(trim($clientesRecursos[$idColegio])) === mb_strtolower(trim($terceroExternoNombre));
}

/**
 * Contención por PALABRAS COMPLETAS: todas las palabras de $corto deben aparecer como palabra
 * completa dentro de $largo (no como fragmento pegado a otra palabra). Evita falsos positivos
 * como "PANAMERICANO" o "ANGLOAMERICANO" matcheando contra "americano" (Colegio Americano) por
 * simple substring de caracteres — confirmado en datos reales que strpos() sin límite de palabra
 * cruzaba documentos de colegios totalmente distintos (Panamericano, Angloamericano, Colombo
 * Americano) hacia "Colegio Americano", contaminando su colocación con clientes/facturas ajenos.
 */
function contiene_palabras_completas($corto, $largo) {
    $tokens = array_filter(explode(' ', $corto), fn($t) => $t !== '');
    foreach ($tokens as $t) {
        if (!preg_match('/(?:^|\s)' . preg_quote($t, '/') . '(?:$|\s)/', $largo)) return false;
    }
    return true;
}

/**
 * Empareja el texto extraído del concepto contra el universo COMPLETO de colegios precargado
 * (ver cargar_todos_los_colegios — cualquier calendario, no solo Calendario B). Primero
 * coincidencia exacta sobre el texto normalizado sin stopwords; si no hay, contención
 * (substring) en cualquier dirección. Al final, solo se devuelve el id si el colegio ganador es
 * de CALENDARIO B (`calendario === 2`) — si el mejor/único match real resulta ser de otro
 * calendario, el documento pertenece a un colegio fuera del alcance de este reporte y se deja
 * sin cruzar, en vez de caer de rebote en un colegio de Calendario B parecido pero distinto (ver
 * memory/project_colocacion_modulo.md, caso real "Gimnasio Moderno Castilla" de Calendario A
 * absorbido por error dentro de "Gimnasio Moderno" de Calendario B).
 *
 * $nombresDuplicados (ver cargar_nombres_colegio_duplicados): si el nombre encontrado está
 * duplicado en cualquier otro colegio de la BD (mismo Calendario B u otro calendario/zona), NO
 * se auto-asigna aunque el match sea exacto y único — el texto del concepto no trae ninguna otra
 * pista (ciudad, distribuidor) para saber cuál de los homónimos es el correcto, así que se deja
 * sin cruzar para revisión manual en vez de adivinar.
 */
function emparejar_colegio($textoExtraido, array $colegiosPrecargados, array $nombresDuplicados = []) {
    if ($textoExtraido === null || $textoExtraido === '') return null;
    $buscado = quitar_stopwords(normalizar_nombre_colegio($textoExtraido));
    if ($buscado === '') return null;

    foreach ($colegiosPrecargados as $idColegio => $info) {
        if ($info['norm'] === $buscado) {
            if (isset($nombresDuplicados[$info['norm']])) return null;
            return $info['calendario'] === 2 ? $idColegio : null;
        }
    }

    // Contención (substring) como respaldo, con salvaguardas confirmadas necesarias contra datos
    // reales (ver memory/project_colocacion_modulo.md):
    // 1) por PALABRAS COMPLETAS, no por fragmento de caracteres (ver contiene_palabras_completas).
    // 2) el nombre real del colegio (normalizado, sin stopwords) debe tener 2+ palabras — un
    //    nombre real de UNA sola palabra (ej. "Bolivar", "Montessori", "Americano", "Colina") es
    //    genérico y aparece dentro de muchos nombres de colegios reales distintos ("Bolivar de
    //    Soacha", "Maria Montessori", "Colombo Americano"...); solo se acepta ahí un match
    //    EXACTO, nunca por contención, o se deja sin cruzar para revisión manual.
    // 3) se busca entre TODOS los calendarios (no solo B) — si el único candidato que contiene
    //    todas las palabras resulta ser de Calendario A, es preferible dejarlo sin cruzar a
    //    adivinar que es un colegio de Calendario B con nombre parecido.
    $candidatos = [];
    foreach ($colegiosPrecargados as $idColegio => $info) {
        $normalizado = $info['norm'];
        $palabrasNormalizado = count(array_filter(explode(' ', $normalizado), fn($t) => $t !== ''));
        if ($palabrasNormalizado < 2) continue;
        if (isset($nombresDuplicados[$normalizado])) continue;
        if (contiene_palabras_completas($normalizado, $buscado) || contiene_palabras_completas($buscado, $normalizado)) {
            $candidatos[] = $idColegio;
        }
    }
    if (count($candidatos) === 1) {
        $idUnico = $candidatos[0];
        return $colegiosPrecargados[$idUnico]['calendario'] === 2 ? $idUnico : null;
    }

    return null;
}
