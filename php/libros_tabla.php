<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

$draw = intval($_GET['draw']);

if ($_SESSION["tipo"] != 1 && ($_SESSION["id"] ?? null) != 21) {
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

$puede_gestionar = true;
// Carlos Puentes (id=21) puede ver y abrir "Editar" (limitado a la ubicación en bodega
// desde libros.php), pero nunca eliminar libros.
$puede_eliminar = ($_SESSION["tipo"] == 1);
// Asociar libros a una serie es exclusivo del usuario id=1.
$puede_asociar = ($_SESSION["id"] == 1);

$start = intval($_GET['start']);
$length = intval($_GET['length']);
$searchValue = $_GET['search']['value'] ?? '';

$columns = ['isbn', 'libro', 'ubicacion', 'materia', 'grado', 'precio', 'presupuesto', 'asociacion', 'acciones'];

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

// ── Ubicaciones en bodega (batch fetch para evitar N+1) ──────────
$ubi_map = [];
$libro_ids = array_column($rows, 'id');
if (!empty($libro_ids)) {
    $ph = implode(',', array_fill(0, count($libro_ids), '?'));
    $req_ubi = $bdd->prepare(
        "SELECT lu.id_libro, lu.posicion, u.id AS ubicacion_id, u.piso, u.ubicacion AS slot,
                l.id AS lugar_id, l.id_tipo, l.lugar
         FROM libros_ubicaciones lu
         JOIN ubicaciones u ON u.id = lu.ubicacion
         JOIN lugares l     ON l.id = u.id_lugar
         WHERE lu.id_libro IN ($ph)
         ORDER BY lu.id"
    );
    $req_ubi->execute($libro_ids);
    foreach ($req_ubi->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ubi_map[$row['id_libro']][] = $row;
    }
}

$data = [];
foreach ($rows as $libro) {
    $es_serie = ($libro["id_grado"] == 15 || $libro["id_grado"] == 16);

    $isbnHtml = htmlspecialchars($libro["isbn"]);
    $libroHtml = htmlspecialchars($libro["libro"]);

    $materia_nombre = '';
    foreach ($materias as $materia) {
        if ($materia["id"] == $libro["id_materia"]) { $materia_nombre = $materia["materia"]; break; }
    }
    $materiaHtml = htmlspecialchars($materia_nombre);

    $grado_nombre = '';
    foreach ($grados as $grado) {
        if ($grado["id"] == $libro["id_grado"]) { $grado_nombre = $grado["grado"]; break; }
    }
    $gradoHtml = htmlspecialchars($grado_nombre);

    $precioHtml = $es_serie
        ? '<span class="text-muted">&mdash;</span>'
        : '$ ' . number_format((float) $libro["precio"], 0, ',', '.');

    $presupuestoHtml = ($libro["presupuesto"] == 1)
        ? '<span class="badge-serie" style="background:#dcfce7;color:#15803d;">Sí</span>'
        : '<span class="badge-serie" style="background:#f1f5f9;color:#64748b;">No</span>';

    $ubis = $ubi_map[$libro["id"]] ?? [];
    $ubi_str = '';
    foreach ($ubis as $ub) {
        $ubi_str .= ($ub['id_tipo'] == 1)
            ? $ub['lugar'] . $ub['piso'] . ' Pallet ' . $ub['slot'] . ' ' . $ub['posicion'] . ', '
            : $ub['lugar'] . ' Bandeja ' . $ub['slot'] . ', ';
    }
    $ubi_str = rtrim($ubi_str, ', ');
    $ubicacionHtml = $ubi_str !== '' ? htmlspecialchars($ubi_str) : '<span class="text-muted">&mdash;</span>';
    $ubi_actual = $ubis[0] ?? null;

    $num_hijos = (int) $libro["num_hijos"];
    $pri_sec = (int) $libro["pri_sec"];
    $libroNombreAttr = htmlspecialchars($libro["libro"], ENT_QUOTES);

    if ($num_hijos > 0) {
        $asociacionHtml = '<span class="badge-serie badge-serie-padre" title="' . $num_hijos . ' libro(s) asociados a este como serie"><i class="bi bi-diagram-3"></i> Padre &middot; ' . $num_hijos . '</span>';
    } elseif ($pri_sec > 0 && $libro["nombre_padre"] !== null) {
        $asociacionHtml = '<span class="badge-serie badge-serie-hijo" title="Asociado a: ' . htmlspecialchars($libro["nombre_padre"], ENT_QUOTES) . '"><i class="bi bi-link-45deg"></i> ' . htmlspecialchars($libro["nombre_padre"]) . '</span>';
        if ($puede_asociar) {
            $asociacionHtml .= ' <button type="button" class="btn-asociar-serie" data-id="' . $libro["id"] . '" data-libro="' . $libroNombreAttr . '" data-materia="' . $libro["id_materia"] . '" data-padre-id="' . $pri_sec . '" data-padre-nombre="' . htmlspecialchars($libro["nombre_padre"], ENT_QUOTES) . '" title="Cambiar asociación"><i class="bi bi-pencil"></i></button>';
        }
    } else {
        $asociacionHtml = '<span class="badge-serie badge-serie-libre">Sin asociar</span>';
        if ($puede_asociar) {
            $asociacionHtml .= ' <button type="button" class="btn-asociar-serie" data-id="' . $libro["id"] . '" data-libro="' . $libroNombreAttr . '" data-materia="' . $libro["id_materia"] . '" title="Asociar a una serie"><i class="bi bi-link"></i> Asociar</button>';
        }
    }

    if ($puede_gestionar) {
        $accionesHtml = '<div class="acciones-libro">'
            . '<button type="button" class="btn-editar-libro"'
            . ' data-id="' . $libro["id"] . '"'
            . ' data-isbn="' . htmlspecialchars($libro["isbn"], ENT_QUOTES) . '"'
            . ' data-libro="' . $libroNombreAttr . '"'
            . ' data-materia="' . $libro["id_materia"] . '"'
            . ' data-grado="' . $libro["id_grado"] . '"'
            . ' data-precio="' . htmlspecialchars($libro["precio"], ENT_QUOTES) . '"'
            . ' data-presupuesto="' . (int) $libro["presupuesto"] . '"'
            . ' data-serie="' . ($es_serie ? '1' : '0') . '"'
            . ' data-lugar="' . ($ubi_actual['lugar_id'] ?? '') . '"'
            . ' data-piso="' . htmlspecialchars($ubi_actual['piso'] ?? '', ENT_QUOTES) . '"'
            . ' data-ubicacion-id="' . ($ubi_actual['ubicacion_id'] ?? '') . '"'
            . ' data-ubicacion-posicion="' . htmlspecialchars($ubi_actual['posicion'] ?? '', ENT_QUOTES) . '"'
            . ' title="Editar libro"><i class="bi bi-pencil-square"></i></button>';
        if ($puede_eliminar) {
            $accionesHtml .= '<button type="button" class="btn-eliminar-libro" data-id="' . $libro["id"] . '" data-titulo="' . htmlspecialchars($libro["libro"], ENT_QUOTES) . '" title="Eliminar libro"><i class="bi bi-trash3"></i></button>';
        }
        $accionesHtml .= '</div>';
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
        'ubicacion'   => $ubicacionHtml,
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
