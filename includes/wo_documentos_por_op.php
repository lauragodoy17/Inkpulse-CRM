<?php
/**
 * /includes/wo_documentos_por_op.php
 * Documentos de World Office (FV/REM) con sus renglones: por OP del CRM (backorders de paquetes de pedidos)
 * o, para la venta directa, por "VD-<id>" en el concepto (wo_documentos_por_venta_directa). Por OP se
 * juntan dos fuentes:
 *  1. El concepto de WO con "OP <año>-<id>" (dm_documentos_wo_de_ops, la misma búsqueda de las
 *     devoluciones de muestras: confirma el número exacto de la OP).
 *  2. Los documentos que se asociaron a mano a la OP en op_pendiente.php (historial_documento_op con
 *     id_wo): así se encuentran aunque el concepto no traiga la OP.
 * Cada documento aparece una sola vez (por id de WO).
 */
require_once __DIR__ . '/devoluciones_muestras_datos.php'; // dm_documentos_wo_de_ops, dm_op_de_concepto
require_once __DIR__ . '/historial_documento_op.php';

/**
 * $ops = [['id','anio'], ...]. Devuelve ['ok','error','documentos' => [['id','tipo','documento','fecha',
 * 'concepto','op','op_label','origen' => 'concepto'|'op_pendiente','renglones' => [['producto_wo',
 * 'descripcion','codigo','cantidad','valor_unitario','descuento'], ...]], ...]].
 */
function wo_documentos_por_op(PDO $bdd, array $ops) {
    if (!$ops) return ['ok' => true, 'error' => null, 'documentos' => []];
    $wo = dm_documentos_wo_de_ops($ops);
    if (!$wo['ok']) return $wo;

    $documentos = [];
    foreach ($wo['documentos'] as $d) {
        $d['origen'] = 'concepto';
        $documentos[(int)$d['id']] = $d;
    }

    // Documentos asociados a la OP en op_pendiente.php (el más reciente de cada id de WO).
    crear_tabla_historial_documento_op($bdd);
    $ids = array_map(fn($o) => (int)$o['id'], $ops);
    $anios = [];
    foreach ($ops as $o) $anios[(int)$o['id']] = (int)$o['anio'];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $bdd->prepare("SELECT h.opid, h.id_wo, h.n_doc, h.fecha, t.tipo AS tipo_doc
                         FROM historial_documento_op h LEFT JOIN tipo_doc t ON t.id = h.tipo_doc
                         WHERE h.opid IN ($ph) AND h.id_wo IS NOT NULL AND h.id_wo > 0
                         ORDER BY h.id DESC");
    $st->execute($ids);
    $pendientes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $idWo = (int)$h['id_wo'];
        if (isset($documentos[$idWo]) || isset($pendientes[$idWo])) continue;
        $tipoDoc = strtoupper(trim((string)$h['tipo_doc']));
        $pendientes[$idWo] = [
            'id'        => $idWo,
            'tipo'      => strpos($tipoDoc, 'REM') !== false ? 'REM' : 'FV',
            'documento' => trim((string)$h['n_doc']),
            'fecha'     => date('d/m/Y', strtotime($h['fecha'])),
            'concepto'  => 'Asociado a la OP en el CRM',
            'op'        => (int)$h['opid'],
            'op_label'  => ($anios[(int)$h['opid']] ?? '') . '-' . (int)$h['opid'],
            'ped'       => null,
            'origen'    => 'op_pendiente',
            'renglones' => [],
        ];
    }

    if ($pendientes) {
        $pet = [];
        foreach ($pendientes as $id => $_) {
            $pet[$id] = ['endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $id, 'metodo' => 'POST',
                         'datos' => cuerpo_renglones_documento(0, 200)];
        }
        foreach (hacer_peticiones_api_paralelo($pet) as $id => $resp) {
            if (($resp['status'] ?? '') !== 'OK') {
                return ['ok' => false, 'error' => 'No se pudieron leer los títulos del documento ' . $pendientes[$id]['documento'] . ' en World Office.', 'documentos' => []];
            }
            foreach ($resp['data']['content'] ?? [] as $r) {
                $pendientes[$id]['renglones'][] = [
                    'producto_wo' => (int)($r['inventario']['id'] ?? 0),
                    'descripcion' => $r['inventario']['descripcion'] ?? '',
                    'codigo'      => trim((string)($r['inventario']['codigo'] ?? '')),
                    'cantidad'    => (float)($r['cantidad'] ?? 0),
                    // Precio del renglón: valorUnitario bruto y porcentajeDescuento en fracción (WO no da el neto).
                    'valor_unitario' => (float)($r['valorUnitario'] ?? 0),
                    'descuento'   => (float)($r['porcentajeDescuento'] ?? 0),
                ];
            }
            $documentos[$id] = $pendientes[$id];
        }
    }

    ksort($documentos);
    return ['ok' => true, 'error' => null, 'documentos' => array_values($documentos)];
}

