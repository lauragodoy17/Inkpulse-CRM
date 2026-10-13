<?php
// Cierre manual del presupuesto de un colegio en un periodo (tabla presupuestos_cierre).
// Con el presupuesto cerrado no se pueden añadir libros, borrarlos ni guardar cambios en la
// pestaña Presupuesto; solo el Administrador (tipo=1) puede cerrarlo o reabrirlo.

function asegurar_tabla_presupuestos_cierre(PDO $bdd) {
	try {
		$bdd->exec("CREATE TABLE IF NOT EXISTS presupuestos_cierre (
			id_colegio INT NOT NULL,
			id_periodo INT NOT NULL,
			cerrado TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id_colegio, id_periodo)
		)");
	} catch (Exception $e) {}
}

function presupuesto_cerrado(PDO $bdd, $id_colegio, $id_periodo) {
	asegurar_tabla_presupuestos_cierre($bdd);
	$req = $bdd->prepare("SELECT cerrado FROM presupuestos_cierre WHERE id_colegio = ? AND id_periodo = ? LIMIT 1");
	$req->execute([(int)$id_colegio, (int)$id_periodo]);
	return (int)$req->fetchColumn() === 1;
}
