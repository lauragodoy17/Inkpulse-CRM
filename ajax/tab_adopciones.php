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

  require_once(__DIR__ . "/../includes/adopcion_cerrada.php");
  $adop_cerrada = adopcion_cerrada($bdd, $_GET["colegio"], $gp_periodo["id"]);
  $req_existe_rec = $bdd->prepare("SELECT COUNT(*) FROM recursos WHERE id_colegio = ? AND id_periodo = ?");
  $req_existe_rec->execute([$_GET["colegio"], $gp_periodo["id"]]);
  $existe_recurso = (int)$req_existe_rec->fetchColumn() > 0;

  $show_guardar = !$adop_cerrada && ($num_hp >= 1 && $_SESSION["tipo"] != 4) &&
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

  // Si ya se guardó una cantidad manual para este colegio+periodo, esa reemplaza
  // el conteo automático de trabajadores_colegios.
  try { $bdd->exec("CREATE TABLE IF NOT EXISTS ia_profesores_colegio (id INT AUTO_INCREMENT PRIMARY KEY, id_colegio INT NOT NULL, id_periodo INT NOT NULL, cantidad_profesores INT NOT NULL, UNIQUE KEY uniq_colegio_periodo (id_colegio, id_periodo))"); } catch (Exception $e) {}
  $req_profes_manual = $bdd->prepare("SELECT cantidad_profesores FROM ia_profesores_colegio WHERE id_colegio=? AND id_periodo=?");
  $req_profes_manual->execute([$_GET['colegio'], $_GET['periodo']]);
  $profes_manual = $req_profes_manual->fetch();
  if ($profes_manual) {
    $cantidad_profesores = (int)$profes_manual['cantidad_profesores'];
  }

  $req_interacciones = $bdd->prepare("SELECT interacciones FROM ia_presupuestos WHERE id_modelo_tokens=? AND id_periodo=? ORDER BY id DESC LIMIT 1");
  $req_interacciones->execute([$modelo_activo['id_modelo_tokens'] ?? 0, $_GET['periodo']]);
  $interacciones = $req_interacciones->fetch()['interacciones'] ?? 0;

  $costo_ia_semanal = $costo_ia_cop * $interacciones * $cantidad_profesores;
  $costo_almacenamiento_anual = $modelo_activo['costo_almacenamiento'] ?? 0;
  $costo_almacenamiento_semanal = $costo_almacenamiento_anual / 53;

  $costo_semanal = $costo_ia_semanal + $costo_almacenamiento_semanal;
  $costo_anual = ($costo_ia_semanal * 53) + $costo_almacenamiento_anual;
?>

