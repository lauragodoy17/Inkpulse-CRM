<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header("Location: ../zonas.php");
    exit;
}

function generar_codigo_zona($bdd) {
    do {
        $codigo = (string) random_int(100000, 99999999);
        $existe = $bdd->prepare("SELECT id FROM zonas WHERE codigo = :codigo");
        $existe->execute([':codigo' => $codigo]);
    } while ($existe->rowCount() > 0);
    return $codigo;
}

$tipo_empresa   = $_POST["tipo_empresa"] ?? '';
$nombre_zona    = trim($_POST["nombre_zona"] ?? '');
$departamento   = $_POST["departamento"] ?? '';
$responsable_id = $_POST["responsable"] ?? '';

if ($nombre_zona === '' || $departamento === '' || $responsable_id === '') {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Completa todos los campos obligatorios.'));
    exit;
}

if ($tipo_empresa === 'eureka') {

    $zona_completa = 'EUREKA/' . mb_strtoupper($nombre_zona, 'UTF-8');

    $dup = $bdd->prepare("SELECT id FROM zonas WHERE zona = :zona");
    $dup->execute([':zona' => $zona_completa]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Ya existe una zona Eureka con ese nombre.'));
        exit;
    }

    $codigo = generar_codigo_zona($bdd);

    $req = $bdd->prepare("INSERT INTO zonas (departamento, codigo, zona) VALUES (:dep, :codigo, :zona)");
    $req->execute([':dep' => $departamento, ':codigo' => $codigo, ':zona' => $zona_completa]);

    $upd = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 3");
    $upd->execute([':codigo' => $codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Zona Eureka creada correctamente.'));
    exit;

} elseif ($tipo_empresa === 'distribuidor_nuevo') {

    $nombre_empresa = trim($_POST["nombre_empresa"] ?? '');
    if ($nombre_empresa === '') {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Escribe el nombre de la empresa distribuidora.'));
        exit;
    }

    $nombre_empresa_norm = mb_strtoupper($nombre_empresa, 'UTF-8');

    $dup = $bdd->prepare("SELECT id FROM zonas WHERE zona = :zona AND zona NOT LIKE '%Eureka%' AND zona NOT LIKE '%ALEJANDRO%'");
    $dup->execute([':zona' => $nombre_empresa_norm]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Ya existe una empresa distribuidora con ese nombre.'));
        exit;
    }

    $codigo = generar_codigo_zona($bdd);

    $req = $bdd->prepare("INSERT INTO zonas (departamento, codigo, zona) VALUES (:dep, :codigo, :zona)");
    $req->execute([':dep' => $departamento, ':codigo' => $codigo, ':zona' => $nombre_empresa_norm]);

    $req2 = $bdd->prepare("INSERT INTO sub_zonas (departamento, cod_zona, sub_zona) VALUES (:dep, :codigo, :sub)");
    $req2->execute([':dep' => $departamento, ':codigo' => $codigo, ':sub' => mb_strtoupper($nombre_zona, 'UTF-8')]);

    $upd = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 6");
    $upd->execute([':codigo' => $codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Empresa distribuidora y zona creadas correctamente.'));
    exit;

} elseif ($tipo_empresa === 'distribuidor_existente') {

    $empresa_codigo = $_POST["empresa_codigo"] ?? '';
    if ($empresa_codigo === '') {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Selecciona la empresa distribuidora.'));
        exit;
    }

    $chk = $bdd->prepare("SELECT id FROM zonas WHERE codigo = :codigo AND zona NOT LIKE '%Eureka%' AND zona NOT LIKE '%ALEJANDRO%'");
    $chk->execute([':codigo' => $empresa_codigo]);
    if ($chk->rowCount() == 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('La empresa seleccionada no es válida.'));
        exit;
    }

    $sub_norm = mb_strtoupper($nombre_zona, 'UTF-8');
    $dup = $bdd->prepare("SELECT id FROM sub_zonas WHERE cod_zona = :codigo AND sub_zona = :sub");
    $dup->execute([':codigo' => $empresa_codigo, ':sub' => $sub_norm]);
    if ($dup->rowCount() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Esta empresa ya tiene registrada una zona con ese nombre.'));
        exit;
    }

    $req = $bdd->prepare("INSERT INTO sub_zonas (departamento, cod_zona, sub_zona) VALUES (:dep, :codigo, :sub)");
    $req->execute([':dep' => $departamento, ':codigo' => $empresa_codigo, ':sub' => $sub_norm]);

    $upd = $bdd->prepare("UPDATE usuarios SET cod_zona = :codigo WHERE id = :id AND tipo = 6");
    $upd->execute([':codigo' => $empresa_codigo, ':id' => $responsable_id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Zona de distribuidor creada correctamente.'));
    exit;

} else {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Tipo de empresa inválido.'));
    exit;
}
