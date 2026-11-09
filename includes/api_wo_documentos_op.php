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
require_once __DIR__ . '/api_wo_ventas.php'; // hacer_peticion_api_ventas, obtener_detalle_impresion_wo (DREM/NCV)

// Tipos de documento que tiene sentido asociar a un despacho (una OP se
// factura o se remisiona, nunca se paga/anula desde aquí).
define('WO_TIPOS_DESPACHO', ['FV' => 'Factura de Venta', 'REM' => 'Remisión']);

// Devoluciones (2026-11-09): las OP de devolución de venta se atienden con una devolución de remisión
// o una nota crédito. No salen en listarDocumentoSalidaAlmacen sino en /ventas/filtrarPaginado (mismos
// filtros; el valor total ya viene en la lista). op_pendiente.php busca en los cuatro tipos.
// Facturas de punto de venta (POS, 2026-11-09): mismo trato, pero en /puntodeventa/filtrarPaginado (canal 2).
define('WO_TIPOS_DEVOLUCION', ['DREM' => 'Devolución de Remisión', 'NCV' => 'Nota Crédito', 'POS' => 'Factura POS']);
define('WO_TIPOS_OP_PENDIENTE', array_merge(array_keys(WO_TIPOS_DESPACHO), array_keys(WO_TIPOS_DEVOLUCION)));

/** Una página de documentos DREM/NCV/POS de World Office con los filtros dados (mismo formato de crear_filtro_api).
 * Hasta 100: una OP de venta en colegio puede tener decenas de facturas POS, y aquí no hay consulta extra por documento. */
function wo_listar_documentos_ventas($tipo, array $filtros, $registros = 100) {
    $pos = $tipo === 'POS';
    return hacer_peticion_api_ventas($pos ? '/puntodeventa/filtrarPaginado' : '/ventas/filtrarPaginado', [
        'columnaOrdenar' => 'fecha,id', 'pagina' => 0, 'registrosPorPagina' => (int)$registros, 'orden' => 'DESC',
        'filtros' => array_values(array_merge([crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0)], $filtros)),
        'canal' => $pos ? 2 : 0, 'registroInicial' => 0,
    ]);
}

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
 * escribiendo a mano (ver memoria del proyecto). Con $filtro (callable sobre el concepto) solo se
 * dejan los documentos cuyo concepto lo cumpla.
 */
