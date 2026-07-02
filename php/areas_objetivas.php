<?php 
	require_once("aut.php");
	require_once('../conexion/bdd.php');
	require_once("registrar_historial.php");

	$id_usuario_h = intval($_SESSION["id"] ?? 0);
	
		$sql_periodo="SELECT id FROM periodos WHERE id='".$_POST["periodo"]."'";


		$req_periodo = $bdd->prepare($sql_periodo);
		$req_periodo->execute();
		$gp_periodo = $req_periodo->fetch();

		$libs_2=[];



		foreach ($_POST["libs_ao"] as $libros => $libro) {

			if (empty($libro)) {
        		continue;
   			}

			list($materia,$grado,$lib,$grado_otro) = explode("/", $libro);

			// Saltar si el libro ya existe en este colegio/periodo
			$sql_dup = "SELECT id FROM presupuestos WHERE id_libro='".$lib."' AND id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$gp_periodo["id"]."'";
			$req_dup = $bdd->prepare($sql_dup);
			$req_dup->execute();
			if ($req_dup->rowCount() > 0) continue;

			if ($lib !=0) {

				if ($grado != 17) {

				
					$sql_p="INSERT INTO areas_objetivas(id_periodo,id_colegio,id_materia,id_grado,id_grado_otro,id_libro_eureka,espacio) VALUES('".$gp_periodo["id"]."','".$_POST["id_colegio"]."', '".$materia."', '".$grado."','".$grado_otro."', '".$lib."', '1')";
				

				}else {
				
					do {
			        	$caracteres = "123456789"; //posibles caracteres a usar
			        	$numerodeletras=11; //numero de letras para generar el texto
			        	$cod_area =""; //variable para almacenar la cadena generada
			        	for($i=0;$i<$numerodeletras;$i++)
			         	{
				            $cod_area .=substr($caracteres,rand(0,strlen($caracteres)),1); /*Extraemos 1 caracter de los caracteres 
				            entre el rango 0 a Numero de letras que tiene la cadena */
			         	}
			        	$sql = "SELECT codigo FROM areas_objetivas";

						$req = $bdd->prepare($sql);
						$req->execute();
						$codigos = $req->fetchAll();

				        foreach($codigos as $codigo) {
							if ($cod_area !="") {
								if (($codigo["codigo"]==$cod_area)) $cod_area="";
							}
						}
		   
		      		} while ($cod_area=="");

					$sql_p="INSERT INTO areas_objetivas(codigo,id_periodo,id_colegio,id_materia,id_grado,id_grado_otro,id_libro_eureka,espacio) VALUES('".$cod_area."','".$gp_periodo["id"]."','".$_POST["id_colegio"]."', '".$materia."', '".$grado."','".$grado_otro."', '".$lib."', '".$_POST["espacio"]."')";
				
				}
			

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

				// Historial: log new adopted book
				$req_lib_h = $bdd->prepare("SELECT libro FROM libros WHERE id=:id");
				$req_lib_h->execute([':id' => $lib]);
				$lib_h_row = $req_lib_h->fetch();
				$lib_h_nombre = $lib_h_row ? $lib_h_row['libro'] : "Libro #$lib";
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					'Nuevo libro adoptado', '', $lib_h_nombre);

				if ($grado== 15 || $grado ==16 ) {
					$sq_l2 = "SELECT id FROM libros WHERE pri_sec='".$lib."'";
																
					$req_l2 = $bdd->prepare($sq_l2);
					$req_l2->execute();
					$libros2 = $req_l2->fetchAll();

					foreach ($libros2 as $libro2) {

						$libs_2[]=$libro2["id"];
						

					}

				}
				elseif ($grado == 17) {

					if ($lib !=0) {

						

						$sql_e = "INSERT INTO presupuestos(id_periodo,id_colegio, id_libro,cod_area,  precio, tasa_compra,descuento,pre_aprob,pre_definido, id_usuario, cod_zona, sub_zona, responsable) values ('".$gp_periodo["id"]."','".$_POST["id_colegio"]."','".$lib."','".$cod_area."','0', '0', '0','1','1','".$_POST["promotor"]."', '".$_POST["cod_zona"]."', '".$_POST["sub_zona"]."', '".$_POST["responsable"]."')";
	

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

				else {
					


					$sql_e = "INSERT INTO presupuestos(id_periodo,id_colegio, id_libro, precio, tasa_compra,descuento,pre_aprob,pre_definido,id_usuario, cod_zona, sub_zona, responsable) values ('".$gp_periodo["id"]."','".$_POST["id_colegio"]."','".$lib."','0', '0', '0','1','1','".$_POST["promotor"]."', '".$_POST["cod_zona"]."', '".$_POST["sub_zona"]."', '".$_POST["responsable"]."')";

			

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


		}

		if(!empty($libs_2)) {

			$libs_2=array_unique($libs_2);

			foreach ($libs_2 as $lib2) {

		
				$sql_e = "INSERT INTO presupuestos(id_periodo,id_colegio, id_libro, precio, tasa_compra,descuento,pre_aprob,pre_definido,id_usuario, cod_zona, sub_zona, responsable) values ('".$gp_periodo["id"]."','".$_POST["id_colegio"]."','".$lib2."','0', '0', '0','1','1','".$_POST["promotor"]."', '".$_POST["cod_zona"]."', '".$_POST["sub_zona"]."', '".$_POST["responsable"]."')";

				

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
		


	
	header('Location: ../colegio.php?codigo='.$_POST["cod_colegio"].'&periodo='.$_POST["periodo"].'&tab=presupuesto');
	
?>