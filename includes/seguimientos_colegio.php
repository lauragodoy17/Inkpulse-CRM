<?php
/**
 * /includes/seguimientos_colegio.php
 * "Gestión y seguimiento" de un colegio (pestaña Visitas de colegio.php, pedido por la usuaria 2026-10-16):
 * historial de gestiones con fecha, "Seguimiento con" (usuario; en BD id_responsable), observación y fecha
 * del próximo seguimiento o visita (obligatoria solo para tipo 3, salvo SEG_USUARIOS_PROXIMA_OPCIONAL). Un día antes de esa fecha se envía un recordatorio por
 * correo al asesor encargado del colegio (el usuario de la zona del colegio, igual que el "promotor" de
 * colegio.php) y a la persona elegida en "Seguimiento con".
 *
 * Los recordatorios los envía seg_enviar_recordatorios(), que se llama desde:
 *  - php/seguimientos_recordatorios.php por línea de comandos (programar una vez al día, ej. 7:00 am);
 *  - el mismo archivo por web, una vez al día por navegador (template/nav_side.php), por si la tarea
 *    programada no corrió;
 *  - php/seguimiento_colegio_guardar.php, cuando la próxima fecha es hoy o mañana.
 * Al ejecutar una visita (php/ejecutar_visita.php) se pide la fecha de la próxima visita y se registra aquí
 * con seg_registrar_desde_visita() (id_plan_trabajo = la visita ejecutada), con el mismo recordatorio.
 * Cada seguimiento se avisa una sola vez (recordatorio_enviado_at).
 */
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

const SEG_URL_CRM = 'https://crm.somoseureka.com.co';
const SEG_OBSERVACION_MAX = 2000;
const SEG_TIPOS_PROXIMO = ['seguimiento' => 'Seguimiento', 'visita' => 'Visita'];
// La próxima fecha (seguimiento y ejecutar visita) solo es obligatoria para los promotores (tipo 3), salvo
// Mariana Castañeda (mariana.castaneda@, id 57) (pedido por la usuaria 2026-10-19).
const SEG_USUARIOS_PROXIMA_OPCIONAL = [57];

/** ¿La próxima fecha es opcional para este usuario (el de la sesión)? */
function seg_proxima_opcional($idUsuario, $tipo) {
    return (int)$tipo !== 3 || in_array((int)$idUsuario, SEG_USUARIOS_PROXIMA_OPCIONAL, true);
}

