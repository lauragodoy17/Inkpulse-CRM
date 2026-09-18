<?php
/**
 * /includes/informe_editorial_datos.php
 * "Informe cumplimiento" por asesor (tipo=3 + Hector Morales, id=69), con presupuesto asignado y
 * adopción valorizados y desglosados por editorial (Eureka / McGraw Hill / Otra) — para
 * php/informe_editorial_excel.php. Objetivo del reporte (dado por el usuario, 2026-09-18): que
 * cada asesor pase de un 100% en cumplimiento (adopción ÷ presupuesto asignado).
 *
 * La valorización (presupuesto/adopción en pesos) reutiliza EXACTAMENTE la misma fórmula que
 * php/valoriza_global_excel.php (precio neto × alumnos según tasa de compra, con caída a la
 * tasa/descuento normal cuando no hay una propia de distribuidor) para no inventar un cálculo
 * aparte que se desincronice del resto del CRM. La única diferencia real es que aquí se agrupa por
 * EDITORIAL del libro (libros.editorial) además de por asesor, y el resultado no es una fila por
 * colegio sino un total por asesor.
 */

// EEE (id=45) es la misma editorial que Eureka (aparece separada en el catálogo `editoriales`,
// pero de negocio son una sola) — confirmado por el usuario 2026-09-18, sus valores se suman al
// bucket "eureka" en vez de caer en "Otra". Ojo: NO se incluye "EUREKA BB" (id=48) aquí — el
// usuario solo confirmó Eureka+EEE, esa otra queda en "Otra" hasta que se confirme lo mismo.
const IDS_EDITORIAL_EUREKA_INFORME = [1, 45];
const ID_EDITORIAL_MCGRAW_INFORME = 14;

function calcular_bucket_editorial_informe($idEditorial) {
    $idEditorial = (int)$idEditorial;
    if (in_array($idEditorial, IDS_EDITORIAL_EUREKA_INFORME, true)) return 'eureka';
    if ($idEditorial === ID_EDITORIAL_MCGRAW_INFORME) return 'mcgraw';
    return 'otra';
}

/**
 * Tabla donde se guarda el "Presupuesto asignado por temporada" (columna J del Excel) que se
 * escribe a mano — antes vivía SOLO dentro del archivo descargado y se perdía en la siguiente
 * descarga; el usuario pidió 2026-09-18 que se guardara para no tener que volver a escribirlo cada
 * semana. Una fila por asesor + temporada (id CANÓNICO, ver resolver_temporada_informe_editorial()
 * — así da igual si se guarda/lee pidiendo el período de Calendario A o el de B).
 */
function asegurar_tabla_informe_editorial_presupuesto_temporada($bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS informe_editorial_presupuesto_temporada (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_periodo INT NOT NULL,
        id_usuario INT NOT NULL,
        presupuesto DECIMAL(14,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_periodo_usuario (id_periodo, id_usuario)
    )");
}

/**
 * [id_usuario => presupuesto] ya guardado para la temporada de $idPeriodo (resuelve al id
 * canónico). Vacío si nadie ha guardado nada todavía.
 */
function obtener_presupuesto_temporada_informe_editorial($bdd, $idPeriodo) {
    asegurar_tabla_informe_editorial_presupuesto_temporada($bdd);
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];
    $stmt = $bdd->prepare("SELECT id_usuario, presupuesto FROM informe_editorial_presupuesto_temporada WHERE id_periodo = ?");
    $stmt->execute([$idCanonico]);
    $porAsesor = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $porAsesor[(int)$row['id_usuario']] = (float)$row['presupuesto'];
    }
    return $porAsesor;
}

/**
 * Guarda (upsert) el presupuesto por temporada de uno o varios asesores — $valoresPorAsesor es
 * [id_usuario => presupuesto]. Un valor <= 0 BORRA la fila en vez de guardar un 0 (para poder
 * "vaciar" un asesor y que la columna J vuelva a quedar en blanco/fórmula por defecto, no en 0).
 */
