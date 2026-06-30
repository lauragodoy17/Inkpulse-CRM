<?php

    include("../conexion/bdd.php");
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once("registrar_historial.php");

    $id_usuario_h = intval($_SESSION["id"] ?? 0);

    foreach ($_POST["profe"] as $profes => $profe) {

        if ($profe == "") continue;

        $parts = explode("/", $profe);
        if (count($parts) < 5) continue;

        $nombre   = $parts[0];
        $apellido = $parts[1];
        $correo   = $parts[2];
        $telefono = $parts[3];
        $area     = $parts[4];
        $nivel    = isset($parts[5]) ? $parts[5] : '';

        $niveles_validos = ['Bachillerato', 'Primaria', 'Preescolar'];
        if (!in_array($nivel, $niveles_validos)) $nivel = null;

        if ($profe !="") {

            $sql_p = "INSERT INTO trabajadores_colegios(id_colegio, nombre, apellido, email, telefono, area, cargo, nivel_academico) VALUES('{$_POST['id_colegio']}', '{$nombre}','{$apellido}','{$correo}','{$telefono}','{$area}', '6', ".($nivel ? "'$nivel'" : "NULL").")";


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

            registrar_historial($bdd, $_POST['id_colegio'], $id_usuario_h, 'Información de contacto',
                'Nuevo docente', '', "$nombre $apellido");
        }


    }

    header('Location: ../colegio.php?codigo='.$_POST["cod_colegio"].'&periodo='.$_POST["periodo"].'&tab=info_contac');

?>
