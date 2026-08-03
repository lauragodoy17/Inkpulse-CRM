<?php
    /**
     * /probar_api.php
     * Script rápido para verificar la conexión y el funcionamiento de los filtros dinámicos.
     */

    // Habilitamos el reporte de errores de PHP en pantalla para capturar cualquier falla
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    // 1. Importamos las funciones de inventario (esta ya incluye internamente al cliente)
    require_once("../includes/prueba_post2.php");

    echo "<h2>🧪 Iniciando prueba de conexión con World Office Cloud...</h2>";

    // 2. Construimos la lista de filtros de forma dinámica utilizando la función global
    $mis_filtros = [];

    // Replicamos exactamente los filtros de tu ejemplo técnico exitoso
    $mis_filtros[] = crear_filtro_api("documentoTipo.codigoDocumento", "FV", 0);
    $mis_filtros[] = crear_filtro_api("moneda.id", "31", 4);

    echo "<p>✅ Filtros dinámicos construidos correctamente.</p>";

    // 3. Ejecutamos el llamado al endpoint enviando los filtros, la página 0 y 10 registros
    echo "<p>⏳ Enviando petición cURL a World Office...</p>";
    $resultado = listar_documentos_salida_almacen($mis_filtros, 0, 10);

    // 4. Analizamos la respuesta del servidor externo
    if (isset($resultado['status']) && $resultado['status'] === 'error') {
        echo "<h3 style='color:red;'>❌ La petición falló</h3>";
        echo "<pre>";
        print_r($resultado); // Muestra el mensaje de error interno de cURL o el JSON roto
        echo "</pre>";
    } else {
        echo "<h3 style='color:green;'>🎉 ¡Conexión Exitosa! JSON recibido y decodificado.</h3>";
        echo "<p>Estructura de los datos devueltos por la API:</p>";
        echo "<pre style='background:#f4f4f4; padding:15px; border:1px solid #ccc; max-height: 400px; overflow: auto;'>";
        
        // Imprime de forma legible todo el arreglo devuelto por World Office
        print_r($resultado); 
        
        echo "</pre>";
    }

?>