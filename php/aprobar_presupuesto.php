<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once(__DIR__ . "/../includes/presupuesto_cerrado.php");

	if (presupuesto_cerrado($bdd, $_POST["id_colegio"] ?? 0, $_POST["periodo"] ?? 0)) {
		header('Location: ../colegio.php?codigo='.urlencode($_POST["codigo"] ?? '').'&periodo='.intval($_POST["periodo"] ?? 0).'&tab=presupuesto');
		exit;
	}


	$sql_fcole = "SELECT MAX(fila_zona) as fila_zona FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

	$req_fcole = $bdd->prepare($sql_fcole);
	$req_fcole->execute();
	$fcole = $req_fcole->fetch();

	if ($fcole["fila_zona"] > 0) {

		$fila_zona= $fcole["fila_zona"];

	}else {


		$sql_zona = "SELECT cod_zona FROM colegios WHERE id='".$_POST["id_colegio"]."'";

		$req_zona = $bdd->prepare($sql_zona);
		$req_zona->execute();
		$zona = $req_zona->fetch();


		$sql = "SELECT MAX(fila_zona) as fila_zona FROM presupuestos p JOIN colegios c ON p.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona WHERE p.id_periodo='".$_POST["periodo"]."' AND z.codigo='".$zona["cod_zona"]."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$con_fila_zona = $req->fetch();

		if ($con_fila_zona["fila_zona"] > 0) {

			$fila_zona=$con_fila_zona["fila_zona"] + 1;
		}
		else {

			$fila_zona=2;
		}

	}

	$sql_fcole = "SELECT MAX(fila) as fila FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

	$req_fcole = $bdd->prepare($sql_fcole);
	$req_fcole->execute();
	$fcole = $req_fcole->fetch();

	if ($fcole["fila"] > 0) {

		$fila= $fcole["fila"];

	}else {

		$sql = "SELECT MAX(fila) as fila FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$con_fila = $req->fetch();

		if ($con_fila["fila"] > 0) {

			$fila=$con_fila["fila"] + 1;
		}
		else {

			$fila=2;
		}

	}

	

	foreach ($_POST["presupuesto_p"] as $presups => $presup) {

		list($libro,$tasa_c,$descuento, $precio, $probab) = explode("/", $presup);

		$sql = "SELECT columna FROM libros WHERE id='".$presup."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$con_colum = $req->fetch();	
		if ($tasa_c=="") {

			$sql_cod = "SELECT g.id_grado, p.cod_area FROM presupuestos p JOIN libros g ON g.id=p.id_libro WHERE p.id_libro='".$presup."'";
			$req_cod = $bdd->prepare($sql_cod);
			$req_cod->execute();

			$row_cod = $req_cod->fetch();

			if ($row_cod["id_grado"] != 17) {

				$sql_e = "UPDATE presupuestos SET fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$presup."'";
			}else{

				$sql_e = "UPDATE presupuestos SET fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["cod_area"]."'";

			}

		}else {


			$sql_cod = "SELECT g.id_grado, p.cod_area FROM presupuestos p JOIN libros g ON g.id=p.id_libro WHERE p.id_libro='".$libro."'";
			$req_cod = $bdd->prepare($sql_cod);
			$req_cod->execute();
			
			$row_cod = $req_cod->fetch();
			echo $libro;

			if ($row_cod["id_grado"] != 17) {

			$sql_e = "UPDATE presupuestos SET fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND (id_libro='".$libro."' OR cod_area='".$row_cod["cod_area"]."')";

			}else{

				$sql_e = "UPDATE presupuestos SET fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["cod_area"]."'";
			}
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

	
		
				
	}

	foreach ($_POST["aprobar"] as $aprobados => $aprobado) {

		$sql_e = "UPDATE presupuestos SET aprobado='1', pre_definido='1' WHERE id='".$aprobado."'";

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

	/*$sql = "INSERT INTO notificaciones(id_periodo,id_colegio,id_tipo_notifi,visible) VALUES('".$_POST["periodo"]."','".$_POST["id_colegio"]."','2','1')";

		$query = $bdd->prepare( $sql );
		if ($query == false) {
			print_r($bdd->errorInfo());
			die ('Erreur prepare');
		}

		$sth = $query->execute();
		if ($sth == false) {
			print_r($query->errorInfo());
			die ('Erreur execute');
		}

	$sql = "UPDATE notificaciones SET visible='0' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_tipo_notifi='1'";

		$query = $bdd->prepare( $sql );
		if ($query == false) {
			print_r($bdd->errorInfo());
			die ('Erreur prepare');
		}

		$sth = $query->execute();
		if ($sth == false) {
			print_r($query->errorInfo());
			die ('Erreur execute');
		}
	*/

	$sql_e = "UPDATE colegios_status SET id_status='2' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

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

	header('Location: ../colegio.php?codigo='.$_POST["codigo"].'&periodo='.$_POST["periodo"].'&tab=presupuesto');


?>