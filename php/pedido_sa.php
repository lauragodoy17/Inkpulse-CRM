<?php
	ini_set('display_startup_errors', 1);
	ini_set('display_errors', 1);
	error_reporting(-1);
	require_once("../php/aut.php");
	require_once('../conexion/bdd.php');

	use PHPMailer\PHPMailer\PHPMailer;
	use PHPMailer\PHPMailer\SMTP;
	use PHPMailer\PHPMailer\Exception;

	require '../lib/PHPMailer/src/Exception.php';
	require '../lib/PHPMailer/src/PHPMailer.php';
	require '../lib/PHPMailer/src/SMTP.php';
	require_once("../includes/stock_bajo.php");
	require_once("../includes/pedidos2_cliente.php");

	header("Content-Type:text/html;charset=utf-8");

	$dir_subida = $_SERVER['DOCUMENT_ROOT'] .'/adjuntos_dist/';
	$nombre_archivo=uniqid()."_".$_FILES['archivo']['name'];
	$fichero_subido = $dir_subida . basename($nombre_archivo);
	if (move_uploaded_file($_FILES['archivo']['tmp_name'], $fichero_subido)) {
		echo "archivo subido";
	}else{
		$nombre_archivo="";
	}

	$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

	$req_periodo = $bdd->prepare($sql_periodo);
	$req_periodo->execute();
	$gp_periodo = $req_periodo->fetch();

	//$objetivo = $_POST['objetivo'];

	do {
	    $caracteres = "1234567890"; //posibles caracteres a usar
	    $numerodeletras=10; //numero de letras para generar el texto
	    $cod_pedido =""; //variable para almacenar la cadena generada
	    for($i=0;$i<$numerodeletras;$i++)
	    {
	        $cod_pedido .=substr($caracteres,rand(0,strlen($caracteres)),1); /*Extraemos 1 caracter de los caracteres 
	         entre el rango 0 a Numero de letras que tiene la cadena */
	    }
	    $sql = "SELECT codigo FROM pedidos2";

		$req = $bdd->prepare($sql);
		$req->execute();
		$codigos = $req->fetchAll();

	    foreach($codigos as $codigo) {
			if ($cod_pedido !="") {
				if (($codigo["codigo"]==$cod_pedido)) $cod_pedido="";
			}
		}
	   
	 } while ($cod_pedido=="");


	 // 1. Mapa de relaciones [id_libro => cartilla] (compartido con Backorders sin adopción)
	require_once("../includes/pedidos2_cartillas.php");
	$relaciones = relaciones_cartillas_pedido_sa();


	foreach ($_POST["libro_e"] as $libros => $libro) {

		if (empty($libro)) continue;
		list($id_libro,$cantidad,$descuento) = array_pad(explode("/", $libro), 3, 0);
				
		if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();


			// 2. Obtienes el valor directamente o asignas un valor por defecto (null) si no existe
			$cartilla = $relaciones[$id_libro] ?? null;

			$sql_p = "INSERT INTO libros_pedidos2(cod_pedido,id_libro,cantidad,descuento) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."','".$descuento."')";
				

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

			if ($cartilla !== null) {

				$sql_p = "INSERT INTO libros_pedidos2(cod_pedido,id_libro,cantidad,descuento) VALUES('".$cod_pedido."','".$cartilla."','".$cantidad."','".$descuento."')";

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
			

	}

	foreach (($_POST['pri_sec'] ?? []) as $index => $id_libro) {

		echo "entro";
    	$cantidad  = $_POST['cantidad_pri_sec'][$index]  ?? 0;
    	$descuento = $_POST['descuento_pri_sec'][$index] ?? 0;

    	if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();

			// 2. Obtienes el valor directamente o asignas un valor por defecto (null) si no existe
			$cartilla = $relaciones[$id_libro] ?? null;

			$sql_p = "INSERT INTO libros_pedidos2(cod_pedido,id_libro,cantidad, descuento) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."','".$descuento."')";

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

			if ($cartilla !== null) {

				$sql_p = "INSERT INTO libros_pedidos2(cod_pedido,id_libro,cantidad, descuento) VALUES('".$cod_pedido."','".$cartilla."','".$cantidad."','".$descuento."')";

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
	}

	$_POST["observaciones"]=str_replace("'", " ", $_POST["observaciones"]);

	

	// Cliente de World Office (clientes.id) elegido en el formulario: es el que sale en la planilla
	asegurar_columna_cliente_pedidos2($bdd);
	$id_cliente = intval($_POST["cliente"] ?? 0);
	$cliente_sql = $id_cliente > 0 ? "'".$id_cliente."'" : "NULL";

	$sql_p2 = "INSERT INTO pedidos2(codigo,id_periodo,colegio,cliente,id_usuario,fecha_r,observaciones,archivo,fac_rem,estado,tipo,tipo_muestras) VALUES('".$cod_pedido."','".$gp_periodo["id"]."','".$_POST["colegio"]."',".$cliente_sql.",'".$_SESSION["id"]."','".$_POST["fecha_r"]."','".$_POST["observaciones"]."','".$nombre_archivo."','".$_POST["fac_rem"]."','1','".$_POST["tipo_p"]."','".$_POST["tipo"]."')";

				
				
	$query_p2 = $bdd->prepare( $sql_p2 );
	if ($query_p2 == false) {
		print_r($bdd->errorInfo());
		die ('Erreur prepare');
	}
	$sth_p2 = $query_p2->execute();
	if ($sth_p2 == false) {
		print_r($query_p2->errorInfo());
		die ('Erreur execute');
	}


	$sql = "SELECT id FROM pedidos2 WHERE codigo='".$cod_pedido."'";

	$req = $bdd->prepare($sql);
	$req->execute();
	$pedido = $req->fetch();

	$sq_l2 = "SELECT CONCAT(nombres, ' ', apellidos) AS promotor FROM usuarios WHERE id='".$_SESSION["id"]."'";
														
	$req_l2 = $bdd->prepare($sq_l2);
	$req_l2->execute();
	$promo = $req_l2->fetch();

	
	/*$mail = new PHPMailer(true);

	try {

		//Server settings
		//$mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;                      // OFF verbose debug output
		$mail->isSMTP();                                            // Send using SMTP
	    $mail->Host       = 'mail.somoseureka.com.co';                    // Set the SMTP server to send through
	    $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
	    $mail->SMTPAutoTLS = false; 
	    $mail->Username   = 'crm@somoseureka.com.co';                     // SMTP username
	    $mail->Password   = 'cRm14356$';                              // SMTP password
		//$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
		$mail->Port       = 587;                                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_S	above                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

		//Recipients
		$mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
		$mail->addAddress("felipe.vargas@somoseureka.com.co", 'felipe.vargas@somoseureka.com.co');     // Add a recipient
			  
		$mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
		$mail->addCC("comercial@somoseureka.com.co");

			  
		// Content
		$mail->isHTML(true);

		$sql = "SELECT id FROM pedidos2 WHERE codigo='".$cod_pedido."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$pedido = $req->fetch();
		                                  // Set email format to HTML
		$mail->Subject = 'Solicitud de pedido sin adopción #'.$pedido["id"].'';

		

		$mail->Body    = '<p style="font-size: 17px;">El usuario: '.$promo["promotor"].' hizo la solicitud de pedido sin adopción #'.$pedido["id"].' para: '.$_POST["colegio"].'. Haz clic <a href="https://crm.somoseureka.com.co/pedido_colegio_sa.php?id_pedido_dist='.$pedido['id'].' ">aquí</a> para revisarlo<p>';

		$mail->AltBody = 'probandosss';

		$mail->CharSet = 'UTF-8';

		$mail->send();
			//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
	} catch (Exception $e) {

		echo "An error has occurred please try again: {$mail->ErrorInfo}";
	}*/

	// Aviso de stock bajo a facturación. Venta (tipo_p=1): bodega General. Muestras (tipo_p=2): bodega
	// General o Muestras General, basta con que una esté baja (pedido por el usuario 2026-10-05). El
	// correo dice el tipo de pedido — ver includes/correo_stock_bajo.php.
	try {
		$esMuestras = (int)($_POST['tipo_p'] ?? 0) === 2;
		if ($esMuestras) {
			$req_lm = $bdd->prepare("SELECT DISTINCT lp.id_libro FROM libros_pedidos2 lp WHERE lp.cod_pedido = ? AND lp.cantidad != 0");
			$req_lm->execute([$cod_pedido]);
			$libros_bajos = libros_bajo_stock_muestras($bdd, $req_lm->fetchAll(PDO::FETCH_COLUMN));
			$tipoTexto = 'pedido de muestras sin adopción';
			$bodegasTexto = 'en la bodega General o en la bodega Muestras General';
		} else {
			$bajo_stock = libros_bajo_stock_pedidos($bdd, [$pedido['id']], 'pedidos2');
			$libros_bajos = $bajo_stock[$pedido['id']] ?? [];
			$tipoTexto = 'pedido de venta sin adopción';
			$bodegasTexto = 'en la bodega General';
		}

		if ($libros_bajos) {
			require_once("../includes/correo_stock_bajo.php");
			enviar_correo_stock_bajo(
				'Stock bajo en '.$tipoTexto.' #'.$pedido['id'].' - '.$_POST['colegio'],
				'El '.$tipoTexto.' <strong>#'.$pedido['id'].'</strong>, solicitado por '.htmlspecialchars($promo['promotor']).' para el colegio <strong>'.htmlspecialchars($_POST['colegio']).'</strong>, incluye libros cuya existencia '.$bodegasTexto.' de World Office está por debajo de '.UMBRAL_STOCK_BAJO.' unidades:',
				$libros_bajos,
				$esMuestras,
				'https://crm.somoseureka.com.co/pedido_colegio_sa.php?id_pedido='.$pedido['id'],
				'El '.$tipoTexto.' #'.$pedido['id'].' para '.$_POST['colegio'].' incluye libros con existencia baja '.$bodegasTexto.'.'
			);
		}
	} catch (\Throwable $e) {
		error_log('No se pudo enviar el aviso de stock bajo del pedido sin adopción #'.$pedido['id'].': '.$e->getMessage());
	}

	header("Location: ../pedido_colegio_sa.php?id_pedido=".$pedido["id"]."");

?>