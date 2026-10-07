<?php
	
	include("../conexion/bdd.php");

	// Devoluciones registradas desde lo despachado en World Office (devoluciones_muestras.php): los
	// libros y cantidades no se cambian aquí, para no saltarse el tope "despachado − ya devuelto"
	// (pedido por el usuario 2026-10-05). Solo se actualizan los datos generales de más abajo.
	$vinculada_muestreo = false;
	if (($_POST["tipo"] ?? "") == 1) {
		try {
			$req_v = $bdd->prepare("SELECT id_muestreo FROM devoluciones WHERE codigo = ?");
			$req_v->execute([$_POST['codigo'] ?? '']);
			$vinculada_muestreo = (int)$req_v->fetchColumn() > 0;
			if ($vinculada_muestreo) {
				// El cliente es el del muestreo y no se cambia (pedido por el usuario 2026-10-05).
				$req_p = $bdd->prepare("SELECT persona FROM devoluciones WHERE codigo = ?");
				$req_p->execute([$_POST['codigo'] ?? '']);
				$_POST['persona'] = (int)$req_p->fetchColumn();
			}
		} catch (Exception $e) { /* columna aún no creada: devolución del formulario anterior */ }
	}

	if (!$vinculada_muestreo) {

	$sql = "SELECT id FROM libros_devol WHERE cod_pedido='".$_POST['codigo']."'";

	$req = $bdd->prepare($sql);
	$req->execute();
	$libs= $req->fetchAll();

	foreach($libs as $lib) {

		$libsp[]=$lib["id"];
	}

	$resultados = array_diff($libsp, $_POST['lpid']);

	foreach($resultados as $resultado) {

		$sql = "DELETE FROM `libros_devol` WHERE id='".$resultado."'";

		$req = $bdd->prepare($sql);
		$req->execute();

	}

	foreach ($_POST["libro_e"] as $libros => $libro) {

		list($id_libro,$cantidad) = array_pad(explode("/", $libro), 2, '');
			
		if ($id_libro !='') {
			
			$sql_p = "INSERT INTO libros_devol(cod_pedido,id_libro,cantidad) VALUES('".$_POST['codigo']."','".$id_libro."','".$cantidad."')";
				
				
			$query_p = $bdd->prepare( $sql_p );
			if ($query_p == false) {
				print_r($bdd->errorInfo());
				die ('Erreur prepare');
			}
			$sth_p = $query_p->execute();
			if ($sth_p == false) {
				print_r($query_p->errorInfo());
				die ('Erreur execute');
			}

		}
		

	}

	foreach ($_POST["lib_p"] as $lib_p) {
		
		list($cant,$lib,$desc) =explode("/", $lib_p);

		$sql_e = "UPDATE libros_devol SET cantidad='".$cant."' WHERE id='".$lib."'";

		$query_e = $bdd->prepare( $sql_e );
		if ($query_e == false) {
			print_r($bdd->errorInfo());
			die ('Erreur prepare');
		}
		$sth_e = $query_e->execute();
		if ($sth_e == false) {
			print_r($query_e->errorInfo());
			die ('Erreur execute');
		}

	}

	}

	if ($_POST["tipo"]==1) {
		$sql_e = "UPDATE devoluciones SET persona='".$_POST['persona']."', observaciones='".$_POST['observaciones']."' WHERE codigo='".$_POST['codigo']."'";
	}else{
		$sql_e = "UPDATE devoluciones_prov SET persona='".$_POST['persona']."', observaciones='".$_POST['observaciones']."' WHERE codigo='".$_POST['codigo']."'";
	}
	

	$query_e = $bdd->prepare( $sql_e );
	if ($query_e == false) {
		print_r($bdd->errorInfo());
		die ('Erreur prepare');
	}
	$sth_e = $query_e->execute();
	if ($sth_e == false) {
		print_r($query_e->errorInfo());
		die ('Erreur execute');
	}

	

	header('Location: ../vista_devol.php?id_devol='.$_POST["pedido"].'&tipo='.$_POST["tipo"].'');


	

?>