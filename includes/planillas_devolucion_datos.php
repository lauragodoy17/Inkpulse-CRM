<?php
/**
 * /includes/planillas_devolucion_datos.php
 * Planillas de devolución de venta directa (pedido de la usuaria 2026-11-25). Módulo: planillas_devolucion.php
 * (menú Venta directa). Acciones: php/planillas_devolucion_accion.php. PDF: php/planilla_devolucion.php?id=N.
 *
 * - Se genera por pedido de venta directa: pedido #, colegio, fecha de venta, responsable de la venta, los títulos con
 *   sus unidades y una columna vacía para lo que cuenta bodega; al final las firmas del responsable de la venta y del
 *   auxiliar de bodega.
 * - Títulos (regla de la usuaria 2026-11-25): SOLO remisiones de World Office con prefijo CEUR de las OP del pedido
 *   (dvv_documentos_de_ops; las RE22 y demás no cuentan). Si no hay ninguna (o el pedido no tiene OP), lo SOLICITADO
 *   en el pedido (libros_pedidos). planillas_devolucion.origen = 'wo' | 'pedido'.
 * - Cada planilla queda guardada (planillas_devolucion) con su consecutivo (el id) y una FOTO de los datos (titulos en
 *   JSON): el PDF se vuelve a descargar igual aunque WO o el pedido cambien después.
 * - Se le puede adjuntar un archivo (la planilla firmada escaneada, foto...): adjuntos/planillas_devolucion/.
 * - Un pedido puede tener varias planillas (p. ej. una por cada devolución); se anulan con motivo, no se borran.
 */
require_once __DIR__ . '/devoluciones_ventas_datos.php'; // dvd_ops_pedido, wo_documentos_por_op, dm_filtro_alcance_sql
require_once __DIR__ . '/pedidos_venta_directa.php';     // datos_venta_vd, asegurar_columnas_pedido_vd
require_once __DIR__ . '/sobrecosto_canal.php';          // canal Venta directa - Eureka

const PDV_ESTADO_ACTIVA = 1;
const PDV_ESTADO_ANULADA = 0;
const PDV_ADJ_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx'];
const PDV_ADJ_MAX = 15 * 1024 * 1024;

