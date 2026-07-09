<?php
ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
//error_reporting(-1);
require_once("../php/aut.php");
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
	
	$colegio = $_POST['cole'];
	$profesor = $_POST['profesor'];
	$objetivo = ($_POST['objetivo'] === 'otro') ? '0' : $_POST['objetivo'];
	$otro_objetivo = ($_POST['objetivo'] === 'otro')
	    ? str_replace(["'", '"'], ' ', trim($_POST['otro_objetivo_txt'] ?? ''))
	    : '';
	$start = $_POST['start'];
	$end = $_POST['end'];


do {
	$caracteres = "1234567890"; //posibles caracteres a usar
	$numerodeletras=10; //numero de letras para generar el texto
	$cod_plan =""; //variable para almacenar la cadena generada
	for($i=0;$i<$numerodeletras;$i++)
	    {
	        $cod_plan .=substr($caracteres,rand(0,strlen($caracteres)),1); /*Extraemos 1 caracter de los caracteres 
	        entre el rango 0 a Numero de letras que tiene la cadena */
	    }
	$sql = "SELECT codigo FROM plan_trabajo";

	$req = $bdd->prepare($sql);
	$req->execute();
	$codigos = $req->fetchAll();

	foreach($codigos as $codigo) {
		if ($cod_plan !="") {
			if (($codigo["codigo"]==$cod_plan)) $cod_plan="";
		}
	}
	   
} while ($cod_plan=="");

if (isset($_POST["descripcion"])) {
	$_POST["descripcion"]= str_replace(["'", '"'], ' ', $_POST["descripcion"]);
}else{
	$_POST["descripcion"]='';
}


$_POST["participantes"] = array_filter($_POST["participantes"] ?? [], fn($v) => $v !== 'otro');
$_POST["participantes"] [] =$_SESSION['id'];
$otro_participante = str_replace(["'", '"'], ' ', trim($_POST['otro_participante'] ?? ''));

if(isset($_POST["oficina"])) {
	
	$sql = "INSERT INTO plan_trabajo(codigo,id_periodo,id_promotor,id_colegio,resultado,color,start,end,agendamiento,descripcion) values ('$cod_plan','".$gp_periodo["id"]."', '".$_SESSION['id']."','".$_POST["oficina"]."','0','#4c00ff', '$start', '$end', '5','".$_POST["descripcion"]."')";
	$query = $bdd->prepare( $sql );
	if ($query == false) {
	 print_r($bdd->errorInfo());
	 die ('Erreur prepare');
	}
	$sth = $query->execute();
	if ($sth == false) {
	 print_r($query->errorInfo());
	 die ('Erreur execute');
	}

}

elseif(isset($_POST["casa"])) {

	$sql = "INSERT INTO plan_trabajo(codigo,id_periodo,id_promotor,id_colegio,resultado,color,start,end,agendamiento,descripcion) values ('$cod_plan','".$gp_periodo["id"]."', '".$_SESSION['id']."','".$_POST["casa"]."','0','#4c00ff', '$start', '$end', '5','".$_POST["descripcion"]."')";
	$query = $bdd->prepare( $sql );
	if ($query == false) {
	 print_r($bdd->errorInfo());
	 die ('Erreur prepare');
	}
	$sth = $query->execute();
	if ($sth == false) {
	 print_r($query->errorInfo());
	 die ('Erreur execute');
	}

}

elseif(isset($_POST["otro_chk"])) {

	$otro_lugar = trim($_POST['otro_lugar_txt'] ?? '');
	$otro_lugar = str_replace(["'", '"'], ' ', $otro_lugar);

	$sql = "INSERT INTO plan_trabajo(codigo,id_periodo,id_promotor,id_colegio,otro_lugar,resultado,color,start,end,agendamiento,descripcion) values ('$cod_plan','".$gp_periodo["id"]."', '".$_SESSION['id']."','0','".$otro_lugar."','0','#4c00ff', '$start', '$end', '5','".$_POST["descripcion"]."')";
	$query = $bdd->prepare( $sql );
	if ($query == false) {
	 print_r($bdd->errorInfo());
	 die ('Erreur prepare');
	}
	$sth = $query->execute();
	if ($sth == false) {
	 print_r($query->errorInfo());
	 die ('Erreur execute');
	}

}

