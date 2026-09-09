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

	if ($id_colegio <= 0 || $id_periodo <= 0) {
		http_response_code(400);
		echo json_encode(["ok" => false]);
		exit;
	}

	crear_tablas_paquetes($bdd);

	$paquetes = construir_preview_paquetes($bdd, $id_colegio, $id_periodo, $definidos, $en_paquete, $tipo_adop);

	echo json_encode(["ok" => true, "paquetes" => $paquetes]);
?>
