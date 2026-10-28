<?php
// Datos del "Mercado editorial" de un colegio en un periodo (pestaña ajax/tab_mercado.php y php/mercado_excel.php).
// Tasa de compra y descuento se guardan como fracción (0.60 = 60 %). Si la tasa está en 0 se toma la
// del periodo según el nivel del grado y si el descuento está en 0 se propone el 20 %.

define('MERCADO_DESCUENTO_DEFECTO', 20);

// Tasa por defecto (en %) del periodo según el grado: preescolar, primaria, 6°-9° y 10°-11°.
function mercado_tasa_defecto($periodo, $id_grado) {
	if ($id_grado < 4)  return (float)$periodo['t_preescolar'];
	if ($id_grado < 9)  return (float)$periodo['t_primaria'];
	if ($id_grado < 13) return (float)$periodo['t_6_9'];
	return (float)$periodo['t_10_11'];
}

function mercado_periodo($bdd, $id_periodo) {
	$req = $bdd->prepare("SELECT id, periodo, f_cierre, t_preescolar, t_primaria, t_6_9, t_10_11 FROM periodos WHERE id=?");
	$req->execute([$id_periodo]);
	return $req->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mercado_colegio($bdd, $id_colegio) {
	$req = $bdd->prepare("SELECT id, codigo, colegio, cod_zona FROM colegios WHERE id=?");
	$req->execute([$id_colegio]);
	return $req->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ¿Puede el usuario en sesión añadir/editar el mercado de este colegio? (mismas reglas que Presupuesto)
function mercado_puede_editar($colegio) {
	$tipo = (int)($_SESSION['tipo'] ?? 0);
	if ($tipo === 2 || $tipo === 4) return false;
	return $tipo === 1 || (string)($_SESSION['zona'] ?? '') === (string)$colegio['cod_zona'];
}

function mercado_periodo_abierto($periodo) {
	return $periodo && $periodo['f_cierre'] > date('Y-m-d');
}

// Filas del mercado con alumnos del grado, valores efectivos (en %) y cálculos.
function mercado_filas($bdd, $id_colegio, $periodo) {
	$req = $bdd->prepare("SELECT m.id, m.id_grado, m.id_tipo_libro, m.libro, m.vigencia, m.precio, m.tasa_compra, m.descuento,
	                             e.editorial, b.materia, c.grado, t.tipo
	                      FROM mercado_editorial m
	                      JOIN editoriales e ON e.id = m.editorial
	                      JOIN materias b    ON b.id = m.id_materia
	                      JOIN grados c      ON c.id = m.id_grado
	                      LEFT JOIN tipos_libro t ON t.id = m.id_tipo_libro
	                      WHERE m.id_colegio=? AND m.id_periodo=?
	                      ORDER BY e.editorial, m.id_grado, b.materia, m.libro");
	$req->execute([$id_colegio, $periodo['id']]);
	$filas = $req->fetchAll(PDO::FETCH_ASSOC);

	$req_al = $bdd->prepare("SELECT id_grado, SUM(alumnos) FROM grados_paralelos WHERE id_colegio=? AND id_periodo=? GROUP BY id_grado");
	$req_al->execute([$id_colegio, $periodo['id']]);
	$alumnos = $req_al->fetchAll(PDO::FETCH_KEY_PAIR);

	foreach ($filas as &$f) {
		mercado_calcular($f, $periodo, (int)($alumnos[$f['id_grado']] ?? 0));
	}
	unset($f);
	return $filas;
}

// Completa una fila de mercado_editorial (precio, tasa_compra, descuento, id_grado, tipo) con los valores
// efectivos en % y los cálculos de precio neto, ejemplares y venta potencial.
function mercado_calcular(&$f, $periodo, $alumnos) {
	$f['alumnos']    = (int)$alumnos;
	$f['tasa']       = (float)$f['tasa_compra'] > 0 ? round($f['tasa_compra'] * 100, 2) : mercado_tasa_defecto($periodo, (int)$f['id_grado']);
	$f['desc']       = (float)$f['descuento'] > 0 ? round($f['descuento'] * 100, 2) : MERCADO_DESCUENTO_DEFECTO;
	$f['precio']     = (float)$f['precio'];
	$f['neto']       = $f['precio'] - $f['precio'] * $f['desc'] / 100;
	$f['ejemplares'] = (int)floor($f['alumnos'] * $f['tasa'] / 100);
	$f['venta']      = $f['neto'] * $f['ejemplares'];
	$f['tipo']       = ($f['tipo'] ?? '') ?: '—';
}
