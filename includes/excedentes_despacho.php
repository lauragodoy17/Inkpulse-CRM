<?php
/**
 * Control de EXCEDENTES al despachar un pedido (original o pedido nuevo generado para atender su
 * backorder): cuando se asocia a la OP un documento de World Office (factura/remisión), se compara,
 * por producto, lo que trae ese documento contra lo solicitado en el pedido original. Si con este
 * documento lo despachado supera lo solicitado, se avisa y, al confirmar, queda registrado.
 *
 * Cálculo por producto de World Office (inventario.id):
 *   solicitada      suma de libros_pedidos.cantidad de los pedidos ORIGINALES de la OP (un pedido
 *                   nuevo de backorder se reemplaza por su original: su cantidad es parte del saldo
 *                   del original, no algo adicional)
 *   previa          lo ya despachado en OTROS documentos de esa familia de pedidos (caché
 *                   backorder_wo_docs/renglones, mismos documentos que usa calcular_lineas_backorder())
 *   en_documento    lo que trae el documento que se está asociando (en vivo desde World Office)
 *   excedente       max(0, min(en_documento, previa + en_documento − solicitada))
 * Solo se revisan los títulos que SÍ están en los pedidos (con cantidad > 0): un título del documento
 * que no está en el pedido (otro título, o un pack despachado por sus componentes) se ignora —
 * decisión de la usuaria 2026-09-30.
 *
 * Solo aplica a OPs de pedidos (tabla `pedidos`). Pedidos sin adopción, muestreos y devoluciones
 * no tienen backorder de pedidos, así que no se revisan.
 */
require_once(__DIR__ . '/backorders_datos.php');
require_once(__DIR__ . '/api_wo_documentos_op.php');

