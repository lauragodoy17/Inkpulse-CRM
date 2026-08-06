<?php
    /**
     * /probar_pedidos_wc.php
     * Script de prueba para validar la lectura de pedidos en WooCommerce.
     */

    // Habilitamos el reporte de errores de PHP en pantalla
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    // 1. Importamos la librería de pedidos de WooCommerce
    require_once("../includes/wc_pedidos.php");

    echo "<h2>🛒 Iniciando prueba de conexión con la API de WooCommerce...</h2>";

    // 2. Definimos parámetros de búsqueda (Filtros nativos de WooCommerce)
    // Traeremos solo 5 registros por rendimiento en la prueba
    $filtros = [
        'per_page' => 5,
        'status'   => 'any' // Puedes cambiarlo por 'processing', 'completed', 'pending'
    ];

    echo "<p>⏳ Solicitando los últimos 5 pedidos a tu WordPress...</p>";

    // 3. Ejecutamos la función del endpoint
    $resultado = listar_pedidos_woocommerce($filtros);

    // 4. Analizamos e imprimimos la respuesta de WooCommerce
    if (isset($resultado['status']) && $resultado['status'] === 'error') {
        echo "<h3 style='color:red;'>❌ La conexión con WooCommerce falló</h3>";
        echo "<pre style='background:#fee; padding:10px; border:1px solid red;'>";
        print_r($resultado); // Muestra el error de cURL o si el JSON está roto
        echo "</pre>";
    } else {
        echo "<h3 style='color:green;'>🎉 ¡Conexión Exitosa con WooCommerce!</h3>";
        echo "<p>Se recibieron " . count($resultado) . " pedidos. Abajo tienes la estructura del primer pedido encontrado:</p>";
        echo "<pre style='background:#f4f4f4; padding:15px; border:1px solid #ccc; max-height: 400px; overflow: auto;'>";
        
        // Si la tienda tiene pedidos, mostramos solo el primero para no saturar la pantalla
        if (!empty($resultado)) {
            // Si WooCommerce te devuelve un error de WordPress (como claves inválidas), 
            // vendrá dentro del array con la clave 'code' o 'message'. Validamos eso:
            if (isset($resultado['code'])) {
                echo "<strong>Error devuelto por WordPress:</strong>\n";
                print_r($resultado);
            } else {
                echo "<strong>Detalle del último pedido (ID: " . $resultado[0]['id'] . "):</strong>\n";
                print_r($resultado[0]); 
            }
        } else {
            echo "La conexión fue exitosa pero tu tienda de WooCommerce actualmente no tiene ningún pedido registrado.";
        }
        
        echo "</pre>";
    }

?>