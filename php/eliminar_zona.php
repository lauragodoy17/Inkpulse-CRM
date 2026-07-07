<?php
require_once("aut.php");
require_once("../conexion/bdd.php");

if (($_SESSION["tipo"] ?? null) != 1) {
    header("Location: ../zonas.php");
    exit;
}

$tipo   = $_POST["tipo"] ?? '';
$id     = $_POST["id"] ?? '';
$codigo = $_POST["codigo"] ?? '';

if ($id === '' || $codigo === '') {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Solicitud inválida.'));
    exit;
}

if ($tipo === 'eureka' || $tipo === 'empresa') {

    $chk = $bdd->prepare("SELECT COUNT(*) FROM colegios WHERE cod_zona = :codigo OR zona_madre = :codigo");
    $chk->execute([':codigo' => $codigo]);
    if ($chk->fetchColumn() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('No se puede eliminar: hay colegios asignados a esta zona. Reasígnalos primero.'));
        exit;
    }

    if ($tipo === 'empresa') {
        $chkSub = $bdd->prepare("SELECT COUNT(*) FROM sub_zonas WHERE cod_zona = :codigo");
        $chkSub->execute([':codigo' => $codigo]);
        if ($chkSub->fetchColumn() > 0) {
            header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('No se puede eliminar: la empresa todavía tiene zonas registradas.'));
            exit;
        }
    }

    $del = $bdd->prepare("DELETE FROM zonas WHERE id = :id");
    $del->execute([':id' => $id]);

    $tipoUsuario = $tipo === 'eureka' ? 3 : 6;
    $clr = $bdd->prepare("UPDATE usuarios SET cod_zona = NULL WHERE cod_zona = :codigo AND tipo = :tipo_usuario");
    $clr->execute([':codigo' => $codigo, ':tipo_usuario' => $tipoUsuario]);

    $msg = $tipo === 'eureka' ? 'Zona Eureka eliminada correctamente.' : 'Empresa distribuidora eliminada correctamente.';
    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode($msg));
    exit;

} elseif ($tipo === 'subzona') {

    $chk = $bdd->prepare("SELECT COUNT(*) FROM colegios WHERE sub_zona = :id");
    $chk->execute([':id' => $id]);
    if ($chk->fetchColumn() > 0) {
        header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('No se puede eliminar: hay colegios asignados a esta zona. Reasígnalos primero.'));
        exit;
    }

    $del = $bdd->prepare("DELETE FROM sub_zonas WHERE id = :id");
    $del->execute([':id' => $id]);

    header("Location: ../zonas.php?ink_status=ok&ink_msg=" . urlencode('Zona eliminada correctamente.'));
    exit;

} else {
    header("Location: ../zonas.php?ink_status=error&ink_msg=" . urlencode('Tipo de zona inválido.'));
    exit;
}