function excedentes_asegurar_tabla(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS excedentes_despacho (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_op INT NOT NULL,
        id_wo INT NOT NULL,
        n_doc VARCHAR(50) NOT NULL,
        pedidos VARCHAR(255) NOT NULL COMMENT 'ids de los pedidos originales, formato ,12,34,',
        producto_wo INT NOT NULL,
        codigo VARCHAR(100) NULL,
        descripcion VARCHAR(255) NULL,
        solicitada DECIMAL(12,2) NOT NULL DEFAULT 0,
        despachada_previa DECIMAL(12,2) NOT NULL DEFAULT 0,
        en_documento DECIMAL(12,2) NOT NULL DEFAULT 0,
        excedente DECIMAL(12,2) NOT NULL DEFAULT 0,
        origen VARCHAR(30) NOT NULL,
        id_usuario INT NOT NULL,
        fecha DATETIME NOT NULL,
        fecha_actualizacion DATETIME NULL,
        UNIQUE KEY uniq_op_doc_prod (id_op, id_wo, producto_wo),
        KEY idx_pedidos (pedidos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Pedidos ORIGINALES ligados a una OP (directo, agrupado o como origen de un pedido nuevo). */
function excedentes_pedidos_originales_op(PDO $bdd, $idOp) {
    backorders_asegurar_tablas($bdd);
    $stmt = $bdd->prepare("SELECT id_pedido FROM ordenes_pedidos WHERE id = ? AND id_pedido <> 0
                           UNION SELECT id_pedido FROM op_pedidos_agrupados WHERE op = ? AND tipo = 1
                           UNION SELECT id_pedido_origen FROM backorder_pedidos_generados WHERE id_op = ?");
    $stmt->execute([$idOp, $idOp, $idOp]);
    $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    if (empty($ids)) return [];
    $familias = backorders_familias($bdd, $ids);
    $originales = [];
    foreach ($ids as $idP) $originales[$familias['hijos'][$idP] ?? $idP] = true;
    return array_keys($originales);
}

/**
 * Compara el documento $idWo contra lo solicitado en los pedidos de la OP. Devuelve:
 *   aplica          false si la OP no es de pedidos
 *   ok / error      false si no se pudo consultar World Office
 *   pedidos         ids de los pedidos originales
 *   items           solo los productos con excedente (codigo, descripcion, solicitada, previa,
 *                   en_documento, excedente, producto_wo)
 *   total_excedente suma de excedentes
 *   sin_sincronizar hay documentos previos de la familia sin caché: lo "previo" puede estar corto
 *   ya_registrado   este documento ya se había confirmado con excedente en esta OP
 */
function excedentes_calcular(PDO $bdd, $idOp, $idWo) {
    $idOp = (int)$idOp;
    $idWo = (int)$idWo;
    $res = ['aplica' => false, 'ok' => true, 'error' => null, 'pedidos' => [], 'items' => [],
            'total_excedente' => 0.0, 'sin_sincronizar' => false, 'ya_registrado' => false];
    if ($idOp <= 0 || $idWo <= 0) return $res;

    $originales = excedentes_pedidos_originales_op($bdd, $idOp);
    if (empty($originales)) return $res;
    $res['aplica'] = true;
    $res['pedidos'] = $originales;

    // Documento que se está asociando, en vivo desde World Office.
    $resp = obtener_renglones_documento($idWo, 0, 200);
    if (($resp['status'] ?? '') !== 'OK') {
        $res['ok'] = false;
        $res['error'] = 'No se pudo consultar el documento en World Office para compararlo con lo solicitado.';
        return $res;
    }
    $doc = []; // producto_wo => [codigo, descripcion, cantidad]
    foreach ($resp['data']['content'] ?? [] as $r) {
        $inv = (int)($r['inventario']['id'] ?? 0);
        if ($inv <= 0) continue;
        if (!isset($doc[$inv])) {
            $doc[$inv] = ['codigo' => trim((string)($r['inventario']['codigo'] ?? '')),
                          'descripcion' => $r['inventario']['descripcion'] ?? '', 'cantidad' => 0.0];
        }
        $doc[$inv]['cantidad'] += (float)($r['cantidad'] ?? 0);
    }
    return excedentes_comparar($bdd, $idOp, $idWo, $doc, $res);
}

/**
 * Parte de excedentes_calcular() que no consulta World Office: recibe los productos del documento
 * ($doc: producto_wo => [codigo, descripcion, cantidad]) y $res ya con aplica/pedidos.
 */
function excedentes_comparar(PDO $bdd, $idOp, $idWo, array $doc, array $res) {
    $originales = $res['pedidos'];
    if (empty($doc)) return $res;

    // Solicitado por libro en los pedidos originales (no anulados).
    $ph = implode(',', array_fill(0, count($originales), '?'));
    $stmt = $bdd->prepare("SELECT lp.id_libro, SUM(lp.cantidad) AS cantidad
                           FROM libros_pedidos lp JOIN pedidos p ON p.codigo = lp.cod_pedido
                           WHERE p.estado != 3 AND p.id IN ($ph) GROUP BY lp.id_libro");
    $stmt->execute($originales);
    $solicitadaPorLibro = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $solicitadaPorLibro[(int)$r['id_libro']] = (float)$r['cantidad'];

    // Libros del catálogo que corresponden a cada producto del documento: por id_wo o por ISBN
    // (el catálogo tiene títulos repetidos, así que un producto puede ser varios libros).
    $librosPorProducto = [];
    if (!empty($solicitadaPorLibro)) {
        $phL = implode(',', array_fill(0, count($solicitadaPorLibro), '?'));
        $stmt = $bdd->prepare("SELECT id, id_wo, isbn FROM libros WHERE id IN ($phL)");
        $stmt->execute(array_keys($solicitadaPorLibro));
        $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($doc as $inv => $d) {
            foreach ($libros as $l) {
                if ((int)$l['id_wo'] === $inv || ($d['codigo'] !== '' && trim((string)$l['isbn']) === $d['codigo'])) {
                    $librosPorProducto[$inv][(int)$l['id']] = true;
                }
            }
        }
    }

    // Lo ya despachado en OTROS documentos de la familia (mismos documentos que usa el backorder).
    $calc = calcular_lineas_backorder($bdd, null, null, null, null, $originales);
    $clavesDocs = [];
    foreach ($calc['lineas'] as $l) {
        foreach ($l['ops'] as $op) $clavesDocs[$op['tipo_wo'] . '|' . $op['n_doc']] = [$op['tipo_wo'], $op['n_doc']];
    }
    $res['sin_sincronizar'] = !empty($calc['sinSincronizar']);
    $previa = []; // producto_wo => cantidad
    if (!empty($clavesDocs)) {
        $idsWoPrevios = [];
        $stmt = $bdd->prepare("SELECT id_wo FROM backorder_wo_docs WHERE tipo_documento = ? AND numero = ?");
        foreach ($clavesDocs as [$tipo, $numero]) {
            $stmt->execute([$tipo, $numero]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0 && $id !== $idWo) $idsWoPrevios[$id] = true;
        }
        if (!empty($idsWoPrevios)) {
            $phD = implode(',', array_fill(0, count($idsWoPrevios), '?'));
            $stmt = $bdd->prepare("SELECT id_libro, SUM(cantidad) FROM backorder_wo_renglones
                                   WHERE id_libro IS NOT NULL AND id_wo IN ($phD) GROUP BY id_libro");
            $stmt->execute(array_keys($idsWoPrevios));
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$inv, $cant]) $previa[(int)$inv] = (float)$cant;
        }
    }

    foreach ($doc as $inv => $d) {
        $solicitada = 0.0;
        foreach (array_keys($librosPorProducto[$inv] ?? []) as $idLibro) $solicitada += $solicitadaPorLibro[$idLibro] ?? 0;
        if ($solicitada <= 0) continue; // título que no está en el pedido: no se revisa
        $prev = $previa[$inv] ?? 0.0;
        $exc = max(0.0, min($d['cantidad'], $prev + $d['cantidad'] - $solicitada));
        if ($exc <= 0) continue;
        $res['items'][] = [
            'producto_wo' => $inv, 'codigo' => $d['codigo'], 'descripcion' => $d['descripcion'],
            'solicitada' => round($solicitada, 2), 'previa' => round($prev, 2),
            'en_documento' => round($d['cantidad'], 2), 'excedente' => round($exc, 2),
        ];
        $res['total_excedente'] += $exc;
    }
    $res['total_excedente'] = round($res['total_excedente'], 2);
    usort($res['items'], fn($a, $b) => strcmp($a['descripcion'], $b['descripcion']));

    if (!empty($res['items'])) {
        excedentes_asegurar_tabla($bdd);
        $stmt = $bdd->prepare("SELECT COUNT(*) FROM excedentes_despacho WHERE id_op = ? AND id_wo = ?");
        $stmt->execute([$idOp, $idWo]);
        $res['ya_registrado'] = (int)$stmt->fetchColumn() > 0;
    }
    return $res;
}

/** true si hay excedente y el usuario todavía no lo confirmó (ni se había confirmado antes). */
function excedentes_requiere_confirmacion(array $calc, $confirmado) {
    return !empty($calc['items']) && !$calc['ya_registrado'] && !$confirmado;
}

/** Mensaje corto para redirecciones/errores cuando falta confirmar. */
function excedentes_mensaje(array $calc) {
    $partes = [];
    foreach ($calc['items'] as $it) {
        $partes[] = $it['descripcion'] . ': ' . excedentes_fmt($it['excedente']) . ' und. de más';
    }
    return 'El documento envía ' . excedentes_fmt($calc['total_excedente']) . ' unidades adicionales a las solicitadas (' . implode('; ', $partes) . ').';
}

function excedentes_fmt($v) {
    $v = (float)$v;
    return floor($v) == $v ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.');
}

/**
 * Deja constancia del excedente confirmado. Si el mismo documento ya estaba registrado para la OP
 * (p. ej. se guardó y luego se atendió con él), se actualizan las cifras sin duplicar.
 */
function excedentes_registrar(PDO $bdd, $idOp, $idWo, $nDoc, array $calc, $origen, $idUsuario) {
    if (empty($calc['items'])) return;
    excedentes_asegurar_tabla($bdd);
    $pedidos = ',' . implode(',', $calc['pedidos']) . ',';
    $stmt = $bdd->prepare("INSERT INTO excedentes_despacho
            (id_op, id_wo, n_doc, pedidos, producto_wo, codigo, descripcion, solicitada, despachada_previa,
             en_documento, excedente, origen, id_usuario, fecha)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE n_doc = VALUES(n_doc), pedidos = VALUES(pedidos), solicitada = VALUES(solicitada),
             despachada_previa = VALUES(despachada_previa), en_documento = VALUES(en_documento),
             excedente = VALUES(excedente), fecha_actualizacion = NOW()");
    foreach ($calc['items'] as $it) {
        $stmt->execute([(int)$idOp, (int)$idWo, $nDoc, $pedidos, $it['producto_wo'], $it['codigo'], $it['descripcion'],
                        $it['solicitada'], $it['previa'], $it['en_documento'], $it['excedente'], $origen, (int)$idUsuario]);
    }
}

/**
 * Excedentes registrados, para mostrarlos como trazabilidad. $idPedido: los de ese pedido (si es un
 * pedido nuevo de backorder, los de su pedido original). $idOp: los de esa OP. `vigente` indica si
 * el documento sigue siendo el de la OP (actual o usado en un despacho) o si luego se reemplazó.
 */
function excedentes_listar(PDO $bdd, $idPedido = null, $idOp = null) {
    excedentes_asegurar_tabla($bdd);
    $where = [];
    $params = [];
    if ($idPedido) {
        $fam = backorders_familias($bdd, [(int)$idPedido]);
        $orig = $fam['hijos'][(int)$idPedido] ?? (int)$idPedido;
        $where[] = "e.pedidos LIKE ?";
        $params[] = '%,' . $orig . ',%';
    }
    if ($idOp) {
        $where[] = "e.id_op = ?";
        $params[] = (int)$idOp;
    }
    if (empty($where)) return [];
    $stmt = $bdd->prepare("SELECT e.*, o.año, o.n_doc AS n_doc_op,
                                  CONCAT(TRIM(u.nombres), ' ', TRIM(u.apellidos)) AS usuario,
                                  EXISTS(SELECT 1 FROM op_atendidas a WHERE a.opid = e.id_op AND a.n_doc = e.n_doc) AS en_despacho
                           FROM excedentes_despacho e
                           JOIN ordenes_pedidos o ON o.id = e.id_op
                           LEFT JOIN usuarios u ON u.id = e.id_usuario
                           WHERE " . implode(' AND ', $where) . "
                           ORDER BY e.fecha DESC, e.descripcion");
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as &$f) {
        $f['vigente'] = $f['en_despacho'] || trim((string)$f['n_doc_op']) === trim((string)$f['n_doc']);
    }
    unset($f);
    return $filas;
}

/** Tarjeta HTML de trazabilidad (pedido_colegio.php y op_pendiente.php). '' si no hay registros. */
function excedentes_html(array $filas, $mostrarOp = true) {
    if (empty($filas)) return '';
    $h = '<div class="exc-card"><div class="exc-head"><i class="bi bi-exclamation-triangle-fill"></i> Unidades despachadas por encima de lo solicitado</div>'
       . '<div class="table-responsive"><table class="exc-tabla"><thead><tr><th>Fecha</th>'
       . ($mostrarOp ? '<th>OP</th>' : '') . '<th>Documento</th><th>Título</th><th class="num">Solicitado</th>'
       . '<th class="num">Despachado antes</th><th class="num">En el documento</th><th class="num">De más</th><th>Confirmado por</th></tr></thead><tbody>';
    foreach ($filas as $f) {
        $h .= '<tr' . ($f['vigente'] ? '' : ' class="exc-reemplazado" title="Este documento después se reemplazó en la OP"') . '>'
            . '<td>' . htmlspecialchars(date('d/m/Y H:i', strtotime($f['fecha']))) . '</td>'
            . ($mostrarOp ? '<td><a href="op_pendiente.php?op=' . (int)$f['id_op'] . '" target="_blank">' . htmlspecialchars($f['año'] . '-' . $f['id_op']) . '</a></td>' : '')
            . '<td>' . htmlspecialchars($f['n_doc']) . ($f['vigente'] ? '' : ' <span class="exc-tag">reemplazado</span>') . '</td>'
            . '<td>' . htmlspecialchars($f['descripcion']) . '</td>'
            . '<td class="num">' . excedentes_fmt($f['solicitada']) . '</td>'
            . '<td class="num">' . excedentes_fmt($f['despachada_previa']) . '</td>'
            . '<td class="num">' . excedentes_fmt($f['en_documento']) . '</td>'
            . '<td class="num exc-mas">+' . excedentes_fmt($f['excedente']) . '</td>'
            . '<td>' . htmlspecialchars($f['usuario'] ?: '—') . '</td></tr>';
    }
    $h .= '</tbody></table></div></div>';
    return $h;
}

function excedentes_estilos() {
    return '<style>
      .exc-card { border: 1.5px solid #fde68a; background: #fffbeb; border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
      .exc-head { padding: 12px 18px; font-weight: 700; color: #92400e; font-size: .9rem; display: flex; gap: 8px; align-items: center; }
      table.exc-tabla { width: 100%; border-collapse: collapse; font-size: .8rem; background: #fff; }
      table.exc-tabla th, table.exc-tabla td { border-top: 1px solid #fde68a; padding: 7px 12px; }
      table.exc-tabla thead th { background: #fef3c7; color: #78350f; font-weight: 700; white-space: nowrap; }
      table.exc-tabla .num { text-align: center; font-variant-numeric: tabular-nums; }
      table.exc-tabla .exc-mas { color: #b45309; font-weight: 800; }
      table.exc-tabla tr.exc-reemplazado td { color: #94a3b8; }
      .exc-tag { font-size: .68rem; background: #f1f5f9; color: #64748b; border-radius: 10px; padding: 1px 7px; }
    </style>';
}
