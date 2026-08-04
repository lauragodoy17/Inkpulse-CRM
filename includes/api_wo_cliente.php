<?php
/**
 * /includes/api_cliente.php
 * Conector base para realizar peticiones HTTP mediante cURL y utilidades globales.
 */

// 1. Cargamos de forma segura la configuración usando la ruta absoluta del archivo actual (__DIR__)
require_once("../conexion/api_wo_config.php");

/**
 * Realiza una petición HTTP a la API externa de World Office.
 *
 * @param string $endpoint El endpoint al que apuntar (ej: 'usuarios', 'productos/1').
 * @param string $metodo El método HTTP de la petición ('GET', 'POST', 'PUT', 'DELETE').
 * @param array|null $datos Los datos que se enviarán en el cuerpo (se transformarán a JSON).
 * @return array La respuesta de la API decodificada como un array de PHP.
 */
function hacer_peticion_api($endpoint, $metodo = 'GET', $datos = null) {
    // Construimos la URL completa uniendo la base y el endpoint
    $url_completa = API_URL_BASE . $endpoint;
    
    // Inicializamos cURL
    $ch = curl_init($url_completa);
    
    // Configuramos las cabeceras exactas que solicita World Office
    $cabeceras = [
        'Content-Type: application/json',
        'Authorization: ' . API_TOKEN
    ];
    
    // Configuración estructural base de cURL
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devuelve la respuesta como string en vez de imprimirla
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras); // Inyecta los headers configurados
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($metodo)); // Define el método (GET, POST, etc.)
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Tiempo límite de espera de 30 segundos
    curl_setopt($ch, CURLOPT_ENCODING, ''); // Maneja la compresión de datos nativamente
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Sigue redirecciones si la API cambia de servidor
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Fuerza el protocolo HTTP requerido
    
    // Si la petición envía datos, los transformamos a formato JSON string
    if ($datos !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos));
    }
    
    // Ejecutamos la petición de red
    $respuesta = curl_exec($ch);
    
    // Capturamos posibles errores del canal de comunicación (ej: sin internet, DNS fallido)
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return [
            'status' => 'error',
            'mensaje_interno' => 'Error de conexión cURL: ' . $error_msg
        ];
    }
    
    // Cerramos la conexión cURL
    curl_close($ch);
    
    // Decodificamos el JSON recibido de la API externa a un array asociativo de PHP
    $resultado = json_decode($respuesta, true);
    
    // Si el JSON de la API está roto o vacío, devolvemos la respuesta cruda para auditoría
    if ($resultado === null) {
        return [
            'status' => 'error',
            'mensaje_interno' => 'La API externa no devolvió un JSON válido',
            'respuesta_cruda' => $respuesta
        ];
    }
    
    return $resultado;
}

/**
 * FUNCIÓN GLOBAL: Construye la estructura de un filtro individual para cualquier endpoint.
 * Llena automáticamente los valores por defecto que exige World Office.
 */
function crear_filtro_api($atributo, $valor, $tipo_dato = 0, $tipo_filtro = 0, $operador = 0) {
    return [
        "atributo"      => (string)$atributo,
        "valor"         => (string)$valor,
        "valor2"        => null,
        "tipoFiltro"    => (int)$tipo_filtro,
        "tipoDato"      => (int)$tipo_dato,
        "nombreColumna" => null,
        "valores"       => null,
        "clase"         => null,
        "operador"      => (int)$operador,
        "subGrupo"      => "filtro"
    ];
}