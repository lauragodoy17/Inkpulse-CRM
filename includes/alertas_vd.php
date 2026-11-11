<?php
/**
 * /includes/alertas_vd.php
 * Alertas de venta directa (pedido de la usuaria 2026-11-10). Llegan al correo y al WhatsApp configurados en
 * conexion/alertas_vd_config.php:
 *
 * 1. Nueva fecha de venta: cuando otro usuario (no el dueño del correo de alertas) programa una fecha, ya sea en la
 *    adopción (php/guardar_definicion.php) o desde el calendario (php/calendario_vd_accion.php).
 * 2. Recordatorio: cuando faltan ALERTAS_VD_DIAS_RECORDATORIO días o menos para una venta. Se revisa solo, como
 *    máximo cada 30 minutos, cuando alguien navega el CRM (template/nav_side.php), y también se puede correr con
 *    una tarea programada: php php/cron_alertas_vd.php
 *
 * Cada venta recibe un solo recordatorio (tabla adopcion_vd_alertas). Si la venta se programó ya dentro del plazo,
 * el aviso de "nueva fecha" hace las veces de recordatorio. Un fallo al enviar nunca detiene la acción del usuario:
 * se registra en el log de PHP.
 */
require_once __DIR__ . '/../conexion/alertas_vd_config.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/sobrecosto_canal.php';

/** Tabla de alertas enviadas. DDL: llamar fuera de transacciones. */
function alertas_vd_asegurar(PDO $bdd) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $bdd->exec("CREATE TABLE IF NOT EXISTS adopcion_vd_alertas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_jornada INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            enviado_at DATETIME NOT NULL,
            UNIQUE KEY uq_jornada_tipo (id_jornada, tipo)
        )");
    } catch (Exception $e) {}
}

// ── Formato ──────────────────────────────────────────────────────────────────
function alertas_vd_fecha_larga($ymd) {
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $ts = strtotime($ymd);
    return $dias[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}
function alertas_vd_hora($hhmm) {
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return ($h % 12 ?: 12) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ($h < 12 ? ' a. m.' : ' p. m.');
}
/** "Hoy", "Mañana", "En 2 días". */
function alertas_vd_cuando($ymd) {
    $d = (int)round((strtotime($ymd) - strtotime(date('Y-m-d'))) / 86400);
    return $d <= 0 ? 'Hoy' : ($d === 1 ? 'Mañana' : "En $d días");
}

/** Datos de las ventas para los mensajes. */
function alertas_vd_info(PDO $bdd, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $st = $bdd->prepare("SELECT j.id, j.fecha, TIME_FORMAT(j.hora_inicio, '%H:%i') AS hi, TIME_FORMAT(j.hora_fin, '%H:%i') AS hf,
                                j.id_periodo, c.colegio, c.ciudad, c.codigo, c.direccion,
                                r.vd_contacto_nombre, r.vd_contacto_celular,
                                TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS programo
                         FROM adopcion_vd_jornadas j
                         JOIN colegios c ON c.id = j.id_colegio
                         LEFT JOIN recursos r ON r.id_colegio = j.id_colegio AND r.id_periodo = j.id_periodo
                         LEFT JOIN usuarios u ON u.id = j.id_usuario
                         WHERE j.id IN (" . implode(',', $ids) . ")
                         ORDER BY j.fecha, j.hora_inicio, c.colegio");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Tabla HTML de ventas para el correo. */
function alertas_vd_tabla_html(array $filas) {
    $th = 'padding:6px 10px;border-bottom:2px solid #c7d2fe;text-align:left;font-size:13px;color:#3730a3;';
    $td = 'padding:6px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top;';
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $html = '<table style="border-collapse:collapse;margin:8px 0 14px;"><thead><tr>'
          . '<th style="' . $th . '">Colegio</th><th style="' . $th . '">Fecha</th><th style="' . $th . '">Horario</th><th style="' . $th . '">Contacto del colegio</th>'
          . '</tr></thead><tbody>';
    foreach ($filas as $f) {
        $url = ALERTAS_VD_URL_CRM . 'colegio.php?codigo=' . urlencode((string)$f['codigo']) . '&periodo=' . (int)$f['id_periodo'] . '&tab=adopciones';
        $html .= '<tr>'
              . '<td style="' . $td . '"><a href="' . $h($url) . '" style="color:#4338ca;font-weight:bold;">' . $h($f['colegio']) . '</a>'
              . ($f['ciudad'] ? '<br><span style="color:#64748b;font-size:12px;">' . $h($f['ciudad']) . ($f['direccion'] ? ' · ' . $h($f['direccion']) : '') . '</span>' : '') . '</td>'
              . '<td style="' . $td . '">' . $h(ucfirst(alertas_vd_fecha_larga($f['fecha']))) . '<br><span style="color:#b45309;font-size:12px;font-weight:bold;">' . alertas_vd_cuando($f['fecha']) . '</span></td>'
              . '<td style="' . $td . 'white-space:nowrap;">' . alertas_vd_hora($f['hi']) . ' – ' . alertas_vd_hora($f['hf']) . '</td>'
              . '<td style="' . $td . '">' . ($f['vd_contacto_nombre'] ? $h($f['vd_contacto_nombre']) . '<br><span style="color:#64748b;font-size:12px;">' . $h($f['vd_contacto_celular']) . '</span>' : '<span style="color:#94a3b8;">Sin registrar</span>') . '</td>'
              . '</tr>';
    }
    return $html . '</tbody></table>';
}

/** Texto de WhatsApp de una venta. */
function alertas_vd_linea_texto(array $f) {
    return '• ' . $f['colegio'] . ($f['ciudad'] ? ' (' . $f['ciudad'] . ')' : '') . "\n  "
         . ucfirst(alertas_vd_fecha_larga($f['fecha'])) . ' (' . alertas_vd_cuando($f['fecha']) . ")\n  "
         . alertas_vd_hora($f['hi']) . ' – ' . alertas_vd_hora($f['hf'])
         . ($f['vd_contacto_nombre'] ? "\n  Contacto: " . $f['vd_contacto_nombre'] . ' ' . $f['vd_contacto_celular'] : '');
}

// ── Envío ────────────────────────────────────────────────────────────────────
/** Correo al destinatario de alertas. Devuelve true si se envió. */
function alertas_vd_enviar_correo($asunto, $cuerpoHtml, $alt) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = 'somoseureka.com.co';
        $mail->SMTPAuth    = true;
        $mail->SMTPAutoTLS = false;
        $mail->Username    = 'crm@somoseureka.com.co';
        $mail->Password    = 'cRm14356$';
        $mail->SMTPSecure  = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->Timeout     = 15;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
        $mail->addAddress(ALERTAS_VD_CORREO);
        $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $asunto;
        $mail->Body = '<div style="font-family:Arial,sans-serif;color:#1e293b;">' . $cuerpoHtml
                    . '<p style="font-size:13px;margin-top:16px;"><a href="' . ALERTAS_VD_URL_CRM . 'calendario_venta_directa.php" style="color:#4338ca;">Ver el calendario de venta directa</a></p></div>';
        $mail->AltBody = $alt;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Alertas venta directa: no se pudo enviar el correo "' . $asunto . '": ' . $e->getMessage());
        return false;
    }
}

/** WhatsApp por CallMeBot (si hay apikey). Devuelve true si se envió. */
function alertas_vd_enviar_whatsapp($texto) {
    if (ALERTAS_VD_CALLMEBOT_APIKEY === '' || ALERTAS_VD_CELULAR === '' || !function_exists('curl_init')) return false;
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone' => ALERTAS_VD_CELULAR, 'text' => mb_substr($texto, 0, 1500), 'apikey' => ALERTAS_VD_CALLMEBOT_APIKEY,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        error_log('Alertas venta directa: no se pudo enviar el WhatsApp (HTTP ' . $code . ') ' . $err);
        return false;
    }
    return true;
}

