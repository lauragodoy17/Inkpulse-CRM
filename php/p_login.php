<?php
	require_once('../conexion/bdd.php');
	$pass=$_POST['clave'];
	$pass=md5($pass);
	
	$sql = "SELECT * FROM usuarios WHERE correo='".$_POST["correo"]."'";
	$req = $bdd->prepare($sql);
	$req->execute();
	$num=$req->rowCount();
	if ($num !== 1) echo "<script>alert('Usuario no existe');window.location='../login.php';</script>";
	$usuario = $req->fetch();
		
	if($pass==$usuario['clave']){

		if ($usuario['act'] != 1) {
			echo "<script>alert('Tu usuario está inactivo. Contacta al administrador.');window.location='../login.php';</script>";
		} else {
			session_start();
	    	// inicio la sesión
	    	$_SESSION["autentificado"]= "SI";
	    	//defino la sesión que demuestra que el usuario está autorizado
	    	$_SESSION["ultimoAcceso"]= date("Y-n-j H:i:s");
			$_SESSION['id']=$usuario['id'];
			$_SESSION['tipo']=$usuario['tipo'];
			$_SESSION['zona']=$usuario['cod_zona'];
			$_SESSION['pais']=$usuario['id_pais'];

			if ($_POST['id_pedido']!="") {
				header("location:../pedido_colegio.php?id_pedido=".$_POST['id_pedido']."");

			}elseif ($_POST['id_pedido_dist']!="") {
				header("location:../pedido_colegio2.php?id_pedido=".$_POST['id_pedido_dist']."");
			}elseif ($_POST['id_muestreo']!="") {
				header("location:../muestreo_colegio.php?id_pedido=".$_POST['id_muestreo']."");
			}elseif ($_POST['opd']!="") {
				header("location:../opd_solicitada.php?opd=".$_POST['opd']."");
			}
			else{

				header("location:../index.php");

			}
		}

	}
	else echo "<script>alert('Clave Invalida');window.location='../login.php';</script>";
?>