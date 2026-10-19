<?php
require_once("aut.php");
// Conexion a la base de datos
require_once('../conexion/bdd.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../lib/PHPMailer/src/Exception.php';
require '../lib/PHPMailer/src/PHPMailer.php';
require '../lib/PHPMailer/src/SMTP.php';

	$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

	$req_periodo = $bdd->prepare($sql_periodo);
	$req_periodo->execute();
	$gp_periodo = $req_periodo->fetch();

	//$color = $_POST['color'];
	$sql_v = "UPDATE visitas SET observaciones='".$_POST["comentarios"]."',fecha='".date("Y-m-d H:i:s")."', efectiva='".$_POST["efectiva"]."', longitud='".$_POST["longitud"]."', latitud='".$_POST["latitud"]."' WHERE id_plan_trabajo='".$_POST["id_visita"]."'";

		
		
		$query_v = $bdd->prepare( $sql_v );
		if ($query_v == false) {
		 print_r($bdd->errorInfo());
		 die ('Erreur prepare');
		}
		$sth_v = $query_v->execute();
		if ($sth_v == false) {
		 print_r($query_v->errorInfo());
		 die ('Erreur execute');
		}

		$sth_p = "UPDATE plan_trabajo SET resultado='1', color='#008000' WHERE id='".$_POST["id_visita"]."'";
		
		
		$query_p = $bdd->prepare( $sth_p );
		if ($query_p == false) {
		 print_r($bdd->errorInfo());
		 die ('Erreur prepare');
		}
		$sth_p = $query_p->execute();
		if ($sth_p == false) {
		 print_r($query_p->errorInfo());
		 die ('Erreur execute');
		}

	// Próxima visita (pedido por la usuaria 2026-10-16): queda en Gestión y seguimiento del colegio con
	// recordatorio por correo un día antes. Ver includes/seguimientos_colegio.php.
	if (trim($_POST["proxima_visita"] ?? '') !== '') {
		require_once('../includes/seguimientos_colegio.php');
		try {
			$seg = seg_registrar_desde_visita($bdd, (int)$_POST["id_visita"], $_POST["proxima_visita"], $_POST["comentarios"] ?? '', (int)($_SESSION['id'] ?? 0));
			if (!$seg['ok']) error_log('Próxima visita plan #' . (int)$_POST["id_visita"] . ': ' . $seg['error']);
		} catch (Exception $e) {
			error_log('Próxima visita plan #' . (int)$_POST["id_visita"] . ': ' . $e->getMessage());
		}
	}

	$sql_p = "SELECT id FROM visitas WHERE id_plan_trabajo='".$_POST["id_visita"]."'";
												
	$req = $bdd->prepare($sql_p);
	$req->execute();
	$num = $req->rowCount();

	


	if ($_SESSION["tipo"] ==4) {

		$sql_c = "SELECT id_colegio FROM plan_trabajo WHERE id='".$_POST["id_visita"]."'";
												
		$req_c = $bdd->prepare($sql_c);
		$req_c->execute();
		$colegio = $req_c->fetch();

		if ($colegio["id_colegio"] > 2) {
			
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
    			];                                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_S	above                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

				//Recipients
				$mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
				$mail->addAddress("felipe.vargas@somoseureka.com.co", 'Usuario');     // Add a recipient


				$sql = "SELECT p.id, c.colegio, c.cod_zona, CONCAT(u.nombres, ' ',u.apellidos ) as fullname, v.observaciones, v.fecha, o.objetivo FROM plan_trabajo p JOIN colegios c ON c.id=p.id_colegio JOIN usuarios u ON u.id=p.id_promotor JOIN visitas v ON p.id=v.id_plan_trabajo JOIN objetivos o ON p.id_objetivo=o.id WHERE p.id='".$_POST["id_visita"]."'";

				$req = $bdd->prepare($sql);
				$req->execute();
				$pedido = $req->fetch();

				$sql = "SELECT correo FROM usuarios WHERE cod_zona='".$pedido["cod_zona"]."' ";

				$req = $bdd->prepare($sql);
				$req->execute();
				$vendedor = $req->fetch();
					  
				$mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
				$mail->addCC("".$vendedor["correo"]."");

				//$mail->addBCC('bcc@example.com');

					  
				// Content

				$mail->isHTML(true); 

		        // Set email format to HTML


				$mail->Subject = 'Ejecución plan de trabajo #'.$pedido["id"].'';

				$mail->Body    = '<p style="font-size: 17px;">'.$pedido["fullname"].' Ejecuto plan de trabajo en: '.$pedido["colegio"].' con el objetivo: '.$pedido["objetivo"].'. Fecha: '.$pedido["fecha"].' y registro las siguientes observaciones: '.$pedido["observaciones"].'<p>';

				$mail->AltBody = 'probandosss';

				$mail->CharSet = 'UTF-8';

				$mail->send();
				//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
			} catch (Exception $e) {

				echo "An error has occurred please try again: {$mail->ErrorInfo}";
			}
			
		}
		
		

	}

	


header('Location: '.$_SERVER['HTTP_REFERER']);

	
?>