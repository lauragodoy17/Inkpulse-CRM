<?php
  require_once("../php/aut.php");
  include("../conexion/bdd.php");
  require_once("../includes/materializar_atenciones_pendientes.php");

  // Si alguna solicitud distribuida en años (ver php/solicitud_recurso.php) tenía una cuota
  // pendiente para este período, y el período ya existe (por eso se está viendo este tab), se
  // convierte ahora en una solicitud real — sin costo si no hay nada pendiente.
  materializar_atenciones_pendientes($bdd, (int)$_GET['periodo']);

  // ── Métricas para las tarjetas ──────────────────────────────
  $sql_total = "SELECT SUM(r.valor_e) as total FROM solicitudes_recursos s
                JOIN recursos_solicitados r ON s.id=r.id_solicitud
                WHERE s.id_colegio='".$_GET['colegio']."' AND s.id_periodo='".$_GET['periodo']."' AND s.estado='4'";
  $req_total = $bdd->prepare($sql_total); $req_total->execute();
  $total = $req_total->fetch();

  $sql_solic = "SELECT COUNT(*) as cnt FROM solicitudes_recursos
                WHERE id_colegio='".$_GET['colegio']."' AND id_periodo='".$_GET['periodo']."'";
  $req_solic = $bdd->prepare($sql_solic); $req_solic->execute();
  $cnt_solic = (int)$req_solic->fetchColumn();

  $sql_pend = "SELECT COUNT(*) as cnt FROM solicitudes_recursos
               WHERE id_colegio='".$_GET['colegio']."' AND id_periodo='".$_GET['periodo']."' AND estado IN (1,2)";
  $req_pend = $bdd->prepare($sql_pend); $req_pend->execute();
  $cnt_pend = (int)$req_pend->fetchColumn();

  $sql_entregadas = "SELECT COUNT(*) as cnt FROM solicitudes_recursos
                     WHERE id_colegio='".$_GET['colegio']."' AND id_periodo='".$_GET['periodo']."' AND estado=4";
  $req_entregadas = $bdd->prepare($sql_entregadas); $req_entregadas->execute();
  $cnt_entregadas = (int)$req_entregadas->fetchColumn();

  $sql_sin_legal = "SELECT COUNT(*) as cnt FROM solicitudes_recursos s
                    WHERE s.id_colegio='".$_GET['colegio']."' AND s.id_periodo='".$_GET['periodo']."'
                    AND COALESCE((SELECT SUM(r.legaliza) FROM recursos_solicitados r WHERE r.id_solicitud = s.id), 0) = 0
                    AND COALESCE((SELECT SUM(te.valor) FROM trazabilidad_entregas te WHERE te.id_solicitud = s.id AND te.tipo='legalizacion'), 0) = 0
                    AND COALESCE(s.legal_cerrado, 0) = 0";
  $req_sin_legal = $bdd->prepare($sql_sin_legal); $req_sin_legal->execute();
  $cnt_sin_legal = (int)$req_sin_legal->fetchColumn();
?>

