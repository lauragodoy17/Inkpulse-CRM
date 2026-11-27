<?php
/**
 * /php/lista_empaque_borrador.php
 * Borradores de listas de empaque (ver le_borrador_guardar()):
 *   GET  ?id=N                              → el borrador para seguir editándolo en lista_empaque.php
 *   POST (JSON) {accion:'guardar', id?, …}  → crea o actualiza el borrador
 *   POST (JSON) {accion:'eliminar', id}     → lo elimina
 */
require_once("aut.php");
header('Content-Type: application/json');

if (($_SESSION['autentificado'] ?? '') !== 'SI') {
    header_remove('Location');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Tu sesión expiró. Inicia sesión en otra pestaña y vuelve a guardar el borrador: lo que armaste en esta página no se pierde.']);
    exit;
}
if (!in_array(intval($_SESSION["tipo"] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/listas_empaque_datos.php");
le_crear_tablas($bdd);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $b = le_borrador_obtener($bdd, intval($_GET['id'] ?? 0));
    echo json_encode($b ? ['ok' => true, 'borrador' => $b] : ['ok' => false, 'error' => 'El borrador no existe: puede que ya se haya generado la lista o que alguien lo haya eliminado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);
    exit;
}
$accion = $entrada['accion'] ?? '';
if ($accion === 'guardar') {
    echo json_encode(le_borrador_guardar($bdd, $entrada, intval($_SESSION['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
} elseif ($accion === 'eliminar') {
    echo json_encode(le_borrador_eliminar($bdd, intval($entrada['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'error' => 'Acción no válida.']);
}
