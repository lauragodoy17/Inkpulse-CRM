<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");

	foreach ($_POST['b_presup'] as $presup) {

		$req_fila = $bdd->prepare("SELECT id_periodo, id_colegio, id_libro, cod_area FROM presupuestos WHERE id=?");
		$req_fila->execute([$presup]);
		$fila = $req_fila->fetch();

		$sql_eliminar="DELETE FROM presupuestos WHERE id='".$presup."'";

		$req_eliminar = $bdd->prepare($sql_eliminar);
		$req_eliminar->execute();

		// Libera el libro en areas_objetivas para que pueda volver a agregarse en el mismo periodo
		if ($fila) {
			if (!empty($fila["cod_area"]) && $fila["cod_area"] != '0') {
				$req_ao = $bdd->prepare("DELETE FROM areas_objetivas WHERE codigo=? AND id_periodo=? AND id_colegio=?");
				$req_ao->execute([$fila["cod_area"], $fila["id_periodo"], $fila["id_colegio"]]);
			} else {
				$req_ao = $bdd->prepare("DELETE FROM areas_objetivas WHERE id_libro_eureka=? AND id_periodo=? AND id_colegio=?");
				$req_ao->execute([$fila["id_libro"], $fila["id_periodo"], $fila["id_colegio"]]);
			}
		}
	}
		

	header('Location: ../colegio.php?codigo='.$_POST["codigo"].'&periodo='.$_POST["periodo"].'&tab=presupuesto');
?>
