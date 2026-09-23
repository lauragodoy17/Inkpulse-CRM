<?php
	require_once("aut.php");
	require_once('../conexion/bdd.php');
	require_once("registrar_historial.php");
	require_once(__DIR__ . "/../includes/adopcion_cerrada.php");

	$id_colegio = (int)($_POST["id_colegio"] ?? 0);
	$id_periodo = (int)($_POST["periodo"] ?? 0);
	$codigo     = $_POST["codigo"] ?? '';
	$accion     = $_POST["accion"] ?? '';

	if ($_SESSION["tipo"] != 1 || $id_colegio <= 0 || $id_periodo <= 0 || !in_array($accion, ['cerrar', 'abrir'], true)) {
		http_response_code(403);
		die('No autorizado');
	}

	asegurar_columna_cerrado($bdd);

	$nuevo = ($accion === 'cerrar') ? 1 : 0;
	$req = $bdd->prepare("UPDATE recursos SET cerrado = ? WHERE id_colegio = ? AND id_periodo = ? AND cerrado <> ?");
	$req->execute([$nuevo, $id_colegio, $id_periodo, $nuevo]);

	if ($req->rowCount() > 0) {
		registrar_historial($bdd, $id_colegio, intval($_SESSION["id"] ?? 0), 'Adopciones', 'Estado adopción',
			$nuevo ? 'Abierta' : 'Cerrada', $nuevo ? 'Cerrada' : 'Abierta');
	}

	header('Location: ../colegio.php?codigo='.urlencode($codigo).'&periodo='.$id_periodo.'&tab=adopciones');
?>
