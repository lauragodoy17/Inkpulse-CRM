<?php
/**
 * /php/informe_editorial_snapshot_cron.php
 * Guarda la "foto" de la semana para el Informe Cumplimiento por editorial (ver
 * includes/informe_editorial_datos.php) — la columna "Último informe enviado día X" del Excel se
 * llena con lo que este script guarde. El informe se saca todos los viernes (aclarado por el
 * usuario 2026-09-18: "para... alimentar la columna... Último informe... es el TOTAL CUMPLIMIENTO
 * de hace 8 días atrás" — en español "de hace ocho días" es la forma común de decir "hace una
 * semana", es decir, el viernes anterior), así que este script SOLO guarda los viernes — no todos
 * los días — para que "el snapshot más reciente antes de hoy" (ver
 * obtener_ultimo_snapshot_informe_editorial()) sea siempre el del viernes pasado, sin tener que
 * calcular fechas exactas a mano.
 *
 * Este proyecto no tiene un mecanismo de cron propio — hay que programarlo a mano en el
 * Programador de tareas de Windows: acción "Iniciar un programa",
 *   programa: C:\xampp\php\php.exe
 *   argumentos: "C:\Users\USUARIO\Desktop\inkpulse\php\informe_editorial_snapshot_cron.php"
 *   desencadenador: SEMANAL, todos los viernes a las 9:00 am.
 * Se ejecuta por línea de comandos (sin sesión de navegador), así que NO pasa por php/aut.php.
 *
 * Por seguridad (si alguien deja el desencadenador de Windows en "diario" por error, o lo corre a
 * mano un día cualquiera) el script mismo revisa que hoy sea viernes y no hace nada si no lo es —
 * salvo que se le pase el argumento "forzar" (pensado para pruebas o para recuperar manualmente un
 * viernes en que el Programador de tareas no corrió, ej. por un apagón o feriado).
 */

chdir(__DIR__);
require_once("../conexion/bdd.php");
require_once("../includes/periodos_fechas.php");
require_once("../includes/informe_editorial_datos.php");

$forzado = in_array('forzar', $argv ?? [], true);
$esViernes = ((int)date('N')) === 5; // ISO-8601: 1=lunes .. 7=domingo
if (!$esViernes && !$forzado) {
    echo date('Y-m-d H:i:s') . " — Hoy no es viernes, no se guarda nada (el informe se saca semanalmente)."
        . " Para forzarlo de todos modos: php informe_editorial_snapshot_cron.php forzar\n";
    exit;
}

asegurar_fechas_periodos($bdd);

// Se guarda la foto de TODOS los períodos que hoy están vigentes según su calendario (fecha_inicio
// a fecha_fin) — puede ser más de uno si Calendario A y B se solapan, y ninguno si hay un hueco
// entre calendarios (ver includes/periodos_fechas.php). guardar_snapshot_informe_editorial() ya
// combina cada período con su pareja de temporada (ver resolver_temporada_informe_editorial()) y
// guarda bajo el id canónico, así que llamarla una vez por cada vigente no duplica nada aunque
// varios resuelvan a la misma temporada.
$periodosVigentes = $bdd->query(
    "SELECT id, periodo FROM periodos WHERE fecha_inicio IS NOT NULL AND fecha_fin IS NOT NULL
     AND fecha_inicio <= CURDATE() AND CURDATE() <= fecha_fin"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($periodosVigentes)) {
    echo date('Y-m-d H:i:s') . " — Ningún período vigente hoy, nada que guardar.\n";
    exit;
}

foreach ($periodosVigentes as $p) {
    $n = guardar_snapshot_informe_editorial($bdd, (int)$p['id']);
    echo date('Y-m-d H:i:s') . " — Período {$p['periodo']} (id {$p['id']}): {$n} asesor(es) guardado(s).\n";
}
