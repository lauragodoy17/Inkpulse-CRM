<?php
/**
 * /php/calendario_vd_accion.php
 * Calendario de venta directa (2026-11-10): agendar o quitar una fecha de venta desde calendario_venta_directa.php.
 * Escribe en adopcion_vd_jornadas, la misma tabla del bloque "Venta directa - Eureka" de Adopciones, así que lo que
 * se agenda aquí aparece al entrar al colegio. Cada acción queda en el historial del colegio (módulo "Venta directa").
 *
 * POST accion=agendar: id_colegio, id_periodo, fecha (Y-m-d), hora_ini, hora_fin (HH:MM)
 * POST accion=reprogramar: id, fecha, hora_ini, hora_fin (2026-11-11: cambia la fecha y el horario de una venta programada)
 * POST accion=eliminar: id (adopcion_vd_jornadas.id) — "Cancelar venta" en el calendario
 * Una venta atendida (con formato de venta directa registrado, adopcion_vd_atendidas) no se reprograma ni se cancela.
 * Responde JSON {ok, error?, msg?}.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("registrar_historial.php");
require_once("../includes/devoluciones_muestras_datos.php");
require_once("../includes/sobrecosto_canal.php");
require_once("../includes/adopcion_vd_datos.php");
require_once("../includes/adopcion_cerrada.php");
require_once("../includes/alertas_vd.php");

header('Content-Type: application/json; charset=utf-8');
dm_validar_acceso(true);

function cvd_salir($ok, $texto) {
    echo json_encode($ok ? ['ok' => true, 'msg' => $texto] : ['ok' => false, 'error' => $texto], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cvd_salir(false, 'Solicitud no válida.');

sobrecosto_asegurar_columna($bdd);
adopcion_vd_asegurar($bdd);
alertas_vd_asegurar($bdd);
$id_usuario = (int)($_SESSION['id'] ?? 0);
[$alcance, $params_alcance] = dm_filtro_alcance_sql();
$cond_canal = sql_adopcion_tiene_canal([canal_andrea_id($bdd)]);

/** Colegio con canal Venta directa - Eureka en ese período y dentro del alcance del usuario (o null). */
function cvd_colegio(PDO $bdd, $idColegio, $idPeriodo, $cond_canal, $alcance, $params_alcance) {
    $st = $bdd->prepare("SELECT c.id, c.colegio FROM recursos r JOIN colegios c ON c.id = r.id_colegio
                         WHERE r.id_colegio = ? AND r.id_periodo = ? AND $cond_canal $alcance LIMIT 1");
    $st->execute(array_merge([(int)$idColegio, (int)$idPeriodo], $params_alcance));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$accion = $_POST['accion'] ?? '';

/** La venta (jornada) con su colegio validado; sale con error si no existe, no hay acceso, la adopción está cerrada o ya fue atendida. */
function cvd_jornada_editable(PDO $bdd, $id, $cond_canal, $alcance, $params_alcance, $verbo) {
    $st = $bdd->prepare("SELECT j.id, j.id_colegio, j.id_periodo, j.fecha, TIME_FORMAT(j.hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(j.hora_fin, '%H:%i') AS hora_fin
                         FROM adopcion_vd_jornadas j WHERE j.id = ?");
    $st->execute([(int)$id]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    if (!$j) cvd_salir(false, 'Esa venta ya no existe.');
    $col = cvd_colegio($bdd, $j['id_colegio'], $j['id_periodo'], $cond_canal, $alcance, $params_alcance);
    if (!$col) cvd_salir(false, 'No tienes acceso a ese colegio.');
    if (adopcion_cerrada($bdd, $j['id_colegio'], $j['id_periodo'])) cvd_salir(false, 'La adopción de ' . $col['colegio'] . " está cerrada: no se puede $verbo la venta.");
    $st = $bdd->prepare("SELECT id, id_colegio, id_periodo, fecha FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ?");
    $st->execute([(int)$j['id_colegio'], (int)$j['id_periodo']]);
    $atendidas = adopcion_vd_atendidas($bdd, $st->fetchAll(PDO::FETCH_ASSOC));
    if (isset($atendidas[$j['id']])) cvd_salir(false, "Esa venta ya fue atendida (Formato venta directa No. " . $atendidas[$j['id']]['consecutivo'] . "): no se puede $verbo.");
    return [$j, $col];
}

/** Fecha y horario del POST validados: [fecha, ini, fin] o sale con error. */
function cvd_horario_post() {
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $ini = trim((string)($_POST['hora_ini'] ?? ''));
    $fin = trim((string)($_POST['hora_fin'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) cvd_salir(false, 'Elige la fecha de la venta.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ini) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $fin)) cvd_salir(false, 'Completa la hora de inicio y de fin.');
    if ($fin <= $ini) cvd_salir(false, 'La hora de fin debe ser posterior a la hora de inicio.');
    return [$fecha, $ini, $fin];
}

if ($accion === 'reprogramar') {
    [$j, $col] = cvd_jornada_editable($bdd, (int)($_POST['id'] ?? 0), $cond_canal, $alcance, $params_alcance, 'reprogramar');
    [$fecha, $ini, $fin] = cvd_horario_post();
    if ($fecha === $j['fecha'] && $ini === $j['hora_inicio'] && $fin === $j['hora_fin']) cvd_salir(false, 'Elige una fecha u horario diferente al actual.');
    $st = $bdd->prepare("SELECT COUNT(*) FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ? AND fecha = ? AND hora_inicio < ? AND hora_fin > ? AND id <> ?");
    $st->execute([(int)$j['id_colegio'], (int)$j['id_periodo'], $fecha, $fin . ':00', $ini . ':00', (int)$j['id']]);
    if ((int)$st->fetchColumn() > 0) cvd_salir(false, $col['colegio'] . ' ya tiene otra venta en ese horario.');

    $bdd->beginTransaction();
    $bdd->prepare("UPDATE adopcion_vd_jornadas SET fecha = ?, hora_inicio = ?, hora_fin = ? WHERE id = ?")->execute([$fecha, $ini . ':00', $fin . ':00', (int)$j['id']]);
    // El recordatorio se vuelve a enviar para la nueva fecha.
    $bdd->prepare("DELETE FROM adopcion_vd_alertas WHERE id_jornada = ? AND tipo = 'recordatorio'")->execute([(int)$j['id']]);
    registrar_historial($bdd, $j['id_colegio'], $id_usuario, 'Venta directa', 'Venta reprogramada desde el calendario',
        adopcion_vd_jornadas_texto([$j]), adopcion_vd_jornadas_texto([['fecha' => $fecha, 'hora_inicio' => $ini, 'hora_fin' => $fin]]));
    $bdd->commit();
    cvd_salir(true, 'Se reprogramó la venta de ' . $col['colegio'] . ' para el ' . date('d/m/Y', strtotime($fecha)) . '.');
}

if ($accion === 'agendar') {
    $id_colegio = (int)($_POST['id_colegio'] ?? 0);
    $id_periodo = (int)($_POST['id_periodo'] ?? 0);
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $ini = trim((string)($_POST['hora_ini'] ?? ''));
    $fin = trim((string)($_POST['hora_fin'] ?? ''));

    $col = cvd_colegio($bdd, $id_colegio, $id_periodo, $cond_canal, $alcance, $params_alcance);
    if (!$col) cvd_salir(false, 'Elige un colegio con canal Venta directa - Eureka.');
    if (adopcion_cerrada($bdd, $id_colegio, $id_periodo)) cvd_salir(false, 'La adopción de ' . $col['colegio'] . ' está cerrada: no se pueden agregar fechas.');
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) cvd_salir(false, 'Elige la fecha de la venta.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ini) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $fin)) cvd_salir(false, 'Completa la hora de inicio y de fin.');
    if ($fin <= $ini) cvd_salir(false, 'La hora de fin debe ser posterior a la hora de inicio.');

    // El mismo colegio no puede tener dos ventas que se solapen el mismo día.
    $st = $bdd->prepare("SELECT COUNT(*) FROM adopcion_vd_jornadas WHERE id_colegio = ? AND id_periodo = ? AND fecha = ? AND hora_inicio < ? AND hora_fin > ?");
    $st->execute([$id_colegio, $id_periodo, $fecha, $fin . ':00', $ini . ':00']);
    if ((int)$st->fetchColumn() > 0) cvd_salir(false, $col['colegio'] . ' ya tiene una venta en ese horario.');

    $bdd->beginTransaction();
    $bdd->prepare("INSERT INTO adopcion_vd_jornadas (id_colegio, id_periodo, fecha, hora_inicio, hora_fin, created_at, id_usuario) VALUES (?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([$id_colegio, $id_periodo, $fecha, $ini . ':00', $fin . ':00', $id_usuario ?: null]);
    $id_nueva = (int)$bdd->lastInsertId();
    $st = $bdd->prepare("SELECT vd_tiene_fechas FROM recursos WHERE id_colegio = ? AND id_periodo = ?");
    $st->execute([$id_colegio, $id_periodo]);
    $tenia = $st->fetchColumn();
    if ($tenia === null || (int)$tenia !== 1) {
        $bdd->prepare("UPDATE recursos SET vd_tiene_fechas = 1 WHERE id_colegio = ? AND id_periodo = ?")->execute([$id_colegio, $id_periodo]);
        registrar_historial($bdd, $id_colegio, $id_usuario, 'Venta directa', '¿Ya tiene fechas de venta?', $tenia === null ? '' : 'No', 'Sí');
    }
    registrar_historial($bdd, $id_colegio, $id_usuario, 'Venta directa', 'Fecha de venta agendada desde el calendario', '',
        adopcion_vd_jornadas_texto([['fecha' => $fecha, 'hora_inicio' => $ini, 'hora_fin' => $fin]]));
    $bdd->commit();
    // Alerta por correo/WhatsApp si quien agenda no es el destinatario de las alertas (includes/alertas_vd.php).
    try { alertas_vd_nueva($bdd, [$id_nueva], $id_usuario); } catch (Throwable $e) { error_log('Alertas venta directa: ' . $e->getMessage()); }
    cvd_salir(true, 'Venta agendada en ' . $col['colegio'] . '.');
}

if ($accion === 'eliminar') {
    [$j, $col] = cvd_jornada_editable($bdd, (int)($_POST['id'] ?? 0), $cond_canal, $alcance, $params_alcance, 'cancelar');
    $id = (int)$j['id'];

    $bdd->beginTransaction();
    $bdd->prepare("DELETE FROM adopcion_vd_jornadas WHERE id = ?")->execute([$id]);
    registrar_historial($bdd, $j['id_colegio'], $id_usuario, 'Venta directa', 'Venta cancelada desde el calendario', adopcion_vd_jornadas_texto([$j]), '');
    $bdd->commit();
    cvd_salir(true, 'Se canceló la venta de ' . $col['colegio'] . '.');
}

cvd_salir(false, 'Acción no válida.');
