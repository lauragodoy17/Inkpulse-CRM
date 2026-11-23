<?php
/**
 * /includes/plan_lector.php
 * Orden de los títulos (2026-11-23, la usuaria) en adopción, pedidos, devoluciones y formato de venta directa: por CURSO y,
 * dentro de cada curso, primero los libros particulares (matemáticas, lenguaje...) en orden alfabético y después los de
 * PLAN LECTOR en orden alfabético (sin encabezados de sección, salvo en el formato de venta directa).
 * Plan lector = materia "Plan lector" (materias.id 9) del libro o del área objetiva con que se adoptó. El curso es el de la
 * adopción: el del área objetiva (areas_objetivas.id_grado_otro) si la línea tiene cod_area; si no, el del libro.
 */
require_once __DIR__ . '/orden_texto.php'; // orden_texto_cmp: alfabético sin distinguir tildes

const MATERIA_PLAN_LECTOR = 9;

/** Clave de orden "04-0" (curso 4, libro particular) / "04-1" (curso 4, plan lector); sin curso = 99 (al final). */
function plan_lector_clave($esPlan, $idGrado) {
    $g = (int)$idGrado;
    return sprintf('%02d-%d', $g > 0 && $g < 99 ? $g : 99, $esPlan ? 1 : 0);
}

/** Compara dos filas por [curso, plan lector, título]. $a/$b = ['plan' => bool, 'grado' => int, 'titulo' => string]. */
function plan_lector_comparar(array $a, array $b) {
    return strcmp(plan_lector_clave($a['plan'], $a['grado']), plan_lector_clave($b['plan'], $b['grado']))
        ?: orden_texto_cmp($a['titulo'], $b['titulo']);
}
