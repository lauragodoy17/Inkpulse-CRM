<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once(__DIR__ . "/../includes/paquetes_colegio.php");

	if (!puede_usar_tipo_adopcion()) {
		http_response_code(403);
		echo json_encode(["ok" => false]);
		exit;
	}

	$id_colegio = intval($_POST['id_colegio'] ?? 0);
	$id_periodo = intval($_POST['periodo'] ?? 0);
	$tipo_adop  = intval($_POST['tipo_adop'] ?? 0);
	$definidos  = $_POST['definidos'] ?? [];
	$en_paquete = $_POST['en_paquete'] ?? [];
	$en_paquete2 = $_POST['en_paquete2'] ?? [];

	if ($id_colegio <= 0 || $id_periodo <= 0) {
		http_response_code(400);
		echo json_encode(["ok" => false]);
		exit;
	}

	crear_tablas_paquetes($bdd);

	// Descuentos escritos en pantalla, aún sin guardar ({id_presupuesto: porcentaje}): el neto se ve en tiempo real (2026-11-24).
	$descuentos = [];
	foreach ((array)($_POST['descuentos'] ?? []) as $id_p => $pct) {
		$pct = str_replace(',', '.', trim((string)$pct));
		if ((int)$id_p > 0 && is_numeric($pct) && (float)$pct >= 0 && (float)$pct <= 100) $descuentos[(int)$id_p] = (float)$pct / 100;
	}

	$paquetes = construir_preview_paquetes($bdd, $id_colegio, $id_periodo, (array)$definidos, (array)$en_paquete, $tipo_adop, (array)$en_paquete2, $descuentos);

	echo json_encode(["ok" => true, "paquetes" => $paquetes]);
?>
