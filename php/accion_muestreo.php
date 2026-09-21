<?php
  require_once("../php/aut.php");
  include("../conexion/bdd.php");
  require_once("../includes/historial_estados.php");

  crear_tabla_historial_estados($bdd);
  $id_usuario_accion = intval($_SESSION['id'] ?? 0);

  if (isset($_GET["rechazar"])) {
    $id_rechazar = intval($_GET["rechazar"]);
    $req = $bdd->prepare("UPDATE muestreos SET estado='3' WHERE id=?");
    $req->execute([$id_rechazar]);
    registrar_historial_estado($bdd, 'muestreos', $id_rechazar, 3, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=2&ink_status=ok&ink_msg=".urlencode('Muestreo rechazado correctamente.'));

  } elseif (isset($_GET["aprobar"])) {
    $sql = "UPDATE muestreos SET estado='2', observaciones='".$_GET["observaciones"]."' WHERE id='".$_GET["aprobar"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    registrar_historial_estado($bdd, 'muestreos', $_GET["aprobar"], 2, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=2&ink_status=ok&ink_msg=".urlencode('Muestreo aprobado correctamente.'));

  }elseif (isset($_GET["procesar"])) {
    $sql = "UPDATE muestreos SET estado='5' WHERE id='".$_GET["procesar"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    registrar_historial_estado($bdd, 'muestreos', $_GET["procesar"], 5, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=3&ink_status=ok&ink_msg=".urlencode('Muestreo cambiado a procesando.'));

  }elseif (isset($_GET["facturacion"])) {
    $sql = "UPDATE muestreos SET estado='6' WHERE id='".$_GET["facturacion"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    registrar_historial_estado($bdd, 'muestreos', $_GET["facturacion"], 6, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=6&ink_status=ok&ink_msg=".urlencode('Muestreo pasado a facturación.'));

  }elseif (isset($_GET["despacho"])) {
    $sql = "UPDATE muestreos SET estado='7' WHERE id='".$_GET["despacho"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    registrar_historial_estado($bdd, 'muestreos', $_GET["despacho"], 7, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=8&ink_status=ok&ink_msg=".urlencode('Muestreo pasado a en despacho.'));

  }else {
    $sql = "UPDATE muestreos SET estado='4' WHERE id='".$_GET["entregado"]."'";
    $req = $bdd->prepare($sql);
    $req->execute();
    registrar_historial_estado($bdd, 'muestreos', $_GET["entregado"], 4, $id_usuario_accion);
    header("location: ../lista_muestreo.php?tp=8&ink_status=ok&ink_msg=".urlencode('Muestreo despachado correctamente.'));
  }
?>
