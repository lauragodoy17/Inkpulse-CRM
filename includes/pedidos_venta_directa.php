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
	                            vd_direccion, vd_responsable, vd_tel_responsable
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
	if ($jornadas) {
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
