<?php
/**
 * /includes/planillas_procesamiento_datos.php
 * Reporte de las planillas de procesamiento ya generadas (ver
 * php/generar_planilla_procesamiento*.php e includes/planilla_pdf.php) — junta las tres tablas
 * (pedidos con adopción / muestreo / pedidos sin adopción) en un solo listado, con filtro de
 * fecha y el tipo de cada una bien identificado. Pedido por el usuario 2026-09-18.
 */

require_once(__DIR__ . "/pedidos2_cliente.php");

/**
 * Los tres tipos de planilla, con la etiqueta a mostrar y las tablas/columnas propias de cada uno
 * — para no repetir "if tipo == x" por todos lados, cualquier función de acá recorre este mapa.
 */
function tipos_planillas_procesamiento() {
    return [
        'venta' => [
            'label' => 'Pedidos de venta',
            'tabla_planilla' => 'planillas_procesamiento',
            'tabla_detalle' => 'planillas_procesamiento_pedidos',
            'tabla_origen' => 'pedidos',
        ],
        'muestreo' => [
            'label' => 'Muestras',
            'tabla_planilla' => 'planillas_procesamiento_muestreo',
            'tabla_detalle' => 'planillas_procesamiento_muestreo_pedidos',
            'tabla_origen' => 'muestreos',
        ],
        'sa' => [
            'label' => 'Pedidos sin adopción',
            'tabla_planilla' => 'planillas_procesamiento_sa',
            'tabla_detalle' => 'planillas_procesamiento_sa_pedidos',
            'tabla_origen' => 'pedidos2',
        ],
    ];
}

/**
 * Listado combinado de planillas generadas, opcionalmente acotado por rango de fecha
 * (fecha_generacion) y por tipo. Devuelve un array de filas ordenado por fecha descendente, cada
 * una con: id, tipo, tipo_label, fecha_generacion, usuario, cantidad_pedidos.
 * Las tablas de cada tipo pueden no existir todavía si nunca se ha generado ninguna planilla de
 * ese tipo (se crean solas la primera vez que se genera una, ver generar_planilla_procesamiento*.php)
 * — se envuelve en try/catch para no romper el reporte completo por eso.
 */
function obtener_planillas_procesamiento($bdd, $fechaDesde = null, $fechaHasta = null, $tipoFiltro = null) {
    $tipos = tipos_planillas_procesamiento();
    $filas = [];

    foreach ($tipos as $tipo => $info) {
        if ($tipoFiltro && $tipoFiltro !== $tipo) continue;

        $condiciones = '1=1';
        $params = [];
        if ($fechaDesde) { $condiciones .= ' AND DATE(pp.fecha_generacion) >= ?'; $params[] = $fechaDesde; }
        if ($fechaHasta) { $condiciones .= ' AND DATE(pp.fecha_generacion) <= ?'; $params[] = $fechaHasta; }

        try {
            $stmt = $bdd->prepare("SELECT pp.id, pp.fecha_generacion, pp.cantidad_pedidos,
                                           CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS usuario
                                    FROM {$info['tabla_planilla']} pp
                                    LEFT JOIN usuarios u ON u.id = pp.id_usuario
                                    WHERE $condiciones
                                    ORDER BY pp.fecha_generacion DESC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $filas[] = [
                    'id' => (int)$row['id'],
                    'tipo' => $tipo,
                    'tipo_label' => $info['label'],
                    'fecha_generacion' => $row['fecha_generacion'],
                    'usuario' => trim($row['usuario']) !== '' ? $row['usuario'] : '—',
                    'cantidad_pedidos' => (int)$row['cantidad_pedidos'],
                ];
            }
        } catch (Exception $e) {
            // Tabla de este tipo aún no existe (nunca se ha generado una planilla así) — se omite,
            // no es un error del reporte.
        }
    }

    usort($filas, fn($a, $b) => strcmp($b['fecha_generacion'], $a['fecha_generacion']));
    return $filas;
}

/**
 * Detalle (colegio, responsable/cliente, fecha del pedido) de UNA planilla puntual, para mostrar
 * ("cliente" = lo que va en la columna Cliente del PDF: cliente del pedido o, si no hay, el responsable)
 * "los datos correspondientes" al abrir/expandir una fila del reporte. Devuelve [] si el tipo no
 * es válido o la planilla no tiene (ya) ningún pedido asociado.
 */
function obtener_detalle_planilla_procesamiento($bdd, $tipo, $idPlanilla) {
    $tipos = tipos_planillas_procesamiento();
    if (!isset($tipos[$tipo])) return [];
    $info = $tipos[$tipo];
    $idPlanilla = (int)$idPlanilla;

    if ($tipo === 'venta') {
        $sql = "SELECT o.id, o.fecha, c.colegio,
                       CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable,
                       COALESCE(NULLIF(TRIM(cl.cliente),''),
                                NULLIF(TRIM(CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE c.responsable END),''),
                                NULLIF(TRIM(CONCAT_WS(' ',TRIM(u.nombres),TRIM(u.apellidos))),''),
                                NULLIF(TRIM(CONCAT_WS(' ',TRIM(uc.nombres),TRIM(uc.apellidos))),'')) AS cliente
                FROM {$info['tabla_detalle']} d
                JOIN {$info['tabla_origen']} o ON o.id = d.id_pedido
                JOIN colegios c ON c.id = o.id_colegio
                LEFT JOIN zonas z ON z.codigo = c.cod_zona
                LEFT JOIN usuarios u ON u.cod_zona = z.codigo
                LEFT JOIN clientes cl ON cl.id = o.cliente
                LEFT JOIN usuarios uc ON uc.id = o.id_usuario
                WHERE d.id_planilla = ?
                GROUP BY o.id
                ORDER BY o.id";
    } elseif ($tipo === 'muestreo') {
        $sql = "SELECT o.id, o.fecha, c.colegio,
                       CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable,
                       COALESCE(NULLIF(TRIM(CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE c.responsable END),''),
                                NULLIF(TRIM(CONCAT_WS(' ',TRIM(u.nombres),TRIM(u.apellidos))),'')) AS cliente
                FROM {$info['tabla_detalle']} d
                JOIN {$info['tabla_origen']} o ON o.id = d.id_pedido
                JOIN colegios c ON c.id = o.id_colegio
                LEFT JOIN usuarios u ON u.id = o.id_usuario
                WHERE d.id_planilla = ?
                GROUP BY o.id
                ORDER BY o.id";
    } else { // sa: pedidos2.colegio es texto directo, sin FK a colegios
        asegurar_columna_cliente_pedidos2($bdd);
        $sql = "SELECT o.id, o.fecha, o.colegio,
                       CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS responsable,
                       COALESCE(NULLIF(TRIM(cl.cliente),''),
                                NULLIF(TRIM(CONCAT_WS(' ',TRIM(u.nombres),TRIM(u.apellidos))),'')) AS cliente
                FROM {$info['tabla_detalle']} d
                JOIN {$info['tabla_origen']} o ON o.id = d.id_pedido
                LEFT JOIN usuarios u ON u.id = o.id_usuario
                LEFT JOIN clientes cl ON cl.id = o.cliente
                WHERE d.id_planilla = ?
                GROUP BY o.id
                ORDER BY o.id";
    }

    try {
        $stmt = $bdd->prepare($sql);
        $stmt->execute([$idPlanilla]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}
