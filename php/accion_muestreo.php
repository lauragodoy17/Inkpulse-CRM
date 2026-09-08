<?php
  include("../conexion/bdd.php");

  if (isset($_GET["rechazar"])) {
    $sql = "UPDATE muestreos SET estado='3' WHERE id='".$_GET["rechazar"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    header("location: ../lista_muestreo.php?tp=2&ink_status=ok&ink_msg=".urlencode('Muestreo rechazado correctamente.'));

  } elseif (isset($_GET["aprobar"])) {
    $sql = "UPDATE muestreos SET estado='2', observaciones='".$_GET["observaciones"]."' WHERE id='".$_GET["aprobar"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    header("location: ../lista_muestreo.php?tp=2&ink_status=ok&ink_msg=".urlencode('Muestreo aprobado correctamente.'));

  }elseif (isset($_GET["procesar"])) {
    $sql = "UPDATE muestreos SET estado='5' WHERE id='".$_GET["procesar"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    header("location: ../lista_muestreo.php?tp=3&ink_status=ok&ink_msg=".urlencode('Muestreo cambiado a procesando.'));

  }elseif (isset($_GET["facturacion"])) {
    $sql = "UPDATE muestreos SET estado='6' WHERE id='".$_GET["facturacion"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    header("location: ../lista_muestreo.php?tp=6&ink_status=ok&ink_msg=".urlencode('Muestreo pasado a facturación.'));

  }else {
    $sql = "UPDATE muestreos SET estado='4' WHERE id='".$_GET["entregado"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    header("location: ../lista_muestreo.php?tp=7&ink_status=ok&ink_msg=".urlencode('Muestreo despachado correctamente.'));
  }
?>
