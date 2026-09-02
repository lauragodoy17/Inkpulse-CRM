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
 */
require_once("aut.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
header('Content-Type: application/json');

$id_wo = isset($_POST['id_wo']) ? (int)$_POST['id_wo'] : 0;
$tipo_documento = trim($_POST['tipo_documento'] ?? '');
$id_colegio = isset($_POST['id_colegio']) ? (int)$_POST['id_colegio'] : 0;

if ($id_wo <= 0 || $tipo_documento === '' || $id_colegio <= 0) {
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

$stmt = $bdd->prepare("SELECT id FROM periodos WHERE id_calendario = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$id_calendario_colegio]);
$id_periodo_colegio = $stmt->fetchColumn();
if ($id_periodo_colegio === false) {
    echo json_encode(['success' => false, 'message' => 'El colegio no tiene un período activo en su calendario']);
    exit;
}

try {
    $bdd->exec("ALTER TABLE wo_documentos_colocacion ADD COLUMN asignado_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER id_colegio");
} catch (Exception $e) {}

$stmt = $bdd->prepare("UPDATE wo_documentos_colocacion SET id_colegio = ?, id_periodo = ?, asignado_manual = 1, updated_at = NOW() WHERE id_wo = ? AND tipo_documento = ?");
$stmt->execute([$id_colegio, $id_periodo_colegio, $id_wo, $tipo_documento]);

echo json_encode(['success' => $stmt->rowCount() > 0]);
