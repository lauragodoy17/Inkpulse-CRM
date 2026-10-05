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
// SM (id=2) también se suma a Eureka (pedido por el usuario 2026-10-05: "Eureka = Eureka + SM"),
// así que deja de salir en el desglose de "Otra".
// Reclasificación pedida por el usuario 2026-10-05, tras revisar qué había en cada categoría:
// - EUREKA BB (id=48, serie Brady) suma a Eureka.
// - EDIARTE (id=17, packs "All Sorts", etiqueta "INGLES - MGH") y la editorial "OTRA" (id=31,
//   New Interactions / Ellevate / Discovering Our Past) suman a McGraw Hill, salvo los libros de
//   Pearson que estaban en "OTRA" (ver RECLASIFICACION_ISBN_INFORME_EDITORIAL).
// - Algunos libros sin editorial ("NINGUNA", id=0) se reasignan por ISBN (ver abajo) y los libros
//   propios del colegio San José de la Anunciación se excluyen (ver
//   filtro_libros_excluidos_informe_sql()).
// Solo cambia cómo se agrupan en ESTE informe; el catálogo `libros` no se toca.
const IDS_EDITORIAL_EUREKA_INFORME = [1, 45, 2, 48];
const IDS_EDITORIAL_MCGRAW_INFORME = [14, 17, 31];

// "PL" no existe en `editoriales`: id ficticio solo para el desglose de este informe (cae en "Otra").
const ID_EDITORIAL_PL_INFORME = -1;

// ISBN => editorial con la que cuenta en el informe. Solo se aplica a libros con editorial
// NINGUNA (0) u OTRA (31) en el catálogo — pedido por el usuario 2026-10-05.
const RECLASIFICACION_ISBN_INFORME_EDITORIAL = [
    // OTRA → PEARSON: Exploring Science International 7 y 8
    '9781292294100' => 11,
    '9781292294148' => 11,
    // NINGUNA → PL: El tucán y el pájaro carpintero, La danza de la libertad, Entre lo bello y lo
    // profundo, Amorfinos y otros cantos divinos, Mi hermana Juana y las ballenas del fin del mundo,
    // De taquito antología de fútbol
    '9789582010867' => ID_EDITORIAL_PL_INFORME,
    '9789585675315' => ID_EDITORIAL_PL_INFORME,
    '9789978483381' => ID_EDITORIAL_PL_INFORME,
    '9789978485491' => ID_EDITORIAL_PL_INFORME,
    '9789584261663' => ID_EDITORIAL_PL_INFORME,
    '9786287591295' => ID_EDITORIAL_PL_INFORME,
    // NINGUNA → PLANETA: Mi primer Quijote, El perfume, Clásicos en escena, Las aventuras de Ráquira
    '9789584290335' => 20,
    '9789584232083' => 20,
    '9789584241108' => 20,
    '9786280002798' => 20,
    // NINGUNA → VICENS VIVES: Prisma K comprensión lectora (Prisma G-J ya están en Vicens Vives)
    '9789588421889' => 37,
    // NINGUNA → ILS: Trazos y letras / Lógica y números preescolar 1, 2 y 3
    '9789585325142' => 34,
    '9789585325128' => 34,
    '9789585325159' => 34,
    '9789585325173' => 34,
    '9786076960233' => 34,
    '9786076960240' => 34,
    // NINGUNA → ILS: Ciudadanía Digital Bachillerato 1 (mismo rango de ISBN que Desempeños.com Plus y
    // Robótica Genibot) — confirmado por el usuario 2026-10-05
    '9789978487969' => 34,
];

/** Editorial con la que cuenta un libro en este informe (aplica RECLASIFICACION_ISBN_INFORME_EDITORIAL). */
function editorial_efectiva_informe($idEditorial, $isbn) {
    $idEditorial = (int)$idEditorial;
    if (in_array($idEditorial, [0, 31], true)) {
        $isbn = preg_replace('/[^0-9X]/i', '', (string)$isbn);
        if (isset(RECLASIFICACION_ISBN_INFORME_EDITORIAL[$isbn])) return RECLASIFICACION_ISBN_INFORME_EDITORIAL[$isbn];
    }
    return $idEditorial;
}

