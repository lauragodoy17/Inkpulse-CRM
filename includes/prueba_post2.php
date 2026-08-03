<?php
    /**
     * /includes/funciones_inventario.php
     * Funciones dinámicas para el módulo de inventarios de World Office Cloud.
     */

    require_once("api_wo_cliente.php");

    /**
     * Obtiene el listado de documentos de salida permitiendo filtros infinitos y dinámicos.
     *
     * @param array $filtros_aplicados Lista de filtros creados previamente mediante crear_filtro_api().
     * @param int $pagina Número de página (comienza en 0).
     * @param int $registros_por_pagina Cantidad de registros devueltos por la API.
     * @return array Respuesta de la API decodificada como array de PHP.
     */
    function listar_documentos_salida_almacen($filtros_aplicados = [], $pagina = 0, $registros_por_pagina = 10) {
        $endpoint = '/inventarios/listarDocumentoSalidaAlmacen';
        $metodo = 'POST';
        
        // MEJORA: Resetea los índices (0, 1, 2...) para asegurar que json_encode genere un arreglo [] válido
        $filtros_limpios = array_values($filtros_aplicados);
        
        // Estructura base de la petición idéntica a World Office
        $cuerpo_peticion = [
            "columnaOrdenar"     => "fecha,id",
            "pagina"             => (int)$pagina,
            "registrosPorPagina" => (int)$registros_por_pagina,
            "orden"              => "DESC",
            "canal"              => 0,
            "registroInicial"    => 0,
            "filtros"            => $filtros_limpios // Inyectamos el arreglo de filtros dinámicos asegurados
        ];
        
        return hacer_peticion_api($endpoint, $metodo, $cuerpo_peticion);
    }

?>