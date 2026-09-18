<?php
/**
 * /php/informe_editorial_guardar_presupuesto_temporada.php
 * Guarda el "Presupuesto asignado por temporada" (columna J del Excel de Informe Cumplimiento)
 * que se llenaba a mano en cada descarga — ahora se guarda para no tener que volver a escribirlo
 * cada semana. Pedido por el usuario 2026-09-18. Ver reporte_editorial.php (formulario) y
 * includes/informe_editorial_datos.php (guardar_presupuesto_temporada_informe_editorial()).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/informe_editorial_datos.php");
header('Content-Type: application/json');

$idPeriodo = (int)($_POST['periodo'] ?? 0);
$valores = $_POST['presupuesto'] ?? [];
if ($idPeriodo <= 0 || !is_array($valores)) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
    exit;
}

// Se limpia a número (quita separadores de miles si el navegador mandó algo con puntos/comas) y se
// descartan valores no numéricos en vez de fallar todo el guardado por una fila mal escrita.
$valoresLimpios = [];
foreach ($valores as $idUsuario => $valor) {
    $idUsuario = (int)$idUsuario;
    if ($idUsuario <= 0) continue;
    $valor = trim((string)$valor);
    if ($valor === '') { $valoresLimpios[$idUsuario] = 0; continue; }
    if (!is_numeric($valor)) continue;
    $valoresLimpios[$idUsuario] = (float)$valor;
}

guardar_presupuesto_temporada_informe_editorial($bdd, $idPeriodo, $valoresLimpios);
echo json_encode(['success' => true, 'guardados' => count($valoresLimpios)]);
