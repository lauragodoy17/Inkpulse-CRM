<?php
require_once("aut.php");
require_once("../conexion/bdd.php");
header('Content-Type: application/json');

if ($_SESSION["tipo"] != 1 || !isset($_POST["id"])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$id_periodo = $_POST["id"];

// Tablas que referencian periodos.id a través de id_periodo. Si el período
// ya tiene registros asociados en cualquiera de ellas, no se puede eliminar.
$tablas_relacionadas = [
    'adjuntos', 'areas_objetivas', 'colegios_estados_clientes', 'colegios_status',
    'devoluciones', 'devoluciones_prov', 'devoluciones_v', 'estudiantes',
    'grados_materias', 'grados_paralelos', 'ia_presupuestos', 'ia_trm',
    'mercado_editorial', 'muestreos', 'muestreos_e', 'notificaciones',
    'pedidos', 'pedidos2', 'pension', 'plan_trabajo', 'presupuestos',
    'profes_delfos', 'recursos', 'solicitudes_recursos', 'visitas', 'zonas_periodos',
];

foreach ($tablas_relacionadas as $tabla) {
    $req = $bdd->prepare("SELECT COUNT(*) FROM `$tabla` WHERE id_periodo = :id");
    $req->execute([':id' => $id_periodo]);
    if ($req->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el período ya está referenciado en el sistema y tiene información asociada.']);
        exit;
    }
}

$req_del = $bdd->prepare("DELETE FROM periodos WHERE id=:id");
$req_del->execute([':id' => $id_periodo]);

echo json_encode(['success' => true, 'message' => 'Período eliminado correctamente.']);