function seg_asegurar_tabla(PDO $bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS seguimientos_colegio (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_colegio INT NOT NULL,
        fecha DATE NOT NULL,
        id_responsable INT NOT NULL,
        observacion TEXT NOT NULL,
        proxima_fecha DATE NULL,
        proximo_tipo VARCHAR(20) NULL,
        id_usuario INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recordatorio_enviado_at DATETIME NULL,
        recordatorio_para VARCHAR(255) NULL,
        recordatorio_error VARCHAR(255) NULL,
        KEY idx_colegio (id_colegio, fecha),
        KEY idx_recordatorio (proxima_fecha, recordatorio_enviado_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Visita ejecutada que originó el seguimiento (NULL si se registró desde "Nuevo seguimiento").
    if (!$bdd->query("SHOW COLUMNS FROM seguimientos_colegio LIKE 'id_plan_trabajo'")->fetch()) {
        $bdd->exec("ALTER TABLE seguimientos_colegio ADD COLUMN id_plan_trabajo INT NULL AFTER id_colegio, ADD KEY idx_plan (id_plan_trabajo)");
    }
}

/** Asesor encargado del colegio: el usuario activo de la zona del colegio (asesor/admin primero). */
function seg_asesor_colegio(PDO $bdd, $idColegio) {
    $st = $bdd->prepare("SELECT u.id, TRIM(CONCAT(u.nombres,' ',u.apellidos)) AS nombre, TRIM(u.correo) AS correo
                         FROM colegios c JOIN usuarios u ON u.cod_zona = c.cod_zona
                         WHERE c.id = ? AND u.act = 1
                         ORDER BY u.tipo IN (3, 1) DESC, u.id
                         LIMIT 1");
    $st->execute([(int)$idColegio]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Usuarios activos para el campo "Seguimiento con": [id => nombre]. */
function seg_responsables(PDO $bdd) {
    return $bdd->query("SELECT id, TRIM(CONCAT(nombres,' ',apellidos)) FROM usuarios WHERE act = 1 ORDER BY nombres, apellidos")
               ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function seg_listar(PDO $bdd, $idColegio) {
    $st = $bdd->prepare("SELECT s.*, TRIM(CONCAT(r.nombres,' ',r.apellidos)) AS responsable,
                                TRIM(CONCAT(u.nombres,' ',u.apellidos)) AS registrado_por
                         FROM seguimientos_colegio s
                         LEFT JOIN usuarios r ON r.id = s.id_responsable
                         LEFT JOIN usuarios u ON u.id = s.id_usuario
                         WHERE s.id_colegio = ?
                         ORDER BY s.fecha DESC, s.id DESC");
    $st->execute([(int)$idColegio]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function seg_fecha_valida($f) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$f);
    return $d && $d->format('Y-m-d') === $f;
}

/** Registra un seguimiento. Devuelve ['ok','error','id']. */
function seg_guardar(PDO $bdd, array $e, $idUsuario) {
    $idColegio = (int)($e['id_colegio'] ?? 0);
    $fecha = trim((string)($e['fecha'] ?? ''));
    $idResp = (int)($e['id_responsable'] ?? 0);
    $obs = trim((string)($e['observacion'] ?? ''));
    $prox = trim((string)($e['proxima_fecha'] ?? ''));
    $tipo = (string)($e['proximo_tipo'] ?? 'seguimiento');

    $st = $bdd->prepare("SELECT 1 FROM colegios WHERE id = ?");
    $st->execute([$idColegio]);
    if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'El colegio no existe.'];
    if (!seg_fecha_valida($fecha)) return ['ok' => false, 'error' => 'Escribe la fecha de la gestión.'];
    if ($fecha > date('Y-m-d')) return ['ok' => false, 'error' => 'La fecha de la gestión no puede ser futura; para lo que viene usa "Próximo seguimiento".'];
    $st = $bdd->prepare("SELECT 1 FROM usuarios WHERE id = ? AND act = 1");
    $st->execute([$idResp]);
    if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'Elige con quién es el seguimiento.'];
    if ($obs === '') return ['ok' => false, 'error' => 'Escribe la observación.'];
    if (mb_strlen($obs) > SEG_OBSERVACION_MAX) return ['ok' => false, 'error' => 'La observación admite máximo ' . SEG_OBSERVACION_MAX . ' caracteres.'];
    if ($prox === '') {
        $st = $bdd->prepare("SELECT tipo FROM usuarios WHERE id = ?");
        $st->execute([(int)$idUsuario]);
        if (!seg_proxima_opcional($idUsuario, $st->fetchColumn())) return ['ok' => false, 'error' => 'Elige la fecha del próximo seguimiento o visita.'];
    } else {
        if (!seg_fecha_valida($prox)) return ['ok' => false, 'error' => 'La fecha del próximo seguimiento no es válida.'];
        if ($prox <= date('Y-m-d')) return ['ok' => false, 'error' => 'La fecha del próximo seguimiento debe ser posterior a hoy.'];
    }
    if (!isset(SEG_TIPOS_PROXIMO[$tipo])) $tipo = 'seguimiento';

    $bdd->prepare("INSERT INTO seguimientos_colegio (id_colegio, fecha, id_responsable, observacion, proxima_fecha, proximo_tipo, id_usuario)
                   VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$idColegio, $fecha, $idResp, $obs, $prox !== '' ? $prox : null, $tipo, (int)$idUsuario]);
    return ['ok' => true, 'error' => null, 'id' => (int)$bdd->lastInsertId()];
}

/**
 * Registra la próxima visita indicada al ejecutar una visita del plan de trabajo: fecha = hoy, "Seguimiento
 * con" = el promotor de la visita, observación = los comentarios de la visita. Un solo registro por visita
 * (si ya existe no se duplica). Si la próxima es mañana el recordatorio se envía en el acto.
 * Devuelve ['ok','error'].
 */
function seg_registrar_desde_visita(PDO $bdd, $idPlan, $proxima, $comentarios, $idUsuario) {
    seg_asegurar_tabla($bdd);
    $st = $bdd->prepare("SELECT id_colegio, id_promotor FROM plan_trabajo WHERE id = ?");
    $st->execute([(int)$idPlan]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return ['ok' => false, 'error' => 'La visita no existe.'];
    $obs = trim((string)$comentarios);
    if ($obs === '') $obs = 'Visita ejecutada.';
    $datos = [
        'id_colegio' => $plan['id_colegio'], 'fecha' => date('Y-m-d'), 'id_responsable' => $plan['id_promotor'],
        'observacion' => mb_substr($obs, 0, SEG_OBSERVACION_MAX), 'proxima_fecha' => trim((string)$proxima), 'proximo_tipo' => 'visita',
    ];
    $st = $bdd->prepare("SELECT 1 FROM seguimientos_colegio WHERE id_plan_trabajo = ?");
    $st->execute([(int)$idPlan]);
    if ($st->fetchColumn()) return ['ok' => true, 'error' => null];

    $res = seg_guardar($bdd, $datos, $idUsuario);
    if (!$res['ok']) return $res;
    $bdd->prepare("UPDATE seguimientos_colegio SET id_plan_trabajo = ? WHERE id = ?")->execute([(int)$idPlan, $res['id']]);
    if ($datos['proxima_fecha'] === date('Y-m-d', strtotime('+1 day'))) {
        try { seg_enviar_recordatorios($bdd); } catch (Exception $e) { error_log('Recordatorio visita #' . $idPlan . ': ' . $e->getMessage()); }
    }
    return $res;
}

/**
 * Envía los recordatorios pendientes: seguimientos cuya próxima fecha es mañana (o hoy, si ayer no se
 * envió) y que no se han avisado. Cada uno se "reserva" antes de enviar para que dos ejecuciones al
 * tiempo no lo manden dos veces; si el envío falla se libera para el siguiente intento.
 * Devuelve ['enviados' => n, 'errores' => [...]].
 */
function seg_enviar_recordatorios(PDO $bdd) {
    seg_asegurar_tabla($bdd);
    $hoy = date('Y-m-d');
    $manana = date('Y-m-d', strtotime('+1 day'));
    $st = $bdd->prepare("SELECT s.*, c.colegio, c.codigo, TRIM(CONCAT(r.nombres,' ',r.apellidos)) AS responsable,
                                TRIM(r.correo) AS responsable_correo, r.act AS responsable_act
                         FROM seguimientos_colegio s
                         JOIN colegios c ON c.id = s.id_colegio
                         LEFT JOIN usuarios r ON r.id = s.id_responsable
                         WHERE s.proxima_fecha BETWEEN ? AND ? AND s.recordatorio_enviado_at IS NULL
                         ORDER BY s.proxima_fecha, s.id");
    $st->execute([$hoy, $manana]);
    $pendientes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pendientes) return ['enviados' => 0, 'errores' => []];

    $periodo = 0;
    if (is_file(__DIR__ . '/periodos_fechas.php')) {
        require_once __DIR__ . '/periodos_fechas.php';
        $periodo = (int)(obtener_periodo_activo($bdd)['periodoActivo'] ?? 0);
    }

    $reservar = $bdd->prepare("UPDATE seguimientos_colegio SET recordatorio_enviado_at = NOW() WHERE id = ? AND recordatorio_enviado_at IS NULL");
    $listo = $bdd->prepare("UPDATE seguimientos_colegio SET recordatorio_para = ?, recordatorio_error = NULL WHERE id = ?");
    $fallo = $bdd->prepare("UPDATE seguimientos_colegio SET recordatorio_enviado_at = NULL, recordatorio_error = ? WHERE id = ?");
    $enviados = 0;
    $errores = [];
    foreach ($pendientes as $s) {
        $reservar->execute([$s['id']]);
        if ($reservar->rowCount() === 0) continue; // otra ejecución ya lo tomó
        $asesor = seg_asesor_colegio($bdd, $s['id_colegio']);
        try {
            $para = seg_enviar_correo($s, $asesor, $periodo);
            $listo->execute([mb_substr($para, 0, 255), $s['id']]);
            $enviados++;
        } catch (Exception $ex) {
            $msg = mb_substr($ex->getMessage(), 0, 255);
            $fallo->execute([$msg, $s['id']]);
            $errores[] = 'Seguimiento #' . $s['id'] . ': ' . $msg;
            error_log('Recordatorio seguimiento colegio #' . $s['id'] . ': ' . $msg);
        }
    }
    return ['enviados' => $enviados, 'errores' => $errores];
}

/**
 * Arma y envía un recordatorio al asesor del colegio y a la persona de "Seguimiento con" (un solo correo,
 * sin repetir si son la misma). Devuelve a quién se envió; lanza Exception si no se pudo.
 */
function seg_enviar_correo(array $s, ?array $asesor, $periodo) {
    // Destinatarios: [correo => nombre].
    $destinos = [];
    if ($s['responsable'] && (int)$s['responsable_act'] === 1 && filter_var($s['responsable_correo'], FILTER_VALIDATE_EMAIL)) {
        $destinos[strtolower($s['responsable_correo'])] = $s['responsable'];
    }
    if ($asesor && filter_var($asesor['correo'], FILTER_VALIDATE_EMAIL)) {
        $destinos += [strtolower($asesor['correo']) => $asesor['nombre']];
    }
    if (!$destinos) {
        throw new Exception('Ni el asesor del colegio ni la persona del seguimiento tienen un correo válido.');
    }
    $nombres = array_values(array_unique(array_filter([$asesor['nombre'] ?? '', $s['responsable'] ?? ''])));

    $tipo = SEG_TIPOS_PROXIMO[$s['proximo_tipo']] ?? 'Seguimiento';
    $cuando = $s['proxima_fecha'] === date('Y-m-d') ? 'hoy' : 'mañana';
    $fechaProx = date('d/m/Y', strtotime($s['proxima_fecha']));
    $url = SEG_URL_CRM . '/colegio.php?codigo=' . rawurlencode($s['codigo']) . ($periodo ? '&periodo=' . $periodo : '') . '&tab=visitas';
    $h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $fila = fn($k, $v) => '<tr><td style="padding:5px 12px 5px 0;color:#6b7280;white-space:nowrap;vertical-align:top;">' . $k . '</td><td style="padding:5px 0;color:#111827;">' . $v . '</td></tr>';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'somoseureka.com.co';
    $mail->SMTPAuth   = true;
    $mail->SMTPAutoTLS = false;
    $mail->Username   = 'crm@somoseureka.com.co';
    $mail->Password   = 'cRm14356$';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
    foreach ($destinos as $correo => $nombre) $mail->addAddress($correo, $nombre);
    $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Recordatorio: ' . mb_strtolower($tipo) . ' ' . $cuando . ' (' . $fechaProx . ') - ' . $s['colegio'];
    $mail->Body = '<p style="font-size:15px;">Hola' . ($nombres ? ' ' . $h(implode(' y ', $nombres)) : '') . ', ' . $cuando . ' <strong>' . $fechaProx . '</strong> está programado un <strong>'
        . mb_strtolower($tipo) . '</strong> con el colegio <strong>' . $h($s['colegio']) . '</strong>.</p>'
        . '<table style="border-collapse:collapse;font-size:14px;margin:6px 0 12px;">'
        . $fila('Última gestión', date('d/m/Y', strtotime($s['fecha'])))
        . $fila('Asesor del colegio', $h($asesor['nombre'] ?? '—'))
        . $fila('Seguimiento con', $h($s['responsable'] ?: '—'))
        . $fila('Observación', nl2br($h($s['observacion'])))
        . $fila('Próximo ' . mb_strtolower($tipo), $fechaProx)
        . '</table>'
        . '<p style="font-size:14px;">Haz clic <a href="' . $h($url) . '">aquí</a> para ver el colegio en el CRM.</p>';
    $mail->AltBody = 'Recordatorio: ' . mb_strtolower($tipo) . ' ' . $cuando . ' ' . $fechaProx . ' con el colegio ' . $s['colegio']
        . '. Observación: ' . $s['observacion'] . '. Ver: ' . $url;
    // Si el servidor rechaza a uno de los destinatarios (ej. buzón que ya no existe), PHPMailer igual
    // entrega a los demás y luego lanza la excepción: se da por enviado para no repetírselo a los otros.
    $noEntregados = '';
    try {
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $fallidos = array_filter(array_keys($destinos), fn($c) => stripos($e->getMessage(), $c) !== false);
        if (stripos($e->getMessage(), 'recipients failed') === false || count($fallidos) >= count($destinos)) throw $e;
        $noEntregados = ' (no se pudo entregar a: ' . implode(', ', $fallidos) . ')';
    }
    return implode(', ', array_map(fn($c, $n) => $n . ' <' . $c . '>', array_keys($destinos), $destinos)) . $noEntregados;
}
