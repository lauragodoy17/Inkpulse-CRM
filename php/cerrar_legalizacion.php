<?php

	require_once("aut.php");
	require_once('../conexion/bdd.php');

	// Agregar columna si no existe
	try {
		$bdd->exec("ALTER TABLE solicitudes_recursos ADD COLUMN legal_cerrado TINYINT(1) NOT NULL DEFAULT 0");
	} catch (Exception $e) {}

	$id = (int)($_GET["solicitud"] ?? $_POST["solicitud"] ?? 0);
	$stmt = $bdd->prepare("UPDATE solicitudes_recursos SET legal_cerrado = 1 WHERE id = ?");
	$stmt->execute([$id]);

	header('Location: ../vista_solicitud.php?id='.$id);

?>
