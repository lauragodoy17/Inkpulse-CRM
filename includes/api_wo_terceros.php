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
 * - Filtro "mayor que" en listarTerceros: crear_filtro_api('id', $valor, 2, 3) filtra
 *   del lado de WO por id > $valor (confirmado por prueba directa contra producción:
 *   con 10,919 terceros totales, filtrar id>11767 devolvió exactamente los 4 con id
 *   mayor). tipoDato=2/tipoFiltro=0 es "=", tipoFiltro=2 es "<". No documentado en
 *   ningún lado, encontrado por fuerza bruta probando combinaciones tipoDato/tipoFiltro.
 */

require_once("api_wo_cliente.php");

function listar_terceros($filtros = [], $pagina = 0, $registrosPorPagina = 20, $orden = 'DESC') {
    $endpoint = '/terceros/listarTerceros';
    $cuerpo = [
        "columnaOrdenar" => "id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => $orden,
        "canal" => 0,
        // El offset real de paginación lo controla registroInicial, NO "pagina"
        // (confirmado por prueba directa: con registroInicial fijo en 0, WO
        // devolvía siempre la página 0 sin importar el valor de "pagina").
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
        "filtros" => array_values($filtros)
    ];
    return hacer_peticion_api($endpoint, 'POST', $cuerpo);
}

function consultar_tercero($id) {
    $endpoint = '/terceros/consultar/' . (int)$id;
    return hacer_peticion_api($endpoint, 'GET');
}
