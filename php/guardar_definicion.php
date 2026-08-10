<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once("registrar_historial.php");

	$id_usuario_h = intval($_SESSION["id"] ?? 0);

	$sql_y = "SELECT 
			    CASE 
			        WHEN p.id_calendario = 1 THEN RIGHT(p.periodo, 2)
			        ELSE RIGHT(p.periodo, 3)
			    END AS ultimos
			FROM periodos p JOIN calendarios c ON c.id=p.id_calendario WHERE p.id='".$_POST["periodo"]."'";
	$req_y = $bdd->prepare($sql_y);
	$req_y->execute();
	$row_y = $req_y->fetch();

	$year=$row_y["ultimos"];
	foreach ($_POST["presupuesto_d"] as $presups => $presup) {

		if ($presup == "") continue;

		$parts = explode("/", $presup);
		if (count($parts) < 5) continue;

		list($libro,$tasa_c,$descuento, $precio,$precio_padre) = $parts;

		

		if ($tasa_c=="") {

			$sql_cod = "SELECT p.id_libro, g.id_grado_otro,g.codigo FROM presupuestos p JOIN areas_objetivas g ON g.id_libro_eureka=p.id_libro WHERE g.codigo='".$libro."'";
			$req_cod = $bdd->prepare($sql_cod);
			$req_cod->execute();

			$row_cod = $req_cod->fetch();

			if ($row_cod["id_grado_otro"] == 0) {

				$sql2 = "SELECT id,tasa_compra,descuento,tasa_compra_d,descuento_d FROM presupuestos WHERE id='".$libro."' AND id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

			}else{

				$sql2 = "SELECT id,tasa_compra,descuento,tasa_compra_d,descuento_d FROM presupuestos WHERE cod_area='".$row_cod["codigo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

			}
			$req2 = $bdd->prepare($sql2);
			$req2->execute();
			$row2 = $req2->fetch();	

			if ($row2["tasa_compra_d"]==0.00) {
		
				if ($row_cod["id_grado_otro"] == 0) {

					$sql_e = "UPDATE presupuestos SET tasa_compra_d='".$row2["tasa_compra"]."', descuento_d='".$row2["descuento"]."' WHERE id='".$row2["id"]."'";

				}else{

					$sql_e = "UPDATE presupuestos SET tasa_compra_d='".$row2["tasa_compra"]."', descuento_d='".$row2["descuento"]."' WHERE cod_area='".$row_cod["codigo"]."'";
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

		

		}else {

			$sql_cod = "SELECT p.id_libro, g.id_grado_otro,g.codigo FROM presupuestos p JOIN areas_objetivas g ON g.id_libro_eureka=p.id_libro WHERE g.codigo='".$libro."'";
			$req_cod = $bdd->prepare($sql_cod);
			$req_cod->execute();

			$row_cod = $req_cod->fetch();

			// Fetch old values and book name for historial
			if ($row_cod["id_grado_otro"] == 0) {
				$req_old_d = $bdd->prepare("SELECT precio, precio_venta_final, tasa_compra_d, descuento_d FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'");
				$req_lib_d = $bdd->prepare("SELECT libro FROM libros WHERE id=:id");
				$req_lib_d->execute([':id' => $libro]);
			} else {
				$req_old_d = $bdd->prepare("SELECT precio, precio_venta_final, tasa_compra_d, descuento_d FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["codigo"]."'");
				$req_lib_d = $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id=a.id_libro_eureka WHERE a.codigo='".$libro."'");
				$req_lib_d->execute();
			}
			$req_old_d->execute();
			$old_d = $req_old_d->fetch();
			if (isset($req_lib_d) && $row_cod["id_grado_otro"] == 0) $req_lib_d->execute([':id' => $libro]);
			$lib_d_row = isset($req_lib_d) ? $req_lib_d->fetch() : false;
			$lib_d_nombre = $lib_d_row ? $lib_d_row['libro'] : "Libro $libro";

			if ($row_cod["id_grado_otro"] == 0) {
				$sql_e = "UPDATE presupuestos SET  tasa_compra_d='".$tasa_c."',descuento_d='".$descuento."', precio='".$precio."', precio_venta_final='".$precio_padre."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'";

			}else{

				$sql_e = "UPDATE presupuestos SET  tasa_compra_d='".$tasa_c."',descuento_d='".$descuento."', precio='".$precio."', precio_venta_final='".$precio_padre."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["codigo"]."'";
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

			// Historial
			if ($old_d) {
				if (abs((float)($old_d['tasa_compra_d'] ?? 0) - (float)$tasa_c) > 0.0001) {
					$tc_old_pct = round((float)($old_d['tasa_compra_d'] ?? 0) * 100, 2) . '%';
					$tc_new_pct = round((float)$tasa_c * 100, 2) . '%';
					registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
						"Tasa de compra - $lib_d_nombre", $tc_old_pct, $tc_new_pct);
				}
				if (abs((float)($old_d['descuento_d'] ?? 0) - (float)$descuento) > 0.0001) {
					$desc_old_pct = round((float)($old_d['descuento_d'] ?? 0) * 100, 2) . '%';
					$desc_new_pct = round((float)$descuento * 100, 2) . '%';
					registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
						"Descuento - $lib_d_nombre", $desc_old_pct, $desc_new_pct);
				}
				if (abs((float)($old_d['precio_venta_final'] ?? 0) - (float)$precio_padre) > 0.0001) {
					registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
						"Precio venta padre - $lib_d_nombre", (string)$old_d['precio_venta_final'], (string)$precio_padre);
				}
			}
		}
		

			

	
		
				
	}

	// Manejo de los archivos del acuerdo de adopción (máx. 3, solo para tipos 1, 3, 10).
	// El input es múltiple (archivo_adopcion[]); si llegan archivos nuevos reemplazan por
	// completo el set anterior (igual que el archivo único reemplazaba al suyo), en vez de
	// acumularse sobre los ya guardados.
	$archivos_subidos = ['', '', ''];
	if (in_array($_SESSION['tipo'], [1, 3, 10]) && isset($_FILES['archivo_adopcion']) && is_array($_FILES['archivo_adopcion']['name'])) {
		$uploads_dir = dirname(__DIR__) . '/uploads/adopciones/';
		if (!is_dir($uploads_dir)) {
			mkdir($uploads_dir, 0755, true);
		}
		$total_subidos = min(count($_FILES['archivo_adopcion']['name']), 3);
		for ($i = 0; $i < $total_subidos; $i++) {
			if ($_FILES['archivo_adopcion']['error'][$i] !== UPLOAD_ERR_OK) continue;
			$ext      = strtolower(pathinfo($_FILES['archivo_adopcion']['name'][$i], PATHINFO_EXTENSION));
			$filename = 'adop_' . intval($_POST['id_colegio']) . '_' . intval($_POST['periodo']) . '_' . time() . '_' . $i . '.' . $ext;
			if (move_uploaded_file($_FILES['archivo_adopcion']['tmp_name'][$i], $uploads_dir . $filename)) {
				$archivos_subidos[$i] = 'uploads/adopciones/' . $filename;
			}
		}
	}
	$hay_archivos_nuevos = ($archivos_subidos[0] !== '' || $archivos_subidos[1] !== '' || $archivos_subidos[2] !== '');

	$sql_rec = "SELECT id FROM recursos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

	$req_rec = $bdd->prepare($sql_rec);
	$req_rec->execute();
	$num = $req_rec->rowCount();

	// El formulario de Adopciones ya no tiene campos recurso/valor_recurso/reintegro/
	// valor_reintegro/descripcion/venta_real (se quitaron de la UI), pero la tabla recursos
	// sigue teniendo esas columnas NOT NULL; se completan con un valor por defecto para no
	// depender de que $_POST los traiga.
	$recurso           = $_POST['recurso'] ?? '';
	$valor_recurso     = $_POST['valor_recurso'] ?? 0;
	$reintegro         = $_POST['reintegro'] ?? '';
	$valor_reintegro   = $_POST['valor_reintegro'] ?? 0;
	$descripcion_canal = $_POST['descripcion'] ?? '';
	$venta_real        = $_POST['venta_real'] ?? 0;
	$cliente_adop      = $_POST['cliente'] ?? 0;

	if ($num < 1 ) {

		$sql_e = "INSERT INTO recursos(id_periodo,id_colegio,recurso,valor_recurso, reintegro, valor_reintegro,id_canal,descripcion_canal,venta_real,fecha,observaciones,archivo,archivo2,archivo3,cliente) VALUES ('".$_POST["periodo"]."', '".$_POST["id_colegio"]."', '".$recurso."','".$valor_recurso."', '".$reintegro."','".$valor_reintegro."','".$_POST["canal"]."','".$descripcion_canal."','".$venta_real."','".date("Y-m-d")."','".$_POST["observaciones"]."','".$archivos_subidos[0]."','".$archivos_subidos[1]."','".$archivos_subidos[2]."','".$cliente_adop."')";

	}else {

		$arch_set = $hay_archivos_nuevos ? ", archivo='".$archivos_subidos[0]."', archivo2='".$archivos_subidos[1]."', archivo3='".$archivos_subidos[2]."'" : '';
		$sql_e = "UPDATE recursos SET recurso='".$recurso."', valor_recurso='".$valor_recurso."', reintegro='".$reintegro."', valor_reintegro='".$valor_reintegro."', id_canal='".$_POST["canal"]."', descripcion_canal='".$descripcion_canal."',venta_real='".$venta_real."' , fecha='".date("Y-m-d")."', observaciones='".$_POST["observaciones"]."', cliente='".$cliente_adop."'".$arch_set." WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";
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


	foreach($_POST["presup_profes"] as $presup_p) {

		$sql_e = "DELETE FROM presup_profes WHERE id_presup='".$presup_p."'";

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

	

	foreach($_POST["presup_profes"] as $presup_p) {

		// No todos los libros tienen profesores seleccionados; sin isset() acceder a una
		// clave "profesXXXX" inexistente lanza un warning y rompe el foreach (null).
		foreach(($_POST["profes".$presup_p.""] ?? []) as $profe) {



			$sql_e = "INSERT INTO presup_profes(id_presup,id_profe) VALUES ('".$presup_p."','".$profe."')";

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

		

	
		
	$sql_nconse = "SELECT conse FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

	$req_nconse = $bdd->prepare($sql_nconse);
	$req_nconse->execute();
	$nconse = $req_nconse->fetch();

	if ($nconse["conse"] ==0 ) {

		$sql_fcole = "SELECT MAX(conse+1) as conse FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."'";

		$req_fcole = $bdd->prepare($sql_fcole);
		$req_fcole->execute();
		$conse = $req_fcole->fetch();
	}

	
	//Busco los que ya estan definidos en ese colegio
	$sqld = "SELECT id FROM presupuestos WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."' AND definido='1'";
	$reqd = $bdd->prepare($sqld);
	$reqd->execute();
	$s_defs = $reqd->fetchAll();

	foreach($s_defs as $s_def) {
		$defs[]=$s_def["id"];

	}
		
	if (isset($_POST["definir"]) ) {
		
		foreach ($_POST["definir"] as $definiciones => $definir) {

			if ($definir == "") continue;
			$parts_def = explode("/", $definir);
			if (count($parts_def) < 2) continue;
			list($libro,$id_presupuesto) = $parts_def;
			
			//almaceno en un array los que se marcaron como definidos
			$defs2[]=$id_presupuesto;

			// Historial: nuevo checkbox marcado como adoptado
			if (!in_array($id_presupuesto, $defs ?? [])) {
				$req_lib_def = $bdd->prepare("SELECT l.libro FROM libros l JOIN presupuestos p ON l.id=p.id_libro WHERE p.id=:id");
				$req_lib_def->execute([':id' => $id_presupuesto]);
				$lib_def_row = $req_lib_def->fetch();
				$lib_def_nombre = $lib_def_row ? $lib_def_row['libro'] : "Presupuesto #$id_presupuesto";
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					'Libro adoptado', '', $lib_def_nombre);
			}

			$sql2 = "SELECT aprobado FROM presupuestos WHERE id='".$id_presupuesto."'";
			$req2 = $bdd->prepare($sql2);
			$req2->execute();
			$row2 = $req2->fetch();	

			if ($row2["aprobado"]==1) {

				if ($nconse["conse"] ==0 ) {

					$sql_e = "UPDATE presupuestos SET definido='1' WHERE id='".$id_presupuesto."'";


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

					
					$sql_c = "UPDATE presupuestos SET conse='".$conse["conse"]."', year='".$year."' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

					$query_c = $bdd->prepare( $sql_c );
						if ($query_c == false) {
						print_r($bdd->errorInfo());
						die ('Erreur prepare');
					}
					$sth_c = $query_c->execute();
					if ($sth_c == false) {
						print_r($query_c->errorInfo());
						die ('Erreur execute');
					}

				}else{
					$sql_e = "UPDATE presupuestos SET definido='1' WHERE id='".$id_presupuesto."'";

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

					$sql_c = "UPDATE presupuestos SET conse='".$nconse["conse"]."', year='".$year."' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

					$query_c = $bdd->prepare( $sql_c );
						if ($query_c == false) {
						print_r($bdd->errorInfo());
						die ('Erreur prepare');
					}
					$sth_c = $query_c->execute();
					if ($sth_c == false) {
						print_r($query_c->errorInfo());
						die ('Erreur execute');
					}
				}

					

				
			}else{

				if ($nconse["conse"] ==0 ) {

					$sql_e = "UPDATE presupuestos SET definido='1' WHERE id='".$id_presupuesto."'";

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

					$sql_c = "UPDATE presupuestos SET conse='".$conse["conse"]."', year='".$year."' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

					$query_c = $bdd->prepare( $sql_c );
						if ($query_c == false) {
						print_r($bdd->errorInfo());
						die ('Erreur prepare');
					}
					$sth_c = $query_c->execute();
					if ($sth_c == false) {
						print_r($query_c->errorInfo());
						die ('Erreur execute');
					}

				}else{
						
					$sql_e = "UPDATE presupuestos SET definido='1' WHERE id='".$id_presupuesto."'";

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

					$sql_c = "UPDATE presupuestos SET conse='".$nconse["conse"]."', year='".$year."' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

					$query_c = $bdd->prepare( $sql_c );
						if ($query_c == false) {
						print_r($bdd->errorInfo());
						die ('Erreur prepare');
					}
					$sth_c = $query_c->execute();
					if ($sth_c == false) {
						print_r($query_c->errorInfo());
						die ('Erreur execute');
					}
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

		foreach ($defs as $d => $valor) {
			//Buscos los que estan definidos en el colegio en el arreglo de los que se marcaron como definidos
			if (!in_array($valor, $defs2)) {


				$sql_e = "UPDATE presupuestos SET definido='0' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."' AND id='".$valor."'";

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

				// Historial: adopción eliminada
				$req_lib_rem = $bdd->prepare("SELECT l.libro FROM libros l JOIN presupuestos p ON l.id=p.id_libro WHERE p.id=:id");
				$req_lib_rem->execute([':id' => $valor]);
				$lib_rem_row = $req_lib_rem->fetch();
				$lib_rem_nombre = $lib_rem_row ? $lib_rem_row['libro'] : "Libro presupuesto #$valor";
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					'Adopción eliminada', $lib_rem_nombre, '');
			}

		}

		$sql_e = "UPDATE colegios_status SET id_status='1' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

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

	}else{
		// Log each currently-defined book as removed before setting all to 0
		if (!empty($defs)) {
			$req_lib_all = $bdd->prepare("SELECT l.libro FROM libros l JOIN presupuestos p ON l.id=p.id_libro WHERE p.id=:id");
			foreach ($defs as $def_id) {
				$req_lib_all->execute([':id' => $def_id]);
				$lib_all_row = $req_lib_all->fetch();
				$lib_all_nombre = $lib_all_row ? $lib_all_row['libro'] : "Libro presupuesto #$def_id";
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					'Adopción eliminada', $lib_all_nombre, '');
			}
		}

		$sql_e = "UPDATE presupuestos SET definido='0' WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

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
		

	$sql_z = "INSERT INTO notificaciones (id_periodo,id_colegio,id_usuario,id_tipo_notifi,usuario_respuesta,visible) VALUES ('".$_POST["periodo"]."','".$_POST["id_colegio"]."','0', '1', '0', '1')";
		
	$query_z = $bdd->prepare( $sql_z );
	if ($query_z == false) {
		print_r($bdd->errorInfo());
		die ('Erreur prepare');
	}
	$sth_z = $query_z->execute();
	if ($sth_z == false) {
		print_r($query_z->errorInfo());
		die ('Erreur execute');
	}


	foreach ($_POST["v_uni_vr"] as $v_uni_vrs => $v_uni_vr) {

		if ($v_uni_vr == "") continue;
		$parts_vr = explode("/", $v_uni_vr);
		if (count($parts_vr) < 2) continue;
		list($libro,$uni_vr) = $parts_vr;

		
		if ($libro!="") {

			$sql_cod = "SELECT p.id_libro, g.id_grado_otro,g.codigo FROM presupuestos p JOIN areas_objetivas g ON g.id_libro_eureka=p.id_libro WHERE g.codigo='".$libro."'";
			$req_cod = $bdd->prepare($sql_cod);
			$req_cod->execute();

			$row_cod = $req_cod->fetch();

			if ($row_cod["id_grado_otro"] == 0) {
				$req_old_vr = $bdd->prepare("SELECT uni_vr FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'");
				$req_lib_vr = $bdd->prepare("SELECT libro FROM libros WHERE id=:id");
				$req_lib_vr->execute([':id' => $libro]);
				$lib_vr_row = $req_lib_vr->fetch();
				$lib_vr_nombre = $lib_vr_row ? $lib_vr_row['libro'] : "Libro $libro";

				$sql_e = "UPDATE presupuestos SET  uni_vr='".$uni_vr."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'";
			}else{
				$req_old_vr = $bdd->prepare("SELECT uni_vr FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["codigo"]."'");
				$req_lib_vr2 = $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id=a.id_libro_eureka WHERE a.codigo='".$libro."'");
				$req_lib_vr2->execute();
				$lib_vr_row = $req_lib_vr2->fetch();
				$lib_vr_nombre = $lib_vr_row ? $lib_vr_row['libro'] : "Libro cod $libro";

				$sql_e = "UPDATE presupuestos SET  uni_vr='".$uni_vr."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$row_cod["codigo"]."'";
			}
			$req_old_vr->execute();
			$old_vr = $req_old_vr->fetch();

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

			if ($old_vr && abs((float)($old_vr['uni_vr'] ?? 0) - (float)$uni_vr) > 0.0001) {
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					"Unidades de venta real - $lib_vr_nombre", (string)$old_vr['uni_vr'], (string)$uni_vr);
			}

		}
					
				
	}
	
	header('Location: ../colegio.php?codigo='.$_POST["codigo"].'&periodo='.$_POST["periodo"].'&tab=adopciones');

?>