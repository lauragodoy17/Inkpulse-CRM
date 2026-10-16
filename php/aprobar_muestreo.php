<?php
	require_once("../php/aut.php");
	require_once('../conexion/bdd.php');
	require_once('../includes/historial_estados.php');
	crear_tabla_historial_estados($bdd);

	$id_muestreo = intval($_POST["id_muestreo"] ?? 0);

	// libro_m[] = "id de libros_muestreos/cantidad aprobada" (lo arma muestreo_colegio.php al enviar).
	// Solo libros de ESTE muestreo.
	$upd = $bdd->prepare("UPDATE libros_muestreos lm JOIN muestreos m ON m.codigo = lm.cod_muestreo
		SET lm.cantidad_aprob = ? WHERE lm.id = ? AND m.id = ?");
	foreach ($_POST["libro_m"] ?? [] as $libro) {
		$partes = explode("/", (string)$libro, 2);
		$id_libro = intval($partes[0] ?? 0);
		$cantidad = trim($partes[1] ?? '');
		if ($id_libro <= 0 || $cantidad === '') continue;
		$upd->execute([intval($cantidad), $id_libro, $id_muestreo]);
	}

	$req = $bdd->prepare("UPDATE muestreos SET estado = '2', observaciones = ? WHERE id = ?");
	$req->execute([$_POST["observaciones"] ?? '', $id_muestreo]);
	registrar_historial_estado($bdd, 'muestreos', $id_muestreo, 2, intval($_SESSION['id'] ?? 0));


	header("location: ../lista_muestreo.php?tp=2&ink_status=ok&ink_msg=".urlencode('Muestreo aprobado correctamente.'));

?>
