<?php
	ini_set("display_errors", 1);
	ini_set("display_startup_errors", 1);
	error_reporting(E_ALL);

	require_once("../php/aut.php");
	include("../conexion/bdd.php");

	$sql_periodo = "SELECT * FROM periodos WHERE id='".$_GET['periodo']."'";
	$req_periodo = $bdd->prepare($sql_periodo);
	$req_periodo->execute();
	$gp_periodo = $req_periodo->fetch();

	$sql_costo_ia = "SELECT mt.id AS id_modelo_tokens, COALESCE(mt.valor_entrada * mt.tokens_entrada + mt.valor_salida * mt.tokens_salida, 0) AS costo_ia
	                  FROM ia_modelos m
	                  JOIN ia_modelo_tokens mt ON mt.id_modelo = m.id
	                  WHERE m.activo = 1
	                  ORDER BY mt.id DESC LIMIT 1";
	$modelo_activo = $bdd->query($sql_costo_ia)->fetch();
	$costo_ia = $modelo_activo['costo_ia'] ?? 0;

	$trm_actual = $bdd->query("SELECT trm FROM ia_trm ORDER BY fecha DESC, id DESC LIMIT 1")->fetch()['trm'] ?? 0;
	$costo_ia_cop = $costo_ia * $trm_actual;

	$req_cant_profes = $bdd->prepare("SELECT COUNT(*) AS total FROM trabajadores_colegios WHERE id_colegio=? AND cargo=6 AND activo=1");
	$req_cant_profes->execute([$_GET['colegio']]);
	$cantidad_profesores = $req_cant_profes->fetch()['total'];

	$req_interacciones = $bdd->prepare("SELECT interacciones FROM ia_presupuestos WHERE id_modelo_tokens=? AND id_periodo=? ORDER BY id DESC LIMIT 1");
	$req_interacciones->execute([$modelo_activo['id_modelo_tokens'] ?? 0, $_GET['periodo']]);
	$interacciones = $req_interacciones->fetch()['interacciones'] ?? 0;

	$costo_semanal = $costo_ia_cop * $interacciones * $cantidad_profesores;
	$costo_anual = $costo_semanal * 53;
?>

