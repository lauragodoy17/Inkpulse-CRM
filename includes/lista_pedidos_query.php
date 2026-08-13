<?php
/**
 * Piezas de SQL compartidas entre lista_pedidos.php y ajax/lista_pedidos_data.php
 * para que el listado (conteo, opciones de filtro y datos paginados) use
 * siempre exactamente el mismo alcance de filas (estado + restricción de zona).
 */

function lista_pedidos_estado_val($tp) {
  $map = [2 => '1', 3 => '2', 4 => '4', 5 => '3'];
  return $map[$tp] ?? '1';
}

function lista_pedidos_query_parts($tp) {
  $estado_val = lista_pedidos_estado_val($tp);

  $from = "FROM pedidos p
           JOIN colegios c ON p.id_colegio=c.id
           JOIN zonas z ON z.codigo=c.cod_zona
           JOIN usuarios u ON u.cod_zona=z.codigo
           LEFT JOIN calendarios cal ON c.id_calendario=cal.id";

  $where  = "WHERE p.estado = :estado";
  $params = [':estado' => $estado_val];
  if (($_SESSION['tipo'] ?? null) != 10) {
    $where .= " AND (c.cod_zona = :zona OR c.zona_madre = :zona)";
    $params[':zona'] = $_SESSION['zona'];
  }

  $select_calc = "CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS resp_calc,
                  CASE WHEN u.tipo=3 THEN TRIM(SUBSTRING_INDEX(z.zona,'/',1)) ELSE TRIM(z.zona) END AS empresa_calc";

  return [$from, $where, $params, $select_calc];
}
