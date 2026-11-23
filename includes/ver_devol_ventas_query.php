<?php
/**
 * Piezas de SQL compartidas entre ver_devol_ventas.php y
 * ajax/ver_devol_ventas_data.php (tabla devoluciones_v) para que el conteo,
 * el select de estados y los datos paginados usen siempre el mismo alcance
 * de filas (según el tipo de usuario y su zona).
 */

require_once __DIR__ . '/formatos_vd_datos.php';
require_once __DIR__ . '/devoluciones_ventas_datos.php'; // tipo de devolución (normal / de corte)

function ver_devol_ventas_query_parts() {
  // Desde 2026-10-22 (pedido de la usuaria) aquí salen TODAS las devoluciones de ventas, también las de venta
  // directa (devoluciones_v.ids_pedidos / id_pedido_vd), marcadas con su formato (alias f). Se aseguran las
  // columnas antes de usarlas. (La lista propia ver_devol_venta_directa.php se borró el 2026-11-13.)
  global $bdd;
  fvd_asegurar_tablas($bdd);
  dvv_asegurar_columnas($bdd); // devoluciones_v.tipo_devolucion

  $from = "FROM devoluciones_v p
           JOIN usuarios u ON u.id=p.id_usuario
           JOIN estados_dev e ON e.id=p.estado
           JOIN clientes c ON c.id=p.cliente
           LEFT JOIN colegios i ON i.id=p.id_colegio
           LEFT JOIN calendarios cal ON i.id_calendario=cal.id
           LEFT JOIN formatos_vd f ON f.id=p.id_formato_vd";

  $tipo_ses = $_SESSION['tipo'] ?? null;
  $params   = [];
  if ($tipo_ses == 1 || $tipo_ses == 2) {
    $where = "WHERE 1";
  } elseif ($tipo_ses == 3) {
    $where = "WHERE p.id_usuario = :id_usuario";
    $params[':id_usuario'] = $_SESSION['id'];
  } else {
    $where = "WHERE (i.cod_zona = :zona OR i.zona_madre = :zona)";
    $params[':zona'] = $_SESSION['zona'];
  }

  return [$from, $where, $params];
}