<style>
  /* ── Contenedor ─────────────────────────────────────────────── */
  .pr-wrap { padding: 24px; }

  /* ── Tarjetas de resumen ────────────────────────────────────── */
  .pr-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }
  .pr-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 6px rgba(15,23,42,.08);
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .pr-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
    background: #ede9fe;
    color: #6d28d9;
  }
  .pr-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
  .pr-card-icon.orange { background: #ffedd5; color: #c2410c; }
  .pr-card-icon.green  { background: #dcfce7; color: #15803d; }
  .pr-card-icon.teal   { background: #ccfbf1; color: #0f766e; }
  .pr-card-label { font-size: 0.74rem; color: #64748b; margin: 0 0 2px 0; }
  .pr-card-val   { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; }

  /* ── Encabezado ─────────────────────────────────────────────── */
  .pr-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .pr-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0 0 2px 0;
  }
  .pr-title i { color: #6c63ff; margin-right: 6px; }
  .pr-subtitle { font-size: 0.82rem; color: #718096; margin: 0; }
  .pr-actions  { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

  /* ── Contenedor con scroll propio ───────────────────────────── */
  .pr-table-wrap {
    border-radius: 10px;
    overflow: auto;
    max-height: 60vh;
    box-shadow: 0 2px 12px rgba(15,23,42,.12);
    scrollbar-width: thin;
    scrollbar-color: #4361ee #e2e8f0;
  }
  .pr-table-wrap::-webkit-scrollbar        { height: 10px; width: 10px; }
  .pr-table-wrap::-webkit-scrollbar-track  { background: #e2e8f0; border-radius: 0 0 10px 10px; }
  .pr-table-wrap::-webkit-scrollbar-thumb  { background: #4361ee; border-radius: 10px; border: 2px solid #e2e8f0; }
  .pr-table-wrap::-webkit-scrollbar-thumb:hover { background: #2a3fc7; }

  /* ── Tabla ──────────────────────────────────────────────────── */
  #tabla-presup {
    width: 100%;
    font-size: 0.82rem;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1000px;
  }
  #tabla-presup thead th {
    background: #f8fafc;
    color: #374151;
    font-weight: 600;
    padding: 11px 8px;
    text-align: center;
    white-space: nowrap;
    border: none;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.80rem;
    letter-spacing: .02em;
    position: sticky;
    top: 0;
    z-index: 2;
  }
  #tabla-presup thead th:first-child { text-align: left; padding-left: 14px; }

  #tabla-presup tbody tr { background: #fff; }
  #tabla-presup tbody tr:nth-child(even) { background: #f8fafc; }
  #tabla-presup tbody tr:hover { background: #eff6ff; }
  #tabla-presup tbody td {
    padding: 7px 8px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
    text-align: center;
    color: #1e293b;
    font-size: 0.82rem;
  }
  #tabla-presup tbody td:first-child { text-align: left; font-weight: 500; color: #0f172a; }

  /* venta potencial en naranja cuando tiene valor */
  .venta { color: #ea580c; font-weight: 600; }

  /* inputs tasa / descuento */
  .pr-input {
    width: 48px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    padding: 3px 4px;
    text-align: center;
    font-size: 0.80rem;
    background: #f8fafc;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    color: #1e293b;
  }
  .pr-input:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 2px rgba(99,102,241,.2);
  }

  /* select probabilidad — base */
  .pr-prob {
    border: none;
    border-radius: 20px;
    padding: 4px 10px;
    font-size: 0.76rem;
    font-weight: 700;
    cursor: pointer;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    text-align: center;
    min-width: 120px;
    transition: background .2s, color .2s;
  }
  /* colores por nivel */
  .pr-prob.prob-sin      { background: #f1f5f9; color: #64748b; }
  .pr-prob.prob-alta     { background: #ede9fe; color: #6d28d9; }
  .pr-prob.prob-media    { background: #ffedd5; color: #c2410c; }
  .pr-prob.prob-perdida  { background: #fee2e2; color: #b91c1c; }
  .pr-prob.prob-adoptada { background: #dcfce7; color: #15803d; }
  .pr-prob.prob-baja     { background: #dbeafe; color: #1d4ed8; }

  /* fila total */
  #tabla-presup tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    font-size: 0.85rem;
    background: #f8fafc;
    color: #374151;
    border: none;
    border-top: 2px solid #e2e8f0;
    text-align: center;
  }
  #tabla-presup tfoot td:first-child { text-align: left; padding-left: 14px; }

  /* botón borrar por fila */
  .pr-btn-del {
    background: none;
    border: 1px solid #fca5a5;
    color: #dc2626;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: background .15s;
  }
  .pr-btn-del:hover { background: #fee2e2; }

  /* footer guardar */
  .pr-footer { display: flex; justify-content: center; margin-top: 22px; }

  /* ── Toast de notificación ──────────────────────────────────── */
  .pr-toast {
    position: fixed;
    top: 24px;
    right: 24px;
    min-width: 260px;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 0.87rem;
    font-weight: 600;
    color: #fff;
    z-index: 99999;
    box-shadow: 0 6px 20px rgba(0,0,0,.18);
    display: flex;
    align-items: center;
    gap: 10px;
    opacity: 0;
    transform: translateY(-16px);
    transition: opacity .3s, transform .3s;
    pointer-events: none;
  }
  .pr-toast.show  { opacity: 1; transform: translateY(0); }
  .pr-toast.ok    { background: #16a34a; }
  .pr-toast.error { background: #dc2626; }
  .pr-toast i { font-size: 1.1rem; }


  /* ── Modal de confirmación ──────────────────────────────────── */
  .pr-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 99998;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s;
  }
  .pr-overlay.open { opacity: 1; pointer-events: all; }
  .pr-confirm-box {
    background: #fff;
    border-radius: 14px;
    padding: 28px 32px;
    max-width: 380px;
    width: 90%;
    text-align: center;
    box-shadow: 0 10px 40px rgba(15,23,42,.2);
    transform: scale(.95);
    transition: transform .2s;
  }
  .pr-overlay.open .pr-confirm-box { transform: scale(1); }
  .pr-confirm-icon { font-size: 2.2rem; color: #dc2626; margin-bottom: 10px; }
  .pr-confirm-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
  .pr-confirm-msg   { font-size: 0.85rem; color: #64748b; margin: 0 0 22px; }
  .pr-confirm-btns  { display: flex; gap: 10px; justify-content: center; }
  .pr-confirm-btns .btn-cancel { background: #f1f5f9; color: #475569; border: none; border-radius: 8px; padding: 8px 22px; font-size: 0.85rem; cursor: pointer; }
  .pr-confirm-btns .btn-ok     { background: #dc2626; color: #fff;     border: none; border-radius: 8px; padding: 8px 22px; font-size: 0.85rem; cursor: pointer; font-weight: 600; }
</style>

<div class="pr-wrap">

  <!-- Encabezado -->
  <div class="pr-header">
    <div>
      <h5 class="pr-title"><i class="bi bi-book-half"></i> Presupuesto de libros</h5>
      <p class="pr-subtitle">Gestiona el presupuesto de libros aprobados y su probabilidad de venta</p>
    </div>
    <div class="pr-actions">
      <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal_presupuesto" type="button">
        <i class="bi bi-plus-circle"></i> Añadir libros
      </a>
      <a class="btn btn-success btn-sm" href="php/presupuesto_excel.php?cole=<?= htmlspecialchars($_GET['colegio']) ?>&periodo=<?= htmlspecialchars($_GET['periodo']) ?>">
        <i class="bi bi-file-earmark-excel"></i> Exportar Excel
      </a>
    </div>
  </div>

  <?php if ($gp_periodo["periodo"] >= 2027): ?>
  <!-- Tarjetas de resumen -->
  <div class="pr-cards">
    <div class="pr-card">
      <div class="pr-card-icon"><i class="bi bi-cpu"></i></div>
      <div>
        <p class="pr-card-label">Costo IA (por interacción)</p>
        <p class="pr-card-val">$<?= number_format($costo_ia_cop, 2, ",", ".") ?> COP</p>
      </div>
    </div>
    <div class="pr-card">
      <div class="pr-card-icon blue"><i class="bi bi-person-video3"></i></div>
      <div>
        <p class="pr-card-label">Cantidad de profesores</p>
        <p class="pr-card-val"><?= $cantidad_profesores ?></p>
      </div>
    </div>
    <div class="pr-card">
      <div class="pr-card-icon teal"><i class="bi bi-chat-dots"></i></div>
      <div>
        <p class="pr-card-label">Cantidad de interacciones</p>
        <p class="pr-card-val"><?= $interacciones ?></p>
      </div>
    </div>
    <div class="pr-card">
      <div class="pr-card-icon orange"><i class="bi bi-calendar-week"></i></div>
      <div>
        <p class="pr-card-label">Costo semanal</p>
        <p class="pr-card-val">$<?= number_format($costo_semanal, 2, ",", ".") ?> COP</p>
      </div>
    </div>
    <div class="pr-card">
      <div class="pr-card-icon green"><i class="bi bi-calendar-range"></i></div>
      <div>
        <p class="pr-card-label">Costo anual</p>
        <p class="pr-card-val">$<?= number_format($costo_anual, 2, ",", ".") ?> COP</p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modal añadir libros -->
  <style>
    .lb-modal .modal-content  { border:none; border-radius:12px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.15); }
    .lb-modal .modal-header   { background:#fff; padding:18px 24px 14px; border-bottom:1px solid #e9ecef; }
    .lb-modal .modal-title    { font-size:16px; font-weight:700; color:#111827; margin:0 0 3px; }
    .lb-modal .modal-subtitle { font-size:12.5px; color:#6b7280; margin:0; }
    .lb-modal .close          { color:#9ca3af; opacity:1; text-shadow:none; font-size:1.3rem; margin-top:-4px; }
    .lb-modal .close:hover    { color:#374151; }
    .lb-modal .modal-body     { padding:20px 24px; background:#f9fafb; max-height:65vh; overflow-y:auto; }
    .lb-modal .modal-footer   { border-top:1px solid #e9ecef; padding:14px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center; }

    .lb-book-item {
      background:#fff; border:1px solid #e5e7eb; border-radius:10px;
      padding:16px 18px; margin-bottom:10px;
      box-shadow:0 1px 4px rgba(0,0,0,.04);
    }
    .lb-book-num {
      font-size:12px; font-weight:700; color:#4f46e5;
      margin:0 0 14px; display:flex; align-items:center; gap:5px;
    }
    .lb-book-num::before {
      content:''; display:inline-block; width:3px; height:14px;
      background:#4f46e5; border-radius:2px;
    }

    .lb-modal .form-group { margin-bottom:0; }
    .lb-modal .form-group label {
      font-size:12px; font-weight:600; color:#374151; margin-bottom:5px; display:block;
    }
    .lb-modal .form-control {
      border-radius:7px; font-size:13px; border:1px solid #d1d5db;
      padding:7px 10px; background:#fff; color:#111827;
      transition:border-color .15s, box-shadow .15s;
    }
    .lb-modal .form-control:focus {
      border-color:#4f46e5; background:#fff;
      box-shadow:0 0 0 3px rgba(79,70,229,.1); outline:none;
    }
    .lb-req { color:#ef4444; }

    .lb-add-btn {
      display:inline-flex; align-items:center; gap:5px;
      color:#4f46e5; font-size:13px; font-weight:600;
      cursor:pointer; border:none; background:none; padding:0;
      text-decoration:none;
    }
    .lb-add-btn:hover { text-decoration:underline; color:#4f46e5; }

    .lb-modal .btn-primary {
      background:#4f46e5; border-color:#4f46e5;
      border-radius:8px; padding:8px 22px; font-weight:600; font-size:13.5px;
    }
    .lb-modal .btn-primary:hover { background:#4338ca; border-color:#4338ca; }
    .lb-modal .btn-light { border-radius:8px; font-size:13.5px; }
  </style>

  <div class="modal fade lb-modal" id="modal_presupuesto" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">

        <div class="modal-header">
          <div>
            <h5 class="modal-title">Añadir libros</h5>
            <p class="modal-subtitle">Completa la información del libro que quieres añadir al presupuesto</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
        </div>

        <form action="php/areas_objetivas.php" method="POST" class="miFormulario">
        <div class="modal-body">

          <div class="otra_ao">
            <div class="lb-book-item">
              <p class="lb-book-num"><i class="bi bi-bookmark"></i> Libro #1</p>
              <div class="row">
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label for="materia">Materia <span class="lb-req">*</span></label>
                    <select name="materia" id="materia" class="form-control materia">
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
                    <label for="grado">Grado <span class="lb-req">*</span></label>
                    <select name="grado" required id="grado" class="form-control grado">
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
                <div class="col-sm col-6 g_otro d-none">
                  <div class="form-group">
                    <label for="grado_otro" id="l_grado_otro">Grado específico <span class="lb-req">*</span></label>
                    <select name="grado_otro" id="grado_otro" class="form-control">
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
                    <label for="libro_e">Libro <span class="lb-req">*</span></label>
                    <select name="libro_e" id="libro_e" class="form-control grado custom-select2" required></select>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <input type="hidden" name="libs_ao[]" id="libs_ao">

          <?php for ($i=1; $i < 100; $i++): ?>
          <div id="agg_ao<?= $i ?>" class="d-none">
            <div class="lb-book-item">
              <p class="lb-book-num"><i class="bi bi-bookmark"></i> Libro #<?= $i+1 ?></p>
              <div class="row">
                <div class="col-sm col-6">
                  <div class="form-group">
                    <label>Materia <span class="lb-req">*</span></label>
                    <select name="materia1" id="materia<?= $i ?>" class="form-control materia">
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
                    <select name="grado1" id="grado<?= $i ?>" class="form-control grado">
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
                <div class="col-sm col-6 g_otro<?= $i ?> d-none">
                  <div class="form-group">
                    <label id="l_grado_otro<?= $i ?>">Grado específico <span class="lb-req">*</span></label>
                    <select name="grado_otro" id="grado_otro<?= $i ?>" class="form-control">
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
                    <select name="libro_e" id="libro_e<?= $i ?>" class="form-control grado custom-select2"></select>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <input type="hidden" name="libs_ao[]" id="libs_ao<?= $i ?>">
          <?php endfor; ?>

          <input type="hidden" name="promotor"    value="<?= $_GET['promotor'] ?>">
          <input type="hidden" name="id_colegio"  id="cole" value="<?= $_GET['colegio'] ?>">
          <input type="hidden" name="cod_colegio" value="<?= $_GET['codigo'] ?>">
          <input type="hidden" name="cod_zona"    value="<?= $_GET['cod_zona'] ?>">
          <input type="hidden" name="sub_zona"    value="<?= $_GET['sub_zona'] ?>">
          <input type="hidden" name="responsable" value="<?= $_GET['responsable'] ?>">
          <input type="hidden" name="periodo"     value="<?= $_GET['periodo'] ?>">

        </div><!-- /.modal-body -->

        <div class="modal-footer">
          <?php if ($_SESSION["tipo"] != 2 && $_SESSION["tipo"] != 4): ?>
            <?php if ($_SESSION["zona"] == $_GET['cod_zona'] || $_SESSION["tipo"] == 1): ?>
              <?php if ($_GET["f_cierre"] > date("Y-m-d")): ?>
                <button type="button" class="lb-add-btn" id="agregar_ao">
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

  <!-- Tabla de presupuesto -->
  <?php
    $sql = "SELECT p.id as pid, p.cod_area, b.materia, c.grado,l.id, l.libro,l.id_materia, l.id_grado, l.pri_sec, l.precio, desc_max, desc_max_dist FROM presupuestos p JOIN libros l ON p.id_libro=l.id JOIN materias b ON l.id_materia=b.id JOIN grados c ON l.id_grado=c.id WHERE id_colegio='".$_GET["colegio"]."' AND id_periodo='".$_GET["periodo"]."' AND p.pre_aprob=1 ORDER BY l.id_grado ASC";
    $req = $bdd->prepare($sql);
    $req->execute();
    $libros_p = $req->fetchAll();

    $sql_hp = "SELECT id FROM presupuestos WHERE id_periodo='".$_GET["periodo"]."' AND id_colegio='".$_GET["colegio"]."'";
    $req_hp = $bdd->prepare($sql_hp);
    $req_hp->execute();
    $num_hp = $req_hp->rowCount();

    $sql_exist_p = "SELECT DISTINCT id_libro FROM presupuestos WHERE id_colegio='".$_GET["colegio"]."' AND id_periodo='".$_GET["periodo"]."'";
    $req_exist_p = $bdd->prepare($sql_exist_p);
    $req_exist_p->execute();
    $ids_exist_presup = array_map('intval', array_column($req_exist_p->fetchAll(PDO::FETCH_ASSOC), 'id_libro'));

    echo "<form action='php/actualizar_presupuesto.php' method='POST' id='pp' class='miFormulario'>";

    echo "<div class='pr-table-wrap mt-2'>
        <table id='tabla-presup'>
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
                    <th>Venta ajustada</th>
                    <th>Probabilidad</th>
                    <th>Borrar</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($libros_p as $libro_p) {

        if ($libro_p["cod_area"] !="") {
            $libro_p["id_grado"] = 17;
        }

        $id_real_libro = $libro_p["id"];

        if ($libro_p["id_grado"] != 17) {
            $sql_presup = "SELECT id, precio, tasa_compra, descuento, pre_aprob, aprobado, probabilidad FROM presupuestos WHERE id_libro='".$libro_p["id"]."' AND id_periodo='".$_GET["periodo"]."' AND id_colegio='".$_GET["colegio"]."'";
        } else {
            $sql_presup = "SELECT id, precio, tasa_compra, descuento, pre_aprob, aprobado, probabilidad FROM presupuestos WHERE cod_area='".$libro_p["cod_area"]."' AND id_periodo='".$_GET["periodo"]."' AND id_colegio='".$_GET["colegio"]."'";
        }
        $req_presup = $bdd->prepare($sql_presup);
        $req_presup->execute();
        $presup = $req_presup->fetch();

        if ($libro_p["id_grado"] != 17) {
            $sq_gp = "SELECT paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$_GET['colegio']."' AND id_grado='".$libro_p["id_grado"]."' AND id_periodo='".$_GET["periodo"]."'";
        } else {
            $sql_go = "SELECT id_grado_otro FROM areas_objetivas WHERE codigo='".$libro_p["cod_area"]."'";
            $req_go = $bdd->prepare($sql_go);
            $req_go->execute();
            $go = $req_go->fetch();
            $sq_gp = "SELECT paralelos, SUM(alumnos) as alumnos FROM grados_paralelos WHERE id_colegio='".$_GET['colegio']."' AND id_grado='".$go["id_grado_otro"]."' AND id_periodo='".$_GET["periodo"]."'";
        }
        $req_gp = $bdd->prepare($sq_gp);
        $req_gp->execute();
        $gp = $req_gp->fetch();

        echo "<tr>
            <td>".$libro_p["libro"]."</td>
            <td>".$libro_p["materia"]."</td>";

        if ($libro_p["id_grado"] != 17) {
            echo "<td>".$libro_p["grado"]."</td>";
        } else {
            $libro_101 = $libro_p["id"];
            $libro_p["id"] = $libro_p["cod_area"];
            $sql_otrg = "SELECT g.grado FROM grados g JOIN areas_objetivas a ON g.id=a.id_grado_otro WHERE a.codigo='".$libro_p["cod_area"]."'";
            $req_otrg = $bdd->prepare($sql_otrg);
            $req_otrg->execute();
            $otrg = $req_otrg->fetch();
            echo "<td>".$otrg["grado"]."</td>";
        }

        echo "<!--<td>".$gp["paralelos"]."</td>-->
            <td id='alm_p".$libro_p["id"]."'>".$gp["alumnos"]."</td>";

        // Tasa de compra
        if ($presup["tasa_compra"] != 0.00) {
            $presup["tasa_compra"] = $presup["tasa_compra"] * 100;
            echo "<td><input type='text' class='pr-input' name='tasa[]' id='tasa_p".$libro_p["id"]."' value='".$presup["tasa_compra"]."'> %</td>";
        } else {
            if ($libro_p["id_grado"] < 4) {
                echo "<td><input type='text' class='pr-input' name='tasa[]' id='tasa_p".$libro_p["id"]."' value='".$gp_periodo["t_preescolar"]."' required> %</td>";
            } elseif ($libro_p["id_grado"] < 9 && $libro_p["id_grado"] > 3) {
                echo "<td><input type='text' class='pr-input' name='tasa[]' id='tasa_p".$libro_p["id"]."' value='".$gp_periodo["t_primaria"]."' required> %</td>";
            } elseif ($libro_p["id_grado"] > 8 && $libro_p["id_grado"] < 13) {
                echo "<td><input type='text' class='pr-input' name='tasa[]' id='tasa_p".$libro_p["id"]."' value='".$gp_periodo["t_6_9"]."' required> %</td>";
            } else {
                echo "<td><input type='text' class='pr-input' name='tasa[]' id='tasa_p".$libro_p["id"]."' value='".$gp_periodo["t_10_11"]."' required> %</td>";
            }
        }

        // PVP
        if ($presup["precio"] != "" && $presup["precio"] != 0) {
            $precio = number_format($presup["precio"], 0, ",", ".");
            echo "<td id='pvp_p".$libro_p["id"]."'>".$precio."</td>";
            echo "<input type='hidden' id='pvp_s_p".$libro_p["id"]."' value='".$presup["precio"]."'>";
        } else {
            $precio = number_format($libro_p["precio"], 0, ",", ".");
            echo "<td id='pvp_p".$libro_p["id"]."'>".$precio."</td>";
            echo "<input type='hidden' id='pvp_s_p".$libro_p["id"]."' value='".$libro_p["precio"]."'>";
        }

        // Descuento
        if ($presup["descuento"] != "") {
            $presup_m = $presup["descuento"] * 100;
            echo "<td><input type='text' class='pr-input' name='descuento[]' id='descuento_p".$libro_p["id"]."' value='".$presup_m."'> %</td>";
        } else {
            echo "<td><input type='text' class='pr-input' name='descuento[]' value='20' id='descuento_p".$libro_p["id"]."' required> %</td>";
        }

        // Precio neto y venta potencial
        if ($presup["tasa_compra"] != "") {
            $precio_neto = $libro_p["precio"] - ($libro_p["precio"] * $presup["descuento"]);
            if ($presup["probabilidad"] != 3) {
                $venta_p = $precio_neto * floor($gp["alumnos"] * $presup["tasa_compra"] / 100);
            } else {
                $venta_p = 0;
            }
            $precio_ne = number_format($precio_neto, 2, ",", ".");
            echo "<td id='pn_p".$libro_p["id"]."'>".$precio_ne."</td>";
            echo "<input type='hidden' id='pn_s_p".$libro_p["id"]."' value='".$precio_neto."'>";
            if ($presup["probabilidad"] != 3) {
                $venta_po = number_format($venta_p, 0, ",", ".");
            } else {
                $venta_po = 0;
            }
            echo "<td id='venta_p_p".$libro_p["id"]."' class='venta'>".$venta_po."</td>
                  <input type='hidden' id='venta_ps_p".$libro_p["id"]."' class='venta1_p' value='".$venta_p."'>";
        } else {
            echo "<td id='pn_p".$libro_p["id"]."'></td>
                  <td id='venta_p_p".$libro_p["id"]."' class='venta'></td>
                  <input type='hidden' id='venta_ps_p".$libro_p["id"]."' class='venta1_p'>";
        }

        // Probabilidad
        $sql_prob = "SELECT * FROM probabilidades";
        $req_prob = $bdd->prepare($sql_prob);
        $req_prob->execute();
        $probs = $req_prob->fetchAll();

        // Venta ajustada = venta potencial × (probabilidad %)
        $prob_valor_actual = 0;
        foreach ($probs as $prob) {
            if ($prob['id'] == $presup['probabilidad']) {
                $prob_valor_actual = floatval($prob['valor']);
                break;
            }
        }
        $venta_ajustada = isset($venta_p) ? round($venta_p * ($prob_valor_actual / 100)) : 0;
        $venta_ajustada_fmt = $venta_ajustada > 0 ? number_format($venta_ajustada, 0, ",", ".") : 0;
        echo "<td id='venta_aj_p".$libro_p["id"]."' class='venta-aj'>".$venta_ajustada_fmt."</td>
              <input type='hidden' id='venta_ajs_p".$libro_p["id"]."' class='venta1_aj_p' value='".$venta_ajustada."'>";

        if ($presup["probabilidad"] == 0) {
            echo '<td>
                <select class="pr-prob" name="proba[]" id="proba_p'.$libro_p["id"].'">
                    <option value="">Seleccione</option>';
            foreach ($probs as $prob) {
                echo '<option value="'.$prob["id"].'" data-valor="'.$prob["valor"].'">'.$prob["probabilidad"].' ( '.$prob["valor"].' % )</option>';
            }
            echo '</select></td>';
        } else {
            echo '<td>
                <select class="pr-prob" name="proba[]" id="proba_p'.$libro_p["id"].'">
                    <option value="">Seleccione</option>';
            foreach ($probs as $prob) {
                if ($presup["probabilidad"] == $prob["id"]) {
                    echo '<option value="'.$prob["id"].'" data-valor="'.$prob["valor"].'" SELECTED>'.$prob["probabilidad"].' ( '.$prob["valor"].' % )</option>';
                } else {
                    echo '<option value="'.$prob["id"].'" data-valor="'.$prob["valor"].'">'.$prob["probabilidad"].' ( '.$prob["valor"].' % )</option>';
                }
            }
            echo '</select></td>';
        }

        // Borrar
        echo "<td>";
        if ($gp_periodo["f_cierre"] > date("Y-m-d")) {
            echo "<button class='pr-btn-del pr-btn-row-del' type='button'
                    data-pid='".$libro_p["pid"]."'
                    data-libro='".$id_real_libro."'
                    data-codigo='".htmlspecialchars($_GET["codigo"])."'
                    data-periodo='".htmlspecialchars($_GET["periodo"])."'>
                    <i class='fa fa-trash-o'></i></button>";
        }
        echo "</td>";

        echo "<input type='hidden' name='presupuesto_p[]' id='presupuesto_p".$libro_p["id"]."'>";

        echo "<script>

            $('#descuento_p".$libro_p["id"]."').keyup(function(){
                var pvp=parseInt($('#pvp_s_p".$libro_p["id"]."').val());
                var descuento=parseFloat($('#descuento_p".$libro_p["id"]."').val());";
                if ($_SESSION['tipo']!=6) {
                    echo "var desc_max=parseFloat(".$libro_p["desc_max"].")* 100;";
                } else {
                    echo "var desc_max=parseFloat(".$libro_p["desc_max_dist"].")* 100;";
                }
                if (isset($libro_101)) {
                    if ($libro_101 !=3481 && $libro_101 !=3482) {
                        echo "if (descuento > 69){
                            alert('el descuento no debe superar el 69%');
                            $('#descuento_p".$libro_p["id"]."').val('20');
                            $('#descuento_p".$libro_p["id"]."').focus();
                            descuento=20;
                        }";
                    }
                } else {
                    echo "if (descuento > 69){
                        alert('el descuento no debe superar el 69%');
                        $('#descuento_p".$libro_p["id"]."').val('20');
                        $('#descuento_p".$libro_p["id"]."').focus();
                        descuento=20;
                    }";
                }
                echo"
                if (desc_max > 0){
                    if (descuento > desc_max){
                        alert('el descuento no debe superar: '+desc_max);
                        $('#descuento_p".$libro_p["id"]."').val(desc_max);
                        $('#descuento_p".$libro_p["id"]."').focus();
                        descuento=desc_max;
                    }
                }
                descuento= descuento/100;
                var precio_neto= pvp - (pvp * descuento);
                if(isNaN(precio_neto)){ precio_neto=0 }
                $('#pn_p".$libro_p["id"]."').text(formatNumber.new(precio_neto));
                var tasa_c=parseInt($('#tasa_p".$libro_p["id"]."').val());
                tasa_c=tasa_c/100;
                var alumnos=parseInt($('#alm_p".$libro_p["id"]."').text());
                var vp= precio_neto *(Math.floor(alumnos*tasa_c))
                if(isNaN(vp)){ vp=0; }
                $('#venta_p_p".$libro_p["id"]."').text(formatNumber.new(vp));
                $('#venta_ps_p".$libro_p["id"]."').val(vp);
                var prob_valor=parseFloat($('#proba_p".$libro_p["id"]."').find('option:selected').data('valor')) || 0;
                var venta_aj=Math.round(vp*(prob_valor/100));
                if(isNaN(venta_aj)){venta_aj=0;}
                $('#venta_aj_p".$libro_p["id"]."').text(formatNumber.new(venta_aj));
                $('#venta_ajs_p".$libro_p["id"]."').val(venta_aj);
                var total_vp_aj_p=0;
                $('.venta1_aj_p').each(function(){
                    total_vp_aj_p+=parseFloat($(this).val()) || 0;
                    total_vp_aj_p=Math.round(total_vp_aj_p);
                });
                $('#total_vp_aj_p').text(formatNumber.new(total_vp_aj_p));
                var probab=$('#proba_p".$libro_p["id"]."').val();
                $('#presupuesto_p".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+probab);
                var total_vp_p=0;
                $('.venta1_p').each(function(){
                    total_vp_p+=parseFloat($(this).val()) || 0;
                    total_vp_p=Math.round(total_vp_p * 100) / 100;
                });
                $('#total_vp_p').text(formatNumber.new(total_vp_p));
            })

            $('#tasa_p".$libro_p["id"]."').keyup(function(){
                var pvp=parseInt($('#pvp_s_p".$libro_p["id"]."').val());
                var descuento=parseFloat($('#descuento_p".$libro_p["id"]."').val());
                descuento= descuento/100;
                var precio_neto= pvp - (pvp * descuento);
                if(isNaN(precio_neto)){ precio_neto=0 }
                $('#pn_p".$libro_p["id"]."').text(formatNumber.new(precio_neto));
                var tasa_c=parseInt($('#tasa_p".$libro_p["id"]."').val());
                tasa_c=tasa_c/100;
                var alumnos=parseInt($('#alm_p".$libro_p["id"]."').text());
                var vp= precio_neto *(Math.floor(alumnos*tasa_c))
                if(isNaN(vp)){ vp=0; }
                $('#venta_p_p".$libro_p["id"]."').text(formatNumber.new(vp));
                $('#venta_ps_p".$libro_p["id"]."').val(vp);
                var prob_valor=parseFloat($('#proba_p".$libro_p["id"]."').find('option:selected').data('valor')) || 0;
                var venta_aj=Math.round(vp*(prob_valor/100));
                if(isNaN(venta_aj)){venta_aj=0;}
                $('#venta_aj_p".$libro_p["id"]."').text(formatNumber.new(venta_aj));
                $('#venta_ajs_p".$libro_p["id"]."').val(venta_aj);
                var total_vp_aj_p=0;
                $('.venta1_aj_p').each(function(){
                    total_vp_aj_p+=parseFloat($(this).val()) || 0;
                    total_vp_aj_p=Math.round(total_vp_aj_p);
                });
                $('#total_vp_aj_p').text(formatNumber.new(total_vp_aj_p));
                var probab=$('#proba_p".$libro_p["id"]."').val();
                $('#presupuesto_p".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+probab);
                var total_vp_p=0;
                $('.venta1_p').each(function(){
                    total_vp_p+=parseFloat($(this).val()) || 0;
                    total_vp_p=Math.round(total_vp_p * 100) / 100;
                });
                $('#total_vp_p').text(formatNumber.new(total_vp_p));
            })

            $('#proba_p".$libro_p["id"]."').change(function(){
                var pvp=parseInt($('#pvp_s_p".$libro_p["id"]."').val());
                var descuento=parseFloat($('#descuento_p".$libro_p["id"]."').val());
                descuento= descuento/100;
                var precio_neto= pvp - (pvp * descuento);
                if(isNaN(precio_neto)){ precio_neto=0 }
                $('#pn_p".$libro_p["id"]."').text(formatNumber.new(precio_neto));
                var tasa_c=parseInt($('#tasa_p".$libro_p["id"]."').val());
                tasa_c=tasa_c/100;
                var alumnos=parseInt($('#alm_p".$libro_p["id"]."').text());
                var vp= precio_neto *(Math.floor(alumnos*tasa_c))
                if(isNaN(vp)){ vp=0; }
                $('#venta_p_p".$libro_p["id"]."').text(formatNumber.new(vp));
                $('#venta_ps_p".$libro_p["id"]."').val(vp);
                var prob_valor=parseFloat($('#proba_p".$libro_p["id"]."').find('option:selected').data('valor')) || 0;
                var venta_aj=Math.round(vp*(prob_valor/100));
                if(isNaN(venta_aj)){venta_aj=0;}
                $('#venta_aj_p".$libro_p["id"]."').text(formatNumber.new(venta_aj));
                $('#venta_ajs_p".$libro_p["id"]."').val(venta_aj);
                var total_vp_aj_p=0;
                $('.venta1_aj_p').each(function(){
                    total_vp_aj_p+=parseFloat($(this).val()) || 0;
                    total_vp_aj_p=Math.round(total_vp_aj_p);
                });
                $('#total_vp_aj_p').text(formatNumber.new(total_vp_aj_p));
                var probab=$('#proba_p".$libro_p["id"]."').val();
                $('#presupuesto_p".$libro_p["id"]."').val(".$libro_p["id"]."+'/'+tasa_c+'/'+descuento+'/'+pvp+'/'+probab);
                var total_vp_p=0;
                $('.venta1_p').each(function(){
                    total_vp_p+=parseFloat($(this).val()) || 0;
                    total_vp_p=Math.round(total_vp_p * 100) / 100;
                });
                $('#total_vp_p').text(formatNumber.new(total_vp_p));
            })
        </script>";

        echo "</tr>";
    }


    echo '</tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
              <td id="total_vp_p"></td>
              <td id="total_vp_aj_p"></td>
              <td></td><td></td>
            </tr>
          </tfoot>
          </table></div><!-- /.pr-table-wrap -->';

    echo '<input type="hidden" name="id_colegio" id="cole" value="'.$_GET["colegio"].'">
          <input type="hidden" name="codigo" value="'.$_GET["codigo"].'">
          <input type="hidden" name="periodo" value="'.$_GET["periodo"].'">';

    if ($num_hp >= 1) {
        echo '<div class="pr-footer">';
        if ($_SESSION["tipo"]==1) {
            echo '<button class="btn btn-primary px-5" id="guardar_p">Guardar</button>';
        } elseif ($_SESSION["tipo"] !=2 && $_SESSION["tipo"] != 4) {
            if ($gp_periodo["f_cierre"] > date("Y-m-d")) {
                echo '<button class="btn btn-primary px-5 miBoton">Guardar</button>';
            }
        }
        echo '</div>';
    }

    echo "</form>";
  ?>


</div>

<!-- Toast -->
<div class="pr-toast ok" id="pr-toast">
  <i class="bi bi-check-circle-fill"></i>
  <span class="pr-toast-msg"></span>
</div>

<!-- Modal confirmación borrar -->
<div class="pr-overlay" id="pr-overlay">
  <div class="pr-confirm-box">
    <div class="pr-confirm-icon"><i class="bi bi-trash3-fill"></i></div>
    <p class="pr-confirm-title">¿Eliminar libro?</p>
    <p class="pr-confirm-msg" id="pr-confirm-msg"></p>
    <div class="pr-confirm-btns">
      <button class="btn-cancel" id="pr-btn-cancel">Cancelar</button>
      <button class="btn-ok"     id="pr-btn-ok">Sí, eliminar</button>
    </div>
  </div>
</div>

<script>var librosYaEnPresup = <?= json_encode($ids_exist_presup) ?>;</script>
<script src="../vendors/scripts/core.js"></script>
<script src="../src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="../src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="../src/plugins/datatables/js/natural.js"></script>
<script>

  $(document).ready(function(){
    $.fn.dataTable.ext.errMode = 'none';
    $('#tabla-presup').dataTable({
      "paging":    false,
      "searching": false,
      "info":      false,
      "ordering":  true,
      "language": { "emptyTable": "No hay información para mostrar" },
      order: [[2, 'asc']],
      columnDefs: [
        { type: 'natural', targets: 2 },
        { orderable: false, targets: '_all' },
        { orderable: true,  targets: 2 }
      ]
    });
  });
</script>
<script>

	 $('#grado').on('change',function(){
      var valor = $(this).val();
      var materia=$("#materia").val();
      if (valor==17) {
          $(".g_otro").removeClass("d-none");
          $("#grado_otro").attr("required","required");
      }else {
        $(".g_otro").addClass("d-none");
        $("#grado_otro").removeAttr("required");
      }
      var dataString = 'mat_gra='+materia+"/"+valor;
      $.ajax({
        url: "ajax/buscar_l_eureka_p.php",
        type: "POST",
        data: dataString,
        success: function (resp) {
          $("#libro_e").html(resp);
          console.log(resp);
          if(valor =="") {
            $("#libro_e").html("");
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

      $('#materia').on('change',function(){
        var valor = $(this).val();
        var grado = $("#grado").val()
        var dataString = 'mat_gra='+valor+'/'+grado;
        $.ajax({
          url: "ajax/buscar_l_eureka_p.php",
          type: "POST",
          data: dataString,
          dataType: "html",
          success: function (resp) {
            $("#libro_e").html(resp);
            if(valor =="") {
              $("#libro_e").html("");
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

      $('#libro_e').on('change',function(){
        $value=$("#materia").val()+"/"+$("#grado").val()+"/"+$(this).val()+"/"+$("#grado_otro").val();
        $("#libs_ao").val($value);
      });

      var m = 1;
    $("#agregar_ao").click(function(){
      if (m>98) {
        $("#agregar_ao").addClass("d-none");
      }
      $("#agg_ao"+m).removeClass("d-none");
      m++;

      <?php for ($i=1; $i < 100; $i++) { ?>

        $('#grado<?php echo $i; ?>').on('change',function(){
          var valor = $(this).val();
          var materia=$("#materia<?php echo $i; ?>").val();
          if (valor==17) {
              $(".g_otro<?php echo $i; ?>").removeClass("d-none");
              $("#grado_otro<?php echo $i; ?>").attr("required","required");
          }else {
            $(".g_otro<?php echo $i; ?>").addClass("d-none");
            $("#grado_otro<?php echo $i; ?>").removeAttr("required");
          }
          var dataString = 'mat_gra='+materia+"/"+valor;
          $.ajax({
            url: "ajax/buscar_l_eureka_p.php",
            type: "POST",
            data: dataString,
            success: function (resp) {
              $("#libro_e<?php echo $i; ?>").html(resp);
              console.log(resp);
              if(valor =="") {
                $("#libro_e<?php echo $i; ?>").html("");
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

        $('#materia<?php echo $i; ?>').on('change',function(){
          var valor = $(this).val();
          var grado = $("#grado<?php echo $i; ?>").val()
          var dataString = 'mat_gra='+valor+'/'+grado;
          $.ajax({
            url: "ajax/buscar_l_eureka_p.php",
            type: "POST",
            data: dataString,
            dataType: "html",
            success: function (resp) {
              $("#libro_e<?php echo $i; ?>").html(resp);
              if(valor =="") {
                $("#libro_e<?php echo $i; ?>").html("");
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

        $('#libro_e<?php echo $i; ?>').on('change',function(){
          $value=$("#materia<?php echo $i; ?>").val()+"/"+$("#grado<?php echo $i; ?>").val()+"/"+$(this).val()+"/"+$("#grado_otro<?php echo $i; ?>").val();
          $("#libs_ao<?php echo $i; ?>").val($value);
        });

      <?php } ?>

    });

     var formatNumber = {
        separador: ".",
        sepDecimal: ',',
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

    var total_vp_p=0;
    $('.venta1_p').each(function(){
        total_vp_p+=parseFloat($(this).val()) || 0;
        total_vp_p=Math.round(total_vp_p * 100) / 100;
    });
    $('#total_vp_p').text(formatNumber.new(total_vp_p));

    var total_vp_aj_p=0;
    $('.venta1_aj_p').each(function(){
        total_vp_aj_p+=parseFloat($(this).val()) || 0;
        total_vp_aj_p=Math.round(total_vp_aj_p * 100) / 100;
    });
    $('#total_vp_aj_p').text(formatNumber.new(total_vp_aj_p));

    // ── Toast ───────────────────────────────────────────────────
    function prToast(msg, tipo) {
        var $t = $('#pr-toast');
        var icon = tipo === 'error' ? 'bi bi-x-circle-fill' : 'bi bi-check-circle-fill';
        $t.removeClass('ok error').addClass(tipo);
        $t.find('i').attr('class', icon);
        $t.find('.pr-toast-msg').text(msg);
        $t.addClass('show');
        setTimeout(function(){ $t.removeClass('show'); }, 3500);
    }

    // ── Validar duplicados al guardar libros en presupuesto ──────
    $('#modal_presupuesto form.miFormulario').on('submit', function(e) {
        var ids = [];
        var errMsg = '';
        $('input[name="libs_ao[]"]').each(function() {
            var val = $(this).val();
            if (!val) return;
            var libroId = val.split('/')[2];
            if (!libroId || libroId === '0') return;
            if (ids.indexOf(libroId) !== -1) {
                errMsg = 'Hay libros repetidos en el formulario';
                return false;
            }
            if (librosYaEnPresup.indexOf(parseInt(libroId)) !== -1) {
                errMsg = 'Uno de los libros ya existe en el presupuesto';
                return false;
            }
            ids.push(libroId);
        });
        if (errMsg) {
            e.preventDefault();
            prToast(errMsg, 'error');
            return false;
        }
    });

    // ── Modal de confirmación ────────────────────────────────────
    var _prConfirmCb = null;
    function prConfirm(msg, cb) {
        $('#pr-confirm-msg').text(msg);
        $('#pr-overlay').addClass('open');
        _prConfirmCb = cb;
    }
    $('#pr-btn-cancel').on('click', function() {
        $('#pr-overlay').removeClass('open');
        _prConfirmCb = null;
    });
    $('#pr-btn-ok').on('click', function() {
        $('#pr-overlay').removeClass('open');
        if (_prConfirmCb) _prConfirmCb();
        _prConfirmCb = null;
    });

    // ── Borrar fila con AJAX ─────────────────────────────────────
    $(document).on('click', '.pr-btn-row-del', function() {
        var $btn    = $(this);
        var pid     = $btn.data('pid');
        var libro   = parseInt($btn.data('libro'));
        var codigo  = $btn.data('codigo');
        var periodo = $btn.data('periodo');

        prConfirm('¿Deseas eliminar este libro del presupuesto?', function() {
            $.ajax({
                url:  'php/borrar_presupuesto.php',
                type: 'POST',
                data: { 'b_presup[]': pid, codigo: codigo, periodo: periodo },
                complete: function() {
                    var idx = librosYaEnPresup.indexOf(libro);
                    if (idx !== -1) librosYaEnPresup.splice(idx, 1);
                    $btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
                    prToast('Libro eliminado correctamente', 'ok');
                }
            });
        });
    });

    // ── Guardar: mostrar toast tras recarga ──────────────────────
    if (sessionStorage.getItem('pr_saved')) {
        sessionStorage.removeItem('pr_saved');
        prToast('Presupuesto guardado correctamente', 'ok');
    }
    $('#pp').on('submit', function() {
        if ($(this).attr('action') !== 'php/borrar_presupuesto.php') {
            sessionStorage.setItem('pr_saved', '1');
        }
    });

    // ── Color dinámico de probabilidad ──────────────────────────
    function actualizarColorProb($sel) {
        var v = parseInt($sel.val());
        $sel.removeClass('prob-sin prob-alta prob-media prob-perdida prob-adoptada prob-baja');
        if      (v === 1) $sel.addClass('prob-alta');
        else if (v === 2) $sel.addClass('prob-media');
        else if (v === 3) $sel.addClass('prob-perdida');
        else if (v === 4) $sel.addClass('prob-adoptada');
        else if (v === 5) $sel.addClass('prob-baja');
        else              $sel.addClass('prob-sin');
    }
    // Al cargar: aplica color según valor actual
    $('.pr-prob').each(function () { actualizarColorProb($(this)); });
    // Al cambiar
    $(document).on('change', '.pr-prob', function () { actualizarColorProb($(this)); });


</script>
