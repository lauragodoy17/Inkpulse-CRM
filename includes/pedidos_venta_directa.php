<?php
// Pedido para Andrea Díaz (venta directa): al solicitar el pedido se marca si es para ella y, si lo es,
// se capturan los mismos datos generales del formato de venta directa (formatos_vd: fecha/horas de la
// venta, responsable del colegio, teléfonos, dirección y observaciones). Se guardan en el propio pedido.

function asegurar_columnas_pedido_vd(PDO $bdd) {
	static $hecho = false;
	if ($hecho) return;
	$hecho = true;
	$columnas = [
		"para_andrea TINYINT NOT NULL DEFAULT 0",
		"vd_fecha_venta DATE NULL",
		"vd_hora_inicio TIME NULL",
		"vd_hora_fin TIME NULL",
		"vd_contacto_colegio VARCHAR(150) NULL",
		"vd_tel_contacto VARCHAR(40) NULL",
		"vd_direccion VARCHAR(255) NULL",
		"vd_observaciones TEXT NULL",
		// Responsable de la venta: se pregunta al aprobar el pedido (php/aprobar_pedido.php, 2026-10-26).
		"vd_responsable VARCHAR(150) NULL",
		// Teléfono del responsable de la venta: también se pregunta al aprobar (2026-11-13).
		"vd_tel_responsable VARCHAR(40) NULL",
		// Fecha de venta de la adopción elegida al solicitar el pedido (adopcion_vd_jornadas.id, opcional, 2026-11-25).
		"vd_id_jornada INT NULL",
	];
	foreach ($columnas as $col) {
		try {
			$bdd->exec("ALTER TABLE pedidos ADD COLUMN $col");
		} catch (Exception $e) {}
	}
}

/** Lee el formulario de solicitar_pedido.php: ['ok','error','datos'] con las columnas de pedidos. */
function leer_pedido_vd(array $post) {
	$para = (string)($post['para_andrea'] ?? '');
	if ($para !== '0' && $para !== '1') return ['ok' => false, 'error' => 'Indica si el pedido es para jornada de venta en el colegio.'];

	$vacio = ['para_andrea' => 0, 'vd_fecha_venta' => null, 'vd_hora_inicio' => null, 'vd_hora_fin' => null,
		'vd_contacto_colegio' => null, 'vd_tel_contacto' => null, 'vd_direccion' => null, 'vd_observaciones' => null];
	if ($para === '0') return ['ok' => true, 'error' => null, 'datos' => $vacio];

	$fecha = (string)($post['vd_fecha_venta'] ?? '');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) return ['ok' => false, 'error' => 'La fecha de venta es obligatoria.'];
	$hora  = function ($v) { $v = trim((string)$v); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null; };
	$texto = function ($v, $max) { $v = mb_substr(trim(str_replace(["'", '"'], '', (string)$v)), 0, $max); return $v === '' ? null : $v; };

	// 2026-10-26: todos los datos de la jornada de venta son obligatorios.
	$datos = [
		'para_andrea' => 1,
		'vd_fecha_venta' => $fecha,
		'vd_hora_inicio' => $hora($post['vd_hora_inicio'] ?? ''),
		'vd_hora_fin' => $hora($post['vd_hora_fin'] ?? ''),
		'vd_contacto_colegio' => $texto($post['vd_contacto_colegio'] ?? '', 150),
		'vd_tel_contacto' => $texto($post['vd_tel_contacto'] ?? '', 40),
		'vd_direccion' => $texto($post['vd_direccion'] ?? '', 255),
		'vd_observaciones' => $texto($post['vd_observaciones'] ?? '', 2000),
	];
	$nombres = ['vd_hora_inicio' => 'la hora de venta (desde)', 'vd_hora_fin' => 'la hora de venta (hasta)',
		'vd_contacto_colegio' => 'el contacto del colegio', 'vd_tel_contacto' => 'los teléfonos de contacto',
		'vd_direccion' => 'la dirección de la venta']; // las observaciones de la venta son opcionales
	foreach ($nombres as $campo => $nombre) {
		if ($datos[$campo] === null) return ['ok' => false, 'error' => 'Completa ' . $nombre . '.'];
	}
	if ($datos['vd_hora_fin'] <= $datos['vd_hora_inicio']) return ['ok' => false, 'error' => 'La hora de venta (hasta) debe ser posterior a la hora (desde).'];
	// La recogida va desde 2 días antes de la venta hasta el mismo día (2026-10-26).
	$recogida = (string)($post['fecha_r'] ?? '');
	$desde = date('Y-m-d', strtotime($fecha . ' -2 days'));
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recogida) || $recogida < $desde || $recogida > $fecha) {
		return ['ok' => false, 'error' => 'La fecha de recogida debe estar entre ' . date('d/m/Y', strtotime($desde)) . ' y ' . date('d/m/Y', strtotime($fecha)) . ' (hasta 2 días antes de la venta).'];
	}

	return ['ok' => true, 'error' => null, 'datos' => $datos];
}

