<?php
	/*ini_set('display_startup_errors', 1);
	ini_set('display_errors', 1);
	error_reporting(-1);*/
	require_once("../php/aut.php");
	require_once('../conexion/bdd.php');

	use PHPMailer\PHPMailer\PHPMailer;
	use PHPMailer\PHPMailer\SMTP;
	use PHPMailer\PHPMailer\Exception;

	require '../lib/PHPMailer/src/Exception.php';
	require '../lib/PHPMailer/src/PHPMailer.php';
	require '../lib/PHPMailer/src/SMTP.php';
	require_once("../includes/stock_bajo.php");

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


	foreach ($_POST["libro_e"] as $libros => $libro) {

		if (empty($libro)) continue;
		list($id_libro,$cantidad,$descuento) = array_pad(explode("/", $libro), 3, 0);
				
		if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();

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

		}
			

	}

	foreach (($_POST['pri_sec'] ?? []) as $index => $id_libro) {
    	$cantidad  = $_POST['cantidad_pri_sec'][$index]  ?? 0;
    	$descuento = $_POST['descuento_pri_sec'][$index] ?? 0;

    	if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();

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

		}
	}

	$_POST["observaciones"]=str_replace("'", " ", $_POST["observaciones"]);

	

	$sql_p2 = "INSERT INTO pedidos2(codigo,id_periodo,colegio,id_usuario,fecha_r,observaciones,archivo,fac_rem,estado) VALUES('".$cod_pedido."','7','".$_POST["colegio"]."','".$_SESSION["id"]."','".$_POST["fecha_r"]."','".$_POST["observaciones"]."','".$nombre_archivo."','".$_POST["fac_rem"]."','1')";

				
				
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

	// Aviso de stock bajo (bodega General, World Office) a facturación
	try {
		$bajo_stock = libros_bajo_stock_pedidos($bdd, [$pedido['id']], 'pedidos2');
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
			$mail_stock->Subject = 'Stock bajo en pedido sin adopción #'.$pedido['id'].' - '.$_POST['colegio'];
			$mail_stock->Body =
				'<p style="font-size:15px;">El pedido sin adopción <strong>#'.$pedido['id'].'</strong>, solicitado por '.htmlspecialchars($promo['promotor']).' para el colegio <strong>'.htmlspecialchars($_POST['colegio']).'</strong>, incluye libros cuya existencia en la bodega General de World Office está por debajo de 50 unidades:</p>'
				. '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>'
				. '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Libro</th>'
				. '<th style="padding:4px 10px;border-bottom:2px solid #d1d5db;">Existencia</th>'
				. '</tr></thead><tbody>'.$filas_html.'</tbody></table>'
				. '<p style="font-size:14px;margin-top:14px;">Haz clic <a href="https://crm.somoseureka.com.co/pedido_colegio_sa.php?id_pedido='.$pedido['id'].'">aquí</a> para revisar el pedido.</p>';
			$mail_stock->AltBody = 'El pedido sin adopción #'.$pedido['id'].' para '.$_POST['colegio'].' incluye libros con existencia baja en bodega General.';

			$mail_stock->send();
		}
	} catch (Exception $e) {
		error_log('No se pudo enviar el aviso de stock bajo del pedido sin adopción #'.$pedido['id'].': '.$e->getMessage());
	}

	header("Location: ../pedido_colegio_sa.php?id_pedido=".$pedido["id"]."");

?>