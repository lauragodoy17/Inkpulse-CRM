<?php
/**
 * /includes/periodos_fechas.php
 * Calcula y persiste el rango de fechas (fecha_inicio/fecha_fin) de un
 * período según su calendario, para que los reportes lean el rango desde
 * `periodos` en vez de recalcularlo cada uno por su cuenta.
 *
 * Reglas de negocio (dadas por el usuario, 2026-09-10):
 * - Calendario A: el "periodo" es solo el año final (ej. "2027"). El rango va
 *   del 1 de octubre del año ANTERIOR al 31 de agosto de ese mismo año
 *   (ej. "2027" -> 2026-10-01 a 2027-08-31).
 * - Calendario B: el "periodo" es el año + "B" (ej. "2026B"). El rango va del
 *   1 de mayo al 31 de diciembre de ese mismo año
 *   (ej. "2026B" -> 2026-05-01 a 2026-12-31).
 * Cualquier otro calendario (ej. "Institucional", act=0) no tiene regla
 * definida todavía: devuelve [null, null].
 */
function calcular_rango_periodo($calendarioLetra, $periodo) {
    $anio = (int) preg_replace('/[^0-9]/', '', (string)$periodo);
    if ($anio <= 0) return [null, null];

    if ($calendarioLetra === 'A') {
        return [($anio - 1) . '-10-01', $anio . '-08-31'];
    }
    if ($calendarioLetra === 'B') {
        return [$anio . '-05-01', $anio . '-12-31'];
    }
    return [null, null];
}

/**
 * Agrega fecha_inicio/fecha_fin a `periodos` si no existen todavía, y
 * calcula esos valores para cualquier fila que los tenga en NULL. Se llama
 * de forma oportunista (barata: sin filas pendientes es un SELECT vacío)
 * desde donde se crean/editan/listan períodos — no hace falta una migración
 * aparte para instalaciones que ya tenían la tabla `periodos` sin estas
 * columnas.
 */
function asegurar_fechas_periodos($bdd) {
    try {
        $bdd->exec("ALTER TABLE periodos ADD COLUMN fecha_inicio DATE NULL AFTER periodo");
    } catch (Exception $e) {}
    try {
        $bdd->exec("ALTER TABLE periodos ADD COLUMN fecha_fin DATE NULL AFTER fecha_inicio");
    } catch (Exception $e) {}

    $pendientes = $bdd->query("SELECT p.id, p.periodo, c.calendario
                                FROM periodos p JOIN calendarios c ON c.id = p.id_calendario
                                WHERE p.fecha_inicio IS NULL OR p.fecha_fin IS NULL")
                       ->fetchAll(PDO::FETCH_ASSOC);
    if (empty($pendientes)) return;

    $upd = $bdd->prepare("UPDATE periodos SET fecha_inicio = ?, fecha_fin = ? WHERE id = ?");
    foreach ($pendientes as $p) {
        [$inicio, $fin] = calcular_rango_periodo($p['calendario'], $p['periodo']);
        if ($inicio && $fin) $upd->execute([$inicio, $fin, $p['id']]);
    }
}

/**
 * Períodos disponibles (con su rango de fechas) más cuál está "activo" hoy (CURDATE() dentro de
 * fecha_inicio/fecha_fin). Si ninguno cubre la fecha actual (huecos entre calendarios, o períodos
 * sin fecha calculada todavía), cae al de id más alto. Idéntica a
 * includes/backorders_datos.php::obtener_periodos_backorders() — se duplica aquí (en vez de
 * requerir ese archivo, que arrastra toda la cadena de World Office de includes/stock_bajo.php)
 * para que cualquier página que solo necesite "la lista de períodos" no cargue dependencias que no
 * usa.
 */
function obtener_periodo_activo($bdd) {
    $periodos = $bdd->query("SELECT p.id, p.periodo, p.fecha_inicio, p.fecha_fin, c.calendario
                              FROM periodos p JOIN calendarios c ON c.id = p.id_calendario
                              ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $activo = null;
    foreach ($periodos as $p) {
        if ($p['fecha_inicio'] && $p['fecha_fin'] && $p['fecha_inicio'] <= date('Y-m-d') && date('Y-m-d') <= $p['fecha_fin']) {
            $activo = (int)$p['id'];
            break;
        }
    }
    if ($activo === null && !empty($periodos)) $activo = (int)$periodos[0]['id'];
    return ['periodos' => $periodos, 'periodoActivo' => $activo];
}

/**
 * Rango de fechas [fecha_inicio, fecha_fin] de un período, ya calculado y guardado en
 * `periodos` (ver calcular_rango_periodo()/asegurar_fechas_periodos()) — para filtrar por
 * calendario en vez de por el id_periodo literal de cada registro (ej. los buscadores de
 * Backorders, que pedido por el usuario 2026-09-18 deben mostrar solo lo pendiente DENTRO del
 * rango de fechas del período seleccionado). Devuelve [null, null] si el período no existe o
 * su calendario no tiene regla de rango definida (ver calcular_rango_periodo()).
 */
function obtener_rango_fechas_periodo($bdd, $idPeriodo) {
    $idPeriodo = (int)$idPeriodo;
    if ($idPeriodo <= 0) return [null, null];
    $stmt = $bdd->prepare("SELECT fecha_inicio, fecha_fin FROM periodos WHERE id = ?");
    $stmt->execute([$idPeriodo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? [$row['fecha_inicio'], $row['fecha_fin']] : [null, null];
}
