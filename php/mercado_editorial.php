<?php
	// Añade títulos al mercado editorial del colegio (formulario "Añadir títulos" de ajax/tab_mercado.php).
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

	$sql = "INSERT INTO mercado_editorial(id_periodo, id_colegio, id_materia, id_grado, id_tipo_libro, editorial, id_libro_eureka,
	                                      libro, precio, tasa_compra, descuento, tipo_acuerdo, vigencia, id_usuario, created_at)
	        VALUES(?, ?, ?, ?, ?, ?, 0, ?, 0, 0, 0, 0, ?, ?, NOW())";
	$query = $bdd->prepare($sql);

	$guardados = 0;
	foreach ($_POST['libro'] ?? [] as $i => $libro) {
		$libro     = trim($libro);
		$editorial = (int)($_POST['editorial'][$i] ?? 0);
		$materia   = (int)($_POST['materia'][$i] ?? 0);
		$grado     = (int)($_POST['grado'][$i] ?? 0);
		if ($libro === '' || !$editorial || !$materia || !$grado) {
			continue;
		}
		$query->execute([
			$periodo['id'], $colegio['id'], $materia, $grado, (int)($_POST['tipo_libro'][$i] ?? 0), $editorial,
			mb_substr($libro, 0, 200), mb_substr(trim($_POST['vigencia'][$i] ?? ''), 0, 50), $_SESSION['id'],
		]);
		$guardados++;
	}

	header('Location: '.$volver.'&msg='.($guardados ? 'mercado_anadido' : 'mercado_vacio'));
