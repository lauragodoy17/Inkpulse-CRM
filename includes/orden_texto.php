<?php
/**
 * Orden alfabético de títulos (2026-11-20, la usuaria): sin distinguir mayúsculas ni tildes, para que "Álgebra" quede
 * con la A y no después de la Z (mb_strtolower solo compara bytes: "á" > "z"). Los números se comparan como números.
 */
if (!function_exists('orden_texto_clave')) {
    /** Texto normalizado para ordenar: minúsculas, sin tildes ni espacios sobrantes. */
    function orden_texto_clave($s) {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        return strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
                          'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
                          'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n~', 'ç' => 'c']);
    }

    /** Compara dos títulos alfabéticamente (para usort): < 0, 0 o > 0. */
    function orden_texto_cmp($a, $b) {
        return strnatcmp(orden_texto_clave($a), orden_texto_clave($b));
    }
}
