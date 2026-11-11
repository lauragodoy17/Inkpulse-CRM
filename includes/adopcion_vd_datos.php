<?php
/**
 * Datos de la venta directa en la adopción (pedido de la usuaria 2026-11-10): con el canal "Venta directa - Eureka"
 * la adopción pide las fechas y el horario de la venta (una o varias jornadas) y el contacto del colegio
 * (nombre y celular obligatorios, correo opcional).
 *
 * - Contacto: recursos.vd_contacto_nombre, vd_contacto_celular, vd_contacto_correo.
 * - Jornadas: tabla adopcion_vd_jornadas (id_colegio, id_periodo, fecha, hora_inicio, hora_fin).
 * - Se capturan en ajax/tab_adopciones.php y se guardan en php/guardar_definicion.php.
 */

/** Columnas y tabla. DDL: llamar fuera de transacciones. */
function adopcion_vd_asegurar(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $columnas = [
        'vd_contacto_nombre'  => 'VARCHAR(150) NULL DEFAULT NULL',
        'vd_contacto_celular' => 'VARCHAR(40) NULL DEFAULT NULL',
        'vd_contacto_correo'  => 'VARCHAR(150) NULL DEFAULT NULL',
        // ¿El colegio ya tiene fechas de venta? 1 sí, 0 no, NULL sin responder (2026-11-10).
        'vd_tiene_fechas'     => 'TINYINT(1) NULL DEFAULT NULL',
    ];
    foreach ($columnas as $columna => $definicion) {
        if ($bdd->query("SHOW COLUMNS FROM recursos LIKE '$columna'")->fetch()) continue;
        try {
            $bdd->exec("ALTER TABLE recursos ADD COLUMN $columna $definicion");
        } catch (Exception $e) {}
    }
    try {
        $bdd->exec("CREATE TABLE IF NOT EXISTS adopcion_vd_jornadas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_colegio INT NOT NULL,
            id_periodo INT NOT NULL,
            fecha DATE NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_fin TIME NOT NULL,
            created_at DATETIME NULL,
            id_usuario INT NULL,
            KEY idx_colegio_periodo (id_colegio, id_periodo)
        )");
    } catch (Exception $e) {}
    // Quién programó cada fecha (para las alertas, includes/alertas_vd.php). 2026-11-10.
    try {
        if (!$bdd->query("SHOW COLUMNS FROM adopcion_vd_jornadas LIKE 'id_usuario'")->fetch()) {
            $bdd->exec("ALTER TABLE adopcion_vd_jornadas ADD COLUMN id_usuario INT NULL");
        }
    } catch (Exception $e) {}
}

/**
 * ['tiene_fechas' => true|false|null, 'contacto' => ['nombre','celular','correo'], 'jornadas' => [['fecha','hora_inicio','hora_fin']]]
 * (horas HH:MM). tiene_fechas null = aún no se respondió; si hay jornadas guardadas de antes de la pregunta, cuenta como sí.
 */
function adopcion_vd_leer(PDO $bdd, $idColegio, $idPeriodo) {
    adopcion_vd_asegurar($bdd);
    $st = $bdd->prepare("SELECT vd_contacto_nombre, vd_contacto_celular, vd_contacto_correo, vd_tiene_fechas FROM recursos WHERE id_colegio = ? AND id_periodo = ? LIMIT 1");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st = $bdd->prepare("SELECT fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
                         FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ? ORDER BY fecha, hora_inicio, id");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $jornadas = $st->fetchAll(PDO::FETCH_ASSOC);
    $tiene = ($r['vd_tiene_fechas'] ?? null) === null ? ($jornadas ? true : null) : ((int)$r['vd_tiene_fechas'] === 1);
    return [
        'tiene_fechas' => $tiene,
        'contacto' => [
            'nombre'  => (string)($r['vd_contacto_nombre'] ?? ''),
            'celular' => (string)($r['vd_contacto_celular'] ?? ''),
            'correo'  => (string)($r['vd_contacto_correo'] ?? ''),
        ],
        'jornadas' => $jornadas,
    ];
}

/**
 * Celular colombiano (2026-11-10): 10 dígitos que empiezan por 3, con o sin indicativo +57 / 57 y con espacios,
 * guiones, puntos o paréntesis. Devuelve el número como "300 123 4567" o null si no es válido.
 */
function adopcion_vd_celular($valor) {
    $v = trim((string)$valor);
    if ($v === '' || !preg_match('/^[0-9+\s().-]+$/', $v)) return null;
    if (substr_count($v, '+') > 1 || (strpos($v, '+') !== false && strpos($v, '+') !== 0)) return null;
    $d = preg_replace('/\D/', '', $v);
    if (strlen($d) === 12 && substr($d, 0, 2) === '57') $d = substr($d, 2);
    elseif (strpos($v, '+') === 0) return null; // otro indicativo de país
    if (!preg_match('/^3\d{9}$/', $d)) return null;
    if (preg_match('/^3(\d)\1{8}$/', $d)) return null; // 3000000000, 3111111111...: no es un número real
    return substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6);
}

