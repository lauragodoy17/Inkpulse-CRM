<?php
/**
 * Armado de paquetes por grado para colegios con Tipo de adopción = Paquetes/Ambos.
 * Ver tabla `paquetes_colegio` (un registro por colegio+periodo+grado) y
 * `paquetes_colegio_libros` (detalle de títulos adoptados que componen cada paquete).
 */

/**
 * Resuelve empresa/zona/responsable de un colegio a partir de su cod_zona, con la
 * MISMA convención que ya usa `php/colegios_tabla.php` (la fuente de verdad del
 * listado principal de colegios): se busca el usuario cuyo cod_zona coincide con
 * el del colegio; si su tipo es Promotor/Administrador/Jefe Comercial (1, 3, 10),
 * la "empresa" es lo que hay antes de la "/" en `zonas.zona` (ej. "EUREKA") y el
 * responsable es ese usuario. Si no hay usuario asignado a esa zona, devuelve null
 * (colegio sin empresa/responsable asignado).
 */
function resolver_empresa_colegio(PDO $bdd, $cod_zona) {
	$cod_zona = trim((string)$cod_zona);
	if ($cod_zona === '' || $cod_zona === '0') return null;

	$req = $bdd->prepare("SELECT z.zona, CONCAT(u.nombres,' ',u.apellidos) AS promotor, u.tipo
	                       FROM zonas z JOIN usuarios u ON z.codigo = u.cod_zona
	                       WHERE z.codigo = ?");
	$req->execute([$cod_zona]);
	$row = $req->fetch(PDO::FETCH_ASSOC);
	if (!$row) return null;

	if (in_array((int)$row['tipo'], [1, 3, 10], true)) {
		$partes = explode('/', $row['zona'], 2);
		return [
			'empresa'     => trim($partes[0]),
			'zona'        => trim($partes[1] ?? ''),
			'responsable' => $row['promotor'],
		];
	}

	// Otros tipos (ej. 6 = Distribuidor): la zona no tiene el formato "EMPRESA/subzona",
	// es directamente el nombre de la empresa/distribuidor.
	return [
		'empresa'     => trim($row['zona']),
		'zona'        => '',
		'responsable' => $row['promotor'],
	];
}

/**
 * true si el colegio (según su cod_zona) es de un promotor Eureka (usuarios.tipo=3)
 * o de Héctor Morales (usuarios.id=69) — el criterio "eureka" pedido explícitamente
 * por el usuario para el selector del reporte y el filtro de datos. Usado por
 * obtener_datos_reporte_paquetes() para decidir qué colegios entran al reporte.
 */
function es_colegio_eureka(PDO $bdd, $cod_zona) {
	$cod_zona = trim((string)$cod_zona);
	if ($cod_zona === '' || $cod_zona === '0') return false;

	$req = $bdd->prepare("SELECT id, tipo FROM usuarios WHERE cod_zona = ? LIMIT 1");
	$req->execute([$cod_zona]);
	$row = $req->fetch(PDO::FETCH_ASSOC);
	if (!$row) return false;

	return (int)$row['tipo'] === 3 || (int)$row['id'] === 69;
}

/**
 * true si la sesión activa puede ver/usar todo lo relacionado con "Tipo de
 * adopción" (el módulo de paquetes): solo Administrador (tipo=1) y Héctor
 * Morales (id=69) — pedido explícito del usuario, para TODOS los demás
 * (incluidos los promotores tipo=3) debe quedar oculto.
 */
function puede_usar_tipo_adopcion() {
	return (($_SESSION['tipo'] ?? null) == 1) || ((int)($_SESSION['id'] ?? 0) === 69);
}

function crear_tablas_paquetes(PDO $bdd) {
	$bdd->exec("CREATE TABLE IF NOT EXISTS paquetes_colegio (
		id INT AUTO_INCREMENT PRIMARY KEY,
		id_periodo INT NOT NULL,
		id_colegio INT NOT NULL,
		id_grado INT NOT NULL,
		paquete VARCHAR(150) NOT NULL,
		codigo VARCHAR(30) NOT NULL,
		cantidad_titulos INT NOT NULL DEFAULT 0,
		precio_neto_sumado DECIMAL(12,2) NOT NULL DEFAULT 0,
		precio_redondeado DECIMAL(12,2) NULL,
		precio_final DECIMAL(12,2) NOT NULL DEFAULT 0,
		created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY uniq_colegio_periodo_grado (id_colegio, id_periodo, id_grado)
	) ENGINE=InnoDB");

	$bdd->exec("CREATE TABLE IF NOT EXISTS paquetes_colegio_libros (
		id INT AUTO_INCREMENT PRIMARY KEY,
		id_paquete INT NOT NULL,
		id_presupuesto INT NOT NULL,
		id_libro INT NOT NULL,
		precio_neto DECIMAL(12,2) NOT NULL DEFAULT 0,
		KEY idx_paquete (id_paquete),
		FOREIGN KEY (id_paquete) REFERENCES paquetes_colegio(id) ON DELETE CASCADE
	) ENGINE=InnoDB");

	// Marca si un título adoptado debe entrar al paquete de su grado cuando el
	// Tipo de adopción es "Ambos" (con "Paquetes" solo, todo lo adoptado entra,
	// esta bandera no se consulta). Por defecto en 1 para no cambiar el
	// comportamiento de nada existente.
	try { $bdd->exec("ALTER TABLE presupuestos ADD COLUMN en_paquete TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
}

/**
 * Código de "curso" que se inserta en el código del paquete, entre el DANE y el año:
 * número de grado a 2 dígitos (01=Primero ... 11=Once); preescolar con siglas.
 */
function codigo_curso_para_grado($id_grado) {
	static $map = [
		1 => 'PJ', 2 => 'JA', 3 => 'TR',
		4 => '01', 5 => '02', 6 => '03', 7 => '04', 8 => '05', 9 => '06',
		10 => '07', 11 => '08', 12 => '09', 13 => '10', 14 => '11',
	];
	return $map[(int)$id_grado] ?? ('G' . (int)$id_grado);
}

/**
 * Agrupa filas de presupuestos (ya traídas de BD, con las columnas que arman
 * las dos consultas de abajo) por grado real, resolviendo el grado de las áreas
 * objetivas (cod_area lleno) vía id_grado_otro en vez de libros.id_grado (que
 * para esas filas siempre queda en 17="Otro", no es el grado real).
 *
 * Devuelve [id_grado => ['titulos' => [{id_presupuesto, id_libro, libro, precio_neto, en_paquete}], 'suma' => float]]
 */
function _agrupar_filas_presupuesto_por_grado(array $filas) {
	$grupos = [];
	foreach ($filas as $f) {
		$id_grado_real = ($f['cod_area'] !== '' && (int)$f['id_grado_otro'] > 0)
			? (int)$f['id_grado_otro']
			: (int)$f['libro_grado'];
		if ($id_grado_real <= 0) continue;

		$descuento_usado = ((float)$f['tasa_compra_d'] != 0.0) ? (float)$f['descuento_d'] : (float)$f['descuento'];
		$precio_neto = round((float)$f['precio'] - ((float)$f['precio'] * $descuento_usado), 2);

		if (!isset($grupos[$id_grado_real])) $grupos[$id_grado_real] = ['titulos' => [], 'suma' => 0.0];
		$grupos[$id_grado_real]['titulos'][] = [
			'id_presupuesto'      => (int)$f['id_presupuesto'],
			'id_libro'            => (int)$f['id_libro'],
			'libro'               => $f['libro'],
			'precio_neto'         => $precio_neto,
			'precio_venta_padre'  => (float)$f['precio_venta_final'],
			'en_paquete'          => (int)$f['en_paquete'] === 1,
		];
		$grupos[$id_grado_real]['suma'] += $precio_neto;
	}
	return $grupos;
}

const _SQL_COLUMNAS_TITULO_PAQUETE = "p.id AS id_presupuesto, p.id_libro, p.cod_area, p.precio, p.descuento, p.descuento_d, p.tasa_compra_d, p.en_paquete, p.precio_venta_final,
	               l.libro, l.id_grado AS libro_grado,
	               (SELECT ao.id_grado_otro FROM areas_objetivas ao
	                WHERE ao.codigo = p.cod_area AND ao.id_colegio = p.id_colegio AND ao.id_periodo = p.id_periodo
	                LIMIT 1) AS id_grado_otro
	        FROM presupuestos p
	        JOIN libros l ON l.id = p.id_libro";

/**
 * Títulos actualmente adoptados (presupuestos.definido=1) de un colegio+periodo,
 * agrupados por grado real. El lookup de id_grado_otro va como subconsulta escalar
 * (no JOIN) porque varios títulos de una misma área objetiva comparten el mismo
 * `codigo` — un JOIN normal multiplicaría cada presupuesto una vez por cada
 * título hermano con ese código.
 */
function titulos_adoptados_por_grado(PDO $bdd, $id_colegio, $id_periodo) {
	$sql = "SELECT " . _SQL_COLUMNAS_TITULO_PAQUETE . " WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.definido = 1";
	$req = $bdd->prepare($sql);
	$req->execute([(int)$id_colegio, (int)$id_periodo]);
	return _agrupar_filas_presupuesto_por_grado($req->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Igual que titulos_adoptados_por_grado(), pero filtrando por un set explícito de
 * ids de presupuesto en vez de `definido=1` en BD — para previsualizar paquetes con
 * la selección de checkboxes que el usuario tiene en pantalla antes de guardar.
 */
function titulos_por_ids_agrupados(PDO $bdd, $id_colegio, $id_periodo, array $ids) {
	$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
	if (!$ids) return [];

	$in = implode(',', $ids);
	$sql = "SELECT " . _SQL_COLUMNAS_TITULO_PAQUETE . " WHERE p.id_colegio = ? AND p.id_periodo = ? AND p.id IN ($in)";
	$req = $bdd->prepare($sql);
	$req->execute([(int)$id_colegio, (int)$id_periodo]);
	return _agrupar_filas_presupuesto_por_grado($req->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Títulos adoptados que NO quedaron incluidos en ningún paquete ya guardado
 * (es decir, no aparecen en `paquetes_colegio_libros` para ningún paquete de
 * ese colegio+periodo) — los "libros sueltos". Se compara contra lo REALMENTE
 * guardado, no contra el flag `en_paquete` solo, porque con Tipo de adopción
 * "Paquetes" (2) ese flag se ignora al armar los paquetes (entra todo lo
 * adoptado) y podría quedar en 0 de un cambio de tipo anterior sin que eso
 * signifique que el título esté realmente suelto.
 *
 * Devuelve una lista plana (no agrupada) de {grado, libro, precio_neto, precio_venta_padre},
 * ordenada por grado.
 */
function titulos_sueltos(PDO $bdd, $id_colegio, $id_periodo) {
	$id_colegio = (int)$id_colegio;
	$id_periodo = (int)$id_periodo;

	$grupos = titulos_adoptados_por_grado($bdd, $id_colegio, $id_periodo);
	if (!$grupos) return [];

	$req_en_paq = $bdd->prepare("SELECT pl.id_presupuesto FROM paquetes_colegio_libros pl
	                              JOIN paquetes_colegio pc ON pc.id = pl.id_paquete
	                              WHERE pc.id_colegio = ? AND pc.id_periodo = ?");
	$req_en_paq->execute([$id_colegio, $id_periodo]);
	$en_algun_paquete = array_flip(array_map('intval', $req_en_paq->fetchAll(PDO::FETCH_COLUMN)));

	$grados_map = [];
	foreach ($bdd->query("SELECT id, grado FROM grados") as $g) $grados_map[(int)$g['id']] = $g['grado'];

	ksort($grupos);

	$out = [];
	foreach ($grupos as $id_grado => $g) {
		$nombre_grado = $grados_map[$id_grado] ?? ('Grado ' . $id_grado);
		foreach ($g['titulos'] as $t) {
			if (isset($en_algun_paquete[$t['id_presupuesto']])) continue;
			$out[] = [
				'grado'              => $nombre_grado,
				'libro'              => $t['libro'],
				'precio_neto'        => $t['precio_neto'],
				'precio_venta_padre' => $t['precio_venta_padre'],
			];
		}
	}
	return $out;
}

/**
 * Arma la lista de paquetes (paquete, código, cantidad, precio neto sumado,
 * precio redondeado ya guardado si existe, precio final) a partir de un conjunto
 * ya agrupado por grado (salida de cualquiera de las dos funciones de arriba),
 * aplicando el filtro de selección de "Ambos" y el precio_redondeado persistido.
 * No escribe nada en BD — la usan tanto la previsualización como el recálculo real.
 */
function _armar_lista_paquetes(PDO $bdd, $id_colegio, $id_periodo, array $grupos_todos, $tipo_adop) {
	$tipo_adop = (int)$tipo_adop;

	$grupos = [];
	foreach ($grupos_todos as $id_grado => $g) {
		$titulos = $g['titulos'];
		if ($tipo_adop === 3) {
			$titulos = array_values(array_filter($titulos, function ($t) { return $t['en_paquete']; }));
		}
		if (!$titulos) continue;
		$grupos[$id_grado] = [
			'titulos' => $titulos,
			'suma'    => array_sum(array_column($titulos, 'precio_neto')),
		];
	}
	if (!$grupos) return [];

	$req_col = $bdd->prepare("SELECT dane FROM colegios WHERE id=?");
	$req_col->execute([$id_colegio]);
	$dane = trim((string)$req_col->fetchColumn());

	$req_per = $bdd->prepare("SELECT periodo FROM periodos WHERE id=?");
	$req_per->execute([$id_periodo]);
	$anio = substr((string)$req_per->fetchColumn(), 0, 4);

	$grados_map = [];
	foreach ($bdd->query("SELECT id, grado FROM grados") as $g) $grados_map[(int)$g['id']] = $g['grado'];

	// Paquete ya guardado para ese grado (si existe), para no perder su id ni su
	// precio_redondeado ni en el recálculo real ni en la previsualización.
	$req_ex = $bdd->prepare("SELECT id, id_grado, precio_redondeado FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=?");
	$req_ex->execute([$id_colegio, $id_periodo]);
	$existentes = [];
	foreach ($req_ex->fetchAll(PDO::FETCH_ASSOC) as $e) $existentes[(int)$e['id_grado']] = $e;

	ksort($grupos);

	$out = [];
	foreach ($grupos as $id_grado => $g) {
		$nombre_grado = $grados_map[$id_grado] ?? ('Grado ' . $id_grado);
		$suma = round($g['suma'], 2);
		$existente = $existentes[$id_grado] ?? null;
		$precio_redondeado = $existente ? $existente['precio_redondeado'] : null;
		$precio_final = ($precio_redondeado !== null) ? (float)$precio_redondeado : $suma;

		$out[$id_grado] = [
			'id_paquete'         => $existente ? (int)$existente['id'] : null,
			'id_grado'           => $id_grado,
			'paquete'            => 'Paquete ' . $nombre_grado,
			'codigo'             => $dane . codigo_curso_para_grado($id_grado) . $anio,
			'titulos'            => $g['titulos'],
			'cantidad_titulos'   => count($g['titulos']),
			'precio_neto_sumado' => $suma,
			'precio_redondeado'  => $precio_redondeado !== null ? (float)$precio_redondeado : null,
			'precio_final'       => $precio_final,
		];
	}
	return $out;
}

/**
 * Previsualiza los paquetes que resultarían de una selección de checkboxes AÚN NO
 * guardada (definidos = ids de presupuesto actualmente marcados como "Adopción" en
 * pantalla, en_paquete = ids actualmente marcados en el panel de selección). No
 * escribe nada en BD. Devuelve una lista indexada (para JSON), no asociativa por
 * grado.
 */
function construir_preview_paquetes(PDO $bdd, $id_colegio, $id_periodo, array $ids_definidos, array $ids_en_paquete, $tipo_adop) {
	$id_colegio = (int)$id_colegio;
	$id_periodo = (int)$id_periodo;
	$tipo_adop  = (int)$tipo_adop;
	if ($id_colegio <= 0 || $id_periodo <= 0 || !in_array($tipo_adop, [2, 3], true)) return [];

	$grupos_todos = titulos_por_ids_agrupados($bdd, $id_colegio, $id_periodo, $ids_definidos);

	// Sobrescribe en_paquete con la selección EN PANTALLA (aún no guardada en BD).
	$set_en_paquete = array_flip(array_filter(array_map('intval', $ids_en_paquete), function ($v) { return $v > 0; }));
	foreach ($grupos_todos as $id_grado => $g) {
		foreach ($grupos_todos[$id_grado]['titulos'] as $i => $t) {
			$grupos_todos[$id_grado]['titulos'][$i]['en_paquete'] = isset($set_en_paquete[$t['id_presupuesto']]);
		}
	}

	return array_values(_armar_lista_paquetes($bdd, $id_colegio, $id_periodo, $grupos_todos, $tipo_adop));
}

/**
 * Recalcula los paquetes por grado de un colegio+periodo a partir de los títulos
 * actualmente adoptados (presupuestos.definido=1) y GUARDA el resultado. Solo arma
 * paquetes si el Tipo de adopción guardado en `recursos` es Paquetes (2) o Ambos (3);
 * si es Libros Sueltos (1) o no está definido, borra cualquier paquete existente.
 *
 * Con "Paquetes" (2), todos los títulos adoptados de un grado entran al paquete.
 * Con "Ambos" (3), solo entran los títulos marcados con presupuestos.en_paquete=1
 * (selección manual del usuario) — un grado sin ningún título seleccionado no
 * genera paquete.
 *
 * El precio_redondeado que el usuario haya guardado manualmente para un grado
 * se conserva entre recálculos; si no hay uno, precio_final = precio_neto_sumado.
 *
 * El código de cada paquete es DANE del colegio + código de curso del grado +
 * año del periodo (ej. "311001033366" + "04" + "2027").
 */
function recalcular_paquetes_colegio(PDO $bdd, $id_colegio, $id_periodo) {
	crear_tablas_paquetes($bdd);

	$id_colegio = (int)$id_colegio;
	$id_periodo = (int)$id_periodo;
	if ($id_colegio <= 0 || $id_periodo <= 0) return;

	$req_tipo = $bdd->prepare("SELECT tipo_adop FROM recursos WHERE id_colegio=? AND id_periodo=?");
	$req_tipo->execute([$id_colegio, $id_periodo]);
	$tipo_adop = (int)($req_tipo->fetchColumn() ?: 0);

	// Solo Paquetes (2) o Ambos (3) generan paquetes por grado.
	if ($tipo_adop !== 2 && $tipo_adop !== 3) {
		$req_del = $bdd->prepare("DELETE FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=?");
		$req_del->execute([$id_colegio, $id_periodo]);
		return;
	}

	$grupos_todos = titulos_adoptados_por_grado($bdd, $id_colegio, $id_periodo);
	$paquetes = _armar_lista_paquetes($bdd, $id_colegio, $id_periodo, $grupos_todos, $tipo_adop);

	$req_ex = $bdd->prepare("SELECT id_grado FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=?");
	$req_ex->execute([$id_colegio, $id_periodo]);
	$grados_existentes = array_map('intval', $req_ex->fetchAll(PDO::FETCH_COLUMN));
	$grados_vigentes = array_keys($paquetes);

	// Borra paquetes de grados que ya no tienen ningún título seleccionado/adoptado.
	$a_borrar = array_diff($grados_existentes, $grados_vigentes);
	if ($a_borrar) {
		$in = implode(',', array_map('intval', $a_borrar));
		$bdd->exec("DELETE FROM paquetes_colegio WHERE id_colegio=$id_colegio AND id_periodo=$id_periodo AND id_grado IN ($in)");
	}

	$upsert = $bdd->prepare("INSERT INTO paquetes_colegio
			(id_periodo, id_colegio, id_grado, paquete, codigo, cantidad_titulos, precio_neto_sumado, precio_redondeado, precio_final)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE
			paquete = VALUES(paquete),
			codigo = VALUES(codigo),
			cantidad_titulos = VALUES(cantidad_titulos),
			precio_neto_sumado = VALUES(precio_neto_sumado),
			precio_final = IF(precio_redondeado IS NOT NULL, precio_redondeado, VALUES(precio_neto_sumado)),
			updated_at = NOW()");

	$req_id_paq = $bdd->prepare("SELECT id FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=? AND id_grado=?");
	$del_lib = $bdd->prepare("DELETE FROM paquetes_colegio_libros WHERE id_paquete=?");
	$ins_lib = $bdd->prepare("INSERT INTO paquetes_colegio_libros (id_paquete, id_presupuesto, id_libro, precio_neto) VALUES (?, ?, ?, ?)");

	foreach ($paquetes as $id_grado => $p) {
		$upsert->execute([$id_periodo, $id_colegio, $id_grado, $p['paquete'], $p['codigo'], $p['cantidad_titulos'], $p['precio_neto_sumado'], $p['precio_redondeado'], $p['precio_final']]);

		$req_id_paq->execute([$id_colegio, $id_periodo, $id_grado]);
		$id_paquete = (int)$req_id_paq->fetchColumn();
		if ($id_paquete <= 0) continue;

		$del_lib->execute([$id_paquete]);
		foreach ($p['titulos'] as $t) {
			$ins_lib->execute([$id_paquete, $t['id_presupuesto'], $t['id_libro'], $t['precio_neto']]);
		}
	}
}

/**
 * Datos para "Reporte Paquetes" (módulo de Informes): un renglón por libro dentro
 * de cada paquete guardado, de colegios cuyo promotor asignado es tipo=3 o es
 * Héctor Morales (id=69) — ver es_colegio_eureka() — para el periodo dado. Si
 * $id_usuario > 0, se restringe a los colegios de la zona de ese usuario.
 */
function obtener_datos_reporte_paquetes(PDO $bdd, $id_periodo, $id_usuario) {
	$id_periodo = (int)$id_periodo;
	$id_usuario = (int)$id_usuario;

	$cod_zona_usuario = null;
	if ($id_usuario > 0) {
		$req_u = $bdd->prepare("SELECT cod_zona FROM usuarios WHERE id=?");
		$req_u->execute([$id_usuario]);
		$cod_zona_usuario = $req_u->fetchColumn();
		if ($cod_zona_usuario === false) return [];
	}

	$sql = "SELECT co.cod_zona, co.dane, co.colegio,
	               g.id AS id_grado, g.grado, pc.codigo AS codigo_paquete,
	               pc.precio_neto_sumado, pc.precio_redondeado, pc.precio_final,
	               l.isbn, l.libro
	        FROM paquetes_colegio pc
	        JOIN colegios co ON co.id = pc.id_colegio
	        JOIN grados g ON g.id = pc.id_grado
	        JOIN paquetes_colegio_libros pl ON pl.id_paquete = pc.id
	        JOIN libros l ON l.id = pl.id_libro
	        WHERE pc.id_periodo = ?";
	$params = [$id_periodo];
	if ($cod_zona_usuario !== null) {
		$sql .= " AND co.cod_zona = ?";
		$params[] = $cod_zona_usuario;
	}
	$sql .= " ORDER BY co.colegio ASC, g.id ASC, l.libro ASC";

	$req = $bdd->prepare($sql);
	$req->execute($params);
	$filas = $req->fetchAll(PDO::FETCH_ASSOC);

	$cache_empresa = [];
	$cache_eureka = [];
	$out = [];
	foreach ($filas as $f) {
		$cz = $f['cod_zona'];
		if (!array_key_exists($cz, $cache_eureka)) {
			$cache_eureka[$cz] = es_colegio_eureka($bdd, $cz);
		}
		if (!$cache_eureka[$cz]) continue;

		if (!array_key_exists($cz, $cache_empresa)) {
			$cache_empresa[$cz] = resolver_empresa_colegio($bdd, $cz);
		}
		$info = $cache_empresa[$cz];
		if (!$info) continue;

		$out[] = [
			'zona'               => $info['zona'],
			'responsable'        => $info['responsable'],
			'dane'               => $f['dane'],
			'colegio'            => $f['colegio'],
			'grado'              => $f['grado'],
			'codigo_paquete'     => $f['codigo_paquete'],
			'precio_neto_sumado' => (float)$f['precio_neto_sumado'],
			'precio_redondeado'  => $f['precio_redondeado'] !== null ? (float)$f['precio_redondeado'] : null,
			'precio_final'       => (float)$f['precio_final'],
			'isbn'               => $f['isbn'],
			'libro'              => $f['libro'],
		];
	}
	return $out;
}
