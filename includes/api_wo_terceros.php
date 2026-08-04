<?php
/**
 * /includes/api_wo_terceros.php
 * Listado y consulta individual de terceros (clientes/proveedores) en World Office.
 * - POST /terceros/listarTerceros — descubierto por prueba directa para esta cuenta
 *   (no está en el catálogo público de developer.worldoffice.cloud). No requiere
 *   filtros obligatorios (a diferencia de listarDocumentoSalidaAlmacen).
 * - GET /terceros/consultar/{id} — detalle por id (a diferencia de lo probado antes
 *   por nombre en /terceros/{id}, getTerceroById, etc., que daban 404, este sí existe:
 *   confirmado en la documentación propia de World Office para esta cuenta).
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

function consultar_tercero($id) {
    $endpoint = '/terceros/consultar/' . (int)$id;
    return hacer_peticion_api($endpoint, 'GET');
}