function wo_buscar_documentos_por_texto($texto, $tipos = null, ?callable $filtro = null) {
    if ($tipos === null) $tipos = array_keys(WO_TIPOS_DESPACHO);
    $texto = trim($texto);
    if ($texto === '') return ['ok' => false, 'error' => 'Texto de búsqueda vacío.', 'candidatos' => []];

    $numero = null;
    if (preg_match('/(\d+)\s*$/', $texto, $m)) $numero = $m[1];

    $vistos      = []; // "$tipo:$id" ya agregado, para no duplicar entre las dos formas de búsqueda
    $candidatos  = [];
    $sin_mostrar = []; // tipo => coincidencias en WO, cuando son más de las que se traen

    foreach ($tipos as $tipo) {
        $filtros_por_forma = [
            [crear_filtro_api('concepto', $texto, 0, 1)],                     // tipoFiltro 1 = LIKE
            [crear_filtro_api('terceroExterno.nombreCompleto', $texto, 0, 1)], // tipoFiltro 1 = LIKE, busca por cliente
        ];
        if ($numero !== null) {
            $filtros_por_forma[] = [crear_filtro_api('numero', $numero, 4, 0)]; // tipoFiltro 0 = exacto
        }

        $esDevolucion = isset(WO_TIPOS_DEVOLUCION[$tipo]);
        foreach ($filtros_por_forma as $filtros_extra) {
            if ($esDevolucion) {
                $resp = wo_listar_documentos_ventas($tipo, $filtros_extra);
            } else {
                $resp = listar_documentos_salida_almacen(array_merge([
                    crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                    crear_filtro_api('moneda.id', '31', 4),
                ], $filtros_extra), 0, 20);
            }
            if (($resp['status'] ?? '') !== 'OK') continue; // tipo no habilitado o sin resultados: se ignora, no es error fatal

            // Más coincidencias de las que trae una página (p. ej. cientos de facturas POS de una misma OP).
            $hay = (int)($resp['data']['totalElements'] ?? 0);
            $trae = count($resp['data']['content'] ?? []);
            if ($esDevolucion && $hay > $trae) $sin_mostrar[$tipo] = max($sin_mostrar[$tipo] ?? 0, $hay);

            foreach ($resp['data']['content'] ?? [] as $row) {
                if (!empty($row['senAnulado'])) continue; // no ofrecer documentos anulados
                if ($filtro !== null && !$filtro($row['concepto'] ?? '')) continue;

                $clave = $tipo . ':' . $row['id'];
                if (isset($vistos[$clave])) continue;
                $vistos[$clave] = true;

                // DREM/NCV/POS: el total ya viene en la lista (y la API de renglones rechaza las DREM y POS).
                $valor_neto = $esDevolucion ? (isset($row['valorTotal']) ? (float)$row['valorTotal'] : null) : wo_calcular_valor_neto($row['id']);

                $candidatos[] = [
                    'id'              => $row['id'],
                    'tipo'            => $tipo,
                    'tipo_label'      => WO_TIPOS_DESPACHO[$tipo] ?? WO_TIPOS_DEVOLUCION[$tipo] ?? $tipo,
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

    $aviso = null;
    if ($sin_mostrar) {
        $partes = [];
        foreach ($sin_mostrar as $tipo => $n) $partes[] = number_format($n, 0, ',', '.') . ' de ' . (WO_TIPOS_DEVOLUCION[$tipo] ?? $tipo);
        $aviso = 'World Office tiene más documentos de los que se muestran (' . implode(', ', $partes) . '); salen los más recientes. '
               . 'Para encontrar uno en particular, búscalo por su número (ej. "POSK 13086").';
    }
    return ['ok' => true, 'error' => null, 'candidatos' => $candidatos, 'aviso' => $aviso];
}

/**
 * Busca por el número de una OP de Inkpulse en el concepto, en los tipos dados (por defecto FV + REM).
 * El concepto se escribe a mano de varias formas ("OP 2026-6522", "OP2026-6519", "OP:2026-5698"), así
 * que se busca "<año>-<id>" y se confirma el número exacto (2026-652 no es 2026-6522).
 */
function wo_buscar_documentos_por_op($anio, $op_id, $tipos = null) {
    $anio = intval($anio);
    $op_id = intval($op_id);
    $patron = '/(?<!\d)' . $anio . '\s*-\s*' . $op_id . '(?!\d)/';
    return wo_buscar_documentos_por_texto($anio . '-' . $op_id, $tipos, fn($concepto) => (bool)preg_match($patron, (string)$concepto));
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
        // Devoluciones de remisión (DREM) y facturas POS: la API de renglones responde TIPO_DOCUMENTO_NO_ADMITO_API;
        // sus ítems salen del detalle de impresión.
        $items = wo_items_documento_impresion($id_documento);
        if ($items !== null) return $items;
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

/**
 * Ítems de un documento desde su detalle de impresión (devoluciones de remisión DREM y facturas POS), con la misma
 * forma de wo_obtener_items_documento(); null si World Office no lo devuelve. Aquí el descuento viene
 * en porcentaje ("50") y el "total" del renglón es bruto: se pasa a fracción y se calcula el neto.
 */
function wo_items_documento_impresion($id_documento) {
    $resp = obtener_detalle_impresion_wo($id_documento);
    if (($resp['status'] ?? '') !== 'OK') return null;

    $items = [];
    $total = 0;
    foreach ($resp['data']['detalles'] ?? [] as $r) {
        $cantidad      = floatval($r['cantidad'] ?? 0);
        $valorUnitario = floatval($r['valorUnitario'] ?? 0);
        $descuento_pct = floatval($r['porcentajeDescuento'] ?? 0) / 100;
        $subtotal      = $cantidad * $valorUnitario;
        $total_renglon = $subtotal - ($subtotal * $descuento_pct);
        $total += $total_renglon;

        $items[] = [
            'descripcion'    => $r['descripcion'] ?? '—',
            'codigo'         => trim((string)($r['codigo'] ?? '')),
            'cantidad'       => $cantidad,
            'valor_unitario' => $valorUnitario,
            'descuento_pct'  => $descuento_pct,
            'total'          => $total_renglon,
        ];
    }
    return ['ok' => true, 'error' => null, 'items' => $items, 'total' => $total];
}
