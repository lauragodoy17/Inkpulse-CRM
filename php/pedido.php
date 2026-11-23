<?php 
	require_once("../php/aut.php");
	require_once('../conexion/bdd.php');

	use PHPMailer\PHPMailer\PHPMailer;
	use PHPMailer\PHPMailer\SMTP;
	use PHPMailer\PHPMailer\Exception;

	require '../lib/PHPMailer/src/Exception.php';
	require '../lib/PHPMailer/src/PHPMailer.php';
	require '../lib/PHPMailer/src/SMTP.php';
	require_once("../includes/stock_bajo.php");
	require_once("../includes/pedidos_venta_directa.php");
	require_once("../includes/pedido_paquetes.php");

	// 2026-11-10: se quitó del formulario "¿El pedido es para jornada de venta en el colegio?" y los datos de la
	// jornada (ahora viven en la adopción: includes/adopcion_vd_datos.php). El pedido queda con para_andrea = 0;
	// la legalización de venta directa sigue tomando los colegios por el canal de la adopción (dvd_sql_canal_vd).
	asegurar_columnas_pedido_vd($bdd);
	pedido_paquetes_asegurar($bdd);

	$id_colegio_p = intval($_POST['id_colegio'] ?? 0);
	$id_periodo_p = intval($_POST['periodo'] ?? 0);
	// Tipo de pedido según el Tipo de adopción: 1 libros sueltos, 2 paquetes, 3 ambos (ya no se elige a mano).
	$_POST['tipo'] = pedido_tipo_segun_adopcion($bdd, $id_colegio_p, $id_periodo_p);

	// Paquetes pedidos ("id_paquete/cantidad/plataforma") → un renglón de libros_pedidos por libro del paquete.
	$lineas_paquete = pedido_lineas_de_paquetes($bdd, $id_colegio_p, $id_periodo_p, (array)($_POST['paquete'] ?? []));
	$hay_sueltos = false;
	foreach ((array)($_POST['libro'] ?? []) as $l) { $p = explode('/', (string)$l); if ((int)($p[1] ?? 0) > 0) { $hay_sueltos = true; break; } }
	if (!$lineas_paquete && !$hay_sueltos) {
		header('Location: ../solicitar_pedido.php?id_colegio='.$id_colegio_p.'&periodo='.$id_periodo_p.'&ink_status=error&ink_msg='.urlencode('Indica la cantidad de al menos un paquete o libro. No se guardó el pedido.'));
		exit;
	}


	do {
	         $caracteres = "1234567890"; //posibles caracteres a usar
	         $numerodeletras=10; //numero de letras para generar el texto
	         $cod_pedido =""; //variable para almacenar la cadena generada
	         for($i=0;$i<$numerodeletras;$i++)
	         {
	            $cod_pedido .=substr($caracteres,rand(0,strlen($caracteres)),1); /*Extraemos 1 caracter de los caracteres 
	            entre el rango 0 a Numero de letras que tiene la cadena */
	         }
	        $sql = "SELECT codigo FROM pedidos";

			$req = $bdd->prepare($sql);
			$req->execute();
			$codigos = $req->fetchAll();

	         foreach($codigos as $codigo) {
				if ($cod_pedido !="") {
					if (($codigo["codigo"]==$cod_pedido)) $cod_pedido="";
				}
			}
	   
	 } while ($cod_pedido=="");


	$ins_paq = $bdd->prepare("INSERT INTO libros_pedidos (cod_pedido, id_libro, cod_area, id_presup, cantidad, plataforma, id_paquete) VALUES (?, ?, ?, ?, ?, ?, ?)");
	foreach ($lineas_paquete as $lp) {
		$ins_paq->execute([$cod_pedido, $lp['id_libro'], $lp['cod_area'], $lp['id_presup'], $lp['cantidad'], $lp['plataforma'], $lp['id_paquete']]);
	}

	foreach ((array)($_POST["libro"] ?? []) as $libros => $libro) {

		// Libro sin área objetiva: llega "libro/cantidad/plataforma" (sin cod_area).
		list($id_libro,$cantidad,$plataforma,$cod_area) = array_pad(explode("/", $libro), 4, "");
			
		if ($libro !=0) {
			if ($cantidad > 0) {
				$sql_p = "INSERT INTO libros_pedidos(cod_pedido,id_libro,cod_area,cantidad,plataforma) VALUES('".$cod_pedido."','".$id_libro."','".$cod_area."','".$cantidad."','".$plataforma."')";
					
					
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
	
	$sql_p2 = "INSERT INTO pedidos(codigo,id_periodo,id_colegio,id_usuario,fecha_r,dir_ent,observaciones,cliente,fac_rem,tipo,estado) VALUES('".$cod_pedido."','".$_POST["periodo"]."','".$_POST["id_colegio"]."','".$_SESSION["id"]."','".$_POST["fecha_r"]."','".$_POST["dir_ent"]."','".$_POST["observaciones"]."','".$_POST["cliente"]."','".$_POST["fac_rem"]."','".$_POST["tipo"]."','1')";
				
				
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



	$sq_l2 ="SELECT CONCAT(nombres, ' ', apellidos) AS promotor FROM usuarios WHERE id='".$_SESSION["id"]."'";
														
	$req_l2 = $bdd->prepare($sq_l2);
	$req_l2->execute();
	$promo = $req_l2->fetch();

	$sq_l3 = "SELECT colegio FROM colegios WHERE id='".$_POST["id_colegio"]."'";
														
	$req_l3 = $bdd->prepare($sq_l3);
	$req_l3->execute();
	$cole = $req_l3->fetch();

	$mail = new PHPMailer(true);

	try {

		//Server settings
		//$mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;                      // OFF verbose debug output
		$mail->isSMTP();                                            // Send using SMTP
	    $mail->Host       = 'somoseureka.com.co';                    // Set the SMTP server to send through
	    $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
	    $mail->SMTPAutoTLS = false; 
	    $mail->Username   = 'crm@somoseureka.com.co';                     // SMTP username
	    $mail->Password   = 'cRm14356$';                              // SMTP password
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
		$mail->Port       = 587;
		$mail->SMTPOptions = [
		  'ssl' => [
		    'verify_peer' => false,
		    'verify_peer_name' => false,
		    'allow_self_signed' => true
		  ]
		];                                   // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_S	above                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

		//Recipients
		$mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
		$mail->addAddress("felipe.vargas@somoseureka.com.co", 'felipe.vargas@somoseureka.com.co');     // Add a recipient
			  
		$mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
		$mail->addCC("comercial@somoseureka.com.co");
		//$mail->addCC("oltoledo@hotmail.com");
		//$mail->addBCC('comercial@eurekalibros.com.co');

			  
		// Content
		$mail->isHTML(true);

		$sql = "SELECT id FROM pedidos WHERE codigo='".$cod_pedido."'";

		$req = $bdd->prepare($sql);
		$req->execute();
		$pedido = $req->fetch();
		                                  // Set email format to HTML
		$mail->Subject = 'Solicitud de pedido #'.$pedido["id"].'';

		

		$mail->Body    = '<p style="font-size: 17px;">'.$promo["promotor"].' hizo la solicitud de pedido #'.$pedido["id"].' para: '.$cole["colegio"].'. Haz clic <a href="https://crm.somoseureka.com.co/pedido_colegio.php?id_pedido='.$pedido['id'].'&tp=2">aquí</a> para revisarlo<p>';

		$mail->AltBody = 'probandosss';

		$mail->CharSet = 'UTF-8';

		$mail->send();
			//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
	} catch (Exception $e) {

		echo "An error has occurred please try again: {$mail->ErrorInfo}";
	}

	// Aviso de stock bajo (bodega General, World Office) a facturación. El correo dice que es un
	// pedido de VENTA (pedido por el usuario 2026-10-05) — ver includes/correo_stock_bajo.php.
	try {
		$bajo_stock = libros_bajo_stock_pedidos($bdd, [$pedido['id']], 'pedidos');
		$libros_bajos = $bajo_stock[$pedido['id']] ?? [];

		if ($libros_bajos) {
			require_once("../includes/correo_stock_bajo.php");
			enviar_correo_stock_bajo(
				'Stock bajo en pedido de venta #'.$pedido['id'].' - '.$cole['colegio'],
				'El pedido de venta <strong>#'.$pedido['id'].'</strong>, solicitado por '.htmlspecialchars($promo['promotor']).' para el colegio <strong>'.htmlspecialchars($cole['colegio']).'</strong>, incluye libros cuya existencia en la bodega General de World Office está por debajo de '.UMBRAL_STOCK_BAJO.' unidades:',
				$libros_bajos,
				false,
				'https://crm.somoseureka.com.co/pedido_colegio.php?id_pedido='.$pedido['id'].'&tp=2',
				'El pedido de venta #'.$pedido['id'].' para '.$cole['colegio'].' incluye libros con existencia baja en bodega General.'
			);
		}
	} catch (\Throwable $e) {
		error_log('No se pudo enviar el aviso de stock bajo del pedido #'.$pedido['id'].': '.$e->getMessage());
	}

	header('Location: ../colegios_pedidos.php?periodo='.$_POST['periodo'].'&ink_status=ok&ink_msg='.urlencode('Pedido solicitado correctamente.'));

?>