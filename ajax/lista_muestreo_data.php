<?php
/**
 * Fuente de datos server-side (DataTables) para lista_muestreo.php.
 * tp=1 (solicitudes) usa una tabla y columnas totalmente distintas de
 * tp=2..5 (muestreos), así que se resuelven por separado.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/lista_muestreo_query.php");

header('Content-Type: application/json; charset=utf-8');

$tp = intval($_POST['tp'] ?? 2);

$draw   = intval($_POST['draw'] ?? 1);
$start  = max(0, intval($_POST['start'] ?? 0));
$length = intval($_POST['length'] ?? 25);
if ($length <= 0) $length = 25;

$search_val  = trim($_POST['search']['value'] ?? '');
$fecha_desde = trim($_POST['fecha_desde'] ?? '');
$fecha_hasta = trim($_POST['fecha_hasta'] ?? '');

if ($tp == 1) {

  $responsable = ''; // tp=1 no tiene estos selects
  $empresa     = '';

  $gp_periodo = $bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetch();

  $from = "FROM solicitudes_recursos s
           JOIN estados_pedidos e ON e.id=s.estado
           LEFT JOIN trabajadores_colegios t ON s.solicitante=t.id
           LEFT JOIN cargos ca ON ca.id=t.cargo
           JOIN colegios c ON c.id=s.id_colegio
           JOIN usuarios u ON u.id=s.usuario";
  $where  = "WHERE s.id_periodo = :periodo";
  $params = [':periodo' => $gp_periodo['id']];

  $req = $bdd->prepare("SELECT COUNT(*) $from $where");
  $req->execute($params);
  $records_total = intval($req->fetchColumn());

  $extra = [];
  $extra_params = [];
  if ($search_val !== '') {
    $extra[] = "(c.colegio LIKE :s OR s.conse LIKE :s OR ca.cargo LIKE :s OR e.estado LIKE :s
                 OR CONCAT(t.nombre,' ',t.apellido) LIKE :s OR CONCAT(u.nombres,' ',u.apellidos) LIKE :s)";
    $extra_params[':s'] = '%' . $search_val . '%';
  }
  if ($fecha_desde !== '') {
    $extra[] = "DATE(s.fecha) >= :fdesde";
    $extra_params[':fdesde'] = $fecha_desde;
  }
  if ($fecha_hasta !== '') {
    $extra[] = "DATE(s.fecha) <= :fhasta";
    $extra_params[':fhasta'] = $fecha_hasta;
  }
  $where_filtered = $where . ($extra ? (' AND ' . implode(' AND ', $extra)) : '');
  $all_params = array_merge($params, $extra_params);

  $req = $bdd->prepare("SELECT COUNT(*) $from $where_filtered");
  $req->execute($all_params);
  $records_filtered = intval($req->fetchColumn());

  $order_map     = ['id' => 's.id', 'fecha_d' => 's.fecha', 'colegio' => 'c.colegio'];
  $order_col_idx = intval($_POST['order'][0]['column'] ?? 0);
  $order_dir     = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
  $order_field   = $_POST['columns'][$order_col_idx]['data'] ?? 'id';
  $order_sql     = $order_map[$order_field] ?? 's.id';

  $sql_data = "SELECT e.estado AS estado_nombre, s.id, s.fecha, s.fecha_entrega, s.conse,
                      CONCAT(t.nombre,' ',t.apellido) AS solicitante, ca.cargo, c.colegio,
                      CONCAT(u.nombres,' ',u.apellidos) AS promotor
               $from $where_filtered
               ORDER BY $order_sql $order_dir
               LIMIT :start, :length";
  $req = $bdd->prepare($sql_data);
  foreach ($all_params as $k => $v) $req->bindValue($k, $v);
  $req->bindValue(':start', $start, PDO::PARAM_INT);
  $req->bindValue(':length', $length, PDO::PARAM_INT);
  $req->execute();
  $rows = $req->fetchAll();

  $data = [];
  foreach ($rows as $p) {
    $data[] = [
      'id'            => $p['id'],
      'conse'         => htmlspecialchars($p['conse']),
      'fecha_d'       => date('d/m/Y', strtotime($p['fecha'])),
      'fecha_entrega' => $p['fecha_entrega'] ? date('d/m/Y', strtotime($p['fecha_entrega'])) : '—',
      'solicitante'   => htmlspecialchars($p['solicitante'] ?? '—'),
      'cargo'         => htmlspecialchars($p['cargo'] ?? '—'),
      'colegio'       => htmlspecialchars($p['colegio']),
      'promotor'      => htmlspecialchars($p['promotor']),
      'estado_nombre' => htmlspecialchars($p['estado_nombre']),
    ];
  }

} else {

  $responsable = trim($_POST['responsable'] ?? '');
  $empresa     = trim($_POST['empresa'] ?? '');

  list($from, $where, $params, $select_calc) = lista_muestreo_query_parts($tp);

  $req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id $from $where GROUP BY p.id) t");
  $req->execute($params);
  $records_total = intval($req->fetchColumn());

  $having = [];
  $having_params = [];
  if ($search_val !== '') {
    $having[] = "(c.colegio LIKE :s OR resp_calc LIKE :s OR empresa_calc LIKE :s OR cal.calendario LIKE :s)";
    $having_params[':s'] = '%' . $search_val . '%';
  }
  if ($responsable !== '') {
    $having[] = "resp_calc = :responsable";
    $having_params[':responsable'] = $responsable;
  }
  if ($empresa !== '') {
    $having[] = "empresa_calc = :empresa";
    $having_params[':empresa'] = $empresa;
  }
  if ($fecha_desde !== '') {
    $having[] = "DATE(p.fecha) >= :fdesde";
    $having_params[':fdesde'] = $fecha_desde;
  }
  if ($fecha_hasta !== '') {
    $having[] = "DATE(p.fecha) <= :fhasta";
    $having_params[':fhasta'] = $fecha_hasta;
  }
  $having_sql = $having ? ('HAVING ' . implode(' AND ', $having)) : '';
  $all_params = array_merge($params, $having_params);

  // c.colegio y cal.calendario deben estar en el SELECT de esta subconsulta:
  // MySQL exige que HAVING solo vea columnas del GROUP BY, agregadas, o
  // presentes en el SELECT — nunca columnas "sueltas" de las tablas unidas.
  $req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id, c.colegio, cal.calendario, $select_calc $from $where GROUP BY p.id $having_sql) t");
  $req->execute($all_params);
  $records_filtered = intval($req->fetchColumn());

  $order_map     = ['id' => 'p.id', 'fecha_d' => 'p.fecha', 'colegio' => 'c.colegio',
                     'calendario' => 'cal.calendario', 'empresa' => 'empresa_calc', 'responsable' => 'resp_calc'];
  $order_col_idx = intval($_POST['order'][0]['column'] ?? 0);
  $order_dir     = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
  $order_field   = $_POST['columns'][$order_col_idx]['data'] ?? 'id';
  $order_sql     = $order_map[$order_field] ?? 'p.id';

  $sql_data = "SELECT p.id, z.zona, u.nombres, u.apellidos, u.tipo, p.fecha, c.colegio, c.sub_zona, c.responsable, cal.calendario, $select_calc
               $from
               $where
               GROUP BY p.id
               $having_sql
               ORDER BY $order_sql $order_dir
               LIMIT :start, :length";
  $req = $bdd->prepare($sql_data);
  foreach ($all_params as $k => $v) $req->bindValue($k, $v);
  $req->bindValue(':start', $start, PDO::PARAM_INT);
  $req->bindValue(':length', $length, PDO::PARAM_INT);
  $req->execute();
  $rows = $req->fetchAll();

  $sub_zonas_map = [];
  foreach ($bdd->query("SELECT id, sub_zona FROM sub_zonas")->fetchAll() as $sz)
    $sub_zonas_map[$sz['id']] = $sz['sub_zona'];

  $url_base = ($tp == 2) ? 'muestreo_colegio.php?id_pedido=' : ("muestreo_colegio_resto.php?tp={$tp}&id_pedido=");

  $data = [];
  foreach ($rows as $p) {
    $tipo_p = intval($p['tipo'] ?? 0);
    if ($tipo_p == 3 || $tipo_p == 1) {
      $parts     = explode('/', $p['zona'] ?? '');
      $empresa_v = trim($parts[0] ?? '');
      $n_zona    = trim($parts[1] ?? '');
      $resp_v    = trim(($p['nombres'] ?? '') . ' ' . ($p['apellidos'] ?? ''));
    } else {
      $empresa_v = $p['zona'] ?? '';
      $n_zona    = $sub_zonas_map[$p['sub_zona']] ?? '—';
      $resp_v    = $p['responsable'] ?? '—';
    }
    $data[] = [
      'id'          => $p['id'],
      'fecha_d'     => date('d/m/Y', strtotime($p['fecha'])),
      'empresa'     => htmlspecialchars($empresa_v),
      'zona'        => htmlspecialchars($n_zona),
      'responsable' => htmlspecialchars($resp_v),
      'colegio'     => htmlspecialchars($p['colegio']),
      'calendario'  => htmlspecialchars($p['calendario'] ?? '—'),
      'url_detalle' => $url_base . $p['id'],
    ];
  }
}

echo json_encode([
  'draw'            => $draw,
  'recordsTotal'    => $records_total,
  'recordsFiltered' => $records_filtered,
  'data'            => $data,
]);