// ── 1. Nueva fecha de venta ──────────────────────────────────────────────────
/** ¿El usuario es el mismo que recibe las alertas? (no se le avisa de lo que él mismo programa) */
function alertas_vd_es_destinatario(PDO $bdd, $idUsuario) {
    $st = $bdd->prepare("SELECT correo FROM usuarios WHERE id = ?");
    $st->execute([(int)$idUsuario]);
    return strcasecmp(trim((string)$st->fetchColumn()), ALERTAS_VD_CORREO) === 0;
}

/**
 * Avisa que se programaron nuevas fechas de venta ($ids = adopcion_vd_jornadas.id). Si quien las programó es el
 * destinatario de las alertas, no se envía nada.
 */
function alertas_vd_nueva(PDO $bdd, array $ids, $idUsuario) {
    if (!$ids || alertas_vd_es_destinatario($bdd, $idUsuario)) return;
    alertas_vd_asegurar($bdd);
    $filas = alertas_vd_info($bdd, $ids);
    if (!$filas) return;
    $st = $bdd->prepare("SELECT TRIM(CONCAT(COALESCE(nombres, ''), ' ', COALESCE(apellidos, ''))) FROM usuarios WHERE id = ?");
    $st->execute([(int)$idUsuario]);
    $programo = $st->fetchColumn() ?: ($filas[0]['programo'] ?: 'Un usuario');
    $colegios = array_values(array_unique(array_column($filas, 'colegio')));
    $n = count($filas);
    $asunto = ($n === 1 ? 'Nueva fecha de venta directa: ' : "$n nuevas fechas de venta directa: ") . implode(', ', $colegios);
    $html = '<p style="font-size:15px;"><strong>' . htmlspecialchars($programo) . '</strong> programó '
          . ($n === 1 ? 'una nueva fecha' : "$n nuevas fechas") . ' de venta directa:</p>' . alertas_vd_tabla_html($filas);
    $texto = "Venta directa - Eureka\n$programo programó " . ($n === 1 ? 'una nueva fecha' : "$n nuevas fechas") . " de venta:\n\n"
           . implode("\n\n", array_map('alertas_vd_linea_texto', $filas));
    alertas_vd_enviar_correo($asunto, $html, $texto);
    alertas_vd_enviar_whatsapp($texto);

    // Las que ya están dentro del plazo del recordatorio no reciben otro aviso.
    $limite = date('Y-m-d', strtotime('+' . ALERTAS_VD_DIAS_RECORDATORIO . ' days'));
    $ins = $bdd->prepare("INSERT IGNORE INTO adopcion_vd_alertas (id_jornada, tipo, enviado_at) VALUES (?, 'recordatorio', NOW())");
    foreach ($filas as $f) {
        if ($f['fecha'] <= $limite) $ins->execute([(int)$f['id']]);
    }
}

