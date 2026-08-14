<?php
/**
 * Fuente de datos server-side (DataTables) para lista_pedidos_sa.php (tabla pedidos2).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

header('Content-Type: application/json; charset=utf-8');

$tp = intval($_POST['tp'] ?? 2);

$draw   = intval($_POST['draw'] ?? 1);
$start  = max(0, intval($_POST['start'] ?? 0));
$length = intval($_POST['length'] ?? 25);
if ($length <= 0) $length = 25;

$search_val  = trim($_POST['search']['value'] ?? '');
$fecha_desde = trim($_POST['fecha_desde'] ?? '');
$fecha_hasta = trim($_POST['fecha_hasta'] ?? '');

if ($tp == 2) {
  $where_estado = "p.estado='1' AND p.verify='1'";
} elseif ($tp == 3) {
  $where_estado = "p.estado='2'";
} elseif ($tp == 4) {
  $where_estado = "p.estado='4'";
} else {
  $where_estado = "p.estado='3'";
}

$from  = "FROM pedidos2 p JOIN usuarios u ON u.id=p.id_usuario";
$where = "WHERE $where_estado";

$req = $bdd->query("SELECT COUNT(*) FROM (SELECT p.id $from $where GROUP BY p.id) t");
$records_total = intval($req->fetchColumn());

$having = [];
$having_params = [];
if ($search_val !== '') {
  $having[] = "(p.id LIKE :s OR p.colegio LIKE :s OR CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) LIKE :s)";
  $having_params[':s'] = '%' . $search_val . '%';
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

if ($having_params) {
  // p.colegio, u.nombres y u.apellidos deben estar en el SELECT de esta
  // subconsulta porque el HAVING los referencia (MySQL exige que HAVING solo
  // vea columnas del GROUP BY, agregadas, o presentes en el SELECT).
  $req = $bdd->prepare("SELECT COUNT(*) FROM (SELECT p.id, p.colegio, u.nombres, u.apellidos $from $where GROUP BY p.id $having_sql) t");
  $req->execute($having_params);
  $records_filtered = intval($req->fetchColumn());
} else {
  $records_filtered = $records_total;
}

$order_map     = ['id' => 'p.id', 'fecha_d' => 'p.fecha', 'colegio' => 'p.colegio'];
$order_col_idx = intval($_POST['order'][0]['column'] ?? 0);
$order_dir     = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_field   = $_POST['columns'][$order_col_idx]['data'] ?? 'id';
$order_sql     = $order_map[$order_field] ?? 'p.id';

$sql_data = "SELECT p.id, u.nombres, u.apellidos, p.fecha, p.colegio
             $from
             $where
             GROUP BY p.id
             $having_sql
             ORDER BY $order_sql $order_dir
             LIMIT :start, :length";
$req = $bdd->prepare($sql_data);
foreach ($having_params as $k => $v) $req->bindValue($k, $v);
$req->bindValue(':start', $start, PDO::PARAM_INT);
$req->bindValue(':length', $length, PDO::PARAM_INT);
$req->execute();
$rows = $req->fetchAll();

$data = [];
foreach ($rows as $p) {
  $data[] = [
    'id'        => $p['id'],
    'fecha_d'   => date('d/m/Y', strtotime($p['fecha'])),
    'promotor'  => htmlspecialchars(trim(($p['nombres'] ?? '') . ' ' . ($p['apellidos'] ?? ''))),
    'colegio'   => htmlspecialchars($p['colegio']),
  ];
}

echo json_encode([
  'draw'            => $draw,
  'recordsTotal'    => $records_total,
  'recordsFiltered' => $records_filtered,
  'data'            => $data,
]);
