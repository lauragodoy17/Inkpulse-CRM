<?php
/**
 * /includes/api_wo_reportes.php
 * Reporte "Ventas por Producto Detallado por Documento" de World Office.
 *
 * A diferencia de todo lo demás en este panel, este reporte vive en un
 * microservicio aparte (wo-reportes-...azurewebsites.net), NO en
 * api.worldoffice.cloud, y se autentica con un TOKEN DE SESIÓN de un
 * usuario real de World Office (dura ~24h), no con el token permanente
 * guardado en apis_externas. Por eso esta función recibe el token como
 * parámetro en vez de leerlo de la configuración: el usuario debe copiarlo
 * desde el Network tab del navegador cada vez que lo necesite.
 *
 * Descubierto inspeccionando la petición real que hace la propia interfaz
 * de World Office al generar este reporte manualmente.
 */

function obtener_reporte_ventas_producto($token, $fechaInicio, $fechaFin, array $tiposDocumentoIds = [1, 6]) {
    $url = 'https://wo-reportes-prodinst1-dufecyb8a4cbejdx.eastus2-01.azurewebsites.net/reporte/mensaje';

    $body = [
        "id" => null,
        "accion" => "obtenerInformacionVentasProductos",
        "codigo" => "REPORTE_VENTAS_PRODUCTO",
        "filtro" => [
            "columnaOrdenar" => "documentoEncabezado.prefijo",
            "orden" => "ASC",
            "filtros" => [
                [
                    "atributo" => "documentoEncabezado.empresa.id",
                    "valor" => "", "valor2" => null, "tipoFiltro" => 0, "tipoDato" => 6,
                    "nombreColumna" => null, "valores" => [1],
                    "clase" => "documentoMovimientoInventario", "operador" => 0,
                    "valorReporte" => "EUREKA CONTENIDOS EDUCATIVOS SAS", "labelValorReporte" => "Empresa:"
                ],
                [
                    "atributo" => "documentoEncabezado.fecha",
                    "valor" => $fechaInicio, "valor2" => $fechaFin, "tipoFiltro" => 8, "tipoDato" => 3,
                    "nombreColumna" => null, "valores" => null,
                    "clase" => "documentoMovimientoInventario", "operador" => 0,
                    "valorReporte" => "Fecha Inicio: $fechaInicio, Fecha Fin: $fechaFin", "labelValorReporte" => "Fechas:"
                ],
                [
                    "atributo" => "documentoEncabezado.documentoTipo.id",
                    "valor" => "", "valor2" => null, "tipoFiltro" => 0, "tipoDato" => 6,
                    "nombreColumna" => null, "valores" => array_values($tiposDocumentoIds),
                    "clase" => "documentoMovimientoInventario", "operador" => 0,
                    "valorReporte" => "", "labelValorReporte" => "Tipo de Documento:"
                ]
            ],
            "opcionesAdicionales" => [
                [
                    "atributo" => "mostrarLogo", "valor" => "", "valor2" => null, "tipoFiltro" => 0, "tipoDato" => 6,
                    "nombreColumna" => null, "valores" => [], "clase" => "", "operador" => 0
                ]
            ]
        ],
        "canal" => "WEB"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/plain',
        'Accept: application/json',
        'Authorization: ' . $token,
        'Origin: https://worldoffice.cloud',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $respuesta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['status' => 'error', 'mensaje_interno' => 'Error de conexión cURL: ' . $error_msg];
    }
    curl_close($ch);

    if ($http_code === 401 || $http_code === 403) {
        return ['status' => 'error', 'mensaje_interno' => 'Token inválido o expirado (HTTP ' . $http_code . '). Vuelve a copiarlo del Network tab de World Office.'];
    }

    $data = json_decode($respuesta, true);
    if ($data === null) {
        return ['status' => 'error', 'mensaje_interno' => 'La API no devolvió un JSON válido (HTTP ' . $http_code . ')'];
    }

    // Esta API devuelve un objeto de error (con "codigoError") en vez de un
    // objeto de reporte (con "rows") cuando el token expiró o es inválido.
    if (isset($data['codigoError'])) {
        return [
            'status' => 'error',
            'mensaje_interno' => 'El token probablemente expiró o no es válido (código ' . $data['codigoError'] . '). Vuelve a copiarlo del Network tab.',
            'respuesta_cruda' => $data
        ];
    }

    return ['status' => 'ok', 'data' => $data];
}
