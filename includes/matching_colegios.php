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
 * Precarga los colegios de Calendario B normalizados (sin stopwords), una
 * sola vez por sincronización, para no consultar la BD por cada documento.
 * Devuelve un arreglo [id_colegio => texto_normalizado_sin_stopwords].
 */
function cargar_colegios_calendario_b($bdd) {
    $stmt = $bdd->query("SELECT id, colegio FROM colegios WHERE id_calendario = 2");
    $colegios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $norm = quitar_stopwords(normalizar_nombre_colegio($fila['colegio']));
        if ($norm !== '') {
            $colegios[(int)$fila['id']] = $norm;
        }
    }
    return $colegios;
}

/**
 * Empareja el texto extraído del concepto contra los colegios precargados
 * (ver cargar_colegios_calendario_b). Primero coincidencia exacta sobre el
 * texto normalizado sin stopwords; si no hay, contención (substring) en
 * cualquier dirección. Devuelve el id_colegio o null si no hay match.
 */
function emparejar_colegio($textoExtraido, array $colegiosPrecargados) {
    if ($textoExtraido === null || $textoExtraido === '') return null;
    $buscado = quitar_stopwords(normalizar_nombre_colegio($textoExtraido));
    if ($buscado === '') return null;

    foreach ($colegiosPrecargados as $idColegio => $normalizado) {
        if ($normalizado === $buscado) {
            return $idColegio;
        }
    }

    // Contención (substring) como respaldo, pero solo si hay UN único candidato: un texto
    // corto/genérico (ej. "andes") puede ser substring de varios colegios reales a la vez, y
    // adivinar cuál de ellos es no es mejor que dejarlo sin cruzar para revisión manual.
    $candidatos = [];
    foreach ($colegiosPrecargados as $idColegio => $normalizado) {
        if (strpos($normalizado, $buscado) !== false || strpos($buscado, $normalizado) !== false) {
            $candidatos[] = $idColegio;
        }
    }
    if (count($candidatos) === 1) {
        return $candidatos[0];
    }

    return null;
}