// ── 2. Recordatorio de ventas cercanas ───────────────────────────────────────
/**
 * Envía un recordatorio de las ventas que están a ALERTAS_VD_DIAS_RECORDATORIO días o menos y aún no lo tienen.
 * Sin $forzar, revisa como máximo cada 30 minutos. Devuelve cuántas ventas se avisaron.
 */
function alertas_vd_recordatorios(PDO $bdd, $forzar = false) {
    alertas_vd_asegurar($bdd);
    if (!$forzar) {
        // Marca de la última revisión: fila id_jornada = 0, tipo 'revision'.
        $st = $bdd->query("SELECT enviado_at FROM adopcion_vd_alertas WHERE id_jornada = 0 AND tipo = 'revision'");
        $ultima = $st->fetchColumn();
        if ($ultima && strtotime($ultima) > time() - 1800) return 0;
        $bdd->exec("INSERT INTO adopcion_vd_alertas (id_jornada, tipo, enviado_at) VALUES (0, 'revision', NOW())
                    ON DUPLICATE KEY UPDATE enviado_at = NOW()");
    }

    $hoy = date('Y-m-d');
    $limite = date('Y-m-d', strtotime('+' . ALERTAS_VD_DIAS_RECORDATORIO . ' days'));
    $cond_canal = sql_adopcion_tiene_canal([canal_andrea_id($bdd)]);
    $st = $bdd->prepare("SELECT j.id FROM adopcion_vd_jornadas j
                         JOIN recursos r ON r.id_colegio = j.id_colegio AND r.id_periodo = j.id_periodo
                         LEFT JOIN adopcion_vd_alertas a ON a.id_jornada = j.id AND a.tipo = 'recordatorio'
                         WHERE a.id IS NULL AND $cond_canal AND j.fecha BETWEEN ? AND ?
                           AND (j.fecha > ? OR j.hora_fin > CURTIME())");
    $st->execute([$hoy, $limite, $hoy]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return 0;

    // Se apartan antes de enviar para que dos visitas al mismo tiempo no manden el aviso dos veces.
    $ins = $bdd->prepare("INSERT IGNORE INTO adopcion_vd_alertas (id_jornada, tipo, enviado_at) VALUES (?, 'recordatorio', NOW())");
    $mios = [];
    foreach ($ids as $id) {
        $ins->execute([$id]);
        if ($ins->rowCount() > 0) $mios[] = $id;
    }
    $filas = alertas_vd_info($bdd, $mios);
    if (!$filas) return 0;

    $n = count($filas);
    $asunto = 'Recordatorio: ' . ($n === 1 ? 'venta directa ' . mb_strtolower(alertas_vd_cuando($filas[0]['fecha'])) . ' en ' . $filas[0]['colegio'] : "$n ventas directas próximas");
    $html = '<p style="font-size:15px;">' . ($n === 1 ? 'Se acerca una venta directa' : "Se acercan $n ventas directas") . ' (faltan '
          . ALERTAS_VD_DIAS_RECORDATORIO . ' días o menos):</p>' . alertas_vd_tabla_html($filas);
    $texto = "Recordatorio - Venta directa Eureka\n" . ($n === 1 ? 'Se acerca una venta:' : "Se acercan $n ventas:") . "\n\n"
           . implode("\n\n", array_map('alertas_vd_linea_texto', $filas));
    $ok = alertas_vd_enviar_correo($asunto, $html, $texto);
    $ok_wa = alertas_vd_enviar_whatsapp($texto);
    if (!$ok && !$ok_wa) {
        // No salió ni el correo ni el WhatsApp: se liberan para intentarlo en la próxima revisión.
        $bdd->exec("DELETE FROM adopcion_vd_alertas WHERE tipo = 'recordatorio' AND id_jornada IN (" . implode(',', $mios) . ")");
        return 0;
    }
    return $n;
}