/** Lee y valida el formulario de Adopciones: ['ok','error','datos' => mismo formato de adopcion_vd_leer]. */
function adopcion_vd_del_post(array $post) {
    $texto = function ($v, $max) { return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, $max); };

    // Primero: ¿ya tiene fechas de venta? Con "No" se sigue sin fechas; con "Sí" son obligatorias.
    $tiene = (string)($post['vd_tiene_fechas'] ?? '');
    if ($tiene !== '0' && $tiene !== '1') return ['ok' => false, 'error' => 'Indica si el colegio ya tiene fechas de venta (Sí / No).'];
    $tiene = $tiene === '1';
    // Con "No" el panel (fechas y contacto) no se muestra: no se pide nada más y el contacto guardado se conserva.
    if (!$tiene) return ['ok' => true, 'error' => null, 'datos' => ['tiene_fechas' => false, 'contacto' => null, 'jornadas' => []]];

    $fechas = $tiene ? (array)($post['vd_fecha'] ?? []) : [];
    $inis   = (array)($post['vd_hora_ini'] ?? []);
    $fines  = (array)($post['vd_hora_fin'] ?? []);
    $jornadas = [];
    foreach (array_values($fechas) as $i => $fecha) {
        $fecha = trim((string)$fecha);
        $ini = trim((string)(array_values($inis)[$i] ?? ''));
        $fin = trim((string)(array_values($fines)[$i] ?? ''));
        if ($fecha === '' && $ini === '' && $fin === '') continue; // fila vacía
        $n = count($jornadas) + 1;
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$d || $d->format('Y-m-d') !== $fecha) return ['ok' => false, 'error' => "Falta la fecha de la venta $n."];
        if (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin)) {
            return ['ok' => false, 'error' => 'Completa el horario (desde y hasta) de la venta del ' . $d->format('d/m/Y') . '.'];
        }
        if ($fin <= $ini) return ['ok' => false, 'error' => 'En la venta del ' . $d->format('d/m/Y') . ' la hora "hasta" debe ser posterior a la hora "desde".'];
        $jornadas[] = ['fecha' => $fecha, 'hora_inicio' => $ini, 'hora_fin' => $fin];
    }
    if ($tiene && !$jornadas) return ['ok' => false, 'error' => 'Marcaste que el colegio ya tiene fechas de venta: indica al menos una fecha y horario.'];
    usort($jornadas, fn($a, $b) => strcmp($a['fecha'] . $a['hora_inicio'], $b['fecha'] . $b['hora_inicio']));

    $nombre  = $texto($post['vd_contacto_nombre'] ?? '', 150);
    $celular = $texto($post['vd_contacto_celular'] ?? '', 40);
    $correo  = $texto($post['vd_contacto_correo'] ?? '', 150);
    if ($nombre === '') return ['ok' => false, 'error' => 'El nombre del contacto del colegio es obligatorio.'];
    if ($celular === '') return ['ok' => false, 'error' => 'El celular del contacto del colegio es obligatorio.'];
    $cel_norm = adopcion_vd_celular($celular);
    if ($cel_norm === null) {
        return ['ok' => false, 'error' => 'El celular del contacto del colegio no es válido: debe tener 10 dígitos y empezar por 3 (ej. 300 123 4567).'];
    }
    $celular = $cel_norm;
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'El correo del contacto del colegio no es válido.'];

    return ['ok' => true, 'error' => null, 'datos' => [
        'tiene_fechas' => $tiene,
        'contacto' => ['nombre' => $nombre, 'celular' => $celular, 'correo' => $correo],
        'jornadas' => $jornadas,
    ]];
}

/** Texto corto de las jornadas para el historial: "15/11/2026 08:00-12:00; ...". */
function adopcion_vd_jornadas_texto(array $jornadas) {
    return implode('; ', array_map(fn($j) => date('d/m/Y', strtotime($j['fecha'])) . ' ' . $j['hora_inicio'] . '-' . $j['hora_fin'], $jornadas));
}

