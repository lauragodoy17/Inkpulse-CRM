<?php
/**
 * /includes/api_wo_inventario.php
 * Listado de inventario (productos/libros) en World Office.
 * Endpoint descubierto por prueba directa para esta cuenta (no está en el
 * catálogo público de developer.worldoffice.cloud): POST /inventarios/listarInventarios.
 * No requiere filtros obligatorios.
 */

require_once("api_wo_cliente.php");

function listar_inventarios($filtros = [], $pagina = 0, $registrosPorPagina = 20) {
    $endpoint = '/inventarios/listarInventarios';
    $cuerpo = [
        "columnaOrdenar" => "id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "canal" => 0,
        "registroInicial" => 0,
        "filtros" => array_values($filtros)
    ];
    return hacer_peticion_api($endpoint, 'POST', $cuerpo);
}

/**
 * Existencias (cantidad real en stock) de un producto, agrupadas por empresa.
 * Endpoint tomado de la documentación propia de esta integración (no del
 * catálogo público de developer.worldoffice.cloud):
 * GET /inventarios/{id}/existencias/empresa
 */
function consultar_existencias_por_empresa($idInventario) {
    return hacer_peticion_api('/inventarios/' . rawurlencode($idInventario) . '/existencias/empresa', 'GET', null);
}

/**
 * Existencias (cantidad real en stock) de un producto, agrupadas por bodega.
 * GET /inventarios/{id}/existencias/bodega
 */
function consultar_existencias_por_bodega($idInventario) {
    return hacer_peticion_api('/inventarios/' . rawurlencode($idInventario) . '/existencias/bodega', 'GET', null);
}
