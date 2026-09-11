<?php
	require_once("../php/aut.php");
	require_once('../conexion/bdd.php');

	list($materia,$tipo) = explode("/", $_POST["mat_gra"]);

	if ($tipo==1) {
		$sql = "SELECT id,libro,id_grado FROM libros WHERE id_materia='".$materia."' AND id_grado!=50 AND id_grado!=51 AND presupuesto=1 AND (tipo=3 || tipo=4) ORDER BY
	  CASE
	  	WHEN libro LIKE '%GUIA%' THEN 1
	  	WHEN libro LIKE '%TEACHER%' THEN 2
	    WHEN libro LIKE '%Primaria%' THEN 3
	    WHEN libro LIKE '%Bachillerato%' THEN 4
	    ELSE 5
	  END,
	  libro;";
	}elseif($tipo==2){
		$sql = "SELECT id,libro,id_grado FROM libros WHERE id_materia='".$materia."' AND id_grado!=50 AND id_grado!=51 AND presupuesto=1 AND tipo=1 ORDER BY
	  CASE
	    WHEN libro LIKE '%Primaria%' THEN 1
	    WHEN libro LIKE '%Bachillerato%' THEN 2
	    ELSE 3
	  END,
	  libro;";
	}

	$req = $bdd->prepare($sql);
	$req->execute();
	$libros = $req->fetchAll();
	echo"<option value=''>Seleccione</option>";
	foreach($libros as $lib) {;
		echo"<option value=".$lib["id"]." data-grado=".$lib["id_grado"].">".$lib["libro"]."</option>";
	}
?>