/**
 * Números de venta directa escritos en un concepto de WO: "VD-15", "VD - 15", "vd-15". El número debe
 * terminar ahí (VD-15 no es VD-150).
 */
function wo_vd_de_concepto($concepto) {
    require_once __DIR__ . '/venta_directa_datos.php';
    return vd_ids_en_concepto($concepto);
}

/**
 * Documentos FV/REM de World Office (no anulados) de una venta directa: los que tienen "VD-<id>" en el
 * concepto (pedido de la usuaria 2026-10-14: la venta directa no usa OP; el número VD es el filtro para
 * que no se cruce con pedidos, muestreos ni pedidos sin adopción, cuyos números se repiten).
 * Misma forma de respuesta que wo_documentos_por_op().
 */
function wo_documentos_por_venta_directa($idVd) {
    $idVd = (int)$idVd;
    $peticiones = [];
    foreach (array_keys(WO_TIPOS_DESPACHO) as $tipo) {
        foreach (['VD-' . $idVd, 'VD - ' . $idVd] as $i => $texto) {
            $peticiones["$tipo|$i"] = [
                'endpoint' => '/inventarios/listarDocumentoSalidaAlmacen',
                'metodo'   => 'POST',
                'datos'    => cuerpo_listar_documentos_salida_almacen([
                    crear_filtro_api('documentoTipo.codigoDocumento', $tipo, 0),
                    crear_filtro_api('moneda.id', '31', 4),
                    crear_filtro_api('concepto', $texto, 0, 1), // LIKE
                ], 0, 50),
            ];
        }
    }
    $documentos = [];
    $hayRespuesta = false;
    foreach (hacer_peticiones_api_paralelo($peticiones) as $clave => $resp) {
        $status = $resp['status'] ?? '';
        if ($status === 'OK' || $status === 'BAD_REQUEST') $hayRespuesta = true; // sin coincidencias = BAD_REQUEST
        if ($status !== 'OK') continue;
        foreach ($resp['data']['content'] ?? [] as $row) {
            if (!empty($row['senAnulado'])) continue;
            if (!in_array($idVd, wo_vd_de_concepto($row['concepto'] ?? ''), true)) continue; // VD-15 ≠ VD-150
            $documentos[(int)$row['id']] = [
                'id'        => (int)$row['id'],
                'tipo'      => explode('|', $clave)[0],
                'documento' => trim(($row['prefijo'] ?? '') . ' ' . ($row['numero'] ?? '')),
                'fecha'     => $row['fecha'] ?? '',
                'concepto'  => $row['concepto'] ?? '',
                'origen'    => 'concepto',
                'renglones' => [],
            ];
        }
    }
    if (!$hayRespuesta) return ['ok' => false, 'error' => 'No se pudo consultar World Office. Intenta de nuevo en unos minutos.', 'documentos' => []];

    $pet = [];
    foreach ($documentos as $id => $_) {
        $pet[$id] = ['endpoint' => '/documentos/getRenglonesByDocumentoEncabezado/' . $id, 'metodo' => 'POST', 'datos' => cuerpo_renglones_documento(0, 200)];
    }
    foreach (hacer_peticiones_api_paralelo($pet) as $id => $resp) {
        if (($resp['status'] ?? '') !== 'OK') {
            return ['ok' => false, 'error' => 'No se pudieron leer los títulos del documento ' . $documentos[$id]['documento'] . ' en World Office.', 'documentos' => []];
        }
        foreach ($resp['data']['content'] ?? [] as $r) {
            $documentos[$id]['renglones'][] = [
                'producto_wo' => (int)($r['inventario']['id'] ?? 0),
                'descripcion' => $r['inventario']['descripcion'] ?? '',
                'codigo'      => trim((string)($r['inventario']['codigo'] ?? '')),
                'cantidad'    => (float)($r['cantidad'] ?? 0),
            ];
        }
    }
    ksort($documentos);
    return ['ok' => true, 'error' => null, 'documentos' => array_values($documentos)];
}
