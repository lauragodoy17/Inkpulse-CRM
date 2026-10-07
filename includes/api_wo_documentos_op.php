<?php
/**
 * Búsqueda de documentos de World Office (facturas de venta y remisiones)
 * relacionados con una OP de Inkpulse, para autocompletar "Registrar
 * despacho" en op_pendiente.php.
 *
 * El campo `concepto` de WO trae, en los documentos generados desde
 * Inkpulse, el texto "OP <año>-<id>; PED <id_pedido>; COL <colegio>; ..."
 * (confirmado con datos reales: ver [[project-wo-api]]). Ese texto SÍ viene
 * en el listado (`listarDocumentoSalidaAlmacen`), no solo en el detalle, así
 * que se puede filtrar por concepto (LIKE) directamente ahí — no hace falta
 * traer todo el listado y filtrar documento por documento.
 */
require_once("prueba_post2.php");
require_once("prueba_get2.php");

// Tipos de documento que tiene sentido asociar a un despacho (una OP se
// factura o se remisiona, nunca se paga/anula desde aquí).
define('WO_TIPOS_DESPACHO', ['FV' => 'Factura de Venta', 'REM' => 'Remisión']);

/**
 * Busca documentos de venta en World Office que coincidan con $texto, en los
 * tipos indicados (por defecto FV + REM). Prueba varias formas a la vez:
 *   - concepto LIKE $texto (para "OP 2026-5750", nombre de colegio, etc.)
 *   - número de documento EXACTO, si $texto trae dígitos (para cuando se
 *     busca por el número mismo, ej. "FVE 2436" o "2436")
 *   - terceroExterno (cliente) LIKE $texto, para buscar por nombre de
 *     cliente/colegio tal como quedó registrado en World Office
 * y junta los resultados sin duplicar. Devuelve, por cada coincidencia, el
 * valor neto ya calculado (suma de los renglones, restando el descuento) —
 * mismo cálculo que valida contra los valores que el personal ya venía
 * escribiendo a mano (ver memoria del proyecto).
 */
function wo_buscar_documentos_por_texto($texto, $tipos = null) {
    if ($tipos === null) $tipos = array_keys(WO_TIPOS_DESPACHO);
    $texto = trim($texto);
    if ($texto === '') return ['ok' => false, 'error' => 'Texto de búsqueda vacío.', 'candidatos' => []];

    $numero = null;
    if (preg_match('/(\d+)\s*$/', $texto, $m)) $numero = $m[1];

    $vistos     = []; // "$tipo:$id" ya agregado, para no duplicar entre las dos formas de búsqueda
    $candidatos = [];

    foreach ($tipos as $tipo) {
        $filtros_por_forma = [
            [crear_filtro_api('concepto', $texto, 0, 1)],                     // tipoFiltro 1 = LIKE
            [crear_filtro_api('terceroExterno.nombreCompleto', $texto, 0, 1)], // tipoFiltro 1 = LIKE, busca por cliente
        ];
        if ($numero !== null) {
            $filtros_por_forma[] = [crear_filtro_api('numero', $numero, 4, 0)]; // tipoFiltro 0 = exacto
        }

        foreach ($filtros_por_forma as $filtros_extra) {
            $filtros = array_merge([
                crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                crear_filtro_api('moneda.id', '31', 4),
            ], $filtros_extra);

            $resp = listar_documentos_salida_almacen($filtros, 0, 20);
            if (($resp['status'] ?? '') !== 'OK') continue; // tipo no habilitado o sin resultados: se ignora, no es error fatal

            foreach ($resp['data']['content'] ?? [] as $row) {
                if (!empty($row['senAnulado'])) continue; // no ofrecer documentos anulados

                $clave = $tipo . ':' . $row['id'];
                if (isset($vistos[$clave])) continue;
                $vistos[$clave] = true;

                $valor_neto = wo_calcular_valor_neto($row['id']);

                $candidatos[] = [
                    'id'              => $row['id'],
                    'tipo'            => $tipo,
                    'tipo_label'      => WO_TIPOS_DESPACHO[$tipo] ?? $tipo,
                    'prefijo'         => $row['prefijo'] ?? '',
                    'numero'          => $row['numero'] ?? '',
                    'documento'       => trim(($row['prefijo'] ?? '') . ' ' . ($row['numero'] ?? '')),
                    'fecha'           => $row['fecha'] ?? '',
                    'tercero_externo' => $row['terceroExterno'] ?? '',
                    'concepto'        => $row['concepto'] ?? '',
                    'valor_neto'      => $valor_neto,
                ];
            }
        }
    }

    return ['ok' => true, 'error' => null, 'candidatos' => $candidatos];
}

/**
 * Busca por el número de una OP de Inkpulse específicamente, construyendo el
 * texto "OP <año>-<id>" tal como lo escribe el sistema al generar el
 * documento en World Office.
 */
function wo_buscar_documentos_por_op($anio, $op_id) {
    $texto = 'OP ' . intval($anio) . '-' . intval($op_id);
    return wo_buscar_documentos_por_texto($texto);
}

/**
 * Suma neta de los renglones de un documento: cantidad × valorUnitario,
 * menos el porcentaje de descuento (fracción 0-1) de cada renglón. WO NO
 * expone el total ya calculado en `listarDocumentoSalidaAlmacen` ni en el
 * detalle por id — solo en los renglones — así que hay que sumarlo aquí.
 */
function wo_calcular_valor_neto($id_documento) {
    $resp = obtener_renglones_documento($id_documento, 0, 200);
    if (($resp['status'] ?? '') !== 'OK') return null;

    $total = 0;
    foreach ($resp['data']['content'] ?? [] as $r) {
        $subtotal  = floatval($r['cantidad'] ?? 0) * floatval($r['valorUnitario'] ?? 0);
        $descuento = $subtotal * floatval($r['porcentajeDescuento'] ?? 0);
        $total += ($subtotal - $descuento);
    }
    return $total;
}

/**
 * Ítems (libros) de un documento de venta, para el botón "Ver" del
 * historial de documentos en op_pendiente.php: descripción, código (ISBN),
 * cantidad, precio unitario y total ya neto (mismo cálculo de
 * wo_calcular_valor_neto, pero devolviendo el detalle renglón por renglón
 * en vez de solo la suma).
 */
function wo_obtener_items_documento($id_documento) {
    $resp = obtener_renglones_documento($id_documento, 0, 200);
    if (($resp['status'] ?? '') !== 'OK') {
        return ['ok' => false, 'error' => 'No se pudo consultar los ítems en World Office.', 'items' => [], 'total' => 0];
    }

    $items = [];
    $total = 0;
    foreach ($resp['data']['content'] ?? [] as $r) {
        $cantidad      = floatval($r['cantidad'] ?? 0);
        $valorUnitario = floatval($r['valorUnitario'] ?? 0);
        $subtotal      = $cantidad * $valorUnitario;
        $descuento_pct = floatval($r['porcentajeDescuento'] ?? 0);
        $total_renglon = $subtotal - ($subtotal * $descuento_pct);
        $total += $total_renglon;

        $items[] = [
            'descripcion' => $r['inventario']['descripcion'] ?? '—',
            'codigo'      => $r['inventario']['codigo'] ?? '',
            'cantidad'    => $cantidad,
            'valor_unitario' => $valorUnitario,
            'descuento_pct'  => $descuento_pct,
            'total'       => $total_renglon,
        ];
    }

    return ['ok' => true, 'error' => null, 'items' => $items, 'total' => $total];
}
