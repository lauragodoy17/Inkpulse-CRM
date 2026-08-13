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


	foreach ($_POST["libro"] as $libros => $libro) {

		list($id_libro,$cantidad,$plataforma,$cod_area) = explode("/", $libro);
			
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


	$sq_l2 = "SELECT CONCAT(nombres, ' ', apellidos) AS promotor FROM usuarios WHERE id='".$_SESSION["id"]."'";
														
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

	// Aviso de stock bajo (bodega General, World Office) a facturación
	try {
		$bajo_stock = libros_bajo_stock_pedidos($bdd, [$pedido['id']], 'pedidos');
		$libros_bajos = $bajo_stock[$pedido['id']] ?? [];

		if ($libros_bajos) {
			$filas_html = '';
			foreach ($libros_bajos as $lb) {
				$filas_html .= '<tr><td style="padding:4px 10px;border-bottom:1px solid #e5e7eb;">'.htmlspecialchars($lb['libro']).'</td>'
					. '<td style="padding:4px 10px;border-bottom:1px solid #e5e7eb;text-align:center;">'.$lb['existencia'].' unid.</td></tr>';
			}

			$mail_stock = new PHPMailer(true);
			$mail_stock->isSMTP();
			$mail_stock->Host       = 'somoseureka.com.co';
			$mail_stock->SMTPAuth   = true;
			$mail_stock->SMTPAutoTLS = false;
			$mail_stock->Username   = 'crm@somoseureka.com.co';
			$mail_stock->Password   = 'cRm14356$';
			$mail_stock->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
			$mail_stock->Port       = 587;
			$mail_stock->SMTPOptions = [
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true
				]
			];

			$mail_stock->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
			$mail_stock->addAddress('felipe.vargas@somoseureka.com.co', 'felipe.vargas@somoseureka.com.co');
			$mail_stock->addCC('comercial@somoseureka.com.co');
			$mail_stock->addCC('oltoledo@hotmail.com');
			$mail_stock->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');

			$mail_stock->isHTML(true);
			$mail_stock->CharSet = 'UTF-8';
			$mail_stock->Subject = 'Stock bajo en pedido #'.$pedido['id'].' - '.$cole['colegio'];
			$mail_stock->Body =
				'<p style="font-size:15px;">El pedido <strong>#'.$pedido['id'].'</strong>, solicitado por '.htmlspecialchars($promo['promotor']).' para el colegio <strong>'.htmlspecialchars($cole['colegio']).'</strong>, incluye libros cuya existencia en la bodega General de World Office está por debajo de 50 unidades:</p>'
				. '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>'
				. '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Libro</th>'
				. '<th style="padding:4px 10px;border-bottom:2px solid #d1d5db;">Existencia</th>'
				. '</tr></thead><tbody>'.$filas_html.'</tbody></table>'
				. '<p style="font-size:14px;margin-top:14px;">Haz clic <a href="https://crm.somoseureka.com.co/pedido_colegio.php?id_pedido='.$pedido['id'].'&tp=2">aquí</a> para revisar el pedido.</p>';
			$mail_stock->AltBody = 'El pedido #'.$pedido['id'].' para '.$cole['colegio'].' incluye libros con existencia baja en bodega General.';

			$mail_stock->send();
		}
	} catch (Exception $e) {
		error_log('No se pudo enviar el aviso de stock bajo del pedido #'.$pedido['id'].': '.$e->getMessage());
	}

	header('Location: ../colegios_pedidos.php?periodo='.$_POST['periodo'].'&ink_status=ok&ink_msg='.urlencode('Pedido solicitado correctamente.'));

?>