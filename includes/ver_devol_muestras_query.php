<?php
/**
 * Piezas de SQL compartidas entre ver_devol_muestras.php y
 * ajax/ver_devol_muestras_data.php (tabla devoluciones, tipo=1) para que el
 * conteo, el select de estados y los datos paginados usen siempre el mismo
 * alcance de filas (según el tipo de usuario).
 */

function ver_devol_muestras_query_parts() {
  $from = "FROM devoluciones p
           JOIN usuarios u ON u.id=p.id_usuario
           JOIN estados_pedidos e ON e.id=p.estado
           LEFT JOIN colegios c ON c.id=p.id_colegio";

  $where  = "WHERE p.tipo = '1'";
  $params = [];
  if (($_SESSION['tipo'] ?? null) != 1 && ($_SESSION['tipo'] ?? null) != 2) {
    $where .= " AND p.id_usuario = :id_usuario";
    $params[':id_usuario'] = $_SESSION['id'];
  }

  return [$from, $where, $params];
}
