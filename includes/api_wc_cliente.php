<?php
    /**
     * /includes/wc_cliente.php
     * Conector base para realizar peticiones HTTP mediante cURL hacia WooCommerce.
     */

    // 1. Cargamos de forma segura la configuración usando la ruta absoluta
    require_once("../conexion/api_wc_config.php");

    /**
     * Realiza una petición HTTP a la REST API de WooCommerce.
     *
     * @param string $endpoint El recurso (ej: 'products', 'orders/1024').
     * @param string $metodo El método HTTP ('GET', 'POST', 'PUT', 'DELETE').
     * @param array|null $datos Parámetros URL (si es GET) o Cuerpo JSON (si es POST/PUT).
     * @return array La respuesta de WooCommerce decodificada.
     */
    function hacer_peticion_woocommerce($endpoint, $metodo = 'GET', $datos = null) {
        $metodo = strtoupper($metodo);
        $url_completa = WC_URL_BASE . $endpoint;
        
        // 2. Si el método es GET y se enviaron datos, los convertimos en parámetros de URL (?key=value)
        if ($metodo === 'GET' && !empty($datos)) {
            // http_build_query transforma [ 'status' => 'completed' ] en '?status=completed'
            $url_completa .= '?' . http_build_query($datos);
        }
        
        $ch = curl_init($url_completa);
        
        // 3. Autenticación Básica requerida por WooCommerce (Key:Secret codificado en Base64)
        $credenciales_base64 = base64_encode(WC_CUSTOMER_KEY . ':' . WC_CUSTOMER_SECRET);
        
        $cabeceras = [
            'Content-Type: application/json',
            'Authorization: Basic ' . $credenciales_base64
        ];
        
        // Configuración base de cURL idéntica a tus pruebas anteriores
        $opciones_curl = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1
        ];
        
        // 4. Si es POST o PUT y hay datos, los inyectamos como cuerpo JSON string
        if (($metodo === 'POST' || $metodo === 'PUT') && $datos !== null) {
            $opciones_curl[CURLOPT_POSTFIELDS] = json_encode($datos);
        }
        
        curl_setopt_array($ch, $opciones_curl);
        
        $respuesta = curl_exec($ch);
        
        // Captura de errores de conexión física
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return [
                'status' => 'error',
                'mensaje_interno' => 'Error de conexión WooCommerce cURL: ' . $error_msg
            ];
        }
        
        curl_close($ch);
        
        $resultado = json_decode($respuesta, true);
        
        if ($resultado === null) {
            return [
                'status' => 'error',
                'mensaje_interno' => 'WooCommerce no devolvió un JSON válido.',
                'respuesta_cruda'  => $respuesta
            ];
        }
        
        return $resultado;
    }

?>