function guardar_presupuesto_temporada_informe_editorial($bdd, $idPeriodo, array $valoresPorAsesor) {
    asegurar_tabla_informe_editorial_presupuesto_temporada($bdd);
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];

    $stmtUpsert = $bdd->prepare("INSERT INTO informe_editorial_presupuesto_temporada (id_periodo, id_usuario, presupuesto)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE presupuesto = VALUES(presupuesto)");
    $stmtBorrar = $bdd->prepare("DELETE FROM informe_editorial_presupuesto_temporada WHERE id_periodo = ? AND id_usuario = ?");

    foreach ($valoresPorAsesor as $idUsuario => $valor) {
        $idUsuario = (int)$idUsuario;
        $valor = (float)$valor;
        if ($valor > 0) {
            $stmtUpsert->execute([$idCanonico, $idUsuario, $valor]);
        } else {
            $stmtBorrar->execute([$idCanonico, $idUsuario]);
        }
    }
}

/**
 * Tabla donde se guarda, una vez a la semana, la "foto" de cada asesor (ver
 * guardar_snapshot_informe_editorial()) — para poder mostrar "Último informe enviado día X" y la
 * variación frente a hoy. Se crea sola la primera vez que hace falta (mismo patrón ya usado en
 * includes/periodos_fechas.php para columnas nuevas), no requiere una migración aparte.
 */
function asegurar_tabla_informe_editorial_snapshots($bdd) {
    $bdd->exec("CREATE TABLE IF NOT EXISTS informe_editorial_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        id_periodo INT NOT NULL,
        id_usuario INT NOT NULL,
        adopcion_eureka DECIMAL(14,2) NOT NULL DEFAULT 0,
        adopcion_mcgraw DECIMAL(14,2) NOT NULL DEFAULT 0,
        adopcion_otra DECIMAL(14,2) NOT NULL DEFAULT 0,
        presupuesto_eureka DECIMAL(14,2) NOT NULL DEFAULT 0,
        presupuesto_mcgraw DECIMAL(14,2) NOT NULL DEFAULT 0,
        presupuesto_otra DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fecha_periodo_usuario (fecha, id_periodo, id_usuario)
    )");
}

/**
 * Una "temporada" cruza los dos calendarios que corren EN PARALELO (mismos meses reales, distinto
 * calendario de colegio) — confirmado por el usuario 2026-09-18 con un caso concreto: lo de
 * "2026B" debe contar en el informe que se descarga como "2027". El patrón es el mismo en TODOS
 * los períodos ya cargados (verificado contra la tabla completa): un período de Calendario A con
 * año Y siempre empareja con el de Calendario B año (Y-1) — "2027"↔"2026B", "2026"↔"2025B",
 * "2025"↔"2024B" — porque el rango de fechas de A (1 oct año-1 a 31 ago año) se solapa de oct a
 * dic con el rango de B (1 may a 31 dic del mismo año).
 *
 * Devuelve ['idCanonico' => el id de Calendario A (o el mismo $idPeriodo si no hay pareja o su
 * calendario no es A/B), 'idsIncluidos' => todos los id de período cuyos datos hay que sumar,
 * 'labelCombinado' => texto para mostrar, ej. "2027 + 2026B"].
 */
