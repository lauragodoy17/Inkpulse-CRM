<?php
	// Guarda tasa de compra, PVP y descuento de los títulos del mercado editorial (tabla de ajax/tab_mercado.php).
	// Los porcentajes llegan en % y se guardan como fracción.
	require_once("aut.php");
	include("../conexion/bdd.php");
	require_once('../includes/mercado_editorial_datos.php');

	$colegio = mercado_colegio($bdd, (int)($_POST['id_colegio'] ?? 0));
	$periodo = mercado_periodo($bdd, (int)($_POST['periodo'] ?? 0));
	if (!$colegio || !$periodo) {
		header('Location: ../index.php');
		exit;
	}
	$volver = '../colegio.php?codigo='.urlencode($colegio['codigo']).'&periodo='.$periodo['id'].'&tab=mercado';

	if (!mercado_puede_editar($colegio) || !mercado_periodo_abierto($periodo)) {
		header('Location: '.$volver.'&msg=mercado_sin_permiso');
		exit;
	}

	$num = fn($v) => max(0, (float)str_replace(',', '.', (string)$v));

	$query = $bdd->prepare("UPDATE mercado_editorial SET precio=?, tasa_compra=?, descuento=?, updated_at=NOW()
	                        WHERE id=? AND id_colegio=? AND id_periodo=?");
	foreach ($_POST['items'] ?? [] as $id => $item) {
		$query->execute([
			round($num($item['precio'] ?? 0)),
			min(100, $num($item['tasa'] ?? 0)) / 100,
			min(100, $num($item['descuento'] ?? 0)) / 100,
			(int)$id, $colegio['id'], $periodo['id'],
		]);
	}

	header('Location: '.$volver.'&msg=mercado_guardado');
