<?php
// Cierre manual de la adopción de un colegio en un periodo (recursos.cerrado).
// Con la adopción cerrada no se pueden añadir libros ni guardar cambios en la
// pestaña Adopciones; solo el Administrador (tipo=1) puede cerrarla o reabrirla.

function asegurar_columna_cerrado(PDO $bdd) {
	try {
		$bdd->exec("ALTER TABLE recursos ADD COLUMN cerrado TINYINT(1) NOT NULL DEFAULT 0");
	} catch (Exception $e) {}
}

function adopcion_cerrada(PDO $bdd, $id_colegio, $id_periodo) {
	asegurar_columna_cerrado($bdd);
	$req = $bdd->prepare("SELECT cerrado FROM recursos WHERE id_colegio = ? AND id_periodo = ? LIMIT 1");
	$req->execute([(int)$id_colegio, (int)$id_periodo]);
	return (int)$req->fetchColumn() === 1;
}