/**
 * Condición SQL (alias `l` = libros) que excluye del informe los ~40 libros propios del colegio San
 * José de la Anunciación (sin editorial ni ISBN): no son de los asesores de Eureka y no deben contar
 * en venta real, presupuesto ni adopciones — pedido por el usuario 2026-10-05.
 */
function filtro_libros_excluidos_informe_sql() {
    return " AND NOT (l.editorial = 0 AND l.libro LIKE '%SAN JOSE DE LA ANUNCIACION%')";
}


// Presupuesto OFICIAL de la gerencia por asesor (Eureka / McGraw Hill), tomado de la imagen
// "presupuesto.jpeg" que pasó el usuario 2026-10-05 ("PPTO 25-26"); va en el informe de
// 2027 + 2026B ("eso tiene que aparecer sí o sí"), comparado con el bloque PRESUPUESTO ASIGNADO.
// id_usuario null = fila sin asesor en el CRM ("Dirección comercial", confirmado por el usuario).
const PRESUPUESTO_OFICIAL_PPTO_25_26_INFORME = [
    ['id_usuario' => 11,   'nombre' => 'MANUEL CORREA',        'mcgraw' => 14746689,  'eureka' => 665253312],
    ['id_usuario' => 16,   'nombre' => 'MARIO HERRERA',        'mcgraw' => 136053900, 'eureka' => 423946100],
    ['id_usuario' => 55,   'nombre' => 'BERNARDO OSORIO',      'mcgraw' => 38988905,  'eureka' => 411011095],
    ['id_usuario' => 69,   'nombre' => 'HECTOR MORALES',       'mcgraw' => 35000000,  'eureka' => 415000000],
    ['id_usuario' => 72,   'nombre' => 'JAIRO RICO',           'mcgraw' => 53972625,  'eureka' => 326027375],
    ['id_usuario' => 56,   'nombre' => 'SARA BERRIO',          'mcgraw' => 35000000,  'eureka' => 315000000],
    ['id_usuario' => 51,   'nombre' => 'WILSON SUAREZ',        'mcgraw' => 72681875,  'eureka' => 327318125],
    ['id_usuario' => 49,   'nombre' => 'WILSON VARGAS',        'mcgraw' => 218656053, 'eureka' => 581343947],
    ['id_usuario' => 13,   'nombre' => 'YIMMI FORERO',         'mcgraw' => 83267438,  'eureka' => 496732563],
    ['id_usuario' => 14,   'nombre' => 'YOLANDA MONTENEGRO',   'mcgraw' => 76332375,  'eureka' => 573667625],
    ['id_usuario' => null, 'nombre' => 'DIRECCION COMERCIAL',  'mcgraw' => 109158060, 'eureka' => 390841940],
];
// Clave = período de Calendario A (id canónico de la temporada). Para una temporada nueva, agregar
// otra entrada con su lista.
const PRESUPUESTO_OFICIAL_INFORME_EDITORIAL = [
    '2027' => PRESUPUESTO_OFICIAL_PPTO_25_26_INFORME,
];

/**
 * Presupuesto oficial (ver PRESUPUESTO_OFICIAL_INFORME_EDITORIAL) de la temporada de $idPeriodo, o
 * [] si no hay uno cargado para esa temporada.
 */
function obtener_presupuesto_oficial_informe_editorial($bdd, $idPeriodo) {
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];
    $stmt = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
    $stmt->execute([$idCanonico]);
    return PRESUPUESTO_OFICIAL_INFORME_EDITORIAL[(string)$stmt->fetchColumn()] ?? [];
}

function calcular_bucket_editorial_informe($idEditorial) {
    $idEditorial = (int)$idEditorial;
    if (in_array($idEditorial, IDS_EDITORIAL_EUREKA_INFORME, true)) return 'eureka';
    if (in_array($idEditorial, IDS_EDITORIAL_MCGRAW_INFORME, true)) return 'mcgraw';
    return 'otra';
}

