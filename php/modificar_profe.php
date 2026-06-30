<?php

    include("../conexion/bdd.php");
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once("registrar_historial.php");

    $id_usuario_h = intval($_SESSION["id"] ?? 0);

    // Fetch old values and id_colegio before UPDATE
    $req_old_profe = $bdd->prepare("SELECT id_colegio, nombre, apellido, telefono, email, area, nivel_academico FROM trabajadores_colegios WHERE id=:id");
    $req_old_profe->execute([':id' => $_POST['id_profe']]);
    $old_profe = $req_old_profe->fetch();

    $niveles_validos = ['Bachillerato', 'Primaria', 'Preescolar'];
    $nivel_nuevo = in_array($_POST['nivel_profe'] ?? '', $niveles_validos) ? $_POST['nivel_profe'] : null;

    $sql = "UPDATE trabajadores_colegios SET nombre='{$_POST['nombre_profe']}', apellido='{$_POST['apellido_profe']}', telefono='{$_POST['telefono_profe']}', email='{$_POST['correo_profe']}', area='{$_POST['area_profe']}', nivel_academico=".($nivel_nuevo ? "'$nivel_nuevo'" : "NULL")."  WHERE id='{$_POST['id_profe']}'";

    $req = $bdd->prepare($sql);
    $req->execute();

    // Historial
    if ($old_profe) {
        $persona = trim(($old_profe['nombre'] ?? '') . ' ' . ($old_profe['apellido'] ?? ''));

        // Lookup area (materia) names
        $stmt_mat = $bdd->prepare("SELECT materia FROM materias WHERE id=:id");
        $stmt_mat->execute([':id' => $old_profe['area']]);
        $r = $stmt_mat->fetch();
        $area_old_name = $r ? $r['materia'] : (string)$old_profe['area'];

        $stmt_mat->execute([':id' => $_POST['area_profe']]);
        $r = $stmt_mat->fetch();
        $area_new_name = $r ? $r['materia'] : (string)$_POST['area_profe'];

        $campos_profe = [
            'Nombre'           => [$old_profe['nombre'],            $_POST['nombre_profe']],
            'Apellido'         => [$old_profe['apellido'],          $_POST['apellido_profe']],
            'Teléfono'         => [$old_profe['telefono'],          $_POST['telefono_profe']],
            'Correo'           => [$old_profe['email'],             $_POST['correo_profe']],
            'Área'             => [$area_old_name,                   $area_new_name],
            'Nivel académico'  => [$old_profe['nivel_academico'],   $nivel_nuevo],
        ];
        foreach ($campos_profe as $nombre_campo => $vals) {
            if ((string)$vals[0] !== (string)$vals[1]) {
                registrar_historial($bdd, $old_profe['id_colegio'], $id_usuario_h, 'Información de contacto',
                    "$nombre_campo docente ($persona)", $vals[0], $vals[1]);
            }
        }
    }

    header('Location: ../colegio.php?codigo='.$_POST["cod_colegio"].'&periodo='.$_POST["periodo"].'&tab=info_contac');

?>
