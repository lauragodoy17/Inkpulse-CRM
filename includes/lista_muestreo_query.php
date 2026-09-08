<?php
/**
 * Piezas de SQL compartidas entre lista_muestreo.php y ajax/lista_muestreo_data.php
 * para el alcance tp=2..5 (tabla muestreos). El caso tp=1 (solicitudes_recursos)
 * no necesita esto: es una tabla distinta, sin GROUP BY ni columnas calculadas.
 */

function lista_muestreo_estado_val($tp) {
  $map = [2 => '1', 3 => '2', 4 => '4', 5 => '3'];
  return $map[$tp] ?? '1';
}

function lista_muestreo_query_parts($tp) {
  $estado_val = lista_muestreo_estado_val($tp);

  $from = "FROM muestreos p
           JOIN colegios c ON p.id_colegio=c.id
           JOIN zonas z ON z.codigo=c.cod_zona
           JOIN usuarios u ON u.id=p.id_usuario
           LEFT JOIN calendarios cal ON c.id_calendario=cal.id";

  $where  = "WHERE p.estado = :estado";
  $params = [':estado' => $estado_val];
  if (($_SESSION['tipo'] ?? null) != 10) {
    $where .= " AND (c.cod_zona = :zona OR c.zona_madre = :zona)";
    $params[':zona'] = $_SESSION['zona'];
  }

  // OJO: a diferencia de lista_pedidos (solo tipo=3), aquí tipo=1 también usa
  // el formato "empresa/zona" — así viene el listado original de muestreo.
  $select_calc = "CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS resp_calc,
                  CASE WHEN u.tipo IN (1,3) THEN TRIM(SUBSTRING_INDEX(z.zona,'/',1)) ELSE TRIM(z.zona) END AS empresa_calc";

  return [$from, $where, $params, $select_calc];
}
