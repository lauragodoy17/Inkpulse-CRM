<?php
/**
 * /php/colocacion_asignar_colegio.php
 * Asigna manualmente un colegio (de cualquier calendario — un documento sin cruzar puede ser
 * tanto de Calendario A como de B) a un documento de World Office que el cruce automático
 * (includes/matching_colegios.php) no pudo emparejar. Marca `asignado_manual = 1` para que una
 * futura sincronización (php/sync_colocacion_wo.php) no sobrescriba la asignación manual con el
 * resultado (probablemente NULL de nuevo) del matcher.
 *
 * `tipo_documento` es OBLIGATORIO junto con `id_wo`: la clave única real de la tabla es compuesta
 * (id_wo, tipo_documento) — los ids de World Office son por módulo, no globales, y hay
 * solapamiento real de rangos confirmado entre FV y DREM/POS/NCV (ver
 * memory/project_wo_api.md). Un UPDATE que filtrara solo por id_wo podría tocar el documento de
 * OTRO tipo que comparta ese mismo id numérico.
 *
 * `id_periodo` también se recalcula acá (2026-09-02, fix): cada documento queda etiquetado al
 * sincronizar con el período ACTIVO DE CALENDARIO B (ver php/sync_colocacion_wo.php), porque el
 * cruce automático solo asigna colegios de Calendario B. Pero esta asignación manual admite
 * colegios de CUALQUIER calendario — si el colegio elegido es de Calendario A, el documento debe
 * quedar etiquetado con el período activo de Calendario A (no el de B), o nunca aparecerá en
 * reporte_colocacion.php al filtrar por un período de Calendario A (el reporte filtra
 * `wo_documentos_colocacion` por `id_periodo` exacto — ver includes/colocacion_datos.php). Sin
 * este recálculo, el documento queda "cruzado" en la base de datos pero invisible en cualquier
 * período que se filtre — confirmado con un caso real (Santísimo Sacramento, colegio de Calendario
 * A, asignado bajo el período de Calendario B, invisible al filtrar 2027).
 *
 * 2026-09-03: el "período activo" de un calendario NO es simplemente el más reciente creado en la
 * tabla `periodos` — no existe ninguna columna/bandera de "activo", así que tomar `ORDER BY id
 * DESC LIMIT 1` asume que el último período creado es el vigente. Eso es cierto hoy para
 * Calendario B (2026B es el más reciente Y el vigente), pero para Calendario A ya existe el
 * período 2027 creado de antemano (planeación futura) mientras 2026 sigue siendo el vigente real
 * — confirmado por el usuario tras encontrar 267 documentos asignados manualmente a colegios de
 * Calendario A que habían quedado etiquetados con 2027 en vez de 2026 (corregidos por SQL directo
 * el mismo día). Por pedido explícito del usuario, Calendario A queda fijo en el período "2026"
 * (constante `PERIODO_ACTIVO_CALENDARIO_A` abajo) en vez de "el más reciente"; Calendario B sigue
 * usando `ORDER BY id DESC LIMIT 1` porque ahí sí coincide con el vigente. Si en el futuro se crea
 * un período de Calendario A posterior a 2026 que SÍ sea el vigente, hay que actualizar esa
 * constante a mano — no hay forma de derivarlo automáticamente sin una bandera de "activo" en la
 * tabla `periodos`.
 *
 * 2026-11-03: por pedido del usuario, Calendario A pasa a 2027 para los documentos con fecha desde
 * el 2026-11-01; los anteriores siguen en 2026. Se compara la fecha del documento (no la del día en
 * que se asigna) para que un documento viejo asignado tarde no caiga en 2027. Calendario B no cambia.
 * Regla compartida con la sincronización, ver includes/colocacion_cruce.php.
 *
 * Recibe uno o varios documentos: `documentos` (JSON, [{id_wo, tipo_documento}, ...]) para asignar
 * un grupo completo desde el panel de reporte_colocacion.php.
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/colocacion_cruce.php");
header('Content-Type: application/json');

$documentos = json_decode($_POST['documentos'] ?? '', true);
$id_colegio = isset($_POST['id_colegio']) ? (int)$_POST['id_colegio'] : 0;

if (!is_array($documentos) || !$documentos || $id_colegio <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$stmt = $bdd->prepare("SELECT id_calendario FROM colegios WHERE id = ?");
$stmt->execute([$id_colegio]);
$id_calendario_colegio = $stmt->fetchColumn();
if ($id_calendario_colegio === false) {
    echo json_encode(['success' => false, 'message' => 'El colegio no existe']);
    exit;
}

$periodosA = [];
$id_periodo_colegio = false;
if ((int)$id_calendario_colegio === 1) {
    foreach ($bdd->query("SELECT periodo, id FROM periodos WHERE id_calendario = 1")->fetchAll(PDO::FETCH_NUM) as [$nombre, $id]) {
        $periodosA[(string)$nombre] = (int)$id;
    }
} else {
    $stmt = $bdd->prepare("SELECT id FROM periodos WHERE id_calendario = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id_calendario_colegio]);
    $id_periodo_colegio = $stmt->fetchColumn();
    if ($id_periodo_colegio === false) {
        echo json_encode(['success' => false, 'message' => 'El colegio no tiene un período activo en su calendario']);
        exit;
    }
}

$stmtFecha = $bdd->prepare("SELECT fecha FROM wo_documentos_colocacion WHERE id_wo = ? AND tipo_documento = ?");
$stmtAsignar = $bdd->prepare("UPDATE wo_documentos_colocacion SET id_colegio = ?, id_periodo = ?, asignado_manual = 1, updated_at = NOW() WHERE id_wo = ? AND tipo_documento = ?");
$asignados = 0;
$bdd->beginTransaction();
try {
    foreach ($documentos as $doc) {
        $id_wo = (int)($doc['id_wo'] ?? 0);
        $tipo_documento = trim((string)($doc['tipo_documento'] ?? ''));
        if ($id_wo <= 0 || $tipo_documento === '') continue;
        $stmtFecha->execute([$id_wo, $tipo_documento]);
        $fecha_documento = $stmtFecha->fetchColumn();
        if ($fecha_documento === false) continue;
        $id_periodo = (int)$id_calendario_colegio === 1
            ? colocacion_periodo_calendario_a($periodosA, $fecha_documento)
            : $id_periodo_colegio;
        if (!$id_periodo) continue;
        $stmtAsignar->execute([$id_colegio, $id_periodo, $id_wo, $tipo_documento]);
        $asignados += $stmtAsignar->rowCount();
    }
    $bdd->commit();
} catch (Exception $e) {
    $bdd->rollBack();
    echo json_encode(['success' => false, 'message' => 'No se pudieron guardar las asignaciones.']);
    exit;
}

echo json_encode(['success' => $asignados > 0, 'asignados' => $asignados, 'message' => $asignados > 0 ? '' : 'No se asignó ningún documento.']);