// Asesores cuyo informe se limita a una lista fija de colegios (por DANE), sin importar qué otros
// colegios tenga su zona — pedido por el usuario 2026-10-02: Mariana Castañeda (id=57) solo cuenta
// Colegio Espiritu Santo Marianistas Girardot, Gimnasio Academico Regional, Liceo Nuestra Señora De
// Torcoroma y Gimnasio Superior Nuevos Andes. Aplica a presupuesto, adopción y venta real.
const COLEGIOS_RESTRINGIDOS_INFORME_EDITORIAL = [
    57 => ['325307000501', '311769004268', '311001033439', '311001109168'],
];

/**
 * Condición SQL (para un WHERE que ya tenga los alias `owner` y `c`) que descarta los colegios de
 * los asesores restringidos que no estén en su lista.
 */
function filtro_colegios_restringidos_informe_sql() {
    $condiciones = [];
    foreach (COLEGIOS_RESTRINGIDOS_INFORME_EDITORIAL as $idAsesor => $danes) {
        $lista = implode(',', array_map(fn($d) => "'" . preg_replace('/[^0-9]/', '', $d) . "'", $danes));
        $condiciones[] = "(owner.id = " . (int)$idAsesor . " AND c.dane NOT IN ($lista))";
    }
    return $condiciones ? ' AND NOT (' . implode(' OR ', $condiciones) . ')' : '';
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
    // "Presupuesto asignado por temporada" vigente al tomar la foto (NULL si no había). El
    // TOTAL CUMPLIMIENTO del Excel se calcula contra ese valor cuando existe, así que la foto
    // tiene que usar el mismo denominador para que "Último informe" sea comparable (reportado
    // 2026-09-25: Bernardo salía 10,69% en la foto contra 128% en el cumplimiento actual).
    try { $bdd->exec("ALTER TABLE informe_editorial_snapshots ADD COLUMN presupuesto_temporada DECIMAL(14,2) NULL DEFAULT NULL"); } catch (Exception $e) {}
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
              AND owner.id IS NOT NULL" . filtro_colegios_restringidos_informe_sql() . "
        GROUP BY c.id, owner.id");
    $stmtColegios->execute([$idPeriodo, $idCalendario]);
    $colegios = $stmtColegios->fetchAll(PDO::FETCH_ASSOC);

    $idColegioAsesor = []; // id_colegio => id_asesor (un colegio pertenece a un solo asesor acá)
    foreach ($colegios as $c) $idColegioAsesor[(int)$c['id']] = (int)$c['id_asesor'];
    $idsColegio = array_values(array_unique(array_keys($idColegioAsesor)));

    $vacio = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0];
    $presupuestoPorAsesor = []; // id_asesor => ['eureka'=>, 'mcgraw'=>, 'otra'=>]
    $adopcionPorAsesor = [];
    // Detalle del bucket "Otra" por editorial (id_asesor => id_editorial => monto) — para el
    // cuadro de desglose debajo del informe (pedido por el usuario 2026-10-05: ver cuánto de
    // "Otra" es Altiva y cuánto de las demás editoriales).
    $presupuestoOtraPorEditorial = [];
    $adopcionOtraPorEditorial = [];

    if (!empty($idsColegio)) {
        $ph = implode(',', array_fill(0, count($idsColegio), '?'));

        // Presupuesto/adopción por línea, con el editorial del libro — mismo filtro de negocio que
        // php/valoriza_global_excel.php ("valorización libro a libro": excluye probabilidad=3
        // "Perdida" y líneas sin ninguna tasa de compra asignada, que no representan una venta
        // proyectable).
        $stmtPres = $bdd->prepare("SELECT p.id_colegio, p.tasa_compra, p.tasa_compra_d, p.descuento, p.descuento_d,
                                           p.precio, p.pre_definido, p.definido, p.cod_area, p.uni_vr, l.id_grado, l.editorial, l.isbn
                                    FROM presupuestos p JOIN libros l ON p.id_libro = l.id
                                    WHERE p.id_colegio IN ($ph) AND (p.pre_definido=1 OR p.definido=1) AND p.id_periodo = ?
                                          AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)"
                                          . filtro_libros_excluidos_informe_sql());
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
                $idEdEfectiva = editorial_efectiva_informe($l['editorial'], $l['isbn']);
                $bucket = calcular_bucket_editorial_informe($idEdEfectiva);

                if ($l['pre_definido'] == 1) {
                    // round() antes de floor(): mismo motivo que valoriza_global_excel.php
                    // (tasa_compra es DECIMAL pero PDO lo entrega como string/float binario
                    // impreciso).
                    $alumnos_tasa = floor(round($alumnos * $l['tasa_compra'], 6));
                    $precio_neto  = $l['precio'] - ($l['precio'] * $l['descuento']);
                    $presupuestoPorAsesor[$idAsesor][$bucket] += $precio_neto * $alumnos_tasa;
                    if ($bucket === 'otra') {
                        $idEd = $idEdEfectiva;
                        $presupuestoOtraPorEditorial[$idAsesor][$idEd] = ($presupuestoOtraPorEditorial[$idAsesor][$idEd] ?? 0.0) + $precio_neto * $alumnos_tasa;
                    }
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
                    if ($bucket === 'otra') {
                        $idEd = $idEdEfectiva;
                        $adopcionOtraPorEditorial[$idAsesor][$idEd] = ($adopcionOtraPorEditorial[$idAsesor][$idEd] ?? 0.0) + $precio_neto_d * $alumnos_tasa_d;
                    }
                }
            }
        }
    }

    return [
        'presupuestoPorAsesor' => $presupuestoPorAsesor, 'adopcionPorAsesor' => $adopcionPorAsesor,
        'presupuestoOtraPorEditorial' => $presupuestoOtraPorEditorial, 'adopcionOtraPorEditorial' => $adopcionOtraPorEditorial,
    ];
}