/** DDL: llamar fuera de transacciones. */
function pdv_asegurar(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $bdd->exec("CREATE TABLE IF NOT EXISTS planillas_devolucion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pedido INT NOT NULL,
        id_colegio INT NOT NULL,
        id_periodo INT NOT NULL,
        colegio VARCHAR(255) NOT NULL,
        fecha_venta DATE NULL,
        horario VARCHAR(60) NULL,
        responsable VARCHAR(150) NULL,
        tel_responsable VARCHAR(40) NULL,
        remisiones VARCHAR(500) NULL,
        origen VARCHAR(10) NOT NULL DEFAULT 'wo',
        titulos MEDIUMTEXT NOT NULL,
        total_unidades DECIMAL(12,2) NOT NULL DEFAULT 0,
        adjunto VARCHAR(255) NULL,
        adjunto_nombre VARCHAR(255) NULL,
        adjunto_at DATETIME NULL,
        adjunto_por INT NULL,
        estado TINYINT NOT NULL DEFAULT 1,
        motivo_anulacion VARCHAR(500) NULL,
        anulada_at DATETIME NULL,
        anulada_por INT NULL,
        id_usuario INT NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_pdv_pedido (id_pedido),
        KEY idx_pdv_colegio (id_colegio, id_periodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        if (!$bdd->query("SHOW COLUMNS FROM planillas_devolucion LIKE 'origen'")->fetch()) {
            $bdd->exec("ALTER TABLE planillas_devolucion ADD COLUMN origen VARCHAR(10) NOT NULL DEFAULT 'wo' AFTER remisiones");
        }
    } catch (Exception $e) {}
}

/** Carpeta de adjuntos (se crea si no existe). */
function pdv_dir_adjuntos() {
    $dir = __DIR__ . '/../adjuntos/planillas_devolucion/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** SQL (alias x = pedidos, r = recursos) de los pedidos de venta directa: canal de la adopción o marcados "para Andrea". */
function pdv_sql_vd(PDO $bdd) {
    return "(x.para_andrea = 1 OR " . sql_adopcion_tiene_canal([canal_andrea_id($bdd)]) . ")";
}

/** Períodos con pedidos de venta directa dentro del alcance, el más reciente primero: [['id','periodo']]. */
function pdv_periodos(PDO $bdd) {
    asegurar_columnas_pedido_vd($bdd);
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT DISTINCT pe.id, pe.periodo FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         JOIN periodos pe ON pe.id = x.id_periodo
                         LEFT JOIN recursos r ON r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo
                         WHERE x.estado <> 3 AND " . pdv_sql_vd($bdd) . $alcance . " ORDER BY pe.id DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pedidos de venta directa del período, sin anulados, dentro del alcance (con o sin OP: sin remisión CEUR la planilla
 * sale con lo solicitado): [['id','fecha','estado_txt','id_colegio','colegio','planillas' => n activas]].
 */
function pdv_pedidos(PDO $bdd, $idPeriodo) {
    pdv_asegurar($bdd);
    asegurar_columnas_pedido_vd($bdd);
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT x.id, x.fecha, ep.estado AS estado_txt, x.id_colegio, c.colegio,
                                (SELECT COUNT(*) FROM planillas_devolucion pd WHERE pd.id_pedido = x.id AND pd.estado = " . PDV_ESTADO_ACTIVA . ") AS planillas
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN recursos r ON r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo
                         LEFT JOIN estados_pedidos ep ON ep.id = x.estado
                         WHERE x.id_periodo = ? AND x.estado <> 3 AND " . pdv_sql_vd($bdd) . $alcance . "
                         ORDER BY c.colegio, x.id DESC");
    $st->execute(array_merge([(int)$idPeriodo], $params));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Pedido de venta directa dentro del alcance (o null). */
function pdv_pedido(PDO $bdd, $idPedido) {
    asegurar_columnas_pedido_vd($bdd);
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT x.id, x.codigo, x.estado, x.id_colegio, x.id_periodo, c.colegio, c.ciudad
                         FROM pedidos x JOIN colegios c ON c.id = x.id_colegio
                         LEFT JOIN recursos r ON r.id_colegio = x.id_colegio AND r.id_periodo = x.id_periodo
                         WHERE x.id = ? AND " . pdv_sql_vd($bdd) . $alcance);
    $st->execute(array_merge([(int)$idPedido], $params));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Datos de la planilla de un pedido, calculados ahora (consulta World Office). Devuelve ['ok','error','datos' => [...]]
 * con las columnas de planillas_devolucion (titulos como arreglo [['titulo','codigo','unidades']]).
 */
function pdv_calcular(PDO $bdd, $idPedido) {
    $p = pdv_pedido($bdd, $idPedido);
    if (!$p) return ['ok' => false, 'error' => 'El pedido #' . (int)$idPedido . ' no existe, no es de venta directa o no está en tu alcance.'];
    if ((int)$p['estado'] === 3) return ['ok' => false, 'error' => 'El pedido #' . (int)$idPedido . ' está anulado.'];

    // Remisiones CEUR de las OP del pedido (dvv_documentos_de_ops con solo remisiones: prefijo CEUR; las RE22 no).
    $titulos = [];
    $remisiones = [];
    $ops = dvd_ops_pedido($bdd, 'v' . (int)$idPedido);
    if ($ops) {
        $wo = dvv_documentos_de_ops($bdd, $ops, true);
        if (!$wo['ok']) return ['ok' => false, 'error' => $wo['error'] ?: 'No se pudo consultar World Office.'];
        foreach ($wo['documentos'] as $doc) {
            $remisiones[] = trim($doc['documento']);
            foreach ($doc['renglones'] as $r) {
                $k = $r['producto_wo'] ?: ('c' . $r['codigo'] . '|' . $r['descripcion']);
                if (!isset($titulos[$k])) $titulos[$k] = ['titulo' => trim($r['descripcion']), 'codigo' => trim($r['codigo']), 'unidades' => 0];
                $titulos[$k]['unidades'] += (float)$r['cantidad'];
            }
        }
        $titulos = array_values(array_filter($titulos, fn($t) => $t['unidades'] > 0));
    }
    $origen = 'wo';
    if (!$titulos) {
        // Sin remisión CEUR: lo solicitado en el pedido.
        $origen = 'pedido';
        $remisiones = [];
        $st = $bdd->prepare("SELECT l.libro AS titulo, TRIM(l.isbn) AS codigo, SUM(lp.cantidad) AS unidades
                             FROM libros_pedidos lp JOIN libros l ON l.id = lp.id_libro
                             WHERE lp.cod_pedido = ? GROUP BY lp.id_libro HAVING unidades > 0");
        $st->execute([(string)$p['codigo']]);
        $titulos = array_map(fn($t) => ['titulo' => trim($t['titulo']), 'codigo' => (string)$t['codigo'], 'unidades' => (float)$t['unidades']], $st->fetchAll(PDO::FETCH_ASSOC));
        if (!$titulos) return ['ok' => false, 'error' => 'El pedido #' . (int)$idPedido . ' no tiene remisiones CEUR en World Office ni títulos solicitados.'];
    }
    usort($titulos, fn($a, $b) => strcoll(mb_strtoupper($a['titulo']), mb_strtoupper($b['titulo'])));

    // Fecha y responsable de la venta: los del formato de venta directa donde está el pedido; si no está en ninguno (o
    // le falta el dato), datos_venta_vd (fecha elegida en el pedido o de la adopción; responsable escrito al aprobar).
    $venta = datos_venta_vd($bdd, ['v' . (int)$idPedido]);
    try {
        $st = $bdd->prepare("SELECT fecha_venta, hora_inicio, hora_fin, responsable, tel_responsable FROM formatos_vd
                             WHERE estado = 1 AND FIND_IN_SET(?, REPLACE(ids_pedidos, ' ', '')) ORDER BY id DESC LIMIT 1");
        $st->execute(['v' . (int)$idPedido]);
        if ($fvd = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ($fvd as $k => $v) if (trim((string)$v) !== '') $venta[$k] = $v;
            if (!empty($fvd['fecha_venta']) && empty($fvd['hora_inicio'])) unset($venta['hora_inicio'], $venta['hora_fin']); // horario de otra fecha
        }
    } catch (PDOException $e) {} // sin tabla de formatos todavía
    $hora = fn($h) => $h ? date('g:i a', strtotime($h)) : '';

    return ['ok' => true, 'error' => null, 'datos' => [
        'id_pedido' => (int)$idPedido,
        'id_colegio' => (int)$p['id_colegio'],
        'id_periodo' => (int)$p['id_periodo'],
        'colegio' => $p['colegio'] . ($p['ciudad'] ? ' - ' . $p['ciudad'] : ''),
        'fecha_venta' => $venta['fecha_venta'] ?? null,
        'horario' => !empty($venta['hora_inicio']) ? $hora($venta['hora_inicio']) . ' a ' . $hora($venta['hora_fin'] ?? '') : null,
        'responsable' => trim((string)($venta['responsable'] ?? '')) ?: null,
        'tel_responsable' => trim((string)($venta['tel_responsable'] ?? '')) ?: null,
        'remisiones' => $origen === 'wo' ? mb_substr(implode(', ', $remisiones), 0, 500) : null,
        'origen' => $origen,
        'titulos' => $titulos,
        'total_unidades' => array_sum(array_column($titulos, 'unidades')),
    ]];
}

/** Genera y guarda una planilla del pedido. Devuelve ['ok','error','id']. */
function pdv_generar(PDO $bdd, $idPedido, $idUsuario) {
    pdv_asegurar($bdd);
    $c = pdv_calcular($bdd, $idPedido);
    if (!$c['ok']) return $c + ['id' => null];
    $d = $c['datos'];
    $d['titulos'] = json_encode($d['titulos'], JSON_UNESCAPED_UNICODE);
    $cols = array_keys($d);
    $bdd->prepare("INSERT INTO planillas_devolucion (" . implode(', ', $cols) . ", estado, id_usuario, created_at)
                   VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", " . PDV_ESTADO_ACTIVA . ", ?, NOW())")
        ->execute(array_merge(array_values($d), [(int)$idUsuario]));
    return ['ok' => true, 'error' => null, 'id' => (int)$bdd->lastInsertId()];
}

/** Planilla guardada dentro del alcance del usuario (o null), con titulos decodificados. */
function pdv_obtener(PDO $bdd, $id) {
    pdv_asegurar($bdd);
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT pd.*, CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario
                         FROM planillas_devolucion pd JOIN colegios c ON c.id = pd.id_colegio LEFT JOIN usuarios u ON u.id = pd.id_usuario
                         WHERE pd.id = ?" . $alcance);
    $st->execute(array_merge([(int)$id], $params));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;
    $f['titulos'] = json_decode($f['titulos'], true) ?: [];
    return $f;
}

/** Histórico del período (alcance del usuario), las más recientes primero. */
function pdv_listar(PDO $bdd, $idPeriodo) {
    pdv_asegurar($bdd);
    [$alcance, $params] = dm_filtro_alcance_sql();
    $st = $bdd->prepare("SELECT pd.id, pd.id_pedido, pd.colegio, pd.fecha_venta, pd.horario, pd.responsable, pd.remisiones, pd.origen, pd.total_unidades,
                                pd.adjunto, pd.adjunto_nombre, pd.adjunto_at, pd.estado, pd.motivo_anulacion, pd.anulada_at, pd.created_at,
                                CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                CONCAT(TRIM(ua.nombres), ' ', TRIM(ua.apellidos)) AS usuario_adjunto,
                                CONCAT(TRIM(un.nombres), ' ', TRIM(un.apellidos)) AS usuario_anula
                         FROM planillas_devolucion pd JOIN colegios c ON c.id = pd.id_colegio
                         LEFT JOIN usuarios u ON u.id = pd.id_usuario
                         LEFT JOIN usuarios ua ON ua.id = pd.adjunto_por
                         LEFT JOIN usuarios un ON un.id = pd.anulada_por
                         WHERE pd.id_periodo = ?" . $alcance . " ORDER BY pd.id DESC");
    $st->execute(array_merge([(int)$idPeriodo], $params));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Consecutivo visible: "PD-000012". */
function pdv_numero($id) {
    return 'PD-' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT);
}

/** Guarda el adjunto de la planilla ($archivo = entrada de $_FILES). Reemplaza el anterior. Devuelve ['ok','error']. */
function pdv_adjuntar(PDO $bdd, array $f, array $archivo, $idUsuario) {
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => ($archivo['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'El archivo es muy grande.' : 'Elige el archivo a adjuntar.'];
    }
    if ((int)$archivo['size'] > PDV_ADJ_MAX) return ['ok' => false, 'error' => 'El archivo pasa de 15 MB.'];
    $ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PDV_ADJ_EXT, true)) return ['ok' => false, 'error' => 'Tipo de archivo no permitido. Usa PDF, imagen, Word o Excel.'];
    $nombre = 'pdv' . (int)$f['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], pdv_dir_adjuntos() . $nombre)) return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    $bdd->prepare("UPDATE planillas_devolucion SET adjunto = ?, adjunto_nombre = ?, adjunto_at = NOW(), adjunto_por = ? WHERE id = ?")
        ->execute([$nombre, mb_substr(basename((string)$archivo['name']), 0, 255), (int)$idUsuario, (int)$f['id']]);
    // El archivo anterior se conserva en la carpeta (no se borra) por si hace falta recuperarlo.
    return ['ok' => true, 'error' => null];
}

/** Unidades sin decimales sobrantes: 3 / 2.5. */
function pdv_num($n) {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
}

/** PDF de una planilla guardada ($f = pdv_obtener). Devuelve el PDF como texto. */
function pdv_pdf(array $f) {
    require_once __DIR__ . '/../lib/FPDF/fpdf.php';
    require_once __DIR__ . '/planilla_pdf.php'; // lp_latin1, pdf_fit_text
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle(lp_latin1('Planilla de devolución ' . pdv_numero($f['id'])));
    $ancho = $pdf->GetPageWidth() - 24;
    $cols = [
        ['label' => 'No.', 'w' => 10, 'align' => 'C'],
        ['label' => 'Código', 'w' => 30, 'align' => 'C'],
        ['label' => 'Título', 'w' => $ancho - 10 - 30 - 28 - 30, 'align' => 'L'],
        ['label' => ($f['origen'] ?? 'wo') === 'pedido' ? 'Unidades solicitadas' : 'Unidades remisionadas', 'w' => 28, 'align' => 'C'],
        ['label' => 'Unidades contadas', 'w' => 30, 'align' => 'C'],
    ];
    $encabezado_tabla = function () use ($pdf, $cols) {
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetFillColor(237, 233, 254);
        $y = $pdf->GetY();
        foreach ($cols as $c) {
            $x = $pdf->GetX();
            $pdf->Cell($c['w'], 10, '', 1, 0, 'C', true);
            $lineas = $c['w'] < 32 && strpos($c['label'], ' ') !== false ? explode(' ', $c['label'], 2) : [$c['label']];
            foreach ($lineas as $i => $l) {
                $pdf->SetXY($x, $y + (10 - count($lineas) * 4) / 2 + $i * 4);
                $pdf->Cell($c['w'], 4, lp_latin1($l), 0, 0, 'C');
            }
            $pdf->SetXY($x + $c['w'], $y);
        }
        $pdf->Ln(10);
        $pdf->SetFont('Arial', '', 9);
    };
    $nueva_pagina = function ($continuacion) use ($pdf, $f) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->Cell(0, 8, lp_latin1('PLANILLA DE DEVOLUCIÓN'), 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(124, 58, 237);
        $pdf->Cell(0, 5, lp_latin1('No. ' . pdv_numero($f['id']) . ($continuacion ? '  (continuación)' : '')), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        if ((int)$f['estado'] !== PDV_ESTADO_ACTIVA) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(185, 28, 28);
            $pdf->Cell(0, 6, 'ANULADA', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    };

    $nueva_pagina(false);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 5, lp_latin1('Generada el ' . date('d/m/Y g:i a', strtotime($f['created_at'])) . (trim((string)($f['usuario'] ?? '')) !== '' ? ' por ' . trim($f['usuario']) : '')), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    $dato = function ($etq, $val, $w, $salto) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->Cell(40, 7, lp_latin1($etq), 'LTB', 0, 'L');
        $pdf->SetFont('Arial', '', 9.5);
        $pdf->Cell($w - 40, 7, pdf_fit_text($pdf, $val, $w - 42), 'RTB', $salto ? 1 : 0, 'L');
    };
    $mitad = $ancho / 2;
    $responsable = trim((string)$f['responsable']);
    $dato('Pedido #', (string)$f['id_pedido'], $mitad, false);
    $dato('Fecha de venta', $f['fecha_venta'] ? date('d/m/Y', strtotime($f['fecha_venta'])) . ($f['horario'] ? '  ' . $f['horario'] : '') : 'Sin fecha de venta', $mitad, true);
    $dato('Colegio', (string)$f['colegio'], $mitad, false);
    $dato('Responsable de venta', $responsable !== '' ? $responsable . (trim((string)$f['tel_responsable']) !== '' ? ' - ' . $f['tel_responsable'] : '') : 'Sin registrar', $mitad, true);
    $dato(($f['origen'] ?? 'wo') === 'pedido' ? 'Títulos' : 'Remisiones CEUR', ($f['origen'] ?? 'wo') === 'pedido'
        ? 'Solicitados en el pedido (sin remisión CEUR en World Office)' : (string)$f['remisiones'], $ancho, true);
    $pdf->Ln(5);

    $encabezado_tabla();
    $fila_h = 7;
    $limite = $pdf->GetPageHeight() - 12;
    foreach ($f['titulos'] as $i => $t) {
        if ($pdf->GetY() + $fila_h > $limite) { $nueva_pagina(true); $encabezado_tabla(); }
        $vals = [$i + 1, $t['codigo'], null, pdv_num($t['unidades']), ''];
        foreach ($cols as $j => $c) {
            $texto = $j === 2 ? pdf_fit_text($pdf, $t['titulo'], $c['w'] - 2) : lp_latin1((string)$vals[$j]);
            $pdf->Cell($c['w'], $fila_h, $texto, 1, 0, $c['align']);
        }
        $pdf->Ln($fila_h);
    }
    if ($pdf->GetY() + $fila_h > $limite) { $nueva_pagina(true); $encabezado_tabla(); }
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Cell($cols[0]['w'] + $cols[1]['w'] + $cols[2]['w'], $fila_h, 'TOTAL', 1, 0, 'R', true);
    $pdf->Cell($cols[3]['w'], $fila_h, pdv_num($f['total_unidades']), 1, 0, 'C', true);
    $pdf->Cell($cols[4]['w'], $fila_h, '', 1, 1, 'C', true);

    // Firmas (siempre completas en la misma hoja).
    if ($pdf->GetY() + 42 > $limite) $nueva_pagina(true);
    $pdf->Ln(24);
    $y = $pdf->GetY();
    $w_firma = ($ancho - 20) / 2;
    $firma = function ($x, $titulo, $nombre) use ($pdf, $y, $w_firma) {
        $pdf->Line($x, $y, $x + $w_firma, $y);
        $pdf->SetXY($x, $y + 1.5);
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->Cell($w_firma, 5, lp_latin1($titulo), 0, 2, 'C');
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->Cell($w_firma, 5, $nombre !== '' ? pdf_fit_text($pdf, $nombre, $w_firma) : lp_latin1('Nombre: ______________________________'), 0, 2, 'C');
    };
    $firma(12, 'Firma del responsable de venta', $responsable);
    $firma(12 + $w_firma + 20, 'Firma del auxiliar de bodega', '');

    return $pdf->Output('S');
}
