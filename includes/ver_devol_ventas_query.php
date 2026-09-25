<?php
/**
 * Piezas de SQL compartidas entre ver_devol_ventas.php y
 * ajax/ver_devol_ventas_data.php (tabla devoluciones_v) para que el conteo,
 * el select de estados y los datos paginados usen siempre el mismo alcance
 * de filas (según el tipo de usuario y su zona).
 */

function ver_devol_ventas_query_parts() {
  $from = "FROM devoluciones_v p
           JOIN usuarios u ON u.id=p.id_usuario
           JOIN estados_dev e ON e.id=p.estado
           JOIN clientes c ON c.id=p.cliente
           LEFT JOIN colegios i ON i.id=p.id_colegio
           LEFT JOIN calendarios cal ON i.id_calendario=cal.id";

  $tipo_ses = $_SESSION['tipo'] ?? null;
  $params   = [];
  if ($tipo_ses == 1 || $tipo_ses == 2) {
    $where = "WHERE 1=1";
  } elseif ($tipo_ses == 3) {
    $where = "WHERE p.id_usuario = :id_usuario";
    $params[':id_usuario'] = $_SESSION['id'];
  } else {
    $where = "WHERE (i.cod_zona = :zona OR i.zona_madre = :zona)";
    $params[':zona'] = $_SESSION['zona'];
  }

  return [$from, $where, $params];
}
