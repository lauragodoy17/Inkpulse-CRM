<?php
/**
 * /includes/api_wo_ventas.php
 * Devoluciones de venta (documentoTipo "DREM"), notas crédito de venta
 * (documentoTipo "NCV"), facturas de punto de venta (documentoTipo "POS") y
 * recibos de caja (documentoTipo "RC", agregado 2026-09-02) de World Office.
 * RC vive en un endpoint propio dentro de este mismo host —
 * `/contabilidad/filtrarPaginado`, distinto de `/ventas/` y `/puntodeventa/` —
 * encontrado por prueba directa después de que `/inventarios/listarDocumentoSalidaAlmacen`
 * (ver includes/prueba_post2.php) confirmara que ESE endpoint no expone ningún
 * valor para RC (0 renglones, sin campo de total en el detalle) — la conclusión
 * anterior de "World Office no expone valor para RC" era válida solo para ese
 * endpoint, no para toda la API. Trae `valorCredito`/`valorDebito` (iguales
 * entre sí, con `diferencia` siempre en 0 en los casos probados — un Recibo de
 * Caja es partida doble) en vez de `valorTotal`. Viven en un microservicio aparte
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

function listar_recibos_caja_wo($pagina = 0, $registrosPorPagina = 20) {
    $cuerpo = [
        "columnaOrdenar" => "fecha,id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "filtros" => [[
            "atributo" => "documentoTipo.codigoDocumento", "valor" => "RC", "valor2" => null,
            "tipoFiltro" => 0, "tipoDato" => 0, "nombreColumna" => null, "clase" => null,
            "operador" => 1, "subGrupo" => "filtro",
        ]],
        "canal" => 0,
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
    ];
    return hacer_peticion_api_ventas('/contabilidad/filtrarPaginado', $cuerpo);
}

/**
 * Órdenes de compra (documentoTipo "OC"): mismo host y misma ruta que las devoluciones de venta
 * (`/ventas/filtrarPaginado`), verificado en vivo 2026-09-28. Ojo: "FC" en esa misma ruta es
 * FACTURA de compra (175 docs: editoriales, servicios como COMCEL/UNE, compras menores "DSEL"),
 * no orden de compra; el usuario eligió listar solo OC.
 */
function listar_ordenes_compra_wo($pagina = 0, $registrosPorPagina = 100) {
    $cuerpo = [
        "columnaOrdenar" => "id",
        "pagina" => (int)$pagina,
        "registrosPorPagina" => (int)$registrosPorPagina,
        "orden" => "DESC",
        "filtros" => [[
            "atributo" => "documentoTipo.codigoDocumento", "valor" => "OC", "valor2" => null,
            "tipoFiltro" => 0, "tipoDato" => 0, "nombreColumna" => null, "valores" => null,
            "clase" => null, "operador" => 0, "subGrupo" => "filtro",
        ]],
        "canal" => 0,
        "registroInicial" => (int)$pagina * (int)$registrosPorPagina,
    ];
    return hacer_peticion_api_ventas('/ventas/filtrarPaginado', $cuerpo);
}

/**
 * Detalle "de impresión" de un documento de inventario de World Office: encabezado, renglones
 * (`detalles`: código/ISBN, descripción, bodega, cantidad, valor unitario, descuento, IVA, total) y
 * totales (`piePagina`). Sirve para los tipos que la API principal rechaza con
 * TIPO_DOCUMENTO_NO_ADMITO_API (getRenglonesByDocumentoEncabezado): devoluciones de venta (DREM) y
 * órdenes de compra (OC), verificado en vivo 2026-09-28 con la OC id 5774.
 *
 * Vive en el microservicio de reportes (wo-reportes-...), pero esta acción SÍ acepta el token
 * permanente de apis_externas (a diferencia del reporte de ventas por producto de
 * includes/api_wo_reportes.php, que exige token de sesión). El `codigo` es el formato de
 * impresión: "DEV_REMV_EST" (el que se encontró para DREM) también sirve para OC — WO arma la
 * respuesta según el tipo real del documento (encabezado.docTipo = "OC").
 * Un id inexistente responde HTTP 200 con un arreglo que trae `codigoError`.
 */
function obtener_detalle_impresion_wo($idDocumento, $codigo = 'DEV_REMV_EST') {
    $ch = curl_init('https://wo-reportes-prodinst1-dufecyb8a4cbejdx.eastus2-01.azurewebsites.net/reporte/mensaje');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/plain',   // así lo manda la propia interfaz de WO
        'Accept: application/json',
        'Authorization: ' . API_TOKEN,
        'Origin: https://worldoffice.cloud',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'accion' => 'obtenerInformacionImpresionInventarios',
        'codigo' => $codigo,
        'id' => (string)(int)$idDocumento,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $respuesta = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 'error', 'mensaje_interno' => 'Error de conexión cURL: ' . $error];
    }
    curl_close($ch);

    $data = json_decode($respuesta, true);
    if (!is_array($data)) return ['status' => 'error', 'mensaje_interno' => 'La API no devolvió un JSON válido (HTTP ' . $http . ')'];
    $error = $data['codigoError'] ?? ($data[0]['codigoError'] ?? null);
    if ($error !== null || !isset($data['detalles'])) {
        return ['status' => 'error', 'mensaje_interno' => 'World Office no devolvió el documento' . ($error !== null ? " (código $error)" : " (HTTP $http)") . '.', 'respuesta_cruda' => $data];
    }
    return ['status' => 'OK', 'data' => $data];
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