/** Suma $origen (id => monto) dentro de $destino (id => monto). */
function sumar_montos_por_id_informe(array &$destino, array $origen) {
    foreach ($origen as $id => $monto) $destino[$id] = ($destino[$id] ?? 0.0) + $monto;
}

/** [id_editorial => nombre] de los ids pedidos (id 0 = libros sin editorial). */
function nombres_editoriales_informe($bdd, array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $nombres = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $bdd->prepare("SELECT id, editorial FROM editoriales WHERE id IN ($ph)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $nombres[(int)$row['id']] = trim($row['editorial']);
    }
    $nombres[ID_EDITORIAL_PL_INFORME] = 'PL';
    foreach ($ids as $id) if (!isset($nombres[$id])) $nombres[$id] = 'SIN EDITORIAL';
    return $nombres;
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
    $presOtraEd = [];
    $adopOtraEd = [];
    foreach ($temporada['idsIncluidos'] as $idPeriodoParte) {
        $parcial = calcular_datos_editorial_un_periodo($bdd, $idPeriodoParte);
        foreach ($parcial['presupuestoOtraPorEditorial'] as $idAsesor => $porEd) {
            if (!isset($presOtraEd[$idAsesor])) $presOtraEd[$idAsesor] = [];
            sumar_montos_por_id_informe($presOtraEd[$idAsesor], $porEd);
        }
        foreach ($parcial['adopcionOtraPorEditorial'] as $idAsesor => $porEd) {
            if (!isset($adopOtraEd[$idAsesor])) $adopOtraEd[$idAsesor] = [];
            sumar_montos_por_id_informe($adopOtraEd[$idAsesor], $porEd);
        }
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
            // Detalle de "Otra": [id_editorial => monto]
            'presupuesto_otra_ed' => $presOtraEd[$id] ?? [],
            'adopcion_otra_ed' => $adopOtraEd[$id] ?? [],
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

    $presTemporada = obtener_presupuesto_temporada_informe_editorial($bdd, $idPeriodo);

    $hoy = date('Y-m-d');
    $stmt = $bdd->prepare("INSERT INTO informe_editorial_snapshots
            (fecha, id_periodo, id_usuario, adopcion_eureka, adopcion_mcgraw, adopcion_otra,
             presupuesto_eureka, presupuesto_mcgraw, presupuesto_otra, presupuesto_temporada)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            adopcion_eureka = VALUES(adopcion_eureka), adopcion_mcgraw = VALUES(adopcion_mcgraw), adopcion_otra = VALUES(adopcion_otra),
            presupuesto_eureka = VALUES(presupuesto_eureka), presupuesto_mcgraw = VALUES(presupuesto_mcgraw), presupuesto_otra = VALUES(presupuesto_otra),
            presupuesto_temporada = VALUES(presupuesto_temporada)");
    foreach ($datos['asesores'] as $a) {
        $stmt->execute([
            $hoy, $idCanonico, $a['id_usuario'],
            $a['adopcion']['eureka'], $a['adopcion']['mcgraw'], $a['adopcion']['otra'],
            $a['presupuesto']['eureka'], $a['presupuesto']['mcgraw'], $a['presupuesto']['otra'],
            $presTemporada[$a['id_usuario']] ?? null,
        ]);
    }
    return count($datos['asesores']);
}

/**
 * Respaldo del cron semanal: si hoy es viernes y todavía no hay foto de hoy para la temporada, la
 * guarda al abrir el informe o descargar el Excel. El Programador de tareas de Windows nunca se
 * llegó a configurar (reportado 2026-09-25: las columnas "Último informe" / "Variación" salían
 * vacías), así que la foto semanal no puede depender solo de él. Si el cron sí corre, su
 * guardado de las 9am sobrescribe este (ON DUPLICATE KEY UPDATE). No afecta la comparación del
 * mismo día: obtener_ultimo_snapshot_informe_editorial() solo mira fechas anteriores a hoy.
 */
function asegurar_snapshot_semanal_informe_editorial($bdd, $idPeriodo) {
    if ((int)date('N') !== 5) return;
    asegurar_tabla_informe_editorial_snapshots($bdd);
    $idCanonico = resolver_temporada_informe_editorial($bdd, $idPeriodo)['idCanonico'];
    $stmt = $bdd->prepare("SELECT 1 FROM informe_editorial_snapshots WHERE id_periodo = ? AND fecha = CURDATE() LIMIT 1");
    $stmt->execute([$idCanonico]);
    if ($stmt->fetchColumn()) return;
    guardar_snapshot_informe_editorial($bdd, $idPeriodo);
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
                                   presupuesto_eureka, presupuesto_mcgraw, presupuesto_otra, presupuesto_temporada
                            FROM informe_editorial_snapshots WHERE id_periodo = ? AND fecha = ?");
    $stmt->execute([$idCanonico, $fecha]);
    $porAsesor = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adopTotal = $row['adopcion_eureka'] + $row['adopcion_mcgraw'] + $row['adopcion_otra'];
        // Mismo denominador que el TOTAL CUMPLIMIENTO del Excel: presupuesto por temporada si
        // estaba guardado al tomar la foto; si no, el presupuesto cargado en el CRM.
        $presTotal = $row['presupuesto_temporada'] !== null
            ? (float)$row['presupuesto_temporada']
            : $row['presupuesto_eureka'] + $row['presupuesto_mcgraw'] + $row['presupuesto_otra'];
        $porAsesor[(int)$row['id_usuario']] = [
            'cumplimiento' => $presTotal > 0 ? ($adopTotal / $presTotal) * 100 : null,
        ];
    }
    return ['fecha' => $fecha, 'porAsesor' => $porAsesor];
}

/**
 * Venta real por asesor de UN SOLO período (sin combinar temporada) — mismo patrón de
 * php/dashboard_ventareal_stats.php (recursos.venta_real manual reemplaza por completo al
 * calculado, por colegio, cuando está capturado >0), pero atribuido con el mismo criterio de
 * "dueño de zona" que usa ESTE informe (owner.tipo=3 OR owner.id=69, SIN owner.act=1) en vez del
 * criterio de ese dashboard (tipo IN (3,6), owner.act=1) — para que sea el mismo asesor al que ya
 * se le atribuye presupuesto/adopción en este reporte.
 */
function venta_real_por_asesor_un_periodo($bdd, $idPeriodo) {
    $idPeriodo = (int)$idPeriodo;
    $stmtPer = $bdd->prepare("SELECT id_calendario FROM periodos WHERE id = ?");
    $stmtPer->execute([$idPeriodo]);
    $idCalendario = (int)$stmtPer->fetchColumn();
    if (!$idCalendario) return [];

    $ownerJoin = "LEFT JOIN (
            SELECT id_colegio, id_periodo, MIN(cod_zona) as cod_zona
            FROM presupuestos
            WHERE cod_zona <> ''
            GROUP BY id_colegio, id_periodo
            HAVING COUNT(DISTINCT cod_zona) = 1
        ) pz ON pz.id_colegio = c.id AND pz.id_periodo = p.id_periodo
        LEFT JOIN usuarios owner ON owner.cod_zona = COALESCE(pz.cod_zona, c.cod_zona) AND owner.cod_zona <> ''
             AND (owner.tipo = 3 OR owner.id = 69)";

    $filtroRestringidos = filtro_colegios_restringidos_informe_sql();

    // Desglosada por editorial del libro (pedido por el usuario 2026-10-02: la venta real se
    // distribuye en Eureka / McGraw Hill / Otra igual que presupuesto y adopciones).
    $stmtCalc = $bdd->prepare("SELECT c.id as id_colegio, owner.id as id_asesor, l.editorial, l.isbn,
            SUM(CASE WHEN p.tasa_compra_d = 0
                THEN (p.precio - p.precio * p.descuento) * p.uni_vr
                ELSE (p.precio - p.precio * p.descuento_d) * p.uni_vr END) as venta_calculada
        FROM presupuestos p
        JOIN colegios c ON p.id_colegio = c.id
        JOIN libros l ON p.id_libro = l.id
        $ownerJoin
        WHERE p.id_periodo = ? AND p.definido != 0 AND c.id_calendario = ?
              AND p.probabilidad != 3 AND (p.tasa_compra != 0.00 OR p.tasa_compra_d != 0.00)
              AND owner.id IS NOT NULL $filtroRestringidos
              " . filtro_libros_excluidos_informe_sql() . "
        GROUP BY c.id, owner.id, l.editorial, l.isbn");
    $stmtCalc->execute([$idPeriodo, $idCalendario]);

    $vacio = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'otra_ed' => []];
    $ventaPorColegio = []; // id_colegio => ['id_asesor'=>, 'eureka'=>, 'mcgraw'=>, 'otra'=>, 'otra_ed' => [id_editorial => monto]]
    foreach ($stmtCalc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idColegio = (int)$row['id_colegio'];
        if (!isset($ventaPorColegio[$idColegio])) $ventaPorColegio[$idColegio] = ['id_asesor' => (int)$row['id_asesor']] + $vacio;
        $idEdEfectiva = editorial_efectiva_informe($row['editorial'], $row['isbn']);
        $bucket = calcular_bucket_editorial_informe($idEdEfectiva);
        $ventaPorColegio[$idColegio][$bucket] += (float)$row['venta_calculada'];
        if ($bucket === 'otra') {
            $idEd = $idEdEfectiva;
            $ventaPorColegio[$idColegio]['otra_ed'][$idEd] = ($ventaPorColegio[$idColegio]['otra_ed'][$idEd] ?? 0.0) + (float)$row['venta_calculada'];
        }
    }

    $stmtManual = $bdd->prepare("SELECT r.id_colegio, owner.id as id_asesor, MAX(r.venta_real) as venta_real
        FROM recursos r
        JOIN colegios c ON r.id_colegio = c.id
        JOIN presupuestos p ON p.id_colegio = c.id AND p.id_periodo = r.id_periodo
        $ownerJoin
        WHERE r.id_periodo = ? AND r.venta_real > 0 AND c.id_calendario = ? AND owner.id IS NOT NULL $filtroRestringidos
        GROUP BY r.id_colegio, owner.id");
    $stmtManual->execute([$idPeriodo, $idCalendario]);
    foreach ($stmtManual->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // El manual reemplaza al calculado para ese colegio, sin importar si ya había uno. Como
        // recursos.venta_real es un solo monto por colegio (sin editorial), se reparte en la misma
        // proporción por editorial que tenga la venta calculada del colegio; si no hay calculada
        // para repartir, va completa a Eureka.
        $idColegio = (int)$row['id_colegio'];
        $manual = (float)$row['venta_real'];
        $calc = $ventaPorColegio[$idColegio] ?? $vacio;
        $totalCalc = $calc['eureka'] + $calc['mcgraw'] + $calc['otra'];
        $fila = ['id_asesor' => (int)$row['id_asesor']] + $vacio;
        if ($totalCalc > 0) {
            foreach (['eureka', 'mcgraw', 'otra'] as $bucket) $fila[$bucket] = $manual * $calc[$bucket] / $totalCalc;
            foreach ($calc['otra_ed'] as $idEd => $monto) $fila['otra_ed'][$idEd] = $manual * $monto / $totalCalc;
        } else {
            $fila['eureka'] = $manual;
        }
        $ventaPorColegio[$idColegio] = $fila;
    }

    $porAsesor = []; // id_asesor => ['eureka','mcgraw','otra','total','otra_ed']
    foreach ($ventaPorColegio as $fila) {
        $total = $fila['eureka'] + $fila['mcgraw'] + $fila['otra'];
        if ($total <= 0) continue;
        $id = $fila['id_asesor'];
        if (!isset($porAsesor[$id])) $porAsesor[$id] = $vacio + ['total' => 0.0];
        foreach (['eureka', 'mcgraw', 'otra'] as $bucket) $porAsesor[$id][$bucket] += $fila[$bucket];
        $porAsesor[$id]['total'] += $total;
        sumar_montos_por_id_informe($porAsesor[$id]['otra_ed'], $fila['otra_ed']);
    }
    return $porAsesor;
}

/**
 * Venta real por asesor de la TEMPORADA ANTERIOR a la de $idPeriodo (año A-1, con su propia pareja
 * de Calendario B) — pedido por el usuario 2026-09-22 para la columna "Venta real temporada {año}"
 * de php/informe_editorial_excel.php: al bajar el informe de una temporada (ej. 2027), mostrar de
 * referencia cuánto vendió realmente cada asesor en la temporada pasada (2026+2025B). Reutiliza
 * resolver_temporada_informe_editorial() para encontrar la pareja de Calendario B de esa temporada
 * anterior, igual que se hace con la temporada actual.
 * Devuelve ['anioAnterior' => int|null, 'labelAnterior' => string, 'porAsesor' => [id_usuario =>
 * venta_real]]. anioAnterior=null si el período "año-1" de Calendario A no existe todavía (ej. se
 * pidió el período más antiguo cargado) — el llamador debe dejar la columna vacía en ese caso, no
 * lanzar error.
 */
function obtener_venta_real_temporada_anterior($bdd, $idPeriodo) {
    $temporadaActual = resolver_temporada_informe_editorial($bdd, $idPeriodo);
    $stmtA = $bdd->prepare("SELECT periodo FROM periodos WHERE id = ?");
    $stmtA->execute([$temporadaActual['idCanonico']]);
    $anioActual = (int)preg_replace('/[^0-9]/', '', (string)$stmtA->fetchColumn());
    if ($anioActual <= 0) return ['anioAnterior' => null, 'labelAnterior' => '', 'porAsesor' => []];

    $anioAnterior = $anioActual - 1;
    $stmtPer = $bdd->prepare("SELECT p.id FROM periodos p JOIN calendarios c ON c.id = p.id_calendario WHERE c.calendario = 'A' AND p.periodo = ?");
    $stmtPer->execute([(string)$anioAnterior]);
    $idPeriodoAnterior = $stmtPer->fetchColumn();
    if (!$idPeriodoAnterior) return ['anioAnterior' => $anioAnterior, 'labelAnterior' => (string)$anioAnterior, 'porAsesor' => []];

    $temporadaAnterior = resolver_temporada_informe_editorial($bdd, (int)$idPeriodoAnterior);

    $porAsesor = [];
    foreach ($temporadaAnterior['idsIncluidos'] as $idPeriodoParte) {
        foreach (venta_real_por_asesor_un_periodo($bdd, $idPeriodoParte) as $idAsesor => $montos) {
            if (!isset($porAsesor[$idAsesor])) $porAsesor[$idAsesor] = ['eureka' => 0.0, 'mcgraw' => 0.0, 'otra' => 0.0, 'total' => 0.0, 'otra_ed' => []];
            foreach (['eureka', 'mcgraw', 'otra', 'total'] as $k) $porAsesor[$idAsesor][$k] += $montos[$k];
            sumar_montos_por_id_informe($porAsesor[$idAsesor]['otra_ed'], $montos['otra_ed']);
        }
    }

    return ['anioAnterior' => $anioAnterior, 'labelAnterior' => $temporadaAnterior['labelCombinado'], 'porAsesor' => $porAsesor];
}
