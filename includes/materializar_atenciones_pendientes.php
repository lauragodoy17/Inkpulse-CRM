<?php
/**
 * /includes/materializar_atenciones_pendientes.php
 * Distribución en años de una atención (ver php/solicitud_recurso.php): al distribuir una
 * solicitud en varios años, los años siguientes al actual NO crean un período académico nuevo —
 * eso habilitaría presupuestos/adopciones/tasas/etc. para un año que todavía no existe de verdad
 * en el negocio. En vez de eso, cada cuota futura queda "pendiente" en
 * atenciones_pendientes_distribucion (sin tocar la tabla periodos para nada).
 *
 * La primera vez que alguien abre el reporte o la pestaña de atenciones de un período que
 * coincide en año y calendario con una pendiente (es decir: el período YA existe, por sus propias
 * razones normales del negocio, no por esta función), materializar_atenciones_pendientes() la
 * convierte en una solicitud real — igual que si el promotor la hubiera creado ese día — y la
 * marca para no volver a procesarla.
 */

function crear_tabla_pendientes_distribucion(PDO $bdd) {
    try {
        $bdd->exec("CREATE TABLE IF NOT EXISTS atenciones_pendientes_distribucion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_solicitud_origen INT NOT NULL,
            id_recurso_origen INT NULL,
            id_colegio INT NOT NULL,
            id_calendario INT NOT NULL,
            anio_objetivo INT NOT NULL,
            id_usuario INT NOT NULL,
            solicitante INT NOT NULL,
            fecha_entrega DATE NOT NULL,
            reintegro VARCHAR(255) NULL,
            recurso TEXT NOT NULL,
            tipo INT NOT NULL,
            categoria INT NOT NULL,
            monto DECIMAL(10,0) NOT NULL DEFAULT 0,
            distribucion_anio_num INT NOT NULL,
            distribucion_total_anios INT NOT NULL,
            materializado TINYINT(1) NOT NULL DEFAULT 0,
            id_solicitud_generada INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pendientes (materializado, id_calendario, anio_objetivo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Exception $e) {}
}

/**
 * Año calendario de la etiqueta de un período: "2026" -> 2026, "2026B" -> 2026.
 */
function anio_de_periodo($etiqueta_periodo) {
    return (int) substr((string)$etiqueta_periodo, 0, 4);
}

/**
 * Materializa (si hay algo pendiente) las cuotas de distribución en años que le correspondan al
 * período $id_periodo. Segura para llamar en cada carga del reporte/tab de atenciones: si no hay
 * filas pendientes para ese calendario+año, no hace ningún INSERT.
 */
function materializar_atenciones_pendientes(PDO $bdd, $id_periodo) {
    $id_periodo = (int)$id_periodo;
    if ($id_periodo <= 0) return;

    crear_tabla_pendientes_distribucion($bdd);

    $req_p = $bdd->prepare("SELECT id_calendario, periodo FROM periodos WHERE id = ?");
    $req_p->execute([$id_periodo]);
    $periodo_row = $req_p->fetch(PDO::FETCH_ASSOC);
    if (!$periodo_row) return;

    $id_calendario = (int)$periodo_row['id_calendario'];
    $anio          = anio_de_periodo($periodo_row['periodo']);

    $req_pend = $bdd->prepare(
        "SELECT DISTINCT id_solicitud_origen FROM atenciones_pendientes_distribucion
         WHERE materializado = 0 AND id_calendario = ? AND anio_objetivo = ?"
    );
    $req_pend->execute([$id_calendario, $anio]);
    $origenes = $req_pend->fetchAll(PDO::FETCH_COLUMN);
    if (empty($origenes)) return;

    $stmt_fragmentos = $bdd->prepare(
        "SELECT * FROM atenciones_pendientes_distribucion
         WHERE materializado = 0 AND id_calendario = ? AND anio_objetivo = ? AND id_solicitud_origen = ?"
    );
    $stmt_conse    = $bdd->prepare("SELECT MAX(conse) as conse FROM solicitudes_recursos WHERE id_periodo = ?");
    $stmt_sol_ins  = $bdd->prepare(
        "INSERT INTO solicitudes_recursos
            (id_periodo, usuario, id_colegio, estado, solicitante, fecha_entrega, reintegro, conse, distribucion_grupo_id, distribucion_anio_num, distribucion_total_anios)
         VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt_rec_ins  = $bdd->prepare("INSERT INTO recursos_solicitados (id_solicitud, tipo, categoria, recurso, presupuesto) VALUES (?, ?, ?, ?, ?)");
    $stmt_marcar   = $bdd->prepare("UPDATE atenciones_pendientes_distribucion SET materializado = 1, id_solicitud_generada = ? WHERE id = ?");
    $stmt_area_sel = $bdd->prepare("SELECT materia, preescolar, primaria, bachillerato FROM areas_recursos WHERE id_solicitud = ?");
    $stmt_area_ins = $bdd->prepare("INSERT INTO areas_recursos (id_solicitud, materia, preescolar, primaria, bachillerato) VALUES (?, ?, ?, ?, ?)");
    $stmt_notif    = $bdd->prepare("INSERT INTO notificaciones (id_periodo,id_colegio,id_usuario,id_solicitud,id_tipo_notifi,usuario_respuesta,visible) VALUES (?,?,0,?,7,0,1)");

    foreach ($origenes as $id_origen) {
        $stmt_fragmentos->execute([$id_calendario, $anio, $id_origen]);
        $fragmentos = $stmt_fragmentos->fetchAll(PDO::FETCH_ASSOC);
        if (empty($fragmentos)) continue;

        $f0 = $fragmentos[0];

        $stmt_conse->execute([$id_periodo]);
        $conse = ((int)$stmt_conse->fetchColumn()) + 1;

        $stmt_sol_ins->execute([
            $id_periodo, $f0['id_usuario'], $f0['id_colegio'], $f0['solicitante'],
            $f0['fecha_entrega'], $f0['reintegro'], $conse,
            $id_origen, $f0['distribucion_anio_num'], $f0['distribucion_total_anios'],
        ]);
        $id_solicitud_nueva = (int)$bdd->lastInsertId();

        $stmt_area_sel->execute([$id_origen]);
        foreach ($stmt_area_sel->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $stmt_area_ins->execute([$id_solicitud_nueva, $a['materia'], $a['preescolar'], $a['primaria'], $a['bachillerato']]);
        }

        foreach ($fragmentos as $frag) {
            $stmt_rec_ins->execute([$id_solicitud_nueva, $frag['tipo'], $frag['categoria'], $frag['recurso'], $frag['monto']]);
            $stmt_marcar->execute([$id_solicitud_nueva, $frag['id']]);
        }

        // Notificación en la campanita del CRM, igual que una solicitud nueva normal. No se
        // reenvía el correo SMTP acá a propósito: esta función corre dentro de la carga de un
        // reporte/tab (potencialmente muchas veces al día), y una llamada SMTP síncrona ahí
        // arriesga hacer lenta o fallar la vista solo por una materialización de fondo.
        try { $stmt_notif->execute([$id_periodo, $f0['id_colegio'], $id_solicitud_nueva]); } catch (\Exception $e) {}
    }
}
