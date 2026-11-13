<?php
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once("registrar_historial.php");
	require_once(__DIR__ . "/../includes/paquetes_colegio.php");
	crear_tablas_paquetes($bdd);
	require_once(__DIR__ . "/../includes/adopcion_cerrada.php");

	// Si el formulario llegó incompleto, no se guarda nada. PHP descarta los campos que
	// pasan de max_input_vars; entonces faltan id_colegio/periodo y el guardado escribía
	// registros con colegio/periodo vacíos. "form_completo" es el último campo del
	// formulario (ajax/tab_adopciones.php): si no llegó, el POST se cortó.
	if (!isset($_POST['form_completo']) || intval($_POST['id_colegio'] ?? 0) <= 0 || intval($_POST['periodo'] ?? 0) <= 0) {
		header('Content-Type: text/html; charset=utf-8');
		echo '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;color:#7f1d1d;">'
		   . '<h3 style="margin-top:0;">No se guardaron los cambios</h3>'
		   . '<p>El formulario de Adopciones llegó incompleto al servidor (tiene más campos de los que el servidor acepta en un solo envío), así que no se guardó nada para no dejar datos a medias.</p>'
		   . '<p>Avísale al administrador del sistema para que aumente el límite <code>max_input_vars</code> de PHP.</p>'
		   . '<p><a href="javascript:history.back()">&larr; Volver</a></p></div>';
		exit;
	}

	if (adopcion_cerrada($bdd, $_POST["id_colegio"] ?? 0, $_POST["periodo"] ?? 0)) {
		header('Location: ../colegio.php?codigo='.urlencode($_POST["codigo"] ?? '').'&periodo='.intval($_POST["periodo"] ?? 0).'&tab=adopciones');
		exit;
	}

	$id_usuario_h = intval($_SESSION["id"] ?? 0);

	// "Venta directa - Eureka" exige % de sobrecosto (2026-10-26). Se valida antes de escribir nada.
	require_once(__DIR__ . "/../includes/sobrecosto_canal.php");
	sobrecosto_asegurar_columna($bdd);
	$url_adop = '../colegio.php?codigo='.urlencode($_POST["codigo"] ?? '').'&periodo='.intval($_POST["periodo"]).'&tab=adopciones';
	// Hasta dos canales de venta: el primero se guarda en id_canal (lo usa el resto de este archivo) y el segundo en id_canal2.
	$canales_post = canales_del_formulario($_POST["canal"] ?? []);
	$_POST["canal"] = $canales_post[0] ?? 0;
	$id_canal2 = $canales_post[1] ?? null;
	$con_sobrecosto = (bool)array_intersect($canales_post, canales_con_sobrecosto($bdd));
	// 2026-11-10: ya no hay % de sobrecosto para toda la adopción; cada título trae su propio valor en pesos
	// (sc_libro[id_libro] / sc_area[cod_area], columna "Sobrecosto por libro"), que se guarda tal cual en presupuestos.sobrecosto.

	// Sobrecosto por libro obligatorio en los títulos adoptados solo con "Venta directa - Eureka"; con los demás canales es
	// opcional (2026-11-13, la usuaria). 0 es válido.
	// Se valida antes de escribir nada: la clave del título es la primera parte de definir[] (id del libro, o cod_area
	// en el grado "Otro"), la misma de sc_libro[] / sc_area[].
	$sc_post = (array)($_POST['sc_libro'] ?? []) + (array)($_POST['sc_area'] ?? []);
	$sc_faltan = [];
	foreach ((array)($_POST['definir'] ?? []) as $def_sc) {
		$clave_def = explode('/', (string)$def_sc)[0];
		if ($clave_def === '' || !array_key_exists($clave_def, $sc_post)) continue;
		if (preg_replace('/[^0-9]/', '', (string)$sc_post[$clave_def]) === '') $sc_faltan[] = $clave_def;
	}
	if ($con_sobrecosto && $sc_faltan) {
		$nombres_sc = [];
		$st_n = $bdd->prepare("SELECT libro FROM libros WHERE id = ?");
		$st_a = $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id = a.id_libro_eureka WHERE a.codigo = ? LIMIT 1");
		foreach ($sc_faltan as $k) {
			$st = ctype_digit($k) ? $st_n : $st_a;
			$st->execute([$k]);
			$nombres_sc[] = $st->fetchColumn() ?: $k;
		}
		header('Location: '.$url_adop.'&ink_status=error&ink_msg='.urlencode('Falta el sobrecosto por libro (puede ser 0) en: '.implode(', ', $nombres_sc).'. No se guardaron los cambios.'));
		exit;
	}

	// Venta directa - Eureka (2026-11-10): fechas y horario de venta + contacto del colegio (nombre y celular obligatorios).
	// Se valida antes de escribir nada.
	require_once(__DIR__ . "/../includes/adopcion_vd_datos.php");
	adopcion_vd_asegurar($bdd);
	$vd_adop = null;
	if ($con_sobrecosto) {
		$vd_res = adopcion_vd_del_post($_POST);
		if (!$vd_res['ok']) {
			header('Location: '.$url_adop.'&ink_status=error&ink_msg='.urlencode($vd_res['error'].' No se guardaron los cambios.'));
			exit;
		}
		$vd_adop = $vd_res['datos'];
	}

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

			if (empty($row_cod["id_grado_otro"])) {

				$sql2 = "SELECT id,tasa_compra,descuento,tasa_compra_d,descuento_d FROM presupuestos WHERE id='".$libro."' AND id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

			}else{

				$sql2 = "SELECT id,tasa_compra,descuento,tasa_compra_d,descuento_d FROM presupuestos WHERE cod_area='".$row_cod["codigo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";

			}
			$req2 = $bdd->prepare($sql2);
			$req2->execute();
			$row2 = $req2->fetch();	

			if ($row2["tasa_compra_d"]==0.00) {
		
				if (empty($row_cod["id_grado_otro"])) {

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
			if (empty($row_cod["id_grado_otro"])) {
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
			if (isset($req_lib_d) && empty($row_cod["id_grado_otro"])) $req_lib_d->execute([':id' => $libro]);
			$lib_d_row = isset($req_lib_d) ? $req_lib_d->fetch() : false;
			$lib_d_nombre = $lib_d_row ? $lib_d_row['libro'] : "Libro $libro";

			if (empty($row_cod["id_grado_otro"])) {
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
	// El select "Tipo de adopción" solo se muestra a Administrador/Héctor Morales
	// (puede_usar_tipo_adopcion()). Si no vino en el formulario, el tipo guardado NO
	// se toca: antes se sobrescribía con 0 y recalcular_paquetes_colegio() borraba
	// los paquetes del colegio cada vez que otro usuario guardaba Adopciones.
	$tipo_adop_enviado	= isset($_POST['tipo_adop']);
	$tipo_adop			= (int)($_POST['tipo_adop'] ?? 0);
	$set_tipo_adop		= $tipo_adop_enviado ? ", tipo_adop='".$tipo_adop."'" : '';

	// Foto de antes de guardar, para dejar en el historial los cambios de tipo de adopción y de precio de los paquetes.
	$tipo_adop_antes = tipo_adop_guardado($bdd, $_POST["id_colegio"], $_POST["periodo"]);
	$paquetes_antes  = paquetes_precios_actuales($bdd, $_POST["id_colegio"], $_POST["periodo"]);

	if ($num < 1 ) {

		$sql_e = "INSERT INTO recursos(id_periodo,id_colegio,recurso,valor_recurso, reintegro, valor_reintegro,id_canal,descripcion_canal,venta_real,fecha,observaciones,archivo,archivo2,archivo3,cliente,tipo_adop) VALUES ('".$_POST["periodo"]."', '".$_POST["id_colegio"]."', '".$recurso."','".$valor_recurso."', '".$reintegro."','".$valor_reintegro."','".$_POST["canal"]."','".$descripcion_canal."','".$venta_real."','".date("Y-m-d")."','".$_POST["observaciones"]."','".$archivos_subidos[0]."','".$archivos_subidos[1]."','".$archivos_subidos[2]."','".$cliente_adop."','".$tipo_adop."')";

	}else {

		$arch_set = $hay_archivos_nuevos ? ", archivo='".$archivos_subidos[0]."', archivo2='".$archivos_subidos[1]."', archivo3='".$archivos_subidos[2]."'" : '';
		$sql_e = "UPDATE recursos SET recurso='".$recurso."', valor_recurso='".$valor_recurso."', reintegro='".$reintegro."', valor_reintegro='".$valor_reintegro."', id_canal='".$_POST["canal"]."', descripcion_canal='".$descripcion_canal."',venta_real='".$venta_real."' , fecha='".date("Y-m-d")."', observaciones='".$_POST["observaciones"]."', cliente='".$cliente_adop."'".$set_tipo_adop.$arch_set." WHERE id_colegio='".$_POST["id_colegio"]."' AND id_periodo='".$_POST["periodo"]."'";
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

			if (empty($row_cod["id_grado_otro"])) {
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
	
	// Selección manual de títulos que van al paquete (solo tiene efecto real con
	// Tipo de adopción = Ambos; ver includes/paquetes_colegio.php). Un título que ya
	// estaba adoptado antes de este guardado respeta lo que el usuario marcó/desmarcó
	// en el panel "Selecciona los títulos para el paquete"; uno recién adoptado en
	// este mismo guardado (no tuvo panel para elegir) entra incluido por defecto.
	// Si el panel no vino en el formulario (usuario que no ve el módulo de paquetes),
	// los títulos ya adoptados conservan su selección en vez de quedar todos en 0.
	// La tabla "Paquete / Paquete 2 / Libro suelto" viaja como un solo campo JSON {paq:[ids], paq2:[ids], sue:[ids]}.
	$seleccion_ambos = json_decode((string)($_POST['seleccion_ambos'] ?? ''), true);
	$panel_seleccion_enviado = is_array($seleccion_ambos);
	$en_paquete_marcados = array_map('intval', (array)($seleccion_ambos['paq'] ?? []));
	$req_en_paquete = $bdd->prepare("UPDATE presupuestos SET en_paquete=? WHERE id=?");
	foreach (($defs2 ?? []) as $id_p) {
		$id_p = (int)$id_p;
		$ya_estaba_antes = in_array($id_p, $defs ?? []);
		if ($ya_estaba_antes && !$panel_seleccion_enviado) continue;
		$en_paquete_val = $ya_estaba_antes ? (in_array($id_p, $en_paquete_marcados) ? 1 : 0) : 1;
		$req_en_paquete->execute([$en_paquete_val, $id_p]);
	}

	// Columna "Paquete 2" (segundo paquete del curso, solo con "Ambos"): solo se toca si la tabla
	// vino en el formulario; un título recién adoptado arranca fuera del paquete 2.
	if ($panel_seleccion_enviado) {
		$en_paquete2_marcados = array_map('intval', (array)($seleccion_ambos['paq2'] ?? []));
		$req_en_paquete2 = $bdd->prepare("UPDATE presupuestos SET en_paquete2=? WHERE id=?");
		foreach (($defs2 ?? []) as $id_p) {
			$id_p = (int)$id_p;
			$ya_estaba_antes = in_array($id_p, $defs ?? []);
			$req_en_paquete2->execute([($ya_estaba_antes && in_array($id_p, $en_paquete2_marcados)) ? 1 : 0, $id_p]);
		}
	}

	// Columna "Libro suelto" de la tabla de selección de "Ambos" (presupuestos.venta_suelta),
	// independiente de "Paquete". Solo se toca si la tabla vino en el formulario y el tipo
	// guardado es Ambos (3); con otros tipos, o si el usuario no ve la tabla, no cambia nada.
	// Un título recién adoptado en este guardado queda SIN marcar como suelto (0), para
	// que el usuario lo marque explícitamente.
	if ($panel_seleccion_enviado && $tipo_adop === 3) {
		$venta_suelta_marcados = array_map('intval', (array)($seleccion_ambos['sue'] ?? []));
		$req_venta_suelta = $bdd->prepare("UPDATE presupuestos SET venta_suelta=? WHERE id=?");
		foreach (($defs2 ?? []) as $id_p) {
			$id_p = (int)$id_p;
			$ya_estaba_antes = in_array($id_p, $defs ?? []);
			$venta_suelta_val = ($ya_estaba_antes && in_array($id_p, $venta_suelta_marcados)) ? 1 : 0;
			$req_venta_suelta->execute([$venta_suelta_val, $id_p]);
		}
	}

	recalcular_paquetes_colegio($bdd, $_POST["id_colegio"], $_POST["periodo"]);

	// Precio redondeado escrito en "Previsualizar paquetes" para paquetes que todavía no estaban
	// guardados ({"grado-número": valor}): se aplica ahora que ya existen. Misma validación que
	// ajax/guardar_precio_paquete.php (con "Paquetes", máximo $1.000 por debajo de la sumatoria).
	$precios_pend = json_decode((string)($_POST['precios_paquete'] ?? ''), true);
	if (is_array($precios_pend) && $precios_pend && puede_usar_tipo_adopcion()) {
		$tipo_guardado = tipo_adop_guardado($bdd, $_POST["id_colegio"], $_POST["periodo"]);
		$req_paq_pend = $bdd->prepare("SELECT id, precio_neto_sumado FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=? AND id_grado=? AND numero=?");
		$upd_paq_pend = $bdd->prepare("UPDATE paquetes_colegio SET precio_redondeado=?, precio_final=?, updated_at=NOW() WHERE id=?");
		foreach ($precios_pend as $clave_pend => $valor_pend) {
			if (!preg_match('/^(\d+)-([12])$/', (string)$clave_pend, $m_pend)) continue;
			$valor_pend = trim((string)$valor_pend);
			if ($valor_pend === '' || !is_numeric($valor_pend) || (float)$valor_pend < 0) continue;
			$req_paq_pend->execute([(int)$_POST["id_colegio"], (int)$_POST["periodo"], (int)$m_pend[1], (int)$m_pend[2]]);
			$paq_pend = $req_paq_pend->fetch(PDO::FETCH_ASSOC);
			if (!$paq_pend) continue;
			if (es_tipo_adop_paquetes($tipo_guardado) && (float)$valor_pend < precio_minimo_paquete($paq_pend['precio_neto_sumado'])) continue;
			$upd_paq_pend->execute([(float)$valor_pend, (float)$valor_pend, (int)$paq_pend['id']]);
		}
	}

	if ($tipo_adop_enviado && $tipo_adop !== $tipo_adop_antes) {
		registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones', 'Tipo de adopción',
			tipo_adop_nombre($bdd, $tipo_adop_antes), tipo_adop_nombre($bdd, $tipo_adop));
	}
	historial_precios_paquetes($bdd, $_POST["id_colegio"], $id_usuario_h, $paquetes_antes,
		paquetes_precios_actuales($bdd, $_POST["id_colegio"], $_POST["periodo"]));

	$bdd->prepare("UPDATE recursos SET id_canal2 = ? WHERE id_colegio = ? AND id_periodo = ?")
		->execute([$id_canal2, (int)$_POST["id_colegio"], (int)$_POST["periodo"]]);

	if ($vd_adop !== null) {
		$vd_nuevas = adopcion_vd_guardar($bdd, (int)$_POST["id_colegio"], (int)$_POST["periodo"], $vd_adop, $id_usuario_h);
		// Alerta por correo/WhatsApp si otro usuario programó fechas nuevas (includes/alertas_vd.php).
		if ($vd_nuevas) {
			require_once(__DIR__ . "/../includes/alertas_vd.php");
			try { alertas_vd_nueva($bdd, $vd_nuevas, $id_usuario_h); } catch (Throwable $e) { error_log("Alertas venta directa: " . $e->getMessage()); }
		}
	}

	// Sobrecosto por libro: se guarda en todas las adopciones, con cualquier canal (2026-11-11). 0 es un valor válido;
	// vacío deja el campo sin valor (NULL).
	{
		$sc_grupos = [
			'id_libro' => (array)($_POST['sc_libro'] ?? []),
			'cod_area' => (array)($_POST['sc_area'] ?? []),
		];
		foreach ($sc_grupos as $col_sc => $valores_sc) {
			$st_sc_old = $bdd->prepare("SELECT sobrecosto FROM presupuestos WHERE id_colegio = ? AND id_periodo = ? AND $col_sc = ? LIMIT 1");
			$st_sc_upd = $bdd->prepare("UPDATE presupuestos SET sobrecosto = ? WHERE id_colegio = ? AND id_periodo = ? AND $col_sc = ?");
			$st_sc_lib = $col_sc === 'id_libro'
				? $bdd->prepare("SELECT libro FROM libros WHERE id = ?")
				: $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id = a.id_libro_eureka WHERE a.codigo = ? LIMIT 1");
			foreach ($valores_sc as $clave_sc => $valor_sc) {
				$txt_sc = preg_replace('/[^0-9.,]/', '', trim((string)$valor_sc));
				if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $txt_sc)) $txt_sc = str_replace('.', '', $txt_sc); // "5.000" = cinco mil
				$txt_sc = str_replace(',', '.', $txt_sc);
				$nuevo_sc = ($txt_sc !== '' && is_numeric($txt_sc) && (float)$txt_sc >= 0) ? round((float)$txt_sc, 2) : null;
				$params_sc = [(int)$_POST["id_colegio"], (int)$_POST["periodo"], (string)$clave_sc];
				$st_sc_old->execute($params_sc);
				$fila_sc = $st_sc_old->fetch(PDO::FETCH_ASSOC);
				if (!$fila_sc) continue;
				$viejo_sc = $fila_sc['sobrecosto'] !== null ? (float)$fila_sc['sobrecosto'] : null;
				if ($viejo_sc === $nuevo_sc || ($viejo_sc !== null && $nuevo_sc !== null && abs($viejo_sc - $nuevo_sc) < 0.005)) continue;
				$st_sc_upd->execute(array_merge([$nuevo_sc], $params_sc));
				$st_sc_lib->execute([(string)$clave_sc]);
				$nombre_sc = $st_sc_lib->fetchColumn() ?: "Libro $clave_sc";
				registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Adopciones',
					"Sobrecosto por libro - $nombre_sc", $viejo_sc === null ? '' : (string)$viejo_sc, $nuevo_sc === null ? '' : (string)$nuevo_sc);
			}
		}
	}

	// Con estos canales el precio venta padre es obligatorio en los títulos adoptados (la pantalla no deja enviar sin él;
	// si igual llega vacío, se guarda lo demás y se avisa cuáles faltan).
	$sin_pp = [];
	if ($con_sobrecosto) {
		$st_pp = $bdd->prepare("SELECT l.libro FROM presupuestos p JOIN libros l ON l.id = p.id_libro
		                        WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = 1 AND COALESCE(p.precio_venta_final, 0) <= 0 ORDER BY l.libro");
		$st_pp->execute([(int)$_POST["id_colegio"], (int)$_POST["periodo"]]);
		$sin_pp = $st_pp->fetchAll(PDO::FETCH_COLUMN);
	}
	{
		// Sobrecosto por libro: obligatorio solo con "Venta directa - Eureka"; 0 cuenta como digitado (2026-11-13).
		$st_sc_falta = $bdd->prepare("SELECT l.libro FROM presupuestos p JOIN libros l ON l.id = p.id_libro
		                              WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = 1 AND p.sobrecosto IS NULL ORDER BY l.libro");
		$st_sc_falta->execute([(int)$_POST["id_colegio"], (int)$_POST["periodo"]]);
		$sin_sc = $con_sobrecosto ? $st_sc_falta->fetchAll(PDO::FETCH_COLUMN) : [];
		$faltas = [];
		if ($sin_pp) $faltas[] = 'falta el precio venta padre en: '.implode(', ', $sin_pp);
		if ($sin_sc) $faltas[] = 'falta el sobrecosto por libro en: '.implode(', ', $sin_sc);
		if ($faltas) {
			header('Location: '.$url_adop.'&ink_status=error&ink_msg='.urlencode('Se guardó, pero son obligatorios: '.implode('; ', $faltas).'.'));
			exit;
		}
	}

	header('Location: ../colegio.php?codigo='.$_POST["codigo"].'&periodo='.$_POST["periodo"].'&tab=adopciones');

?>