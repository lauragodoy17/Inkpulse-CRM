<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$draw = intval($_GET['draw']);

if ($_SESSION["tipo"] != 1) {
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

$start = intval($_GET['start']);
$length = intval($_GET['length']);
$searchValue = $_GET['search']['value'] ?? '';

$columns = ['periodo', 'calendario', 'f_cierre', 't_preescolar', 't_primaria', 't_6_9', 't_10_11', 'acciones'];

$orderSQL = 'ORDER BY p.id DESC';
if (isset($_GET['order'][0]['column'])) {
    $columnIndex = intval($_GET['order'][0]['column']);
    $sortDir = $_GET['order'][0]['dir'] === 'desc' ? 'DESC' : 'ASC';
    $orderMap = ['periodo' => 'p.periodo', 'calendario' => 'c.calendario', 'f_cierre' => 'p.f_cierre'];
    if (isset($columns[$columnIndex]) && isset($orderMap[$columns[$columnIndex]])) {
        $orderSQL = "ORDER BY " . $orderMap[$columns[$columnIndex]] . " $sortDir";
    }
}

$searchSQL = '';
$params = [];
if ($searchValue !== '') {
    $searchSQL = "WHERE (p.periodo LIKE :search OR c.calendario LIKE :search)";
    $params[':search'] = "%" . $searchValue . "%";
}

$baseFrom = "FROM periodos p JOIN calendarios c ON p.id_calendario = c.id $searchSQL";

$stmtTotal = $bdd->prepare("SELECT COUNT(*) FROM periodos");
$stmtTotal->execute();
$recordsTotal = $stmtTotal->fetchColumn();

$stmtFiltered = $bdd->prepare("SELECT COUNT(*) $baseFrom");
$stmtFiltered->execute($params);
$recordsFiltered = $stmtFiltered->fetchColumn();

$dataSQL = "SELECT p.id, p.periodo, p.f_cierre, p.t_preescolar, p.t_primaria, p.t_6_9, p.t_10_11, c.id AS id_calendario, c.calendario
            $baseFrom $orderSQL LIMIT :start, :length";
$stmt = $bdd->prepare($dataSQL);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':length', $length, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $periodo) {
    $badgeClase = $periodo["calendario"] === 'B' ? 'badge-calendario-b' : 'badge-calendario-a';
    $calendarioHtml = '<span class="badge-calendario ' . $badgeClase . '">' . htmlspecialchars($periodo["calendario"]) . '</span>';

    $accionesHtml = '<div class="acciones-periodo">'
        . '<button type="button" class="btn-editar-periodo"'
        . ' data-id="' . $periodo["id"] . '"'
        . ' data-periodo="' . htmlspecialchars($periodo["periodo"], ENT_QUOTES) . '"'
        . ' data-calendario-id="' . $periodo["id_calendario"] . '"'
        . ' data-f-cierre="' . htmlspecialchars($periodo["f_cierre"], ENT_QUOTES) . '"'
        . ' data-t-preescolar="' . htmlspecialchars($periodo["t_preescolar"], ENT_QUOTES) . '"'
        . ' data-t-primaria="' . htmlspecialchars($periodo["t_primaria"], ENT_QUOTES) . '"'
        . ' data-t-6-9="' . htmlspecialchars($periodo["t_6_9"], ENT_QUOTES) . '"'
        . ' data-t-10-11="' . htmlspecialchars($periodo["t_10_11"], ENT_QUOTES) . '"'
        . ' title="Editar período"><i class="bi bi-pencil-square"></i></button>'
        . '<button type="button" class="btn-eliminar-periodo" data-id="' . $periodo["id"] . '" data-periodo="' . htmlspecialchars($periodo["periodo"], ENT_QUOTES) . '" title="Eliminar período"><i class="bi bi-trash3"></i></button>'
        . '</div>';

    $data[] = [
        'periodo'      => htmlspecialchars($periodo["periodo"]),
        'calendario'   => $calendarioHtml,
        'f_cierre'     => htmlspecialchars($periodo["f_cierre"]),
        't_preescolar' => htmlspecialchars($periodo["t_preescolar"]) . ' %',
        't_primaria'   => htmlspecialchars($periodo["t_primaria"]) . ' %',
        't_6_9'        => htmlspecialchars($periodo["t_6_9"]) . ' %',
        't_10_11'      => htmlspecialchars($periodo["t_10_11"]) . ' %',
        'acciones'     => $accionesHtml,
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => intval($recordsTotal),
    "recordsFiltered" => intval($recordsFiltered),
    "data" => $data
]);