else {



	
	//$color = $_POST['color'];

	$id_profesor = intval($_POST["profe"]);


	
	



	foreach ($_POST["participantes"] as $participante) {

		if ($participante == $_SESSION["id"]) {

			$sql = "INSERT INTO plan_trabajo(codigo,id_periodo,id_promotor,id_colegio,id_profesor,id_objetivo,otro_objetivo,otro_participante,resultado,color,start,end,agendamiento,descripcion) values ('$cod_plan','".$gp_periodo["id"]."', '".$participante."', '$colegio', '$id_profesor', '$objetivo','$otro_objetivo','$otro_participante','0','#4c00ff', '$start', '$end', '5', '".$_POST["descripcion"]."')";

			$query = $bdd->prepare( $sql );
			if ($query == false) {
				print_r($bdd->errorInfo());
				die ('Erreur prepare');
			}
			$sth = $query->execute();
			if ($sth == false) {
				print_r($query->errorInfo());
				die ('Erreur execute');
			}

		}else{

			$sql = "INSERT INTO plan_trabajo(codigo,id_periodo,id_promotor,id_colegio,id_profesor,id_objetivo,otro_objetivo,otro_participante,resultado,color,start,end,agendamiento,descripcion) values ('$cod_plan','".$gp_periodo["id"]."', '".$participante."', '$colegio', '$id_profesor', '$objetivo','$otro_objetivo','$otro_participante','0','#4c00ff', '$start', '$end', '4', '".$_POST["descripcion"]."')";

			$query = $bdd->prepare( $sql );
			if ($query == false) {
				print_r($bdd->errorInfo());
				die ('Erreur prepare');
			}
			$sth = $query->execute();
			if ($sth == false) {
				print_r($query->errorInfo());
				die ('Erreur execute');
			}

			$sql_noti = "INSERT INTO notificaciones(id_periodo,id_usuario,cod_plan,id_tipo_notifi,visible) VALUES('".$gp_periodo["id"]."','".$participante."','$cod_plan','4','1')";

			$query_noti = $bdd->prepare( $sql_noti );
			if ($query_noti == false) {
				print_r($bdd->errorInfo());
				die ('Erreur prepare');
			}

			$sth_noti = $query_noti->execute();
			if ($sth_noti == false) {
				print_r($query_noti->errorInfo());
				die ('Erreur execute');
			}

			$sq_l2 = "SELECT CONCAT(nombres, ' ', apellidos) AS promotor FROM usuarios WHERE id='".$_SESSION["id"]."'";
														
			$req_l2 = $bdd->prepare($sq_l2);
			$req_l2->execute();
			$promo = $req_l2->fetch();

			$sq_lc = "SELECT correo FROM usuarios WHERE id='".$participante."'";
														
			$req_lc = $bdd->prepare($sq_lc);
			$req_lc->execute();
			$correo = $req_lc->fetch();

			$sql = "SELECT colegio FROM colegios WHERE id='".$colegio."'";

			$req = $bdd->prepare($sql);
			$req->execute();
			$cole = $req->fetch();

			$mail = new PHPMailer(true);

			try {

				//Server settings
				//$mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;                      // OFF verbose debug output
				$mail->isSMTP();                                            // Send using SMTP
				$mail->Host       = 'mail.eurekalibros.com.co';                    // Set the SMTP server to send through
				$mail->SMTPAuth   = true;                                   // Enable SMTP authentication
				$mail->SMTPAutoTLS = false; 
				$mail->Username   = 'crm@eurekalibros.com.co';                     // SMTP username
				$mail->Password   = 'cRm14356$';                              // SMTP password
				//$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
				$mail->Port       = 587;                                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_S	above                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

				//Recipients
				$mail->setFrom('crm@eurekalibros.com.co', 'CRM Eureka');
				$mail->addAddress($correo["correo"], 'Usuario');     // Add a recipient
						  
				$mail->addReplyTo('crm@eurekalibros.com.co', 'CRM Eureka');

				//$mail->addBCC('bcc@example.com');

				  
				// Content
				$mail->isHTML(true);                                  // Set email format to HTML
				$mail->Subject = 'Invitación a plan de trabajo';

				$mail->Body    = '<p style="font-size: 17px;">'.$promo["promotor"].' le ha hecho una invitación a plan de trabajo al colegio: '.$cole["colegio"].' <p>';

				$mail->AltBody = 'probandosss';

				$mail->CharSet = 'UTF-8';

				$mail->send();
				//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
			} catch (Exception $e) {

				echo "An error has occurred please try again: {$mail->ErrorInfo}";
			}


		}
		
		
		
	}
}
	

if (isset($_POST['cod_cole'])) {

	header('Location: ../colegio.php?codigo='.$_POST["cod_cole"].'&periodo='.$gp_periodo["id"].'');

}else{

	header('Location: '.$_SERVER['HTTP_REFERER']);

}
	
?>
