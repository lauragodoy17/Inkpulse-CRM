<?php
// Cliente (catálogo World Office, tabla `clientes`) de un pedido sin adopción:
// pedidos2.cliente = clientes.id, igual que pedidos.cliente. Es lo que se
// imprime en la columna Cliente de la planilla de procesamiento SA.

function asegurar_columna_cliente_pedidos2(PDO $bdd) {
	try {
		$bdd->exec("ALTER TABLE pedidos2 ADD COLUMN cliente INT NULL");
	} catch (Exception $e) {}
}
