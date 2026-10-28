<?php
	// Elimina un título del mercado editorial del colegio (botón de papelera en ajax/tab_mercado.php).
	require_once("aut.php");
	require_once('../conexion/bdd.php');
	require_once('../includes/mercado_editorial_datos.php');

	$colegio = mercado_colegio($bdd, (int)($_POST['id_colegio'] ?? 0));
	$periodo = mercado_periodo($bdd, (int)($_POST['periodo'] ?? 0));
	if (!$colegio || !$periodo) {
		header('Location: ../index.php');
		exit;
	}
	$volver = '../colegio.php?codigo='.urlencode($colegio['codigo']).'&periodo='.$periodo['id'].'&tab=mercado';

	if (!mercado_puede_editar($colegio)) {
		header('Location: '.$volver.'&msg=mercado_sin_permiso');
		exit;
	}

	$req = $bdd->prepare("DELETE FROM mercado_editorial WHERE id=? AND id_colegio=? AND id_periodo=?");
	$req->execute([(int)($_POST['id'] ?? 0), $colegio['id'], $periodo['id']]);

	header('Location: '.$volver.'&msg=mercado_eliminado');
