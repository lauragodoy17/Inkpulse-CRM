<?php
	require_once("bdd.php");

	$sql = "SELECT url_produccion, token FROM apis_externas WHERE api = 'WO'";
  	$req = $bdd->prepare($sql); $req->execute();
  	$api_wo  = $req->fetch();
	// 1. URLs del servicio (separando entorno de pruebas y producción)
	//define('API_URL_SANDBOX', 'https://ejemplo.com');
	//define('API_URL_LIVE', 'https://ejemplo.com');

	// 2. Control de entorno (true = producción, false = pruebas/desarrollo)
	//define('API_PRODUCCION', false); 

	// 3. Elección automática de la URL según el entorno
	//define('API_URL_BASE', API_PRODUCCION ? API_URL_LIVE : API_URL_SANDBOX);
	define('API_URL_BASE', $api_wo['url_produccion']);

	// 4. Tus credenciales de acceso (El Token que requiere tu cabecera)
	// Reemplaza este valor simulado por tu token real de la API externa
	define('API_TOKEN', $api_wo['token']);
?>