<style>
  /* ── Contenedor ──────────────────────────────────────────── */
  .at-wrap { padding: 24px; }

  /* ── Encabezado ──────────────────────────────────────────── */
  .at-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .at-title    { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 2px 0; }
  .at-subtitle { font-size: 0.82rem; color: #718096; margin: 0; }
  .at-actions  { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

  /* ── Tarjetas de resumen ─────────────────────────────────── */
  .at-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }
  .at-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 6px rgba(15,23,42,.08);
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .at-card-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
  }
  .at-card-icon.purple { background: #ede9fe; color: #6d28d9; }
  .at-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
  .at-card-icon.orange { background: #ffedd5; color: #c2410c; }
  .at-card-icon.green  { background: #dcfce7; color: #15803d; }
  .at-card-icon.amber  { background: #fef3c7; color: #b45309; }
  .at-card.has-pending { border-left: 3px solid #f59e0b; }
  .at-card-label { font-size: 0.74rem; color: #64748b; margin: 0 0 2px 0; }
  .at-card-val   { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; word-break: break-all; }
  .at-card > div:last-child { min-width: 0; }

  /* ── Tabla ───────────────────────────────────────────────── */
  .at-table-wrap {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15,23,42,.09);
  }
  #tabla-atenciones {
    width: 100%;
    font-size: 0.83rem;
    border-collapse: collapse;
  }
  #tabla-atenciones thead th {
    background: #f8fafc;
    color: #374151;
    font-weight: 600;
    padding: 11px 12px;
    text-align: left;
    border: none;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
    font-size: 0.80rem;
  }
  #tabla-atenciones tbody tr { background: #fff; }
  #tabla-atenciones tbody tr:nth-child(even) { background: #f8fafc; }
  #tabla-atenciones tbody tr:hover { background: #eff6ff; cursor: pointer; }
  #tabla-atenciones tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    color: #1e293b;
    vertical-align: middle;
  }

  /* Enlace # de solicitud */
  .at-link {
    font-weight: 700;
    color: #4f46e5;
    text-decoration: none;
  }
  .at-link:hover { text-decoration: underline; }

  /* Badges de estado */
  .at-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
  }
  .at-badge.entregado  { background: #dcfce7; color: #15803d; }
  .at-badge.pendiente  { background: #fef3c7; color: #92400e; }
  .at-badge.enviado    { background: #dbeafe; color: #1d4ed8; }
  .at-badge.aprobado   { background: #ede9fe; color: #6d28d9; }
  .at-badge.rechazado  { background: #fee2e2; color: #b91c1c; }
  .at-badge.default    { background: #f1f5f9; color: #64748b; }

  /* ── Toast ──────────────────────────────────────────────── */
  .at-toast {
    position: fixed; top: 24px; right: 24px; min-width: 260px;
    padding: 14px 20px; border-radius: 10px; font-size: 0.87rem;
    font-weight: 600; color: #fff; z-index: 99999;
    box-shadow: 0 6px 20px rgba(0,0,0,.18);
    display: flex; align-items: center; gap: 10px;
    opacity: 0; transform: translateY(-16px);
    transition: opacity .3s, transform .3s; pointer-events: none;
  }
  .at-toast.show  { opacity: 1; transform: translateY(0); }
  .at-toast.ok    { background: #16a34a; }
  .at-toast.error { background: #dc2626; }
  .at-toast i { font-size: 1.1rem; }

  /* ── Panel resumen inline ────────────────────────────────── */
  .at-panel {
    margin-top: 20px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15,23,42,.10);
    display: none;
  }
  .at-panel-header {
    background: #0f172a;
    color: #e2e8f0;
    padding: 12px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.88rem;
    font-weight: 600;
  }
  .at-panel-close {
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 1.1rem;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
  }
  .at-panel-close:hover { color: #fff; }
  #at-iframe {
    width: 100%;
    border: none;
    display: block;
    min-height: 500px;
  }

  /* ── Badge de legalización ──────────────────────────────── */
  .at-legal-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px; font-size: 0.73rem; font-weight: 700; white-space: nowrap;
  }
  .at-legal-badge.ok      { background: #dcfce7; color: #15803d; }
  .at-legal-badge.pending { background: #fef3c7; color: #92400e; }
  .at-legal-badge.proceso { background: #fef3c7; color: #92400e; }

  /* ── Botón filtro sin legalizar ─────────────────────────── */
  .at-filter-legal {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 13px; border-radius: 20px; border: 1.5px solid #f59e0b;
    background: #fff; color: #b45309; font-size: 12.5px; font-weight: 600;
    cursor: pointer; transition: all .15s; white-space: nowrap;
  }
  .at-filter-legal:hover,
  .at-filter-legal.active { background: #f59e0b; border-color: #f59e0b; color: #fff; }
</style>

<div class="at-wrap">

  <!-- Encabezado -->
  <div class="at-header">
    <div>
      <h5 class="at-title"><i class="bi bi-headset" style="color:#6366f1;margin-right:6px"></i> Solicitud de recursos</h5>
      <p class="at-subtitle">Gestiona las solicitudes de atención y entrega de recursos del colegio</p>
    </div>
    <div class="at-actions">
      <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal_atenciones">
        <i class="bi bi-plus-circle"></i> Nueva solicitud
      </a>
    </div>
  </div>

  <!-- Tarjetas de resumen -->
  <div class="at-cards">
    <div class="at-card">
      <div class="at-card-icon purple"><i class="bi bi-cash-stack"></i></div>
      <div>
        <p class="at-card-label">Recurso entregado</p>
        <p class="at-card-val">$&nbsp;<?= number_format($total['total'] ?? 0, 0, ',', '.') ?></p>
      </div>
    </div>
    <div class="at-card">
      <div class="at-card-icon blue"><i class="bi bi-file-earmark-text"></i></div>
      <div>
        <p class="at-card-label">Solicitudes</p>
        <p class="at-card-val"><?= $cnt_solic ?></p>
      </div>
    </div>
    <div class="at-card">
      <div class="at-card-icon orange"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <p class="at-card-label">Por entregar</p>
        <p class="at-card-val"><?= $cnt_pend ?></p>
      </div>
    </div>
    <div class="at-card">
      <div class="at-card-icon green"><i class="bi bi-check2-all"></i></div>
      <div>
        <p class="at-card-label">Entregadas</p>
        <p class="at-card-val"><?= $cnt_entregadas ?></p>
      </div>
    </div>
    <div class="at-card <?= $cnt_sin_legal > 0 ? 'has-pending' : '' ?>">
      <div class="at-card-icon amber"><i class="bi bi-exclamation-circle-fill"></i></div>
      <div>
        <p class="at-card-label">Sin legalizar</p>
        <p class="at-card-val"><?= $cnt_sin_legal ?></p>
      </div>
    </div>
  </div>

  <?php if ($cnt_sin_legal > 0): ?>
  <div style="margin-bottom:16px;padding:10px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <span style="font-size:.83rem;font-weight:600;color:#92400e;">
      <i class="bi bi-exclamation-triangle-fill" style="margin-right:6px"></i>
      <?= $cnt_sin_legal ?> solicitud<?= $cnt_sin_legal > 1 ? 'es' : '' ?> sin legalizar en este periodo
    </span>
    <button class="at-filter-legal" id="at-filter-legal">
      <i class="bi bi-funnel-fill"></i> Ver solo sin legalizar
    </button>
  </div>
  <?php endif; ?>

  <!-- Modal nueva solicitud -->
  <style>
    .at-modal .modal-content  { border: none; border-radius: 14px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.18); }
    .at-modal .modal-header   { background: #0f172a; padding: 16px 22px; border-bottom: none; }
    .at-modal .modal-title    { font-size: 15px; font-weight: 700; color: #f1f5f9; display: flex; align-items: center; gap: 8px; margin: 0; }
    .at-modal .modal-title i  { color: #818cf8; font-size: 18px; }
    .at-modal .close          { color: #94a3b8; opacity: 1; text-shadow: none; font-size: 1.4rem; }
    .at-modal .close:hover    { color: #fff; }
    .at-modal .modal-body     { padding: 22px 24px; background: #f8fafc; }
    .at-modal .modal-footer   { border-top: 1px solid #e2e8f0; padding: 14px 22px; background: #fff; }

    /* Secciones internas */
    .at-section {
      background: #fff;
      border-radius: 10px;
      padding: 18px 20px;
      margin-bottom: 16px;
      border: 1px solid #e9ecef;
    }
    .at-section-title {
      font-size: 12.5px; font-weight: 700; color: #374151;
      display: flex; align-items: center; gap: 6px;
      margin: 0 0 14px 0; text-transform: uppercase; letter-spacing: .04em;
    }
    .at-section-title i { color: #6366f1; font-size: 14px; }

    /* Labels y controles */
    .at-modal .form-group label {
      font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block;
    }
    .at-modal .form-control {
      border-radius: 8px; font-size: 13px; border: 1.5px solid #d1d5db;
      padding: 7px 10px; background: #f9fafb; color: #111827;
      transition: border-color .15s, box-shadow .15s;
    }
    .at-modal .form-control:focus {
      border-color: #6366f1; background: #fff;
      box-shadow: 0 0 0 3px rgba(99,102,241,.12); outline: none;
    }

    /* Fila de área / recurso */
    .at-row-item {
      background: #f8faff;
      border: 1px solid #e0e7ff;
      border-radius: 9px;
      padding: 14px 16px;
      margin-bottom: 10px;
      position: relative;
    }
    .at-row-num {
      font-size: 11px; font-weight: 700; color: #6366f1;
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px;
    }

    /* Botón agregar más */
    .at-add-more {
      display: inline-flex; align-items: center; gap: 5px;
      color: #6366f1; font-size: 13px; font-weight: 600;
      cursor: pointer; padding: 4px 0; border: none; background: none;
    }
    .at-add-more:hover { text-decoration: underline; }

    /* Requerido */
    .at-req { color: #ef4444; margin-left: 2px; }

    /* Botón guardar */
    .at-modal .btn-primary {
      background: #6366f1; border-color: #6366f1;
      border-radius: 8px; padding: 8px 26px; font-weight: 600; font-size: 14px;
    }
    .at-modal .btn-primary:hover { background: #4f46e5; border-color: #4f46e5; }
    .at-modal .btn-light { border-radius: 8px; }
  </style>

  <div class="modal fade at-modal" id="modal_atenciones" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-headset"></i> Nueva solicitud de atención</h5>
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
        </div>

        <form action="php/solicitud_recurso.php" method="POST" enctype="multipart/form-data">
        <div class="modal-body">

          <!-- Solicitante -->
          <div class="at-section">
            <p class="at-section-title"><i class="bi bi-person-badge"></i> Solicitante</p>
            <div class="row">
              <div class="form-group col-sm-5 col-12">
                <label for="solicitante">Solicitante del colegio <span class="at-req">*</span></label>
                <select name="solicitante" id="solicitante" class="form-control" required>
                  <option value="">Seleccionar persona...</option>
                  <?php
                    $sql = "SELECT t.id, CONCAT(nombre, ' ', apellido) as trabajador, c.cargo FROM trabajadores_colegios t JOIN cargos c ON t.cargo=c.id WHERE t.telefono !='' AND id_colegio='{$_GET['colegio']}'";
                    $req = $bdd->prepare($sql); $req->execute();
                    foreach ($req->fetchAll() as $t)
                      echo '<option value="'.$t['id'].'">'.$t['trabajador'].' ('.$t['cargo'].')</option>';
                  ?>
                </select>
              </div>
              <div class="form-group col-sm-3 col-6">
                <label for="fecha_entrega">Fecha de entrega <span class="at-req">*</span></label>
                <input type="date" class="form-control" name="fecha_entrega" id="fecha_entrega" required>
              </div>
              <div class="form-group col-sm-2 col-6">
                <label for="reintegro">Reintegro</label>
                <input type="text" class="form-control" name="reintegro" id="reintegro" autocomplete="off" placeholder="Opcional">
              </div>
              <div class="form-group col-sm-2 col-6">
                <label for="distribucion_anios">Distribución en años <span class="at-req">*</span></label>
                <select name="distribucion_anios" id="distribucion_anios" class="form-control" required>
                  <option value="1" selected>1 año</option>
                  <option value="2">2 años</option>
                  <option value="3">3 años</option>
                  <option value="4">4 años</option>
                </select>
              </div>
            </div>
            <p style="font-size:11.5px;color:#6b7280;margin:8px 0 0;">
              <i class="bi bi-info-circle"></i>
              Si eliges más de 1 año, el presupuesto de cada recurso solicitado se reparte en partes iguales
              entre este período y los siguientes años del mismo calendario. Los años futuros quedan
              reservados y aparecen solos en su reporte de atenciones cuando ese período llegue a existir
              (no hace falta crearlo de antemano).
            </p>
          </div>

          <!-- Áreas comprometidas -->
          <div class="at-section">
            <p class="at-section-title"><i class="bi bi-grid-3x3-gap"></i> Áreas comprometidas</p>

            <div class="otro_area">
              <div class="at-row-item">
                <p class="at-row-num">Área #1</p>
                <div class="row">
                  <div class="form-group col-sm-3 col-12">
                    <label for="materia_at">Materia <span class="at-req">*</span></label>
                    <select name="materia[]" id="materia_at" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $m)
                          echo '<option value="'.$m['id'].'">'.$m['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label for="preescolar_at">Preescolar</label>
                    <input type="number" class="form-control" name="preescolar" id="preescolar_at" autocomplete="off" placeholder="0">
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label for="primaria_at">Primaria</label>
                    <input type="number" class="form-control" name="primaria" id="primaria_at" autocomplete="off" placeholder="0">
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label for="bachillerato_at">Bachillerato</label>
                    <input type="number" class="form-control" name="bachillerato" id="bachillerato_at" autocomplete="off" placeholder="0">
                  </div>
                </div>
              </div>
            </div>
            <input type="hidden" name="areas_r[]" id="areas_r">

            <?php for ($i=1; $i < 10; $i++): ?>
            <div id="agg_area<?= $i ?>" class="d-none">
              <div class="at-row-item">
                <p class="at-row-num">Área #<?= $i+1 ?></p>
                <div class="row">
                  <div class="form-group col-sm-3 col-12">
                    <label>Materia <span class="at-req">*</span></label>
                    <select name="materia[]" id="materia_at<?= $i ?>" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $m)
                          echo '<option value="'.$m['id'].'">'.$m['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label>Preescolar</label>
                    <input type="number" class="form-control" name="preescolar" id="preescolar_at<?= $i ?>" autocomplete="off" placeholder="0">
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label>Primaria</label>
                    <input type="number" class="form-control" name="primaria_at" id="primaria_at<?= $i ?>" autocomplete="off" placeholder="0">
                  </div>
                  <div class="form-group col-sm-3 col-4">
                    <label>Bachillerato</label>
                    <input type="number" class="form-control" name="bachillerato" id="bachillerato_at<?= $i ?>" autocomplete="off" placeholder="0">
                  </div>
                </div>
              </div>
            </div>
            <input type="hidden" name="areas_r[]" id="areas_r<?= $i ?>">
            <?php endfor; ?>

            <button type="button" class="at-add-more" id="agregar_area">
              <i class="bi bi-plus-circle"></i> Agregar área
            </button>
          </div>

          <!-- Recursos solicitados -->
          <div class="at-section">
            <p class="at-section-title"><i class="bi bi-box-seam"></i> Recursos solicitados</p>

            <div class="at-row-item">
              <p class="at-row-num">Recurso #1</p>
              <div class="row">
                <div class="form-group col-sm-3 col-12">
                  <label for="recurso_at">Descripción del recurso <span class="at-req">*</span></label>
                  <input type="text" class="form-control" name="recurso" id="recurso_at" autocomplete="off" placeholder="Ej. Marcadores, papelería...">
                </div>
                <div class="form-group col-sm-3 col-12">
                  <label for="tipo_at">Tipo <span class="at-req">*</span></label>
                  <select name="tipo_at[]" id="tipo_at" class="form-control" required>
                    <option value="">Seleccionar</option>
                    <?php
                      $sql = "SELECT id, tipo FROM tipos_recursos WHERE categoria=1 OR categoria=3";
                      $req = $bdd->prepare($sql); $req->execute();
                      foreach ($req->fetchAll() as $t)
                        echo '<option value="'.$t['id'].'">'.$t['tipo'].'</option>';
                    ?>
                  </select>
                </div>
                <div class="form-group col-sm-3 col-12">
                  <label for="categoria">Categoría <span class="at-req">*</span></label>
                  <select name="categoria[]" id="categoria" class="form-control" required>
                    <option value="">Seleccionar</option>
                    <?php
                      $sql = "SELECT id, categoria FROM categoria_recursos";
                      $req = $bdd->prepare($sql); $req->execute();
                      foreach ($req->fetchAll() as $c)
                        echo '<option value="'.$c['id'].'">'.$c['categoria'].'</option>';
                    ?>
                  </select>
                </div>
                <div class="form-group col-sm-3 col-12">
                  <label for="presupuesto_at">Presupuesto <span class="at-req">*</span></label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text" style="border-radius:8px 0 0 8px;font-size:13px;border:1.5px solid #d1d5db;border-right:none;background:#f1f5f9">$</span></div>
                    <input type="number" class="form-control" name="primaria" id="presupuesto_at" autocomplete="off" placeholder="0" style="border-radius:0 8px 8px 0">
                  </div>
                </div>
              </div>
            </div>
            <input type="hidden" name="recursos[]" id="recursos">

            <?php for ($i=1; $i < 10; $i++): ?>
            <div id="agg_recurso<?= $i ?>" class="d-none">
              <div class="at-row-item">
                <p class="at-row-num">Recurso #<?= $i+1 ?></p>
                <div class="row">
                  <div class="form-group col-sm-3 col-12">
                    <label>Descripción del recurso <span class="at-req">*</span></label>
                    <input type="text" class="form-control" name="recurso" id="recurso_at<?= $i ?>" autocomplete="off" placeholder="Ej. Marcadores, papelería...">
                  </div>
                  <div class="form-group col-sm-3 col-12">
                    <label>Tipo <span class="at-req">*</span></label>
                    <select name="materia[]" id="tipo_at<?= $i ?>" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, tipo FROM tipos_recursos WHERE categoria=1 OR categoria=3";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $t)
                          echo '<option value="'.$t['id'].'">'.$t['tipo'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-3 col-12">
                    <label>Categoría <span class="at-req">*</span></label>
                    <select name="categoria[]" id="categoria<?= $i ?>" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, categoria FROM categoria_recursos";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $c)
                          echo '<option value="'.$c['id'].'">'.$c['categoria'].'</option>';
                      ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-3 col-12">
                    <label>Presupuesto <span class="at-req">*</span></label>
                    <div class="input-group">
                      <div class="input-group-prepend"><span class="input-group-text" style="border-radius:8px 0 0 8px;font-size:13px;border:1.5px solid #d1d5db;border-right:none;background:#f1f5f9">$</span></div>
                      <input type="number" class="form-control" name="primaria" id="presupuesto_at<?= $i ?>" autocomplete="off" placeholder="0" style="border-radius:0 8px 8px 0">
                    </div>
                  </div>
                </div>
              </div>
              <input type="hidden" name="recursos[]" id="recursos<?= $i ?>">
            </div>
            <?php endfor; ?>

            <button type="button" class="at-add-more" id="agregar_recurso">
              <i class="bi bi-plus-circle"></i> Agregar recurso
            </button>
          </div>

          <input type="hidden" name="id_colegio"  value="<?= $_GET['colegio'] ?>">
          <input type="hidden" name="periodo"     value="<?= $_GET['periodo'] ?>">
          <input type="hidden" name="cod_colegio" value="<?= $_GET['codigo']  ?>">

        </div><!-- /.modal-body -->

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancelar</button>
          <?php if ($_SESSION["tipo"] != 4): ?>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Enviar solicitud</button>
          <?php endif; ?>
        </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Tabla de solicitudes -->
  <div class="at-table-wrap">
    <table id="tabla-atenciones">
      <thead>
        <tr>
          <th>#</th>
          <th>Fecha</th>
          <th>Solicitante (Cargo)</th>
          <th>Fecha de entrega</th>
          <th>Valor de la solicitud</th>
          <th>Estado</th>
          <th>Legalización</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $sql = "SELECT e.estado, e.id as id_estado, s.id, s.fecha,
                         CONCAT(t.nombre, ' ', t.apellido) as solicitante,
                         c.cargo, s.fecha_entrega, s.conse,
                         (SELECT SUM(r.legaliza) FROM recursos_solicitados r WHERE r.id_solicitud = s.id) as total_legaliza
                  FROM solicitudes_recursos s
                  JOIN estados_pedidos e  ON e.id = s.estado
                  LEFT JOIN trabajadores_colegios t ON s.solicitante = t.id
                  LEFT JOIN cargos c ON c.id = t.cargo
                  WHERE s.id_colegio='".$_GET['colegio']."' AND s.id_periodo='".$_GET['periodo']."'
                  ORDER BY s.id DESC";
          $req = $bdd->prepare($sql); $req->execute();
          $solicitudes = $req->fetchAll();

          foreach ($solicitudes as $sol):
            $sql2 = "SELECT SUM(presupuesto) as total FROM recursos_solicitados WHERE id_solicitud='".$sol['id']."'";
            $req2 = $bdd->prepare($sql2); $req2->execute();
            $tot2 = $req2->fetch();

            $num = ($sol['id'] < 221) ? $sol['id'] : $sol['conse'];

            // Color del badge según estado
            $estado_lower = strtolower($sol['estado']);
            if (strpos($estado_lower, 'entregad') !== false)     $badge = 'entregado';
            elseif (strpos($estado_lower, 'pendiente') !== false) $badge = 'pendiente';
            elseif (strpos($estado_lower, 'enviado') !== false)   $badge = 'enviado';
            elseif (strpos($estado_lower, 'aprobad') !== false)   $badge = 'aprobado';
            elseif (strpos($estado_lower, 'rechazad') !== false)  $badge = 'rechazado';
            else                                                   $badge = 'default';
        ?>
        <?php
          // Estado de legalización: trazabilidad primero, fallback a campo histórico para estados 2 y 4
          $tot_legal_sol  = 0;
          $tot_presup_sol = (float)($tot2['total'] ?? 0);
          try {
            $req_ls = $bdd->prepare("SELECT COALESCE(SUM(valor),0) FROM trazabilidad_entregas WHERE id_solicitud=? AND tipo='legalizacion'");
            $req_ls->execute([$sol['id']]);
            $tot_legal_sol = (float)$req_ls->fetchColumn();
          } catch (Exception $e) {}
          if ($tot_legal_sol <= 0 && in_array((int)$sol['id_estado'], [2, 4])) {
            $old_leg = (float)($sol['total_legaliza'] ?? 0);
            if ($old_leg > 0) $tot_legal_sol = $old_leg;
          }
          $legal_cerrado_sol = 0;
          try {
            $req_lc = $bdd->prepare("SELECT legal_cerrado FROM solicitudes_recursos WHERE id=?");
            $req_lc->execute([$sol['id']]);
            $legal_cerrado_sol = (int)$req_lc->fetchColumn();
          } catch (Exception $e) {}
          if ($legal_cerrado_sol)                    $legal_state = 'completo';
          elseif ($tot_legal_sol <= 0)               $legal_state = 'none';
          elseif ($tot_legal_sol < $tot_presup_sol)  $legal_state = 'proceso';
          else                                       $legal_state = 'completo';
          $es_legal = ($legal_state !== 'none');
        ?>
        <tr data-legal="<?= $es_legal ? '1' : '0' ?>">
          <td><a href="vista_solicitud.php?id=<?= $sol['id'] ?>" class="at-link vista_soli"><?= htmlspecialchars($num) ?></a></td>
          <td><?= htmlspecialchars($sol['fecha']) ?></td>
          <td><?= htmlspecialchars($sol['solicitante'].' ('.$sol['cargo'].')') ?></td>
          <td><?= htmlspecialchars($sol['fecha_entrega']) ?></td>
          <td>$ <?= number_format($tot2['total'], 0, ',', '.') ?></td>
          <td><span class="at-badge <?= $badge ?>"><?= htmlspecialchars($sol['estado']) ?></span></td>
          <td>
            <?php if ($legal_state === 'proceso'): ?>
              <span class="at-legal-badge proceso"><i class="bi bi-hourglass-split"></i> En proceso de legalización</span>
            <?php elseif ($legal_state === 'completo'): ?>
              <span class="at-legal-badge ok"><i class="bi bi-patch-check-fill"></i> Legalización completa</span>
            <?php else: ?>
              <span class="at-legal-badge pending"><i class="bi bi-exclamation-circle-fill"></i> Sin legalizar</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="vista_solicitud.php?id=<?= $sol['id'] ?>" class="btn btn-sm btn-outline-primary vista_soli" style="font-size:.75rem">
              <i class="bi bi-eye"></i> Ver resumen
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($solicitudes)): ?>
        <tr><td colspan="7" class="tbl-empty"><i class="bi bi-inbox"></i>No hay información para mostrar</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Panel resumen inline -->
  <div class="at-panel" id="at-panel">
    <div class="at-panel-header">
      <span><i class="bi bi-file-earmark-text" style="margin-right:6px"></i> <span id="at-panel-title">Resumen de la solicitud</span></span>
      <button class="at-panel-close" id="at-panel-close" title="Cerrar">×</button>
    </div>
    <iframe id="at-iframe" src=""></iframe>
  </div>

  <!-- Panel resumen inline sigue aquí -->
</div><!-- /.at-wrap -->

<!-- Toast notificación -->
<div class="at-toast" id="at-toast">
  <i class="bi bi-check-circle-fill"></i>
  <span id="at-toast-msg"></span>
</div>

<script>
  // ── Select2 dentro del modal (evita que el modal se cierre al seleccionar) ──
  $('#modal_atenciones').on('shown.bs.modal', function() {
    if ($.fn.select2) {
      if ($('#solicitante').data('select2')) {
        $('#solicitante').select2('destroy');
      }
      $('#solicitante').select2({
        dropdownParent: $('#modal_atenciones'),
        placeholder: 'Seleccionar persona...',
        width: '100%'
      });
    }
  });

  // ── Campos ocultos del modal (lógica original intacta) ────────
  $('#materia_at').change(function(){
    var m=$('#materia_at').val(), pre=$('#preescolar_at').val(), pri=$('#primaria_at').val(), bac=$('#bachillerato_at').val();
    $('#areas_r').val(m+'/'+pre+'/'+pri+'/'+bac);
  });
  $('#preescolar_at, #primaria_at, #bachillerato_at').keyup(function(){
    var m=$('#materia_at').val(), pre=$('#preescolar_at').val(), pri=$('#primaria_at').val(), bac=$('#bachillerato_at').val();
    $('#areas_r').val(m+'/'+pre+'/'+pri+'/'+bac);
  });

  var m = 1;
  $("#agregar_area").click(function(){
    if (m>8) { $("#agregar_area").addClass("d-none"); }
    $("#agg_area"+m).removeClass("d-none");
    m++;
    <?php for ($i=1; $i < 10; $i++): ?>
      $('#materia_at<?= $i ?>').change(function(){
        var mat=$('#materia_at<?= $i ?>').val(), pre=$('#preescolar_at<?= $i ?>').val(), pri=$('#primaria_at<?= $i ?>').val(), bac=$('#bachillerato_at<?= $i ?>').val();
        $('#areas_r<?= $i ?>').val(mat+'/'+pre+'/'+pri+'/'+bac);
      });
      $('#preescolar_at<?= $i ?>, #primaria_at<?= $i ?>, #bachillerato_at<?= $i ?>').keyup(function(){
        var mat=$('#materia_at<?= $i ?>').val(), pre=$('#preescolar_at<?= $i ?>').val(), pri=$('#primaria_at<?= $i ?>').val(), bac=$('#bachillerato_at<?= $i ?>').val();
        $('#areas_r<?= $i ?>').val(mat+'/'+pre+'/'+pri+'/'+bac);
      });
    <?php endfor; ?>
  });

  $('#recurso_at, #presupuesto_at').keyup(function(){
    var r=$('#recurso_at').val(), t=$('#tipo_at').val(), c=$('#categoria').val(), p=$('#presupuesto_at').val();
    $('#recursos').val(r+'/'+t+'/'+c+'/'+p);
  });
  $('#tipo_at, #categoria').change(function(){
    var r=$('#recurso_at').val(), t=$('#tipo_at').val(), c=$('#categoria').val(), p=$('#presupuesto_at').val();
    $('#recursos').val(r+'/'+t+'/'+c+'/'+p);
  });

  $("#agregar_recurso").click(function(){
    if (m>8) { $("#agregar_recurso").addClass("d-none"); }
    $("#agg_recurso"+m).removeClass("d-none");
    m++;
    <?php for ($i=1; $i < 10; $i++): ?>
      $('#recurso_at<?= $i ?>, #presupuesto_at<?= $i ?>').keyup(function(){
        var r=$('#recurso_at<?= $i ?>').val(), t=$('#tipo_at<?= $i ?>').val(), c=$('#categoria<?= $i ?>').val(), p=$('#presupuesto_at<?= $i ?>').val();
        $('#recursos<?= $i ?>').val(r+'/'+t+'/'+c+'/'+p);
      });
      $('#tipo_at<?= $i ?>').change(function(){
        var r=$('#recurso_at<?= $i ?>').val(), t=$('#tipo_at<?= $i ?>').val(), c=$('#categoria<?= $i ?>').val(), p=$('#presupuesto_at<?= $i ?>').val();
        $('#recursos<?= $i ?>').val(r+'/'+t+'/'+c+'/'+p);
      });
    <?php endfor; ?>
  });

  // ── Toast ────────────────────────────────────────────────────
  function atToast(msg, tipo) {
    var $t = $('#at-toast');
    $t.removeClass('ok error').addClass(tipo);
    $('#at-toast-msg').text(msg);
    $t.find('i').attr('class', tipo === 'ok' ? 'bi bi-check-circle-fill' : 'bi bi-x-circle-fill');
    $t.addClass('show');
    setTimeout(function(){ $t.removeClass('show'); }, 3500);
  }

  // Mostrar toast si se acaba de guardar
  if (sessionStorage.getItem('at_saved')) {
    sessionStorage.removeItem('at_saved');
    atToast('Solicitud guardada correctamente', 'ok');
  }
  if (sessionStorage.getItem('at_error')) {
    sessionStorage.removeItem('at_error');
    atToast('Ocurrió un error al guardar la solicitud', 'error');
  }

  // Marcar antes de enviar el formulario
  $('form[action="php/solicitud_recurso.php"]').on('submit', function() {
    sessionStorage.setItem('at_saved', '1');
  });

  // ── Abrir resumen inline (reemplaza window.open) ──────────────
  $(document).on('click', '.vista_soli', function(e) {
    e.preventDefault();
    var url  = $(this).attr('href');
    var num  = $(this).closest('tr').find('.at-link').text();
    $('#at-panel-title').text('Resumen de la solicitud #' + num);
    $('#at-iframe').attr('src', url);
    $('#at-panel').fadeIn(250);
    $('html, body').animate({ scrollTop: $('#at-panel').offset().top - 20 }, 400);
  });

  $('#at-panel-close').on('click', function() {
    $('#at-panel').fadeOut(200, function() {
      $('#at-iframe').attr('src', '');
    });
  });

  // ── Filtro sin legalizar ─────────────────────────────────────
  var atLegalFilter = 'all';
  $('#at-filter-legal').on('click', function() {
    if (atLegalFilter === 'all') {
      atLegalFilter = '0';
      $(this).addClass('active').html('<i class="bi bi-x-circle"></i> Mostrando sin legalizar');
    } else {
      atLegalFilter = 'all';
      $(this).removeClass('active').html('<i class="bi bi-funnel-fill"></i> Ver solo sin legalizar');
    }
    $('#tabla-atenciones tbody tr').each(function() {
      if (atLegalFilter === 'all') {
        $(this).show();
      } else {
        $(this).toggle($(this).data('legal') == atLegalFilter);
      }
    });
  });
</script>