/** Guarda contacto y jornadas y deja en el historial lo que cambió. Devuelve los ids de las fechas nuevas (para las alertas). */
function adopcion_vd_guardar(PDO $bdd, $idColegio, $idPeriodo, array $datos, $idUsuario = 0) {
    $antes = adopcion_vd_leer($bdd, $idColegio, $idPeriodo);
    $c = $datos['contacto'] ?? $antes['contacto']; // con "No" no llega contacto: se deja el que había
    $tiene = !empty($datos['tiene_fechas']);
    $bdd->prepare("UPDATE recursos SET vd_contacto_nombre = ?, vd_contacto_celular = ?, vd_contacto_correo = ?, vd_tiene_fechas = ? WHERE id_colegio = ? AND id_periodo = ?")
        ->execute([$c['nombre'], $c['celular'], $c['correo'] !== '' ? $c['correo'] : null, $tiene ? 1 : 0, (int)$idColegio, (int)$idPeriodo]);
    // Por diferencias (2026-11-10): las fechas que no cambian conservan su id (y con él quién las programó y si ya se
    // envió su recordatorio); se borran las que se quitaron y se insertan solo las nuevas.
    $st = $bdd->prepare("SELECT id, CONCAT(fecha, ' ', TIME_FORMAT(hora_inicio, '%H:%i'), ' ', TIME_FORMAT(hora_fin, '%H:%i')) AS clave
                         FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ?");
    $st->execute([(int)$idColegio, (int)$idPeriodo]);
    $existentes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $existentes[$r['clave']][] = (int)$r['id'];
    $ins = $bdd->prepare("INSERT INTO adopcion_vd_jornadas (id_colegio, id_periodo, fecha, hora_inicio, hora_fin, created_at, id_usuario) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $nuevas = [];
    foreach ($datos['jornadas'] as $j) {
        $clave = $j['fecha'] . ' ' . $j['hora_inicio'] . ' ' . $j['hora_fin'];
        if (!empty($existentes[$clave])) { array_shift($existentes[$clave]); continue; } // sigue igual
        $ins->execute([(int)$idColegio, (int)$idPeriodo, $j['fecha'], $j['hora_inicio'] . ':00', $j['hora_fin'] . ':00', (int)$idUsuario ?: null]);
        $nuevas[] = (int)$bdd->lastInsertId();
    }
    $sobran = array_merge([], ...array_values($existentes));
    if ($sobran) $bdd->exec("DELETE FROM adopcion_vd_jornadas WHERE id IN (" . implode(',', array_map('intval', $sobran)) . ")");

    if (!function_exists('registrar_historial')) return $nuevas;
    $etiquetas = ['nombre' => 'Nombre del contacto', 'celular' => 'Celular del contacto', 'correo' => 'Correo del contacto'];
    foreach ($etiquetas as $k => $etiqueta) {
        if ($antes['contacto'][$k] !== $c[$k]) registrar_historial($bdd, $idColegio, $idUsuario, 'Venta directa', $etiqueta, $antes['contacto'][$k], $c[$k]);
    }
    if ($antes['tiene_fechas'] !== $tiene) {
        $sn = fn($v) => $v === null ? '' : ($v ? 'Sí' : 'No');
        registrar_historial($bdd, $idColegio, $idUsuario, 'Venta directa', '¿Ya tiene fechas de venta?', $sn($antes['tiene_fechas']), $sn($tiene));
    }
    $txtAntes = adopcion_vd_jornadas_texto($antes['jornadas']);
    $txtNuevo = adopcion_vd_jornadas_texto($datos['jornadas']);
    if ($txtAntes !== $txtNuevo) registrar_historial($bdd, $idColegio, $idUsuario, 'Venta directa', 'Fechas y horario de venta', $txtAntes, $txtNuevo);
    return $nuevas;
}

/**
 * Fechas de venta atendidas (2026-11-11): las que ya tienen un formato de venta directa registrado (activo).
 * Un formato del colegio y período atiende la fecha programada igual a su fecha de venta; si no coincide con ninguna
 * (el formato automático toma la fecha de la devolución, que es posterior), atiende la última fecha programada hasta
 * su fecha de venta que siga sin formato. Las demás fechas están pendientes, aunque ya hayan pasado.
 * $jornadas: filas con id, id_colegio, id_periodo, fecha. Devuelve [id_jornada => ['id' => id formato, 'consecutivo']].
 */
function adopcion_vd_atendidas(PDO $bdd, array $jornadas) {
    if (!$jornadas) return [];
    $grupos = [];
    foreach ($jornadas as $j) $grupos[(int)$j['id_colegio'] . '-' . (int)$j['id_periodo']][] = $j;
    foreach ($grupos as &$g) usort($g, fn($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: ((int)$a['id'] <=> (int)$b['id']));
    unset($g);
    $cols = array_values(array_unique(array_map(fn($j) => (int)$j['id_colegio'], $jornadas)));
    try {
        $formatos = $bdd->query("SELECT id, consecutivo, id_colegio, id_periodo, fecha_venta FROM formatos_vd
                                 WHERE estado = 1 AND id_colegio IN (" . implode(',', $cols) . ") ORDER BY fecha_venta, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return []; // sin tabla de formatos todavía
    }
    $res = [];
    $sinCoincidir = [];
    foreach ($formatos as $f) {
        $hecho = false;
        foreach ($grupos[$f['id_colegio'] . '-' . $f['id_periodo']] ?? [] as $j) {
            if ($j['fecha'] === $f['fecha_venta'] && !isset($res[$j['id']])) { $res[$j['id']] = $f; $hecho = true; break; }
        }
        if (!$hecho) $sinCoincidir[] = $f;
    }
    foreach ($sinCoincidir as $f) {
        $elegida = null;
        foreach ($grupos[$f['id_colegio'] . '-' . $f['id_periodo']] ?? [] as $j) {
            if ($j['fecha'] <= $f['fecha_venta'] && !isset($res[$j['id']])) $elegida = $j;
        }
        if ($elegida) $res[$elegida['id']] = $f;
    }
    return array_map(fn($f) => ['id' => (int)$f['id'], 'consecutivo' => (int)$f['consecutivo']], $res);
}
