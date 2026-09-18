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

	$sql_periodo="SELECT id FROM periodos ORDER BY id DESC";

	$req_periodo = $bdd->prepare($sql_periodo);
	$req_periodo->execute();
	$gp_periodo = $req_periodo->fetch();

	$colegio = $_POST['cole'];

	// Manejo del archivo adjunto
	$archivo_path = '';
	if (isset($_FILES['archivo_muestreo']) && $_FILES['archivo_muestreo']['error'] === UPLOAD_ERR_OK) {
		$uploads_dir = dirname(__DIR__) . '/uploads/muestreos/';
		if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
		$filename = str_replace(['/', '\\', "\0"], '', $_FILES['archivo_muestreo']['name']);
		if (move_uploaded_file($_FILES['archivo_muestreo']['tmp_name'], $uploads_dir . $filename)) {
			$archivo_path = 'uploads/muestreos/' . $filename;
		}
	}

	$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	try {

	do {
	    $caracteres = "1234567890"; //posibles caracteres a usar
	    $numerodeletras=10; //numero de letras para generar el texto
	    $cod_pedido =""; //variable para almacenar la cadena generada
	    for($i=0;$i<$numerodeletras;$i++)
	    {
	        $cod_pedido .=substr($caracteres,rand(0,strlen($caracteres)),1); /*Extraemos 1 caracter de los caracteres 
	         entre el rango 0 a Numero de letras que tiene la cadena */
	    }
	    $sql = "SELECT codigo FROM muestreos";

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

		if (empty($libro) || strpos($libro, '/') === false) continue;

		list($id_libro,$cantidad) = explode("/", $libro);
				
		if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();

			
				if ($_POST['tp']!=2) {

					$sql_p = "INSERT INTO libros_muestreos(cod_muestreo,id_libro,cantidad) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."')";
				}else{
					$sql_p = "INSERT INTO libros_muestreos_e(cod_muestreo,id_libro,cantidad) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."')";
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

		}
			

	}

	foreach ($_POST['pri_sec'] ?? [] as $index => $id_libro) {
    	$cantidad = $_POST['cantidad_pri_sec'][$index];

    	if ($cantidad > 0) {
				
			$sql_g = "SELECT id_grado FROM libros WHERE id='".$id_libro."'";
			$req_g = $bdd->prepare($sql_g);
			$req_g->execute();

			$grado = $req_g->fetch();

				if ($_POST['tp']!=2) {

					$sql_p = "INSERT INTO libros_muestreos(cod_muestreo,id_libro,cantidad) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."')";
				}else{
					$sql_p = "INSERT INTO libros_muestreos_e(cod_muestreo,id_libro,cantidad) VALUES('".$cod_pedido."','".$id_libro."','".$cantidad."')";
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

		}
	}

	$_POST["observaciones"]=str_replace("'", " ", $_POST["observaciones"]);

	if ($_POST['tp']!=2) {

		$sql_p2 = "INSERT INTO muestreos(codigo,id_periodo,id_colegio,id_usuario,observaciones,estado,archivo) VALUES('".$cod_pedido."','".$gp_periodo["id"]."','".$colegio."','".$_SESSION["id"]."','".$_POST["observaciones"]."','1','".$archivo_path."')";
	}else{

		$sql_p2 = "INSERT INTO muestreos_e(codigo,id_periodo,id_colegio,id_usuario,observaciones,estado,archivo) VALUES('".$cod_pedido."','".$gp_periodo["id"]."','".$colegio."','".$_SESSION["id"]."','".$_POST["observaciones"]."','1','".$archivo_path."')";
	}
				
				
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

	$sq_l3 = "SELECT colegio FROM colegios WHERE id='".$colegio."'";
														
	$req_l3 = $bdd->prepare($sq_l3);
	$req_l3->execute();
	$cole = $req_l3->fetch();

	
	if ($_POST['tp']!=2) {
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
			$mail->addAddress("felipe.vargas@somoseureka.com.co", 'Usuario');     // Add a recipient
				  
			$mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
			$mail->addCC("pedidos@somoseureka.com.co");
			$mail->addCC("giovanni.barbosa@somoseureka.com.co");
			//$mail->addBCC('bcc@example.com');

				  
			// Content
			$mail->isHTML(true);                                  // Set email format to HTML

			$sql = "SELECT id FROM muestreos WHERE codigo='".$cod_pedido."'";

			$req = $bdd->prepare($sql);
			$req->execute();
			$pedido = $req->fetch();

			$mail->Subject = 'Solicitud de muestras #'.$pedido["id"].'';

			$mail->Body    = '<p style="font-size: 17px;">'.$promo["promotor"].' hizo la solicitud de muestras #'.$pedido["id"].' para: '.$cole["colegio"].'. Haz clic <a href="https://crm.somoseureka.com.co/muestreo_colegio.php?id_muestreo='.$pedido['id'].' ">aquí</a> para revisarlo<p>';

			$mail->AltBody = 'probandosss';

			$mail->CharSet = 'UTF-8';

			$mail->send();
				//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
			} catch (Exception $e) {

				echo "An error has occurred please try again: {$mail->ErrorInfo}";
		}
	}



	$_ref = $_SERVER['HTTP_REFERER'] ?? '../solicitar_muestreo.php?tp=1';
	$_sep = strpos($_ref, '?') !== false ? '&' : '?';
	header('Location: ' . $_ref . $_sep . 'status=ok');
	exit;

	} catch (Exception $e) {
		$_ref = $_SERVER['HTTP_REFERER'] ?? '../solicitar_muestreo.php?tp=1';
		$_sep = strpos($_ref, '?') !== false ? '&' : '?';
		header('Location: ' . $_ref . $_sep . 'status=error');
		exit;
	}

?>