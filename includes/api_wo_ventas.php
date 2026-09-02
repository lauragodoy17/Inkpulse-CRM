<?php
/**
 * /includes/api_wo_ventas.php
 * Devoluciones de venta (documentoTipo "DREM"), notas crédito de venta
 * (documentoTipo "NCV") y facturas de punto de venta (documentoTipo "POS")
 * de World Office. Viven en un microservicio aparte
 * (wo-backend-prodinst1-...azurewebsites.net), NO en api.worldoffice.cloud
 * (el host que usa el resto de este proyecto vía hacer_peticion_api()), pero
 * SÍ aceptan el mismo token permanente guardado en `apis_externas` — a
 * diferencia del microservicio de reportes (wo-reportes-...azurewebsites.net,
 * ver includes/api_wo_reportes.php), este NO exige un token de sesión manual.
 * Confirmado por prueba directa contra producción 2026-08-26.
 *
 * A diferencia de listarDocumentoSalidaAlmacen (REM/FV, ver
 * includes/prueba_post2.php + prueba_get2.php), acá el valor neto del
 * documento (valorTotal) ya viene en la propia lista paginada — no hace
 * falta una segunda llamada de detalle/renglones por documento.
 */
require_once __DIR__ . '/../conexion/api_wo_config.php';

define('API_URL_VENTAS_BASE', 'https://wo-backend-prodinst1-hkahewajdqa8amgg.eastus2-01.azurewebsites.net');

function hacer_peticion_api_ventas($endpoint, array $cuerpo) {
    $ch = curl_init(API_URL_VENTAS_BASE . $endpoint);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . API_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $respuesta = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 'error', 'mensaje_interno' => 'Error de conexión cURL: ' . $error];
    }
    curl_close($ch);

    $resultado = json_decode($respuesta, true);
    if ($resultado === null) {
        return ['status' => 'error', 'mensaje_interno' => 'La API externa no devolvió un JSON válido', 'respuesta_cruda' => $respuesta];
    }

    return ['status' => 'OK', 'data' => $resultado];
}

function listar_devoluciones_venta_wo($pagina = 0, $registrosPorPagina = 20) {
    $cuerpo = [
        "columnaOrdenar" => "fecha,id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "filtros" => [[
            "atributo" => "documentoTipo.codigoDocumento", "valor" => "DREM", "valor2" => null,
            "tipoFiltro" => 0, "tipoDato" => 0, "nombreColumna" => null, "clase" => null,
            "operador" => 1, "subGrupo" => "filtro",
        ]],
        "canal" => 0,
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
    ];
    return hacer_peticion_api_ventas('/ventas/filtrarPaginado', $cuerpo);
}

function listar_notas_credito_venta_wo($pagina = 0, $registrosPorPagina = 20) {
    $cuerpo = [
        "columnaOrdenar" => "fecha,id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "filtros" => [[
            "atributo" => "documentoTipo.codigoDocumento", "valor" => "NCV", "valor2" => null,
            "tipoFiltro" => 0, "tipoDato" => 0, "nombreColumna" => null, "clase" => null,
            "operador" => 1, "subGrupo" => "filtro",
        ]],
        "canal" => 0,
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
    ];
    return hacer_peticion_api_ventas('/ventas/filtrarPaginado', $cuerpo);
}

function listar_facturas_pos_wo($pagina = 0, $registrosPorPagina = 20) {
    $cuerpo = [
        "columnaOrdenar" => "fecha,id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "filtros" => [[
            "atributo" => "documentoTipo.codigoDocumento", "valor" => "POS", "valor2" => null,
            "tipoFiltro" => 0, "tipoDato" => 0, "nombreColumna" => null, "valores" => null,
            "clase" => null, "operador" => 0, "subGrupo" => "filtro",
        ]],
        "canal" => 2,
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
    ];
    return hacer_peticion_api_ventas('/puntodeventa/filtrarPaginado', $cuerpo);
}