function resolver_temporada_informe_editorial($bdd, $idPeriodo) {
    $idPeriodo = (int)$idPeriodo;
    $stmt = $bdd->prepare("SELECT p.periodo, c.calendario FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE p.id = ?");
    $stmt->execute([$idPeriodo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['idCanonico' => $idPeriodo, 'idsIncluidos' => [$idPeriodo], 'labelCombinado' => 'Todos'];

    $anio = (int)preg_replace('/[^0-9]/', '', $row['periodo']);

    if ($row['calendario'] === 'A' && $anio > 0) {
        $stmtB = $bdd->prepare("SELECT p.id, p.periodo FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE c.calendario = 'B' AND p.periodo = ?");
        $stmtB->execute([($anio - 1) . 'B']);
        $rowB = $stmtB->fetch(PDO::FETCH_ASSOC);
        if ($rowB) {
            return [
                'idCanonico' => $idPeriodo,
                'idsIncluidos' => [$idPeriodo, (int)$rowB['id']],
                'labelCombinado' => $row['periodo'] . ' + ' . $rowB['periodo'],
            ];
        }
    } elseif ($row['calendario'] === 'B' && $anio > 0) {
        $stmtA = $bdd->prepare("SELECT p.id, p.periodo FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE c.calendario = 'A' AND p.periodo = ?");
        $stmtA->execute([(string)($anio + 1)]);
        $rowA = $stmtA->fetch(PDO::FETCH_ASSOC);
        if ($rowA) {
            return [
                'idCanonico' => (int)$rowA['id'],
                'idsIncluidos' => [(int)$rowA['id'], $idPeriodo],
                'labelCombinado' => $rowA['periodo'] . ' + ' . $row['periodo'],
            ];
        }
    }

    return ['idCanonico' => $idPeriodo, 'idsIncluidos' => [$idPeriodo], 'labelCombinado' => $row['periodo']];
}

/**
 * Presupuesto/adopción valorizados por asesor y editorial de UN SOLO período (sin combinar
 * temporada) — usado por obtener_datos_informe_editorial() una vez por cada período de la
 * temporada. Devuelve ['eureka','mcgraw','otra' => id_asesor => monto] separado en
 * 'presupuestoPorAsesor'/'adopcionPorAsesor'.
 */
function calcular_datos_editorial_un_periodo($bdd, $idPeriodo) {
    $idPeriodo = (int)$idPeriodo;

    $stmtPer = $bdd->prepare("SELECT id_calendario FROM periodos WHERE id = ?");
    $stmtPer->execute([$idPeriodo]);
    $idCalendario = (int)$stmtPer->fetchColumn();

    // Dueño del colegio para este informe: quien tenía la zona (cod_zona) de la línea de
    // presupuesto EN ESE PERIODO (no la zona actual del colegio, que puede haber cambiado de dueño
    // desde entonces) — mismo criterio "dueño de zona" que php/valoriza_global_excel.php. A
    // diferencia de ese archivo, aquí NO se exige owner.act=1: este informe es específicamente de
    // asesores tipo=3 (+ Hector Morales), y uno que ya no está activo pero tuvo resultados en el
    // período (ej. Yolanda Montenegro, ver imagen de referencia del usuario) debe seguir
    // apareciendo con su cumplimiento histórico, no desaparecer del reporte.
    $ownerJoin = "LEFT JOIN (
            SELECT id_colegio, id_periodo, MIN(cod_zona) as cod_zona
            FROM presupuestos
            WHERE cod_zona <> ''
            GROUP BY id_colegio, id_periodo
            HAVING COUNT(DISTINCT cod_zona) = 1
        ) pz ON pz.id_colegio = c.id AND pz.id_periodo = p.id_periodo
        LEFT JOIN usuarios owner ON owner.cod_zona = COALESCE(pz.cod_zona, c.cod_zona) AND owner.cod_zona <> ''
             AND (owner.tipo = 3 OR owner.id = 69)";

    $stmtColegios = $bdd->prepare("SELECT c.id, owner.id as id_asesor
        FROM colegios c
        JOIN presupuestos p ON c.id = p.id_colegio
        $ownerJoin
        WHERE (p.pre_definido=1 OR p.definido=1) AND p.id_periodo = ? AND c.id_calendario = ?
              AND owner.id IS NOT NULL
        GROUP BY c.id, owner.id");
    $stmtColegios->execute([$idPeriodo, $idCalendario]);
    $colegios = $stmtColegios->fetchAll(PDO::FETCH_ASSOC);

    $idColegioAsesor = []; // id_colegio => id_asesor (un colegio pertenece a un solo asesor acá)
    foreach ($colegios as $c) $idColegioAsesor[(int)$c['id']] = (int)$c['id_asesor'];
    $idsColegio = array_values(array_unique(array_keys($idColegioAsesor)));

    $vacio = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0];
    $presupuestoPorAsesor = []; // id_asesor => ['eureka'=>, 'mcgraw'=>, 'otra'=>]
    $adopcionPorAsesor = [];

    if (!empty($idsColegio)) {
        $ph = implode(',', array_fill(0, count($idsColegio), '?'));

        // Presupuesto/adopción por línea, con el editorial del libro — mismo filtro de negocio que
        // php/valoriza_global_excel.php ("valorización libro a libro": excluye probabilidad=3
        // "Perdida" y líneas sin ninguna tasa de compra asignada, que no representan una venta
        // proyectable).
        $stmtPres = $bdd->prepare("SELECT p.id_colegio, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d,
                                           p.precio, p.pre_definido, p.definido, p.cod_area, p.uni_vr, l.id_grado, l.editorial
                                    FROM presupuestos p JOIN libros l ON p.id_libro = l.id
                                    WHERE p.id_colegio IN ($ph) AND (p.pre_definido=1 OR p.definido=1) AND p.id_periodo = ?
                                          AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)");
        $stmtPres->execute(array_merge($idsColegio, [$idPeriodo]));
        $lineasPorColegio = [];
        foreach ($stmtPres->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lineasPorColegio[(int)$row['id_colegio']][] = $row;
        }

        // areas_objetivas: mismo criterio que valoriza_global_excel.php (match por código de área,
        // descartando ambigüedad en vez de adivinar).
        $stmtAo = $bdd->prepare("SELECT id_colegio, codigo, MAX(id_grado_otro) as id_grado_otro
                                  FROM areas_objetivas
                                  WHERE id_colegio IN ($ph) AND id_periodo = ? AND codigo <> ''
                                  GROUP BY id_colegio, codigo
                                  HAVING COUNT(*) = 1");
        $stmtAo->execute(array_merge($idsColegio, [$idPeriodo]));
        $aoMap = [];
        foreach ($stmtAo->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aoMap[(int)$row['id_colegio']][$row['codigo']] = $row['id_grado_otro'];
        }

        $stmtGp = $bdd->prepare("SELECT id_colegio, id_grado, SUM(alumnos) as alumnos
                                  FROM grados_paralelos WHERE id_colegio IN ($ph) AND id_periodo = ? AND alumnos > 0
                                  GROUP BY id_colegio, id_grado");
        $stmtGp->execute(array_merge($idsColegio, [$idPeriodo]));
        $gpMap = [];
        foreach ($stmtGp->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gpMap[(int)$row['id_colegio']][(int)$row['id_grado']] = (int)$row['alumnos'];
        }

        foreach ($lineasPorColegio as $idColegio => $lineas) {
            $idAsesor = $idColegioAsesor[$idColegio] ?? null;
            if (!$idAsesor) continue;
            if (!isset($presupuestoPorAsesor[$idAsesor])) $presupuestoPorAsesor[$idAsesor] = $vacio;
            if (!isset($adopcionPorAsesor[$idAsesor])) $adopcionPorAsesor[$idAsesor] = $vacio;

            foreach ($lineas as $l) {
                $cod_area = trim($l['cod_area']);
                $grado_lookup = ($cod_area !== '' && isset($aoMap[$idColegio][$cod_area]))
                    ? $aoMap[$idColegio][$cod_area] : $l['id_grado'];
                $alumnos = $gpMap[$idColegio][$grado_lookup] ?? 0;
                $bucket = calcular_bucket_editorial_informe($l['editorial']);

                if ($l['pre_definido'] == 1) {
                    // round() antes de floor(): mismo motivo que valoriza_global_excel.php
                    // (tasa_compra es DECIMAL pero PDO lo entrega como string/float binario
                    // impreciso).
                    $alumnos_tasa = floor(round($alumnos * $l['tasa_compra'], 6));
                    $precio_neto  = $l['precio'] - ($l['precio'] * $l['descuento']);
                    $presupuestoPorAsesor[$idAsesor][$bucket] += $precio_neto * $alumnos_tasa;
                }
                if ($l['definido'] != 0) {
                    if ($l['tasa_compra_d'] == 0.00) {
                        $alumnos_tasa_d = floor(round($alumnos * $l['tasa_compra'], 6));
                        $precio_neto_d  = $l['precio'] - ($l['precio'] * $l['descuento']);
                    } else {
                        $alumnos_tasa_d = floor(round($alumnos * $l['tasa_compra_d'], 6));
                        $precio_neto_d  = $l['precio'] - ($l['precio'] * $l['descuento_d']);
                    }
                    $adopcionPorAsesor[$idAsesor][$bucket] += $precio_neto_d * $alumnos_tasa_d;
                }
            }
        }
    }

    return ['presupuestoPorAsesor' => $presupuestoPorAsesor, 'adopcionPorAsesor' => $adopcionPorAsesor];
}

/**
 * Núcleo público: presupuesto asignado y adopción valorizados, por asesor y por editorial, para
 * la TEMPORADA de un período (el período pedido + su pareja de calendario, ver
 * resolver_temporada_informe_editorial() — ej. pedir "2027" también suma "2026B", porque corren en
 * paralelo). Devuelve ['asesores' => [ ['id_usuario', 'nombre', 'activo',
 * 'presupuesto' => ['eureka','mcgraw','otra','total'], 'adopcion' => [...] ], ... ] ].
 * Solo se listan asesores que YA tienen algo registrado (presupuesto o adopción) en la temporada —
 * sin roster ni relleno con $0 (aclarado por el usuario 2026-09-18: "solo los que tengan algo
 * registrado... debe verse reflejado").
 */
function obtener_datos_informe_editorial($bdd, $idPeriodo) {
    $temporada = resolver_temporada_informe_editorial($bdd, $idPeriodo);

    $vacio = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0];
    $presupuestoPorAsesor = [];
    $adopcionPorAsesor = [];
    foreach ($temporada['idsIncluidos'] as $idPeriodoParte) {
        $parcial = calcular_datos_editorial_un_periodo($bdd, $idPeriodoParte);
        foreach ($parcial['presupuestoPorAsesor'] as $idAsesor => $montos) {
            if (!isset($presupuestoPorAsesor[$idAsesor])) $presupuestoPorAsesor[$idAsesor] = $vacio;
            foreach (['eureka', 'mcgraw', 'otra'] as $bucket) $presupuestoPorAsesor[$idAsesor][$bucket] += $montos[$bucket];
        }
        foreach ($parcial['adopcionPorAsesor'] as $idAsesor => $montos) {
            if (!isset($adopcionPorAsesor[$idAsesor])) $adopcionPorAsesor[$idAsesor] = $vacio;
            foreach (['eureka', 'mcgraw', 'otra'] as $bucket) $adopcionPorAsesor[$idAsesor][$bucket] += $montos[$bucket];
        }
    }

    $idsAsesor = array_values(array_unique(array_merge(array_keys($presupuestoPorAsesor), array_keys($adopcionPorAsesor))));
    if (empty($idsAsesor)) return ['asesores' => []];
    $phA = implode(',', array_fill(0, count($idsAsesor), '?'));
    $stmtNom = $bdd->prepare("SELECT id, CONCAT(TRIM(nombres),' ',TRIM(apellidos)) as nombre, act FROM usuarios WHERE id IN ($phA)");
    $stmtNom->execute($idsAsesor);
    $nombresPorId = [];
    foreach ($stmtNom->fetchAll(PDO::FETCH_ASSOC) as $row) $nombresPorId[(int)$row['id']] = $row;

    $asesores = [];
    foreach ($idsAsesor as $id) {
        $pres = ($presupuestoPorAsesor[$id] ?? $vacio);
        $adop = ($adopcionPorAsesor[$id] ?? $vacio);
        $pres['total'] = $pres['eureka'] + $pres['mcgraw'] + $pres['otra'];
        $adop['total'] = $adop['eureka'] + $adop['mcgraw'] + $adop['otra'];
        $asesores[] = [
            'id_usuario' => $id,
            'nombre' => $nombresPorId[$id]['nombre'] ?? ('Usuario #' . $id),
            'activo' => (bool)($nombresPorId[$id]['act'] ?? 1),
            'presupuesto' => $pres,
            'adopcion' => $adop,
        ];
    }
    usort($asesores, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));

    return ['asesores' => $asesores];
}

/**
 * % de cumplimiento (adopción ÷ presupuesto) o null si no hay presupuesto asignado para ese
 * bucket — se muestra como "Sin Ppto Asignado" en la vista (ver php/informe_editorial_excel.php).
 */
function cumplimiento_informe_editorial($adopcion, $presupuesto) {
    if ((float)$presupuesto <= 0) return null;
    return ((float)$adopcion / (float)$presupuesto) * 100;
}

/**
 * Guarda la foto de HOY para cada asesor — pensado para correr una vez POR SEMANA, los viernes
 * (ver php/informe_editorial_snapshot_cron.php, que ya trae ese control; aclarado por el usuario
 * 2026-09-18: el informe se saca todos los viernes, así que "el último informe" debe ser el del
 * viernes pasado, no el del día anterior). Upsert por (fecha, período, usuario): si se corre más de
 * una vez el mismo día, sobreescribe en vez de duplicar. Devuelve cuántos asesores quedaron
 * guardados.
 */
function guardar_snapshot_informe_editorial($bdd, $idPeriodo) {
    asegurar_tabla_informe_editorial_snapshots($bdd);
    // Se guarda bajo el id CANÓNICO de la temporada (el de Calendario A, ver
    // resolver_temporada_informe_editorial()) para que da igual si el cron corrió viendo "vigente"
    // el período de Calendario A o el de B — ambos son la misma temporada y deben compartir el
    // mismo snapshot, no uno cada uno.
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];
    $datos = obtener_datos_informe_editorial($bdd, $idPeriodo);
    if (empty($datos['asesores'])) return 0;

    $hoy = date('Y-m-d');
    $stmt = $bdd->prepare("INSERT INTO informe_editorial_snapshots
            (fecha, id_periodo, id_usuario, adopcion_eureka, adopcion_mcgraw, adopcion_otra,
             presupuesto_eureka, presupuesto_mcgraw, presupuesto_otra)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            adopcion_eureka = VALUES(adopcion_eureka), adopcion_mcgraw = VALUES(adopcion_mcgraw), adopcion_otra = VALUES(adopcion_otra),
            presupuesto_eureka = VALUES(presupuesto_eureka), presupuesto_mcgraw = VALUES(presupuesto_mcgraw), presupuesto_otra = VALUES(presupuesto_otra)");
    foreach ($datos['asesores'] as $a) {
        $stmt->execute([
            $hoy, $idCanonico, $a['id_usuario'],
            $a['adopcion']['eureka'], $a['adopcion']['mcgraw'], $a['adopcion']['otra'],
            $a['presupuesto']['eureka'], $a['presupuesto']['mcgraw'], $a['presupuesto']['otra'],
        ]);
    }
    return count($datos['asesores']);
}

/**
 * Último snapshot guardado ANTES de hoy, por asesor, para la columna "Último informe enviado día
 * X" y la "Variación frente al último". Todos los asesores comparten la MISMA fecha (la del
 * snapshot más reciente que exista para la temporada), igual que en la imagen de referencia. Como
 * el cron solo guarda los viernes (ver php/informe_editorial_snapshot_cron.php), "el más reciente
 * antes de hoy" es en la práctica el viernes anterior — no hace falta calcular "hace 7/8 días" a
 * mano, ya sale solo de que el cron nunca guarda entre semana. Usa el id CANÓNICO de la temporada
 * (ver resolver_temporada_informe_editorial()), igual que guardar_snapshot_informe_editorial(),
 * para encontrar el snapshot sin importar si $idPeriodo es el de Calendario A o el de B.
 * Devuelve ['fecha' => 'YYYY-MM-DD'|null, 'porAsesor' => [id_usuario => ['cumplimiento' => float|null]]].
 */
function obtener_ultimo_snapshot_informe_editorial($bdd, $idPeriodo) {
    asegurar_tabla_informe_editorial_snapshots($bdd);
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];
    $stmtFecha = $bdd->prepare("SELECT MAX(fecha) FROM informe_editorial_snapshots WHERE id_periodo = ? AND fecha < CURDATE()");
    $stmtFecha->execute([$idCanonico]);
    $fecha = $stmtFecha->fetchColumn();
    if (!$fecha) return ['fecha' => null, 'porAsesor' => []];

    $stmt = $bdd->prepare("SELECT id_usuario, adopcion_eureka, adopcion_mcgraw, adopcion_otra,
                                   presupuesto_eureka, presupuesto_mcgraw, presupuesto_otra
                            FROM informe_editorial_snapshots WHERE id_periodo = ? AND fecha = ?");
    $stmt->execute([$idCanonico, $fecha]);
    $porAsesor = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adopTotal = $row['adopcion_eureka'] + $row['adopcion_mcgraw'] + $row['adopcion_otra'];
        $presTotal = $row['presupuesto_eureka'] + $row['presupuesto_mcgraw'] + $row['presupuesto_otra'];
        $porAsesor[(int)$row['id_usuario']] = [
            'cumplimiento' => $presTotal > 0 ? ($adopTotal / $presTotal) * 100 : null,
        ];
    }
    return ['fecha' => $fecha, 'porAsesor' => $porAsesor];
}
