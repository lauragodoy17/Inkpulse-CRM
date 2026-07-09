<?php
	/*ini_set("display_errors", 1);

	ini_set("display_startup_errors", 1);

	error_reporting(E_ALL);*/

	require_once("../php/aut.php");
  	include("../conexion/bdd.php");

	$sql_periodo="SELECT * FROM periodos WHERE id='".$_GET['periodo']."'";

	$req_periodo = $bdd->prepare($sql_periodo);
	$req_periodo->execute();
	$gp_periodo = $req_periodo->fetch();

	$sql_hp = "SELECT id FROM presupuestos WHERE id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";
	$req_hp = $bdd->prepare($sql_hp);
	$req_hp->execute();
	$num_hp = $req_hp->rowCount();

	$show_guardar = ($num_hp >= 1 && $_SESSION["tipo"] != 4) &&
		(!($_SESSION['tipo'] == 3 && $_SESSION["zona"] != '5656') || $_GET["f_cierre"] > date("Y-m-d"));

	$sql_costo_ia = "SELECT mt.id AS id_modelo_tokens, COALESCE(mt.valor_entrada * mt.tokens_entrada + mt.valor_salida * mt.tokens_salida, 0) AS costo_ia, mt.costo_almacenamiento
	                  FROM ia_modelos m
	                  JOIN ia_modelo_tokens mt ON mt.id_modelo = m.id
	                  WHERE m.activo = 1
	                  ORDER BY mt.id DESC LIMIT 1";
	$modelo_activo = $bdd->query($sql_costo_ia)->fetch();
	$costo_ia = $modelo_activo['costo_ia'] ?? 0;

	try { $bdd->exec("ALTER TABLE ia_trm ADD COLUMN id_periodo INT NULL"); } catch (Exception $e) {}
	$req_trm = $bdd->prepare("SELECT trm FROM ia_trm WHERE id_periodo = ? ORDER BY fecha DESC, id DESC LIMIT 1");
	$req_trm->execute([$_GET['periodo']]);
	$trm_row = $req_trm->fetch() ?: $bdd->query("SELECT trm FROM ia_trm ORDER BY fecha DESC, id DESC LIMIT 1")->fetch();
	$trm_actual = $trm_row['trm'] ?? 0;
	$costo_ia_cop = $costo_ia * $trm_actual;

	$req_cant_profes = $bdd->prepare("SELECT COUNT(*) AS total FROM trabajadores_colegios WHERE id_colegio=? AND cargo=6 AND activo=1");
	$req_cant_profes->execute([$_GET['colegio']]);
	$cantidad_profesores = $req_cant_profes->fetch()['total'];

	$req_interacciones = $bdd->prepare("SELECT interacciones FROM ia_presupuestos WHERE id_modelo_tokens=? AND id_periodo=? ORDER BY id DESC LIMIT 1");
	$req_interacciones->execute([$modelo_activo['id_modelo_tokens'] ?? 0, $_GET['periodo']]);
	$interacciones = $req_interacciones->fetch()['interacciones'] ?? 0;

	$costo_ia_semanal = $costo_ia_cop * $interacciones * $cantidad_profesores;
	$costo_almacenamiento_anual = $modelo_activo['costo_almacenamiento'] ?? 0;
	$costo_almacenamiento_semanal = $costo_almacenamiento_anual / 53;

	$costo_semanal = $costo_ia_semanal + $costo_almacenamiento_semanal;
	$costo_anual = ($costo_ia_semanal * 53) + $costo_almacenamiento_anual;
?>

