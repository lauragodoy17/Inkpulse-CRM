<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header("Location: ../zonas.php");
    exit;
}

$tipo           = $_POST["tipo"] ?? '';
$id             = $_POST["id"] ?? '';
$codigo         = $_POST["codigo"] ?? '';
$nombre_zona    = trim($_POST["nombre_zona"] ?? '');
$nombre_empresa = trim($_POST["nombre_empresa"] ?? '');
$empresa_id     = $_POST["empresa_id"] ?? '';
$departamento   = $_POST["departamento"] ?? '';
$responsable_id = $_POST["responsable"] ?? '';

if ($id === '' || $codigo === '' || $nombre_zona === '' || $departamento === '' || $responsable_id === '') {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Completa todos los campos obligatorios.'));
    exit;
}

if ($tipo === 'eureka') {

    $zona_completa = 'EUREKA/' . mb_strtoupper($nombre_zona, 'UTF-8');

    $dup = $bdd->prepare("SELECT id FROM zonas WHERE zona = :zona AND id != :id");
    $dup->execute([':zona' => $zona_completa, ':id' => $id]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Ya existe una zona Eureka con ese nombre.'));
        exit;
    }

    $upd = $bdd->prepare("UPDATE zonas SET departamento = :dep, zona = :zona WHERE id = :id");
    $upd->execute([':dep' => $departamento, ':zona' => $zona_completa, ':id' => $id]);

    $clr = $bdd->prepare("UPDATE usuarios SET cod_zona = NULL WHERE cod_zona = :codigo AND tipo = 3");
    $clr->execute([':codigo' => $codigo]);

    $asg = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 3");
    $asg->execute([':codigo' => $codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Zona Eureka actualizada correctamente.'));
    exit;

} elseif ($tipo === 'empresa') {

    $nombre_empresa_norm = mb_strtoupper($nombre_zona, 'UTF-8');

    $dup = $bdd->prepare("SELECT id FROM zonas WHERE zona = :zona AND zona NOT LIKE '%Eureka%' AND zona NOT LIKE '%ALEJANDRO%' AND id != :id");
    $dup->execute([':zona' => $nombre_empresa_norm, ':id' => $id]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Ya existe una empresa distribuidora con ese nombre.'));
        exit;
    }

    $upd = $bdd->prepare("UPDATE zonas SET departamento = :dep, zona = :zona WHERE id = :id");
    $upd->execute([':dep' => $departamento, ':zona' => $nombre_empresa_norm, ':id' => $id]);

    $clr = $bdd->prepare("UPDATE usuarios SET cod_zona = NULL WHERE cod_zona = :codigo AND tipo = 6");
    $clr->execute([':codigo' => $codigo]);

    $asg = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 6");
    $asg->execute([':codigo' => $codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Empresa distribuidora actualizada correctamente.'));
    exit;

} elseif ($tipo === 'subzona') {

    if ($nombre_empresa === '' || $empresa_id === '') {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Escribe el nombre de la empresa distribuidora.'));
        exit;
    }

    $sub_norm = mb_strtoupper($nombre_zona, 'UTF-8');
    $nombre_empresa_norm = mb_strtoupper($nombre_empresa, 'UTF-8');

    $dup = $bdd->prepare("SELECT id FROM sub_zonas WHERE cod_zona = :codigo AND sub_zona = :sub AND id != :id");
    $dup->execute([':codigo' => $codigo, ':sub' => $sub_norm, ':id' => $id]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Esta empresa ya tiene registrada una zona con ese nombre.'));
        exit;
    }

    $dupEmp = $bdd->prepare("SELECT id FROM zonas WHERE zona = :zona AND zona NOT LIKE '%Eureka%' AND zona NOT LIKE '%ALEJANDRO%' AND id != :id");
    $dupEmp->execute([':zona' => $nombre_empresa_norm, ':id' => $empresa_id]);
    if ($dupEmp->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Ya existe una empresa distribuidora con ese nombre.'));
        exit;
    }

    $upd = $bdd->prepare("UPDATE sub_zonas SET departamento = :dep, sub_zona = :sub WHERE id = :id");
    $upd->execute([':dep' => $departamento, ':sub' => $sub_norm, ':id' => $id]);

    $updEmp = $bdd->prepare("UPDATE zonas SET zona = :zona WHERE id = :id");
    $updEmp->execute([':zona' => $nombre_empresa_norm, ':id' => $empresa_id]);

    $clr = $bdd->prepare("UPDATE usuarios SET cod_zona = NULL WHERE cod_zona = :codigo AND tipo = 6");
    $clr->execute([':codigo' => $codigo]);

    $asg = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 6");
    $asg->execute([':codigo' => $codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Zona actualizada correctamente.'));
    exit;

} else {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Tipo de zona inválido.'));
    exit;
}
