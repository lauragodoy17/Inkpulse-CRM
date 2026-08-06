<?php
	require_once("bdd.php");

	$sql = "SELECT url_produccion, customer_key, customer_secret FROM apis_externas WHERE api = 'WCN'";
  	$req = $bdd->prepare($sql); $req->execute();
  	$api_wc  = $req->fetch();
	

	// 1. URL base de tu tienda (Asegúrate de incluir /wp-json/wc/v3/)
	define('WC_URL_BASE', $api_wc['url_produccion']);

	// 2. Credenciales de la API de WooCommerce (Generadas en WordPress)
	define('WC_CUSTOMER_KEY', $api_wc['customer_key']);
	define('WC_CUSTOMER_SECRET', $api_wc['customer_secret']);

	// 3. Control de entorno (Opcional, por si tienes una tienda de pruebas)
	//define('WC_PRODUCCION', false); 

?>