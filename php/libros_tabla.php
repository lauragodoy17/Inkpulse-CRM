<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

$draw = intval($_GET['draw']);

if ($_SESSION["tipo"] != 1) {
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

$puede_gestionar = true;

$start = intval($_GET['start']);
$length = intval($_GET['length']);
$searchValue = $_GET['search']['value'] ?? '';

$columns = ['isbn', 'libro', 'materia', 'grado', 'precio', 'presupuesto', 'asociacion', 'acciones'];

$orderSQL = 'ORDER BY g.id, l.libro';
if (isset($_GET['order'][0]['column'])) {
    $columnIndex = intval($_GET['order'][0]['column']);
    $sortDir = $_GET['order'][0]['dir'] === 'desc' ? 'DESC' : 'ASC';
    $orderMap = ['isbn' => 'l.isbn', 'libro' => 'l.libro', 'materia' => 'm.materia', 'grado' => 'g.id', 'precio' => 'l.precio', 'presupuesto' => 'l.presupuesto'];
    if (isset($columns[$columnIndex]) && isset($orderMap[$columns[$columnIndex]])) {
        $orderSQL = "ORDER BY " . $orderMap[$columns[$columnIndex]] . " $sortDir";
    }
}

$searchSQL = '';
$params = [];
if ($searchValue !== '') {
    $searchSQL = "WHERE (l.isbn LIKE :search OR l.libro LIKE :search OR m.materia LIKE :search OR g.grado LIKE :search)";
    $params[':search'] = "%" . $searchValue . "%";
}

$baseFrom = "FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id $searchSQL";

$stmtTotal = $bdd->prepare("SELECT COUNT(*) FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id");
$stmtTotal->execute();
$recordsTotal = $stmtTotal->fetchColumn();

$stmtFiltered = $bdd->prepare("SELECT COUNT(*) $baseFrom");
$stmtFiltered->execute($params);
$recordsFiltered = $stmtFiltered->fetchColumn();

$dataFrom = "FROM libros l JOIN materias m ON l.id_materia = m.id JOIN grados g ON l.id_grado = g.id LEFT JOIN libros p ON l.pri_sec = p.id $searchSQL";
$dataSQL = "SELECT l.id, l.isbn, l.libro, l.id_materia, l.id_grado, l.precio, l.presupuesto, l.pri_sec, p.libro as nombre_padre,
            (SELECT COUNT(*) FROM libros c WHERE c.pri_sec = l.id) as num_hijos
            $dataFrom $orderSQL LIMIT :start, :length";
$stmt = $bdd->prepare($dataSQL);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':length', $length, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$materias = $bdd->query("SELECT id, materia FROM materias ORDER BY materia")->fetchAll(PDO::FETCH_ASSOC);
$grados = $bdd->query("SELECT id, grado FROM grados ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $libro) {
    $es_serie = ($libro["id_grado"] == 15 || $libro["id_grado"] == 16);
    $disabled = $puede_gestionar ? '' : 'disabled';

    $isbnHtml = '<input type="text" class="dt-isbn" value="' . htmlspecialchars($libro["isbn"]) . '" ' . $disabled . '>';
    $libroHtml = '<input type="text" class="dt-libro" value="' . htmlspecialchars($libro["libro"]) . '" ' . $disabled . '>';

    $materiaHtml = '<select class="dt-materia" ' . $disabled . '>';
    foreach ($materias as $materia) {
        $sel = ($materia["id"] == $libro["id_materia"]) ? 'selected' : '';
        $materiaHtml .= '<option value="' . $materia["id"] . '" ' . $sel . '>' . htmlspecialchars($materia["materia"]) . '</option>';
    }
    $materiaHtml .= '</select>';

    $gradoHtml = '<select class="dt-grado" ' . $disabled . '>';
    foreach ($grados as $grado) {
        $sel = ($grado["id"] == $libro["id_grado"]) ? 'selected' : '';
        $gradoHtml .= '<option value="' . $grado["id"] . '" ' . $sel . '>' . htmlspecialchars($grado["grado"]) . '</option>';
    }
    $gradoHtml .= '</select>';

    if ($es_serie) {
        $precioHtml = '<span class="text-muted">&mdash;</span>';
    } else {
        $precioHtml = '<input type="number" class="dt-precio" value="' . htmlspecialchars($libro["precio"]) . '" step="any" ' . $disabled . '>';
    }

    $presupuestoHtml = '<select class="dt-presupuesto" ' . $disabled . '>'
        . '<option value="1" ' . ($libro["presupuesto"] == 1 ? 'selected' : '') . '>Sí</option>'
        . '<option value="0" ' . ($libro["presupuesto"] == 0 ? 'selected' : '') . '>No</option>'
        . '</select>';

    $num_hijos = (int) $libro["num_hijos"];
    $pri_sec = (int) $libro["pri_sec"];
    $libroNombreAttr = htmlspecialchars($libro["libro"], ENT_QUOTES);

    if ($num_hijos > 0) {
        $asociacionHtml = '<span class="badge-serie badge-serie-padre" title="' . $num_hijos . ' libro(s) asociados a este como serie"><i class="bi bi-diagram-3"></i> Padre &middot; ' . $num_hijos . '</span>';
    } elseif ($pri_sec > 0 && $libro["nombre_padre"] !== null) {
        $asociacionHtml = '<span class="badge-serie badge-serie-hijo" title="Asociado a: ' . htmlspecialchars($libro["nombre_padre"], ENT_QUOTES) . '"><i class="bi bi-link-45deg"></i> ' . htmlspecialchars($libro["nombre_padre"]) . '</span>';
        if ($puede_gestionar) {
            $asociacionHtml .= ' <button type="button" class="btn-asociar-serie" data-id="' . $libro["id"] . '" data-libro="' . $libroNombreAttr . '" data-materia="' . $libro["id_materia"] . '" data-padre-id="' . $pri_sec . '" data-padre-nombre="' . htmlspecialchars($libro["nombre_padre"], ENT_QUOTES) . '" title="Cambiar asociación"><i class="bi bi-pencil"></i></button>';
        }
    } else {
        $asociacionHtml = '<span class="badge-serie badge-serie-libre">Sin asociar</span>';
        if ($puede_gestionar) {
            $asociacionHtml .= ' <button type="button" class="btn-asociar-serie" data-id="' . $libro["id"] . '" data-libro="' . $libroNombreAttr . '" data-materia="' . $libro["id_materia"] . '" title="Asociar a una serie"><i class="bi bi-link"></i> Asociar</button>';
        }
    }

    if ($puede_gestionar) {
        $accionesHtml = '<div class="acciones-libro">'
            . '<button type="button" class="btn-save-libro" data-id="' . $libro["id"] . '" title="Guardar cambios"><i class="bi bi-check-lg"></i></button>'
            . '<button type="button" class="btn-delete-libro" data-id="' . $libro["id"] . '" data-titulo="' . htmlspecialchars($libro["libro"], ENT_QUOTES) . '" title="Eliminar libro"><i class="bi bi-trash3"></i></button>'
            . '</div>';
    } else {
        $accionesHtml = '<span class="text-muted">&mdash;</span>';
    }

    $data[] = [
        'isbn'        => $isbnHtml,
        'libro'       => $libroHtml,
        'materia'     => $materiaHtml,
        'grado'       => $gradoHtml,
        'precio'      => $precioHtml,
        'presupuesto' => $presupuestoHtml,
        'asociacion'  => $asociacionHtml,
        'acciones'    => $accionesHtml,
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => intval($recordsTotal),
    "recordsFiltered" => intval($recordsFiltered),
    "data" => $data
]);
