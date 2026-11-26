<?php
/**
 * /php/planillas_devolucion_accion.php — acciones del módulo Planillas de devolución (planillas_devolucion.php).
 * POST accion=generar: id_pedido → genera y guarda la planilla (consulta WO). {ok, id, numero, url}
 * POST accion=adjuntar: id + archivo → adjunta (o reemplaza) el archivo de la planilla. {ok, msg}
 * POST accion=anular: id + motivo → la planilla queda anulada (sigue en el histórico). {ok, msg}
 * Responde JSON {ok, error?}.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("registrar_historial.php");
require_once("../includes/planillas_devolucion_datos.php");

header('Content-Type: application/json; charset=utf-8');
dm_validar_acceso(true);

function pdva_salir(array $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdva_salir(['ok' => false, 'error' => 'Solicitud no válida.']);
pdv_asegurar($bdd);
$id_usuario = (int)($_SESSION['id'] ?? 0);
$accion = (string)($_POST['accion'] ?? '');

if ($accion === 'generar') {
    $r = pdv_generar($bdd, (int)($_POST['id_pedido'] ?? 0), $id_usuario);
    if (!$r['ok']) pdva_salir(['ok' => false, 'error' => $r['error']]);
    $f = pdv_obtener($bdd, $r['id']);
    registrar_historial($bdd, $f['id_colegio'], $id_usuario, 'Venta directa', 'Planilla de devolución generada', '',
        pdv_numero($r['id']) . ' · pedido #' . (int)$f['id_pedido']);
    pdva_salir(['ok' => true, 'id' => $r['id'], 'numero' => pdv_numero($r['id']), 'url' => 'php/planilla_devolucion.php?id=' . $r['id']]);
}

$f = pdv_obtener($bdd, (int)($_POST['id'] ?? 0));
if (!$f) pdva_salir(['ok' => false, 'error' => 'La planilla no existe o no está en tu alcance.']);

if ($accion === 'adjuntar') {
    if ((int)$f['estado'] !== PDV_ESTADO_ACTIVA) pdva_salir(['ok' => false, 'error' => 'La planilla está anulada.']);
    $r = pdv_adjuntar($bdd, $f, $_FILES['archivo'] ?? [], $id_usuario);
    if (!$r['ok']) pdva_salir($r);
    registrar_historial($bdd, $f['id_colegio'], $id_usuario, 'Venta directa', 'Adjunto de la planilla de devolución ' . pdv_numero($f['id']),
        (string)$f['adjunto_nombre'], mb_substr(basename((string)$_FILES['archivo']['name']), 0, 255));
    pdva_salir(['ok' => true, 'msg' => 'Se adjuntó el archivo a la planilla ' . pdv_numero($f['id']) . '.']);
}

if ($accion === 'anular') {
    if ((int)$f['estado'] !== PDV_ESTADO_ACTIVA) pdva_salir(['ok' => false, 'error' => 'La planilla ya está anulada.']);
    $motivo = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['motivo'] ?? ''))), 0, 500);
    if ($motivo === '') pdva_salir(['ok' => false, 'error' => 'Escribe el motivo de la anulación.']);
    $bdd->prepare("UPDATE planillas_devolucion SET estado = ?, motivo_anulacion = ?, anulada_at = NOW(), anulada_por = ? WHERE id = ?")
        ->execute([PDV_ESTADO_ANULADA, $motivo, $id_usuario, (int)$f['id']]);
    registrar_historial($bdd, $f['id_colegio'], $id_usuario, 'Venta directa', 'Planilla de devolución anulada', pdv_numero($f['id']), $motivo);
    pdva_salir(['ok' => true, 'msg' => 'Se anuló la planilla ' . pdv_numero($f['id']) . '.']);
}

pdva_salir(['ok' => false, 'error' => 'Acción no válida.']);
