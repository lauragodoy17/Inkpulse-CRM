<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once(__DIR__ . "/../includes/paquetes_colegio.php");

	if (!puede_usar_tipo_adopcion()) {
		http_response_code(403);
		echo json_encode(["ok" => false]);
		exit;
	}

	$id_paquete = intval($_POST['id_paquete'] ?? 0);
	$precio_raw = trim((string)($_POST['precio_redondeado'] ?? ''));

	if ($id_paquete <= 0) {
		http_response_code(400);
		echo json_encode(["ok" => false]);
		exit;
	}

	$precio_redondeado = ($precio_raw === '') ? null : (float)$precio_raw;
	if ($precio_redondeado !== null && $precio_redondeado < 0) {
		http_response_code(400);
		echo json_encode(["ok" => false]);
		exit;
	}

	$req_paq = $bdd->prepare("SELECT precio_neto_sumado, id_colegio, id_periodo FROM paquetes_colegio WHERE id=?");
	$req_paq->execute([$id_paquete]);
	$paq = $req_paq->fetch();

	if ($paq === false) {
		http_response_code(404);
		echo json_encode(["ok" => false]);
		exit;
	}
	$precio_neto_sumado = $paq['precio_neto_sumado'];

	require_once(__DIR__ . "/../includes/adopcion_cerrada.php");
	if (adopcion_cerrada($bdd, $paq['id_colegio'], $paq['id_periodo'])) {
		http_response_code(403);
		echo json_encode(["ok" => false]);
		exit;
	}

	$precio_final = ($precio_redondeado !== null) ? $precio_redondeado : (float)$precio_neto_sumado;

	$req = $bdd->prepare("UPDATE paquetes_colegio SET precio_redondeado = ?, precio_final = ?, updated_at = NOW() WHERE id = ?");
	$req->execute([$precio_redondeado, $precio_final, $id_paquete]);

	echo json_encode(["ok" => true, "precio_final" => $precio_final]);
?>
