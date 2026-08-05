<?php
/**
 * /php/clasificar_libros_bodega.php
 * Clasifica cada libro (con id_wo asignado) según en qué bodega(s) de World
 * Office tiene existencias: GET /inventarios/{id}/existencias/bodega.
 *   - Solo en bodega id=1 "General"          -> libros.tipo = 1
 *   - Solo en bodega id=4 "Muestras General" -> libros.tipo = 4
 *   - En ambas                                -> libros.tipo = 3
 *   - En ninguna de las dos (aunque esté en otra bodega, ej. id=52)
 *                                              -> libros.tipo = 0
 * Se llama en lotes (pagina/porPagina) desde libros_bodega.php porque son
 * ~2,474 libros con id_wo, uno por uno contra WO (~0.5s cada uno) — un solo
 * request no alcanzaría a terminar antes del timeout del servidor.
 * Esto SÍ escribe en la base local (`libros.tipo`), a diferencia de los
 * paneles de solo lectura de pruebas_api/.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/api_wo_inventario.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

const BODEGA_GENERAL = 1;
const BODEGA_MUESTRAS_GENERAL = 4;

$pagina    = isset($_GET['pagina']) ? max(0, (int)$_GET['pagina']) : 0;
$porPagina = isset($_GET['porPagina']) ? max(1, min(50, (int)$_GET['porPagina'])) : 20;

$inicio = microtime(true);

$totalLibros = (int)$bdd->query("SELECT COUNT(*) FROM libros WHERE id_wo > 0")->fetchColumn();
$totalPaginas = (int)ceil($totalLibros / $porPagina);

$stmt = $bdd->prepare("SELECT id, isbn, libro, id_wo, tipo FROM libros WHERE id_wo > 0 ORDER BY id ASC LIMIT :limite OFFSET :offset");
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagina * $porPagina, PDO::PARAM_INT);
$stmt->execute();
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtActualizar = $bdd->prepare("UPDATE libros SET tipo = ?, updated_at = NOW() WHERE id = ?");

$procesados = [];
$resumen = [1 => 0, 3 => 0, 4 => 0, 0 => 0];
$errores = [];

foreach ($libros as $libro) {
    $respuesta = consultar_existencias_por_bodega($libro['id_wo']);
    if (($respuesta['status'] ?? 'error') !== 'OK') {
        $errores[] = ['id' => $libro['id'], 'isbn' => $libro['isbn'], 'libro' => $libro['libro'], 'id_wo' => $libro['id_wo']];
        continue;
    }

    $bodegas = $respuesta['data']['content'] ?? [];
    $enGeneral = false;
    $enMuestras = false;
    foreach ($bodegas as $b) {
        $idBodega = (int)($b['id'] ?? 0);
        if ($idBodega === BODEGA_GENERAL) $enGeneral = true;
        if ($idBodega === BODEGA_MUESTRAS_GENERAL) $enMuestras = true;
    }

    $tipoNuevo = ($enGeneral && $enMuestras) ? 3 : ($enGeneral ? 1 : ($enMuestras ? 4 : 0));

    $stmtActualizar->execute([$tipoNuevo, $libro['id']]);
    $resumen[$tipoNuevo]++;

    $procesados[] = [
        'id' => (int)$libro['id'],
        'isbn' => $libro['isbn'],
        'libro' => $libro['libro'],
        'idWo' => (int)$libro['id_wo'],
        'tipoAnterior' => (int)$libro['tipo'],
        'tipoNuevo' => $tipoNuevo,
        'enGeneral' => $enGeneral,
        'enMuestras' => $enMuestras,
    ];
}

echo json_encode([
    'success' => true,
    'pagina' => $pagina,
    'totalPaginas' => $totalPaginas,
    'totalLibros' => $totalLibros,
    'revisadosEnPagina' => count($libros),
    'procesados' => $procesados,
    'resumen' => $resumen,
    'errores' => $errores,
    'ms' => round((microtime(true) - $inicio) * 1000),
]);