<link rel="stylesheet" href="src/vd-pickers.css?v=20261110">
<style>
  /* ── Contenedor ───────────────────────────────────────────── */
  .adop-wrap { padding: 24px; }

  /* ── Encabezado ───────────────────────────────────────────── */
  .adop-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .adop-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 2px 0;
  }
  .adop-title i { color: #6c63ff; margin-right: 6px; }
  .adop-subtitle { font-size: 0.82rem; color: #718096; margin: 0; }
  .adop-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

  /* ── Tarjetas de resumen ──────────────────────────────────── */
  .adop-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }

  /* ── Sección de tarjetas de IA (separada visualmente) ────── */
  .adop-ia-section {
    background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
    border: 1.5px solid #ddd6fe;
    border-radius: 12px;
    padding: 16px 18px 4px;
    margin-bottom: 22px;
  }
  .adop-ia-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
  }
  .adop-ia-header i { color: #6d28d9; font-size: 1rem; }
  .adop-ia-header span { font-size: 0.86rem; font-weight: 700; color: #4c1d95; }
  .adop-ia-section .adop-cards {
    margin-bottom: 0;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  }
  .adop-ia-section .adop-card {
    background: #fff;
    min-width: 0;
    padding: 14px 12px;
  }
  .adop-ia-section .adop-card-icon { width: 36px; height: 36px; font-size: 1rem; }
  .adop-ia-section .adop-card-val { font-size: 1rem; }

  /* Venta directa - Eureka: fechas/horario de venta y contacto del colegio */
  .vd-panel { margin-top: 14px; border: 1px solid #c7d2fe; background: #eef2ff; border-radius: 12px; padding: 14px 16px; }
  .vd-tit { font-weight: 700; color: #3730a3; font-size: .9rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .vd-panel { container-type: inline-size; container-name: vdpanel; }
  .vd-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
  @container vdpanel (min-width: 1180px) { .vd-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); } }
  .vd-sub { font-size: .78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
  .vd-panel label { display: block; font-size: .72rem; color: #64748b; font-weight: 600; margin-bottom: 3px; letter-spacing: .01em; }
  .vd-panel label small { font-weight: 500; color: #94a3b8; }
  .vd-ob { color: #dc2626; }

  /* Campos con ícono y selectores de fecha y hora: src/vd-pickers.css */

  /* Tarjeta de cada fecha */
  .vd-jornada {
    display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 10px 12px;
    background: #fff; border: 1px solid #e0e7ff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .05); transition: border-color .15s, box-shadow .15s;
  }
  .vd-jornada:focus-within { border-color: #a5b4fc; box-shadow: 0 4px 14px rgba(99, 102, 241, .12); }
  .vd-jornada.vd-error { border-color: #fca5a5; }
  .vd-jornada.vd-error .vd-pick[data-tipo=hora] .vd-pick-txt { border-color: #fca5a5; }
  .vd-dia {
    flex: 0 0 62px; text-align: center; border-radius: 10px; padding: 6px 4px; line-height: 1.1;
    background: linear-gradient(160deg, #6366f1, #4338ca); color: #fff;
  }
  .vd-dia-sem { display: block; font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; opacity: .85; }
  .vd-dia-num { display: block; font-size: 1.35rem; font-weight: 800; }
  .vd-dia-mes { display: block; font-size: .64rem; text-transform: capitalize; opacity: .85; white-space: nowrap; }
  .vd-dia.vd-dia-vacio { background: #eef2ff; color: #a5b4fc; }
  .vd-campos { flex: 1 1 auto; min-width: 0; display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr) auto minmax(0, 1fr) auto; gap: 8px; align-items: end; }
  .vd-f { min-width: 0; }
  .vd-pick .vd-pick-txt { text-overflow: ellipsis; }
  .vd-flecha { color: #a5b4fc; padding-bottom: 9px; }
  .vd-duracion { align-self: end; margin-bottom: 7px; justify-self: start; font-size: .74rem; font-weight: 700; color: #4338ca; background: #eef2ff; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
  .vd-duracion:empty { display: none; }
  .vd-duracion.vd-dur-error { color: #b91c1c; background: #fef2f2; }
  .vd-quitar {
    flex: 0 0 34px; width: 34px; height: 34px; border: 1px solid #fee2e2; background: #fff; color: #ef4444;
    border-radius: 10px; transition: background .15s, color .15s;
  }
  .vd-quitar:hover:not(:disabled) { background: #fef2f2; color: #b91c1c; }
  .vd-quitar:disabled, .vd-agregar:disabled { opacity: .5; cursor: not-allowed; }
  .vd-agregar {
    width: 100%; border: 1.5px dashed #a5b4fc; background: rgba(255, 255, 255, .6); color: #4338ca;
    border-radius: 12px; padding: 8px 12px; font-size: .82rem; font-weight: 700; transition: background .15s, border-color .15s;
  }
  .vd-agregar:hover:not(:disabled) { background: #fff; border-color: #6366f1; }
  /* ¿Ya tiene fechas de venta? */
  .vd-pregunta {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 14px; margin-bottom: 10px;
    background: #fff; border: 1px solid #e0e7ff; border-radius: 12px; padding: 10px 12px; box-shadow: 0 1px 3px rgba(15, 23, 42, .05);
  }
  .vd-pregunta-txt { font-size: .84rem; font-weight: 600; color: #1e293b; }
  .vd-sino { display: inline-flex; gap: 4px; padding: 3px; background: #eef2ff; border-radius: 10px; border: 1px solid transparent; }
  .vd-sino.vd-falta { border-color: #f87171; background: #fef2f2; }
  .vd-sino label { margin: 0; cursor: pointer; }
  .vd-sino input { position: absolute; opacity: 0; pointer-events: none; }
  .vd-sino span {
    display: inline-flex; align-items: center; gap: 6px; min-width: 70px; justify-content: center;
    padding: 6px 14px; border-radius: 8px; font-size: .82rem; font-weight: 700; color: #4338ca; transition: background .15s, color .15s;
  }
  .vd-sino label:hover span { background: rgba(255, 255, 255, .7); }
  .vd-sino input:checked + span { background: linear-gradient(160deg, #6366f1, #4338ca); color: #fff; box-shadow: 0 3px 8px rgba(79, 70, 229, .3); }
  .vd-sino input[value="0"]:checked + span { background: #475569; box-shadow: 0 3px 8px rgba(71, 85, 105, .3); }
  .vd-sino input:focus-visible + span { outline: 2px solid #818cf8; outline-offset: 1px; }
  .vd-sino input:disabled + span { opacity: .5; cursor: not-allowed; }
  .vd-sin-fechas { font-size: .8rem; color: #475569; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 10px 12px; }
  .vd-sin-fechas i { color: #6366f1; margin-right: 4px; }
  .vd-contacto {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px;
    background: #fff; border: 1px solid #e0e7ff; border-radius: 12px; padding: 12px; box-shadow: 0 1px 3px rgba(15, 23, 42, .05);
  }
  /* Validación del celular */
  .vd-inp .vd-cel-estado { left: auto; right: 11px; font-size: .9rem; }
  .vd-inp .vd-cel-estado:not([class*="bi-"]) { display: none; }
  .vd-inp.vd-cel-ok .vd-cel-estado { color: #16a34a; }
  .vd-inp.vd-cel-mal .vd-cel-estado { color: #dc2626; }
  .vd-inp.vd-cel-ok input { border-color: #86efac; padding-right: 32px; }
  .vd-inp.vd-cel-mal input { border-color: #f87171; background: #fef2f2; padding-right: 32px; }
  .vd-inp.vd-cel-mal input:focus { box-shadow: 0 0 0 3px rgba(248, 113, 113, .2); }
  .vd-cel-msg { display: block; min-height: 1em; margin-top: 3px; font-size: .72rem; color: #94a3b8; }
  .vd-cel-msg.vd-cel-msg-error { color: #dc2626; font-weight: 600; }
  /* Responsive según el ancho real de cada tarjeta (no de la pantalla): el bloque puede quedar angosto en pantallas grandes */
  .vd-jornada { container-type: inline-size; container-name: vdj; }
  @container vdj (max-width: 720px) {
    .vd-campos { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .vd-f-fecha { grid-column: 1 / -1; }
    .vd-flecha { display: none; }
    .vd-duracion { grid-column: 1 / -1; margin-bottom: 0; }
  }
  @container vdj (max-width: 400px) {
    .vd-dia { flex-basis: 52px; padding: 5px 3px; }
    .vd-dia-num { font-size: 1.15rem; }
    .vd-campos { grid-template-columns: minmax(0, 1fr); }
  }
  /* Navegadores sin consultas de contenedor */
  @supports not (container-type: inline-size) {
    @media (max-width: 1100px) {
      .vd-campos { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
      .vd-f-fecha, .vd-duracion { grid-column: 1 / -1; }
      .vd-flecha { display: none; }
    }
  }
  .sc-panel { margin-top: 14px; border: 1px solid #fde68a; background: #fffbeb; border-radius: 12px; padding: 14px 16px; }
  .sc-tit { font-weight: 700; color: #92400e; font-size: .9rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .sc-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
  .sc-cards > div { background: #fff; border-radius: 10px; padding: 10px 12px; box-shadow: 0 1px 4px rgba(15,23,42,.06); }
  .sc-cards span { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 600; }
  .sc-cards strong { display: block; font-size: 1.1rem; color: #0f172a; }
  .sc-cards small { color: #94a3b8; font-size: .72rem; }
  .sc-cards .sc-total strong { color: #b45309; }
  @media (max-width: 768px) { .sc-cards { grid-template-columns: 1fr; } }
  .sc-tabla { width: 100%; font-size: .82rem; background: #fff; border-radius: 8px; overflow: hidden; }
  .sc-tabla th { background: #fef3c7; color: #78350f; font-weight: 600; padding: 7px 10px; }
  .sc-tabla td { padding: 6px 10px; border-top: 1px solid #f1f5f9; }
  .sc-tabla .c { text-align: center; } .sc-tabla .r { text-align: right; }
  .sc-tag { font-size: .68rem; background: #e0e7ff; color: #3730a3; border-radius: 6px; padding: 1px 6px; margin-left: 4px; }
  .sc-vacio { margin: 0; font-size: .8rem; color: #92400e; }
  .adop-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 6px rgba(15,23,42,.08);
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
  }
  .adop-card > div { min-width: 0; }
  .adop-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
  }
  .adop-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
  .adop-card-icon.green  { background: #dcfce7; color: #15803d; }
  .adop-card-icon.orange { background: #ffedd5; color: #c2410c; }
  .adop-card-icon.purple { background: #ede9fe; color: #6d28d9; }
  .adop-card-icon.teal   { background: #ccfbf1; color: #0f766e; }
  .adop-card-icon.rose   { background: #ffe4e6; color: #be123c; }
  .adop-card-icon.indigo { background: #e0e7ff; color: #4338ca; }
  .adop-card-icon.amber  { background: #fef3c7; color: #b45309; }
  .adop-card-label { font-size: 0.74rem; color: #64748b; margin: 0 0 2px 0; overflow-wrap: break-word; }
  .adop-card-val   { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; overflow-wrap: break-word; }
  .adop-input-wrap { position: relative; display: inline-flex; align-items: center; }
  .adop-input-wrap .bi-pencil-fill {
    position: absolute; right: 8px; font-size: .68rem; color: #818cf8; pointer-events: none;
  }
  .adop-card-input {
    border: 1px solid #c7d2fe; border-radius: 6px; width: 92px; padding: 2px 24px 2px 6px;
    background: #eef2ff; font-family: inherit; cursor: text;
  }
  .adop-card-input:hover   { border-color: #a5b4fc; background: #e0e7ff; }
  .adop-card-input:focus   { outline: none; border-color: #4f46e5; background: #fff; box-shadow: 0 0 0 2px rgba(79,70,229,.15); }
  .adop-card-pct   { font-size: 0.75rem; color: #64748b; }

  /* ── Contenedor con scroll propio ────────────────────────── */
  .adop-table-wrap {
    border-radius: 10px;
    overflow: auto;
    max-height: 60vh;
    box-shadow: 0 2px 12px rgba(15,23,42,.10);
    scrollbar-width: thin;
    scrollbar-color: #4361ee #e2e8f0;
  }
  .adop-table-wrap::-webkit-scrollbar        { height: 10px; width: 10px; }
  .adop-table-wrap::-webkit-scrollbar-track  { background: #e2e8f0; border-radius: 0 0 10px 10px; }
  .adop-table-wrap::-webkit-scrollbar-thumb  { background: #4361ee; border-radius: 10px; border: 2px solid #e2e8f0; }
  .adop-table-wrap::-webkit-scrollbar-thumb:hover { background: #2a3fc7; }

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

  /* Sobrecosto por libro: oculto si la adopción no tiene canal con sobrecosto */
  #dataTables-adop.sin-sobrecosto .col-sc { display: none; }

  /* Precio venta padre más ancho */
  input.precio-padre-inp, input.sc-libro-inp {
    width: 90px !important;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    padding: 3px 5px;
    font-size: 0.79rem;
    background: #f8fafc;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  input.precio-padre-inp:focus, input.sc-libro-inp:focus {
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
  .adop-footer-form {
    margin-top: 22px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 6px rgba(15,23,42,.07);
    padding: 22px 24px;
  }
  .adop-footer-form .form-label-sm {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .adop-footer-form .form-label-sm i { color: #6366f1; font-size: 0.88rem; }

  /* Campo de archivo */
  .adop-footer-form .adop-file-label {
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
  .adop-footer-form .adop-file-label:hover { border-color: #6366f1; background: #eef2ff; color: #4f46e5; }
  .adop-footer-form .adop-file-label.has-file { border-color: #16a34a; background: #f0fdf4; color: #15803d; }
  .adop-footer-form input[type="file"] { display: none; }
  .adop-file-name { font-size: 0.79rem; color: #6366f1; margin-top: 5px; font-weight: 600; word-break: break-all; }

  /* Controles del footer con borde más visible */
  .adop-footer-form select.form-control,
  .adop-footer-form textarea.form-control {
    border: 1.5px solid #cbd5e0;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #1e293b;
    background: #f8fafc;
    transition: border-color .15s, box-shadow .15s;
    resize: vertical;       /* textarea redimensionable verticalmente */
    min-height: 80px;       /* altura mínima para ver observaciones */
  }
  .adop-footer-form select.form-control {
    min-height: unset;
    padding: 7px 10px;
    cursor: pointer;
  }
  .adop-footer-form select.form-control:focus,
  .adop-footer-form textarea.form-control:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(99,102,241,.15);
    outline: none;
  }

  /* % Cumplimiento como tarjeta destacada */
  .adop-cumplimiento-card {
    background: #f0f4ff;
    border: 1.5px solid #c7d2fe;
    border-radius: 10px;
    padding: 12px 16px;
    text-align: center;
    margin-top: 2px;
  }
  .adop-cumplimiento-val   { font-size: 1.6rem; font-weight: 800; color: #4338ca; display: block; }
  .adop-cumplimiento-label { font-size: 0.76rem; color: #6366f1; font-weight: 600; letter-spacing: .04em; }

  .adop-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
  }

  /* ── Filtro de libros (sticky) ───────────────────────────── */
  .adop-filter-bar {
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
  .adop-filter-left  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .adop-filter-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .adop-filter-bar span {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
    margin-right: 4px;
  }
  .adop-filter-btn {
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
  .adop-filter-btn:hover { border-color: #6366f1; color: #6366f1; }
  .adop-filter-btn.active { background: #6366f1; border-color: #6366f1; color: #fff; }
  .adop-filter-btn .adop-filter-count {
    background: rgba(255,255,255,.25);
    border-radius: 10px;
    padding: 0 6px;
    font-size: 11px;
  }
  .adop-filter-btn:not(.active) .adop-filter-count { background: #f1f5f9; color: #475569; }
</style>

<div class="adop-wrap">

  <!-- Encabezado -->
  <div class="adop-header">
    <div>
      <h5 class="adop-title"><i class="bi bi-bookmark-check-fill"></i> Adopciones de libros</h5>
      <p class="adop-subtitle">Gestiona las adopciones y el seguimiento de venta real</p>
    </div>
    <div class="adop-actions">
      <a class="btn btn-success btn-sm" href="php/adopcion_excel.php?cole=<?= htmlspecialchars($_GET['colegio']) ?>&periodo=<?= htmlspecialchars($_GET['periodo']) ?>">
        <i class="bi bi-file-earmark-excel"></i> Exportar Excel
      </a>
      <?php if ($_SESSION['tipo'] == 1): ?>
        <?php if ($existe_recurso): ?>
        <form action="php/cerrar_adopcion.php" method="POST" style="display:inline;"
          onsubmit="return confirm(<?= htmlspecialchars(json_encode($adop_cerrada ? '¿Reabrir la adopción? Se podrán volver a añadir libros y hacer cambios.' : '¿Cerrar la adopción? No se podrán añadir libros ni hacer cambios de ningún tipo.'), ENT_QUOTES) ?>);">
          <input type="hidden" name="id_colegio" value="<?= (int)$_GET['colegio'] ?>">
          <input type="hidden" name="periodo"    value="<?= (int)$gp_periodo['id'] ?>">
          <input type="hidden" name="codigo"     value="<?= htmlspecialchars($_GET['codigo']) ?>">
          <input type="hidden" name="accion"     value="<?= $adop_cerrada ? 'abrir' : 'cerrar' ?>">
          <?php if ($adop_cerrada): ?>
          <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-unlock"></i> Reabrir adopción</button>
          <?php else: ?>
          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-lock"></i> Cerrar adopción</button>
          <?php endif; ?>
        </form>
        <?php else: ?>
        <button type="button" class="btn btn-danger btn-sm" disabled title="Guarda la adopción (canal de venta, cliente...) antes de cerrarla">
          <i class="bi bi-lock"></i> Cerrar adopción
        </button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($adop_cerrada): ?>
  <div class="alert alert-secondary d-flex align-items-center" style="gap:8px;font-size:.85rem;">
    <i class="bi bi-lock-fill"></i>
    <span>Esta adopción está <strong>cerrada</strong>: no se pueden añadir libros ni hacer cambios.</span>
  </div>
  <?php endif; ?>

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
  <div class="adop-cards">
    <div class="adop-card">
      <div class="adop-card-icon blue"><i class="bi bi-book"></i></div>
      <div>
        <p class="adop-card-label">Total de títulos adoptados</p>
        <p class="adop-card-val"><?= $total_adoptados ?></p>
      </div>
    </div>
    <div class="adop-card">
      <div class="adop-card-icon green"><i class="bi bi-check2-circle"></i></div>
      <div>
        <p class="adop-card-label">Venta potencial</p>
        <p class="adop-card-val" id="adop-card-vp">—</p>
      </div>
    </div>
    <div class="adop-card">
      <div class="adop-card-icon orange"><i class="bi bi-graph-up"></i></div>
      <div>
        <p class="adop-card-label">Venta real</p>
        <p class="adop-card-val" id="adop-card-vr">—</p>
      </div>
    </div>
    <div class="adop-card" id="adop-card-cum-wrap">
      <div class="adop-card-icon purple" id="adop-card-cum-icon"><i class="bi bi-percent"></i></div>
      <div>
        <p class="adop-card-label">% Cumplimiento</p>
        <p class="adop-card-val" id="adop-card-cum">—</p>
      </div>
    </div>
  </div>

  <?php if ($gp_periodo["periodo"] >= 2027 && $_SESSION['tipo'] == 1): ?>
  <!-- Tarjetas de IA (separadas visualmente) -->
  <div class="adop-ia-section">
    <div class="adop-ia-header">
      <i class="bi bi-robot"></i>
      <span>Inteligencia Artificial y tokens</span>
    </div>
    <div class="adop-cards">
      <div class="adop-card">
        <div class="adop-card-icon teal"><i class="bi bi-cpu"></i></div>
        <div>
          <p class="adop-card-label">Costo IA (por interacción)</p>
          <p class="adop-card-val">$<?= number_format($costo_ia_cop, 2, ",", ".") ?> COP</p>
        </div>
      </div>
      <div class="adop-card">
        <div class="adop-card-icon blue"><i class="bi bi-person-video3"></i></div>
        <div>
          <p class="adop-card-label">Cantidad de profesores</p>
          <div class="adop-input-wrap">
            <input type="number" min="0" step="1" class="adop-card-val adop-card-input" id="cantidad_profesores_ia_adop"
              value="<?= $cantidad_profesores ?>"
              title="Editable: haz clic para cambiar la cantidad de profesores"
              data-colegio="<?= htmlspecialchars($_GET['colegio']) ?>"
              data-periodo="<?= htmlspecialchars($_GET['periodo']) ?>">
            <i class="bi bi-pencil-fill"></i>
          </div>
        </div>
      </div>
      <div class="adop-card">
        <div class="adop-card-icon amber"><i class="bi bi-chat-dots"></i></div>
        <div>
          <p class="adop-card-label">Cantidad de interacciones</p>
          <p class="adop-card-val"><?= $interacciones ?></p>
        </div>
      </div>
      <div class="adop-card">
        <div class="adop-card-icon rose"><i class="bi bi-calendar-week"></i></div>
        <div>
          <p class="adop-card-label">Costo semanal</p>
          <p class="adop-card-val" id="costo_semanal_ia_adop">$<?= number_format($costo_semanal, 2, ",", ".") ?> COP</p>
        </div>
      </div>
      <div class="adop-card">
        <div class="adop-card-icon indigo"><i class="bi bi-calendar-range"></i></div>
        <div>
          <p class="adop-card-label">Costo anual</p>
          <p class="adop-card-val" id="costo_anual_ia_adop">$<?= number_format($costo_anual, 2, ",", ".") ?> COP</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      var costoIaCop  = <?= json_encode((float)$costo_ia_cop) ?>;
      var interacciones = <?= json_encode((float)$interacciones) ?>;
      var costoAlmSemanal = <?= json_encode((float)$costo_almacenamiento_semanal) ?>;
      var costoAlmAnual   = <?= json_encode((float)$costo_almacenamiento_anual) ?>;

      function formatCop(valor) {
        return '$' + valor.toLocaleString('es-CO', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' COP';
      }

      function actualizarCostosAd(cantidad) {
        var costoIaSemanal = costoIaCop * interacciones * cantidad;
        $('#costo_semanal_ia_adop').text(formatCop(costoIaSemanal + costoAlmSemanal));
        $('#costo_anual_ia_adop').text(formatCop((costoIaSemanal * 53) + costoAlmAnual));
      }

      $('#cantidad_profesores_ia_adop').on('change', function(){
        var input = $(this);
        var cantidad = parseInt(input.val(), 10);
        if (isNaN(cantidad) || cantidad < 0) cantidad = 0;
        input.val(cantidad);
        actualizarCostosAd(cantidad);

        $.ajax({
          url: "ajax/guardar_cantidad_profesores_ia.php",
          type: "POST",
          data: { colegio: input.data('colegio'), periodo: input.data('periodo'), cantidad: cantidad },
          dataType: "json",
          error: function(){ alert("No se pudo guardar la cantidad de profesores."); }
        });

        // Refleja el cambio en la pestaña de Presupuesto si ya está cargada en la página.
        $(document).trigger('ia:cantidadProfesoresActualizada', [cantidad]);
      });

      // Si el valor se editó desde la pestaña de Presupuesto, se refleja aquí sin volver a guardar.
      $(document).on('ia:cantidadProfesoresActualizada', function(e, cantidad){
        var input = $('#cantidad_profesores_ia_adop');
        if (!input.length || input.val() == cantidad) return;
        input.val(cantidad);
        actualizarCostosAd(cantidad);
      });
    })();
  </script>
  <?php endif; ?>


  <!-- Filtro de libros + acciones (sticky) -->
  <div class="adop-filter-bar">
    <div class="adop-filter-left">
      <span><i class="bi bi-funnel"></i> Ver:</span>
      <button class="adop-filter-btn active" data-filter="todos">
        Todos los libros
      </button>
      <button class="adop-filter-btn" data-filter="adoptados">
        <i class="bi bi-bookmark-check-fill"></i> Solo adoptados
      </button>
    </div>
    <div class="adop-filter-right">
      <?php if (!$adop_cerrada): ?>
      <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal_adopciones">
        <i class="bi bi-plus-circle"></i> Añadir libros
      </a>
      <?php endif; ?>
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
              <?php if ($_GET["f_cierre"] > date("Y-m-d") && !$adop_cerrada): ?>
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

        // Clave de duplicado: id del libro; para grado "Otro" (17) se agrega el grado específico,
        // así el mismo libro puede estar en grados distintos pero no dos veces en el mismo.
        $sql_exist_d = "SELECT DISTINCT id_libro_eureka, id_grado, id_grado_otro FROM areas_objetivas WHERE id_colegio='".$_GET["colegio"]."' AND id_periodo='".$gp_periodo["id"]."'";
        $req_exist_d = $bdd->prepare($sql_exist_d);
        $req_exist_d->execute();
        $ids_exist_adop = [];
        foreach ($req_exist_d->fetchAll(PDO::FETCH_ASSOC) as $ex) {
            $ids_exist_adop[] = $ex["id_grado"] == 17 ? $ex["id_libro_eureka"]."|".$ex["id_grado_otro"] : (string)$ex["id_libro_eureka"];
        }
        $ids_exist_adop = array_values(array_unique($ids_exist_adop));

        // Sobrecosto por libro (2026-11-10): valor en pesos que se digita en cada título (presupuestos.sobrecosto), sin
        // cálculo. Desde 2026-11-11 la columna se ve y se guarda con cualquier canal; solo con "Venta directa - Eureka"
        // es obligatoria (0 es válido).
        require_once(__DIR__ . "/../includes/sobrecosto_canal.php");
        sobrecosto_asegurar_columna($bdd);
        $st_sc_tab = $bdd->prepare("SELECT id_canal, id_canal2 FROM recursos WHERE id_periodo = ? AND id_colegio = ? LIMIT 1");
        $st_sc_tab->execute([(int)$gp_periodo["id"], (int)$_GET["colegio"]]);
        $rec_sc_tab = $st_sc_tab->fetch(PDO::FETCH_ASSOC);
        $tab_con_sc = $rec_sc_tab && array_intersect([(int)$rec_sc_tab["id_canal"], (int)$rec_sc_tab["id_canal2"]], canales_con_sobrecosto($bdd));
        // $campo: 'sc_libro' (clave = id del libro) o 'sc_area' (clave = cod_area, grado "Otro").
        // 2026-11-11: la columna se muestra y se guarda en todas las adopciones (no solo venta directa) y acepta 0.
        $celda_sc_libro = function ($campo, $clave, $valor) {
            $v = ($valor !== null && $valor !== '' && (float)$valor >= 0) ? rtrim(rtrim(number_format((float)$valor, 2, '.', ''), '0'), '.') : '';
            return "<td class='col-sc'><span class='precio-padre-wrap'><span class='pp-signo'>$</span><input type='text' class='sc-libro-inp' name='".$campo."[".htmlspecialchars((string)$clave, ENT_QUOTES)."]' value='".$v."'></span></td>";
        };

    // Descuentos con decimales (2026-11-11): se acepta coma o punto ("12,5" = 12.5 %) y hasta 2 decimales. Se normaliza
    // en el evento input, que corre antes del keyup que recalcula el precio neto (parseFloat solo entiende el punto).
    echo <<<'JS'
<script>
$(document).off('input.descDec').on('input.descDec', 'input.desc-dec', function () {
  var v = this.value.replace(/,/g, '.').replace(/[^0-9.]/g, '');
  var i = v.indexOf('.');
  if (i !== -1) v = v.substr(0, i + 1) + v.substr(i + 1).replace(/\./g, '').substr(0, 2);
  if (v !== this.value) this.value = v;
});
</script>
JS;

    echo "<form action='php/guardar_definicion.php' class='miFormulario' method='POST' id='form_definicion' name='f2' enctype='multipart/form-data'>";
                              
            echo "<div class='adop-table-wrap mt-2'>
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
                    <th class='col-sc'>Sobrecosto por libro</th>
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

                                $sql_presup = "SELECT id, precio, tasa_compra, descuento, definido, tasa_compra_d, descuento_d, precio_venta_final, sobrecosto FROM presupuestos WHERE id_libro='".$libro2["id"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";
                            
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
                                        echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' id='descuento_d".$libro2["id"]."' value='".$presup_m."'> %</td>";

                                    }
                                    elseif( $presup["descuento_d"] !=""){

                                        $presup_m = $presup["descuento_d"] * 100;
                                        echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' id='descuento_d".$libro2["id"]."' value='".$presup_m."'> %</td>";

                                    }
                                    else {

                                        echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' value='20' id='descuento_d".$libro_p["id"]."' required> %</td>";
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
                                    echo $celda_sc_libro('sc_libro', $libro2["id"], $presup["sobrecosto"] ?? null);
                                        
                                    if ($presup["tasa_compra"] !=0.00 || $presup["tasa_compra_d"] !=0.00) {
                                        if ($presup["definido"] ==1) {
                                            echo "<td><input type='checkbox' name='definir[]' class='definir' data-id-presupuesto='".$presup["id"]."' checked value='".$libro2["id"]."/'".$presup["id"]."></td>";
                                        }
                                      else {

                                          echo "<td><input type='checkbox' name='definir[]' class='definir' data-id-presupuesto='".$presup["id"]."' value='".$libro2["id"]."/1".$presup["id"]."'></td>";

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

                                  $sql_presup = "SELECT id,precio, tasa_compra, descuento, tasa_compra_d, descuento_d, precio_venta_final, definido, uni_vr, sobrecosto FROM presupuestos WHERE id_libro='".$libro_p["id"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";

                                  }else{

                                    $sql_presup = "SELECT id,precio, tasa_compra, descuento, tasa_compra_d, descuento_d, precio_venta_final, definido, uni_vr, sobrecosto FROM presupuestos WHERE cod_area='".$libro_p["cod_area"]."' AND id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";

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
                                          echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' id='descuento_d".$libro_p["id"]."' value='".$presup_m."'> %</td>";

                                        }
                                        elseif( $presup["descuento_d"] !=""){

                                          $presup_m = $presup["descuento_d"] * 100;
                                          echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' id='descuento_d".$libro_p["id"]."' value='".$presup_m."'> %</td>";

                                        }
                                        else {

                                          echo "<td><input type='text' size='4' inputmode='decimal' class='desc-dec' name='descuento[]' value='20' id='descuento_d".$libro_p["id"]."' required> %</td>";
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
                                          echo $celda_sc_libro($libro_p["id_grado"] == 17 ? 'sc_area' : 'sc_libro', $libro_p["id"], $presup["sobrecosto"] ?? null);

                                          if ($presup["tasa_compra"] !=0.00 || $presup["tasa_compra_d"] !=0.00) {
                                            if ($presup["definido"] ==1) {
                                              echo "<td><input type='checkbox' name='definir[]' class='definir' data-id-presupuesto='".$presup["id"]."' checked value='".$libro_p["id"]."/".$presup["id"]."'></td>";
                                            }
                                            else {

                                              echo "<td><input type='checkbox' name='definir[]' class='definir' data-id-presupuesto='".$presup["id"]."' value='".$libro_p["id"]."/".$presup["id"]."'></td>";

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
                                <td></td><td class='col-sc'></td><td></td><td></td>
                                <td id='total_vr'></td>
                              </tr>
                              </tfoot>
                              </table></div><!-- /.adop-table-wrap -->
                              <input type='hidden' name='id_colegio' id='cole' value='".$_GET["colegio"]."'>
                              <input type='hidden' name='codigo'     value='".$_GET["codigo"]."'>
                              <input type='hidden' name='periodo'    value='".$gp_periodo["id"]."'>";
                                    
                                    //adopcion solo admin

                                    /*$sql_periodo1="SELECT id FROM periodos ORDER BY id DESC  limit 1;";

                                  $req_periodo1 = $bdd->prepare($sql_periodo1);
                                  $req_periodo1->execute();
                                  $u_periodo = $req_periodo1->fetch();*/                           

                          require_once(__DIR__ . "/../includes/sobrecosto_canal.php");
                          sobrecosto_asegurar_columna($bdd);
                          $canales_sobrecosto = canales_con_sobrecosto($bdd);
                          $sql_rec = "SELECT * FROM recursos WHERE id_periodo='".$gp_periodo["id"]."' AND id_colegio='".$_GET["colegio"]."'";
                          $req_rec = $bdd->prepare($sql_rec);
                          $req_rec->execute();
                          $recursos = $req_rec->fetch();
                          $count    = $req_rec->rowCount();

                          // ── Sección inferior estilizada ──────────────────────────
                          echo '<div class="adop-footer-form">';
                          echo '<div class="row g-3 align-items-start">';

                          // Canal de venta: hasta dos por adopción (recursos.id_canal e id_canal2).
                          $canales_adop = $count > 0 ? [(int)$recursos["id_canal"], (int)$recursos["id_canal2"]] : [];
                          echo '<div class="col-sm-4">
                                  <span class="form-label-sm">
                                    <i class="bi bi-shop"></i> Canal de venta <span style="color:#dc2626">*</span>
                                  </span>
                                  <select name="canal[]" id="canal" class="form-control materia" multiple required>';
                          $sql = "SELECT id, canal_venta FROM canales_venta";
                          $req = $bdd->prepare($sql); $req->execute();
                          $canales = $req->fetchAll();
                          foreach ($canales as $canal) {
                              $id  = $canal['id'];
                              $nom = $canal['canal_venta'];
                              $sel = in_array((int)$id, $canales_adop, true) ? ' SELECTED' : '';
                              echo '<option value="'.$id.'"'.$sel.'>'.$nom.'</option>';
                          }
                          echo '</select></div>';

                          // Sobrecosto (2026-11-10): ya no hay un % para toda la adopción; con "Venta directa - Eureka"
                          // cada título lleva su propio valor en la columna "Sobrecosto por libro" de la tabla.
                          $con_sobrecosto = (bool)array_intersect($canales_adop, $canales_sobrecosto);
                          echo '<script>
                            var canalesSobrecosto = '.json_encode($canales_sobrecosto).';
                            function canalLlevaSobrecosto() {
                              return ($("#canal").val() || []).some(function (id) { return canalesSobrecosto.indexOf(parseInt(id, 10)) !== -1; });
                            }
                            $(function () {
                              $("#canal").select2({
                                placeholder: "Seleccionar canal (máximo 2)...",
                                width: "100%",
                                dropdownParent: $("#adopciones"),
                                maximumSelectionLength: 2,
                                language: { maximumSelected: function () { return "Solo puedes seleccionar 2 canales"; } }
                              });
                            });
                            $("#canal").on("change", function () {
                              var con = canalLlevaSobrecosto();
                              // Fechas y horario de venta + contacto del colegio (2026-11-10).
                              $("#vd_panel").toggle(con).find(":input").prop("disabled", !con);
                              if (window.vdAplicarTieneFechas) window.vdAplicarTieneFechas();
                            });
                            // Ventana con los estilos de src/ink-alerts.js: lista los títulos sin precio y al cerrar lleva al primero.
                            // faltanPp / faltanSc: títulos sin precio venta padre / sin sobrecosto por libro.
                            function alertaPrecioPadre(faltanPp, faltanSc) {
                              faltanSc = faltanSc || [];
                              var esc = function (t) { return $("<div>").text(t).html(); };
                              var estiloUl = "max-height:180px;overflow:auto;margin:0 0 14px;padding:10px 14px 10px 30px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:.84rem;color:#78350f;text-align:left;";
                              var bloque = function (tit, titulos) {
                                if (!titulos.length) return "";
                                return "<p style=\"margin:0 0 6px;font-weight:700;font-size:.84rem;text-align:left;\">" + tit + "</p>"
                                  + "<ul style=\"" + estiloUl + "\">" + titulos.map(function (t) { return "<li>" + esc(t) + "</li>"; }).join("") + "</ul>";
                              };
                              var titulo = faltanPp.length && faltanSc.length ? "Faltan precios y sobrecostos"
                                : (faltanSc.length ? "Falta el sobrecosto por libro" : "Falta el precio venta padre");
                              var $ov = $("<div class=\"ink-overlay\"><div class=\"ink-modal\" style=\"width:440px;\">"
                                + "<div class=\"ink-modal-icon warning\"><i class=\"bi bi-tag-fill\"></i></div>"
                                + "<p class=\"ink-modal-title\">" + titulo + "</p>"
                                + "<p class=\"ink-modal-text\" style=\"margin-bottom:12px;\">Son obligatorios en los títulos adoptados (el sobrecosto puede ser 0). Complétalos en:</p>"
                                + bloque("Precio venta padre", faltanPp)
                                + bloque("Sobrecosto por libro", faltanSc)
                                + "<div class=\"ink-modal-btns\" style=\"margin-top:6px;\"><button type=\"button\" class=\"ink-btn-warning\">Entendido</button></div>"
                                + "</div></div>");
                              var cerrar = function () {
                                $ov.remove();
                                var $primero = $("input.precio-padre-inp, input.sc-libro-inp").filter(function () { return this.style.borderColor; }).first();
                                if ($primero.length) { $primero[0].scrollIntoView({ block: "center" }); $primero.focus(); }
                              };
                              $ov.on("click", function (e) { if (e.target === this) cerrar(); });
                              $ov.find("button").on("click", cerrar);
                              $("body").append($ov);
                            }
                            // Con "Venta directa - Eureka" el precio venta padre y el sobrecosto por libro son obligatorios
                            // en los títulos adoptados.
                            $("#form_definicion").on("submit", function (e) {
                              // Sobrecosto por libro: obligatorio con cualquier canal (0 es válido). Precio venta padre: solo con venta directa.
                              var conVd = canalLlevaSobrecosto();
                              var faltanPp = [], faltanSc = [];
                              var vacio = function ($i) { return !(parseFloat(String($i.val()).replace(/[^0-9.,]/g, "").replace(/\.(?=\d{3}(\D|$))/g, "").replace(",", ".")) > 0); };
                              // El sobrecosto por libro admite 0: solo falta si está vacío.
                              var vacioSc = function ($i) { return String($i.val()).replace(/[^0-9]/g, "") === ""; };
                              $(this).find("input.precio-padre-inp, input.sc-libro-inp").css("border-color", "");
                              $(this).find("input.definir:checked").each(function () {
                                var $tr = $(this).closest("tr");
                                var nombre = $.trim($tr.find("td").first().text());
                                var $pp = $tr.find("input.precio-padre-inp").first();
                                if (conVd && $pp.length && vacio($pp)) { faltanPp.push(nombre); $pp.css("border-color", "#dc2626"); }
                                var $sc = $tr.find("input.sc-libro-inp").first();
                                if ($sc.length && !$sc.prop("disabled") && vacioSc($sc)) { faltanSc.push(nombre); $sc.css("border-color", "#dc2626"); }
                              });
                              if (faltanPp.length || faltanSc.length) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                alertaPrecioPadre(faltanPp, faltanSc);
                                return false;
                              }
                            });
                          </script>';

                          // Cliente
                          echo '<div class="col-sm-4">
                                  <span class="form-label-sm">
                                    <i class="bi bi-person-vcard"></i> Cliente <span style="color:#dc2626">*</span>
                                  </span>
                                  <select name="cliente" id="cliente_adop" class="form-control" required>
                                    <option value="">Seleccionar cliente...</option>';
                          // clientes tiene ~10.000 filas: renderizar todas como <option> congelaba
                          // el desplegable. Solo se imprime aquí el cliente ya guardado; el resto
                          // se busca por AJAX al escribir (ver select2 abajo).
                          if ($count > 0 && !empty($recursos["cliente"])) {
                              $req_cl_sel = $bdd->prepare("SELECT id, cliente FROM clientes WHERE id=:id");
                              $req_cl_sel->execute([':id' => $recursos["cliente"]]);
                              $cl_sel_adop = $req_cl_sel->fetch();
                              if ($cl_sel_adop) {
                                  echo '<option value="'.$cl_sel_adop['id'].'" SELECTED>'.htmlspecialchars($cl_sel_adop['cliente']).'</option>';
                              }
                          }
                          echo '</select></div>';

                          echo '<script>
                            $(function () {
                              $("#cliente_adop").select2({ 
                                placeholder: "Seleccionar cliente...", 
                                width: "100%", 
                                dropdownParent: $("#adopciones"), 
                                minimumInputLength: 2, 
                                ajax: { 
                                    url: "ajax/buscar_clientes_adop.php", 
                                    dataType: "json", 
                                    delay: 300, 
                                    data: function (params) { 
                                        return { 
                                            q: params.term, // Término de búsqueda actual
                                            periodo: '.$_GET["periodo"].', // <--- Tu nuevo parámetro estático
                                            
                                        }; 
                                    }, 
                                    processResults: function (data) { 
                                        return { results: data }; 
                                    } 
                                }, 
                                language: { 
                                    inputTooShort: function () { return "Escribe al menos 2 letras para buscar..."; }, 
                                    noResults: function () { return "Sin resultados"; }, 
                                    searching: function () { return "Buscando..."; } 
                                } 
                            });
                            });
                          </script>';

                           

                          // Documento(s) de adopción (máx. 3) solo para tipos 1, 3, 10. El campo
                          // "Tipo de adopción" en sí (y el armado de paquetes) es más restrictivo:
                          // Administrador, asesores tipo 3 y Héctor Morales — ver puede_usar_tipo_adopcion().
                          require_once(__DIR__ . "/../includes/paquetes_colegio.php");
                          if (in_array($_SESSION['tipo'], [1, 2, 3, 10])) {

                            if (puede_usar_tipo_adopcion()) {
                              echo '<div class="col-sm-4">
                                    <span class="form-label-sm">
                                      <i class="bi bi-person-vcard"></i> Tipo de adopción <span style="color:#dc2626">*</span>
                                    </span>
                                    <select name="tipo_adop" id="tipo_adop" class="form-control" required>
                                      <option value="">Seleccione...</option>';
                                        $sql = "SELECT id, tipo FROM tipos_adopciones";
                                        $req = $bdd->prepare($sql); $req->execute();
                                        $tipos_adop = $req->fetchAll();

                                        foreach ($tipos_adop as $tipo_adop) {

                                            $sel = ($count > 0 && $recursos["tipo_adop"] == $tipo_adop["id"]) ? ' SELECTED' : '';
                                            echo '<option value="'.$tipo_adop["id"].'"'.$sel.'>'.$tipo_adop["tipo"].'</option>';
                                        }

                              echo '</select></div>';
                            } else {
                              echo '<div class="col-sm-4"></div>';
                            }

                            // 2026-11-10: se quitó el panel "Sobrecosto" (unidades vendidas × precio × %): el sobrecosto ya no es un
                            // % de la adopción sino un valor por libro (columna "Sobrecosto por libro" de la tabla).

                            // Venta directa - Eureka (2026-11-10): fechas y horario de venta y contacto del colegio. Solo se ve
                            // (y se envía) con ese canal; nombre y celular del contacto obligatorios, correo opcional.
                            require_once(__DIR__ . "/../includes/adopcion_vd_datos.php");
                            $vd_adop = adopcion_vd_leer($bdd, (int)$_GET["colegio"], (int)$gp_periodo["id"]);
                            $vd_jornadas = $vd_adop['jornadas'] ?: [['fecha' => '', 'hora_inicio' => '', 'hora_fin' => '']];
                            $vd_tiene = $vd_adop['tiene_fechas']; // true / false / null (sin responder)
                            $vd_dis = $con_sobrecosto ? '' : ' disabled';
                            $vd_e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
                            $vd_fila = function ($j) use ($vd_dis, $vd_e) {
                                return '<div class="vd-jornada">
                                          <div class="vd-dia" aria-hidden="true"><span class="vd-dia-sem">Día</span><strong class="vd-dia-num">--</strong><span class="vd-dia-mes">&nbsp;</span></div>
                                          <div class="vd-campos">
                                            <div class="vd-f vd-f-fecha"><label>Fecha <span class="vd-ob">*</span></label>
                                              <div class="vd-inp vd-pick" data-tipo="fecha" data-requerido><i class="bi bi-calendar3"></i><input type="text" class="vd-pick-txt" readonly placeholder="Elegir fecha"'.$vd_dis.'><input type="hidden" name="vd_fecha[]" value="'.$vd_e($j['fecha']).'"'.$vd_dis.'><i class="bi bi-chevron-down vd-caret"></i></div></div>
                                            <div class="vd-f"><label>Desde <span class="vd-ob">*</span></label>
                                              <div class="vd-inp vd-pick" data-tipo="hora" data-rol="ini" data-requerido><i class="bi bi-clock"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"'.$vd_dis.'><input type="hidden" name="vd_hora_ini[]" value="'.$vd_e($j['hora_inicio']).'"'.$vd_dis.'><i class="bi bi-chevron-down vd-caret"></i></div></div>
                                            <span class="vd-flecha" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
                                            <div class="vd-f"><label>Hasta <span class="vd-ob">*</span></label>
                                              <div class="vd-inp vd-pick" data-tipo="hora" data-rol="fin" data-requerido><i class="bi bi-clock-history"></i><input type="text" class="vd-pick-txt" readonly placeholder="--:--"'.$vd_dis.'><input type="hidden" name="vd_hora_fin[]" value="'.$vd_e($j['hora_fin']).'"'.$vd_dis.'><i class="bi bi-chevron-down vd-caret"></i></div></div>
                                            <span class="vd-duracion"></span>
                                          </div>
                                          <button type="button" class="vd-quitar" title="Quitar esta fecha"'.$vd_dis.'><i class="bi bi-trash"></i></button>
                                        </div>';
                            };
                            echo '<div class="col-12" id="vd_panel"'.($con_sobrecosto ? '' : ' style="display:none"').'>
                                    <div class="vd-panel">
                                      <div class="vd-tit"><i class="bi bi-shop"></i> Venta directa - Eureka</div>
                                          <div class="vd-pregunta">
                                            <span class="vd-pregunta-txt">¿El colegio ya tiene fechas de venta? <span class="vd-ob">*</span></span>
                                            <div class="vd-sino" role="radiogroup">
                                              <label><input type="radio" name="vd_tiene_fechas" value="1"'.($vd_tiene === true ? ' checked' : '').$vd_dis.'><span><i class="bi bi-check-circle"></i> Sí</span></label>
                                              <label><input type="radio" name="vd_tiene_fechas" value="0"'.($vd_tiene === false ? ' checked' : '').$vd_dis.'><span><i class="bi bi-x-circle"></i> No</span></label>
                                            </div>
                                          </div>
                                          <div class="vd-sin-fechas" id="vd_sin_fechas"'.($vd_tiene === false ? '' : ' style="display:none"').'><i class="bi bi-info-circle"></i> Sin fechas por ahora: puedes guardar la adopción. Cuando las tengan, vuelve aquí y marca <strong>Sí</strong> para registrar las fechas y el contacto.</div>
                                      <div class="vd-grid" id="vd_contenido"'.($vd_tiene === true ? '' : ' style="display:none"').'>
                                        <div>
                                          <div class="vd-sub"><i class="bi bi-calendar-event"></i> Fechas y horario de venta</div>
                                          <div id="vd_jornadas">';
                            foreach ($vd_jornadas as $j) echo $vd_fila($j);
                            echo '        </div>
                                          <button type="button" class="vd-agregar" id="vd_agregar"'.$vd_dis.'><i class="bi bi-plus-circle"></i> Agregar fecha</button>
                                        </div>
                                        <div>
                                          <div class="vd-sub"><i class="bi bi-person-lines-fill"></i> Contacto del colegio</div>
                                          <div class="vd-contacto">
                                            <div class="vd-f"><label>Nombre <span class="vd-ob">*</span></label>
                                              <div class="vd-inp"><i class="bi bi-person"></i><input type="text" name="vd_contacto_nombre" maxlength="150" placeholder="Nombre y apellido" value="'.$vd_e($vd_adop['contacto']['nombre']).'" required'.$vd_dis.'></div></div>
                                            <div class="vd-f"><label>Celular <span class="vd-ob">*</span></label>
                                              <div class="vd-inp"><i class="bi bi-phone"></i><input type="tel" name="vd_contacto_celular" id="vd_celular" maxlength="17" inputmode="tel" autocomplete="tel" placeholder="300 000 0000" value="'.$vd_e($vd_adop['contacto']['celular']).'" required'.$vd_dis.'><i class="bi vd-cel-estado"></i></div>
                                              <small class="vd-cel-msg" id="vd_celular_msg"></small></div>
                                            <div class="vd-f"><label>Correo <small>(opcional)</small></label>
                                              <div class="vd-inp"><i class="bi bi-envelope"></i><input type="email" name="vd_contacto_correo" maxlength="150" placeholder="correo@colegio.edu.co" value="'.$vd_e($vd_adop['contacto']['correo']).'"'.$vd_dis.'></div></div>
                                          </div>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                  <script src="src/vd-pickers.js?v=20261110"></script>
                                  <template id="vd_plantilla">'.$vd_fila(['fecha' => '', 'hora_inicio' => '', 'hora_fin' => '']).'</template>
                                  <script>
                                    // Tarjeta de cada fecha: día de la semana, duración y aviso si "hasta" no es posterior a "desde".
                                    function vdPintar($fila) {
                                      var f = $fila.find("input[name=\"vd_fecha[]\"]").val();
                                      var ini = $fila.find("input[name=\"vd_hora_ini[]\"]").val();
                                      var fin = $fila.find("input[name=\"vd_hora_fin[]\"]").val();
                                      var d = f ? new Date(f + "T12:00:00") : null;
                                      var ok = d && !isNaN(d);
                                      $fila.find(".vd-dia").toggleClass("vd-dia-vacio", !ok);
                                      $fila.find(".vd-dia-sem").text(ok ? d.toLocaleDateString("es-CO", { weekday: "short" }).replace(".", "") : "Día");
                                      $fila.find(".vd-dia-num").text(ok ? d.getDate() : "--");
                                      $fila.find(".vd-dia-mes").html(ok ? ["ene","feb","mar","abr","may","jun","jul","ago","sep","oct","nov","dic"][d.getMonth()] + " " + d.getFullYear() : "&nbsp;");
                                      var $dur = $fila.find(".vd-duracion").removeClass("vd-dur-error").text("");
                                      $fila.removeClass("vd-error");
                                      if (ini && fin) {
                                        var m = (parseInt(fin.substr(0, 2), 10) * 60 + parseInt(fin.substr(3, 2), 10)) - (parseInt(ini.substr(0, 2), 10) * 60 + parseInt(ini.substr(3, 2), 10));
                                        if (m > 0) {
                                          $dur.text((Math.floor(m / 60) ? Math.floor(m / 60) + " h" : "") + (m % 60 ? " " + (m % 60) + " min" : ""));
                                        } else {
                                          $dur.addClass("vd-dur-error").html("<i class=\"bi bi-exclamation-triangle\"></i> Revisa el horario");
                                          $fila.addClass("vd-error");
                                        }
                                      }
                                    }
                                    // ¿Ya tiene fechas de venta? Sí: se muestran y exigen las fechas. No: se ocultan y no se envían.
                                    window.vdAplicarTieneFechas = function () {
                                      var $panel = $("#vd_panel");
                                      var activo = !$panel.find("input[name=vd_tiene_fechas]").first().prop("disabled");
                                      var v = $panel.find("input[name=vd_tiene_fechas]:checked").val();
                                      $("#vd_contenido").toggle(v === "1").find(":input").prop("disabled", !activo || v !== "1");
                                      if (v === "1" && window.vdRevisarCelular) vdRevisarCelular(false);
                                      $("#vd_sin_fechas").toggle(v === "0");
                                      $panel.find(".vd-sino").removeClass("vd-falta");
                                      if (v !== "1") window.vdPickers.cerrar(false);
                                    };
                                    $("#vd_panel").on("change", "input[name=vd_tiene_fechas]", function () {
                                      window.vdAplicarTieneFechas();
                                      if (this.value === "1") {
                                        var $vacio = $("#vd_jornadas .vd-pick-txt").filter(function () { return !$(this).siblings("input[type=hidden]").val(); }).first();
                                        if ($vacio.length) $vacio.trigger("click").trigger("focus");
                                      }
                                    });
                                    window.vdAplicarTieneFechas();
                                    // Selectores propios de fecha y hora: src/vd-pickers.js.
                                    window.vdPickers.sincronizar($("#vd_panel .vd-pick"));
                                    $("#vd_jornadas .vd-jornada").each(function () { vdPintar($(this)); });
                                    $("#vd_jornadas").on("input change", "input", function () {
                                      var $fila = $(this).closest(".vd-jornada");
                                      // Si "Desde" queda igual o después de "Hasta", se vacía "Hasta" para elegirla de nuevo.
                                      if ($(this).attr("name") === "vd_hora_ini[]") {
                                        var $fin = $fila.find("input[name=\"vd_hora_fin[]\"]");
                                        if ($fin.val() && this.value && $fin.val() <= this.value) { $fin.val(""); window.vdPickers.sincronizar($fin.closest(".vd-pick")); }
                                      }
                                      vdPintar($fila);
                                    });
                                    $("#vd_agregar").on("click", function () {
                                      var $f = $($("#vd_plantilla").html());
                                      $f.find(":input").prop("disabled", false);
                                      $("#vd_jornadas").append($f);
                                      vdPintar($f);
                                      $f.find(".vd-pick-txt").first().trigger("click").trigger("focus");
                                    });
                                    $("#vd_jornadas").on("click", ".vd-quitar", function () {
                                      window.vdPickers.cerrar(false);
                                      var $fila = $(this).closest(".vd-jornada");
                                      var quitar = function () {
                                        var $filas = $("#vd_jornadas .vd-jornada");
                                        if ($filas.length > 1) $fila.remove();
                                        else { $filas.find("input").val(""); vdPintar($filas); }
                                      };
                                      // Fila vacía: se quita sin preguntar. Con fecha: confirmar (2026-11-11).
                                      var f = $fila.find("input[name=\"vd_fecha[]\"]").val() || "";
                                      if (!f) { quitar(); return; }
                                      var ini = $fila.find("input[name=\"vd_hora_ini[]\"]").val() || "", fin = $fila.find("input[name=\"vd_hora_fin[]\"]").val() || "";
                                      var h12 = function (h) { if (!h) return ""; var hh = parseInt(h.substr(0, 2), 10); return (hh % 12 || 12) + ":" + h.substr(3, 2) + (hh < 12 ? " a. m." : " p. m."); };
                                      var txt = f.substr(8, 2) + "/" + f.substr(5, 2) + "/" + f.substr(0, 4) + (ini ? " de " + h12(ini) + (fin ? " a " + h12(fin) : "") : "");
                                      var opts = { type: "danger", title: "¿Segura que quieres eliminar esta venta?", text: "Se quitará la venta del " + txt + ". El cambio queda en firme al guardar la adopción.", btnOk: "Sí, eliminar" };
                                      if (window.inkConfirm) window.inkConfirm(opts, quitar);
                                      else if (confirm(opts.title + " " + opts.text)) quitar();
                                    });
                                    // Celular colombiano: 10 dígitos que empiezan por 3 (con o sin +57). Misma regla que
                                    // adopcion_vd_celular() en includes/adopcion_vd_datos.php.
                                    function vdCelular(v) {
                                      v = String(v || "").trim();
                                      if (!v) return { ok: false, vacio: true, msg: "El celular es obligatorio." };
                                      if (!/^[0-9+\s().-]+$/.test(v)) return { ok: false, msg: "Solo se permiten números." };
                                      if (v.indexOf("+") > 0 || (v.match(/\+/g) || []).length > 1) return { ok: false, msg: "El + solo puede ir al inicio (+57)." };
                                      var d = v.replace(/\D/g, "");
                                      if (d.length === 12 && d.substr(0, 2) === "57") d = d.substr(2);
                                      else if (v.charAt(0) === "+") return { ok: false, msg: "Solo se aceptan celulares de Colombia (+57)." };
                                      if (d.charAt(0) && d.charAt(0) !== "3") return { ok: false, msg: "Un celular en Colombia empieza por 3." };
                                      if (d.length < 10) return { ok: false, incompleto: true, msg: (10 - d.length === 1 ? "Falta " : "Faltan ") + (10 - d.length) + " dígito" + (10 - d.length === 1 ? "" : "s") + " (debe tener 10)." };
                                      if (d.length > 10) return { ok: false, msg: "Tiene " + d.length + " dígitos; un celular tiene 10." };
                                      if (/^3(\d)\1{8}$/.test(d)) return { ok: false, msg: "Ese número no parece real." };
                                      return { ok: true, numero: d.substr(0, 3) + " " + d.substr(3, 3) + " " + d.substr(6) };
                                    }
                                    function vdRevisarCelular(estricto) {
                                      var el = document.getElementById("vd_celular");
                                      if (!el) return true;
                                      var r = vdCelular(el.value);
                                      var $w = $(el).closest(".vd-inp");
                                      // Mientras escribe no se marca en rojo lo que solo está incompleto.
                                      var mostrarError = !r.ok && (estricto || !(r.incompleto || r.vacio));
                                      el.setCustomValidity(r.ok || el.disabled ? "" : r.msg);
                                      $w.toggleClass("vd-cel-ok", r.ok).toggleClass("vd-cel-mal", mostrarError);
                                      $w.find(".vd-cel-estado").attr("class", "bi vd-cel-estado " + (r.ok ? "bi-check-circle-fill" : (mostrarError ? "bi-exclamation-circle-fill" : "")));
                                      $("#vd_celular_msg").text(mostrarError ? r.msg : (r.ok ? "" : (el.value ? r.msg : "10 dígitos, empieza por 3")))
                                        .toggleClass("vd-cel-msg-error", mostrarError);
                                      return r.ok;
                                    }
                                    $("#vd_celular").on("input", function () {
                                      // Formato 300 123 4567 al escribir (si no trae indicativo).
                                      var d = this.value.replace(/\D/g, "");
                                      if (this.value.indexOf("+") === -1 && /^[0-9\s().-]*$/.test(this.value) && d.length <= 10) {
                                        var f = d.substr(0, 3) + (d.length > 3 ? " " + d.substr(3, 3) : "") + (d.length > 6 ? " " + d.substr(6) : "");
                                        if (f !== this.value) this.value = f;
                                      }
                                      vdRevisarCelular(false);
                                    }).on("blur", function () {
                                      var r = vdCelular(this.value);
                                      if (r.ok) this.value = r.numero; // +57 300... queda como 300 ...
                                      vdRevisarCelular(!!this.value);
                                    });
                                    vdRevisarCelular(false);
                                    $("#form_definicion").on("submit", function (e) {
                                      var el = document.getElementById("vd_celular");
                                      if (!el || el.disabled || !$("#vd_panel").is(":visible")) return;
                                      if (vdRevisarCelular(true)) return;
                                      e.preventDefault();
                                      e.stopImmediatePropagation();
                                      var msg = "Celular del contacto: " + vdCelular(el.value).msg;
                                      if (typeof window.inkToast === "function") window.inkToast(msg, "warn"); else alert(msg);
                                      el.scrollIntoView({ block: "center" });
                                      el.focus();
                                      return false;
                                    });
                                    // Los campos de fecha/hora no son inputs nativos: se revisan antes de enviar.
                                    $("#form_definicion").on("submit", function (e) {
                                      var $panel = $("#vd_panel");
                                      if (!$panel.is(":visible")) return;
                                      if (!$panel.find("input[name=vd_tiene_fechas]:checked").length) {
                                        e.preventDefault();
                                        e.stopImmediatePropagation();
                                        var m = "Indica si el colegio ya tiene fechas de venta (Sí / No).";
                                        if (typeof window.inkToast === "function") window.inkToast(m, "warn"); else alert(m);
                                        var $sino = $panel.find(".vd-sino").addClass("vd-falta");
                                        $sino[0].scrollIntoView({ block: "center" });
                                        return false;
                                      }
                                      var $f = window.vdPickers.faltantes($panel);
                                      var malHorario = $panel.find(".vd-jornada.vd-error").length > 0;
                                      if (!$f.length && !malHorario) return;
                                      e.preventDefault();
                                      e.stopImmediatePropagation();
                                      var msg = $f.length ? "Completa las fechas y el horario de venta (Venta directa - Eureka)." : "En una fecha de venta la hora \"hasta\" debe ser posterior a la hora \"desde\".";
                                      if (typeof window.inkToast === "function") window.inkToast(msg, "warn"); else alert(msg);
                                      ($f.length ? $f.first() : $panel.find(".vd-jornada.vd-error").first())[0].scrollIntoView({ block: "center" });
                                      return false;
                                    });
                                  </script>';

                            echo '
                            </div>
                            <br><div class="row">';
                              $archivos_existentes = [];
                              if ($count > 0) {
                                  foreach (['archivo', 'archivo2', 'archivo3'] as $campo_arch) {
                                      if (!empty($recursos[$campo_arch])) $archivos_existentes[] = $recursos[$campo_arch];
                                  }
                              }
                              $arch_existente = count($archivos_existentes) > 0;
                              $periodo_activo = $_GET["f_cierre"] > date("Y-m-d") && !$adop_cerrada;

                              if ($periodo_activo) {
                                  // Periodo activo: upload interactivo
                                  $arch_label_class = $arch_existente ? ' has-file' : '';
                                  $arch_icon_text   = $arch_existente
                                      ? '<i class="bi bi-check-circle-fill" style="font-size:1.2rem;"></i><span id="adop-file-text">Documentos cargados — clic para reemplazar</span>'
                                      : '<i class="bi bi-cloud-upload" style="font-size:1.2rem;"></i><span id="adop-file-text">Haz clic para seleccionar hasta 3 archivos</span>';
                                  $arch_name_html = '<p class="adop-file-name" id="adop-file-name">'
                                      . implode('<br>', array_map(function ($a) {
                                          return '<a href="'.htmlspecialchars($a).'" target="_blank" rel="noopener">'.htmlspecialchars(basename($a)).'</a>';
                                      }, $archivos_existentes))
                                      . '</p>';
                                  //$arch_req_badge = $arch_existente ? '' : ' <span style="color:#dc2626">*</span>';
                                  $arch_req_badge = $arch_existente ? '' : ' ';

                                  echo '<div class="col-sm-4">
                                          <span class="form-label-sm">
                                            <i class="bi bi-paperclip"></i> Acuerdo de adopción'.$arch_req_badge.' <span class="text-muted" style="font-weight:400;">(máx. 3 archivos)</span>
                                          </span>
                                          <label class="adop-file-label'.$arch_label_class.'" id="adop-file-label" for="archivo_adopcion">
                                            '.$arch_icon_text.'
                                          </label>
                                          <input type="file" name="archivo_adopcion[]" id="archivo_adopcion" multiple
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
                                  if ($archivos_existentes) {
                                      foreach ($archivos_existentes as $a) {
                                          echo '<p class="adop-file-name"><i class="bi bi-file-earmark-check" style="color:#16a34a;margin-right:4px;"></i><a href="'.htmlspecialchars($a).'" target="_blank" rel="noopener">'.htmlspecialchars(basename($a)).'</a></p>';
                                      }
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

                          echo '</div>'; // .adop-footer-form

                          require_once(__DIR__ . "/../includes/paquetes_colegio.php");
                          crear_tablas_paquetes($bdd);

                          $tipo_adop_actual = ($count > 0) ? (int)$recursos["tipo_adop"] : 0;

                          // Selección manual de títulos para el paquete: solo tiene efecto con
                          // Tipo de adopción "Ambos" (id 3) — con "Paquetes" solo, todo lo
                          // adoptado entra al paquete sin necesidad de elegir. Todo este bloque
                          // (selección + previsualización) es exclusivo de Administrador/Héctor
                          // Morales — ver puede_usar_tipo_adopcion().
                          // Usuario tipo 2: no arma paquetes, pero sí ve las fichas técnicas de los ya guardados.
                          if (!puede_usar_tipo_adopcion() && puede_ver_fichas_paquete() && tipo_adop_con_fichas($tipo_adop_actual)) {
                              $req_n_paq2 = $bdd->prepare("SELECT COUNT(*) FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=?");
                              $req_n_paq2->execute([(int)$_GET['colegio'], (int)$gp_periodo['id']]);
                              if ((int)$req_n_paq2->fetchColumn() > 0) {
                                  echo '<div style="margin-top:16px;"><a href="ficha_paquete.php?colegio=' . (int)$_GET['colegio'] . '&periodo=' . (int)$gp_periodo['id'] . '" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">'
                                     . '<i class="bi bi-file-earmark-richtext"></i> Fichas técnicas</a></div>';
                              }
                          }
                          $grupos_seleccion = puede_usar_tipo_adopcion() ? titulos_adoptados_por_grado($bdd, $_GET['colegio'], $gp_periodo['id']) : [];
                          if ($grupos_seleccion) {
                              $grados_map_sel = [];
                              foreach ($bdd->query("SELECT id, grado FROM grados") as $g_sel) $grados_map_sel[(int)$g_sel['id']] = $g_sel['grado'];
                              ksort($grupos_seleccion);
                      ?>
                      <div class="adop-footer-form" id="panel-seleccion-paquete" style="margin-top:22px;<?= ($tipo_adop_actual === 3) ? '' : ' display:none;' ?>">
                        <style>
                          #panel-seleccion-paquete .amb-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; margin-bottom:10px; }
                          #panel-seleccion-paquete .amb-grupo { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; overflow:hidden; background:#fff; }
                          #panel-seleccion-paquete .amb-head { display:flex; align-items:center; gap:10px; padding:9px 14px; background:#f8fafc; cursor:pointer; user-select:none; }
                          #panel-seleccion-paquete .amb-head strong { font-size:.86rem; color:#1e293b; flex:1 1 auto; }
                          #panel-seleccion-paquete .amb-head .bi-chevron-down { transition:transform .15s; color:#64748b; }
                          #panel-seleccion-paquete .amb-grupo.cerrado .bi-chevron-down { transform:rotate(-90deg); }
                          #panel-seleccion-paquete .amb-grupo.cerrado table { display:none; }
                          #panel-seleccion-paquete .amb-badge { font-size:.72rem; border-radius:999px; padding:2px 9px; font-weight:600; white-space:nowrap; }
                          #panel-seleccion-paquete .amb-badge.paq { background:#eef0ff; color:#4361ee; }
                          #panel-seleccion-paquete .amb-badge.paq2 { background:#fdf2f8; color:#be185d; }
                          #panel-seleccion-paquete .amb-grupo:not(.con-p2) .amb-p2 { display:none; }
                          #panel-seleccion-paquete .amb-toggle-p2 { font-size:.76rem; padding:0 6px; white-space:nowrap; }
                          #panel-seleccion-paquete .amb-badge.sue { background:#ecfdf5; color:#047857; }
                          #panel-seleccion-paquete table { width:100%; margin:0; font-size:.82rem; }
                          #panel-seleccion-paquete th { background:#fff; color:#64748b; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; padding:7px 14px; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; }
                          #panel-seleccion-paquete td { padding:7px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; color:#374151; }
                          #panel-seleccion-paquete tr:last-child td { border-bottom:0; }
                          #panel-seleccion-paquete .amb-col { width:150px; text-align:center; white-space:nowrap; }
                          #panel-seleccion-paquete .amb-col input { width:16px; height:16px; cursor:pointer; vertical-align:middle; }
                          #panel-seleccion-paquete th.amb-col label { margin:0; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; gap:5px; }
                          #panel-seleccion-paquete .amb-isbn { color:#94a3b8; font-size:.72rem; margin-left:6px; white-space:nowrap; }
                          #panel-seleccion-paquete .amb-aviso { display:none; color:#b45309; font-size:.72rem; margin-left:6px; white-space:nowrap; }
                          #panel-seleccion-paquete tr.sin-venta td { background:#fffbeb; }
                          #panel-seleccion-paquete tr.sin-venta .amb-aviso { display:inline; }
                        </style>
                        <?php
                          // Toda la selección viaja en UN solo campo (JSON {paq:[ids], paq2:[ids], sue:[ids]}), no como
                          // dos checkboxes con name por libro: en colegios grandes el formulario ya supera el
                          // límite de campos de PHP (max_input_vars) y el guardado llegaba incompleto.
                          $sel_inicial = ['paq' => [], 'paq2' => [], 'sue' => []];
                          foreach ($grupos_seleccion as $g_ini) foreach ($g_ini['titulos'] as $t_ini) {
                              if ($t_ini['en_paquete'])   $sel_inicial['paq'][] = (int)$t_ini['id_presupuesto'];
                              if ($t_ini['en_paquete2'])  $sel_inicial['paq2'][] = (int)$t_ini['id_presupuesto'];
                              if ($t_ini['venta_suelta']) $sel_inicial['sue'][] = (int)$t_ini['id_presupuesto'];
                          }
                        ?>
                        <input type="hidden" name="seleccion_ambos" id="seleccion_ambos" value="<?= htmlspecialchars(json_encode($sel_inicial)) ?>">
                        <span class="form-label-sm"><i class="bi bi-ui-checks"></i> Libros para paquete y venta suelta</span>
                        <p class="text-muted" style="font-size:.82rem;margin-top:-4px;">
                          Con Tipo de adopción "Ambos", marca por curso qué libros forman el <strong>paquete</strong> (y, si lo necesitas,
                          un <strong>paquete 2</strong>) y cuáles se pueden comprar como <strong>libro suelto</strong>. Las opciones son
                          independientes: un libro puede estar en los dos paquetes y también venderse suelto.
                        </p>
                        <div class="amb-toolbar">
                          <span class="text-muted" style="font-size:.78rem;"><?= count($grupos_seleccion) ?> cursos</span>
                          <span>
                            <button type="button" class="btn btn-link btn-sm amb-expandir" data-abrir="1" style="font-size:.78rem;padding:0 6px;">Expandir todo</button>
                            <button type="button" class="btn btn-link btn-sm amb-expandir" data-abrir="0" style="font-size:.78rem;padding:0 6px;">Contraer todo</button>
                          </span>
                        </div>
                        <?php foreach ($grupos_seleccion as $id_grado_sel => $g_sel): ?>
                        <?php $con_p2 = (bool)array_filter($g_sel['titulos'], function ($t) { return !empty($t['en_paquete2']); }); ?>
                        <div class="amb-grupo<?= $con_p2 ? ' con-p2' : '' ?>">
                          <div class="amb-head">
                            <i class="bi bi-chevron-down"></i>
                            <strong><?= htmlspecialchars(nombre_grado_ficha($id_grado_sel, $grados_map_sel[$id_grado_sel] ?? ('Grado ' . $id_grado_sel))) ?></strong>
                            <span class="amb-badge paq"><span class="amb-n-paq">0</span> en paquete</span>
                            <span class="amb-badge paq2 amb-p2"><span class="amb-n-paq2">0</span> en paquete 2</span>
                            <span class="amb-badge sue"><span class="amb-n-sue">0</span> sueltos</span>
                            <button type="button" class="btn btn-link btn-sm amb-toggle-p2"><?= $con_p2 ? '<i class="bi bi-dash-circle"></i> Quitar paquete 2' : '<i class="bi bi-plus-circle"></i> Agregar paquete 2' ?></button>
                          </div>
                          <table>
                            <thead><tr>
                              <th>Nombre del libro</th>
                              <th class="amb-col"><label title="Marcar/desmarcar todos"><input type="checkbox" class="amb-todos" data-col="paq"> Paquete</label></th>
                              <th class="amb-col amb-p2"><label title="Marcar/desmarcar todos"><input type="checkbox" class="amb-todos" data-col="paq2"> Paquete 2</label></th>
                              <th class="amb-col"><label title="Marcar/desmarcar todos"><input type="checkbox" class="amb-todos" data-col="sue"> Libro suelto</label></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($g_sel['titulos'] as $t_sel): ?>
                              <tr>
                                <td><?= htmlspecialchars($t_sel['libro']) ?><?php if ($t_sel['isbn'] !== ''): ?><span class="amb-isbn">ISBN <?= htmlspecialchars($t_sel['isbn']) ?></span><?php endif; ?><span class="amb-aviso"><i class="bi bi-exclamation-triangle"></i> No se venderá</span></td>
                                <td class="amb-col"><input type="checkbox" class="amb-chk" data-col="paq" value="<?= (int)$t_sel['id_presupuesto'] ?>" <?= $t_sel['en_paquete'] ? 'checked' : '' ?>></td>
                                <td class="amb-col amb-p2"><input type="checkbox" class="amb-chk" data-col="paq2" value="<?= (int)$t_sel['id_presupuesto'] ?>" <?= $t_sel['en_paquete2'] ? 'checked' : '' ?>></td>
                                <td class="amb-col"><input type="checkbox" class="amb-chk" data-col="sue" value="<?= (int)$t_sel['id_presupuesto'] ?>" <?= $t_sel['venta_suelta'] ? 'checked' : '' ?>></td>
                              </tr>
                            <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <?php endforeach; ?>
                        <script>
                          (function(){
                            // Contadores por curso, "marcar todos" por columna y aviso cuando un libro
                            // no queda ni en el paquete ni como suelto.
                            function refrescarGrupo(grupo) {
                              var filas = grupo.find('tbody tr');
                              ['paq', 'paq2', 'sue'].forEach(function(col){
                                var chks = filas.find('.amb-chk[data-col="' + col + '"]');
                                var n = chks.filter(':checked').length;
                                grupo.find('.amb-n-' + col).text(n);
                                grupo.find('.amb-todos[data-col="' + col + '"]').prop('checked', n === chks.length && n > 0);
                              });
                              filas.each(function(){
                                $(this).toggleClass('sin-venta', $(this).find('.amb-chk:checked').length === 0);
                              });
                            }
                            function actualizarSeleccionAmbos() {
                              var sel = { paq: [], paq2: [], sue: [] };
                              $('#panel-seleccion-paquete .amb-chk:checked').each(function(){
                                sel[$(this).data('col')].push(parseInt(this.value, 10));
                              });
                              $('#seleccion_ambos').val(JSON.stringify(sel));
                            }
                            $('#panel-seleccion-paquete .amb-grupo').each(function(){ refrescarGrupo($(this)); });
                            $('#panel-seleccion-paquete').on('change', '.amb-chk', function(){
                              refrescarGrupo($(this).closest('.amb-grupo'));
                              actualizarSeleccionAmbos();
                            });
                            $('#panel-seleccion-paquete').on('change', '.amb-todos', function(){
                              var grupo = $(this).closest('.amb-grupo');
                              grupo.find('.amb-chk[data-col="' + $(this).data('col') + '"]').prop('checked', this.checked);
                              refrescarGrupo(grupo);
                              actualizarSeleccionAmbos();
                            });
                            $('#form_definicion').on('submit', actualizarSeleccionAmbos);
                            // Paquete 2: oculto hasta que se active en el curso; al quitarlo se desmarcan sus libros.
                            $('#panel-seleccion-paquete').on('click', '.amb-toggle-p2', function(e){
                              e.stopPropagation(); // no colapsar el curso
                              var grupo = $(this).closest('.amb-grupo');
                              var activar = !grupo.hasClass('con-p2');
                              grupo.toggleClass('con-p2', activar).removeClass('cerrado');
                              if (!activar) grupo.find('.amb-chk[data-col="paq2"], .amb-todos[data-col="paq2"]').prop('checked', false);
                              $(this).html(activar ? '<i class="bi bi-dash-circle"></i> Quitar paquete 2' : '<i class="bi bi-plus-circle"></i> Agregar paquete 2');
                              refrescarGrupo(grupo);
                              actualizarSeleccionAmbos();
                            });
                            $('#panel-seleccion-paquete').on('click', '.amb-head', function(){
                              $(this).closest('.amb-grupo').toggleClass('cerrado');
                            });
                            $('#panel-seleccion-paquete').on('click', '.amb-expandir', function(){
                              $('#panel-seleccion-paquete .amb-grupo').toggleClass('cerrado', $(this).data('abrir') != 1);
                            });
                          })();
                        </script>
                      </div>

                      <div id="panel-previsualizar-paquete" style="margin-top:16px;<?= in_array($tipo_adop_actual, [2, 3], true) ? '' : ' display:none;' ?>">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-previsualizar-paquete">
                          <i class="bi bi-eye"></i> Previsualizar paquetes
                        </button>
                        <?php
                          // Fichas técnicas: solo con Tipo de adopción "Paquetes" o "Ambos" YA guardado y con paquetes guardados.
                          $req_n_paq = $bdd->prepare("SELECT COUNT(*) FROM paquetes_colegio WHERE id_colegio=? AND id_periodo=?");
                          $req_n_paq->execute([(int)$_GET['colegio'], (int)$gp_periodo['id']]);
                          if (puede_ver_fichas_paquete() && tipo_adop_con_fichas($tipo_adop_actual) && (int)$req_n_paq->fetchColumn() > 0):
                        ?>
                        <a href="ficha_paquete.php?colegio=<?= (int)$_GET['colegio'] ?>&periodo=<?= (int)$gp_periodo['id'] ?>" target="_blank" rel="noopener"
                           class="btn btn-outline-success btn-sm" id="btn-fichas-paquete" style="margin-left:6px;">
                          <i class="bi bi-file-earmark-richtext"></i> Fichas técnicas
                        </a>
                        <?php endif; ?>
                        <div id="resultado-preview-paquete" style="margin-top:14px;"></div>
                        <input type="hidden" name="precios_paquete" id="precios_paquete" form="form_definicion" value="{}">
                        <?php if ($show_guardar): ?>
                        <button type="submit" form="form_definicion" class="btn btn-success btn-sm miBoton" style="margin-top:14px;">
                          <i class="bi bi-floppy"></i> Guardar cambios
                        </button>
                        <?php endif; ?>
                      </div>

                      <script>
                        // Tipo de adopción YA guardado: el piso del precio y las fichas técnicas
                        // dependen de este (igual que la validación del servidor), no del select sin guardar.
                        var tipoAdopGuardado = <?= (int)$tipo_adop_actual ?>;
                        // Fichas técnicas: solo usuarios tipo 1 y 2 (Héctor solo arma los paquetes).
                        var verFichasPaquete = <?= puede_ver_fichas_paquete() ? 'true' : 'false' ?>;
                        // Precio redondeado escrito en la previsualización para paquetes que aún no están
                        // guardados: clave "grado-número" => valor; viaja en #precios_paquete al guardar.
                        var preciosPendientes = {};

                        $('#tipo_adop').on('change', function(){
                          var val = $(this).val();
                          $('#panel-seleccion-paquete').toggle(val == '3');
                          $('#panel-previsualizar-paquete').toggle(val == '2' || val == '3');
                          $('#btn-fichas-paquete').toggle(val == '2' || val == '3');
                          $('#resultado-preview-paquete').empty();
                        });

                        function escHtmlPaquete(s) {
                          return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
                            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                          });
                        }

                        function formatCopPaquete(v) {
                          return '$' + Number(v).toLocaleString('es-CO', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        }

                        function renderPreviewPaquetes(paquetes) {
                          var cont = $('#resultado-preview-paquete');
                          if (!paquetes || !paquetes.length) {
                            cont.html('<p class="text-muted" style="font-size:.85rem;"><i class="bi bi-info-circle"></i> Con la selección actual no se arma ningún paquete.</p>');
                            return;
                          }
                          var html = '<div class="table-responsive"><table class="table table-sm" style="font-size:.84rem;">' +
                            '<thead><tr><th>Paquete</th><th>Código</th><th>Títulos incluidos</th><th>Precio neto (sumatoria)</th><th>Precio redondeado</th><th>Precio de venta</th></tr></thead><tbody>';
                          // Con Tipo de adopción "Paquetes": ISBN por libro, piso del precio y ficha técnica.
                          var modoPaquetes = $('#tipo_adop').val() == '2';
                          var precioConPiso = tipoAdopGuardado === 2;
                          paquetes.forEach(function(p){
                            var titulos;
                            if (modoPaquetes) {
                              titulos = (p.titulos || []).map(function(t){
                                return '<div>' + escHtmlPaquete(t.libro) +
                                  ' <span class="text-muted" style="font-size:.76rem;white-space:nowrap;">ISBN ' + (t.isbn ? escHtmlPaquete(t.isbn) : '—') + '</span></div>';
                              }).join('');
                            } else {
                              titulos = (p.titulos || []).map(function(t){ return escHtmlPaquete(t.libro); }).join(', ');
                            }
                            var redondeado;
                            if (p.id_paquete && adopCerrada) {
                              redondeado = (p.precio_redondeado !== null && p.precio_redondeado !== undefined) ? formatCopPaquete(p.precio_redondeado) : '<span class="text-muted">Sin definir</span>';
                            } else if (p.id_paquete) {
                              // Paquete ya guardado: se puede definir/editar el precio redondeado al vuelo.
                              var valorInput = (p.precio_redondeado !== null && p.precio_redondeado !== undefined) ? Number(p.precio_redondeado).toFixed(2) : '';
                              var conPiso = precioConPiso && p.precio_minimo !== null && p.precio_minimo !== undefined;
                              redondeado = '<span class="precio-padre-wrap"><span class="pp-signo">$</span>' +
                                '<input type="text" class="precio-padre-inp precio-paquete-inp" data-id-paquete="' + p.id_paquete + '" ' +
                                (conPiso ? 'data-precio-minimo="' + p.precio_minimo + '" ' : '') +
                                'data-valor-previo="' + escHtmlPaquete(valorInput) + '" ' +
                                'value="' + escHtmlPaquete(valorInput) + '" placeholder="Sin definir"></span>' +
                                (conPiso ? '<div class="text-muted" style="font-size:.74rem;">Mínimo ' + formatCopPaquete(p.precio_minimo) + '</div>' : '');
                            } else if (!adopCerrada) {
                              // Paquete aún sin guardar: el precio se escribe ya y se guarda con "Guardar cambios".
                              var clave = p.id_grado + '-' + p.numero;
                              var pend = preciosPendientes[clave] !== undefined ? preciosPendientes[clave] : '';
                              var conPisoPend = $('#tipo_adop').val() == '2' && p.precio_minimo !== null && p.precio_minimo !== undefined;
                              if (pend !== '') p.precio_final = Number(pend);
                              redondeado = '<span class="precio-padre-wrap"><span class="pp-signo">$</span>' +
                                '<input type="text" class="precio-padre-inp precio-paquete-pend" data-clave="' + clave + '" ' +
                                'data-suma="' + p.precio_neto_sumado + '" ' +
                                (conPisoPend ? 'data-precio-minimo="' + p.precio_minimo + '" ' : '') +
                                'value="' + escHtmlPaquete(pend) + '" placeholder="Sin definir"></span>' +
                                (conPisoPend ? '<div class="text-muted" style="font-size:.74rem;">Mínimo ' + formatCopPaquete(p.precio_minimo) + '</div>' : '') +
                                '<div class="text-muted" style="font-size:.72rem;">Se guarda con "Guardar cambios"</div>';
                            } else {
                              redondeado = '<span class="text-muted">Sin definir</span>';
                            }
                            // Ficha técnica con "Paquetes" o "Ambos" (con Ambos, solo los libros marcados como Paquete).
                            var tipoSel = $('#tipo_adop').val();
                            var fichaLink = (verFichasPaquete && (tipoSel == '2' || tipoSel == '3') && (tipoAdopGuardado === 2 || tipoAdopGuardado === 3) && p.id_paquete)
                              ? '<br><a href="ficha_paquete.php?colegio=<?= (int)$_GET['colegio'] ?>&periodo=<?= (int)$gp_periodo['id'] ?>&paquete=' + p.id_paquete + '" target="_blank" rel="noopener" style="font-size:.76rem;"><i class="bi bi-file-earmark-richtext"></i> Ficha técnica</a>'
                              : '';
                            html += '<tr>' +
                              '<td><strong>' + escHtmlPaquete(p.paquete) + '</strong>' + fichaLink + '</td>' +
                              '<td>' + escHtmlPaquete(p.codigo) + '</td>' +
                              '<td>' + titulos + ' <span class="text-muted">(' + p.cantidad_titulos + ')</span></td>' +
                              '<td>' + formatCopPaquete(p.precio_neto_sumado) + '</td>' +
                              '<td>' + redondeado + '</td>' +
                              '<td class="venta" id="precio-final-paq' + (p.id_paquete || ('g' + p.id_grado + '-' + p.numero)) + '">' + formatCopPaquete(p.precio_final) + '</td>' +
                              '</tr>';
                          });
                          html += '</tbody></table></div><p class="text-muted" style="font-size:.78rem;"><i class="bi bi-info-circle"></i> Vista previa con la selección actual. Usa "Guardar cambios" para que quede guardada.</p>';
                          cont.html(html);
                        }

                        // Precio de un paquete aún sin guardar: se valida y se deja pendiente para "Guardar cambios".
                        $(document).on('change', '.precio-paquete-pend', function(){
                          var input = $(this);
                          var clave = String(input.data('clave'));
                          var valor = input.val().trim().replace(/[$\s.]/g, '').replace(',', '.');
                          var minimo = input.attr('data-precio-minimo');
                          if (valor !== '' && !(Number(valor) >= 0)) {
                            alert('Escribe un precio válido.');
                            input.val(preciosPendientes[clave] || '');
                            return;
                          }
                          if (minimo !== undefined && valor !== '' && !(Number(valor) >= Number(minimo))) {
                            alert('El precio del paquete no puede ser menor a ' + formatCopPaquete(minimo) +
                                  ' (se permite bajar como máximo $1.000 del precio original).');
                            input.val(preciosPendientes[clave] || '');
                            return;
                          }
                          if (valor === '') delete preciosPendientes[clave]; else preciosPendientes[clave] = valor;
                          input.val(valor);
                          $('#precios_paquete').val(JSON.stringify(preciosPendientes));
                          $('#precio-final-paqg' + clave).text(formatCopPaquete(valor !== '' ? valor : input.data('suma')));
                        });

                        // Delegado porque la tabla se inserta dinámicamente (no existe al cargar la página).
                        $(document).on('change', '.precio-paquete-inp', function(){
                          var input = $(this);
                          var idPaquete = input.data('id-paquete');
                          var valor = input.val().trim();
                          var valorPrevio = String(input.attr('data-valor-previo') || '');

                          // Tipo de adopción "Paquetes": como máximo $1.000 por debajo del precio original
                          // (misma regla que precio_minimo_paquete() en el servidor, que es la que manda).
                          var minimo = input.attr('data-precio-minimo');
                          if (minimo !== undefined && valor !== '' && !(Number(valor) >= Number(minimo))) {
                            alert('El precio del paquete no puede ser menor a ' + formatCopPaquete(minimo) +
                                  ' (se permite bajar como máximo $1.000 del precio original).');
                            input.val(valorPrevio);
                            return;
                          }

                          $.ajax({
                            url: "ajax/guardar_precio_paquete.php",
                            type: "POST",
                            data: { id_paquete: idPaquete, precio_redondeado: valor },
                            dataType: "json",
                            success: function(resp){
                              if (resp && resp.ok) {
                                input.attr('data-valor-previo', valor);
                                $('#precio-final-paq' + idPaquete).text(formatCopPaquete(resp.precio_final));
                              } else {
                                if (minimo !== undefined) input.val(valorPrevio);
                                alert('No se pudo guardar el precio del paquete.');
                              }
                            },
                            error: function(xhr){
                              if (minimo !== undefined) input.val(valorPrevio);
                              var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'No se pudo guardar el precio del paquete.';
                              alert(msg);
                            }
                          });
                        });

                        $('#btn-previsualizar-paquete').on('click', function(){
                          var definidos = [];
                          $('.definir:checked').each(function(){
                            var idp = $(this).data('id-presupuesto');
                            if (idp) definidos.push(idp);
                          });
                          var enPaquete = [];
                          $('#panel-seleccion-paquete .amb-chk[data-col="paq"]:checked').each(function(){
                            enPaquete.push($(this).val());
                          });
                          var enPaquete2 = [];
                          $('#panel-seleccion-paquete .amb-chk[data-col="paq2"]:checked').each(function(){
                            enPaquete2.push($(this).val());
                          });

                          var btn = $(this);
                          btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Cargando...');

                          $.ajax({
                            url: 'ajax/previsualizar_paquetes.php',
                            type: 'POST',
                            data: {
                              id_colegio: <?= (int)$_GET['colegio'] ?>,
                              periodo: <?= (int)$gp_periodo['id'] ?>,
                              tipo_adop: $('#tipo_adop').val(),
                              definidos: definidos,
                              en_paquete: enPaquete,
                              en_paquete2: enPaquete2
                            },
                            dataType: 'json',
                            success: function(resp){
                              btn.prop('disabled', false).html('<i class="bi bi-eye"></i> Previsualizar paquetes');
                              if (!resp || !resp.ok) { alert('No se pudo previsualizar los paquetes.'); return; }
                              renderPreviewPaquetes(resp.paquetes);
                            },
                            error: function(){
                              btn.prop('disabled', false).html('<i class="bi bi-eye"></i> Previsualizar paquetes');
                              alert('No se pudo previsualizar los paquetes.');
                            }
                          });
                        });
                      </script>
                      <?php
                          }

                          // Debe ser el ÚLTIMO campo del formulario: ver guardar_definicion.php.
                          echo '<input type="hidden" name="form_completo" value="1">';
                          echo '</form>';
                       ?>


</div>
<script>var librosYaEnAdop = <?= json_encode($ids_exist_adop) ?>;</script>
<script>var adopCerrada = <?= $adop_cerrada ? 'true' : 'false' ?>;</script>
<?php if ($adop_cerrada): ?>
<script>
  // Adopción cerrada: todo el formulario queda en solo lectura (el servidor también rechaza los guardados).
  $(function () {
    $('#form_definicion').find('input, select, textarea').attr('disabled', 'disabled');
  });
</script>
<?php endif; ?>
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
        $('#adop-card-cum').text(text);
        var $card = $('#adop-card-cum-wrap');
        var $icon = $('#adop-card-cum-icon');
        var $val  = $('#adop-card-cum');
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
    $('#adop-card-vp').text($('#total_vp_d').text() || '—');
    $('#adop-card-vr').text($('#total_vr').text()   || '—');
    setCumCard(cumplimiento);

    // ── Filtro todos / adoptados ──────────────────────────────
    var $filas = $('#dataTables-adop tbody tr');

    $('.adop-filter-btn').on('click', function(){
      $('.adop-filter-btn').removeClass('active');
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
        var $t = $('#adop-toast');
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
            // Recalcular con el grado específico actual (pudo cambiarse después de elegir el libro)
            var suf = this.id.replace('libs_aod', '');
            var partes = val.split('/');
            partes[3] = $('#grado_otrod' + suf).val() || '';
            $(this).val(partes.join('/'));
            var libroId = partes[2];
            if (!libroId || libroId === '0') return;
            // Grado "Otro" (17): el mismo libro se permite en grados específicos distintos
            var key = partes[1] == 17 ? libroId + '|' + partes[3] : libroId;
            if (ids.indexOf(key) !== -1) {
                errMsg = 'Hay libros repetidos en el formulario';
                return false;
            }
            if (librosYaEnAdop.indexOf(key) !== -1) {
                errMsg = 'Uno de los libros ya existe en adopciones';
                return false;
            }
            ids.push(key);
        });
        if (errMsg) {
            e.preventDefault();
            adToast(errMsg, 'error');
            return false;
        }
    });

    // ── Campo de archivo: mostrar nombres seleccionados (máx. 3) ─
    $('#archivo_adopcion').on('change', function() {
        var $label = $('#adop-file-label');
        var $name  = $('#adop-file-name');
        if (this.files && this.files.length > 3) {
            adToast('Máximo 3 archivos para el acuerdo de adopción.', 'error');
            $(this).val('');
            return;
        }
        if (this.files && this.files.length > 0) {
            var nombres = Array.prototype.map.call(this.files, function(f) { return f.name; });
            $('#adop-file-text').text(this.files.length + (this.files.length > 1 ? ' archivos seleccionados' : ' archivo seleccionado'));
            $name.html(nombres.map(function(n) { return $('<div>').text(n).html(); }).join('<br>'));
            $label.addClass('has-file');
        } else {
            $('#adop-file-text').text('Haz clic para seleccionar hasta 3 archivos');
            $name.text('');
            $label.removeClass('has-file');
        }
    });

    // ── Validar archivo antes de guardar adopciones ──────────────
    var requiereAcuerdo = <?= ($gp_periodo["periodo"] >= 2027) ? 'true' : 'false'; ?>;

    // ── Validar archivo antes de guardar adopciones ──────────────
    /*$('#form_definicion').on('submit', function(e) {

        if (!requiereAcuerdo) {
            return true;
        }

        var archivo = $('#archivo_adopcion')[0];
        var tieneNuevo = archivo && archivo.files.length > 0;

        if (!adArchivoGuardado && !tieneNuevo) {
            e.preventDefault();
            adToast('Debes adjuntar el acuerdo de adopción antes de guardar.', 'error');
            $('#adop-file-label').css({'border-color':'#dc2626','background':'#fef2f2'});
            setTimeout(function(){
                $('#adop-file-label').css({'border-color':'','background':''});
            }, 2500);
            return false;
        }
    });*/

</script>

<div class="pr-toast" id="adop-toast">
  <i class="bi bi-check-circle-fill"></i>
  <span class="pr-toast-msg"></span>
</div>

</div><!-- /.adop-wrap -->