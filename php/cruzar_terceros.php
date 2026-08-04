<?php
/**
 * /php/cruzar_terceros.php
 * Recibe una lista de ids de World Office (los que el usuario marcó con checkbox
 * en clientes_op.php) y los inserta en la tabla local `clientes`. Vuelve a
 * consultar cada tercero directamente en World Office (no confía en datos
 * mandados desde el navegador) y valida de nuevo que no exista ya localmente
 * antes de insertar, por si la lista mostrada en pantalla quedó desactualizada.
 */
require_once("aut.php");

header('Content-Type: application/json');

if (($_SESSION["tipo"] ?? null) != 1) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../includes/wo_clientes_sync.php");
require_once("../conexion/bdd.php");

$body = json_decode(file_get_contents('php://input'), true);
$ids = isset($body['ids']) && is_array($body['ids']) ? array_values(array_unique(array_map('intval', $body['ids']))) : [];
$ids = array_filter($ids, function ($id) { return $id > 0; });

if (!$ids) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron ids para cruzar']);
    exit;
}

$stmtExisteDoc = $bdd->prepare("SELECT id FROM clientes WHERE TRIM(documento) = ? LIMIT 1");
$stmtExisteWo  = $bdd->prepare("SELECT id FROM clientes WHERE id_wo = ? LIMIT 1");
$stmtInsertar  = $bdd->prepare(
    "INSERT INTO clientes (cliente, documento, direccion, telefonos, ciudad, id_wo, created_at, updated_at)
     VALUES (?, ?, '', '', ?, ?, NOW(), NOW())"
);

$insertados = [];
$omitidos = [];
$errores = [];

foreach ($ids as $id) {
    $detalleResp = consultar_tercero($id);
    $d = (($detalleResp['status'] ?? '') === 'error') ? null : ($detalleResp['data'] ?? $detalleResp);

    $identificacion = trim((string)($d['identificacion'] ?? ''));
    $nombreCompleto = trim((string)($d['nombreCompleto'] ?? ''));

    if (!$d || $identificacion === '' || $nombreCompleto === '') {
        $errores[] = ['id' => $id, 'motivo' => 'No se pudo consultar el detalle en World Office'];
        continue;
    }

    $stmtExisteWo->execute([$id]);
    if ($stmtExisteWo->fetchColumn()) {
        $omitidos[] = ['id' => $id, 'identificacion' => $identificacion, 'motivo' => 'Ya existe en clientes (id_wo)'];
        continue;
    }

    $stmtExisteDoc->execute([$identificacion]);
    if ($stmtExisteDoc->fetchColumn()) {
        $omitidos[] = ['id' => $id, 'identificacion' => $identificacion, 'motivo' => 'Ya existe en clientes (documento)'];
        continue;
    }

    $ciudad = $d['ciudad']['nombre'] ?? '';

    $stmtInsertar->execute([$nombreCompleto, $identificacion, $ciudad, $id]);
    $insertados[] = ['id' => $id, 'identificacion' => $identificacion, 'cliente' => $nombreCompleto];
}

// El piso puede haber avanzado con este cruce (o no, si quedaron huecos por
// debajo de algún id recién insertado) — se recalcula para que el frontend
// pueda refrescar la tarjeta sin tener que volver a lanzar una búsqueda.
$piso = calcular_piso_efectivo($bdd);

echo json_encode([
    'success' => true,
    'piso' => $piso,
    'insertados' => $insertados,
    'omitidos' => $omitidos,
    'errores' => $errores,
]);
