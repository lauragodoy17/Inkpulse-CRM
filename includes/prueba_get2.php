<?php
    /**
     * /includes/funciones_detalle_inventario.php
     * Funciones específicas para consultar el detalle de inventarios en World Office Cloud.
     */

    // Importamos el conector base que configuramos al principio
    require_once("api_wo_cliente.php");

    /**
     * Obtiene el detalle completo de un documento de salida de almacén específico por su ID.
     * (Método: GET por URL sin cuerpo de parámetros)
     *
     * @param int|string $id_documento El ID único del documento en World Office (ej: 558).
     * @return array                   Respuesta detallada de la API decodificada como array.
     */

    function obtener_documento_salida_por_id($id_documento) {
        // Construimos la ruta dinámica agregando el ID al final del endpoint tal como pide tu cURL
        $endpoint = '/inventarios/getDocumentoSalidaAlmacenId/' . $id_documento;
        
        // Especificamos el método GET requerido por este recurso
        $metodo = 'GET';
        
        // Al ser un GET puro que no lleva "CURLOPT_POSTFIELDS", pasamos null en los datos
        return hacer_peticion_api($endpoint, $metodo, null);
    }

?>