<style>
  /* ── Contenedor ───────────────────────────────────────────── */
  .ad-wrap { padding: 24px; }

  /* ── Encabezado ───────────────────────────────────────────── */
  .ad-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .ad-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 2px 0;
  }
  .ad-title i { color: #6c63ff; margin-right: 6px; }
  .ad-subtitle { font-size: 0.82rem; color: #718096; margin: 0; }
  .ad-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

  /* ── Tarjetas de resumen ──────────────────────────────────── */
  .ad-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }

  /* ── Sección de tarjetas de IA (separada visualmente) ────── */
  .ad-ia-section {
    background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
    border: 1.5px solid #ddd6fe;
    border-radius: 12px;
    padding: 16px 18px 4px;
    margin-bottom: 22px;
  }
  .ad-ia-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
  }
  .ad-ia-header i { color: #6d28d9; font-size: 1rem; }
  .ad-ia-header span { font-size: 0.86rem; font-weight: 700; color: #4c1d95; }
  .ad-ia-section .ad-cards {
    margin-bottom: 0;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  }
  .ad-ia-section .ad-card {
    background: #fff;
    min-width: 0;
    padding: 14px 12px;
  }
  .ad-ia-section .ad-card-icon { width: 36px; height: 36px; font-size: 1rem; }
  .ad-ia-section .ad-card-val { font-size: 1rem; }

  .ad-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 6px rgba(15,23,42,.08);
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
  }
  .ad-card > div { min-width: 0; }
  .ad-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
  }
  .ad-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
  .ad-card-icon.green  { background: #dcfce7; color: #15803d; }
  .ad-card-icon.orange { background: #ffedd5; color: #c2410c; }
  .ad-card-icon.purple { background: #ede9fe; color: #6d28d9; }
  .ad-card-icon.teal   { background: #ccfbf1; color: #0f766e; }
  .ad-card-icon.rose   { background: #ffe4e6; color: #be123c; }
  .ad-card-icon.indigo { background: #e0e7ff; color: #4338ca; }
  .ad-card-icon.amber  { background: #fef3c7; color: #b45309; }
  .ad-card-label { font-size: 0.74rem; color: #64748b; margin: 0 0 2px 0; overflow-wrap: break-word; }
  .ad-card-val   { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; overflow-wrap: break-word; }
  .ad-card-pct   { font-size: 0.75rem; color: #64748b; }

  /* ── Contenedor con scroll propio ────────────────────────── */
  .ad-table-wrap {
    border-radius: 10px;
    overflow: auto;
    max-height: 60vh;
    box-shadow: 0 2px 12px rgba(15,23,42,.10);
    scrollbar-width: thin;
    scrollbar-color: #4361ee #e2e8f0;
  }
  .ad-table-wrap::-webkit-scrollbar        { height: 10px; width: 10px; }
  .ad-table-wrap::-webkit-scrollbar-track  { background: #e2e8f0; border-radius: 0 0 10px 10px; }
  .ad-table-wrap::-webkit-scrollbar-thumb  { background: #4361ee; border-radius: 10px; border: 2px solid #e2e8f0; }
  .ad-table-wrap::-webkit-scrollbar-thumb:hover { background: #2a3fc7; }

  /* ── Tabla ────────────────────────────────────────────────── */
  #dataTables-adop {
    width: 100%;
    font-size: 0.81rem;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1100px;        /* evita que las columnas se aplasten */
  }
  #dataTables-adop thead th {
    background: #f8fafc;
    color: #374151;
    font-weight: 600;
    padding: 10px 8px;
    text-align: center;
    white-space: nowrap;
    border: none;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.79rem;
    letter-spacing: .02em;
  }
  #dataTables-adop thead th:first-child { text-align: left; padding-left: 14px; }
  /* ocultar flechas de ordenamiento de DataTables */
  #dataTables-adop thead th::after,
  #dataTables-adop thead th::before { display: none !important; }
  #dataTables-adop thead th.sorting,
  #dataTables-adop thead th.sorting_asc,
  #dataTables-adop thead th.sorting_desc { background-image: none !important; padding-right: 8px !important; }

  /* Precio venta padre más ancho */
  input.precio-padre-inp {
    width: 90px !important;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    padding: 3px 5px;
    font-size: 0.79rem;
    background: #f8fafc;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  input.precio-padre-inp:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 2px rgba(99,102,241,.2);
  }
  .precio-padre-wrap { display: inline-flex; align-items: center; gap: 3px; }
  .precio-padre-wrap .pp-signo { font-size: 0.78rem; color: #64748b; font-weight: 600; }

  #dataTables-adop tbody tr { background: #fff; }
  #dataTables-adop tbody tr:nth-child(even) { background: #f8fafc; }
  #dataTables-adop tbody tr:hover { background: #eff6ff; }
  #dataTables-adop tbody td {
    padding: 6px 7px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
    text-align: center;
    color: #1e293b;
    font-size: 0.81rem;
  }
  #dataTables-adop tbody td:first-child { text-align: left; font-weight: 500; padding-left: 14px; }

  /* venta potencial / venta real */
  .venta { color: #ea580c; font-weight: 600; }

  /* inputs tasa / descuento / precio padre / uni_vr */
  #dataTables-adop input[type="text"] {
    width: 52px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    padding: 3px 4px;
    text-align: center;
    font-size: 0.79rem;
    background: #f8fafc;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  #dataTables-adop input[type="text"]:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 2px rgba(99,102,241,.2);
  }
  /* checkbox adopción */
  #dataTables-adop input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #6366f1;
    cursor: pointer;
  }

  /* tfoot */
  #dataTables-adop tfoot td {
    padding: 9px 8px;
    font-weight: 700;
    font-size: 0.82rem;
    background: #f8fafc;
    color: #374151;
    border: none;
    border-top: 2px solid #e2e8f0;
    text-align: center;
  }
  #dataTables-adop tfoot td:first-child { text-align: left; padding-left: 14px; }

  /* ── Sección inferior ─────────────────────────────────────── */
  .ad-footer-form {
    margin-top: 22px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 6px rgba(15,23,42,.07);
    padding: 22px 24px;
  }
  .ad-footer-form .form-label-sm {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .ad-footer-form .form-label-sm i { color: #6366f1; font-size: 0.88rem; }

  /* Campo de archivo */
  .ad-footer-form .ad-file-label {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 2px dashed #cbd5e0;
    border-radius: 8px;
    padding: 14px 16px;
    cursor: pointer;
    background: #f8fafc;
    transition: border-color .15s, background .15s;
    color: #64748b;
    font-size: 0.83rem;
    font-weight: 500;
  }
  .ad-footer-form .ad-file-label:hover { border-color: #6366f1; background: #eef2ff; color: #4f46e5; }
  .ad-footer-form .ad-file-label.has-file { border-color: #16a34a; background: #f0fdf4; color: #15803d; }
  .ad-footer-form input[type="file"] { display: none; }
  .ad-file-name { font-size: 0.79rem; color: #6366f1; margin-top: 5px; font-weight: 600; word-break: break-all; }

  /* Controles del footer con borde más visible */
  .ad-footer-form select.form-control,
  .ad-footer-form textarea.form-control {
    border: 1.5px solid #cbd5e0;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #1e293b;
    background: #f8fafc;
    transition: border-color .15s, box-shadow .15s;
    resize: vertical;       /* textarea redimensionable verticalmente */
    min-height: 80px;       /* altura mínima para ver observaciones */
  }
  .ad-footer-form select.form-control {
    min-height: unset;
    padding: 7px 10px;
    cursor: pointer;
  }
  .ad-footer-form select.form-control:focus,
  .ad-footer-form textarea.form-control:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(99,102,241,.15);
    outline: none;
  }

  /* % Cumplimiento como tarjeta destacada */
  .ad-cumplimiento-card {
    background: #f0f4ff;
    border: 1.5px solid #c7d2fe;
    border-radius: 10px;
    padding: 12px 16px;
    text-align: center;
    margin-top: 2px;
  }
  .ad-cumplimiento-val   { font-size: 1.6rem; font-weight: 800; color: #4338ca; display: block; }
  .ad-cumplimiento-label { font-size: 0.76rem; color: #6366f1; font-weight: 600; letter-spacing: .04em; }

  .ad-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
  }

  /* ── Filtro de libros (sticky) ───────────────────────────── */
  .ad-filter-bar {
    position: sticky;
    top: 0;
    z-index: 20;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    box-shadow: 0 2px 8px rgba(15,23,42,.07);
  }
  .ad-filter-left  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ad-filter-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ad-filter-bar span {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
    margin-right: 4px;
  }
  .ad-filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 14px;
    border-radius: 20px;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
  }
  .ad-filter-btn:hover { border-color: #6366f1; color: #6366f1; }
  .ad-filter-btn.active { background: #6366f1; border-color: #6366f1; color: #fff; }
  .ad-filter-btn .ad-filter-count {
    background: rgba(255,255,255,.25);
    border-radius: 10px;
    padding: 0 6px;
    font-size: 11px;
  }
  .ad-filter-btn:not(.active) .ad-filter-count { background: #f1f5f9; color: #475569; }
</style>

<div class="ad-wrap">

  <!-- Encabezado -->
  <div class="ad-header">
    <div>
      <h5 class="ad-title"><i class="bi bi-bookmark-check-fill"></i> Adopciones de libros</h5>
      <p class="ad-subtitle">Gestiona las adopciones y el seguimiento de venta real</p>
    </div>
    <div class="ad-actions">
      <a class="btn btn-success btn-sm" href="php/adopcion_excel.php?cole=<?= htmlspecialchars($_GET['colegio']) ?>&periodo=<?= htmlspecialchars($_GET['periodo']) ?>">
        <i class="bi bi-file-earmark-excel"></i> Exportar Excel
      </a>
    </div>
  </div>

  <!-- Tarjetas de resumen -->
  <?php
    $sql_adoptados = "SELECT COUNT(*) AS total FROM presupuestos
                      WHERE id_colegio='".$_GET["colegio"]."'
                        AND id_periodo='".$gp_periodo["id"]."'
                        AND definido = 1";
    $req_adoptados = $bdd->prepare($sql_adoptados);
    $req_adoptados->execute();
    $row_adoptados  = $req_adoptados->fetch();
    $total_adoptados = (int)$row_adoptados["total"];
  ?>
  <div class="ad-cards">
    <div class="ad-card">
      <div class="ad-card-icon blue"><i class="bi bi-book"></i></div>
      <div>
        <p class="ad-card-label">Total de títulos adoptados</p>
        <p class="ad-card-val"><?= $total_adoptados ?></p>
      </div>
    </div>
    <div class="ad-card">
      <div class="ad-card-icon green"><i class="bi bi-check2-circle"></i></div>
      <div>
        <p class="ad-card-label">Venta potencial</p>
        <p class="ad-card-val" id="ad-card-vp">—</p>
      </div>
    </div>
    <div class="ad-card">
      <div class="ad-card-icon orange"><i class="bi bi-graph-up"></i></div>
      <div>
        <p class="ad-card-label">Venta real</p>
        <p class="ad-card-val" id="ad-card-vr">—</p>
      </div>
    </div>
    <div class="ad-card" id="ad-card-cum-wrap">
      <div class="ad-card-icon purple" id="ad-card-cum-icon"><i class="bi bi-percent"></i></div>
      <div>
        <p class="ad-card-label">% Cumplimiento</p>
        <p class="ad-card-val" id="ad-card-cum">—</p>
      </div>
    </div>
  </div>

  <?php if ($gp_periodo["periodo"] >= 2027): ?>
  <!-- Tarjetas de IA (separadas visualmente) -->
  <div class="ad-ia-section">
    <div class="ad-ia-header">
      <i class="bi bi-robot"></i>
      <span>Inteligencia Artificial y tokens</span>
    </div>
    <div class="ad-cards">
      <div class="ad-card">
        <div class="ad-card-icon teal"><i class="bi bi-cpu"></i></div>
        <div>
          <p class="ad-card-label">Costo IA (por interacción)</p>
          <p class="ad-card-val">$<?= number_format($costo_ia_cop, 2, ",", ".") ?> COP</p>
        </div>
      </div>
      <div class="ad-card">
        <div class="ad-card-icon blue"><i class="bi bi-person-video3"></i></div>
        <div>
          <p class="ad-card-label">Cantidad de profesores</p>
          <p class="ad-card-val"><?= $cantidad_profesores ?></p>
        </div>
      </div>
      <div class="ad-card">
        <div class="ad-card-icon amber"><i class="bi bi-chat-dots"></i></div>
        <div>
          <p class="ad-card-label">Cantidad de interacciones</p>
          <p class="ad-card-val"><?= $interacciones ?></p>
        </div>
      </div>
      <div class="ad-card">
        <div class="ad-card-icon rose"><i class="bi bi-calendar-week"></i></div>
        <div>
          <p class="ad-card-label">Costo semanal</p>
          <p class="ad-card-val">$<?= number_format($costo_semanal, 2, ",", ".") ?> COP</p>
        </div>
      </div>
      <div class="ad-card">
        <div class="ad-card-icon indigo"><i class="bi bi-calendar-range"></i></div>
        <div>
          <p class="ad-card-label">Costo anual</p>
          <p class="ad-card-val">$<?= number_format($costo_anual, 2, ",", ".") ?> COP</p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>


  <!-- Filtro de libros + acciones (sticky) -->
  <div class="ad-filter-bar">
    <div class="ad-filter-left">
      <span><i class="bi bi-funnel"></i> Ver:</span>
      <button class="ad-filter-btn active" data-filter="todos">
        Todos los libros
      </button>
      <button class="ad-filter-btn" data-filter="adoptados">
        <i class="bi bi-bookmark-check-fill"></i> Solo adoptados
      </button>
    </div>
    <div class="ad-filter-right">
      <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal_adopciones">
        <i class="bi bi-plus-circle"></i> Añadir libros
      </a>
      <?php if ($show_guardar): ?>
      <button type="submit" form="form_definicion" class="btn btn-success btn-sm miBoton">
        <i class="bi bi-floppy"></i> Guardar cambios
      </button>
      <?php endif; ?>
    </div>
  </div>

  <style>
    .pr-toast { position:fixed; top:24px; right:24px; min-width:260px; padding:14px 20px; border-radius:10px; font-size:.87rem; font-weight:600; color:#fff; z-index:99999; box-shadow:0 6px 20px rgba(0,0,0,.18); display:flex; align-items:center; gap:10px; opacity:0; transform:translateY(-16px); transition:opacity .3s, transform .3s; pointer-events:none; }
    .pr-toast.show  { opacity:1; transform:translateY(0); }
    .pr-toast.ok    { background:#16a34a; }
    .pr-toast.error { background:#dc2626; }
    .pr-toast i { font-size:1.1rem; }
    .lb-modal .modal-content  { border:none; border-radius:12px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.15); }
    .lb-modal .modal-header   { background:#fff; padding:18px 24px 14px; border-bottom:1px solid #e9ecef; }
    .lb-modal .modal-title    { font-size:16px; font-weight:700; color:#111827; margin:0 0 3px; }
    .lb-modal .modal-subtitle { font-size:12.5px; color:#6b7280; margin:0; }
    .lb-modal .close          { color:#9ca3af; opacity:1; text-shadow:none; font-size:1.3rem; margin-top:-4px; }
    .lb-modal .close:hover    { color:#374151; }
    .lb-modal .modal-body     { padding:20px 24px; background:#f9fafb; max-height:65vh; overflow-y:auto; }
    .lb-modal .modal-footer   { border-top:1px solid #e9ecef; padding:14px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center; }
    .lb-book-item             { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:10px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
    .lb-book-num              { font-size:12px; font-weight:700; color:#4f46e5; margin:0 0 14px; display:flex; align-items:center; gap:5px; }
    .lb-book-num::before      { content:''; display:inline-block; width:3px; height:14px; background:#4f46e5; border-radius:2px; }
    .lb-modal .form-group     { margin-bottom:0; }
    .lb-modal .form-group label { font-size:12px; font-weight:600; color:#374151; margin-bottom:5px; display:block; }
    .lb-modal .form-control   { border-radius:7px; font-size:13px; border:1px solid #d1d5db; padding:7px 10px; background:#fff; color:#111827; transition:border-color .15s, box-shadow .15s; }
    .lb-modal .form-control:focus { border-color:#4f46e5; background:#fff; box-shadow:0 0 0 3px rgba(79,70,229,.1); outline:none; }
    .lb-req                   { color:#ef4444; }
    .lb-add-btn               { display:inline-flex; align-items:center; gap:5px; color:#4f46e5; font-size:13px; font-weight:600; cursor:pointer; border:none; background:none; padding:0; text-decoration:none; }
    .lb-add-btn:hover         { text-decoration:underline; color:#4f46e5; }
    .lb-modal .btn-primary    { background:#4f46e5; border-color:#4f46e5; border-radius:8px; padding:8px 22px; font-weight:600; font-size:13.5px; }
    .lb-modal .btn-primary:hover { background:#4338ca; border-color:#4338ca; }
    .lb-modal .btn-light      { border-radius:8px; font-size:13.5px; }
  </style>

  <div class="modal fade lb-modal" id="modal_adopciones" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">

        <div class="modal-header">
          <div>
            <h5 class="modal-title">Añadir libros</h5>
            <p class="modal-subtitle">Completa la información del libro que quieres añadir a las adopciones</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
        </div>

        <form action="php/areas_cumplimiento.php" method="POST" class="miFormulario">
        <div class="modal-body">

          <div class="otra_aod">
            <div class="lb-book-item">
              <p class="lb-book-num">Libro #1</p>
              <div class="row">
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label for="materiad">Materia <span class="lb-req">*</span></label>
                    <select name="materiad" id="materiad" class="form-control materiad">
                      <option value="">Seleccionar materia</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $materia)
                          echo '<option value="'.$materia['id'].'">'.$materia['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label for="gradod">Grado <span class="lb-req">*</span></label>
                    <select name="gradod" required id="gradod" class="form-control gradod">
                      <option value="">Seleccionar grado</option>
                      <?php
                        $sql = "SELECT id, grado FROM grados";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $grado)
                          echo '<option value="'.$grado['id'].'">'.$grado['grado'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6 g_otrod d-none">
                  <div class="form-group">
                    <label for="grado_otrod" id="l_grado_otrod">Grado específico <span class="lb-req">*</span></label>
                    <select name="grado_otrod" id="grado_otrod" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, grado FROM grados WHERE id < 15";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $grado)
                          echo '<option value="'.$grado['id'].'">'.$grado['grado'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label for="libro_ed">Libro <span class="lb-req">*</span></label>
                    <select name="libro_ed" id="libro_ed" class="form-control gradod custom-select2" required></select>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <input type="hidden" name="libs_aod[]" id="libs_aod">

          <?php for ($i=1; $i < 100; $i++): ?>
          <div id="agg_aod<?= $i ?>" class="d-none">
            <div class="lb-book-item">
              <p class="lb-book-num">Libro #<?= $i+1 ?></p>
              <div class="row">
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label>Materia <span class="lb-req">*</span></label>
                    <select name="materiad1" id="materiad<?= $i ?>" class="form-control materiad">
                      <option value="">Seleccionar materia</option>
                      <?php
                        $sql = "SELECT id, materia FROM materias";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $materia)
                          echo '<option value="'.$materia['id'].'">'.$materia['materia'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label>Grado <span class="lb-req">*</span></label>
                    <select name="gradod1" id="gradod<?= $i ?>" class="form-control gradod">
                      <option value="">Seleccionar grado</option>
                      <?php
                        $sql = "SELECT id, grado FROM grados";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $grado)
                          echo '<option value="'.$grado['id'].'">'.$grado['grado'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6 g_otrod<?= $i ?> d-none">
                  <div class="form-group">
                    <label id="l_grado_otrod<?= $i ?>">Grado específico <span class="lb-req">*</span></label>
                    <select name="grado_otrod" id="grado_otrod<?= $i ?>" class="form-control">
                      <option value="">Seleccionar</option>
                      <?php
                        $sql = "SELECT id, grado FROM grados WHERE id < 15";
                        $req = $bdd->prepare($sql); $req->execute();
                        foreach ($req->fetchAll() as $grado)
                          echo '<option value="'.$grado['id'].'">'.$grado['grado'].'</option>';
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label>Libro <span class="lb-req">*</span></label>
                    <select name="libro_ed" id="libro_ed<?= $i ?>" class="form-control gradod custom-select2"></select>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <input type="hidden" name="libs_aod[]" id="libs_aod<?= $i ?>">
          <?php endfor; ?>

          <input type="hidden" name="promotor"    value="<?= $_GET['promotor'] ?>">
          <input type="hidden" name="id_colegio"  id="cole" value="<?= $_GET['colegio'] ?>">
          <input type="hidden" name="cod_zona"    value="<?= $_GET['cod_zona'] ?>">
          <input type="hidden" name="sub_zona"    value="<?= $_GET['sub_zona'] ?>">
          <input type="hidden" name="responsable" value="<?= $_GET['responsable'] ?>">
          <input type="hidden" name="cod_colegio" value="<?= $_GET['codigo'] ?>">
          <input type="hidden" name="periodo"     value="<?= $gp_periodo['id'] ?>">

        </div><!-- /.modal-body -->

        <div class="modal-footer">
          <?php if ($_SESSION["tipo"] != 2 && $_SESSION["tipo"] != 4): ?>
            <?php if ($_SESSION["zona"] == $_GET["cod_zona"] || $_SESSION["tipo"] == 1): ?>
              <?php if ($_GET["f_cierre"] > date("Y-m-d")): ?>
                <button type="button" class="lb-add-btn" id="agregar_aod">
                  <i class="bi bi-plus-circle"></i> Añadir otro libro
                </button>
                <button type="submit" class="btn btn-primary miBoton">
                  <i class="bi bi-floppy"></i> Guardar libros
                </button>
              <?php else: ?>
                <span></span>
                <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
              <?php endif; ?>
            <?php else: ?>
              <span></span>
              <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
            <?php endif; ?>
          <?php else: ?>
            <span></span>
            <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
          <?php endif; ?>
        </div>

        </form>
      </div>
    </div>
  </div>
    <?php
                            
        $sql = "SELECT p.id,p.cod_area, b.materia, c.grado,l.id, l.libro,l.id_materia, l.id_grado, l.pri_sec, l.precio, desc_max, desc_max_dist FROM presupuestos p JOIN libros l ON p.id_libro=l.id JOIN materias b ON l.id_materia=b.id JOIN grados c ON l.id_grado=c.id WHERE id_colegio='".$_GET["colegio"]."' AND id_periodo='".$gp_periodo["id"]."' AND p.aprobado < 2 AND p.pre_definido='1' AND p.probabilidad !=3 ORDER BY l.id_grado ASC";


        $req = $bdd->prepare($sql);
        $req->execute();
        $libros_p = $req->fetchAll();

        $sql_exist_d = "SELECT DISTINCT id_libro_eureka FROM areas_objetivas WHERE id_colegio='".$_GET["colegio"]."' AND id_periodo='".$gp_periodo["id"]."'";
        $req_exist_d = $bdd->prepare($sql_exist_d);
        $req_exist_d->execute();
        $ids_exist_adop = array_map('intval', array_column($req_exist_d->fetchAll(PDO::FETCH_ASSOC), 'id_libro_eureka'));

		echo "<form action='php/guardar_definicion.php' class='miFormulario' method='POST' id='form_definicion' name='f2' enctype='multipart/form-data'>";
                              
            echo "<div class='ad-table-wrap mt-2'>
                <table id='dataTables-adop'>
                <thead>
                  <tr>
                    <th>Título</th>
                    <th>Materia</th>
                    <th>Grado</th>
                    <th>Alumnos</th>
                    <th>Tasa de compra</th>
                    <th>PVP</th>
                    <th>Descuento</th>
                    <th>Precio neto</th>
                    <th>Venta potencial</th>
                    <th>Precio venta padre</th>
                    <th>Adopción <input type='checkbox' id='seleccionar_pre'></th>
                    <th>Unidades venta real</th>
                    <th>Venta real</th>
                  </tr>
                </thead>
                <tbody>";
                    foreach ($libros_p as $libro_p) {

                        if ($libro_p["id_grado"] == 15 || $libro_p["id_grado"] == 16 ) {

                            $sq_l2 = "SELECT l.id, l.libro,l.id_grado, l.precio, g.grado, m.materia FROM libros l JOIN materias m ON l.id_materia=m.id JOIN grados g ON l.id_grado=g.id WHERE l.pri_sec='".$libro_p["lib_eureka"]."'";
                            
                            $req_l2 = $bdd->prepare($sq_l2);
                            $req_l2->execute();
                            $libros2 = $req_l2->fetchAll();

                            foreach ($libros2 as $libro2) {

                                $sql_presup = "SELECT id, precio, tasa_compra, descuento, definido, tasa_compra_d, descuento_d, precio_venta_final FROM presupuestos WHERE id_libro='".$libro2["id"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";
                            
                                $req_presup = $bdd->prepare($sql_presup);
                                $req_presup->execute();
                                $presup = $req_presup->fetch();
 
                                $libro=$libro2["libro"];

                                $sq_gp = "SELECT paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$_GET['colegio']."' AND id_grado='".$libro2["id_grado"]."' AND id_periodo='".$gp_periodo["id"]."'";
                            
                                $req_gp = $bdd->prepare($sq_gp);
                                $req_gp->execute();
                                $gp = $req_gp->fetch();

                                 echo "<tr data-adoptado='".($presup["definido"]==1?'1':'0')."'>
                                    <td>".$libro."</td>
                                    <td>".$libro2["materia"]."</td>
                                    <td>".$libro2["grado"]."</td>
                                    <!--<td>".$gp["paralelos"]."</td>-->
                                    <td id='alm_d".$libro2["id"]."'>".$gp["alumnos"]."</td>";
                                      

                                    if ($presup["tasa_compra"] !="" && $presup["tasa_compra_d"] ==0.00 ) {

                                        $presup["tasa_compra"] = $presup["tasa_compra"] *100;
                                        echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro2["id"]."' value='".$presup["tasa_compra"]."'> %</td>";
                                    }
                                    elseif( $presup["tasa_compra_d"] !=""){

                                        $presup["tasa_compra_d"] = $presup["tasa_compra_d"] *100;
                                          echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro2["id"]."' value='".$presup["tasa_compra_d"]."'> %</td>";

                                    }
                                    else {
                                        echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro2["id"]."' value='0' required> %</td>";

                                    }
                                        
                                        
                                    if ($presup["precio"] !=0) {
                                        $precio=number_format($presup["precio"],0,",", ".");

                                        echo "<td id='pvp_d".$libro2["id"]."'>".$precio."</td>";

                                        echo "<input type='hidden' id='pvp_s_d".$libro2["id"]."' value='".$presup["precio"]."'>";
                                    }else {

                                        $precio=number_format($libro2["precio"],0,",", ".");

                                        echo "<td id='pvp_d".$libro2["id"]."'>".$precio."</td>";

                                        echo "<input type='hidden' id='pvp_s_d".$libro2["id"]."' value='".$libro2["precio"]."'>";
                                    }
                                    if ($presup["descuento"] !="" && $presup["descuento_d"] ==0.00) {

                                        $presup_m = $presup["descuento"] * 100;
                                        echo "<td><input type='text' size='2' name='descuento[]' id='descuento_d".$libro2["id"]."' value='".$presup_m."'> %</td>";

                                    }
                                    elseif( $presup["descuento_d"] !=""){

                                        $presup_m = $presup["descuento_d"] * 100;
                                        echo "<td><input type='text' size='2' name='descuento[]' id='descuento_d".$libro2["id"]."' value='".$presup_m."'> %</td>";

                                    }
                                    else {

                                        echo "<td><input type='text' size='2' name='descuento[]' value='20' id='descuento_d".$libro_p["id"]."' required> %</td>";
                                    }
                                    if ($presup["tasa_compra"] !="" && $presup["tasa_compra_d"] ==0.00) {
                                        $precio_neto= $presup["precio"] -( $presup["precio"] * $presup["descuento"]);

                                        if ($presup["definido"] ==1) {
                                            $venta_p= $precio_neto * floor($gp["alumnos"] * $presup["tasa_compra"]/100);
                                        }else{
                                            $venta_p=0;
                                        }

                                        $precio_ne=number_format($precio_neto,2,",", ".");

                                        echo "<td id='pn_d".$libro2["id"]."'>".$precio_ne."</td>";

                                        echo "<input type='hidden' id='pn_s_d".$libro2["id"]."' value='".$precio_neto."'>";
                                        if ($presup["definido"] ==1) {
                                            $venta_po=number_format($venta_p,0,",", ".");
                                        }else{
                                            $venta_po=0;
                                        }

                                        echo"<td id='venta_p_d".$libro2["id"]."' class='venta'>".$venta_po."</td>

                                        <input type='hidden' id='venta_ps_d".$libro2["id"]."' class='venta1_d' value='".$venta_p."'>";
                                    }
                                    elseif($presup["tasa_compra_d"] !=""){

                                        $precio_neto= $presup["precio"] -( $presup["precio"] * $presup["descuento_d"]);

                                        if ($presup["definido"] ==1) {
                                            $venta_p= $precio_neto * floor($gp["alumnos"] * $presup["tasa_compra_d"]/100);
                                        }else{
                                            $venta_p=0;
                                        }

                                        $precio_ne=number_format($precio_neto,2,",", ".");

                                        echo "<td id='pn_d".$libro2["id"]."'>".$precio_ne."</td>";

                                        echo "<input type='hidden' id='pn_s_d".$libro2["id"]."' value='".$precio_neto."'>";
                                        if ($presup["definido"] ==1) {
                                            $venta_po=number_format($venta_p,0,",", ".");
                                        }else{
                                            $venta_po=0;
                                        }

                                        echo"<td id='venta_p_d".$libro2["id"]."' class='venta'>".$venta_po."</td>

                                        <input type='hidden' id='venta_ps_d".$libro2["id"]."' class='venta1_d' value='".$venta_p."'>";

                                        
                                    }else {

                                        echo "<td id='pn_d".$libro2["id"]."'></td>

                                        <td id='venta_p_d".$libro2["id"]."' class='venta1_d'></td>

                                        <input type='hidden' id='venta_ps_d".$libro2["id"]."' class='venta1_d'>";

                                    }

                                    echo "<td><span class='precio-padre-wrap'><span class='pp-signo'>$</span><input type='text' class='precio-padre-inp' name='precio_padre[]' id='precio_padre".$libro2["id"]."' value='".$presup["precio_venta_final"]."'></span></td>";
                                        
                                    if ($presup["tasa_compra"] !=0.00 || $presup["tasa_compra_d"] !=0.00) {
                                        if ($presup["definido"] ==1) {
                                            echo "<td><input type='checkbox' name='definir[]' class='definir' checked value='".$libro2["id"]."/'".$presup["id"]."></td>";
                                        }
	                                    else {

	                                        echo "<td><input type='checkbox' name='definir[]' class='definir' value='".$libro2["id"]."/1".$presup["id"]."'></td>";

	                                    }
                                	}else {
                                    	echo"<td></td>";
                                	}


                                    echo "<input type='hidden' name='presupuesto_d[]' value='".$libro2["id"]."' id='presupuesto_d".$libro2["id"]."'>

                                      <script>

                                        $('#descuento_d".$libro2["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro2["id"]."').val());

                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro2["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro2["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro2["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0
                                            }

                                            $('#venta_p_d".$libro2["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro2["id"]."').val(vp);
                                            

                                            $('#v_d".$libro2["id"]."').val(vp);


                                            $('#precio_n".$libro2["id"]."').val(precio_neto);
                                            
                                            
                                            var precio_padre=parseInt($('#precio_padre".$libro2["id"]."').val());

                                            $('#presupuesto_d".$libro2["id"]."').val(".$libro2["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);

                                            var total_vp_d=0;

                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));



                                          })

                                          $('#tasa_d".$libro2["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro2["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro2["id"]."').val());
                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro2["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro2["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro2["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0
                                            }

                                            $('#venta_p_d".$libro2["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro2["id"]."').val(vp);
                                            

                                            $('#v_d".$libro2["id"]."').val(vp);


                                            $('#precio_n".$libro2["id"]."').val(precio_neto);


                                            var precio_padre=parseInt($('#precio_padre".$libro2["id"]."').val());

                                            $('#presupuesto_d".$libro2["id"]."').val(".$libro2["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);

                                            var total_vp_d=0;

                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));


                                          })

                                          $('#precio_padre".$libro2["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro2["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro2["id"]."').val());
                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro2["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro2["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro2["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0
                                            }

                                            $('#venta_p_d".$libro2["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro2["id"]."').val(vp);
                                            

                                            $('#v_d".$libro2["id"]."').val(vp);


                                            $('#precio_n".$libro2["id"]."').val(precio_neto);


                                            var precio_padre=parseInt($('#precio_padre".$libro2["id"]."').val());

                                            $('#presupuesto_d".$libro2["id"]."').val(".$libro2["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);

                                            var total_vp_d=0;

                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));


                                          })
                                      </script>

                                        
                                      </tr>";
                                  }
                                }

                                else {


                                  if ($libro_p["cod_area"] !="") {
                                    $libro_p["id_grado"] = 17;
                                  }

                                  if ($libro_p["id_grado"] != 17) {

                                  $sql_presup = "SELECT id,precio, tasa_compra, descuento, tasa_compra_d, descuento_d, precio_venta_final, definido, uni_vr FROM presupuestos WHERE id_libro='".$libro_p["id"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";

                                  }else{

                                    $sql_presup = "SELECT id,precio, tasa_compra, descuento, tasa_compra_d, descuento_d, precio_venta_final, definido, uni_vr  FROM presupuestos WHERE cod_area='".$libro_p["cod_area"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";

                                  }
                            
                                  $req_presup = $bdd->prepare($sql_presup);
                                  $req_presup->execute();
                                  $presup = $req_presup->fetch();

                                  $lib_id=$libro_p["id"];

                                  if ($libro_p["id_grado"] != 17) {

                                    $sq_gp = "SELECT paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$_GET['colegio']."' AND id_grado='".$libro_p["id_grado"]."' AND id_periodo='".$gp_periodo["id"]."'";

                                  }else {

                                    $libro_100=$libro_p["id"];

                                    $libro_p["id"]=$libro_p["cod_area"];

                                    $sql_go = "SELECT id_grado_otro FROM areas_objetivas WHERE codigo='".$libro_p["cod_area"]."'";


                                    $req_go = $bdd->prepare($sql_go);
                                    $req_go->execute();
                                    $go = $req_go->fetch();

                                    $sq_gp = "SELECT paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$_GET['colegio']."' AND id_grado='".$go["id_grado_otro"]."' AND id_periodo='".$gp_periodo["id"]."'";
                                  }

                            
                                    $req_gp = $bdd->prepare($sq_gp);
                                    $req_gp->execute();
                                    $gp = $req_gp->fetch();

                                  echo "<tr data-adoptado='".($presup["definido"]==1?'1':'0')."'>
                                      <td>".$libro_p["libro"]."</td>
                                      <td>".$libro_p["materia"]."</td>";

                                    if ($libro_p["id_grado"] != 17) {
                                        echo "<td>".$libro_p["grado"]."</td>";
                                      
                                    }else {

                                      $sql_otrg = "SELECT g.grado FROM grados g JOIN areas_objetivas a ON g.id=a.id_grado_otro WHERE a.codigo='".$libro_p["cod_area"]."'";

                                      $req_otrg = $bdd->prepare($sql_otrg);
                                      $req_otrg->execute();
                                      $otrg = $req_otrg->fetch();

                                      echo "<td>".$otrg["grado"]."</td>";
                                    }
                                      
                                      echo"<!--<td>".$gp["paralelos"]."</td>-->
                                      <td id='alm_d".$libro_p["id"]."'>".$gp["alumnos"]."</td>";
                                      if ($presup["tasa_compra"] !="" && $presup["tasa_compra_d"] ==0.00 ) {

                                          $presup["tasa_compra"] = $presup["tasa_compra"] *100;
                                          echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro_p["id"]."' value='".$presup["tasa_compra"]."'> %</td>";
                                        }
                                        elseif( $presup["tasa_compra_d"] !=""){

                                          $presup["tasa_compra_d"] = $presup["tasa_compra_d"] *100;
                                          echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro_p["id"]."' value='".$presup["tasa_compra_d"]."'> %</td>";

                                        }
                                        else {
                                          echo "<td><input type='text' size='2' name='tasa[]' id='tasa_d".$libro_p["id"]."' value='0' required> %</td>";

                                        }
                                        if ($presup["precio"] !=0) {

                                          $precio=number_format($presup["precio"],0,",", ".");

                                          echo "<td id='pvp_d".$libro_p["id"]."'>".$precio."</td>";

                                          echo "<input type='hidden' id='pvp_s_d".$libro_p["id"]."' value='".$presup["precio"]."'>";
                                        }else {
                                          $precio=number_format($libro_p["precio"],0,",", ".");

                                          echo "<td id='pvp_d".$libro_p["id"]."'>".$precio."</td>";

                                          echo "<input type='hidden' id='pvp_s_d".$libro_p["id"]."' value='".$libro_p["precio"]."'>";
                                        }
                                      if ($presup["descuento"] !="" && $presup["descuento_d"] ==0.00) {

                                          $presup_m = $presup["descuento"] * 100;
                                          echo "<td><input type='text' size='2' name='descuento[]' id='descuento_d".$libro_p["id"]."' value='".$presup_m."'> %</td>";

                                        }
                                        elseif( $presup["descuento_d"] !=""){

                                          $presup_m = $presup["descuento_d"] * 100;
                                          echo "<td><input type='text' size='2' name='descuento[]' id='descuento_d".$libro_p["id"]."' value='".$presup_m."'> %</td>";

                                        }
                                        else {

                                          echo "<td><input type='text' size='2' name='descuento[]' value='20' id='descuento_d".$libro_p["id"]."' required> %</td>";
                                        }
                                        if ($presup["tasa_compra"] !="" && $presup["tasa_compra_d"] ==0.00) {
                                          $precio_neto= $presup["precio"] -( $presup["precio"] * $presup["descuento"]);

                                          if ($presup["definido"]==1) {
                                            $venta_p= $precio_neto * floor($gp["alumnos"] * $presup["tasa_compra"]/100);
                                          }else{
                                            $venta_p=0;
                                          }

                                          $precio_ne=number_format($precio_neto,2,",", ".");

                                          echo "<td id='pn_d".$libro_p["id"]."'>".$precio_ne."</td>";

                                          echo "<input type='hidden' id='pn_s_d".$libro_p["id"]."' value='".$precio_neto."'>";
                                          if ($presup["definido"]==1) {
                                            $venta_po=number_format($venta_p,0,",", ".");
                                          }else{
                                            $venta_po=0;
                                          }

                                          echo"<td id='venta_p_d".$libro_p["id"]."' class='venta'>".$venta_po."</td>

                                          <input type='hidden' id='venta_ps_d".$libro_p["id"]."' class='venta1_d' value='".$venta_p."'>";

                                        }
                                        elseif( $presup["tasa_compra_d"] !=""){

                                          $precio_neto= $presup["precio"] -( $presup["precio"] * $presup["descuento_d"]);
                                          if ($presup["definido"]==1) {
                                            $venta_p= $precio_neto * floor($gp["alumnos"] * $presup["tasa_compra_d"]/100);
                                          }else{
                                             $venta_p= 0;
                                          }

                                          $precio_ne=number_format($precio_neto,2,",", ".");

                                          echo "<td id='pn_d".$libro_p["id"]."'>".$precio_ne."</td>";

                                          echo "<input type='hidden' id='pn_s_d".$libro_p["id"]."' value='".$precio_neto."'>";
                                          if ($presup["definido"]==1) {
                                            $venta_po=number_format($venta_p,0,",", ".");
                                          }else{
                                            $venta_po=0;
                                          }

                                          echo"<td id='venta_p_d".$libro_p["id"]."' class='venta'>".$venta_po."</td>

                                          <input type='hidden' id='venta_ps_d".$libro_p["id"]."' class='venta1_d' value='".$venta_p."'>";

                                        }

                                          else {

                                          echo "<td id='pn_d".$libro_p["id"]."'></td>

                                          <td id='venta_p_d".$libro_p["id"]."' class='venta'></td>

                                          <input type='hidden' id='venta_ps_d".$libro_p["id"]."' class='venta1_d'>";

                                        }


                                          echo "<td><span class='precio-padre-wrap'><span class='pp-signo'>$</span><input type='text' class='precio-padre-inp' name='precio_padre[]' id='precio_padre".$libro_p["id"]."' value='".$presup["precio_venta_final"]."'></span></td>";

                                          if ($presup["tasa_compra"] !=0.00 || $presup["tasa_compra_d"] !=0.00) {
                                            if ($presup["definido"] ==1) {
                                              echo "<td><input type='checkbox' name='definir[]' class='definir' checked value='".$libro_p["id"]."/".$presup["id"]."'></td>";
                                            }
                                            else {

                                              echo "<td><input type='checkbox' name='definir[]' class='definir' value='".$libro_p["id"]."/".$presup["id"]."'></td>";

                                            }
                                          }else {
                                            echo"<td></td>";
                                          }

                                          if ($presup["definido"] ==1) {

                                            if ($presup["uni_vr"] !=0 ) {
                                              echo "<td><input type='text' size='2' name='uni_vr[]' id='uni_vr".$libro_p["id"]."' value='".$presup["uni_vr"]."'></td>";

                                              $venta_r= $precio_neto * $presup["uni_vr"];
                                            
                                              $venta_ro=number_format($venta_r,0,",", ".");
                                           
                                              echo"<td id='venta_r".$libro_p["id"]."' class='venta'>".$venta_ro."</td>
                                              <input type='hidden' id='i_uni_vr".$libro_p["id"]."' class='uni_vr_d' value='".$venta_r."'>";

                                              
                                            }else{
                                              echo "<td><input type='text' size='2' name='uni_vr[]' id='uni_vr".$libro_p["id"]."'></td>
                                              <input type='hidden' id='i_uni_vr".$libro_p["id"]."' class='uni_vr_d'>";

                                              echo"<td id='venta_r".$libro_p["id"]."' class='venta'></td>";
                                             
                                            }
                                                                               
                                            



                                          }else{
                                            echo "<td></td>";
                                            echo "<td></td>";
                                          }
                                          



                                      echo "<input type='hidden' name='presupuesto_d[]' value='".$libro_p["id"]."' id='presupuesto_d".$libro_p["id"]."'>

                                            <input type='hidden' name='v_uni_vr[]' id='v_uni_vr".$libro_p["id"]."'>
                                      <input type='hidden' name='presup_profes[]' value='".$presup["id"]."'>


                                      <script>
                                        $('#descuento_d".$libro_p["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro_p["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro_p["id"]."').val());";


                                            if ($_SESSION['tipo']!=6) {
	                                    		echo "var desc_max=parseFloat(".$libro_p["desc_max"].")* 100;";
			                                }else{
			                                        	echo "var desc_max=parseFloat(".$libro_p["desc_max_dist"].")* 100;";
			                                }

                                            if ($_SESSION['tipo']!=1) {
                                                
                                                if (isset($libro_100)) {
                                                    if ($libro_100 !=3481 && $libro_100 !=3482) {
                                                        echo "if (descuento > 69){
                                                            alert('el descuento no debe superar el 69%');
                                                            $('#descuento_d".$libro_p["id"]."').val('20');
                                                            $('#descuento_d".$libro_p["id"]."').focus();
                                                            descuento=20;
                                                        }
                                                        ";


                                                    }
                                                }else{
                                                     echo "if (descuento > 69){
                                                        alert('el descuento no debe superar el 69%');
                                                        $('#descuento_d".$libro_p["id"]."').val('20');
                                                        $('#descuento_d".$libro_p["id"]."').focus();
                                                        descuento=20;
                                                    }
                                                    ";
                                                }

                                            }
		                                    
                                            
                                            if ($_SESSION['tipo']!=1) {
    		                                    echo"

    		                                    if (desc_max > 0){
    		                                    	if (descuento > desc_max){

    				                                    alert('el descuento no debe superar: '+desc_max);
    				                                    $('#descuento_d".$libro_p["id"]."').val(desc_max);
    				                                    $('#descuento_d".$libro_p["id"]."').focus();
    				                                    descuento=desc_max;
    			                                	}
    		                                    }";
                                            }
                                           echo"descuento= descuento/100;



                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro_p["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro_p["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro_p["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0;
                                            }

                                            $('#venta_p_d".$libro_p["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro_p["id"]."').val(vp);

                                            var precio_padre=parseInt($('#precio_padre".$libro_p["id"]."').val());


                                            $('#presupuesto_d".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);

                                            var total_vp_d=0;


                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));

                                          })

                                          $('#tasa_d".$libro_p["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro_p["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro_p["id"]."').val());
                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro_p["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro_p["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro_p["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0;
                                            }

                                            $('#venta_p_d".$libro_p["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro_p["id"]."').val(vp);


                                            var precio_padre=parseInt($('#precio_padre".$libro_p["id"]."').val());


                                            $('#presupuesto_d".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);


                                            var total_vp_d=0;

                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));

                                          })

                                          $('#precio_padre".$libro_p["id"]."').keyup(function(){
                                            var pvp=parseInt($('#pvp_s_d".$libro_p["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro_p["id"]."').val());
                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                            $('#pn_d".$libro_p["id"]."').text(formatNumber.new(precio_neto));


                                            var tasa_c=parseInt($('#tasa_d".$libro_p["id"]."').val());

                                            tasa_c=tasa_c/100;

                                            var alumnos=parseInt($('#alm_d".$libro_p["id"]."').text());

                                            var vp= precio_neto *(Math.floor(alumnos*tasa_c))

                                            if(isNaN(vp)){
                                              vp=0;
                                            }

                                            $('#venta_p_d".$libro_p["id"]."').text(formatNumber.new(vp));

                                            $('#venta_ps_d".$libro_p["id"]."').val(vp);


                                            var precio_padre=parseInt($('#precio_padre".$libro_p["id"]."').val());


                                            $('#presupuesto_d".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+precio_padre);


                                            var total_vp_d=0;

                                            $('.venta1_d').each(function(){

                                              total_vp_d+=parseFloat($(this).val()) || 0;
                                              total_vp_d=Math.round(total_vp_d * 100) / 100;

                                            });
                                                                              
                                            $('#total_vp_d').text(formatNumber.new(total_vp_d));

                                          })

                                          $('#uni_vr".$libro_p["id"]."').keyup(function(){
                                            
                                            var pvp=parseInt($('#pvp_s_d".$libro_p["id"]."').val());

                                            var descuento=parseFloat($('#descuento_d".$libro_p["id"]."').val());

                                            descuento= descuento/100;

                                            var precio_neto= pvp - (pvp * descuento);

                                            if(isNaN(precio_neto)){
                                              precio_neto=0
                                            }

                                           

                                            var uni_vr=parseInt($('#uni_vr".$libro_p["id"]."').val());

                                            var vr= precio_neto * uni_vr

                                            if(isNaN(vr)){
                                              vr=0;
                                            }



                                            $('#venta_r".$libro_p["id"]."').text(formatNumber.new(vr));

                                            $('#i_uni_vr".$libro_p["id"]."').val(vr);


                                            $('#v_uni_vr".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+uni_vr);


                                          

                                            total_uni_vr_d=0;

                                            $('.uni_vr_d').each(function(){

                                              total_uni_vr_d+=parseFloat($(this).val()) || 0;

                                              total_uni_vr_d=Math.round(total_uni_vr_d * 100) / 100;
                                              

                                            });

                                            $('#total_vr').text(formatNumber.new(total_uni_vr_d));

                                            var cumplimiento=(total_uni_vr_d / total_vp_d) * 100;

                                            setCumCard(cumplimiento);


                                          })

                                         


                                      </script>

                                      
                                    </tr>";

                                }


                            if($_SESSION["tipo"] !=2 ) { 
                                /*if ($_GET["f_cierre"] > date("Y-m-d")){
                                echo'<center><button class="btn btn-primary">Actualizar</button></center>
                                <input type="hidden" name="id_colegio" id="cole" value="'.$_GET["colegio"].'">
                              <input type="hidden" name="cod_colegio" value="'.$_GET["codigo"].'">
                              <input type="hidden" name="id_area" value="'.$area["aid"].'"> 
                                </form></div>';
                              }*/
                            }
                              }
                              echo "
                              </tbody>
                              <tfoot>
                              <tr>
                                <td>Total</td>
                                <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                <td id='total_vp_d'></td>
                                <td></td><td></td><td></td>
                                <td id='total_vr'></td>
                              </tr>
                              </tfoot>
                              </table></div><!-- /.ad-table-wrap -->
                              <input type='hidden' name='id_colegio' id='cole' value='".$_GET["colegio"]."'>
                              <input type='hidden' name='codigo'     value='".$_GET["codigo"]."'>
                              <input type='hidden' name='periodo'    value='".$gp_periodo["id"]."'>";
                                    
                                    //adopcion solo admin

                                    /*$sql_periodo1="SELECT id FROM periodos ORDER BY id DESC  limit 1;";

                                  $req_periodo1 = $bdd->prepare($sql_periodo1);
                                  $req_periodo1->execute();
                                  $u_periodo = $req_periodo1->fetch();*/                           

                          $sql_rec = "SELECT * FROM recursos WHERE id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";
                          $req_rec = $bdd->prepare($sql_rec);
                          $req_rec->execute();
                          $recursos = $req_rec->fetch();
                          $count    = $req_rec->rowCount();

                          // ── Sección inferior estilizada ──────────────────────────
                          echo '<div class="ad-footer-form">';
                          echo '<div class="row g-3 align-items-start">';

                          // Canal de venta
                          echo '<div class="col-sm-3">
                                  <span class="form-label-sm">
                                    <i class="bi bi-shop"></i> Canal de venta
                                  </span>
                                  <select name="canal" id="canal" class="form-control materia">
                                    <option value="">Seleccionar canal...</option>';
                          $sql = "SELECT id, canal_venta FROM canales_venta";
                          $req = $bdd->prepare($sql); $req->execute();
                          $canales = $req->fetchAll();
                          foreach ($canales as $canal) {
                              $id  = $canal['id'];
                              $nom = $canal['canal_venta'];
                              $sel = ($count > 0 && $recursos["id_canal"] == $id) ? ' SELECTED' : '';
                              echo '<option value="'.$id.'"'.$sel.'>'.$nom.'</option>';
                          }
                          echo '</select></div>';

                          // Documento de adopción (solo para tipos 1, 3, 10)
                          if (in_array($_SESSION['tipo'], [1, 3, 10])) {
                              $arch_existente = ($count > 0 && !empty($recursos['archivo'])) ? $recursos['archivo'] : '';
                              $periodo_activo = $_GET["f_cierre"] > date("Y-m-d");

                              if ($periodo_activo) {
                                  // Periodo activo: upload interactivo
                                  $arch_label_class = $arch_existente ? ' has-file' : '';
                                  $arch_icon_text   = $arch_existente
                                      ? '<i class="bi bi-check-circle-fill" style="font-size:1.2rem;"></i><span id="ad-file-text">Documento cargado — clic para reemplazar</span>'
                                      : '<i class="bi bi-cloud-upload" style="font-size:1.2rem;"></i><span id="ad-file-text">Haz clic para seleccionar un archivo</span>';
                                  $arch_name_html = $arch_existente
                                      ? '<p class="ad-file-name" id="ad-file-name">'.htmlspecialchars(basename($arch_existente)).'</p>'
                                      : '<p class="ad-file-name" id="ad-file-name"></p>';
                                  $arch_req_badge = $arch_existente ? '' : ' <span style="color:#dc2626">*</span>';

                                  echo '<div class="col-sm-4">
                                          <span class="form-label-sm">
                                            <i class="bi bi-paperclip"></i> Acuerdo de adopción'.$arch_req_badge.'
                                          </span>
                                          <label class="ad-file-label'.$arch_label_class.'" id="ad-file-label" for="archivo_adopcion">
                                            '.$arch_icon_text.'
                                          </label>
                                          <input type="file" name="archivo_adopcion" id="archivo_adopcion"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                                          '.$arch_name_html.'
                                        </div>';
                                  echo '<script>var adArchivoGuardado = '.($arch_existente ? 'true' : 'false').';</script>';
                              } else {
                                  // Periodo cerrado: solo lectura
                                  echo '<div class="col-sm-4">
                                          <span class="form-label-sm">
                                            <i class="bi bi-paperclip"></i> Acuerdo de adopción
                                          </span>';
                                  if ($arch_existente) {
                                      echo '<p class="ad-file-name"><i class="bi bi-file-earmark-check" style="color:#16a34a;margin-right:4px;"></i>'.htmlspecialchars(basename($arch_existente)).'</p>';
                                  } else {
                                      echo '<p class="text-muted" style="font-size:.82rem;"><i class="bi bi-dash-circle"></i> Sin documento adjunto</p>';
                                  }
                                  echo '</div>';
                                  echo '<script>var adArchivoGuardado = true;</script>';
                              }
                          } else {
                              echo '<script>var adArchivoGuardado = true;</script>';
                          }

                          // Observaciones
                          $obs_val = ($count > 0) ? htmlspecialchars($recursos["observaciones"]) : '';
                          echo '<div class="col-sm-5">
                                  <span class="form-label-sm">
                                    <i class="bi bi-chat-left-text"></i> Observaciones
                                  </span>
                                  <textarea class="form-control" name="observaciones" rows="4"
                                    placeholder="Escribe las observaciones sobre la adopción...">'.$obs_val.'</textarea>
                                </div>';

                          echo '</div>';

                          echo '</div>'; // .ad-footer-form
                          echo '</form>';
                       ?>
	
</div>
<script>var librosYaEnAdop = <?= json_encode($ids_exist_adop) ?>;</script>
<script src="../vendors/scripts/core.js"></script>
<script src="../src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="../src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="../src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="../src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/natural.js"></script>
<script>

    $(document).ready(function () {
        $.fn.dataTable.ext.errMode = 'none';
        $('#dataTables-adop').dataTable({
          "language": {
            "lengthMenu": "Display _MENU_ registros por página",
            "zeroRecords": "Nada encontrado, lo siento",
            "emptyTable": "No hay información para mostrar",
            "info": "",
            "infoEmpty": "",
            "infoFiltered": "",
            "search": "Buscar&nbsp;:",
            paginate: {
              first:"Primero",
              previous:"Anterior",
                next:"Siguiente",
                last:"Último"
            }
          },
          "paging": false,
          "searching": false,
          "ordering": true,
          order: [[2, 'asc']],
          columnDefs: [
            { type: 'natural', targets: 2 },
            { orderable: false, targets: '_all' },
            { orderable: true,  targets: 2 }
          ],
        });
    });
	//libros definicion

    $('#gradod').on('change',function(){
        var valor = $(this).val();
        var materia=$("#materiad").val();
        //alert(valor);

        if (valor==17) {
            $(".g_otrod").removeClass("d-none");
            $(".g_otrod").addClass("show");
            $("#grado_otrod").attr("required","required");
                 
        }else {
            $(".g_otrod").addClass("d-none");
            $(".g_otrod").removeClass("show");
            $("#grado_otrod").removeAttr("required");
        }
        var dataString = 'mat_gra='+materia+"/"+valor;
            $.ajax({

                url: "ajax/buscar_l_eureka_p.php",
                type: "POST",
                data: dataString,
                success: function (resp) {
                 
                    $("#libro_ed").html(resp);                        
                    //console.log(resp);
                    if(valor =="") {
                        $("#libro_ed").html("");
                    }
                },
                error: function (jqXHR,estado,error){
                    alert("error");
                    console.log(estado);
                    console.log(error);
                },
                complete: function (jqXHR,estado){
                    console.log(estado);
                }

                        
            })
                
        });

    $('#materiad').on('change',function(){
        var valor = $(this).val();
        var grado = $("#gradod").val()
        //alert(valor);
        var dataString = 'mat_gra='+valor+'/'+grado;
              
        $.ajax({

            url: "ajax/buscar_l_eureka_p.php",
            type: "POST",
            data: dataString,
            dataType: "html",
            success: function (resp) {
                 
                $("#libro_ed").html(resp);                        
                if(valor =="") {
                    $("#libro_ed").html("");
                }
            },
            error: function (jqXHR,estado,error){
                alert("error");
                console.log(estado);
                console.log(error);
            },
            complete: function (jqXHR,estado){
                console.log(estado);
            }

                          
        })
                
    });

    $('#libro_ed').on('change',function(){
        $value=$("#materiad").val()+"/"+$("#gradod").val()+"/"+$(this).val()+"/"+$("#grado_otrod").val();
        $("#libs_aod").val($value);
                              
    });

    var m = 1;
    $("#agregar_aod").click(function(){

      if (m>98) {
        $("#agregar_aod").addClass("d-none");
    }

    $("#agg_aod"+m).removeClass("d-none");
      

    m++;

    <?php for ($i=1; $i < 100; $i++) { ?>

        $('#gradod<?php echo $i; ?>').on('change',function(){
            var valor = $(this).val();
            var materia=$("#materiad<?php echo $i; ?>").val();
            //alert(valor);
            if (valor==17) {
                $(".g_otrod<?php echo $i; ?>").removeClass("d-none");
                $(".g_otrod<?php echo $i; ?>").addClass("show");
                $("#grado_otrod<?php echo $i; ?>").attr("required","required");
                     
            }else {
                $(".g_otrod<?php echo $i; ?>").addClass("d-none");
                $(".g_otrod<?php echo $i; ?>").removeClass("show");
                $("#grado_otrod<?php echo $i; ?>").removeAttr("required");
            }
            var dataString = 'mat_gra='+materia+"/"+valor;
            $.ajax({

                url: "ajax/buscar_l_eureka_p.php",
                type: "POST",
                data: dataString,
                success: function (resp) {
                     
                    $("#libro_ed<?php echo $i; ?>").html(resp);                        
                    console.log(resp);
                    if(valor =="") {
                        $("#libro_ed<?php echo $i; ?>").html("");
                    }
                },
                error: function (jqXHR,estado,error){
                    alert("error");
                    console.log(estado);
                    console.log(error);
                },
                complete: function (jqXHR,estado){
                    console.log(estado);
                }

                            
            })
                
        });

        $('#materiad<?php echo $i; ?>').on('change',function(){
          var valor = $(this).val();
          var grado = $("#gradod<?php echo $i; ?>").val()
          //alert(valor);
          var dataString = 'mat_gra='+valor+'/'+grado;
                
          $.ajax({

            url: "ajax/buscar_l_eureka_p.php",
            type: "POST",
            data: dataString,
            dataType: "html",
            success: function (resp) {
                    
              $("#libro_ed<?php echo $i; ?>").html(resp);                        
              if(valor =="") {
                $("#libro_ed<?php echo $i; ?>").html("");
              }
            },
            error: function (jqXHR,estado,error){
              alert("error");
              console.log(estado);
              console.log(error);
            },
            complete: function (jqXHR,estado){
              console.log(estado);
            }

                            
          })
                  
        });

        $('#libro_ed<?php echo $i; ?>').on('change',function(){
          $value=$("#materiad<?php echo $i; ?>").val()+"/"+$("#gradod<?php echo $i; ?>").val()+"/"+$(this).val()+"/"+$("#grado_otrod<?php echo $i; ?>").val();
          $("#libs_aod<?php echo $i; ?>").val($value);
                   
        });

      <?php } ?>
 
      
    });

    var formatNumber = {
        separador: ".", // separador para los miles
        sepDecimal: ',', // separador para los decimales
        formatear:function (num){
            num +='';
            var splitStr = num.split('.');
            var splitLeft = splitStr[0];
            var splitRight = splitStr.length > 1 ? this.sepDecimal + splitStr[1] : '';
            var regx = /(\d+)(\d{3})/;
            while (regx.test(splitLeft)) {
                splitLeft = splitLeft.replace(regx, '$1' + this.separador + '$2');
            }
            return this.simbol + splitLeft +splitRight;
        },
        new:function(num, simbol){
            this.simbol = simbol ||'';
            return this.formatear(num);
        }
    }

    //seleccionar todo para aprobar
    $('#seleccionar_pre').click(function(){
        if( $('#seleccionar_pre').is(':checked') ) {
            for (i=0;i<document.f2.elements.length;i++)
            if(document.f2.elements[i].type == "checkbox")
                 document.f2.elements[i].checked=1 
        }else{
          
          for (i=0;i<document.f2.elements.length;i++)
          	if(document.f2.elements[i].type == "checkbox")
                document.f2.elements[i].checked=0 

        }
    })

    function setCumCard(pct) {
        var text = isNaN(pct) ? '—' : pct.toFixed(1) + '%';
        $('#cumplimiento').text(text);
        $('#ad-card-cum').text(text);
        var $card = $('#ad-card-cum-wrap');
        var $icon = $('#ad-card-cum-icon');
        var $val  = $('#ad-card-cum');
        if (isNaN(pct)) {
            $card.css({'background':'#fff', 'box-shadow':'0 1px 6px rgba(15,23,42,.08)'});
            $icon.css({'background':'#ede9fe', 'color':'#6d28d9'});
            $val.css('color', '#0f172a');
        } else if (pct < 80) {
            $card.css({'background':'#fee2e2', 'box-shadow':'0 1px 6px rgba(220,38,38,.15)'});
            $icon.css({'background':'#fecaca', 'color':'#dc2626'});
            $val.css('color', '#dc2626');
        } else {
            $card.css({'background':'#dcfce7', 'box-shadow':'0 1px 6px rgba(21,128,61,.15)'});
            $icon.css({'background':'#bbf7d0', 'color':'#15803d'});
            $val.css('color', '#15803d');
        }
    }

    var total_vp_d=0;

     $('.venta1_d').each(function(){

        total_vp_d+=parseFloat($(this).val()) || 0;
        total_vp_d=Math.round(total_vp_d * 100) / 100;

     });
                                        
    $('#total_vp_d').text(formatNumber.new(total_vp_d));

    	total_uni_vr_d=0;

      	$('.uni_vr_d').each(function(){

        total_uni_vr_d+=parseFloat($(this).val()) || 0;

        total_uni_vr_d=Math.round(total_uni_vr_d * 100) / 100;
                                              

    });

    $('#total_vr').text(formatNumber.new(total_uni_vr_d));

   	var cumplimiento=(total_uni_vr_d / total_vp_d) * 100;

    // ── Actualizar tarjetas de resumen ────────────────────────
    $('#ad-card-vp').text($('#total_vp_d').text() || '—');
    $('#ad-card-vr').text($('#total_vr').text()   || '—');
    setCumCard(cumplimiento);

    // ── Filtro todos / adoptados ──────────────────────────────
    var $filas = $('#dataTables-adop tbody tr');

    $('.ad-filter-btn').on('click', function(){
      $('.ad-filter-btn').removeClass('active');
      $(this).addClass('active');
      var filtro = $(this).data('filter');
      if (filtro === 'adoptados') {
        $filas.hide().filter('[data-adoptado="1"]').show();
      } else {
        $filas.show();
      }
    });

    // ── Toast ────────────────────────────────────────────────────
    function adToast(msg, tipo) {
        var $t = $('#ad-toast');
        var icon = tipo === 'error' ? 'bi bi-x-circle-fill' : 'bi bi-check-circle-fill';
        $t.removeClass('ok error').addClass(tipo);
        $t.find('i').attr('class', icon);
        $t.find('.pr-toast-msg').text(msg);
        $t.addClass('show');
        setTimeout(function(){ $t.removeClass('show'); }, 3500);
    }

    // ── Validar duplicados al guardar libros en adopciones ───────
    $('#modal_adopciones form.miFormulario').on('submit', function(e) {
        var ids = [];
        var errMsg = '';
        $('input[name="libs_aod[]"]').each(function() {
            var val = $(this).val();
            if (!val) return;
            var libroId = val.split('/')[2];
            if (!libroId || libroId === '0') return;
            if (ids.indexOf(libroId) !== -1) {
                errMsg = 'Hay libros repetidos en el formulario';
                return false;
            }
            if (librosYaEnAdop.indexOf(parseInt(libroId)) !== -1) {
                errMsg = 'Uno de los libros ya existe en adopciones';
                return false;
            }
            ids.push(libroId);
        });
        if (errMsg) {
            e.preventDefault();
            adToast(errMsg, 'error');
            return false;
        }
    });

    // ── Campo de archivo: mostrar nombre seleccionado ────────────
    $('#archivo_adopcion').on('change', function() {
        var $label = $('#ad-file-label');
        var $name  = $('#ad-file-name');
        if (this.files && this.files.length > 0) {
            var fname = this.files[0].name;
            $('#ad-file-text').text('Archivo seleccionado');
            $name.text(fname);
            $label.addClass('has-file');
        } else {
            $('#ad-file-text').text('Haz clic para seleccionar un archivo');
            $name.text('');
            $label.removeClass('has-file');
        }
    });

    // ── Validar archivo antes de guardar adopciones ──────────────
    var requiereAcuerdo = <?= ($gp_periodo["periodo"] >= 2027) ? 'true' : 'false'; ?>;

    // ── Validar archivo antes de guardar adopciones ──────────────
    $('#form_definicion').on('submit', function(e) {

        if (!requiereAcuerdo) {
            return true;
        }

        var archivo = $('#archivo_adopcion')[0];
        var tieneNuevo = archivo && archivo.files.length > 0;

        if (!adArchivoGuardado && !tieneNuevo) {
            e.preventDefault();
            adToast('Debes adjuntar el acuerdo de adopción antes de guardar.', 'error');
            $('#ad-file-label').css({'border-color':'#dc2626','background':'#fef2f2'});
            setTimeout(function(){
                $('#ad-file-label').css({'border-color':'','background':''});
            }, 2500);
            return false;
        }
    });

</script>

<div class="pr-toast" id="ad-toast">
  <i class="bi bi-check-circle-fill"></i>
  <span class="pr-toast-msg"></span>
</div>

</div><!-- /.ad-wrap -->