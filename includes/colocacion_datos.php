<?php
/**
 * /includes/colocacion_datos.php
 * Arma las filas del reporte de Colocación (Calendario B): junta datos que ya vive en el CRM
 * (presupuesto, adopciones, población, compradores activos, empresa, cliente) con la caché
 * local de documentos de World Office (wo_documentos_colocacion, sincronizada por
 * php/sync_colocacion_wo.php para REM/FV y php/sync_colocacion_wo_ventas.php para devoluciones
 * DREM y facturas POS). Solo lectura — no llama a la API de World Office.
 * Usado por php/colocacion_tabla.php (JSON para la tabla en pantalla) y
 * php/colocacion_excel.php (exportable), para no duplicar esta consulta en los dos.
 */

// Periodos disponibles para filtrar el reporte: 2026 en adelante, de Calendario A o B (antes de
// ese año no hay sincronización con World Office ni datos relevantes para este reporte). Se
// incluyen ambos calendarios (id_calendario 1 y 2) porque el filtro de periodo determina de qué
// calendario se leen colegios y datos (ver obtener_datos_colocacion): un periodo sin "B" (p.ej.
// "2027", Calendario A) trae los colegios de Calendario A; uno con "B" trae los de Calendario B.
function obtener_periodos_colocacion(PDO $bdd) {
    return $bdd->query("SELECT p.id, p.periodo, p.id_calendario, c.calendario
        FROM periodos p JOIN calendarios c ON c.id = p.id_calendario
        WHERE p.id_calendario IN (1, 2) AND CAST(p.periodo AS UNSIGNED) >= 2026
        ORDER BY CAST(p.periodo AS UNSIGNED) DESC, p.id_calendario ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function obtener_datos_colocacion(PDO $bdd, $id_periodo = null, $cod_zona_scope = null) {
    // ── Periodo: el pedido por filtro, o Calendario B más reciente por defecto (comportamiento
    // histórico de este reporte, que nació como "Colocación Calendario B") ──
    if ($id_periodo !== null) {
        $id_periodo = (int)$id_periodo;
    } else {
        $id_periodo = (int)$bdd->query("SELECT id FROM periodos WHERE id_calendario = 2 ORDER BY id DESC LIMIT 1")->fetchColumn();
    }
    $periodoRow = $bdd->query("SELECT p.periodo, p.id_calendario, c.calendario
        FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE p.id = $id_periodo")->fetch(PDO::FETCH_ASSOC);
    $nombre_periodo = (string)($periodoRow['periodo'] ?? '');
    $id_calendario = (int)($periodoRow['id_calendario'] ?? 2);
    $nombre_calendario = (string)($periodoRow['calendario'] ?? 'B');

    // ── Colegios del calendario correspondiente al periodo seleccionado ──
    // $cod_zona_scope (opcional): restringe el reporte a los colegios de la zona propia de un
    // usuario (asesor/distribuidor), igual criterio de "alcance por zona propia" que ya usa el
    // "else" de php/valoriza_global_excel.php — para php/colocacion_excel_usuario.php, donde
    // cada usuario descarga solo lo suyo.
    if ($cod_zona_scope !== null && $cod_zona_scope !== '') {
        $stmtColegios = $bdd->prepare("SELECT id, colegio, cod_zona, sub_zona, responsable FROM colegios WHERE id_calendario = ? AND (cod_zona = ? OR zona_madre = ?) ORDER BY colegio ASC");
        $stmtColegios->execute([$id_calendario, $cod_zona_scope, $cod_zona_scope]);
        $colegios = $stmtColegios->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $colegios = $bdd->query("SELECT id, colegio, cod_zona, sub_zona, responsable FROM colegios WHERE id_calendario = $id_calendario ORDER BY colegio ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
    $idsColegios = array_column($colegios, 'id');
    if (empty($idsColegios)) {
        return ['periodo' => $nombre_periodo, 'id_calendario' => $id_calendario, 'calendario' => $nombre_calendario, 'filas' => [], 'sinCruzar' => []];
    }
    $inPlaceholders = implode(',', array_fill(0, count($idsColegios), '?'));

    // ── Empresa: cod_zona -> zonas/usuarios, igual que php/colegios_tabla.php ──
    $codigosZona = array_values(array_unique(array_filter(array_column($colegios, 'cod_zona'), fn($z) => intval($z) !== 0)));
    $empresaPorCodZona = [];
    if (!empty($codigosZona)) {
        $inZonas = implode(',', array_fill(0, count($codigosZona), '?'));
        $stmt = $bdd->prepare("SELECT z.codigo, z.zona, u.tipo FROM zonas z JOIN usuarios u ON z.codigo = u.cod_zona WHERE z.codigo IN ($inZonas)");
        $stmt->execute($codigosZona);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
            if (in_array((int)$z['tipo'], [1, 3, 10], true)) {
                $partes = array_pad(explode('/', $z['zona']), 2, '');
                $empresaPorCodZona[$z['codigo']] = trim($partes[0]);
            } else {
                $empresaPorCodZona[$z['codigo']] = trim($z['zona']);
            }
        }
    }

    // ── Presupuesto Registrado en CRM y Adopciones CRM (SUM por colegio) ──
    // Mismo $gradoJoin que php/dashboard_presupuestos_stats.php: resuelve el grado real de
    // libros por área electiva vía areas_objetivas, para cruzar con la población real del grado.
    $gradoJoin = "LEFT JOIN (
            SELECT id_colegio, codigo, MAX(id_grado_otro) as id_grado_otro
            FROM areas_objetivas
            WHERE id_periodo = $id_periodo AND codigo <> ''
            GROUP BY id_colegio, codigo
            HAVING COUNT(*) = 1
        ) ao ON ao.codigo = p.cod_area AND ao.id_colegio = p.id_colegio AND p.cod_area <> '' AND p.cod_area IS NOT NULL
        LEFT JOIN (SELECT id_colegio, id_grado, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_periodo = $id_periodo GROUP BY id_colegio, id_grado) gp
            ON gp.id_colegio = p.id_colegio AND gp.id_grado = COALESCE(ao.id_grado_otro, l.id_grado)";

    $condicionesNegocio = " AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)";

    // Presupuesto: mismo criterio que dashboard_presupuestos_stats.php (pre_definido = 1).
    $ventaPotencialExpr = "((p.precio - p.precio * p.descuento) * FLOOR(COALESCE(gp.alumnos, 0) * p.tasa_compra))";
    $sqlPresupuesto = "SELECT p.id_colegio, SUM($ventaPotencialExpr) as total
        FROM presupuestos p JOIN libros l ON p.id_libro = l.id $gradoJoin
        WHERE p.id_periodo = $id_periodo AND p.pre_definido = 1 AND p.id_colegio IN ($inPlaceholders)$condicionesNegocio
        GROUP BY p.id_colegio";
    $stmt = $bdd->prepare($sqlPresupuesto);
    $stmt->execute($idsColegios);
    $presupuestoPorColegio = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $presupuestoPorColegio[$r['id_colegio']] = (float)$r['total'];

    // Adopciones: mismo criterio que php/valoriza_excel.php (definido = 1, usa la tasa/descuento
    // del distribuidor si está asignada, si no la propia).
    $tasaAdopcion = "IF(p.tasa_compra_d = 0.00, p.tasa_compra, p.tasa_compra_d)";
    $descuentoAdopcion = "IF(p.tasa_compra_d = 0.00, p.descuento, p.descuento_d)";
    // Descuento promedio y número de la adopción: mismo criterio que php/descuento_adopciones_excel.php
    // ($descuentoAdopcion ya es el equivalente de su $descuentoExpr — descuento del distribuidor si
    // tiene tasa asignada, si no el propio). p.conse se asigna una sola vez por colegio+período
    // (todas sus líneas de libros comparten el mismo consecutivo), así que MAX(p.conse) es seguro.
    $ventaAdopcionExpr = "((p.precio - p.precio * $descuentoAdopcion) * FLOOR(COALESCE(gp.alumnos, 0) * $tasaAdopcion))";
    $sqlAdopciones = "SELECT p.id_colegio, SUM($ventaAdopcionExpr) as total, SUM(FLOOR(COALESCE(gp.alumnos, 0) * $tasaAdopcion)) as compradores,
            AVG($descuentoAdopcion) * 100 as descuento_promedio, MAX(p.conse) as numero_adopcion
        FROM presupuestos p JOIN libros l ON p.id_libro = l.id $gradoJoin
        WHERE p.id_periodo = $id_periodo AND p.definido = 1 AND p.id_colegio IN ($inPlaceholders)$condicionesNegocio
        GROUP BY p.id_colegio";
    $stmt = $bdd->prepare($sqlAdopciones);
    $stmt->execute($idsColegios);
    $adopcionesPorColegio = [];
    $compradoresPorColegio = [];
    $descuentoPromedioPorColegio = [];
    $numeroAdopcionPorColegio = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $adopcionesPorColegio[$r['id_colegio']] = (float)$r['total'];
        $compradoresPorColegio[$r['id_colegio']] = (int)$r['compradores'];
        $descuentoPromedioPorColegio[$r['id_colegio']] = (float)$r['descuento_promedio'];
        $numeroAdopcionPorColegio[$r['id_colegio']] = $r['numero_adopcion'];
    }

    // ── Población General ──
    $stmt = $bdd->prepare("SELECT id_colegio, SUM(alumnos) as total FROM grados_paralelos WHERE id_periodo = $id_periodo AND id_colegio IN ($inPlaceholders) GROUP BY id_colegio");
    $stmt->execute($idsColegios);
    $poblacionPorColegio = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $poblacionPorColegio[$r['id_colegio']] = (int)$r['total'];

    // ── Atenciones a clientes: total legalizado (estado=4 "Entregado"), mismo criterio que la
    // columna "Valor atenciones entregadas" de php/valoriza_global_excel.php. ──
    $stmt = $bdd->prepare("SELECT s.id_colegio, SUM(r.legaliza) as total
        FROM solicitudes_recursos s JOIN recursos_solicitados r ON r.id_solicitud = s.id
        WHERE s.id_periodo = $id_periodo AND s.estado = '4' AND s.id_colegio IN ($inPlaceholders)
        GROUP BY s.id_colegio");
    $stmt->execute($idsColegios);
    $atencionesPorColegio = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $atencionesPorColegio[$r['id_colegio']] = (float)$r['total'];

    // ── Cliente vía tabla `recursos` (id_colegio + periodo activo) ──
    $stmt = $bdd->prepare("SELECT r.id_colegio, cl.cliente FROM recursos r JOIN clientes cl ON cl.id = r.cliente
        WHERE r.id_periodo = $id_periodo AND r.cliente != 0 AND r.id_colegio IN ($inPlaceholders)");
    $stmt->execute($idsColegios);
    $clientePorColegioRecursos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $clientePorColegioRecursos[$r['id_colegio']] = $r['cliente'];

    // ── Documentos de World Office ya sincronizados (ver php/sync_colocacion_wo.php para REM/FV
    // y php/sync_colocacion_wo_ventas.php para DREM/NCV/POS/RC). FV, POS y RC (Abonos) suman al
    // total colocado, REM también (como movimientos individuales, igual que antes), y DREM + NCV
    // (devoluciones de venta y notas crédito de venta) restan — reemplazan la fuente anterior
    // basada en `devoluciones_v`/`libros_devol_v` del CRM, decisión del usuario 2026-08-26: World
    // Office es ahora la única fuente de devoluciones para este reporte (NCV agregada 2026-09-02).
    // RC (Abonos) agregado 2026-09-02: hasta entonces Abonos estaba fijo en 0 porque el endpoint
    // usado para REM/FV no expone ningún valor para Recibo de Caja — sí lo expone otro endpoint
    // (`/contabilidad/filtrarPaginado`, ver includes/api_wo_ventas.php), encontrado después de que
    // el usuario mostrara que World Office sí trae valor para RC en su propia interfaz. ──
    $stmt = $bdd->prepare("SELECT id_colegio, tipo_documento, fecha, numero, valor_neto, tercero_externo_nombre
        FROM wo_documentos_colocacion WHERE id_periodo = $id_periodo AND id_colegio IN ($inPlaceholders) ORDER BY fecha ASC");
    $stmt->execute($idsColegios);
    $facturaPorColegio = [];
    $colocacionWoPorColegio = [];
    $colocacionPosPorColegio = [];
    $devolucionesPorColegio = [];
    $abonosPorColegio = [];
    $clientePorColegioWo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idc = $r['id_colegio'];
        // Cada movimiento guarda 'tipo' + 'numero' (además de fecha/valor) para que pantalla y
        // Excel puedan mostrar qué documento concreto (ej. "POS #12503") aportó ese valor —
        // pedido explícito del usuario 2026-09-02, antes solo se mostraba fecha+valor.
        $mov = ['tipo' => $r['tipo_documento'], 'fecha' => $r['fecha'], 'numero' => $r['numero'], 'valor' => (float)$r['valor_neto']];
        switch ($r['tipo_documento']) {
            case 'FV':
                $facturaPorColegio[$idc][] = $mov;
                break;
            case 'DREM':
            case 'NCV':
                $devolucionesPorColegio[$idc][] = $mov;
                break;
            case 'POS':
                $colocacionPosPorColegio[$idc][] = $mov;
                break;
            case 'RC':
                $abonosPorColegio[$idc][] = $mov;
                break;
            default: // REM
                $colocacionWoPorColegio[$idc][] = $mov;
        }
        if (!isset($clientePorColegioWo[$idc]) && !empty($r['tercero_externo_nombre'])) {
            $clientePorColegioWo[$idc] = $r['tercero_externo_nombre'];
        }
    }

    // ── Documentos sin cruzar, para revisión manual ──
    $sinCruzar = $bdd->query("SELECT id_wo, tipo_documento, numero, fecha, concepto, colegio_extraido
        FROM wo_documentos_colocacion WHERE id_periodo = $id_periodo AND id_colegio IS NULL ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

    // ── Ensamblar filas ──
    $filas = [];
    foreach ($colegios as $c) {
        $id = $c['id'];
        $empresa = intval($c['cod_zona']) === 0 ? 'Sin asignar' : ($empresaPorCodZona[$c['cod_zona']] ?? 'Sin asignar');

        // Cliente: manda `recursos`; si no hay fila ahí o no coincide con el tercero de World
        // Office, se usa el tercero de World Office (el caso más común, ver
        // memory/project_colocacion_modulo.md).
        $clienteRecursos = $clientePorColegioRecursos[$id] ?? null;
        $clienteWo = $clientePorColegioWo[$id] ?? null;
        if ($clienteRecursos !== null && ($clienteWo === null || mb_strtolower(trim($clienteRecursos)) === mb_strtolower(trim($clienteWo)))) {
            $cliente = $clienteRecursos;
        } else {
            $cliente = $clienteWo ?? $clienteRecursos ?? '';
        }

        $movimientosAbonos = $abonosPorColegio[$id] ?? [];
        $abonos = array_sum(array_column($movimientosAbonos, 'valor'));
        $movimientosFv = $facturaPorColegio[$id] ?? [];
        $facturaVenta = array_sum(array_column($movimientosFv, 'valor'));
        $movimientosWo = $colocacionWoPorColegio[$id] ?? [];
        $totalColocacionWo = array_sum(array_column($movimientosWo, 'valor'));
        $movimientosPos = $colocacionPosPorColegio[$id] ?? [];
        $totalColocacionPos = array_sum(array_column($movimientosPos, 'valor'));
        $movimientosDevoluciones = $devolucionesPorColegio[$id] ?? [];
        $devoluciones = array_sum(array_column($movimientosDevoluciones, 'valor'));

        $filas[] = [
            'id_colegio' => (int)$id,
            'empresa' => $empresa,
            'colegio' => $c['colegio'],
            'presupuesto_crm' => $presupuestoPorColegio[$id] ?? 0,
            'adopciones_crm' => $adopcionesPorColegio[$id] ?? 0,
            'atenciones_clientes' => $atencionesPorColegio[$id] ?? 0,
            'poblacion_general' => $poblacionPorColegio[$id] ?? 0,
            'compradores_activos' => $compradoresPorColegio[$id] ?? 0,
            'descuento_promedio' => $descuentoPromedioPorColegio[$id] ?? 0,
            'numero_adopcion' => $numeroAdopcionPorColegio[$id] ?? null,
            'cliente' => $cliente,
            // 'factura_venta'/'devoluciones' siguen siendo el total escalar (usado para subtotales
            // por Cliente/Empresa en el Excel, igual que antes); '*_mov' es el detalle por
            // documento (tipo/número/fecha/valor), igual patrón que 'colocacion_wo'/'colocacion_pos'.
            'factura_venta' => $facturaVenta,
            'factura_venta_mov' => $movimientosFv,
            'abonos' => $abonos,
            'abonos_mov' => $movimientosAbonos,
            'colocacion_wo' => $movimientosWo,
            'colocacion_pos' => $movimientosPos,
            'devoluciones' => $devoluciones,
            'devoluciones_mov' => $movimientosDevoluciones,
            'total_colocado' => $facturaVenta + $abonos + $totalColocacionWo + $totalColocacionPos - $devoluciones,
        ];
    }

    return ['periodo' => $nombre_periodo, 'id_calendario' => $id_calendario, 'calendario' => $nombre_calendario, 'filas' => $filas, 'sinCruzar' => $sinCruzar];
}
