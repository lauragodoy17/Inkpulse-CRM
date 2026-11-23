<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once("../php/aut.php");
require_once('../conexion/bdd.php');
require_once('../includes/venta_directa_datos.php');
vd_asegurar_tablas($bdd);

$draw        = $_GET['draw'];
$start       = intval($_GET['start']);
$length      = intval($_GET['length']);
$searchValue = trim($_GET['search']['value'] ?? '');
$tp          = intval($_GET['tp'] ?? 1);
// vd=1: OP de venta directa (numeración propia OPVD-n, lista aparte); sin vd: las OP de siempre, sin las de venta directa.
$vd          = !empty($_GET['vd']);

$filterEstado  = isset($_GET['filter_estado'])  ? intval($_GET['filter_estado'])  : 0;
$filterCliente = trim($_GET['filter_cliente'] ?? '');
$filterDesde   = $_GET['filter_desde'] ?? '';
$filterHasta   = $_GET['filter_hasta'] ?? '';

$orderCols = ['o.id', 'usuario', 'fecha', 'n_doc', 'valor', 'guia', 'cliente', 'estado'];
$orderSQL  = '';
if (isset($_GET['order'][0]['column'])) {
    $idx     = intval($_GET['order'][0]['column']);
    $sortDir = $_GET['order'][0]['dir'] === 'desc' ? 'DESC' : 'ASC';
    if (isset($orderCols[$idx])) {
        $orderSQL = " ORDER BY " . $orderCols[$idx] . " $sortDir";
    }
}

$baseJoin = "FROM ordenes_pedidos o
             JOIN tipo_doc t   ON o.tipo_doc = t.id
             JOIN clientes c   ON c.id = o.cliente
             JOIN usuarios u   ON u.id = o.usuario
             JOIN estados_op e ON e.id = o.estado";

// --- Condiciones base por tp (para recordsTotal sin filtros extra) ---
$tpCond = [$vd ? "o.id_pedido_vd > 0" : "o.id_pedido_vd = 0"];
if ($tp == 2) $tpCond[] = "o.estado = 1";
if ($tp == 3) $tpCond[] = "o.estado = 2";
if ($tp == 4) $tpCond[] = "o.estado = 4";
$totalSQL = empty($tpCond) ? "" : " WHERE " . implode(" AND ", $tpCond);
$stmtTotal = $bdd->query("SELECT COUNT(*) $baseJoin $totalSQL");
$total = $stmtTotal->fetchColumn();

// --- Condiciones con filtros ---
$conditions = $tpCond;
$params     = [];

if (!empty($searchValue)) {
    $conditions[]    = $vd ? "(c.cliente LIKE :search OR CONCAT('OPVD-', o.consecutivo_vd) LIKE :search OR CAST(o.consecutivo_vd AS CHAR) LIKE :search OR CONCAT('VD-', o.id_pedido_vd) LIKE :search)"
                            : "(c.cliente LIKE :search OR CAST(o.id AS CHAR) LIKE :search)";
    $params[':search'] = "%" . $searchValue . "%";
}
if ($filterEstado > 0) {
    $conditions[]        = "o.estado = :f_estado";
    $params[':f_estado'] = $filterEstado;
}
if (!empty($filterCliente)) {
    $conditions[]          = "c.cliente LIKE :f_cliente";
    $params[':f_cliente']  = "%" . $filterCliente . "%";
}
if (!empty($filterDesde)) {
    $conditions[]         = "DATE(o.fecha) >= :f_desde";
    $params[':f_desde']   = $filterDesde;
}
if (!empty($filterHasta)) {
    $conditions[]         = "DATE(o.fecha) <= :f_hasta";
    $params[':f_hasta']   = $filterHasta;
}

$whereSQL = empty($conditions) ? "" : " WHERE " . implode(" AND ", $conditions);

// Total filtrado
$stmtFiltered = $bdd->prepare("SELECT COUNT(*) $baseJoin $whereSQL");
$stmtFiltered->execute($params);
$filtered = $stmtFiltered->fetchColumn();

// Datos paginados
$dataSQL = "SELECT o.id as opid, o.usuario, o.fecha, o.n_doc, o.solicitante, o.valor, o.guia,
            o.fecha_entrega, o.archivo, o.fecha_at, o.usuario_at, o.usuario_anu, o.fecha_anu,
            o.año, o.id_pedido_vd, o.consecutivo_vd, t.tipo, c.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario, e.estado
            $baseJoin $whereSQL $orderSQL LIMIT :start, :length";

$stmt = $bdd->prepare($dataSQL);
$stmt->bindValue(':start',  $start,  PDO::PARAM_INT);
$stmt->bindValue(':length', $length, PDO::PARAM_INT);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->execute();

$data = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $op) {
    if ($tp == 3) {
        $op["id"] = "<a href='op_pendiente.php?op=".$op["opid"]."'>".$op["opid"]."</a>";
    } elseif ($tp == 4) {
        $req = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) AS usr_anu FROM usuarios WHERE id=?");
        $req->execute([$op["usuario_anu"]]);
        $anu = $req->fetch();
        $op["usuario_anu"] = $anu["usr_anu"] ?? '';
        $op["id"] = $op["opid"];
    } else {
        $op["id"] = "<a href='op_pendiente.php?op=".$op["opid"]."'>".$op["año"]." - ".$op["opid"]."</a>";
    }

    if ((int)$op['id_pedido_vd'] > 0) {
        // OP de venta directa: se muestra con su numeración propia.
        $etq = vd_etiqueta_op($op['consecutivo_vd']) . " <small style='color:#64748b'>(" . vd_numero($op['id_pedido_vd']) . ")</small>";
        $op["id"] = $tp == 4 ? $etq : "<a href='op_pendiente.php?op=".$op["opid"]."'>" . $etq . "</a>";
    }

    $op["acciones"] = "<a href='formato_op.php?op=".$op["opid"]."' target='_blank' class='btn-ver-detalle' title='Imprimir OP'><i class='bi bi-printer'></i></a>";

    $estadoText = $op['estado'];
    $badgeClass = 'op-badge-gray';
    if (stripos($estadoText, 'pendiente') !== false) $badgeClass = 'op-badge-yellow';
    elseif (stripos($estadoText, 'atendi')  !== false) $badgeClass = 'op-badge-green';
    elseif (stripos($estadoText, 'anula')   !== false) $badgeClass = 'op-badge-red';
    $op['estado'] = '<span class="op-badge '.$badgeClass.'">'.$estadoText.'</span>';

    $data[] = $op;
}

echo json_encode([
    "draw"            => intval($draw),
    "recordsTotal"    => $total,
    "recordsFiltered" => $filtered,
    "data"            => $data,
]);
?>
