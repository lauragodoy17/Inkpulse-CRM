<?php
/**
 * /calendario_venta_directa.php
 * Menú Venta directa → Calendario (2026-11-10): cronograma de las fechas y horarios de venta que se registran en
 * Adopciones (bloque "Venta directa - Eureka", tabla adopcion_vd_jornadas) de los colegios cuya adopción tiene ese
 * canal. Vista de mes / semana / día (FullCalendar 3.9, el mismo de agenda.php) y lista con todas las ventas por mes
 * (2026-11-11: reemplaza el panel de próximas ventas y la tarjeta de equipos simultáneos). Cada venta está atendida si ya
 * tiene formato de venta directa registrado (adopcion_vd_atendidas) o pendiente si no. Marca las ventas simultáneas.
 * Desde aquí se agenda, reprograma o cancela una venta (php/calendario_vd_accion.php): queda en la adopción del colegio
 * y en su historial (módulo "Venta directa").
 * Respeta el alcance por zona del usuario (dm_filtro_alcance_sql).
 */
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/devoluciones_muestras_datos.php"); // dm_validar_acceso, dm_filtro_alcance_sql
require_once("includes/sobrecosto_canal.php");
require_once("includes/adopcion_vd_datos.php");

dm_validar_acceso();
sobrecosto_asegurar_columna($bdd);
adopcion_vd_asegurar($bdd);

[$alcance, $params_alcance] = dm_filtro_alcance_sql();
$cond_canal = sql_adopcion_tiene_canal([canal_andrea_id($bdd)]);

