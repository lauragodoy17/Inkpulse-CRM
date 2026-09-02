<?php
/**
 * /php/colocacion_buscar_colegio.php
 * Búsqueda de colegios (cualquier calendario, no solo Calendario B) para el select2 de "asignar
 * colegio" del panel "Documentos de World Office sin cruzar" en reporte_colocacion.php — por AJAX
 * a medida que se escribe, en vez de precargar los ~3.840 colegios del sistema en cada uno de los
 * (potencialmente miles de) <select> del panel, que colgaba el navegador.
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if ($tipo_sesion !== 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

// Busca por nombre O por código DANE — el DANE también se muestra en cada resultado (ver
// reporte_colocacion.php) para distinguir colegios homónimos entre zonas/calendarios (ver
// memory/project_colocacion_modulo.md, bugs #2/#3 de cruce por nombre duplicado).
$stmt = $bdd->prepare("SELECT id, colegio, dane FROM colegios WHERE colegio LIKE ? OR dane LIKE ? ORDER BY colegio ASC LIMIT 30");
$stmt->execute(['%' . $q . '%', '%' . $q . '%']);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
