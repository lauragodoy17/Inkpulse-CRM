<?php
    /**
     * /includes/funciones_pedidos_wc.php
     * Funciones específicas para el módulo de Pedidos (Orders) de WooCommerce.
     */

    // Importamos el conector base de WooCommerce que ya configuraste
    require_once("api_wc_cliente.php");

    /**
     * Obtiene el listado de pedidos desde WooCommerce permitiendo paginación y filtros nativos.
     * (Método: GET con parámetros en URL)
     *
     * @param array $parametros_busqueda Filtros opcionales (ej: ['status' => 'processing', 'per_page' => 20]).
     * @return array                     Respuesta de WooCommerce decodificada como array de PHP.
     */
    function listar_pedidos_woocommerce($parametros_busqueda = []) {
        // El recurso base de la documentación de WooCommerce
        $endpoint = '/orders';
        
        // WooCommerce exige el método GET para listar recursos
        $metodo = 'GET';
        
        // Valores por defecto sensatos si el usuario no envía parámetros
        $parametros_defecto = [
            'page'     => 1,  // WooCommerce empieza la paginación en 1 (a diferencia de World Office)
            'per_page' => 10, // Cantidad de pedidos por llamado (máximo 100)
            'order'    => 'desc', // Traer primero los más recientes
            'orderby'  => 'date'
        ];
        
        // Fusionamos los valores por defecto con los que tú envíes dinámicamente
        $parametros_finales = array_merge($parametros_defecto, $parametros_busqueda);
        
        // Enviamos todo al conector. Él se encargará de armar la URL con el Query String automático.
        return hacer_peticion_woocommerce($endpoint, $metodo, $parametros_finales);
    }

    /**
     * Obtiene un único pedido específico utilizando su ID de WordPress.
     * (Método: GET limpio por URL sin parámetros extras)
     * 
     * @param int|string $id_pedido ID del pedido en WooCommerce (ej: 1024).
     * @return array                Detalle completo del pedido (productos, cliente, total).
     */
    function obtener_pedido_woocommerce_por_id($id_pedido) {
        $endpoint = '/orders/' . $id_pedido;
        return hacer_peticion_woocommerce($endpoint, 'GET', null);
    }

    /**
     * Extrae el nombre del colegio de un pedido, guardado como campo de
     * checkout personalizado en meta_data ('billing_colegio' / '_billing_colegio').
     *
     * @param array $pedido Pedido de WooCommerce (con su meta_data).
     * @return string
     */
    function obtener_colegio_pedido($pedido) {
        foreach (($pedido['meta_data'] ?? []) as $meta) {
            if (($meta['key'] ?? '') === 'billing_colegio' || ($meta['key'] ?? '') === '_billing_colegio') {
                return (string)($meta['value'] ?? '');
            }
        }
        return '';
    }

    /**
     * Extrae los id_wo (World Office) de los libros de un pedido, tomados del
     * campo ACF 'id_wo' de cada producto dentro de line_items.
     *
     * @param array $pedido Pedido de WooCommerce (con sus line_items).
     * @return array Lista de id_wo (strings), sin duplicados.
     */
    function obtener_ids_wo_pedido($pedido) {
        $ids = [];
        foreach (($pedido['line_items'] ?? []) as $linea) {
            $id_wo = $linea['acf']['id_wo'] ?? '';
            if ($id_wo !== '' && $id_wo !== null) {
                $ids[] = (string)$id_wo;
            }
        }
        return array_values(array_unique($ids));
    }

?>