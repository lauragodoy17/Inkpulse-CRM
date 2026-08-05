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

    /**
     * Obtiene los renglones (productos/libros) de un documento de venta específico.
     * A pesar del nombre, esta ruta exige POST (un GET da 405) con un cuerpo de
     * paginación como el de los demás listados.
     *
     * @param int|string $id_documento_encabezado El ID del documento (mismo ID que obtener_documento_salida_por_id).
     * @return array Respuesta de la API decodificada, con los renglones en data.content[].
     */
    function obtener_renglones_documento($id_documento_encabezado, $pagina = 0, $registrosPorPagina = 50) {
        $endpoint = '/documentos/getRenglonesByDocumentoEncabezado/' . $id_documento_encabezado;
        $cuerpo = [
            "columnaOrdenar" => "id",
            "pagina" => (int)$pagina,
            "registrosPorPagina" => (int)$registrosPorPagina,
            "orden" => "ASC",
            "canal" => 0,
            // El offset real de paginación lo controla registroInicial, NO "pagina".
            "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
            "filtros" => []
        ];
        return hacer_peticion_api($endpoint, 'POST', $cuerpo);
    }

?>