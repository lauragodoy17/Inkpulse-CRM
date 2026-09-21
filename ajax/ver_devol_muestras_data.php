<?php
/**
 * Fuente de datos server-side (DataTables) para ver_devol_muestras.php.
 * Recibe draw/start/length/search/order (protocolo estándar de DataTables)
 * más los filtros propios de la pantalla (estado, fecha) y resuelve todo en
 * SQL para no traer más filas de las que se van a pintar.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/ver_devol_muestras_query.php");

header('Content-Type: application/json; charset=utf-8');

$draw   = intval($_POST['draw'] ?? 1);
$start  = max(0, intval($_POST['start'] ?? 0));
$length = intval($_POST['length'] ?? 25);
if ($length <= 0) $length = 25;

$search_val  = trim($_POST['search']['value'] ?? '');
$estado      = trim($_POST['estado'] ?? '');
$fecha_desde = trim($_POST['fecha_desde'] ?? '');
$fecha_hasta = trim($_POST['fecha_hasta'] ?? '');

list($from, $where, $params) = ver_devol_muestras_query_parts();

$req = $bdd->prepare("SELECT COUNT(*) $from $where");
$req->execute($params);
$records_total = intval($req->fetchColumn());

$extra = [];
if ($search_val !== '') {
  $extra[] = "(p.id LIKE :s OR CONCAT(u.nombres,' ',u.apellidos) LIKE :s OR c.colegio LIKE :s OR e.estado LIKE :s)";
  $params[':s'] = '%' . $search_val . '%';
}
if ($estado !== '') {
  $extra[] = "e.estado = :estado_f";
  $params[':estado_f'] = $estado;
}
if ($fecha_desde !== '') {
  $extra[] = "DATE(p.fecha) >= :fdesde";
  $params[':fdesde'] = $fecha_desde;
}
if ($fecha_hasta !== '') {
  $extra[] = "DATE(p.fecha) <= :fhasta";
  $params[':fhasta'] = $fecha_hasta;
}
$where_f = $where . ($extra ? (' AND ' . implode(' AND ', $extra)) : '');

$req = $bdd->prepare("SELECT COUNT(*) $from $where_f");
$req->execute($params);
$records_filtered = intval($req->fetchColumn());

$order_map     = ['id' => 'p.id', 'fecha_d' => 'p.fecha', 'colegio' => 'c.colegio', 'estado' => 'e.estado'];
$order_col_idx = intval($_POST['order'][0]['column'] ?? 0);
$order_dir     = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_field   = $_POST['columns'][$order_col_idx]['data'] ?? 'id';
$order_sql     = $order_map[$order_field] ?? 'p.id';

$sql = "SELECT p.id, p.tipo, p.fecha, u.nombres, u.apellidos, e.estado, c.colegio
        $from $where_f
        ORDER BY $order_sql $order_dir
        LIMIT :start, :length";
$req = $bdd->prepare($sql);
foreach ($params as $k => $v) $req->bindValue($k, $v);
$req->bindValue(':start', $start, PDO::PARAM_INT);
$req->bindValue(':length', $length, PDO::PARAM_INT);
$req->execute();
$rows = $req->fetchAll();

$data = [];
foreach ($rows as $p) {
  $data[] = [
    'id'      => $p['id'],
    'tipo'    => $p['tipo'],
    'fecha_d' => date('d/m/Y', strtotime($p['fecha'])),
    'usuario' => htmlspecialchars(trim(($p['nombres'] ?? '') . ' ' . ($p['apellidos'] ?? ''))),
    'colegio' => htmlspecialchars($p['colegio'] ?? '—'),
    'estado'  => htmlspecialchars($p['estado'] ?? ''),
  ];
}

echo json_encode([
  'draw'            => $draw,
  'recordsTotal'    => $records_total,
  'recordsFiltered' => $records_filtered,
  'data'            => $data,
]);