/**
 * Fechas de venta (adopcion_vd_jornadas.id) que ya eligió otro pedido no anulado del colegio en el período (2026-11-25):
 * cada fecha se asigna a un solo pedido, así que no se ofrecen ni se aceptan en un pedido nuevo. [id_jornada => id_pedido].
 */
function pedido_vd_jornadas_usadas(PDO $bdd, $idColegio, $idPeriodo) {
	asegurar_columnas_pedido_vd($bdd);
	$st = $bdd->prepare("SELECT vd_id_jornada, id FROM pedidos WHERE id_colegio = ? AND id_periodo = ? AND vd_id_jornada IS NOT NULL AND estado <> 3");
	$st->execute([(int)$idColegio, (int)$idPeriodo]);
	return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Pedidos de cada fecha de venta con el punto en que van (2026-11-25, la usuaria: modal del calendario de venta directa).
 * Pedidos de una fecha: los que la eligieron al solicitarse (pedidos.vd_id_jornada) y los del formato de venta directa que
 * atiende esa fecha ($atendidas = adopcion_vd_atendidas). Sin anulados. Estado, del más avanzado al menos:
 *  - cerrado: su formato de venta directa con la operación cerrada (enlace al formato).
 *  - devolucion: tiene devolución de venta no anulada (enlace a la devolución).
 *  - facturacion: el pedido está en Facturación, En despacho o Entregado; documento de WO guardado en su OP (sin enlace).
 *  - procesando / pendiente / aprobado: enlace al pedido.
 * Devuelve [id_jornada => [['id','estado','titulo','detalle','url'], ...]].
 */
function pedido_vd_estados_por_jornada(PDO $bdd, array $idsJornadas, array $atendidas) {
	$idsJornadas = array_values(array_filter(array_map('intval', $idsJornadas)));
	if (!$idsJornadas) return [];
	asegurar_columnas_pedido_vd($bdd);
	$porPedido = []; // id_pedido => [id_jornada, ...]
	$st = $bdd->query("SELECT id, vd_id_jornada FROM pedidos WHERE estado <> 3 AND vd_id_jornada IN (" . implode(',', $idsJornadas) . ")");
	foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porPedido[(int)$r['id']][(int)$r['vd_id_jornada']] = true;
	$formatos = [];
	foreach ($atendidas as $idJ => $f) if (in_array((int)$idJ, $idsJornadas, true)) $formatos[(int)$f['id']] = (int)$idJ;
	$formatoDe = []; // id_pedido => ['id','consecutivo','etapa']
	if ($formatos) {
		$st = $bdd->query("SELECT id, consecutivo, etapa, ids_pedidos FROM formatos_vd WHERE estado = 1 AND id IN (" . implode(',', array_keys($formatos)) . ")");
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
			foreach (array_filter(explode(',', str_replace(' ', '', (string)$f['ids_pedidos']))) as $c) {
				if (!preg_match('/^v(\d+)$/', $c, $m)) continue;
				$porPedido[(int)$m[1]][$formatos[(int)$f['id']]] = true;
				$formatoDe[(int)$m[1]] = $f;
			}
		}
	}
	if (!$porPedido) return [];
	$ids = implode(',', array_keys($porPedido));
	// Formato de los pedidos que eligieron la fecha pero cuyo formato no es el que atiende la fecha.
	$sinFormato = array_diff(array_keys($porPedido), array_keys($formatoDe));
	if ($sinFormato) {
		$cond = implode(' OR ', array_map(fn($id) => "FIND_IN_SET('v" . (int)$id . "', REPLACE(ids_pedidos, ' ', ''))", $sinFormato));
		foreach ($bdd->query("SELECT id, consecutivo, etapa, ids_pedidos FROM formatos_vd WHERE estado = 1 AND ($cond)")->fetchAll(PDO::FETCH_ASSOC) as $f) {
			foreach ($sinFormato as $id) if (in_array('v' . $id, explode(',', str_replace(' ', '', $f['ids_pedidos'])), true)) $formatoDe[$id] = $f;
		}
	}
	$pedidos = $bdd->query("SELECT p.id, p.estado, ep.estado AS estado_txt FROM pedidos p LEFT JOIN estados_pedidos ep ON ep.id = p.estado
	                        WHERE p.id IN ($ids) AND p.estado <> 3 ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
	// Devolución de venta más reciente (no anulada) de cada pedido.
	$devolucion = [];
	$cond = implode(' OR ', array_map(fn($id) => "FIND_IN_SET('v" . (int)$id . "', REPLACE(ids_pedidos_venta, ' ', ''))", array_keys($porPedido)));
	foreach ($bdd->query("SELECT id, ids_pedidos_venta FROM devoluciones_v WHERE estado <> 3 AND ($cond) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
		foreach (explode(',', str_replace(' ', '', (string)$d['ids_pedidos_venta'])) as $c) if (preg_match('/^v(\d+)$/', $c, $m)) $devolucion[(int)$m[1]] = (int)$d['id'];
	}
	// Documento de WO de la OP del pedido (directa o agrupada; la más reciente no anulada).
	$documento = [];
	$st = $bdd->query("SELECT x.id_pedido, o.n_doc, t.tipo FROM (
	                       SELECT id_pedido, id AS op FROM ordenes_pedidos WHERE id_pedido IN ($ids) AND estado <> 4
	                       UNION SELECT id_pedido, op FROM op_pedidos_agrupados WHERE id_pedido IN ($ids)
	                   ) x JOIN ordenes_pedidos o ON o.id = x.op AND o.estado <> 4 LEFT JOIN tipo_doc t ON t.id = o.tipo_doc
	                   ORDER BY o.id");
	foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
		if (trim((string)$r['n_doc']) !== '') $documento[(int)$r['id_pedido']] = trim(trim((string)$r['tipo']) . ' ' . trim($r['n_doc']));
	}

	$res = [];
	foreach ($pedidos as $p) {
		$id = (int)$p['id'];
		$f = $formatoDe[$id] ?? null;
		$verPedido = 'pedido_colegio.php?id_pedido=' . $id;
		if ($f && (int)$f['etapa'] >= 7) {
			$e = ['estado' => 'cerrado', 'titulo' => 'Cerrado', 'detalle' => 'Formato venta directa No. ' . (int)$f['consecutivo'], 'url' => 'formato_venta_directa.php?id=' . (int)$f['id']];
		} elseif (isset($devolucion[$id])) {
			$e = ['estado' => 'devolucion', 'titulo' => 'En devolución', 'detalle' => 'Devolución No. ' . $devolucion[$id], 'url' => 'devolucion_colegio.php?id_pedido=' . $devolucion[$id]];
		} elseif (in_array((int)$p['estado'], [4, 6, 7], true)) {
			$e = ['estado' => 'facturacion', 'titulo' => (int)$p['estado'] === 6 ? 'En facturación' : (string)$p['estado_txt'],
			      'detalle' => isset($documento[$id]) ? 'Factura WO ' . $documento[$id] : 'Sin documento de WO registrado', 'url' => ''];
		} else {
			$e = ['estado' => (int)$p['estado'] === 5 ? 'procesando' : 'pendiente', 'titulo' => (string)$p['estado_txt'], 'detalle' => '', 'url' => ''];
		}
		$e = ['id' => $id] + $e + ['pedido_url' => $verPedido];
		foreach (array_keys($porPedido[$id]) as $idJ) $res[$idJ][] = $e;
	}
	return $res;
}

/**
 * Pregunta opcional al solicitar un pedido de un colegio con canal "Venta directa - Eureka" (2026-11-25, la usuaria):
 * ¿Tiene fecha de venta? (vd_tiene_fecha) Sí → se elige una de las fechas registradas en el colegio (vd_id_jornada,
 * sin las canceladas); se copian su fecha y horario al pedido. Sin responder o con "No" el pedido se guarda igual.
 * El responsable del colegio NO se pide aquí: se registra en la adopción al agregar fechas de venta (Adopciones o calendario). Devuelve ['ok','error','datos' => [columna de pedidos => valor]].
 */
function leer_pedido_vd_opcional(PDO $bdd, array $post, $idColegio, $idPeriodo) {
	$datos = [];
	if ((string)($post['vd_tiene_fecha'] ?? '') === '1') {
		require_once __DIR__ . '/adopcion_vd_datos.php';
		adopcion_vd_asegurar($bdd);
		$st = $bdd->prepare("SELECT id, fecha, hora_inicio, hora_fin FROM adopcion_vd_jornadas WHERE id = ? AND id_colegio = ? AND id_periodo = ? AND cancelada = 0");
		$st->execute([(int)($post['vd_id_jornada'] ?? 0), (int)$idColegio, (int)$idPeriodo]);
		$j = $st->fetch(PDO::FETCH_ASSOC);
		if (!$j) return ['ok' => false, 'error' => 'Elige una de las fechas de venta del colegio (o responde "No" a si tiene fecha de venta).'];
		if (isset(pedido_vd_jornadas_usadas($bdd, $idColegio, $idPeriodo)[(int)$j['id']])) {
			return ['ok' => false, 'error' => 'Esa fecha de venta ya está asignada a otro pedido del colegio. Elige otra.'];
		}
		$datos += ['vd_id_jornada' => (int)$j['id'], 'vd_fecha_venta' => $j['fecha'], 'vd_hora_inicio' => $j['hora_inicio'], 'vd_hora_fin' => $j['hora_fin']];
	}
	return ['ok' => true, 'error' => null, 'datos' => $datos];
}

/**
 * Datos generales para prellenar el paso 1 del formato de venta directa (claves de formatos_vd) a partir de
 * los pedidos para Andrea Díaz incluidos en él. Si varios pedidos los traen, se toma el primero que tenga
 * cada dato (en orden de pedido) y las observaciones distintas se juntan.
 */
function datos_formato_vd_de_pedidos(PDO $bdd, array $idsPedidos) {
	$idsPedidos = array_values(array_filter(array_map('intval', $idsPedidos)));
	if (!$idsPedidos) return [];
	asegurar_columnas_pedido_vd($bdd);
	$st = $bdd->prepare("SELECT vd_fecha_venta, vd_hora_inicio, vd_hora_fin, vd_contacto_colegio, vd_tel_contacto, vd_direccion, vd_observaciones, vd_responsable
	                     FROM pedidos WHERE para_andrea = 1 AND id IN (" . implode(',', array_fill(0, count($idsPedidos), '?')) . ") ORDER BY id");
	$st->execute($idsPedidos);
	$mapa = ['fecha_venta' => 'vd_fecha_venta', 'hora_inicio' => 'vd_hora_inicio', 'hora_fin' => 'vd_hora_fin',
	         'contacto_colegio' => 'vd_contacto_colegio', 'tel_contacto' => 'vd_tel_contacto', 'direccion' => 'vd_direccion',
	         'responsable' => 'vd_responsable']; // escrito al aprobar el pedido (2026-10-26)
	$datos = [];
	$obs = [];
	foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
		foreach ($mapa as $campo => $col) {
			if (!isset($datos[$campo]) && (string)$r[$col] !== '') $datos[$campo] = $r[$col];
		}
		$o = trim((string)$r['vd_observaciones']);
		if ($o !== '' && !in_array($o, $obs, true)) $obs[] = $o;
	}
	if ($obs) $datos['observaciones'] = implode("\n", $obs);
	return $datos;
}

/** Datos del formato que vienen de los pedidos (claves "v210") y por eso no se pueden editar: [campo => valor como en el formulario]. */
function datos_fijos_formato_vd(PDO $bdd, array $claves) {
	$ids = [];
	foreach ($claves as $c) if (preg_match('/^v(\d+)$/', (string)$c, $m)) $ids[] = (int)$m[1];
	$datos = datos_formato_vd_de_pedidos($bdd, $ids);
	foreach (['hora_inicio', 'hora_fin'] as $h) if (isset($datos[$h])) $datos[$h] = substr($datos[$h], 0, 5);
	return $datos;
}

/**
 * Responsable de la venta escrito al aprobar los pedidos para Andrea Díaz (pedidos.vd_responsable), el del primer
 * pedido que lo tenga. Solo propone el valor del paso 2 (Entrega de bodega) del formato: no es un dato fijo.
 */
function responsable_venta_de_pedidos(PDO $bdd, array $claves) {
	$ids = [];
	foreach ($claves as $c) if (preg_match('/^v(\d+)$/', (string)$c, $m)) $ids[] = (int)$m[1];
	if (!$ids) return '';
	asegurar_columnas_pedido_vd($bdd);
	// 2026-11-13: cualquier pedido de venta directa (canal "Venta directa - Eureka"), no solo los marcados para Andrea Díaz.
	$st = $bdd->prepare("SELECT vd_responsable FROM pedidos WHERE TRIM(COALESCE(vd_responsable, '')) <> ''
	                     AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id LIMIT 1");
	$st->execute($ids);
	return (string)$st->fetchColumn();
}

/** Reemplaza en lo enviado por el formulario los datos fijos del pedido (lo que llegue del navegador para esos campos no cuenta). */
function post_con_datos_fijos_vd(PDO $bdd, array $claves, array $post) {
	return array_merge($post, datos_fijos_formato_vd($bdd, $claves));
}

/**
 * Datos de la venta para el formato de venta directa (2026-11-13, la usuaria). Se toman primero de la venta a la que
 * fue asignado el colegio y después de lo escrito en el pedido:
 *  - fecha y hora de la venta: la jornada de la adopción (adopcion_vd_jornadas); si no hay, las del pedido.
 *    Jornada: la del día $fechaRef; si no, la última hasta $fechaRef; si no, la primera. Se saltan las que ya tienen
 *    otro formato ($fechasOcupadas).
 *  - responsable del colegio y teléfono: el contacto de la adopción (recursos.vd_contacto_*); si no, los del pedido.
 *  - dirección: la del colegio (colegios.direccion, ciudad); si no, la del pedido.
 *  - responsable de la venta y su teléfono: los que se escribieron al aprobar el pedido.
 * Devuelve [campo de formatos_vd => valor] solo con los datos que se encontraron.
 */
function datos_venta_vd(PDO $bdd, array $claves, $fechaRef = null, array $fechasOcupadas = []) {
	$ids = [];
	foreach ($claves as $c) if (preg_match('/^v(\d+)$/', (string)$c, $m)) $ids[] = (int)$m[1];
	if (!$ids) return [];
	asegurar_columnas_pedido_vd($bdd);
	$st = $bdd->prepare("SELECT id_colegio, id_periodo, vd_fecha_venta, vd_hora_inicio, vd_hora_fin, vd_contacto_colegio, vd_tel_contacto,
	                            vd_direccion, vd_responsable, vd_tel_responsable, vd_id_jornada
	                     FROM pedidos WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
	$st->execute($ids);
	$pedidos = $st->fetchAll(PDO::FETCH_ASSOC);
	if (!$pedidos) return [];
	$dePedido = function ($col) use ($pedidos) {
		foreach ($pedidos as $p) if (trim((string)$p[$col]) !== '') return $p[$col];
		return null;
	};
	$idColegio = (int)$pedidos[0]['id_colegio'];
	$idPeriodo = (int)$pedidos[0]['id_periodo'];
	require_once __DIR__ . '/adopcion_vd_datos.php';
	$adop = adopcion_vd_leer($bdd, $idColegio, $idPeriodo);

	$res = [];
	// Jornada de la venta.
	$jornadas = array_values(array_filter($adop['jornadas'], fn($j) => !in_array($j['fecha'], $fechasOcupadas, true)));
	$jornada = null;
	// 2026-11-25: la fecha de venta elegida al solicitar el pedido manda, si sigue vigente y libre.
	foreach ($pedidos as $p) {
		if (!(int)$p['vd_id_jornada'] || $jornada) continue;
		foreach ($jornadas as $j) if ($j['fecha'] === $p['vd_fecha_venta']) { $jornada = $j; break; }
	}
	if ($jornadas && !$jornada) {
		$ref = $fechaRef ?: date('Y-m-d');
		foreach ($jornadas as $j) if ($j['fecha'] === $ref) { $jornada = $j; break; }
		if (!$jornada) foreach ($jornadas as $j) if ($j['fecha'] <= $ref) $jornada = $j;
		if (!$jornada) $jornada = $jornadas[0];
	}
	if ($jornada) {
		$res['fecha_venta'] = $jornada['fecha'];
		$res['hora_inicio'] = $jornada['hora_inicio'] . ':00';
		$res['hora_fin'] = $jornada['hora_fin'] . ':00';
	} else {
		if ($v = $dePedido('vd_fecha_venta')) $res['fecha_venta'] = $v;
		if ($v = $dePedido('vd_hora_inicio')) $res['hora_inicio'] = $v;
		if ($v = $dePedido('vd_hora_fin')) $res['hora_fin'] = $v;
	}
	// Responsable del colegio.
	$nombre = trim($adop['contacto']['nombre']);
	$cel = trim($adop['contacto']['celular']);
	if ($nombre !== '' || $cel !== '') {
		if ($nombre !== '') $res['contacto_colegio'] = $nombre;
		if ($cel !== '') $res['tel_contacto'] = $cel;
	}
	if (!isset($res['contacto_colegio']) && ($v = $dePedido('vd_contacto_colegio'))) $res['contacto_colegio'] = $v;
	if (!isset($res['tel_contacto']) && ($v = $dePedido('vd_tel_contacto'))) $res['tel_contacto'] = $v;
	// Dirección del colegio.
	$st = $bdd->prepare("SELECT direccion, ciudad FROM colegios WHERE id = ?");
	$st->execute([$idColegio]);
	$col = $st->fetch(PDO::FETCH_ASSOC) ?: [];
	$dir = trim((string)($col['direccion'] ?? ''));
	if ($dir !== '') $res['direccion'] = $dir . (trim((string)($col['ciudad'] ?? '')) !== '' ? ', ' . trim($col['ciudad']) : '');
	elseif ($v = $dePedido('vd_direccion')) $res['direccion'] = $v;
	// Responsable de la venta.
	if ($v = $dePedido('vd_responsable')) $res['responsable'] = $v;
	if ($v = $dePedido('vd_tel_responsable')) $res['tel_responsable'] = $v;
	foreach ($res as $k => $v) $res[$k] = mb_substr((string)$v, 0, $k === 'direccion' ? 255 : ($k === 'responsable' || $k === 'contacto_colegio' ? 150 : 40));
	return $res;
}
