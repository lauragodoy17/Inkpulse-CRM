<?php
/**
 * Fuente de datos server-side (DataTables) para lista_pedidos.php.
 * Recibe draw/start/length/search/order (protocolo estándar de DataTables)
 * más los filtros propios de la pantalla (responsable, empresa, fecha) y
 * resuelve todo en SQL para no traer más filas de las que se van a pintar.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/lista_pedidos_query.php");

header('Content-Type: application/json; charset=utf-8');

$tp = intval($_POST['tp'] ?? 2);

$draw   = intval($_POST['draw'] ?? 1);
$start  = max(0, intval($_POST['start'] ?? 0));
$length = intval($_POST['length'] ?? 25);
if ($length <= 0) $length = 25;

$search_val  = trim($_POST['search']['value'] ?? '');
$responsable = trim($_POST['responsable'] ?? '');
$empresa     = trim($_POST['empresa'] ?? '');
$fecha_desde = trim($_POST['fecha_desde'] ?? '');
$fecha_hasta = trim($_POST['fecha_hasta'] ?? '');

list($from, $where, $params, $select_calc) = lista_pedidos_query_parts($tp);

// recordsTotal: alcance de la pestaña (estado + zona), sin buscador ni filtros
$req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id $from $where GROUP BY p.id) t");
$req->execute($params);
$records_total = intval($req->fetchColumn());

$having = [];
$having_params = [];
if ($search_val !== '') {
  $having[] = "(p.id LIKE :s OR c.colegio LIKE :s OR resp_calc LIKE :s OR empresa_calc LIKE :s OR cal.calendario LIKE :s)";
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

// c.colegio y cal.calendario tienen que estar en el SELECT de esta subconsulta
// porque el HAVING los referencia (MySQL exige que HAVING solo vea columnas
// del GROUP BY, agregadas, o presentes en el SELECT — nunca columnas "sueltas").
$req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id, c.colegio, cal.calendario, $select_calc $from $where GROUP BY p.id $having_sql) t");
$req->execute($all_params);
$records_filtered = intval($req->fetchColumn());

$order_map      = ['id' => 'p.id', 'fecha_d' => 'p.fecha', 'colegio' => 'c.colegio',
                    'calendario' => 'cal.calendario', 'empresa' => 'empresa_calc', 'responsable' => 'resp_calc'];
$order_col_idx  = intval($_POST['order'][0]['column'] ?? 0);
$order_dir      = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_field    = $_POST['columns'][$order_col_idx]['data'] ?? 'id';
$order_sql      = $order_map[$order_field] ?? 'p.id';

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

$data = [];
foreach ($rows as $p) {
  $tipo_p = intval($p['tipo'] ?? 0);
  if ($tipo_p == 3) {
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
  ];
}

echo json_encode([
  'draw'            => $draw,
  'recordsTotal'    => $records_total,
  'recordsFiltered' => $records_filtered,
  'data'            => $data,
]);
