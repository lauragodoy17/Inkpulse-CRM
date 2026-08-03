<?php
    /**
     * /probar_detalle.php
     * Script para verificar la consulta GET por ID de un documento específico.
     */

    // Habilitamos el reporte de errores de PHP en pantalla para diagnosticar fallas
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    // 1. Importamos el archivo de funciones exclusivo que acabamos de crear
    require_once("../includes/prueba_get2.php");

    echo "<h2>🧪 Probando endpoint GET por ID en World Office...</h2>";

    // 2. Definimos el ID del documento que queremos consultar (el 558 de tu cURL)
    $id_documento_prueba = 558;

    echo "<p>⏳ Solicitando detalles del documento ID: <strong>$id_documento_prueba</strong>...</p>";

    // 3. Ejecutamos la función
    $resultado = obtener_documento_salida_por_id($id_documento_prueba);

    // 4. Analizamos la respuesta recibida
    if (isset($resultado['status']) && $resultado['status'] === 'error') {
        echo "<h3 style='color:red;'>❌ La consulta GET falló</h3>";
        echo "<pre style='background:#fee; padding:10px; border:1px solid red;'>";
        print_r($resultado); // Muestra el error de cURL o si el servidor devolvió un error
        echo "</pre>";
    } else {
        echo "<h3 style='color:green;'>🎉 ¡Consulta Exitosa! Detalles obtenidos correctamente.</h3>";
        echo "<p>Abajo verás el array completo con los ítems, cantidades, clientes y totales del documento:</p>";
        echo "<pre style='background:#f4f4f4; padding:15px; border:1px solid #ccc; max-height: 500px; overflow: auto;'>";
        
        // Imprime en pantalla de forma estructurada todo el JSON que devolvió la API
        print_r($resultado); 
        
        echo "</pre>";
    }

?>