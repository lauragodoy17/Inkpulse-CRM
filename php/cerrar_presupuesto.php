<?php
	require_once("aut.php");
	require_once('../conexion/bdd.php');
	require_once("registrar_historial.php");
	require_once(__DIR__ . "/../includes/presupuesto_cerrado.php");

	$id_colegio = (int)($_POST["id_colegio"] ?? 0);
	$id_periodo = (int)($_POST["periodo"] ?? 0);
	$codigo     = $_POST["codigo"] ?? '';
	$accion     = $_POST["accion"] ?? '';

	if (($_SESSION["tipo"] ?? 0) != 1 || $id_colegio <= 0 || $id_periodo <= 0 || !in_array($accion, ['cerrar', 'abrir'], true)) {
		http_response_code(403);
		die('No autorizado');
	}

	$nuevo = ($accion === 'cerrar') ? 1 : 0;
	$antes = presupuesto_cerrado($bdd, $id_colegio, $id_periodo) ? 1 : 0;

	if ($antes !== $nuevo) {
		$req = $bdd->prepare("INSERT INTO presupuestos_cierre (id_colegio, id_periodo, cerrado) VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE cerrado = VALUES(cerrado)");
		$req->execute([$id_colegio, $id_periodo, $nuevo]);

		registrar_historial($bdd, $id_colegio, intval($_SESSION["id"] ?? 0), 'Presupuesto', 'Estado presupuesto',
			$nuevo ? 'Abierto' : 'Cerrado', $nuevo ? 'Cerrado' : 'Abierto');
	}

	header('Location: ../colegio.php?codigo='.urlencode($codigo).'&periodo='.$id_periodo.'&tab=presupuesto');
?>
