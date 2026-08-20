<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>Resumen solicitud</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css"/>
  <link rel="stylesheet" href="src/plugins/datatables/css/responsive.bootstrap4.min.css"/>
  <link rel="stylesheet" href="vendors/styles/core.css"/>
  <link rel="stylesheet" href="vendors/styles/icon-font.min.css"/>
  <link rel="stylesheet" href="vendors/styles/style.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <style>
    /* ── Modo iframe: ocultar nav y header ────────────────────── */
    html.in-iframe .left-side-bar,
    html.in-iframe .header,
    html.in-iframe .mobile-menu-overlay,
    html.in-iframe .main-container > footer { display: none !important; }
    html.in-iframe .main-container         { padding-left: 0 !important; min-height: unset; }
    html.in-iframe body                    { background: #f8fafc !important; }

    /* ── Contenedor ────────────────────────────────────────────── */
    .vs-wrap { padding: 22px 24px; }

    /* ── Encabezado solicitud ─────────────────────────────────── */
    .vs-header {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .vs-num {
      font-size: 1.1rem;
      font-weight: 800;
      color: #0f172a;
      margin: 0;
    }

    /* Badge estado */
    .vs-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .vs-badge.pendiente  { background:#fef9c3; color:#854d0e; }
    .vs-badge.aprobado   { background:#dcfce7; color:#166534; }
    .vs-badge.entregado  { background:#dbeafe; color:#1e40af; }
    .vs-badge.cobrado    { background:#dbeafe; color:#1e40af; }
    .vs-badge.anulado          { background:#fee2e2; color:#991b1b; }
    .vs-badge.rechazado        { background:#fee2e2; color:#991b1b; }
    .vs-badge.default          { background:#f1f5f9; color:#64748b; }
    .vs-badge.en-proceso       { background:#fef3c7; color:#92400e; }
    .vs-badge.legal-completo   { background:#dcfce7; color:#15803d; }

    /* ── Info cards ──────────────────────────────────────────── */
    .vs-info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 24px;
    }
    .vs-info-card {
      background: #fff;
      border-radius: 8px;
      padding: 12px 16px;
      box-shadow: 0 1px 4px rgba(15,23,42,.07);
      border-left: 3px solid #6366f1;
    }
    .vs-info-label { font-size: 0.72rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing:.04em; margin: 0 0 3px; }
    .vs-info-val   { font-size: 0.88rem; font-weight: 600; color: #0f172a; margin: 0; }

    /* ── Sección título ─────────────────────────────────────── */
    .vs-section-title {
      font-size: 0.88rem;
      font-weight: 700;
      color: #1e293b;
      margin: 22px 0 10px;
      display: flex;
      align-items: center;
      gap: 7px;
    }
    .vs-section-title i { color: #6366f1; }

    /* ── Tablas ─────────────────────────────────────────────── */
    .vs-table-wrap { border-radius: 10px; overflow: hidden; box-shadow: 0 1px 8px rgba(15,23,42,.09); margin-bottom: 20px; }
    .vs-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; }
    .vs-table thead th {
      background: #f8fafc;
      color: #374151;
      font-weight: 600;
      padding: 10px 12px;
      text-align: left;
      border: none;
      border-bottom: 2px solid #e2e8f0;
      white-space: nowrap;
      font-size: 0.79rem;
    }
    .vs-table thead th.center { text-align: center; }
    .vs-table tbody tr { background: #fff; }
    .vs-table tbody tr:nth-child(even) { background: #f8fafc; }
    .vs-table tbody td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; color: #1e293b; vertical-align: middle; }
    .vs-table tfoot td { padding: 9px 12px; font-weight: 700; font-size: 0.83rem; background: #f8fafc; color: #374151; border: none; border-top: 2px solid #e2e8f0; }

    /* inputs dentro de tabla */
    .vs-table input[type="text"],
    .vs-table select {
      border: 1px solid #cbd5e0;
      border-radius: 6px;
      padding: 4px 8px;
      font-size: 0.81rem;
      background: #f8fafc;
      outline: none;
      transition: border-color .15s;
      width: 100%;
    }
    .vs-table input[type="text"]:focus,
    .vs-table select:focus { border-color: #6366f1; background: #fff; box-shadow: 0 0 0 2px rgba(99,102,241,.15); }

    /* ── Botones de acción ─────────────────────────────────── */
    .vs-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; justify-content: center; }

    /* ── Contabilizada ──────────────────────────────────────── */
    .vs-contab {
      background: #dcfce7;
      color: #15803d;
      border-radius: 8px;
      padding: 10px 20px;
      font-weight: 700;
      font-size: 0.9rem;
      text-align: center;
      margin-top: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    /* ── Archivo legalización ───────────────────────────────── */
    .vs-archivo { margin-top: 16px; padding: 14px 16px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(15,23,42,.07); }
    .vs-archivo label { font-size: 0.82rem; font-weight: 600; color: #374151; }
  </style>

  <script>
    if (window.self !== window.top) {
      document.documentElement.classList.add('in-iframe');
    }
  </script>
</head>
<body>
<div class="main-container">
<div class="pd-ltr-20 xs-pd-20-10">
<div class="min-height-200px">

<?php
  include("conexion/bdd.php");

  $sql = "SELECT e.estado, s.estado as idestado, s.id, s.fecha,
                 CONCAT(t.nombre, ' ', t.apellido) as solicitante,
                 ca.cargo, s.fecha_entrega, s.id_periodo,
                 s.archivo, s.contab, s.conse, c.colegio, c.codigo,
                 s.distribucion_grupo_id, s.distribucion_anio_num, s.distribucion_total_anios
          FROM solicitudes_recursos s
          JOIN estados_pedidos e   ON e.id = s.estado
          JOIN colegios c          ON c.id = s.id_colegio
          LEFT JOIN trabajadores_colegios t ON s.solicitante = t.id
          LEFT JOIN cargos ca      ON ca.id = t.cargo
          WHERE s.id='".$_GET["id"]."'";
  $req = $bdd->prepare($sql); $req->execute();
  $solicitud = $req->fetch();

  // ── Presupuesto reservado a futuro (solo en la solicitud raíz que se distribuyó en años) ──
  $pendientes_futuro = [];
  if (empty($solicitud["distribucion_grupo_id"]) && (int)$solicitud["distribucion_total_anios"] > 1) {
    $sql_pend = "SELECT anio_objetivo, SUM(monto) as total FROM atenciones_pendientes_distribucion
                 WHERE id_solicitud_origen=? AND materializado=0
                 GROUP BY anio_objetivo ORDER BY anio_objetivo ASC";
    $req_pend = $bdd->prepare($sql_pend); $req_pend->execute([$solicitud["id"]]);
    $pendientes_futuro = $req_pend->fetchAll();
  }

  $num = ($solicitud["id"] < 221) ? $solicitud["id"] : $solicitud["conse"];

  // Badge estado
  $estado_lower = strtolower($solicitud["estado"]);
  if     (str_contains($estado_lower, 'solicit') || str_contains($estado_lower, 'pendient')) $badge = 'pendiente';
  elseif (str_contains($estado_lower, 'aprob'))                                              $badge = 'aprobado';
  elseif (str_contains($estado_lower, 'entreg'))                                             $badge = 'entregado';
  elseif (str_contains($estado_lower, 'cobr'))                                               $badge = 'cobrado';
  elseif (str_contains($estado_lower, 'anul') || str_contains($estado_lower, 'cerr'))        $badge = 'anulado';
  elseif (str_contains($estado_lower, 'rechaz'))                                             $badge = 'rechazado';
  else                                                                                        $badge = 'default';

  $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  $dt = date_create($solicitud["fecha"]);
  $fecha_legible = $dt ? (int)$dt->format('j') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y') . ' · ' . $dt->format('g:i a') : $solicitud["fecha"];

  // Pre-calcular totales para el estado de legalización en el header
  $req_sum = $bdd->prepare("SELECT SUM(presupuesto) as tot_p, SUM(valor_e) as tot_ve FROM recursos_solicitados WHERE id_solicitud=?");
  $req_sum->execute([$solicitud["id"]]);
  $sums_h        = $req_sum->fetch();
  $tot_presup_h  = (float)($sums_h['tot_p']  ?? 0);
  $tot_valor_e_h = (float)($sums_h['tot_ve'] ?? 0);

  $tot_legaliza_h = 0;
  try {
    $req_leg = $bdd->prepare("SELECT COALESCE(SUM(valor),0) FROM trazabilidad_entregas WHERE id_solicitud=? AND tipo='legalizacion'");
    $req_leg->execute([$solicitud["id"]]);
    $tot_legaliza_h = (float)$req_leg->fetchColumn();
  } catch (Exception $e) {}
  // Fallback a campo histórico para estados 2 y 4
  if ($tot_legaliza_h <= 0 && in_array((int)$solicitud['idestado'], [2, 4])) {
    $req_old_h = $bdd->prepare("SELECT COALESCE(SUM(legaliza),0) FROM recursos_solicitados WHERE id_solicitud=?");
    $req_old_h->execute([$solicitud["id"]]);
    $old_l_h = (float)$req_old_h->fetchColumn();
    if ($old_l_h > 0) $tot_legaliza_h = $old_l_h;
  }

  $saldo_h = $tot_presup_h - $tot_legaliza_h;

  $legal_cerrado = 0;
  try {
    $req_lc = $bdd->prepare("SELECT legal_cerrado FROM solicitudes_recursos WHERE id=?");
    $req_lc->execute([$solicitud["id"]]);
    $legal_cerrado = (int)$req_lc->fetchColumn();
  } catch (Exception $e) {}

  if ($legal_cerrado)           $legal_estado = 'completo';
  elseif ($tot_legaliza_h <= 0) $legal_estado = null;
  elseif ($saldo_h > 0)         $legal_estado = 'proceso';
  else                          $legal_estado = 'completo';
?>

<div class="vs-wrap">

  <!-- Encabezado -->
  <div class="vs-header">
    <p class="vs-num"><i class="bi bi-file-earmark-text" style="color:#6366f1;margin-right:6px"></i>Solicitud #<?= htmlspecialchars($num) ?></p>
    <span class="vs-badge <?= $badge ?>"><?= htmlspecialchars($solicitud["estado"]) ?></span>
    <?php if ($legal_estado === 'proceso'): ?>
      <span class="vs-badge en-proceso"><i class="bi bi-hourglass-split" style="margin-right:4px"></i>En proceso de legalización</span>
    <?php elseif ($legal_estado === 'completo'): ?>
      <span class="vs-badge legal-completo"><i class="bi bi-patch-check-fill" style="margin-right:4px"></i>Legalización completa</span>
    <?php endif; ?>
  </div>

  <!-- Tarjetas de información -->
  <div class="vs-info-grid">
    <div class="vs-info-card">
      <p class="vs-info-label">Colegio</p>
      <p class="vs-info-val"><?= htmlspecialchars($solicitud["colegio"]) ?></p>
    </div>
    <div class="vs-info-card">
      <p class="vs-info-label">Fecha de solicitud</p>
      <p class="vs-info-val"><?= htmlspecialchars($fecha_legible) ?></p>
    </div>
    <div class="vs-info-card">
      <p class="vs-info-label">Solicitante</p>
      <p class="vs-info-val"><?= htmlspecialchars($solicitud["solicitante"].' ('.$solicitud["cargo"].')') ?></p>
    </div>
    <div class="vs-info-card">
      <p class="vs-info-label">Fecha de entrega</p>
      <p class="vs-info-val"><?= htmlspecialchars($solicitud["fecha_entrega"]) ?></p>
    </div>
  </div>

  <?php if (!empty($pendientes_futuro)): ?>
  <div style="margin-bottom:16px;padding:10px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;">
    <span style="font-size:.83rem;font-weight:600;color:#1e40af;">
      <i class="bi bi-hourglass-top" style="margin-right:6px"></i>
      Presupuesto reservado a futuro (distribución en años):
      <?php
        $partes = [];
        foreach ($pendientes_futuro as $p) {
          $partes[] = $p['anio_objetivo'] . ': $ ' . number_format($p['total'], 0, ',', '.');
        }
        echo htmlspecialchars(implode(' · ', $partes));
      ?>
    </span>
    <div style="font-size:.76rem;color:#3b82f6;margin-top:3px;">
      Todavía no aparece en ningún reporte de atenciones — se activa solo cuando ese período exista.
    </div>
  </div>
  <?php endif; ?>

  <!-- Áreas comprometidas -->
  <p class="vs-section-title"><i class="bi bi-grid-3x3-gap"></i> Áreas comprometidas</p>
  <div class="vs-table-wrap">
    <table class="vs-table">
      <thead>
        <tr>
          <th>Área / Materia</th>
          <th class="center">Preescolar</th>
          <th class="center">Primaria</th>
          <th class="center">Bachillerato</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $sql = "SELECT m.materia, a.preescolar, a.primaria, a.bachillerato
                  FROM areas_recursos a JOIN materias m ON a.materia=m.id
                  WHERE a.id_solicitud='".$solicitud["id"]."'";
          $req = $bdd->prepare($sql); $req->execute();
          foreach ($req->fetchAll() as $area):
        ?>
        <tr>
          <td><?= htmlspecialchars($area["materia"]) ?></td>
          <td style="text-align:center"><?= htmlspecialchars($area["preescolar"]) ?></td>
          <td style="text-align:center"><?= htmlspecialchars($area["primaria"]) ?></td>
          <td style="text-align:center"><?= htmlspecialchars($area["bachillerato"]) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Recursos solicitados -->
  <p class="vs-section-title"><i class="bi bi-box-seam"></i> Recursos solicitados</p>
  <?php
  if ($solicitud["idestado"] == 1) {
    echo '<form action="php/modificar_solicitud.php?tipo=1" method="POST" id="form_a">';
  } else {
    echo '<form action="php/modificar_solicitud.php?tipo=2" method="POST" id="form_a">';
  }
  ?>
  <div class="vs-table-wrap">
  <div style="overflow-x:auto;">
    <table class="vs-table">
      <thead>
        <tr>
          <th>Recurso</th>
          <th>Tipo</th>
          <th>Categoría</th>
          <th>Presupuesto</th>
          <th>Tipo recurso entregado</th>
          <th>Valor entregado</th>
          <th>Fecha entrega</th>
          <th>Legalización</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $sql = "SELECT t.tipo, r.id, r.recurso, r.presupuesto, r.tipo_e, r.valor_e, r.fecha_e, r.legaliza, c.categoria
                  FROM recursos_solicitados r
                  JOIN tipos_recursos t     ON t.id = r.tipo
                  JOIN categoria_recursos c ON c.id = r.categoria
                  WHERE r.id_solicitud='".$solicitud["id"]."'";
          $req = $bdd->prepare($sql); $req->execute();
          $recursos = $req->fetchAll();
          $t_presup = []; $t_legaliza = [];

          foreach ($recursos as $recurso):
        ?>
        <tr>
          <td><?= htmlspecialchars($recurso["recurso"]) ?></td>
          <td><?= htmlspecialchars($recurso["tipo"]) ?></td>
          <td><?= htmlspecialchars($recurso["categoria"]) ?></td>
          <td>
            <?php if ($solicitud["idestado"] == 1): ?>
              <input type="text" id="presup<?= $recurso["id"] ?>" value="<?= $recurso["presupuesto"] ?>">
              <input type="hidden" id="i_presup<?= $recurso["id"] ?>" name="i_presup[]">
              <script>
                $('#i_presup<?= $recurso["id"] ?>').val('<?= $recurso["presupuesto"] ?>');
                $('#presup<?= $recurso["id"] ?>').keyup(function(){
                  $('#i_presup<?= $recurso["id"] ?>').val(<?= $recurso["id"] ?>+'/'+parseInt($(this).val()));
                });
              </script>
            <?php else: ?>
              $ <?= number_format($recurso["presupuesto"], 0, ',', '.') ?>
            <?php endif; ?>
          </td>
          <?php
            $can_edit = in_array($_SESSION['tipo'], [1,2,7,9]);
            if ($can_edit):
              if ($solicitud["idestado"] == 2):
                $sql2 = "SELECT id, tipo FROM tipos_recursos WHERE categoria=2 OR categoria=3";
                $req2 = $bdd->prepare($sql2); $req2->execute();
                $tipos_e = $req2->fetchAll();
          ?>
          <td>
            <select id="tipo_e<?= $recurso["id"] ?>">
              <option value="">Seleccionar</option>
              <?php foreach ($tipos_e as $te): ?>
              <option value="<?= $te["id"] ?>" <?= $recurso["tipo_e"]==$te["id"] ? 'selected' : '' ?>><?= htmlspecialchars($te["tipo"]) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <div style="font-size:0.73rem;color:#64748b;margin-bottom:3px">Entregado: $ <?= number_format($recurso["valor_e"], 0, ',', '.') ?></div>
            <input type="number" id="valor_e<?= $recurso["id"] ?>" placeholder="Nueva entrega" style="width:100%">
          </td>
          <td><input type="date" id="fecha_e<?= $recurso["id"] ?>" value="<?= $recurso["fecha_e"] ?>"></td>
          <td></td>
          <?php elseif ($solicitud["idestado"] == 4): ?>
          <?php
                $sql2 = "SELECT id, tipo FROM tipos_recursos WHERE categoria=2 OR categoria=3";
                $req2 = $bdd->prepare($sql2); $req2->execute();
                $tipos_e = $req2->fetchAll();
          ?>
          <td>
            <select id="tipo_e<?= $recurso["id"] ?>">
              <option value="">Seleccionar</option>
              <?php foreach ($tipos_e as $te): ?>
              <option value="<?= $te["id"] ?>" <?= $recurso["tipo_e"]==$te["id"] ? 'selected' : '' ?>><?= htmlspecialchars($te["tipo"]) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <div style="font-size:0.73rem;color:#64748b;margin-bottom:3px">Entregado: $ <?= number_format($recurso["valor_e"], 0, ',', '.') ?></div>
            <input type="number" id="valor_e<?= $recurso["id"] ?>" placeholder="Nueva entrega" style="width:100%">
          </td>
          <td><input type="date" id="fecha_e<?= $recurso["id"] ?>" value="<?= $recurso["fecha_e"] ?>"></td>
          <?php
            $legal_acum = 0;
            try {
              $req_la = $bdd->prepare("SELECT COALESCE(SUM(valor),0) FROM trazabilidad_entregas WHERE id_recurso=? AND tipo='legalizacion'");
              $req_la->execute([$recurso["id"]]);
              $legal_acum = (float)$req_la->fetchColumn();
            } catch (Exception $e) {}
            // Fallback a campo histórico si no hay trazabilidad
            if ($legal_acum <= 0) $legal_acum = (float)($recurso["legaliza"] ?? 0);
          ?>
          <td>
            <div style="font-size:0.73rem;color:#64748b;margin-bottom:3px">Legalizado: $ <?= number_format($legal_acum, 0, ',', '.') ?></div>
            <div style="display:flex;gap:3px;">
              <input type="number" id="legaliza<?= $recurso["id"] ?>" placeholder="Nuevo monto" style="width:50%">
              <input type="date" id="fecha_legal<?= $recurso["id"] ?>" style="width:48%">
            </div>
          </td>
          <?php else: ?>
          <?php
                $sql2 = "SELECT tipo FROM tipos_recursos WHERE id='".$recurso["tipo_e"]."'";
                $req2 = $bdd->prepare($sql2); $req2->execute();
                $tipo_e_row = $req2->fetch() ?: [];
          ?>
          <td><?= htmlspecialchars($tipo_e_row["tipo"] ?? '') ?></td>
          <td>$ <?= number_format($recurso["valor_e"], 0, ',', '.') ?></td>
          <td><?= htmlspecialchars($recurso["fecha_e"]) ?></td>
          <td>$ <?= number_format($recurso["legaliza"], 0, ',', '.') ?></td>
          <?php endif; ?>
          <?php else: ?>
          <?php
            $sql2 = "SELECT tipo FROM tipos_recursos WHERE id='".$recurso["tipo_e"]."'";
            $req2 = $bdd->prepare($sql2); $req2->execute();
            $tipo_e_row = $req2->fetch() ?: [];
          ?>
          <td><?= htmlspecialchars($tipo_e_row["tipo"] ?? '') ?></td>
          <td>$ <?= number_format($recurso["valor_e"], 0, ',', '.') ?></td>
          <td><?= htmlspecialchars($recurso["fecha_e"]) ?></td>
          <td>$ <?= number_format($recurso["legaliza"], 0, ',', '.') ?></td>
          <?php endif; ?>
        </tr>
        <?php
          $t_presup[]   = $recurso["presupuesto"];
          $t_legaliza[] = $recurso["legaliza"];
          $t_valor_e[]  = $recurso["valor_e"];
          echo "<input type='hidden' id='i_legaliza".$recurso["id"]."' name='i_legaliza[]'>";
          echo "<input type='hidden' id='i_entrega".$recurso["id"]."'  name='i_entrega[]'>";
          if ($can_edit && $solicitud["idestado"] == 2):
          echo "<script>
            $('#tipo_e".$recurso["id"]."').change(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
            $('#valor_e".$recurso["id"]."').keyup(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
            $('#fecha_e".$recurso["id"]."').blur(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
          </script>";
          endif;
          if ($can_edit && $solicitud["idestado"] == 4):
          echo "<script>
            $('#legaliza".$recurso["id"]."').keyup(function(){
              $('#i_legaliza".$recurso["id"]."').val(".$recurso["id"]."+'|'+parseInt($(this).val()));
            });
            $('#tipo_e".$recurso["id"]."').change(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
            $('#valor_e".$recurso["id"]."').keyup(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
            $('#fecha_e".$recurso["id"]."').blur(function(){
              $('#i_entrega".$recurso["id"]."').val(".$recurso["id"]."+'|'+$('#tipo_e".$recurso["id"]."').val()+'|'+parseInt($('#valor_e".$recurso["id"]."').val())+'|'+$('#fecha_e".$recurso["id"]."').val());
            });
          </script>";
          endif;
        endforeach;

        // Total legalizado: trazabilidad primero, fallback a campo histórico para estados 2 y 4
        $tot_presup   = array_sum($t_presup);
        $tot_valor_e  = array_sum($t_valor_e ?? []);
        $tot_legaliza = 0;
        try {
          $req_tr = $bdd->prepare(
            "SELECT COALESCE(SUM(valor),0) FROM trazabilidad_entregas WHERE id_solicitud=? AND tipo='legalizacion'"
          );
          $req_tr->execute([$solicitud["id"]]);
          $tot_legaliza = (float)$req_tr->fetchColumn();
        } catch (Exception $e) {}
        if ($tot_legaliza <= 0 && in_array((int)$solicitud['idestado'], [2, 4])) {
          $tot_legaliza = (float)array_sum($t_legaliza);
        }
        // Saldo por entregar = Presupuesto - Total entregado (no contra lo legalizado: lo
        // legalizado ya tiene su propia tarjeta "Total legalizado" más abajo).
        $saldo_pendiente = max($tot_presup - $tot_valor_e, 0);
        ?>
      </tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td></td><td></td>
          <td>$ <?= number_format($tot_presup, 0, ',', '.') ?></td>
          <td></td><td></td><td></td>
          <td>$ <?= number_format($tot_legaliza, 0, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  </div>

  <!-- Resumen de saldos -->
  <?php /* $tot_presup, $tot_valor_e, $tot_legaliza y $saldo_pendiente ya calculados arriba */ ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:20px;">
    <div class="vs-info-card" style="border-left-color:#6366f1">
      <p class="vs-info-label">Presupuesto total</p>
      <p class="vs-info-val">$ <?= number_format($tot_presup, 0, ',', '.') ?></p>
    </div>
    <div class="vs-info-card" style="border-left-color:#1d4ed8">
      <p class="vs-info-label">Total entregado</p>
      <p class="vs-info-val">$ <?= number_format($tot_valor_e, 0, ',', '.') ?></p>
    </div>
    <div class="vs-info-card" style="border-left-color:<?= $saldo_pendiente > 0 ? '#f59e0b' : '#16a34a' ?>">
      <p class="vs-info-label">Saldo por entregar</p>
      <p class="vs-info-val" style="color:<?= $saldo_pendiente > 0 ? '#b45309' : '#16a34a' ?>">$ <?= number_format($saldo_pendiente, 0, ',', '.') ?></p>
    </div>
    <div class="vs-info-card" style="border-left-color:#16a34a">
      <p class="vs-info-label">Total legalizado</p>
      <p class="vs-info-val">$ <?= number_format($tot_legaliza, 0, ',', '.') ?></p>
    </div>
  </div>

  <!-- Botones de acción -->
  <input type="hidden" name="solicitud" value="<?= $solicitud["id"] ?>">

  <div class="vs-actions">
    <?php if ($solicitud["idestado"] == 2 && in_array($_SESSION['tipo'], [1,2,7,9])): ?>
      <button type="button" class="btn btn-primary btn-sm vs-confirm-btn" id="entregar" data-tipo="entregar"><i class="bi bi-truck"></i> Entregar</button>
    <?php endif; ?>

    <?php if (in_array($solicitud["idestado"], [2,4]) && in_array($_SESSION['tipo'], [1,2,7,9])): ?>
      <button class="btn btn-success btn-sm"><i class="bi bi-patch-check"></i> Legalizar</button>
    <?php endif; ?>

    <?php if ($solicitud["idestado"] == 1): ?>
      <button class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> Modificar</button>
    <?php endif; ?>

    <?php if (in_array($_SESSION['tipo'], [1,9]) && $solicitud["contab"] == 0 && $solicitud["idestado"] == 4): ?>
      <a class="btn btn-warning btn-sm" href="php/accion_solicitudes.php?solicitud=<?= $solicitud["id"] ?>&contab=1&cod_colegio=<?= $solicitud["codigo"] ?>&periodo=<?= $solicitud["id_periodo"] ?>">
        <i class="bi bi-calculator"></i> Contabilizar
      </a>
    <?php endif; ?>

    <?php if (($_SESSION['tipo'] == 1) && $solicitud["idestado"] == 4 && $legal_estado !== 'completo'): ?>
      <a class="btn btn-success btn-sm"
         href="php/cerrar_legalizacion.php?solicitud=<?= $solicitud["id"] ?>"
         onclick="return confirm('¿Marcar esta solicitud como legalización completa?')">
        <i class="bi bi-patch-check-fill"></i> Legalización completa
      </a>
    <?php endif; ?>

    <?php if (in_array($_SESSION['tipo'], [1]) || $_SESSION['id']==19): ?>
      <?php if ($solicitud["idestado"] == 1): ?>
        <a class="btn btn-success btn-sm vs-confirm-link" data-tipo="aprobar"
           href="php/accion_solicitudes.php?solicitud=<?= $solicitud["id"] ?>&aprobar=1&cod_colegio=<?= $solicitud["codigo"] ?>&periodo=<?= $solicitud["id_periodo"] ?>">
          <i class="bi bi-check-circle"></i> Aprobar
        </a>
        <a class="btn btn-danger btn-sm vs-confirm-link" data-tipo="rechazar"
           href="php/accion_solicitudes.php?solicitud=<?= $solicitud["id"] ?>&rechazar=1&cod_colegio=<?= $solicitud["codigo"] ?>&periodo=<?= $solicitud["id_periodo"] ?>">
          <i class="bi bi-x-circle"></i> Rechazar
        </a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php echo "</form>"; ?>

  <!-- Historial de trazabilidad -->
  <?php
    $trazabilidad = [];
    try {
      $sql_t = "SELECT te.tipo, te.valor, te.fecha, te.fecha_registro, rs.recurso
                FROM trazabilidad_entregas te
                JOIN recursos_solicitados rs ON rs.id = te.id_recurso
                WHERE te.id_solicitud = ?
                ORDER BY te.fecha_registro ASC";
      $req_t = $bdd->prepare($sql_t);
      $req_t->execute([$solicitud["id"]]);
      $trazabilidad = $req_t->fetchAll();
    } catch (Exception $e) { /* tabla aún no creada */ }
  ?>
  <?php if (!empty($trazabilidad)): ?>
  <p class="vs-section-title" style="margin-top:24px"><i class="bi bi-clock-history"></i> Historial de movimientos</p>
  <div class="vs-table-wrap">
    <table class="vs-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Recurso</th>
          <th>Tipo</th>
          <th>Valor</th>
          <th>Fecha</th>
          <th>Registrado</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $n = 0;
        foreach ($trazabilidad as $t):
          $n++;
      ?>
      <tr>
        <td><?= $n ?></td>
        <td><?= htmlspecialchars($t['recurso']) ?></td>
        <td>
          <?php if ($t['tipo'] === 'entrega'): ?>
            <span style="background:#dbeafe;color:#1e40af;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700">Entrega</span>
          <?php else: ?>
            <span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700">Legalización</span>
          <?php endif; ?>
        </td>
        <td>$ <?= number_format($t['valor'], 0, ',', '.') ?></td>
        <td><?= htmlspecialchars($t['fecha']) ?></td>
        <td style="font-size:.75rem;color:#64748b"><?= htmlspecialchars($t['fecha_registro']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Archivo de legalización -->
  <?php if ($solicitud["idestado"] == 4 && $solicitud["archivo"] == ""): ?>
  <div class="vs-archivo">
    <form action="php/archivo_solicitud.php" method="POST" enctype="multipart/form-data">
      <label class="control-label"><i class="bi bi-paperclip" style="color:#6366f1;margin-right:4px"></i> Archivo de legalización</label>
      <div class="d-flex gap-2 align-items-center mt-2">
        <input type="file" name="archivo" id="archivo" class="form-control form-control-sm" required/>
        <input type="hidden" name="solicitud" value="<?= $solicitud["id"] ?>">
        <button class="btn btn-warning btn-sm" style="white-space:nowrap"><i class="bi bi-upload"></i> Subir</button>
      </div>
    </form>
  </div>
  <?php elseif ($solicitud["archivo"] != ""): ?>
  <?php
    $n_archivo = explode('_', $solicitud["archivo"]);
  ?>
  <div class="vs-archivo">
    <span style="font-size:.82rem;font-weight:600;color:#374151"><i class="bi bi-paperclip" style="color:#6366f1;margin-right:4px"></i> Archivo de legalización:</span>
    <a href="adjuntos_atenc/<?= $solicitud["archivo"] ?>" download="<?= $n_archivo[1] ?>" style="margin-left:8px;font-size:.82rem">
      <?= htmlspecialchars($n_archivo[1]) ?>
    </a>
  </div>
  <?php endif; ?>

  <!-- Contabilizada -->
  <?php if ($solicitud["contab"] == 1): ?>
  <div class="vs-contab"><i class="bi bi-check2-circle"></i> Contabilizada</div>
  <?php endif; ?>

</div><!-- /.vs-wrap -->

</div></div></div>

<!-- Modal de confirmación -->
<div class="modal fade" id="vs-modal-confirm" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 8px 32px rgba(0,0,0,.18)">
      <div class="modal-body text-center" style="padding:32px 28px">
        <div id="mc-icon" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:26px;"></div>
        <h5 id="mc-title" style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:1rem;"></h5>
        <p  id="mc-msg"   style="color:#64748b;font-size:.85rem;margin-bottom:22px;"></p>
        <div style="display:flex;gap:10px;justify-content:center;">
          <button type="button" class="btn btn-light btn-sm" data-dismiss="modal" style="font-weight:600;padding:7px 22px;">Cancelar</button>
          <button type="button" id="mc-confirm" class="btn btn-sm" style="font-weight:600;padding:7px 22px;">Confirmar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script>
  var _pendingAction = null;

  var _modalCfg = {
    aprobar:  { bg: '#dcfce7', color: '#16a34a', icon: '&#10003;', title: '¿Aprobar solicitud?',         msg: 'Esta acción cambiará el estado a Aprobado y notificará al promotor.' },
    rechazar: { bg: '#fee2e2', color: '#dc2626', icon: '&#10007;', title: '¿Rechazar solicitud?',         msg: 'Esta acción cambiará el estado a Rechazado.' },
    entregar: { bg: '#dbeafe', color: '#1d4ed8', icon: '&#128666;', title: '¿Marcar como entregada?',    msg: 'Se registrarán los datos de entrega ingresados.' }
  };

  function abrirModal(tipo, accion) {
    var c = _modalCfg[tipo];
    $('#mc-icon').css({ background: c.bg, color: c.color }).html(c.icon);
    $('#mc-title').text(c.title);
    $('#mc-msg').text(c.msg);
    $('#mc-confirm').css({ background: c.color, color: '#fff', border: 'none' });
    _pendingAction = accion;
    $('#vs-modal-confirm').modal('show');
  }

  // Links Aprobar / Rechazar
  $(document).on('click', '.vs-confirm-link', function (e) {
    e.preventDefault();
    var href = $(this).attr('href');
    var tipo = $(this).data('tipo');
    abrirModal(tipo, function () { window.location.href = href; });
  });

  // Botón Entregar
  $(document).on('click', '.vs-confirm-btn', function (e) {
    e.preventDefault();
    abrirModal('entregar', function () {
      $('#form_a').attr('action', 'php/entregar_solicitud.php').submit();
    });
  });

  // Ejecutar acción al confirmar
  $('#mc-confirm').on('click', function () {
    $('#vs-modal-confirm').modal('hide');
    if (_pendingAction) { _pendingAction(); _pendingAction = null; }
  });

  // Capturar valores actuales de los inputs visibles justo antes de enviar
  $('#form_a').on('submit', function () {
    <?php foreach ($recursos as $recurso): ?>
    (function (id) {
      var $legaliza = $('#legaliza' + id);
      if ($legaliza.length) {
        var fecha_legal = $('#fecha_legal' + id).val() || '';
        $('#i_legaliza' + id).val(id + '|' + (parseInt($legaliza.val()) || 0) + '|' + fecha_legal);
      }
      var $valor_e = $('#valor_e' + id);
      if ($valor_e.length) {
        var tipo_e  = $('#tipo_e'  + id).val() || '';
        var fecha_e = $('#fecha_e' + id).val() || '';
        $('#i_entrega' + id).val(id + '|' + tipo_e + '|' + (parseInt($valor_e.val()) || 0) + '|' + fecha_e);
      }
    })(<?= (int)$recurso['id'] ?>);
    <?php endforeach; ?>
  });

  <?php if (!empty($_GET['updated'])): ?>
  if (window.opener && !window.opener.closed) {
    window.opener.location.reload();
  }
  <?php endif; ?>
</script>
</body>
</html>
