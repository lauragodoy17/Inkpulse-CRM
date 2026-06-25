<?php

	require_once("aut.php");
	require_once('../conexion/bdd.php');

	// Crear tabla de trazabilidad si no existe
	$bdd->exec("CREATE TABLE IF NOT EXISTS trazabilidad_entregas (
		id            INT AUTO_INCREMENT PRIMARY KEY,
		id_solicitud  INT NOT NULL,
		id_recurso    INT NOT NULL,
		tipo          VARCHAR(20) NOT NULL,
		valor         DECIMAL(15,0) NOT NULL DEFAULT 0,
		fecha         DATE,
		fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
		id_usuario    INT
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	if ($_GET['tipo'] == 1) {

		// Modificar presupuesto (estado 1)
		foreach ($_POST["i_presup"] as $legaliza) {
			list($recurso, $v_legal) = explode("/", $legaliza);
			if ($recurso != 0) {
				$sql_p = "UPDATE recursos_solicitados SET presupuesto = ? WHERE id = ?";
				$query_p = $bdd->prepare($sql_p);
				$query_p->execute([$v_legal, $recurso]);
			}
		}

	} else {

		// Registrar legalización parcial (acumulativa)
		foreach ($_POST["i_legaliza"] as $legaliza) {
			$parts   = explode("|", $legaliza);
			$recurso = $parts[0] ?? '';
			$v_legal = (int)($parts[1] ?? 0);
			$v_fecha = $parts[2] ?? '';

			if ($recurso != 0 && $v_legal > 0) {

				// Sumar al total legalizado (no reemplazar)
				$sql_p = "UPDATE recursos_solicitados SET legaliza = legaliza + ? WHERE id = ?";
				$query_p = $bdd->prepare($sql_p);
				$query_p->execute([$v_legal, $recurso]);

				// Registrar en trazabilidad
				$sql_h = "INSERT INTO trazabilidad_entregas
				          (id_solicitud, id_recurso, tipo, valor, fecha, id_usuario)
				          VALUES (?, ?, 'legalizacion', ?, ?, ?)";
				$query_h = $bdd->prepare($sql_h);
				$query_h->execute([
					$_POST["solicitud"],
					$recurso,
					$v_legal,
					$v_fecha ?: date('Y-m-d'),
					$_SESSION["id"]
				]);
			}
		}

		// Nueva entrega registrada junto con la legalización
		if (!empty($_POST["i_entrega"])) {
			foreach ($_POST["i_entrega"] as $entrega) {
				$parts     = explode("|", $entrega);
				$recurso   = $parts[0] ?? '';
				$v_tipo_e  = $parts[1] ?? '';
				$v_valor_e = (int)($parts[2] ?? 0);
				$v_fecha_e = $parts[3] ?? '';

				if ($recurso != 0 && $v_valor_e > 0) {
					$sql_p = "UPDATE recursos_solicitados SET tipo_e = ?, valor_e = valor_e + ?, fecha_e = ? WHERE id = ?";
					$query_p = $bdd->prepare($sql_p);
					$query_p->execute([$v_tipo_e, $v_valor_e, $v_fecha_e, $recurso]);

					$sql_h = "INSERT INTO trazabilidad_entregas
					          (id_solicitud, id_recurso, tipo, valor, fecha, id_usuario)
					          VALUES (?, ?, 'entrega', ?, ?, ?)";
					$query_h = $bdd->prepare($sql_h);
					$query_h->execute([
						$_POST["solicitud"],
						$recurso,
						$v_valor_e,
						$v_fecha_e ?: date('Y-m-d'),
						$_SESSION["id"]
					]);
				}
			}
		}

	}

	header('Location: ../vista_solicitud.php?id='.$_POST["solicitud"].'');

?>