// Períodos con fechas de venta (filtro). Por defecto: todos.
$st = $bdd->prepare("SELECT DISTINCT pe.id, pe.periodo FROM adopcion_vd_jornadas j
                     JOIN recursos r ON r.id_colegio = j.id_colegio AND r.id_periodo = j.id_periodo
                     JOIN colegios c ON c.id = j.id_colegio JOIN periodos pe ON pe.id = j.id_periodo
                     WHERE $cond_canal $alcance ORDER BY pe.id DESC");
$st->execute($params_alcance);
$periodos = $st->fetchAll(PDO::FETCH_ASSOC);
$periodo_sel = (int)($_GET['periodo'] ?? 0);

$filtro = '';
$params = $params_alcance;
if ($periodo_sel > 0) { $filtro = ' AND j.id_periodo = ?'; $params[] = $periodo_sel; }

$st = $bdd->prepare("SELECT j.id, j.fecha, TIME_FORMAT(j.hora_inicio, '%H:%i') AS hi, TIME_FORMAT(j.hora_fin, '%H:%i') AS hf,
                            c.id AS id_colegio, c.codigo, c.colegio, c.ciudad, c.direccion, pe.id AS id_periodo, pe.periodo,
                            r.vd_contacto_nombre, r.vd_contacto_celular, r.vd_contacto_correo,
                            COALESCE(NULLIF(TRIM(z.zona), ''), c.cod_zona) AS zona
                     FROM adopcion_vd_jornadas j
                     JOIN recursos r ON r.id_colegio = j.id_colegio AND r.id_periodo = j.id_periodo
                     JOIN colegios c ON c.id = j.id_colegio
                     JOIN periodos pe ON pe.id = j.id_periodo
                     LEFT JOIN zonas z ON z.codigo = c.cod_zona
                     WHERE $cond_canal $alcance $filtro
                     ORDER BY j.fecha, j.hora_inicio, c.colegio");
$st->execute($params);
$jornadas = $st->fetchAll(PDO::FETCH_ASSOC);

// Color fijo por colegio (paleta legible sobre blanco).
$paleta = ['#4f46e5', '#0891b2', '#16a34a', '#d97706', '#db2777', '#7c3aed', '#0d9488', '#dc2626', '#2563eb', '#65a30d', '#c2410c', '#9333ea'];
$colores = [];
// Ventas simultáneas: misma fecha y horas que se solapan entre colegios distintos.
$por_fecha = [];
foreach ($jornadas as $j) $por_fecha[$j['fecha']][] = $j;
$cruces = [];
foreach ($por_fecha as $lista) {
    for ($a = 0; $a < count($lista); $a++) {
        for ($b = $a + 1; $b < count($lista); $b++) {
            if ($lista[$a]['id_colegio'] == $lista[$b]['id_colegio']) continue;
            if ($lista[$a]['hi'] < $lista[$b]['hf'] && $lista[$b]['hi'] < $lista[$a]['hf']) {
                $cruces[$lista[$a]['id']][] = $lista[$b]['colegio'];
                $cruces[$lista[$b]['id']][] = $lista[$a]['colegio'];
            }
        }
    }
}

$hoy = date('Y-m-d');
$eventos = [];
$atendidas = 0;
$por_atender = 0;
$prox7 = 0;
$este_mes = 0;
$con_formato = adopcion_vd_atendidas($bdd, $jornadas); // [id_jornada => formato]
foreach ($jornadas as $j) {
    if (!isset($colores[$j['id_colegio']])) $colores[$j['id_colegio']] = $paleta[count($colores) % count($paleta)];
    [$h1, $m1] = array_map('intval', explode(':', $j['hi']));
    [$h2, $m2] = array_map('intval', explode(':', $j['hf']));
    $min = max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
    // Atendida: tiene formato de venta directa registrado. Pendiente: no lo tiene (aunque la fecha ya haya pasado).
    $formato = $con_formato[$j['id']] ?? null;
    $terminada = $formato !== null;
    if ($terminada) $atendidas++; else $por_atender++;
    if ($j['fecha'] >= $hoy) {
        if ($j['fecha'] <= date('Y-m-d', strtotime('+7 days'))) $prox7++;
    }
    if (substr($j['fecha'], 0, 7) === date('Y-m')) $este_mes++;
    $eventos[] = [
        'id'        => (int)$j['id'],
        'title'     => $j['colegio'],
        'start'     => $j['fecha'] . 'T' . $j['hi'] . ':00',
        'end'       => $j['fecha'] . 'T' . $j['hf'] . ':00',
        'color'     => $colores[$j['id_colegio']],
        'className' => ($terminada ? 'vdc-atendida' : 'vdc-pendiente') . (isset($cruces[$j['id']]) ? ' vdc-cruce' : ''),
        'colegio'   => $j['colegio'],
        'id_colegio' => (int)$j['id_colegio'],
        'ciudad'    => $j['ciudad'],
        'direccion' => $j['direccion'],
        'zona'      => $j['zona'],
        'periodo'   => $j['periodo'],
        'contacto'  => $j['vd_contacto_nombre'],
        'celular'   => $j['vd_contacto_celular'],
        'correo'    => $j['vd_contacto_correo'],
        'minutos'   => $min,
        'atendida'  => $terminada,
        'formato'   => $formato ? 'Formato venta directa No. ' . $formato['consecutivo'] : '',
        'formato_url' => $formato ? 'formato_venta_directa.php?id=' . $formato['id'] : '',
        'pasada'    => $j['fecha'] < $hoy,
        'hi'        => $j['hi'],
        'hf'        => $j['hf'],
        'cruces'    => array_values(array_unique($cruces[$j['id']] ?? [])),
        'informe'   => 'php/adopcion_excel.php?cole=' . (int)$j['id_colegio'] . '&periodo=' . (int)$j['id_periodo'],
        'ver'       => 'colegio.php?codigo=' . urlencode((string)$j['codigo']) . '&periodo=' . (int)$j['id_periodo'] . '&tab=adopciones',
    ];
}

// Colegios a los que se les puede agendar una venta: canal Venta directa - Eureka, dentro del alcance, adopción abierta.
require_once("includes/adopcion_cerrada.php");
asegurar_columna_cerrado($bdd);
$st = $bdd->prepare("SELECT c.id AS id_colegio, c.colegio, c.ciudad, pe.id AS id_periodo, pe.periodo
                     FROM recursos r JOIN colegios c ON c.id = r.id_colegio JOIN periodos pe ON pe.id = r.id_periodo
                     WHERE $cond_canal $alcance AND COALESCE(r.cerrado, 0) <> 1" . ($periodo_sel > 0 ? ' AND r.id_periodo = ?' : '') . "
                     ORDER BY pe.id DESC, c.colegio");
$st->execute($periodo_sel > 0 ? array_merge($params_alcance, [$periodo_sel]) : $params_alcance);
$colegios_agendar = $st->fetchAll(PDO::FETCH_ASSOC);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_dur = fn($m) => trim((intdiv($m, 60) ? intdiv($m, 60) . ' h' : '') . ($m % 60 ? ' ' . ($m % 60) . ' min' : '')) ?: '0 h';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Calendario venta directa</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="src/plugins/fullcalendar/fullcalendar.css" />
  <link rel="stylesheet" type="text/css" href="src/vd-pickers.css?v=20261110" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .vdc-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px; }
    .vdc-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; display:flex; gap:12px; align-items:center; box-shadow:0 1px 3px rgba(15,23,42,.05); }
    .vdc-kpi i { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
    .vdc-kpi span { display:block; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; }
    .vdc-kpi strong { font-size:1.25rem; color:#0f172a; display:block; line-height:1.2; }
    .vdc-kpi small { display:block; font-size:.72rem; color:#94a3b8; font-weight:500; margin-top:1px; }
    .vdc-k5 { background:#fef3c7; color:#b45309; }
    .vdc-k1 { background:#eef2ff; color:#4338ca; } .vdc-k2 { background:#dcfce7; color:#15803d; } .vdc-k3 { background:#e0f2fe; color:#0369a1; } .vdc-k4 { background:#fee2e2; color:#dc2626; }

    .vdc-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; box-shadow:0 1px 3px rgba(15,23,42,.05); }
    .vdc-card h5 { font-size:.95rem; font-weight:700; color:#0f172a; margin:0 0 12px; display:flex; align-items:center; gap:8px; }
    .vdc-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .vdc-filtros select, .vdc-filtros input { height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:.86rem; background:#fff; }
    .vdc-filtros input { flex:1 1 220px; }

    /* FullCalendar */
    #vdc-cal .fc-toolbar h2 { font-size:1.15rem; font-weight:800; color:#1e1b4b; text-transform:capitalize; }
    #vdc-cal .fc-button { background:#fff; border:1px solid #c7d2fe; color:#4338ca; text-shadow:none; box-shadow:none; height:34px; padding:0 12px; font-weight:600; text-transform:capitalize; }
    #vdc-cal .fc-button:hover { background:#eef2ff; }
    #vdc-cal .fc-state-active, #vdc-cal .fc-state-active:hover { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    #vdc-cal .fc-state-disabled { opacity:.5; }
    #vdc-cal .fc-day-header { padding:8px 0; font-size:.78rem; text-transform:uppercase; color:#64748b; font-weight:700; }
    #vdc-cal td.fc-today { background:#f5f3ff; }
    #vdc-cal .fc-event { border-radius:7px; border:none; padding:2px 5px; font-size:.76rem; cursor:pointer; box-shadow:0 1px 3px rgba(15,23,42,.15); }
    #vdc-cal .fc-event .fc-time { font-weight:700; }
    #vdc-cal .fc-day-grid-event .fc-content { white-space:normal; line-height:1.25; }
    #vdc-cal .fc-day-grid-event .fc-time { display:block; }
    #vdc-cal .fc-event.vdc-atendida .fc-title::before { content:"\2714  "; }
    #vdc-cal .fc-event.vdc-cruce { box-shadow:0 0 0 2px #dc2626; }
    #vdc-cal .fc-list-item { cursor:pointer; }
    #vdc-cal .fc-time-grid .fc-slats td { height:1.7em; }
    #vdc-cal .fc-axis { font-size:.74rem; color:#64748b; }
    /* Semana / Día: ventas como tarjetas claras, lado a lado si coinciden */
    #vdc-cal .fc-time-grid-event.vdc-tarjeta { border:none; border-left:4px solid; border-radius:8px; padding:5px 8px; box-shadow:0 1px 2px rgba(15,23,42,.08); margin:1px 3px 1px 1px; overflow:hidden; }
    #vdc-cal .fc-time-grid-event.vdc-tarjeta:hover { box-shadow:0 4px 12px rgba(15,23,42,.14); z-index:5 !important; }
    #vdc-cal .fc-time-grid-event.vdc-tarjeta.vdc-cruce { box-shadow:0 1px 2px rgba(15,23,42,.08); }
    #vdc-cal .fc-time-grid-event.vdc-tarjeta .fc-bg { display:none; }
    #vdc-cal .fc-time-grid-event.vdc-tarjeta .fc-content { white-space:normal; overflow:hidden; height:100%; display:flex; flex-direction:column; gap:2px; }
    .vdc-t-hora { font-size:.72rem; font-weight:800; letter-spacing:.01em; }
    .vdc-t-col { font-size:.84rem; font-weight:700; line-height:1.25; color:#0f172a; }
    .vdc-t-sub { font-size:.74rem; color:#475569; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .vdc-t-sub i { color:#94a3b8; margin-right:2px; }
    .vdc-t-sim { align-self:flex-start; margin-top:3px; font-size:.68rem; font-weight:700; color:#b45309; background:#fef3c7; border-radius:6px; padding:1px 7px; }
    #vdc-cal .fc-agendaWeek-view .fc-time-grid-event.vdc-tarjeta { padding:4px 5px; border-left-width:3px; border-radius:6px; }
    #vdc-cal .fc-agendaWeek-view .vdc-t-hora { font-size:.66rem; white-space:nowrap; overflow:hidden; text-overflow:clip; }
    #vdc-cal .fc-agendaWeek-view .vdc-t-col { font-size:.7rem; line-height:1.2; overflow-wrap:break-word; hyphens:auto; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:6; overflow:hidden; }
    #vdc-cal .fc-toolbar h2 { text-transform:none; }
    #vdc-cal .fc-toolbar h2::first-letter { text-transform:uppercase; }
    #vdc-cal .fc-agendaDay-view .fc-slats td { height:2.4em; }
    #vdc-cal .fc-agendaDay-view .fc-head { display:none; }
    #vdc-cal .fc-time-grid .fc-slats .fc-minor td { border-top:1px dashed #eef2f7; }
    #vdc-cal .fc-time-grid .fc-slats td { border-color:#e5e9f0; }
    #vdc-cal .fc-time-grid .fc-axis { vertical-align:top; padding:3px 8px 0 4px; text-align:right; white-space:nowrap; font-weight:600; }
    #vdc-cal .fc-now-indicator-line { border-color:#ef4444; border-top-width:2px; }
    #vdc-cal .fc-now-indicator-arrow { border-color:#ef4444; border-top-color:transparent; border-bottom-color:transparent; }
    #vdc-cal .fc-agendaDay-view td.fc-today, #vdc-cal .fc-agendaWeek-view td.fc-today { background:#fafaff; }
    #vdc-cal .fc-list-heading td { background:#eef2ff; color:#3730a3; font-weight:700; }

    /* Estado de la venta: atendida = tiene formato registrado, pendiente = no */
    .vdc-estado { display:inline-flex; align-items:center; gap:4px; font-size:.7rem; font-weight:700; border-radius:20px; padding:2px 9px; white-space:nowrap; }
    .vdc-estado.ok { background:#dcfce7; color:#166534; }
    .vdc-estado.pend { background:#f3e8ff; color:#6b21a8; }
    .vdc-estado.pend.vencida { background:#fef3c7; color:#92400e; }

    /* Vista Semana (basicWeek): columnas por día con tarjetas claras */
    #vdc-cal .fc-basicWeek-view .fc-day-header { padding:10px 0; }
    .vdc-sem-dia { display:block; font-size:.7rem; letter-spacing:.06em; color:#64748b; }
    .vdc-sem-num { display:block; font-size:1.25rem; font-weight:800; color:#0f172a; text-transform:none; line-height:1.15; }
    #vdc-cal .fc-basicWeek-view .fc-today .vdc-sem-num, #vdc-cal .fc-basicWeek-view th.fc-today .vdc-sem-num { color:#4f46e5; }
    #vdc-cal .fc-basicWeek-view .fc-row { min-height:320px; }
    #vdc-cal .fc-basicWeek-view td.fc-today { background:#f5f3ff; }
    #vdc-cal .fc-basicWeek-view .fc-day-grid-event.vdc-sem { margin:6px 5px 0; padding:8px 9px; border:1px solid #e2e8f0; border-left:4px solid; border-radius:10px; background:#fff; color:#1e293b; box-shadow:0 1px 3px rgba(15,23,42,.08); }
    #vdc-cal .fc-basicWeek-view .fc-day-grid-event.vdc-sem:hover { box-shadow:0 4px 12px rgba(15,23,42,.14); }
    #vdc-cal .fc-basicWeek-view .fc-day-grid-event.vdc-sem .fc-content { white-space:normal; display:flex; flex-direction:column; gap:3px; }
    .vdc-sem-hora { font-size:.74rem; font-weight:800; display:flex; align-items:center; gap:4px; }
    .vdc-sem-col { font-size:.82rem; font-weight:700; line-height:1.25; color:#0f172a; overflow-wrap:anywhere; }
    .vdc-sem-sub { font-size:.72rem; color:#64748b; }
    .vdc-sem-sim { font-size:.68rem; font-weight:700; color:#b45309; }

    /* Lista: todas las ventas agrupadas por mes */
    #vdc-lista { display:none; margin-top:6px; }
    .vdc-l-filtros { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
    .vdc-l-filtros button { border:1px solid #c7d2fe; background:#fff; color:#4338ca; border-radius:20px; padding:5px 14px; font-size:.8rem; font-weight:600; cursor:pointer; }
    .vdc-l-filtros button.activo { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .vdc-l-mes { display:flex; align-items:baseline; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:18px 0 8px; padding:8px 12px; background:#eef2ff; border-radius:10px; }
    .vdc-l-mes:first-child { margin-top:0; }
    .vdc-l-mes strong { font-size:.95rem; color:#1e1b4b; }
    .vdc-l-mes small { font-size:.76rem; color:#475569; font-weight:600; }
    .vdc-l-fila { display:grid; grid-template-columns:58px 150px minmax(0, 1fr) auto; gap:12px; align-items:center; padding:10px 12px; border:1px solid #eef2f7; border-left:4px solid var(--c); border-radius:10px; margin-bottom:8px; cursor:pointer; background:#fff; transition:box-shadow .12s, background .12s; }
    .vdc-l-fila:hover { background:#f8fafc; box-shadow:0 2px 8px rgba(15,23,42,.07); }
    .vdc-l-fecha { text-align:center; line-height:1.1; }
    .vdc-l-fecha b { display:block; font-size:1.3rem; color:#0f172a; }
    .vdc-l-fecha span { font-size:.68rem; text-transform:uppercase; color:#64748b; font-weight:700; }
    .vdc-l-hora { font-size:.8rem; font-weight:700; color:#334155; }
    .vdc-l-hora small { display:block; color:#94a3b8; font-weight:600; }
    .vdc-l-info { min-width:0; }
    .vdc-l-info strong { display:block; font-size:.86rem; color:#0f172a; }
    .vdc-l-info span { display:block; font-size:.74rem; color:#64748b; }
    .vdc-l-fila.es-hoy { box-shadow:0 0 0 2px #c7d2fe; }
    @media (max-width: 640px) {
      .vdc-l-fila { grid-template-columns:50px minmax(0, 1fr); }
      .vdc-l-hora { grid-column:2; grid-row:1; }
      .vdc-l-info { grid-column:1 / -1; }
      .vdc-l-fila > .vdc-estado { grid-column:1 / -1; justify-self:start; }
    }
    .vdc-alerta { display:inline-flex; gap:4px; align-items:center; margin-top:3px; font-size:.7rem; font-weight:700; color:#b91c1c; background:#fee2e2; border-radius:6px; padding:1px 6px; }
    .vdc-vacio { text-align:center; color:#94a3b8; font-size:.85rem; padding:30px 10px; }
    #vdc-cal .fc-lista-button.vdc-activo { background:#4f46e5; border-color:#4f46e5; color:#fff; }

    /* Detalle */
    .vdc-modal .modal-content { border:none; border-radius:16px; overflow:hidden; }
    .vdc-modal .modal-header { border:none; color:#fff; padding:18px 20px; }
    .vdc-modal .modal-title { font-weight:800; font-size:1.05rem; }
    .vdc-modal .close { color:#fff; opacity:.9; text-shadow:none; }
    .vdc-det { display:grid; grid-template-columns:28px 1fr; gap:10px 8px; font-size:.88rem; color:#1e293b; }
    .vdc-det i { color:#6366f1; font-size:1rem; }
    .vdc-det small { color:#64748b; display:block; }
    .vdc-btns { display:flex; gap:8px; flex-wrap:wrap; }
    .vdc-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-size:.84rem; font-weight:600; text-decoration:none; }
    .vdc-btn-xls { background:#16a34a; color:#fff; } .vdc-btn-xls:hover { background:#15803d; color:#fff; text-decoration:none; }
    .vdc-btn-ver { border:1.5px solid #4f46e5; color:#4f46e5; } .vdc-btn-ver:hover { background:#4f46e5; color:#fff; text-decoration:none; }
    button.vdc-btn { border:none; cursor:pointer; }
    .vdc-btn-nuevo { background:#4f46e5; color:#fff; height:38px; } .vdc-btn-nuevo:hover { background:#4338ca; color:#fff; }
    button.vdc-btn-quitar { background:#fff; color:#dc2626; border:1.5px solid #fecaca; } .vdc-btn-quitar:hover { background:#dc2626; border-color:#dc2626; color:#fff; }
    button.vdc-btn-repro { background:#4f46e5; color:#fff; border:1.5px solid #4f46e5; } .vdc-btn-repro:hover { background:#4338ca; border-color:#4338ca; }
    .vdc-det-nota { margin-top:14px; padding:10px 12px; border-radius:10px; font-size:.84rem; display:flex; gap:8px; align-items:flex-start; }
    .vdc-det-nota.ok { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
    .vdc-det-nota.pend { background:#faf5ff; color:#6b21a8; border:1px solid #e9d5ff; }
    .vdc-det-nota a { font-weight:700; color:inherit; text-decoration:underline; }
    .vdc-ag-col { padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:9px; font-size:.86rem; margin-bottom:12px; }
    .vdc-ag-col small { display:block; color:#64748b; }
    .vdc-btn:disabled { opacity:.6; cursor:wait; }
    .vdc-campo { margin-bottom:12px; min-width:0; }
    .vdc-campo label { display:block; font-size:.76rem; font-weight:700; color:#475569; margin-bottom:4px; }
    .vdc-campo select { width:100%; height:40px; border:1px solid #c7d2fe; border-radius:9px; padding:0 10px; font-size:.88rem; background:#fff; color:#1e293b; }
    .vdc-campo select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.18); }
    .vdc-campos3 { display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:10px; }
    .vdc-campo .vd-inp input { height:40px; }
    .vdc-ag-dur { margin:-4px 0 8px; font-size:.78rem; font-weight:700; color:#4338ca; }
    .vdc-ag-dur:empty { display:none; }
    .vdc-ag-dur i { margin-right:4px; }
    @media (max-width: 480px) { .vdc-campos3 { grid-template-columns:1fr 1fr; } .vdc-campos3 .vdc-campo:first-child { grid-column:1 / -1; } }
    #vdc-cal td.fc-day, #vdc-cal .fc-time-grid .fc-slats td { cursor:copy; }
  </style>
</head>
<body>
<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">
      <div class="page-header">
        <div class="title"><h4>Calendario de venta directa</h4></div>
        <small class="text-muted">Fechas y horarios de venta registrados en la adopción de los colegios con canal <strong>Venta directa - Eureka</strong>.</small>
      </div>

      <div class="vdc-kpis">
        <div class="vdc-kpi"><i class="bi bi-calendar-week vdc-k1"></i><div><span>Ventas en los próximos 7 días</span><strong><?= $prox7 ?></strong></div></div>
        <div class="vdc-kpi"><i class="bi bi-calendar3 vdc-k3"></i><div><span>Ventas este mes</span><strong><?= $este_mes ?></strong></div></div>
        <div class="vdc-kpi"><i class="bi bi-check2-circle vdc-k2"></i><div><span>Ventas atendidas</span><strong><?= $atendidas ?></strong><small>Con formato registrado</small></div></div>
        <div class="vdc-kpi"><i class="bi bi-hourglass-split vdc-k5"></i><div><span>Ventas pendientes</span><strong><?= $por_atender ?></strong><small>Sin formato registrado</small></div></div>
      </div>

      <div class="vdc-filtros">
        <form method="GET" id="vdc-form">
          <select name="periodo" onchange="this.form.submit()" aria-label="Período">
            <option value="0">Todos los períodos</option>
            <?php foreach ($periodos as $p): ?>
              <option value="<?= (int)$p['id'] ?>"<?= $periodo_sel === (int)$p['id'] ? ' selected' : '' ?>>Período <?= $e($p['periodo']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <input type="text" id="vdc-buscar" placeholder="Filtrar por colegio, ciudad o zona..." aria-label="Filtrar">
        <button type="button" class="vdc-btn vdc-btn-nuevo" id="vdc-nuevo"><i class="bi bi-calendar-plus"></i> Agendar venta</button>
      </div>

      <div class="vdc-card">
        <div id="vdc-cal"></div>
        <div id="vdc-lista">
          <div class="vdc-l-filtros" id="vdc-l-filtros">
            <button type="button" class="activo" data-f="">Todas</button>
            <button type="button" data-f="pend">Pendientes</button>
            <button type="button" data-f="ok">Atendidas</button>
          </div>
          <div id="vdc-l-cuerpo"></div>
        </div>
      </div>
    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<!-- Detalle de una venta -->
<div class="modal fade vdc-modal" id="vdc-detalle" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header" id="vdc-det-head">
        <div><div class="modal-title" id="vdc-det-tit"></div><small id="vdc-det-sub" style="opacity:.9"></small></div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="vdc-det" id="vdc-det-body"></div>
        <div id="vdc-det-cruce" style="margin-top:12px"></div>
        <div id="vdc-det-estado"></div>
      </div>
      <div class="modal-footer" style="border:none" id="vdc-det-pie">
        <div class="vdc-btns">
          <button type="button" class="vdc-btn vdc-btn-repro" id="vdc-det-repro"><i class="bi bi-calendar2-range"></i> Reprogramar</button>
          <button type="button" class="vdc-btn vdc-btn-quitar" id="vdc-det-quitar"><i class="bi bi-x-circle"></i> Cancelar venta</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Agendar o reprogramar una venta -->
<div class="modal fade vdc-modal" id="vdc-agendar" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form class="modal-content" id="vdc-ag-form" autocomplete="off">
      <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#1e1b4b)">
        <div><div class="modal-title" id="vdc-ag-tit"><i class="bi bi-calendar-plus"></i> Agendar venta</div><small style="opacity:.9">Queda en la adopción del colegio y en su historial.</small></div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="vdc-ag-id">
        <div class="vdc-ag-col" id="vdc-ag-colinfo" style="display:none"></div>
        <div id="vdc-ag-colcampo">
        <?php if (!$colegios_agendar): ?>
          <div class="vdc-vacio" style="padding:14px">No hay colegios con canal <strong>Venta directa - Eureka</strong> y adopción abierta<?= $periodo_sel > 0 ? ' en este período' : '' ?>.</div>
        <?php else: ?>
        <div class="vdc-campo">
          <label for="vdc-ag-colegio">Colegio <span style="color:#dc2626">*</span></label>
          <select id="vdc-ag-colegio">
            <option value="">Elegir colegio...</option>
            <?php $per_act = null; foreach ($colegios_agendar as $c):
              if ($per_act !== $c['id_periodo']): if ($per_act !== null) echo '</optgroup>'; $per_act = $c['id_periodo']; ?>
                <optgroup label="Período <?= $e($c['periodo']) ?>">
              <?php endif; ?>
              <option value="<?= (int)$c['id_colegio'] ?>|<?= (int)$c['id_periodo'] ?>"><?= $e($c['colegio']) ?><?= $c['ciudad'] ? ' · ' . $e($c['ciudad']) : '' ?></option>
            <?php endforeach; ?></optgroup>
          </select>
        </div>
        <?php endif; ?>
        </div>
        <!-- Selectores propios de fecha y hora (src/vd-pickers.js, los mismos de Adopciones). .vd-jornada agrupa
             "Desde"/"Hasta" para que "Hasta" no deje elegir horas anteriores a "Desde". -->
        <div class="vdc-campos3 vd-jornada" id="vdc-ag-horario">
          <div class="vdc-campo"><label>Fecha <span style="color:#dc2626">*</span></label>
            <div class="vd-inp vd-pick" data-tipo="fecha" data-requerido><i class="bi bi-calendar3"></i><input type="text" class="vd-pick-txt" readonly placeholder="Elegir fecha"><input type="hidden" id="vdc-ag-fecha"><i class="bi bi-chevron-down vd-caret"></i></div></div>
          <div class="vdc-campo"><label>Desde <span style="color:#dc2626">*</span></label>
            <div class="vd-inp vd-pick" data-tipo="hora" data-rol="ini" data-requerido><i class="bi bi-clock"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"><input type="hidden" id="vdc-ag-ini"><i class="bi bi-chevron-down vd-caret"></i></div></div>
          <div class="vdc-campo"><label>Hasta <span style="color:#dc2626">*</span></label>
            <div class="vd-inp vd-pick" data-tipo="hora" data-rol="fin" data-requerido><i class="bi bi-clock-history"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"><input type="hidden" id="vdc-ag-fin"><i class="bi bi-chevron-down vd-caret"></i></div></div>
        </div>
        <div class="vdc-ag-dur" id="vdc-ag-dur"></div>
        <div id="vdc-ag-aviso"></div>
      </div>
      <div class="modal-footer" style="border:none">
        <button type="button" class="vdc-btn" data-dismiss="modal" style="background:#f1f5f9;color:#334155">Cerrar</button>
        <button type="submit" class="vdc-btn vdc-btn-nuevo" id="vdc-ag-guardar"><i class="bi bi-check2"></i> Agendar</button>
      </div>
    </form>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/fullcalendar/fullcalendar.min.js"></script>
<script src="src/ink-alerts.js"></script>
<script src="src/vd-pickers.js?v=20261110"></script>
<script>
(function () {
  var EVENTOS = <?= json_encode($eventos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var HOY = '<?= $hoy ?>';
  var HAY_COLEGIOS = <?= $colegios_agendar ? 'true' : 'false' ?>;
  var porId = {};
  EVENTOS.forEach(function (ev) { porId[ev.id] = ev; ev.buscar = (ev.colegio + ' ' + (ev.ciudad || '') + ' ' + (ev.zona || '')).toLowerCase(); });
  var filtro = '';
  var filtroEstado = '';
  var enLista = false;
  var DIAS = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
  var DIAS_C = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

  function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }
  function hora12(hhmm) {
    var h = parseInt(hhmm.substr(0, 2), 10), m = hhmm.substr(3, 2);
    return (h % 12 || 12) + ':' + m + (h < 12 ? ' a. m.' : ' p. m.');
  }
  function duracion(min) { var h = Math.floor(min / 60), m = min % 60; return ((h ? h + ' h' : '') + (m ? ' ' + m + ' min' : '')).trim() || '0 h'; }
  function visible(ev) { return !filtro || ev.buscar.indexOf(filtro) !== -1; }
  // Atendida = tiene formato registrado. Pendiente = no (en ámbar si la fecha ya pasó sin formato).
  function chipEstado(ev) {
    if (ev.atendida) return '<span class="vdc-estado ok"><i class="bi bi-check-circle-fill"></i> Atendida</span>';
    return '<span class="vdc-estado pend' + (ev.pasada ? ' vencida' : '') + '"><i class="bi bi-clock"></i> Pendiente</span>';
  }

  function abrirDetalle(id) {
    var ev = porId[id]; if (!ev) return;
    $('#vdc-det-head').css('background', 'linear-gradient(135deg,' + ev.color + ', #1e1b4b)');
    $('#vdc-det-tit').text(ev.colegio);
    $('#vdc-det-sub').text((ev.ciudad || '') + (ev.zona ? ' · ' + ev.zona : '') + ' · Período ' + ev.periodo);
    var d = new Date(ev.start.substr(0, 10) + 'T12:00:00');
    var fecha = DIAS[d.getDay()] + ' ' + d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear();
    var html = '<i class="bi bi-calendar-event"></i><div><strong>' + esc(fecha.charAt(0).toUpperCase() + fecha.slice(1)) + '</strong></div>'
      + '<i class="bi bi-clock"></i><div><strong>' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + '</strong><small>' + duracion(ev.minutos) + '</small></div>';
    if (ev.direccion) html += '<i class="bi bi-geo-alt"></i><div>' + esc(ev.direccion) + '</div>';
    html += '<i class="bi bi-person-lines-fill"></i><div>' + (ev.contacto
      ? '<strong>' + esc(ev.contacto) + '</strong><small><a href="tel:' + esc((ev.celular || '').replace(/\s/g, '')) + '">' + esc(ev.celular) + '</a>' + (ev.correo ? ' · <a href="mailto:' + esc(ev.correo) + '">' + esc(ev.correo) + '</a>' : '') + '</small>'
      : '<small>Sin contacto registrado</small>') + '</div>';
    $('#vdc-det-body').html(html);
    $('#vdc-det-cruce').html(ev.cruces.length ? '<span class="vdc-alerta" style="font-size:.78rem;padding:4px 8px"><i class="bi bi-people-fill"></i> Venta simultánea con: ' + esc(ev.cruces.join(', ')) + '</span>' : '');
    $('#vdc-det-estado').html(ev.atendida
      ? '<div class="vdc-det-nota ok"><i class="bi bi-check-circle-fill"></i><div><strong>Venta atendida.</strong> Tiene registrado el <a href="' + esc(ev.formato_url) + '">' + esc(ev.formato) + '</a>.</div></div>'
      : '<div class="vdc-det-nota pend"><i class="bi bi-clock"></i><div><strong>Pendiente.</strong> ' + (ev.pasada ? 'La fecha ya pasó y no tiene formato de venta directa registrado.' : 'Aún no tiene formato de venta directa registrado.') + '</div></div>');
    // Venta programada (sin formato): solo Reprogramar y Cancelar venta. Atendida: sin acciones.
    $('#vdc-det-pie').toggle(!ev.atendida);
    $('#vdc-det-repro, #vdc-det-quitar').data('id', ev.id).prop('disabled', false);
    $('#vdc-detalle').modal('show');
  }

  // ---- Lista: todas las ventas (programadas y atendidas), agrupadas por mes ----
  function pintarLista() {
    var evs = EVENTOS.filter(function (ev) {
      return visible(ev) && (!filtroEstado || (filtroEstado === 'ok') === !!ev.atendida);
    }).sort(function (a, b) { return a.start < b.start ? -1 : (a.start > b.start ? 1 : 0); });
    if (!evs.length) { $('#vdc-l-cuerpo').html('<div class="vdc-vacio"><i class="bi bi-calendar-x" style="font-size:1.8rem;display:block;margin-bottom:6px"></i>No hay ventas para mostrar.</div>'); return; }
    var porMes = {}, orden = [];
    evs.forEach(function (ev) { var k = ev.start.substr(0, 7); if (!porMes[k]) { porMes[k] = []; orden.push(k); } porMes[k].push(ev); });
    var html = '';
    orden.forEach(function (k) {
      var lista = porMes[k];
      var ok = lista.filter(function (ev) { return ev.atendida; }).length;
      html += '<div class="vdc-l-mes" data-mes="' + k + '"><strong>' + MESES[parseInt(k.substr(5, 2), 10) - 1].replace(/^./, function (c) { return c.toUpperCase(); }) + ' ' + k.substr(0, 4) + '</strong>'
        + '<small>' + lista.length + (lista.length === 1 ? ' venta' : ' ventas') + ' · ' + ok + (ok === 1 ? ' atendida' : ' atendidas') + ' · ' + (lista.length - ok) + (lista.length - ok === 1 ? ' pendiente' : ' pendientes') + '</small></div>';
      lista.forEach(function (ev) {
        var f = ev.start.substr(0, 10), d = new Date(f + 'T12:00:00');
        html += '<div class="vdc-l-fila' + (f === HOY ? ' es-hoy' : '') + '" style="--c:' + esc(ev.color) + '" data-id="' + ev.id + '">'
          + '<div class="vdc-l-fecha"><span>' + DIAS_C[d.getDay()] + '</span><b>' + d.getDate() + '</b></div>'
          + '<div class="vdc-l-hora">' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + '<small>' + duracion(ev.minutos) + (f === HOY ? ' · Hoy' : '') + '</small></div>'
          + '<div class="vdc-l-info"><strong>' + esc(ev.colegio) + '</strong>'
          + '<span>' + esc(ev.ciudad || '') + (ev.zona ? ' · ' + esc(ev.zona) : '') + ' · Período ' + esc(ev.periodo) + '</span>'
          + (ev.contacto ? '<span><i class="bi bi-person"></i> ' + esc(ev.contacto) + (ev.celular ? ' · ' + esc(ev.celular) : '') + '</span>' : '')
          + (ev.cruces.length ? '<span class="vdc-alerta"><i class="bi bi-people-fill"></i> Venta simultánea con ' + esc(ev.cruces.join(', ')) + '</span>' : '')
          + '</div>' + chipEstado(ev) + '</div>';
      });
    });
    $('#vdc-l-cuerpo').html(html);
  }
  function entrarLista() {
    enLista = true;
    $('#vdc-cal .fc-view-container, #vdc-cal .fc-left, #vdc-cal .fc-center').hide();
    $('#vdc-cal .fc-right .fc-state-active').removeClass('fc-state-active');
    $('#vdc-cal .fc-lista-button').addClass('vdc-activo');
    pintarLista();
    $('#vdc-lista').show();
  }
  function salirLista() {
    if (!enLista) return;
    enLista = false;
    $('#vdc-lista').hide();
    $('#vdc-cal .fc-view-container, #vdc-cal .fc-left, #vdc-cal .fc-center').show();
    $('#vdc-cal .fc-lista-button').removeClass('vdc-activo');
    var v = $('#vdc-cal').fullCalendar('getView').name;
    $('#vdc-cal .fc-' + v + '-button').addClass('fc-state-active');
  }

  var ancho = $(window).width();
  $('#vdc-cal').fullCalendar({
    monthNames: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
    monthNamesShort: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
    dayNames: ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'],
    dayNamesShort: ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'],
    buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', day: 'Día' },
    customButtons: { lista: { text: 'Lista', click: function () { if (!enLista) entrarLista(); } } },
    allDayText: 'Todo el día',
    noEventsMessage: 'No hay ventas en este período',
    firstDay: 1,
    header: { left: 'prev,next today', center: 'title', right: 'month,basicWeek,agendaDay,lista' },
    defaultView: 'month',
    height: 'auto',
    allDaySlot: false,
    nowIndicator: true,
    slotDuration: '00:30:00',
    slotLabelFormat: 'h:mm a',
    timeFormat: 'h:mm a',
    minTime: '06:00:00',
    maxTime: '21:00:00',
    eventLimit: 3,
    eventLimitText: 'más',
    displayEventEnd: true,
    eventOrder: 'start',
    views: {
      month: { displayEventEnd: false, timeFormat: 'h:mm a' },
      // Semana (2026-11-11): columnas por día con cada venta como tarjeta (hora, colegio, ciudad y estado), en vez de
      // la cuadrícula de horas, que dejaba las ventas angostas y difíciles de leer.
      basicWeek: { eventLimit: false, titleFormat: 'D MMM YYYY', columnHeaderHtml: function (m) {
        return '<span class="vdc-sem-dia">' + m.format('dddd') + '</span><span class="vdc-sem-num">' + m.format('D') + ' <small style="font-size:.7em;color:#94a3b8">' + m.format('MMM') + '</small></span>';
      } },
      agendaDay: { columnHeaderFormat: 'dddd D/M', titleFormat: 'dddd D [de] MMMM [de] YYYY' }
    },
    slotEventOverlap: false,
    events: function (start, end, tz, callback) { callback(EVENTOS.filter(visible)); },
    eventRender: function (ev, $el, view) {
      if (view.name === 'basicWeek') {
        $el.addClass('vdc-sem').css({ borderLeftColor: ev.color, background: '#fff', color: '#1e293b' });
        $el.find('.fc-content').html(
          '<div class="vdc-sem-hora" style="color:' + ev.color + '"><i class="bi bi-clock"></i> ' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + '</div>'
          + '<div class="vdc-sem-col">' + esc(ev.colegio) + '</div>'
          + (ev.ciudad ? '<div class="vdc-sem-sub"><i class="bi bi-geo-alt"></i> ' + esc(ev.ciudad) + '</div>' : '')
          + (ev.cruces.length ? '<div class="vdc-sem-sim"><i class="bi bi-people-fill"></i> Simultánea</div>' : '')
          + '<div>' + chipEstado(ev) + '</div>'
        );
      } else if (view.name === 'agendaDay') {
        $el.addClass('vdc-tarjeta').css({ background: ev.color + '1a', borderLeftColor: ev.color, color: '#1e293b' });
        $el.find('.fc-content').html(
          '<div class="vdc-t-hora" style="color:' + ev.color + '">' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + ' · ' + duracion(ev.minutos) + '</div>'
          + '<div class="vdc-t-col">' + esc(ev.colegio) + ' ' + chipEstado(ev) + '</div>'
          + (ev.ciudad ? '<div class="vdc-t-sub"><i class="bi bi-geo-alt"></i> ' + esc(ev.ciudad) + (ev.zona ? ' · ' + esc(ev.zona) : '') + '</div>' : '')
          + (ev.contacto ? '<div class="vdc-t-sub"><i class="bi bi-person"></i> ' + esc(ev.contacto) + (ev.celular ? ' · ' + esc(ev.celular) : '') + '</div>' : '')
          + (ev.cruces.length ? '<span class="vdc-t-sim"><i class="bi bi-people-fill"></i> Venta simultánea con ' + esc(ev.cruces.join(', ')) + '</span>' : '')
        );
      }
      $el.attr('title', ev.colegio + ' · ' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + ' · ' + (ev.atendida ? 'Atendida' : 'Pendiente') + (ev.cruces.length ? ' · Venta simultánea con ' + ev.cruces.join(', ') : ''));
      if (ev.cruces.length && view.name === 'month') $el.find('.fc-title').prepend('<i class="bi bi-people-fill" style="margin-right:4px"></i>');
    },
    viewRender: function () { salirLista(); },
    eventClick: function (ev) { abrirDetalle(ev.id); return false; },
    // Clic en un día (mes/semana) o en una hora (día): agendar una venta ahí.
    dayClick: function (fecha) {
      var conHora = fecha.hasTime();
      abrirAgendar(fecha.format('YYYY-MM-DD'), conHora ? fecha.format('HH:mm') : '', conHora ? fecha.clone().add(2, 'hours').format('HH:mm') : '');
    }
  });
  // Clic en Mes/Semana/Día estando en Lista: volver al calendario aunque la vista no cambie.
  $('#vdc-cal').on('click', '.fc-month-button, .fc-basicWeek-button, .fc-agendaDay-button', function () { setTimeout(salirLista, 0); });
  if (ancho < 768) entrarLista();

  $('#vdc-l-filtros').on('click', 'button', function () {
    $(this).addClass('activo').siblings().removeClass('activo');
    filtroEstado = $(this).data('f') || '';
    pintarLista();
  });
  $('#vdc-l-cuerpo').on('click', '.vdc-l-fila', function () { abrirDetalle($(this).data('id')); });

  // ---- Agendar / reprogramar venta (php/calendario_vd_accion.php) ----
  var reprogramando = null; // evento que se reprograma, o null al agendar
  function recargar(estado, msg) {
    var u = new URL(window.location.href);
    u.searchParams.set('ink_status', estado);
    u.searchParams.set('ink_msg', msg);
    window.location.href = u.toString();
  }
  function aviso() {
    var f = $('#vdc-ag-fecha').val(), hi = $('#vdc-ag-ini').val(), hf = $('#vdc-ag-fin').val();
    var col = reprogramando ? String(reprogramando.id_colegio) : ($('#vdc-ag-colegio').val() || '').split('|')[0];
    var html = '';
    var minutos = hi && hf ? moment(hf, 'HH:mm').diff(moment(hi, 'HH:mm'), 'minutes') : 0;
    $('#vdc-ag-dur').html(minutos > 0 ? '<i class="bi bi-hourglass-split"></i>Duración: ' + duracion(minutos) : '');
    if (hi && hf && hf <= hi) {
      html = '<span class="vdc-alerta" style="font-size:.78rem;padding:4px 8px"><i class="bi bi-exclamation-triangle-fill"></i> La hora "hasta" debe ser posterior a "desde".</span>';
    } else if (f && hi && hf) {
      var con = {};
      EVENTOS.forEach(function (ev) {
        if (reprogramando && ev.id === reprogramando.id) return;
        if (ev.start.substr(0, 10) === f && String(ev.id_colegio) !== col && ev.hi < hf && ev.hf > hi) con[ev.colegio] = true;
      });
      var nombres = Object.keys(con);
      if (nombres.length) html = '<span class="vdc-alerta" style="font-size:.78rem;padding:4px 8px;background:#fef3c7;color:#92400e"><i class="bi bi-people-fill"></i> Venta simultánea con: ' + esc(nombres.join(', ')) + '</span>';
    }
    $('#vdc-ag-aviso').html(html);
  }
  function abrirAgendar(fecha, ini, fin, ev) {
    reprogramando = ev || null;
    $('#vdc-ag-tit').html(ev ? '<i class="bi bi-calendar2-range"></i> Reprogramar venta' : '<i class="bi bi-calendar-plus"></i> Agendar venta');
    $('#vdc-ag-guardar').html(ev ? '<i class="bi bi-check2"></i> Reprogramar' : '<i class="bi bi-check2"></i> Agendar').prop('disabled', false).toggle(!!ev || HAY_COLEGIOS);
    $('#vdc-ag-colcampo').toggle(!ev);
    $('#vdc-ag-horario').toggle(!!ev || HAY_COLEGIOS);
    $('#vdc-ag-colinfo').toggle(!!ev).html(ev ? '<strong>' + esc(ev.colegio) + '</strong><small>Actual: ' + esc(ev.start.substr(8, 2) + '/' + ev.start.substr(5, 2) + '/' + ev.start.substr(0, 4)) + ' · ' + hora12(ev.hi) + ' – ' + hora12(ev.hf) + '</small>' : '');
    $('#vdc-ag-id').val(ev ? ev.id : '');
    $('#vdc-ag-fecha').val(fecha || '');
    $('#vdc-ag-ini').val(ini || '');
    $('#vdc-ag-fin').val(fin || '');
    $('#vdc-ag-form .vd-pick').removeClass('vd-falta');
    window.vdPickers.sincronizar($('#vdc-ag-form .vd-pick'));
    aviso();
    $('#vdc-agendar').modal('show');
  }
  $('#vdc-agendar').on('shown.bs.modal', function () { if (!reprogramando && !$('#vdc-ag-colegio').val()) $('#vdc-ag-colegio').trigger('focus'); });
  $('#vdc-nuevo').on('click', function () { abrirAgendar(moment().format('YYYY-MM-DD'), '', ''); });
  $('#vdc-ag-form').on('input change', 'input, select', aviso);
  $('#vdc-agendar').on('hide.bs.modal', function () { window.vdPickers.cerrar(false); });
  $('#vdc-ag-ini').on('change', function () {
    // Al elegir "desde": si "hasta" está vacío o queda antes, propone 2 horas después (sin pasar de las 11:55 p. m.).
    var $fin = $('#vdc-ag-fin');
    if (this.value && (!$fin.val() || $fin.val() <= this.value)) {
      var p = moment(this.value, 'HH:mm').add(2, 'hours');
      $fin.val(p.isSame(moment(this.value, 'HH:mm'), 'day') ? p.format('HH:mm') : '23:55');
      window.vdPickers.sincronizar($fin.closest('.vd-pick'));
      aviso();
    }
  });
  $('#vdc-ag-form').on('submit', function (e) {
    e.preventDefault();
    var partes = ($('#vdc-ag-colegio').val() || '').split('|');
    if (!reprogramando && !partes[0]) { window.inkToast('Elige el colegio.', 'warn'); return; }
    if (window.vdPickers.faltantes($(this)).length) { window.inkToast('Elige la fecha y el horario de la venta.', 'warn'); return; }
    var hi = $('#vdc-ag-ini').val(), hf = $('#vdc-ag-fin').val();
    if (hi && hf && hf <= hi) { window.inkToast('La hora "hasta" debe ser posterior a "desde".', 'warn'); return; }
    var datos = reprogramando
      ? { accion: 'reprogramar', id: reprogramando.id, fecha: $('#vdc-ag-fecha').val(), hora_ini: hi, hora_fin: hf }
      : { accion: 'agendar', id_colegio: partes[0], id_periodo: partes[1], fecha: $('#vdc-ag-fecha').val(), hora_ini: hi, hora_fin: hf };
    var verbo = reprogramando ? 'reprogramar' : 'agendar';
    var $b = $('#vdc-ag-guardar').prop('disabled', true);
    $.post('php/calendario_vd_accion.php', datos, null, 'json').done(function (r) {
      if (r && r.ok) recargar('ok', r.msg);
      else { window.inkToast((r && r.error) || 'No se pudo ' + verbo + '.', 'error'); $b.prop('disabled', false); }
    }).fail(function () { window.inkToast('No se pudo ' + verbo + '. Revisa la conexión.', 'error'); $b.prop('disabled', false); });
  });

  $('#vdc-det-repro').on('click', function () {
    var ev = porId[$(this).data('id')]; if (!ev) return;
    $('#vdc-detalle').one('hidden.bs.modal', function () { abrirAgendar(ev.start.substr(0, 10), ev.hi, ev.hf, ev); }).modal('hide');
  });
  $('#vdc-det-quitar').on('click', function () {
    var ev = porId[$(this).data('id')]; if (!ev) return;
    var $b = $(this);
    var hacer = function () {
      $b.prop('disabled', true);
      $.post('php/calendario_vd_accion.php', { accion: 'eliminar', id: ev.id }, null, 'json').done(function (r) {
        if (r && r.ok) recargar('ok', r.msg);
        else { window.inkToast((r && r.error) || 'No se pudo cancelar.', 'error'); $b.prop('disabled', false); }
      }).fail(function () { window.inkToast('No se pudo cancelar. Revisa la conexión.', 'error'); $b.prop('disabled', false); });
    };
    var fechaTxt = ev.start.substr(8, 2) + '/' + ev.start.substr(5, 2) + '/' + ev.start.substr(0, 4);
    var texto = 'Se quita del calendario y de la adopción del colegio, y queda en su historial.';
    if (window.inkConfirm) window.inkConfirm({ type: 'danger', title: '¿Cancelar la venta de ' + ev.colegio + ' del ' + fechaTxt + '?', text: texto, btnOk: 'Sí, cancelar venta' }, hacer);
    else if (confirm('¿Cancelar la venta de ' + ev.colegio + ' del ' + fechaTxt + '? ' + texto)) hacer();
  });

  $('#vdc-buscar').on('input', function () {
    filtro = $.trim(this.value).toLowerCase();
    $('#vdc-cal').fullCalendar('refetchEvents');
    if (enLista) pintarLista();
  });

})();
</script>
</body>
</html>
