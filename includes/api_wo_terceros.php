<?php
/**
 * /includes/api_wo_terceros.php
 * Listado de terceros (clientes/proveedores) en World Office.
 * Endpoint descubierto por prueba directa para esta cuenta (no está en el
 * catálogo público de developer.worldoffice.cloud): POST /terceros/listarTerceros.
 * No requiere filtros obligatorios (a diferencia de listarDocumentoSalidaAlmacen).
 */

require_once("api_wo_cliente.php");

function listar_terceros($filtros = [], $pagina = 0, $registrosPorPagina = 20) {
    $endpoint = '/terceros/listarTerceros';
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
