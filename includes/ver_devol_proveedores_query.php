<?php
/**
 * Piezas de SQL compartidas entre ver_devol_proveedores.php y
 * ajax/ver_devol_proveedores_data.php (tabla devoluciones_prov, tipo=2).
 */

function ver_devol_proveedores_query_parts() {
  $from = "FROM devoluciones_prov p
           JOIN usuarios u ON u.id=p.id_usuario
           JOIN estados_pedidos e ON e.id=p.estado
           JOIN proveedores c ON c.id=p.persona";

  $where  = "WHERE p.tipo = '2'";
  $params = [];

  return [$from, $where, $params];
}
