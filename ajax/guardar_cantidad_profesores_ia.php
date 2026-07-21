<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");

	$id_colegio = intval($_POST['colegio'] ?? 0);
	$id_periodo = intval($_POST['periodo'] ?? 0);
	$cantidad   = intval($_POST['cantidad'] ?? -1);

	if ($id_colegio <= 0 || $id_periodo <= 0 || $cantidad < 0) {
		http_response_code(400);
		echo json_encode(["ok" => false]);
		exit;
	}

	try {
		$bdd->exec("CREATE TABLE IF NOT EXISTS ia_profesores_colegio (
			id INT AUTO_INCREMENT PRIMARY KEY,
			id_colegio INT NOT NULL,
			id_periodo INT NOT NULL,
			cantidad_profesores INT NOT NULL,
			UNIQUE KEY uniq_colegio_periodo (id_colegio, id_periodo)
		)");
	} catch (Exception $e) {}

	$sql = "INSERT INTO ia_profesores_colegio (id_colegio, id_periodo, cantidad_profesores) VALUES (?, ?, ?)
	        ON DUPLICATE KEY UPDATE cantidad_profesores = VALUES(cantidad_profesores)";
	$req = $bdd->prepare($sql);
	$req->execute([$id_colegio, $id_periodo, $cantidad]);

	echo json_encode(["ok" => true]);
?>
