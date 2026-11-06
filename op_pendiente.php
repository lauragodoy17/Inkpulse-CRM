<?php
  require_once("php/aut.php");
  require_once("conexion/bdd.php");
  require_once("includes/historial_documento_op.php");
  require_once("includes/documentos_wo_usados.php");
  require_once("includes/backorders_datos.php");
  require_once("includes/excedentes_despacho.php");
  require_once("includes/venta_directa_datos.php");
  vd_asegurar_tablas($bdd); // columnas ordenes_pedidos.id_pedido_vd / consecutivo_vd

  $sql = "SELECT o.id as opid, o.op_per,o.fecha, o.n_doc, o.solicitante, o.valor, o.guia,
                 o.fecha_entrega, o.archivo, o.observaciones, o.estado, o.transportista,
                 o.obs_envio, o.guia, o.adjunto_envio, o.ciudad_destino,
                 o.id_pedido, o.id_pedido_dist, o.id_muestreo, o.id_pedido_vd, o.consecutivo_vd,
                 o.id_devol_c, o.id_devol_p, o.id_devol_v, o.fecha_at, o.usuario_at, o.año,
                 t.id as tid, t.tipo, t.descrip,
                 c.id as cid, c.cliente, c.documento, c.direccion, c.telefonos, c.ciudad,
                 CONCAT(u.nombres,' ',u.apellidos) AS usuario,
                 e.estado AS n_estado
          FROM ordenes_pedidos o
          JOIN tipo_doc t ON o.tipo_doc=t.id
          JOIN clientes c ON c.id=o.cliente
          JOIN usuarios u ON u.id=o.usuario
          JOIN estados_op e ON e.id=o.estado
          WHERE o.id='".$_GET["op"]."'";

  $req = $bdd->prepare($sql); $req->execute();
  $op  = $req->fetch();

  // Asesor (tipo 3), distribuidor (tipo 6) y el usuario id=69 nunca pueden
  // ver ni tocar el módulo de despacho (buscador de documento, historial de
  // documentos, formulario "Registrar despacho" ni los botones Guardar/
  // Atender/Anular) — solo "Información general" y "Observaciones" se les
  // muestran siempre. Lo único del despacho que sí ven es el resumen de
  // solo lectura "Despacho registrado", y únicamente cuando la OP ya quedó
  // atendida (no mientras esté pendiente, aunque ya tenga apartados
  // guardados por otro usuario).
  $tipo_sesion                  = intval($_SESSION['tipo'] ?? 0);
  $id_sesion                    = intval($_SESSION['id'] ?? 0);
  $es_asesor_o_distribuidor     = in_array($tipo_sesion, [3, 6]) || $id_sesion == 69;
  $puede_ver_despacho           = !$es_asesor_o_distribuidor;
  $mostrar_despachos_registrados = !$es_asesor_o_distribuidor || $op['estado'] == 2;

  $estado_cfg = [
    1 => ['label'=>'Pendiente', 'bg'=>'#fef3c7', 'color'=>'#b45309', 'icon'=>'bi-clock'],
    2 => ['label'=>'Atendida',  'bg'=>'#dcfce7', 'color'=>'#15803d', 'icon'=>'bi-check-circle-fill'],
    4 => ['label'=>'Anulada',   'bg'=>'#fee2e2', 'color'=>'#dc2626', 'icon'=>'bi-x-circle-fill'],
  ];
  $es = $estado_cfg[$op['estado']] ?? ['label'=>$op['n_estado'],'bg'=>'#f1f5f9','color'=>'#64748b','icon'=>'bi-circle'];

  /**
   * HTML del documento (factura/remisión) asociado a la OP: buscador de
   * World Office + número de documento + valor despachado. Es ÚNICO por OP
   * (no se repite por apartado) porque una OP se despacha con un solo
   * documento, aunque ese despacho se reparta en varios envíos/guías.
   */
  function wo_documento_html($n_doc = '', $valor = '') {
    ob_start();
  ?>
    <div class="wo-search-box d-print-none">
      <div class="wo-search-head">
        <span class="wo-search-title"><i class="bi bi-search"></i> Buscar documento en World Office</span>
        <button type="button" class="wo-btn wo-btn-auto"><i class="bi bi-magic"></i> Buscar por esta OP</button>
      </div>
      <div class="wo-manual-row">
        <input type="text" class="op-input wo-texto-manual" placeholder="O busca manualmente: colegio, número de documento, otra OP...">
        <button type="button" class="wo-btn wo-btn-outline wo-btn-manual"><i class="bi bi-search"></i> Buscar</button>
      </div>
      <div class="wo-resultados"></div>
    </div>

    <div class="op-form-grid-2">
      <div>
        <p class="op-field-label">Número de documento <span class="op-required">*</span></p>
        <input required type="text" name="n_doc" value="<?= htmlspecialchars($n_doc) ?>" placeholder="Documento del sistema" class="op-input f-n-doc" />
      </div>
      <div>
        <p class="op-field-label">Valor despachado <span class="op-required">*</span></p>
        <input required type="text" name="valor" value="<?= $valor > 0 ? htmlspecialchars($valor) : '' ?>" placeholder="Valor despachado" class="op-input f-valor" />
      </div>
    </div>
  <?php
    return ob_get_clean();
  }

  /**
   * HTML de un "apartado" de envío (entregado a + guía + fecha + soporte).
   * Es la MISMA función la que imprime el primer apartado visible y la que
   * llena la <template> que JS clona al hacer "Añadir otro apartado" — una
   * sola fuente, para que nunca se desincronicen. Los campos usan
   * name="campo[]" (array) porque php/procesar_op.php guarda un registro en
   * op_atendidas por cada apartado enviado (todos con el mismo documento y
   * valor, que se llenan arriba una sola vez).
   */
  function wo_apartado_html() {
    ob_start();
  ?>
    <div class="despacho-apartado">
      <div class="despacho-apartado-head">
        <span class="despacho-apartado-titulo"><i class="bi bi-box-seam"></i> Apartado <span class="apartado-num">1</span></span>
        <button type="button" class="wo-btn-quitar-apartado d-print-none" style="display:none;"><i class="bi bi-trash"></i> Quitar</button>
      </div>

      <div class="op-form-grid-3">
        <div>
          <p class="op-field-label">Entregado a <span class="op-required">*</span></p>
          <input required type="text" name="transportista[]" placeholder="Entregado a" class="op-input" />
        </div>
        <div>
          <p class="op-field-label">Guía</p>
          <input type="text" name="guia[]" placeholder="Guía" class="op-input" />
        </div>
        <div>
          <p class="op-field-label">Fecha despacho <span class="op-required">*</span></p>
          <input required type="text" data-date-format="yyyy-mm-dd" name="fecha_entrega[]" placeholder="Fecha despacho" class="op-input date-picker" />
        </div>
      </div>

      <div style="margin-bottom:16px">
        <p class="op-field-label">Observaciones de despacho</p>
        <textarea name="obs_envio[]" class="op-textarea" placeholder="Observaciones del despacho..."></textarea>
      </div>

      <div>
        <p class="op-field-label">Soporte de entrega <span class="op-required">*</span></p>
        <input type="file" name="adjunto_envio[]" class="op-input" required />
      </div>
    </div>
  <?php
    return ob_get_clean();
  }
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <title>Inkpulse - <?= $op['estado']!=2 ? 'OP pendiente' : 'OP atendida' ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />

    <style>
      *, *::before, *::after { box-sizing: border-box; }

      /* ── OP Header card ─────────────────────────────────────── */
      .op-hdr {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 14px; padding: 20px 24px;
      }
      .op-hdr-left  { display: flex; align-items: center; gap: 16px; }
      .op-hdr-icon  {
        width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
        background: linear-gradient(135deg,#4361ee,#6d28d9);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; color: #fff;
      }
      .op-hdr-title { font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
      .op-hdr-meta  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
      .op-estado-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 12px; border-radius: 20px; font-size: .78rem; font-weight: 700;
        background: <?= $es['bg'] ?>; color: <?= $es['color'] ?>;
      }
      .op-fecha-tag { font-size: .78rem; color: #64748b; }
      .op-hdr-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
      .op-usuario-tag {
        display: flex; align-items: center; gap: 6px;
        font-size: .8rem; font-weight: 600; color: #374151;
        background: #f1f5f9; padding: 5px 12px; border-radius: 8px;
      }
      .op-btn-print {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 8px; font-size: .78rem; font-weight: 600;
        background: #0d9488; color: #fff; border: none;
        text-decoration: none; transition: opacity .15s;
      }
      .op-btn-print:hover { opacity: .88; text-decoration: none; color: #fff; }

      /* ── Section cards ──────────────────────────────────────── */
      .op-card { background: #fff; border-radius: 14px; overflow: hidden; margin-bottom: 18px;
                 box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04); }
      .op-card-head {
        padding: 14px 20px; border-bottom: 2px solid #c7d2fe;
        font-size: .88rem; font-weight: 700; color: #1e3a8a;
        display: flex; align-items: center; gap: 8px;
        background: #eef2ff; border-left: 4px solid #4361ee;
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
      }
      .op-card-head i { font-size: 1rem; color: #4361ee; }
      .op-card-body { padding: 20px 24px; }

      /* ── Info grids ─────────────────────────────────────────── */
      .op-info-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
      .op-info-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
      @media (max-width: 860px) { .op-info-4 { grid-template-columns: 1fr 1fr; } }
      @media (max-width: 540px) { .op-info-2, .op-info-4 { grid-template-columns: 1fr; } }

      .op-field-label {
        font-size: .68rem; color: #1e40af; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px;
      }
      .op-val { font-size: .875rem; font-weight: 500; color: #0f172a; line-height: 1.4; word-break: break-word; }
      .op-val.muted { color: #94a3b8; }
      .op-val a { color: #4361ee; font-weight: 600; text-decoration: none; }
      .op-val a:hover { text-decoration: underline; }
      .op-obs-text { font-size: .875rem; color: #374151; line-height: 1.6; white-space: pre-wrap; }

      /* ── Form fields ────────────────────────────────────────── */
      .op-form-grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 16px; }
      @media (max-width: 900px) { .op-form-grid-5 { grid-template-columns: 1fr 1fr 1fr; } }
      @media (max-width: 560px) { .op-form-grid-5 { grid-template-columns: 1fr 1fr; } }
      .op-form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px; }
      @media (max-width: 700px) { .op-form-grid-3 { grid-template-columns: 1fr 1fr; } }
      @media (max-width: 480px) { .op-form-grid-3 { grid-template-columns: 1fr; } }
      .op-form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
      @media (max-width: 560px) { .op-form-grid-2 { grid-template-columns: 1fr; } }
      .f-n-doc[readonly], .f-valor[readonly] { background: #f1f5f9; color: #334155; cursor: not-allowed; }

      .op-input, .op-select, .op-textarea {
        width: 100%; padding: 8px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px;
        font-size: .875rem; font-family: 'Inter', sans-serif; color: #0f172a;
        background: #fff; transition: border-color .2s; box-sizing: border-box;
      }
      .op-input:focus, .op-select:focus, .op-textarea:focus {
        outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.08);
      }
      .op-input[type=file] { padding: 6px 10px; cursor: pointer; }
      .op-textarea { resize: vertical; min-height: 90px; }

      .op-required { color: #ef4444; font-size: .75rem; margin-left: 2px; }

      /* Select2 override to match op-input */
      #cliente + .select2-container .select2-selection--single {
        height: auto !important; border: 1.5px solid #e2e8f0 !important;
        border-radius: 8px !important; padding: 7px 12px !important;
        font-size: .875rem; font-family: 'Inter', sans-serif; color: #0f172a;
      }
      #cliente + .select2-container .select2-selection__arrow { top: 7px !important; }
      #cliente + .select2-container--focus .select2-selection--single {
        border-color: #4361ee !important; box-shadow: 0 0 0 3px rgba(67,97,238,.08) !important;
      }
      #tipo_doc { max-width: 100%; }

      /* ── Despacho info rows ──────────────────────────────────── */
      .op-desp-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 12px; }
      @media (max-width: 860px) { .op-desp-grid { grid-template-columns: 1fr 1fr 1fr; } }
      @media (max-width: 560px) { .op-desp-grid { grid-template-columns: 1fr 1fr; } }

      /* ── Buscar documento en World Office ───────────────────── */
      .wo-search-box { background: #f8fafc; border: 1.5px dashed #c7d2fe; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
      .wo-search-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
      .wo-search-title { font-size: .82rem; font-weight: 700; color: #1e3a8a; display: flex; align-items: center; gap: 7px; }
      .wo-btn {
        display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px;
        font-size: .8rem; font-weight: 700; border: none; cursor: pointer;
        background: #4361ee; color: #fff; transition: opacity .15s; white-space: nowrap;
      }
      .wo-btn:hover { opacity: .88; }
      .wo-btn:disabled { opacity: .5; cursor: not-allowed; }
      .wo-btn-outline { background: #fff; color: #4361ee; border: 1.5px solid #4361ee; }
      .wo-manual-row { display: flex; gap: 8px; margin-top: 10px; }
      .wo-manual-row input { flex: 1; }
      .wo-results { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
      .wo-result-card {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 10px 14px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 9px; font-size: .8rem;
      }
      .wo-result-info { flex: 1; min-width: 0; }
      .wo-result-doc { font-weight: 700; color: #0f172a; }
      .wo-result-tipo { display: inline-block; font-size: .65rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 6px; vertical-align: middle; }
      .wo-result-tipo-FV  { background: #dbeafe; color: #1d4ed8; }
      .wo-result-tipo-REM { background: #fef3c7; color: #b45309; }
      .wo-result-meta { color: #64748b; font-size: .72rem; margin-top: 2px; }
      .wo-result-concepto {
        color: #94a3b8; font-size: .7rem; margin-top: 3px; max-width: 520px;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      }
      .wo-result-valor { font-weight: 700; color: #15803d; white-space: nowrap; }
      .wo-empty, .wo-error, .wo-loading { font-size: .8rem; color: #94a3b8; padding: 8px 2px; }
      .wo-error { color: #dc2626; }

      /* ── Historial de documentos ─────────────────────────────── */
      .hist-doc-table { width: 100%; font-size: .8rem; border-collapse: collapse; }
      .hist-doc-table th {
        background: #f8fafc; font-weight: 700; font-size: .68rem; text-transform: uppercase;
        letter-spacing: .04em; color: #64748b; padding: 10px 20px; border-bottom: 2px solid #e2e8f0;
        text-align: left; white-space: nowrap;
      }
      .hist-doc-table td { padding: 10px 20px; border-bottom: 1px solid #f1f5f9; color: #334155; }
      .hist-doc-table tr:last-child td { border-bottom: none; }
      .hist-doc-acciones { white-space: nowrap; }
      .hist-doc-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; border-radius: 7px; border: 1.5px solid #e2e8f0;
        background: #fff; color: #64748b; cursor: pointer; margin-right: 6px; font-size: .8rem;
        transition: background .15s, color .15s, border-color .15s;
      }
      .hist-doc-btn:last-child { margin-right: 0; }
      .hist-doc-btn:hover { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
      .hist-doc-btn-borrar:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
      .hist-doc-usado {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: .72rem; font-weight: 700; color: #94a3b8;
      }

      /* ── Apartados de despacho repetibles ────────────────────── */
      .despacho-apartado {
        border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;
        background: #fdfdff;
      }
      .despacho-apartado-head {
        display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;
      }
      .despacho-apartado-titulo {
        font-size: .8rem; font-weight: 700; color: #4361ee; text-transform: uppercase; letter-spacing: .04em;
        display: flex; align-items: center; gap: 7px;
      }
      .wo-btn-quitar-apartado {
        display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px;
        font-size: .76rem; font-weight: 700; border: 1.5px solid #fecaca; background: #fff;
        color: #dc2626; cursor: pointer; transition: background .15s;
      }
      .wo-btn-quitar-apartado:hover { background: #fef2f2; }

      /* ── Action buttons ──────────────────────────────────────── */
      .op-actions { display: flex; justify-content: center; gap: 14px; margin-top: 6px; flex-wrap: wrap; }
      .op-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 30px; border-radius: 10px; font-size: .9rem; font-weight: 700;
        border: none; cursor: pointer; text-decoration: none; transition: opacity .15s;
      }
      .op-btn:hover { opacity: .88; text-decoration: none; }
      .op-btn-atender { background: linear-gradient(135deg,#22c55e,#16a34a); color: #fff; }
      .op-btn-anular  { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; }
      .op-btn-guardar { background: linear-gradient(135deg,#3b82f6,#2563eb); color: #fff; }

      input[type=number] { -moz-appearance:textfield; }
      input[type=number]::-webkit-inner-spin-button,
      input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
      .exc-modal { width: 640px !important; }
      .exc-modal .exc-texto { font-size: .85rem; color: #475569; margin: 0 0 10px; text-align: center; }
      .exc-modal .exc-nota { font-size: .78rem; color: #92400e; margin: 8px 0 0; }
      .exc-modal .table-responsive { margin-bottom: 12px; }
    </style>
    <?= excedentes_estilos() ?>
  </head>
  <body>
    <?php include("template/nav_side.php"); ?>
    <div class="main-container">
      <div class="pd-ltr-20 xs-pd-20-10">
        <div class="min-height-200px">

          <!-- Breadcrumb -->
          <div class="page-header">
            <div class="row">
              <div class="col-md-6 col-sm-12">
                <div class="title">
                  <h4><?= $op['estado']!=2 ? 'OP pendiente' : 'OP atendida' ?></h4>
                </div>
                <nav aria-label="breadcrumb" role="navigation">
                  <ol class="breadcrumb">
                    <?php
                      $tp_back = [1=>2, 2=>3, 4=>4];
                      $tp_link = $tp_back[$op['estado']] ?? 1;
                    ?>
                    <li class="breadcrumb-item"><a href="lista_op.php?tp=<?= $tp_link ?>">OP</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                      <?= $es['label'] ?>
                    </li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>

          <!-- OP Header -->
          <div class="op-card">
            <div class="op-hdr">
              <div class="op-hdr-left">
                <div class="op-hdr-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div>
                  <h2 class="op-hdr-title"><?php if ((int)$op['id_pedido_vd'] > 0): /* OP de venta directa: numeración propia */ ?><?= htmlspecialchars(vd_etiqueta_op($op['consecutivo_vd'])) ?> <span style="font-size:.7rem;font-weight:700;background:#ccfbf1;color:#0f766e;border-radius:20px;padding:2px 9px;vertical-align:middle;">OP de venta directa</span><?php else: ?>OP # <?= htmlspecialchars($op['año'].' - '.$op['opid']) ?><?php endif; ?></h2>
                  <div class="op-hdr-meta">
                    <span class="op-estado-badge">
                      <i class="bi <?= $es['icon'] ?>"></i> <?= $es['label'] ?>
                    </span>
                    <span class="op-fecha-tag"><i class="bi bi-calendar3"></i> Fecha de creación: <?= htmlspecialchars($op['fecha']) ?></span>
                  </div>
                </div>
              </div>
              <div class="op-hdr-right">
                <div class="op-usuario-tag">
                  <i class="bi bi-person-circle"></i> <?= htmlspecialchars($op['usuario']) ?>
                </div>
                <a href="formato_op.php?op=<?= $op['opid'] ?>" target="_blank" class="op-btn-print">
                  <i class="bi bi-printer"></i> Imprimir
                </a>
              </div>
            </div>
          </div>

          <form action="php/procesar_op.php" method="POST" enctype="multipart/form-data">

          <!-- Información general -->
          <div class="op-card">
            <div class="op-card-head"><i class="bi bi-info-circle"></i> Información general</div>
            <div class="op-card-body">
              <div class="op-info-2">
                <!-- Tipo de documento -->
                <div>
                  <p class="op-field-label">Tipo de documento</p>
                  <?php if ($op['estado'] == 4): ?>
                    <p class="op-val"><?= htmlspecialchars($op['tipo']) ?></p>
                  <?php else: ?>
                    <select name="tipo_doc" id="tipo_doc" class="op-select">
                      <?php
                        $r = $bdd->prepare("SELECT id, tipo FROM tipo_doc");
                        $r->execute(); $tipos = $r->fetchAll();
                        foreach ($tipos as $tipo) {
                          $sel = ($op['tid']==$tipo['id']) ? 'selected' : '';
                          echo "<option value='{$tipo['id']}' $sel>{$tipo['tipo']}</option>";
                        }
                      ?>
                    </select>
                  <?php endif; ?>
                </div>

                <!-- Cliente -->
                <div>
                  <p class="op-field-label">Cliente</p>
                  <?php if ($op['estado'] == 4): ?>
                    <p class="op-val"><?= htmlspecialchars($op['cliente']) ?></p>
                  <?php else: ?>
                    <select name="cliente" id="cliente">
                      <?php
                        $r = $bdd->prepare("SELECT id, cliente FROM clientes");
                        $r->execute(); $clientes = $r->fetchAll();
                        foreach ($clientes as $cli) {
                          $sel = ($op['cid']==$cli['id']) ? 'selected' : '';
                          echo "<option value='{$cli['id']}' $sel>{$cli['cliente']}</option>";
                        }
                      ?>
                    </select>
                  <?php endif; ?>
                </div>
              </div>

              <div class="op-info-4">
                <!-- Contacto -->
                <div>
                  <p class="op-field-label">Contacto</p>
                  <p class="op-val"><?= !empty($op['solicitante']) ? htmlspecialchars($op['solicitante']) : '<span class="muted">—</span>' ?></p>
                </div>

                <!-- Ciudad destino -->
                <div>
                  <p class="op-field-label">Ciudad destino</p>
                  <p class="op-val"><?= !empty($op['ciudad_destino']) ? htmlspecialchars($op['ciudad_destino']) : '<span class="muted">—</span>' ?></p>
                </div>

                <!-- Archivo adjunto (solo cuando no hay pedido de venta) -->
                <?php if ($op['id_pedido'] == 0): ?>
                <div>
                  <p class="op-field-label">Archivo adjunto</p>
                  <p class="op-val">
                    <?php if (!empty($op['archivo'])): ?>
                      <a href="adjuntos/<?= htmlspecialchars($op['archivo']) ?>" target="_blank">
                        <i class="bi bi-paperclip"></i> <?= htmlspecialchars($op['archivo']) ?>
                      </a>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </p>
                </div>
                <?php endif; ?>

                <!-- Fuente / origen -->
                <?php
                  if ($op['id_pedido'] != 0) {
                    // El pedido directo (id_pedido) puede tener además otros pedidos ligados a
                    // esta misma OP por op_pedidos_agrupados (p. ej. un pedido generado para
                    // atender lo pendiente del original) — se muestran todos juntos aquí.
                    $idsPedidoVenta = [(int)$op['id_pedido']];
                    $r_pv_ag = $bdd->prepare("SELECT id_pedido FROM op_pedidos_agrupados WHERE op = ?");
                    $r_pv_ag->execute([$op['opid']]);
                    foreach ($r_pv_ag->fetchAll() as $pv_ag) {
                      $idpv_ag = (int)$pv_ag['id_pedido'];
                      if ($idpv_ag != 0 && !in_array($idpv_ag, $idsPedidoVenta, true)) $idsPedidoVenta[] = $idpv_ag;
                    }
                    $links_pv = [];
                    foreach ($idsPedidoVenta as $idpv) {
                      $r = $bdd->prepare("SELECT estado FROM pedidos WHERE id = ?");
                      $r->execute([$idpv]); $pv = $r->fetch();
                      $tp = ($pv && $pv['estado']==2) ? 3 : 4;
                      $links_pv[] = '<a href="pedido_colegio.php?id_pedido='.$idpv.'&tp='.$tp.'" target="_blank">#'.$idpv.'</a>';
                    }
                    echo '<div><p class="op-field-label">Pedido de venta</p><p class="op-val">'.implode(', ', $links_pv).'</p></div>';
                  }
                  if ($op['id_pedido_vd'] != 0) {
                    echo '<div><p class="op-field-label">Venta directa</p><p class="op-val">'.vd_numero($op['id_pedido_vd']).'</p></div>';
                  }
                  if ($op['id_pedido_dist'] != 0) {
                    $r = $bdd->prepare("SELECT estado FROM pedidos2 WHERE id='".$op['id_pedido_dist']."'");
                    $r->execute(); $pd = $r->fetch();
                    $tp = ($pd['estado']==2) ? 3 : 4;
                    echo '<div><p class="op-field-label">Pedido sin adopción</p><p class="op-val"><a href="pedido_colegio_sa.php?id_pedido='.$op['id_pedido_dist'].'&tp='.$tp.'" target="_blank">#'.$op['id_pedido_dist'].'</a></p></div>';
                  }
                  if ($op['id_muestreo'] != 0) {
                    $r = $bdd->prepare("SELECT estado FROM muestreos WHERE id='".$op['id_muestreo']."'");
                    $r->execute(); $ms = $r->fetch();
                    $tp = ($ms['estado']==2) ? 3 : 4;
                    echo '<div><p class="op-field-label">Pedido de muestras</p><p class="op-val"><a href="muestreo_colegio_resto.php?id_pedido='.$op['id_muestreo'].'&tp='.$tp.'" target="_blank">#'.$op['id_muestreo'].'</a></p></div>';
                  }
                  if ($op['id_devol_c'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de muestra</p><p class="op-val"><a href="vista_devol.php?id_devol='.$op['id_devol_c'].'&tipo=1" target="_blank">#'.$op['id_devol_c'].'</a></p></div>';
                  }
                  if ($op['id_devol_p'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de proveedor</p><p class="op-val"><a href="vista_devol.php?id_devol='.$op['id_devol_p'].'&tipo=2" target="_blank">#'.$op['id_devol_p'].'</a></p></div>';
                  }
                  if ($op['id_devol_v'] != 0) {
                    echo '<div><p class="op-field-label">Devolución de venta</p><p class="op-val"><a href="devolucion_colegio.php?id_pedido='.$op['id_devol_v'].'" target="_blank">#'.$op['id_devol_v'].'</a></p></div>';
                  }
                  if ($op['id_pedido']==0 && $op['id_pedido_dist']==0 && $op['id_muestreo']==0 && $op['id_pedido_vd']==0
                    && $op['id_devol_c']==0 && $op['id_devol_p']==0 && $op['id_devol_v']==0) {
                    $r_ag = $bdd->prepare("SELECT id_pedido FROM op_pedidos_agrupados WHERE op='".$op['opid']."'");
                    $r_ag->execute(); $agps = $r_ag->fetchAll();
                    if (!empty($agps)) {
                      echo '<div><p class="op-field-label">Pedidos agrupados</p><p class="op-val">';
                      foreach ($agps as $agp) {
                        echo '<a href="pedido_colegio_aprobado.php?id_pedido='.$agp['id_pedido'].'" target="_blank">#'.$agp['id_pedido'].'</a> ';
                      }
                      echo '</p></div>';
                    }
                  }
                ?>

                <!-- Si estado==2: mostrar quien y cuando atendió -->
                <?php if ($op['estado'] == 2):
                  $r = $bdd->prepare("SELECT CONCAT(nombres,' ',apellidos) AS usr_aten FROM usuarios WHERE id='".$op['usuario_at']."'");
                  $r->execute(); $aten = $r->fetch();
                ?>
                <div>
                  <p class="op-field-label">Fecha de atención</p>
                  <p class="op-val"><?= htmlspecialchars($op['fecha_at']) ?></p>
                </div>
                <div>
                  <p class="op-field-label">Atendido por</p>
                  <p class="op-val"><?= htmlspecialchars($aten['usr_aten'] ?? '—') ?></p>
                </div>
                <?php endif; ?>

              </div>
            </div>
          </div>

          <?php
            // Pedidos relacionados con esta OP: cada pedido original con lo solicitado, despachado,
            // atendido con pedidos nuevos (backorder) y pendiente, más el historial de pedidos nuevos
            // generados para atenderlo — ver obtener_pedidos_relacionados_op().
            $pedidos_rel_op = obtener_pedidos_relacionados_op($bdd, $op['opid']);
            $tp_por_estado  = [1 => 2, 2 => 3, 3 => 5, 4 => 4, 5 => 6, 6 => 7, 7 => 8];
            $fmt_cant = function ($v) { $v = (float)$v; return floor($v) == $v ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.'); };
          ?>
          <?php if (!empty($pedidos_rel_op)): ?>
          <div class="op-card" id="pedidos-relacionados">
            <div class="op-card-head"><i class="bi bi-diagram-3"></i> Pedidos relacionados</div>
            <div class="op-card-body">
              <style>
                .oprel-pedido { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
                .oprel-pedido:last-child { margin-bottom: 0; }
                .oprel-tit { display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: baseline; margin-bottom: 10px; font-size: .85rem; color: #475569; }
                .oprel-tit strong { font-size: .95rem; color: #0f172a; }
                .oprel-resumen { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
                .oprel-chip { font-size: .75rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; }
                table.oprel-tabla { width: 100%; border-collapse: collapse; font-size: .8rem; margin-bottom: 10px; }
                table.oprel-tabla th, table.oprel-tabla td { border: 1px solid #e2e8f0; padding: 6px 10px; }
                table.oprel-tabla thead th { background: #f1f5f9; color: #334155; font-weight: 700; }
                table.oprel-tabla .num { text-align: center; font-variant-numeric: tabular-nums; }
                table.oprel-tabla tr.anulado td { color: #94a3b8; text-decoration: line-through; }
                table.oprel-tabla tr.anulado td.estado { text-decoration: none; }
                .oprel-sub { font-size: .75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; margin: 6px 0; }
              </style>
              <?php foreach ($pedidos_rel_op as $pr): ?>
              <div class="oprel-pedido">
                <div class="oprel-tit">
                  <strong>Pedido original
                    <a href="pedido_colegio.php?id_pedido=<?= $pr['id_pedido'] ?>&tp=<?= $tp_por_estado[$pr['estado_id']] ?? 2 ?>" target="_blank">#<?= $pr['id_pedido'] ?></a>
                  </strong>
                  <span><?= htmlspecialchars($pr['colegio']) ?><?= $pr['cliente'] !== '' ? ' — ' . htmlspecialchars($pr['cliente']) : '' ?></span>
                  <span>Fecha: <?= htmlspecialchars($pr['fecha']) ?></span>
                  <span>Estado: <?= htmlspecialchars($pr['estado'] ?: '—') ?></span>
                  <a href="reporte_backorders_pedido.php?id_pedido=<?= $pr['id_pedido'] ?>" target="_blank" style="font-size:.78rem;">Ver backorder <i class="bi bi-box-arrow-up-right"></i></a>
                </div>

                <?php if ($pr['esPreMigracion']): ?>
                  <p class="op-val muted" style="margin-bottom:10px;">Pedido con una OP atendida antes de la migración a World Office (01/07/2026): no hay traza confiable de lo despachado.</p>
                <?php else: ?>
                  <div class="oprel-resumen">
                    <span class="oprel-chip" style="background:#eff6ff; color:#1d4ed8;">Solicitado: <?= $fmt_cant($pr['total_solicitada']) ?></span>
                    <span class="oprel-chip" style="background:#f0fdf4; color:#16a34a;">Despachado: <?= $fmt_cant($pr['total_despachada']) ?></span>
                    <span class="oprel-chip" style="background:#f5f3ff; color:#6d28d9;">En pedidos nuevos: <?= $fmt_cant($pr['total_generada']) ?></span>
                    <span class="oprel-chip" style="background:#fffbeb; color:#b45309;">Pendiente: <?= $fmt_cant($pr['total_pendiente']) ?></span>
                  </div>
                  <?php if (!empty($pr['items'])): ?>
                  <div class="table-responsive">
                    <table class="oprel-tabla">
                      <thead>
                        <tr>
                          <th>Título</th>
                          <th class="num">Solicitado originalmente</th>
                          <th class="num">Despachado</th>
                          <th class="num">En pedidos nuevos</th>
                          <th class="num">Pendiente</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($pr['items'] as $it): ?>
                        <tr>
                          <td><?= htmlspecialchars($it['libro']) ?></td>
                          <td class="num"><?= $fmt_cant($it['solicitada']) ?></td>
                          <td class="num"><?= $fmt_cant($it['despachada']) ?></td>
                          <td class="num"><?= $fmt_cant($it['generada']) ?></td>
                          <td class="num"><?= $fmt_cant($it['pendiente']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?php endif; ?>
                <?php endif; ?>

                <p class="oprel-sub">Pedidos nuevos generados para atender el backorder</p>
                <?php if (empty($pr['historial'])): ?>
                  <p class="op-val muted" style="margin:0;">Ninguno.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="oprel-tabla" style="margin-bottom:0;">
                      <thead>
                        <tr>
                          <th>Pedido nuevo</th>
                          <th>Fecha de generación</th>
                          <th>Estado</th>
                          <th>OP</th>
                          <th>Generado por</th>
                          <th>Libros y cantidades</th>
                          <th class="num">Total</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($pr['historial'] as $g): ?>
                        <tr class="<?= $g['anulado'] ? 'anulado' : '' ?>">
                          <td><a href="pedido_colegio.php?id_pedido=<?= $g['id_pedido_nuevo'] ?>&tp=<?= $tp_por_estado[$g['estado_id']] ?? 2 ?>" target="_blank">#<?= $g['id_pedido_nuevo'] ?></a></td>
                          <td><?= htmlspecialchars($g['fecha']) ?></td>
                          <td class="estado"><?= htmlspecialchars($g['estado'] ?: '—') ?></td>
                          <td>
                            <?php if ($g['id_op']): ?>
                              <?= (int)$g['id_op'] === (int)$op['opid'] ? '#' . (int)$g['id_op'] . ' (esta)' : '<a href="op_pendiente.php?op=' . (int)$g['id_op'] . '#pedidos-relacionados">#' . (int)$g['id_op'] . '</a>' ?>
                            <?php else: ?>—<?php endif; ?>
                          </td>
                          <td><?= htmlspecialchars($g['usuario']) ?></td>
                          <td>
                            <?php foreach ($g['libros'] as $gl): ?>
                              <div><?= htmlspecialchars($gl['libro']) ?>: <strong><?= $fmt_cant($gl['cantidad']) ?></strong></div>
                            <?php endforeach; ?>
                          </td>
                          <td class="num"><?= $fmt_cant($g['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php
            // Trazabilidad: documentos de esta OP confirmados con unidades de más frente a lo
            // solicitado (ver includes/excedentes_despacho.php).
            $excedentes_op = excedentes_listar($bdd, null, $op['opid']);
            if (!empty($excedentes_op)) echo excedentes_html($excedentes_op, false);
          ?>

          <!-- Observaciones -->
          <div class="op-card">
            <div class="op-card-head"><i class="bi bi-chat-text"></i> Observaciones</div>
            <div class="op-card-body">
              <?php if (!empty($op['observaciones'])): ?>
                <p class="op-obs-text"><?= htmlspecialchars($op['observaciones']) ?></p>
              <?php else: ?>
                <p class="op-val muted">Sin observaciones</p>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($es_asesor_o_distribuidor && $op['estado'] != 2 && $op['estado'] != 4): ?>
          <div class="op-card">
            <div class="op-card-body" style="text-align:center; color:#94a3b8; padding:28px 20px;">
              <i class="bi bi-hourglass-split" style="font-size:1.6rem; display:block; margin-bottom:8px;"></i>
              La información del despacho estará disponible aquí cuando esta OP quede atendida.
            </div>
          </div>
          <?php endif; ?>

          <?php if ($op['estado'] != 4 && $puede_ver_despacho):
            // El historial se consulta antes de pintar "Documento del
            // despacho" para poder precargar ahí el último documento
            // guardado de ESTA OP — si no, "Registrar despacho"/"Atender"
            // fallan pidiendo buscar un documento que en realidad ya se
            // había guardado antes.
            crear_tabla_historial_documento_op($bdd);
            crear_tabla_documentos_wo_usados($bdd);
            $hist_doc = obtener_historial_documento_op($bdd, $op['opid']);
            $doc_actual_n_doc = $hist_doc[0]['n_doc'] ?? $op['n_doc'];
            $doc_actual_valor = $hist_doc[0]['valor'] ?? $op['valor'];
            $doc_actual_id_wo = $hist_doc[0]['id_wo'] ?? null;

            // Documentos del historial que ya quedaron consumidos por un
            // despacho real: esos registros no se pueden editar ni borrar
            // (perderían el rastro de qué documento se usó para atender).
            $hist_doc_usados = [];
            $ids_wo_hist = array_filter(array_column($hist_doc, 'id_wo'));
            if (!empty($ids_wo_hist)) {
              $placeholders = implode(',', array_fill(0, count($ids_wo_hist), '?'));
              $r_usados = $bdd->prepare("SELECT id_wo FROM documentos_wo_usados WHERE id_wo IN ($placeholders)");
              $r_usados->execute(array_values($ids_wo_hist));
              $hist_doc_usados = array_flip($r_usados->fetchAll(PDO::FETCH_COLUMN));
            }
          ?>
          <!-- Documento del despacho (World Office) -->
          <div class="op-card">
            <div class="op-card-head"><i class="bi bi-file-earmark-text"></i> Documento del despacho</div>
            <div class="op-card-body">
              <div id="despacho-documento" data-op="<?= $op['opid'] ?>">
                <?= wo_documento_html($doc_actual_n_doc, $doc_actual_valor) ?>
                <!-- id del documento en World Office: viaja con el formulario
                     de despacho para que php/procesar_op.php pueda impedir
                     "Atender" con un documento que ya se usó para atender
                     otra OP. -->
                <input type="hidden" name="id_wo" id="despacho-id-wo" value="<?= (int)($doc_actual_id_wo ?? 0) ?>">
              </div>
            </div>
          </div>

          <!-- Historial de documentos: cada vez que se guarda un documento
               distinto al que tenía la OP queda un registro aquí, aunque el
               documento actual (arriba) se siga pudiendo reemplazar. Se
               guarda apenas se elige un candidato en el buscador de World
               Office (no hace falta enviar todo el formulario de despacho),
               así que el <tbody> lo llena JS al vuelo sin recargar. -->
          <div class="op-card" id="historial-documento-op">
            <div class="op-card-head"><i class="bi bi-clock-history"></i> Historial de documentos</div>
            <div class="op-card-body" style="padding:0;">
              <p class="wo-empty hist-doc-empty" style="padding:14px 20px;<?= !empty($hist_doc) ? 'display:none;' : '' ?>">
                <i class="bi bi-info-circle"></i> Aún no hay documentos guardados para esta OP.
              </p>
              <table class="hist-doc-table" style="<?= empty($hist_doc) ? 'display:none;' : '' ?>">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Documento</th>
                    <th>Valor</th>
                    <th class="d-print-none">Acciones</th>
                  </tr>
                </thead>
                <tbody class="hist-doc-tbody">
                  <?php foreach ($hist_doc as $h): $es_usado = !empty($h['id_wo']) && isset($hist_doc_usados[$h['id_wo']]); ?>
                  <tr data-id="<?= $h['id'] ?>" data-tipo-doc="<?= (int)$h['tipo_doc'] ?>" data-n-doc="<?= htmlspecialchars($h['n_doc'], ENT_QUOTES) ?>" data-id-wo="<?= (int)$h['id_wo'] ?>" data-valor="<?= htmlspecialchars($h['valor'], ENT_QUOTES) ?>" data-usado="<?= $es_usado ? '1' : '0' ?>">
                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($h['fecha']))) ?></td>
                    <td><?= htmlspecialchars($h['usuario']) ?></td>
                    <td><?= htmlspecialchars($h['tipo'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($h['n_doc']) ?></td>
                    <td><?= $h['valor'] !== null ? '$' . number_format($h['valor'], 0, ',', '.') : '—' ?></td>
                    <td class="d-print-none hist-doc-acciones">
                      <?php if (!empty($h['id_wo'])): ?>
                      <button type="button" class="hist-doc-btn hist-doc-btn-ver" title="Ver ítems"><i class="bi bi-eye"></i></button>
                      <?php endif; ?>
                      <?php if ($es_usado): ?>
                      <span class="hist-doc-usado" title="Este documento ya quedó asociado a un despacho. Para cambiarlo usa el lápiz del despacho registrado; al borrar todos sus apartados queda libre."><i class="bi bi-lock-fill"></i> En uso</span>
                      <?php else: ?>
                      <button type="button" class="hist-doc-btn hist-doc-btn-editar" title="Editar"><i class="bi bi-pencil"></i></button>
                      <button type="button" class="hist-doc-btn hist-doc-btn-borrar" title="Borrar"><i class="bi bi-trash"></i></button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($op['estado'] != 4):
            // TODOS los despachos de la OP (una OP puede despacharse varias veces,
            // cada despacho con su propio documento de World Office): se agrupan
            // por documento y se numeran en el orden en que se registraron. El
            // "vigente" es el del documento actual de la OP (ordenes_pedidos.n_doc).
            $r_at = $bdd->prepare("SELECT id, transportista, n_doc, guia, fecha_entrega, valor, obs_envio, adjunto_envio, fecha_at FROM op_atendidas WHERE opid = ? ORDER BY id");
            $r_at->execute([$op['opid']]);
            $ats = $r_at->fetchAll();
            $despachos_op = []; // n_doc => [apartados]
            foreach ($ats as $at_row) $despachos_op[$at_row['n_doc']][] = $at_row;
          ?>

            <?php if ($mostrar_despachos_registrados && !empty($ats)):
              $num_despacho = 0;
              foreach ($despachos_op as $doc_despacho => $apartados_despacho):
                $num_despacho++;
                $es_vigente = (string)$doc_despacho === (string)$op['n_doc'];
                foreach ($apartados_despacho as $idx_apartado => $at): ?>
            <!-- Despacho registrado -->
            <div class="op-card">
              <div class="op-card-head" style="color:#15803d; justify-content:space-between;">
                <span>
                  <i class="bi bi-check-circle-fill" style="color:#15803d"></i>
                  Despacho <?= count($despachos_op) > 1 ? $num_despacho . ' ' : '' ?>registrado
                  <span style="font-weight:600; color:#475569;">· Documento <?= htmlspecialchars($doc_despacho) ?></span>
                  <?php if (count($apartados_despacho) > 1): ?>
                    <span style="font-weight:600; color:#64748b;">· Apartado <?= $idx_apartado + 1 ?> de <?= count($apartados_despacho) ?></span>
                  <?php endif; ?>
                  <?php if (count($despachos_op) > 1 && $es_vigente): ?>
                    <span style="font-size:.7rem; font-weight:700; background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:20px; margin-left:4px;">Documento actual</span>
                  <?php endif; ?>
                </span>
                <?php if ($puede_ver_despacho): ?>
                <span class="d-print-none" style="display:flex; gap:6px;">
                  <button type="button" class="hist-doc-btn despacho-btn-editar" title="Editar despacho (documento y datos)"
                          data-id="<?= (int)$at['id'] ?>"
                          data-n-doc="<?= htmlspecialchars($at['n_doc'], ENT_QUOTES) ?>"
                          data-valor="<?= htmlspecialchars($at['valor'], ENT_QUOTES) ?>"
                          data-transportista="<?= htmlspecialchars($at['transportista'], ENT_QUOTES) ?>"
                          data-guia="<?= htmlspecialchars($at['guia'], ENT_QUOTES) ?>"
                          data-fecha="<?= htmlspecialchars($at['fecha_entrega'], ENT_QUOTES) ?>"
                          data-obs="<?= htmlspecialchars($at['obs_envio'], ENT_QUOTES) ?>"
                          data-adjunto="<?= htmlspecialchars($at['adjunto_envio'], ENT_QUOTES) ?>"><i class="bi bi-pencil"></i></button>
                  <button type="button" class="hist-doc-btn hist-doc-btn-borrar despacho-btn-borrar" title="Borrar apartado" data-id="<?= (int)$at['id'] ?>"><i class="bi bi-trash"></i></button>
                </span>
                <?php endif; ?>
              </div>
              <div class="op-card-body">
                <div class="op-desp-grid" style="margin-bottom:12px">
                  <div>
                    <p class="op-field-label">Número de documento</p>
                    <p class="op-val"><?= htmlspecialchars($at['n_doc']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Entregado a</p>
                    <p class="op-val"><?= htmlspecialchars($at['transportista']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Guía</p>
                    <p class="op-val"><?= htmlspecialchars($at['guia']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Fecha de despacho</p>
                    <p class="op-val"><?= htmlspecialchars($at['fecha_entrega']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Valor despachado</p>
                    <p class="op-val"><?= htmlspecialchars($at['valor']) ?: '<span class="muted">—</span>' ?></p>
                  </div>
                  <div>
                    <p class="op-field-label">Registrado el</p>
                    <p class="op-val"><?= !empty($at['fecha_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($at['fecha_at']))) : '<span class="muted">—</span>' ?></p>
                  </div>
                </div>
                <?php if (!empty($at['obs_envio'])): ?>
                <div style="margin-bottom:12px">
                  <p class="op-field-label">Observaciones de despacho</p>
                  <p class="op-obs-text"><?= htmlspecialchars($at['obs_envio']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($at['adjunto_envio'])): ?>
                <div>
                  <p class="op-field-label">Adjunto soporte de entrega</p>
                  <p class="op-val">
                    <a href="adjuntos/envio/<?= htmlspecialchars($at['adjunto_envio']) ?>" target="_blank">
                      <i class="bi bi-paperclip"></i> <?= htmlspecialchars($at['adjunto_envio']) ?>
                    </a>
                  </p>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; endforeach; endif; ?>

            <?php if ($puede_ver_despacho): ?>
            <!-- Formulario de despacho -->
            <div class="op-card">
              <div class="op-card-head"><i class="bi bi-truck"></i> Registrar despacho</div>
              <div class="op-card-body">

                <div id="despacho-apartados" data-op="<?= $op['opid'] ?>">
                  <?= wo_apartado_html() ?>
                </div>

                <!-- Misma plantilla, oculta: JS la clona en "Añadir otro apartado" -->
                <template id="tpl-apartado"><?= wo_apartado_html() ?></template>

                <div class="d-print-none" style="margin-top:4px;">
                  <button type="button" class="wo-btn wo-btn-outline" id="btn-add-apartado">
                    <i class="bi bi-plus-lg"></i> Añadir otro apartado
                  </button>
                </div>
              </div>
            </div>

            <input type="hidden" name="op" value="<?= $op['opid'] ?>">

            <!-- Botones de acción -->
            <div class="op-actions">
              <button type="submit" name="accion" value="guardar" class="op-btn op-btn-guardar">
                <i class="bi bi-save"></i> Guardar
              </button>
              <button type="submit" name="accion" value="atender" class="op-btn op-btn-atender">
                <i class="bi bi-check-lg"></i> Atender
              </button>
              <?php if ($op['estado'] == 1): ?>
              <a class="op-btn op-btn-anular" data-toggle="modal" data-target="#ModalAnu">
                <i class="bi bi-x-lg"></i> Anular
              </a>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          <?php endif; ?>

          </form>

          <?php if ($op['estado'] != 4 && $puede_ver_despacho): ?>
          <!-- Formulario oculto para borrar un registro del historial de documentos -->
          <form id="form-hist-doc-borrar" method="POST" action="php/historial_documento_op_borrar.php" style="display:none;">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="op" value="<?= $op['opid'] ?>">
          </form>

          <!-- Modal Editar registro del historial de documentos -->
          <div class="modal fade" id="ModalEditarDocOp" tabindex="-1" role="dialog" aria-labelledby="modalEditarDocOpLabel">
            <div class="modal-dialog" role="document">
              <div class="modal-content" style="border-radius:12px; overflow:hidden;">
                <form method="POST" action="php/historial_documento_op_editar.php">
                  <div class="modal-header" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
                    <h4 class="modal-title" id="modalEditarDocOpLabel" style="color:#1e3a8a; font-weight:700; font-size:1rem;">
                      <i class="bi bi-pencil"></i> Editar registro del historial
                    </h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body" style="padding:20px;">
                    <input type="hidden" name="id" id="edit-hist-id">
                    <input type="hidden" name="op" value="<?= $op['opid'] ?>">
                    <input type="hidden" name="id_wo" id="edit-hist-id-wo">
                    <div style="margin-bottom:14px;">
                      <p class="op-field-label">Tipo de documento</p>
                      <select name="tipo_doc" id="edit-hist-tipo-doc" class="op-select"></select>
                    </div>
                    <!-- Mismo buscador de World Office que "Documento del despacho": se
                         puede volver a buscar y elegir otro documento, o dejar los
                         valores actuales del registro tal cual. -->
                    <div id="edit-documento" data-op="<?= $op['opid'] ?>">
                      <?= wo_documento_html('', '') ?>
                    </div>
                  </div>
                  <div class="modal-footer" style="background:#fafafa;">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="op-btn op-btn-guardar"><i class="bi bi-save"></i> Guardar cambios</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Borrar un apartado o el despacho completo (ver php/despacho_op_borrar.php) -->
          <form id="form-despacho-borrar" method="POST" action="php/despacho_op_borrar.php" style="display:none;">
            <input type="hidden" name="op" value="<?= $op['opid'] ?>">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="todo" value="">
          </form>

          <!-- Modal Editar despacho (ver php/despacho_op_editar.php) -->
          <div class="modal fade" id="ModalEditarApartado" tabindex="-1" role="dialog" aria-labelledby="modalEditarApartadoLabel">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content" style="border-radius:12px; overflow:hidden;">
                <form method="POST" action="php/despacho_op_editar.php" enctype="multipart/form-data" id="form-editar-despacho">
                  <div class="modal-header" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
                    <h4 class="modal-title" id="modalEditarApartadoLabel" style="color:#1e3a8a; font-weight:700; font-size:1rem;">
                      <i class="bi bi-pencil"></i> Editar despacho
                    </h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                  </div>
                  <div class="modal-body" style="padding:20px;">
                    <input type="hidden" name="op" value="<?= $op['opid'] ?>">
                    <input type="hidden" name="id" id="edit-apartado-id">
                    <input type="hidden" name="id_wo" id="edit-despacho-id-wo">

                    <p class="oprel-sub" style="font-size:.75rem; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:.03em; margin:0 0 8px;">
                      <i class="bi bi-file-earmark-text"></i> Documento de World Office
                    </p>
                    <p style="font-size:.8rem; color:#64748b; margin:0 0 10px;">
                      Déjalo igual o busca y elige otro documento. Si lo cambias, pasan al documento nuevo todos los
                      apartados de este despacho y el anterior queda libre.
                    </p>
                    <div style="margin-bottom:14px; max-width:320px;">
                      <p class="op-field-label">Tipo de documento</p>
                      <select name="tipo_doc" id="edit-despacho-tipo-doc" class="op-select"></select>
                    </div>
                    <div id="edit-despacho-documento" data-op="<?= $op['opid'] ?>">
                      <?= wo_documento_html('', '') ?>
                    </div>

                    <p class="oprel-sub" style="font-size:.75rem; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:.03em; margin:18px 0 8px; padding-top:14px; border-top:1px solid #e2e8f0;">
                      <i class="bi bi-truck"></i> Datos del despacho
                    </p>
                    <div style="margin-bottom:14px;">
                      <p class="op-field-label">Entregado a <span class="op-required">*</span></p>
                      <input required type="text" name="transportista" id="edit-apartado-transportista" class="op-input">
                    </div>
                    <div class="op-form-grid-2">
                      <div>
                        <p class="op-field-label">Guía</p>
                        <input type="text" name="guia" id="edit-apartado-guia" class="op-input" maxlength="20">
                      </div>
                      <div>
                        <p class="op-field-label">Fecha despacho <span class="op-required">*</span></p>
                        <input required type="date" name="fecha_entrega" id="edit-apartado-fecha" class="op-input">
                      </div>
                    </div>
                    <div style="margin-bottom:14px;">
                      <p class="op-field-label">Observaciones de despacho</p>
                      <textarea name="obs_envio" id="edit-apartado-obs" class="op-textarea"></textarea>
                    </div>
                    <div>
                      <p class="op-field-label">Soporte de entrega</p>
                      <p class="op-val" id="edit-apartado-adjunto-actual" style="margin-bottom:6px;"></p>
                      <input type="file" name="adjunto_envio" class="op-input">
                      <p style="font-size:.75rem; color:#94a3b8; margin:4px 0 0;">Déjalo vacío para conservar el soporte actual.</p>
                    </div>
                  </div>
                  <div class="modal-footer" style="background:#fafafa;">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="op-btn op-btn-guardar"><i class="bi bi-save"></i> Guardar cambios</button>
                  </div>
                </form>
              </div>
            </div>
          </div>


          <!-- Modal Ver ítems del documento (World Office) -->
          <div class="modal fade" id="ModalVerDocOp" tabindex="-1" role="dialog" aria-labelledby="modalVerDocOpLabel">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content" style="border-radius:12px; overflow:hidden;">
                <div class="modal-header" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
                  <h4 class="modal-title" id="modalVerDocOpLabel" style="color:#1e3a8a; font-weight:700; font-size:1rem;">
                    <i class="bi bi-eye"></i> Ítems del documento <span id="ver-doc-titulo"></span>
                  </h4>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body" style="padding:0;" id="ver-doc-body">
                  <p class="wo-loading" style="padding:20px;"><i class="bi bi-hourglass-split"></i> Consultando World Office...</p>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Modal Anular -->
          <div class="modal fade" id="ModalAnu" tabindex="-1" role="dialog" aria-labelledby="modalAnuLabel">
            <div class="modal-dialog" role="document">
              <div class="modal-content" style="border-radius:12px; overflow:hidden;">
                <form class="form-horizontal" method="POST" id="form_anu" action="php/anular_op.php">
                  <div class="modal-header" style="background:#fff4f4; border-bottom:1px solid #fecaca;">
                    <h4 class="modal-title" id="modalAnuLabel" style="color:#dc2626; font-weight:700; font-size:1rem;">
                      <i class="bi bi-x-circle-fill"></i> Anular OP
                    </h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body" style="padding:20px;">
                    <div class="form-group">
                      <label class="op-field-label" for="motivo_anu">Motivo de anulación <span class="op-required">*</span></label>
                      <textarea name="motivo_anu" id="motivo_anu" class="op-textarea" required></textarea>
                      <input type="hidden" name="op" value="<?= $op['opid'] ?>">
                    </div>
                  </div>
                  <div class="modal-footer" style="background:#fafafa;">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="op-btn op-btn-anular" id="anular">
                      <i class="bi bi-x-lg"></i> Anular
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

        </div>
        <?php include("template/footer.php"); ?>
      </div>
    </div>

    <script src="vendors/scripts/core.js"></script>
    <script src="vendors/scripts/script.min.js"></script>
    <script src="vendors/scripts/process.js"></script>
    <script src="vendors/scripts/layout-settings.js"></script>

    <script>
      $(document).ready(function(){
        $('#cliente').select2({
          placeholder: 'Seleccionar cliente',
          allowClear: true,
          minimumResultsForSearch: 0,
          width: '100%',
          language: {
            noResults: function(){ return 'Sin resultados'; },
            searching:  function(){ return 'Buscando...'; }
          }
        });
      });

      $('#materia').on('change',function(){
        var valor = $(this).val();
        var dataString = 'mat_gra='+valor;
        $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:dataString, dataType:"html",
          success:function(resp){ $("#libro").html(resp); },
          error:function(){ alert("error"); }
        });
      });

      $('#libro').on('change',function(){
        var cant=$('#cantidad').val(), libro=$('#libro').val();
        var grado=$('#libro option:selected').attr('data-grado');
        if (grado==15||grado==16) {
          $('#l_cantidad').addClass("d-none"); $('#cantidad').addClass("d-none");
          $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
            success:function(resp){ $("#ls_pri_sec").html('').append(resp); }
          });
        } else { $('#libro_e').val(libro+'/'+cant); }
      });

      $('#cantidad').keyup(function(){
        var cant=$('#cantidad').val(), libro=$('#libro').val();
        var grado=$('#libro option:selected').attr('data-grado');
        if (grado!=15||grado!=16) { $('#libro_e').val(libro+'/'+cant); }
      });

      var m=1;
      $("#agregar_libro").click(function(){
        if (m>98) { $("#agregar_libro").addClass("d-none"); }
        $("#agg_l"+m).removeClass("d-none"); m++;
        <?php for ($i=1; $i<100; $i++) { ?>
          $('#materia<?php echo $i; ?>').on('change',function(){
            var v=$(this).val();
            $.ajax({ url:"ajax/buscar_l_eureka_sp.php", type:"POST", data:'mat_gra='+v, dataType:"html",
              success:function(resp){ $("#libro<?php echo $i; ?>").html(resp); }
            });
          });
          $('#libro<?php echo $i; ?>').on('change',function(){
            var cant=$('#cantidad<?php echo $i; ?>').val(), libro=$('#libro<?php echo $i; ?>').val();
            var grado=$('#libro<?php echo $i; ?> option:selected').attr('data-grado');
            if (grado==15||grado==16) {
              $('#l_cantidad<?php echo $i; ?>').addClass("d-none"); $('#cantidad<?php echo $i; ?>').addClass("d-none");
              $.ajax({ url:"ajax/buscar_pri_sec.php", type:"POST", data:'pri_sec='+libro, dataType:"html",
                success:function(resp){ $("#ls_pri_sec<?php echo $i; ?>").html('').append(resp); }
              });
            } else { $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant); }
          });
          $('#cantidad<?php echo $i; ?>').keyup(function(){
            var cant=$('#cantidad<?php echo $i; ?>').val(), libro=$('#libro<?php echo $i; ?>').val();
            var grado=$('#libro option:selected').attr('data-grado');
            if (grado!=15||grado!=16) { $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant); }
          });
        <?php } ?>
      });
      $('.op-actions button[name="accion"]').on('click', function(){
        $(this).closest('form').data('accion', $(this).val());
      });
      $('form[action="php/procesar_op.php"]').on('submit', function(e){
        e.preventDefault();
        var form = this;
        var accion = $(form).data('accion') || 'atender';
        var opts = accion === 'guardar'
          ? { title: '¿Guardar despacho?', text: 'Se guardará la información registrada y podrás seguir añadiendo más apartados.', type: 'success', btnOk: 'Sí, guardar' }
          : { title: '¿Atender esta OP?', text: 'Se registrará el despacho y la OP quedará como atendida.', type: 'success', btnOk: 'Sí, atender' };
        verificarExcedente($('#despacho-id-wo').val(), function (confirmado) {
          if (confirmado) $('<input>').attr({ type: 'hidden', name: 'confirmar_excedente', value: '1' }).appendTo(form);
          inkConfirm(opts, function(){
            $('<input>').attr({ type: 'hidden', name: 'accion', value: accion }).appendTo(form);
            form.submit();
          });
        });
      });

      // ── Buscar documento en World Office (único por OP) ────────────────
      // El documento (factura/remisión) y su valor se buscan/asignan UNA
      // sola vez por OP, arriba de los apartados. Una vez que se elige un
      // candidato, el número de documento queda de solo lectura para que no
      // se pueda escribir uno distinto a mano. La misma lógica se reutiliza
      // en el modal de "Editar registro del historial" (initWoSearch se
      // llama una vez por cada contenedor que tenga esta caja de búsqueda).
      // ── Alerta de excedente ────────────────────────────────────────────
      // Antes de asociar un documento de World Office a la OP (guardar,
      // atender, editar despacho/historial) se compara lo que trae contra lo
      // solicitado en los pedidos de la OP (ajax/excedente_documento_op.php).
      // Si envía unidades de más, se muestra la diferencia por título y se
      // pide confirmar; al confirmar, el servidor deja registro del excedente.
      var EXC_OP_ID = <?= (int)$op['opid'] ?>;
      function fmtCantExc(v) {
        return Number(v).toLocaleString('es-CO', { maximumFractionDigits: 2 });
      }
      function mostrarAlertaExcedente(calc, onOk, onCancel) {
        var filas = calc.items.map(function (it) {
          return '<tr><td>' + $('<div>').text(it.descripcion || it.codigo).html() + '</td>' +
            '<td class="num">' + fmtCantExc(it.solicitada) + '</td>' +
            '<td class="num">' + fmtCantExc(it.previa) + '</td>' +
            '<td class="num">' + fmtCantExc(it.en_documento) + '</td>' +
            '<td class="num exc-mas">+' + fmtCantExc(it.excedente) + '</td></tr>';
        }).join('');
        var aviso = calc.sin_sincronizar
          ? '<p class="exc-nota"><i class="bi bi-info-circle"></i> Hay documentos anteriores de este pedido sin sincronizar con World Office: lo despachado antes podría ser mayor.</p>'
          : '';
        var $ov = $('<div class="ink-overlay">').html(
          '<div class="ink-modal exc-modal">' +
            '<div class="ink-modal-icon warning"><i class="bi bi-exclamation-triangle-fill"></i></div>' +
            '<p class="ink-modal-title">Se están enviando ' + fmtCantExc(calc.total_excedente) + ' unidades adicionales a las solicitadas</p>' +
            '<p class="exc-texto">Con este documento lo despachado supera lo solicitado en el pedido' +
              (calc.pedidos.length > 1 ? 's #' + calc.pedidos.join(', #') : ' #' + calc.pedidos[0]) + ':</p>' +
            '<div class="table-responsive"><table class="exc-tabla"><thead><tr><th>Título</th><th class="num">Solicitado</th>' +
            '<th class="num">Despachado antes</th><th class="num">En este documento</th><th class="num">De más</th></tr></thead>' +
            '<tbody>' + filas + '</tbody></table></div>' + aviso +
            '<p class="exc-texto">Si continúas, el excedente quedará registrado en el pedido y en la OP.</p>' +
            '<div class="ink-modal-btns"><button type="button" class="ink-btn-cancel">Cancelar</button>' +
            '<button type="button" class="ink-btn-warning">Sí, continuar</button></div>' +
          '</div>');
        $ov.find('.ink-btn-cancel').on('click', function () { $ov.remove(); if (onCancel) onCancel(); });
        $ov.find('.ink-btn-warning').on('click', function () { $ov.remove(); onOk(); });
        $('body').append($ov);
      }
      // onContinuar(confirmado): confirmado=true si hubo excedente y se aceptó.
      function verificarExcedente(idWo, onContinuar, onCancel) {
        if (!idWo || idWo === '0') { onContinuar(false); return; }
        $.getJSON('ajax/excedente_documento_op.php', { op: EXC_OP_ID, id_wo: idWo })
          .done(function (calc) {
            if (calc.ok === false && window.inkToast) {
              window.inkToast(calc.error || 'No se pudo comparar el documento con lo solicitado.', 'warn');
            }
            if (!calc.items || !calc.items.length || calc.ya_registrado) { onContinuar(false); return; }
            mostrarAlertaExcedente(calc, function () { onContinuar(true); }, onCancel);
          })
          .fail(function () {
            if (window.inkToast) window.inkToast('No se pudo comparar el documento con lo solicitado.', 'warn');
            onContinuar(false);
          });
      }
      // Formularios de los modales "Editar registro del historial" y "Editar
      // despacho": misma revisión antes de enviarlos, si se eligió un documento.
      $('form[action="php/historial_documento_op_editar.php"], #form-editar-despacho').on('submit', function (e) {
        var form = this;
        if ($(form).data('exc-ok')) return;
        e.preventDefault();
        verificarExcedente($(form).find('input[name="id_wo"]').val(), function (confirmado) {
          if (confirmado) $('<input>').attr({ type: 'hidden', name: 'confirmar_excedente', value: '1' }).appendTo(form);
          $(form).data('exc-ok', true);
          form.submit();
        });
      });

      function fmtValorWo(v) {
        if (v === null || v === undefined) return '—';
        return '$' + Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 });
      }

      // onSeleccionar(candidato, $btnGuardar, $lista) se llama cuando el
      // usuario elige un candidato de los resultados; ahí decide cada
      // contenedor si guarda de inmediato (documento actual) o solo llena
      // los campos para que el propio formulario los envíe (modal editar).
      function initWoSearch(selector, onSeleccionar) {
        var $doc = $(selector);
        if (!$doc.length) return null;

        var $nDoc = $doc.find('.f-n-doc');
        var $valor = $doc.find('.f-valor');

        function renderResultados(candidatos) {
          var $wrap = $doc.find('.wo-resultados').empty();
          if (!candidatos.length) {
            $wrap.append($('<p>', { class: 'wo-empty' }).html('<i class="bi bi-info-circle"></i> No se encontraron documentos en World Office con ese criterio.'));
            return;
          }
          var $lista = $('<div>', { class: 'wo-results' });
          candidatos.forEach(function (c) {
            var $info = $('<div>', { class: 'wo-result-info' }).append(
              $('<div>').append(
                $('<span>', { class: 'wo-result-doc' }).text(c.documento),
                $('<span>', { class: 'wo-result-tipo wo-result-tipo-' + c.tipo }).text(c.tipo_label)
              ),
              $('<div>', { class: 'wo-result-meta' }).text(c.fecha + ' · ' + (c.tercero_externo || '—')),
              $('<div>', { class: 'wo-result-concepto', title: c.concepto }).text(c.concepto)
            );
            var $btnGuardar = $('<button>', { type: 'button', class: 'wo-btn' }).html('<i class="bi bi-save"></i> Guardar');
            $btnGuardar.on('click', function () {
              // Carga (reemplaza) el documento y el valor — elegir otro
              // candidato sustituye al anterior, no se suman. Se pasan los
              // valores anteriores por si hay que deshacer (p. ej. se cancela
              // la alerta de excedente).
              var previo = { n_doc: $nDoc.val(), valor: $valor.val() };
              $nDoc.val(c.documento).prop('readonly', true);
              $valor.val(c.valor_neto).prop('readonly', true);
              onSeleccionar(c, $btnGuardar, $lista, previo);
            });
            var $right = $('<div>', { style: 'display:flex;align-items:center;gap:12px;flex-shrink:0;' }).append(
              $('<span>', { class: 'wo-result-valor' }).text(fmtValorWo(c.valor_neto)),
              $btnGuardar
            );
            $lista.append($('<div>', { class: 'wo-result-card' }).append($info, $right));
          });
          $wrap.append($lista);
        }

        function buscar(url, $btn) {
          var textoOriginal = $btn.html();
          $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Buscando...');
          $doc.find('.wo-resultados').html('<p class="wo-loading"><i class="bi bi-hourglass-split"></i> Consultando World Office...</p>');
          $.getJSON(url)
            .done(function (resp) {
              if (!resp.ok) {
                $doc.find('.wo-resultados').html('<p class="wo-error"><i class="bi bi-exclamation-triangle"></i> ' + (resp.error || 'No se pudo buscar.') + '</p>');
                return;
              }
              renderResultados(resp.candidatos || []);
            })
            .fail(function () {
              $doc.find('.wo-resultados').html('<p class="wo-error"><i class="bi bi-exclamation-triangle"></i> Error al conectar con World Office. Intenta de nuevo.</p>');
            })
            .always(function () {
              $btn.prop('disabled', false).html(textoOriginal);
            });
        }

        $doc.on('click', '.wo-btn-auto', function () {
          buscar('ajax/wo_buscar_documento_op.php?modo=auto&op=' + encodeURIComponent($doc.data('op')), $(this));
        });

        $doc.on('click', '.wo-btn-manual', function () {
          var texto = $doc.find('.wo-texto-manual').val().trim();
          if (!texto) { $doc.find('.wo-texto-manual').focus(); return; }
          buscar('ajax/wo_buscar_documento_op.php?modo=manual&texto=' + encodeURIComponent(texto), $(this));
        });

        $doc.on('keydown', '.wo-texto-manual', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); $doc.find('.wo-btn-manual').click(); }
        });

        return { $doc: $doc, $nDoc: $nDoc, $valor: $valor };
      }

      // Inserta al inicio de la tarjeta "Historial de documentos" el
      // registro que acaba de guardar php/wo_guardar_documento_op.php, sin
      // recargar la página.
      function agregarFilaHistorial(entry) {
        var $card = $('#historial-documento-op');
        if (!$card.length) return;
        $card.find('.hist-doc-empty').hide();
        var $tabla = $card.find('.hist-doc-table').show();
        var $acciones = $('<td>', { class: 'd-print-none hist-doc-acciones' });
        if (entry.id_wo) {
          $acciones.append($('<button>', { type: 'button', class: 'hist-doc-btn hist-doc-btn-ver', title: 'Ver ítems' }).html('<i class="bi bi-eye"></i>'));
        }
        $acciones.append(
          $('<button>', { type: 'button', class: 'hist-doc-btn hist-doc-btn-editar', title: 'Editar' }).html('<i class="bi bi-pencil"></i>'),
          $('<button>', { type: 'button', class: 'hist-doc-btn hist-doc-btn-borrar', title: 'Borrar' }).html('<i class="bi bi-trash"></i>')
        );
        var $fila = $('<tr>', {
          'data-id': entry.id,
          'data-tipo-doc': entry.tipo_doc || 0,
          'data-n-doc': entry.n_doc,
          'data-id-wo': entry.id_wo || 0,
          'data-valor': entry.valor,
        }).append(
          $('<td>').text(entry.fecha),
          $('<td>').text(entry.usuario),
          $('<td>').text(entry.tipo || '—'),
          $('<td>').text(entry.n_doc),
          $('<td>').text(entry.valor_fmt),
          $acciones
        );
        $tabla.find('.hist-doc-tbody').prepend($fila);
      }

      // Documento actual de la OP: al elegir un candidato se guarda de
      // inmediato y queda constancia en el historial.
      (function () {
        var wo = initWoSearch('#despacho-documento', function (c, $btnGuardar, $lista, previo) {
          var opId = wo.$doc.data('op');
          $btnGuardar.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Revisando...');

          verificarExcedente(c.id, function (confirmado) {
            guardarDocumento(confirmado);
          }, function () {
            // Canceló la alerta de excedente: se deja el documento que había.
            wo.$nDoc.val(previo.n_doc);
            wo.$valor.val(previo.valor);
            $btnGuardar.prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
          });

          function guardarDocumento(confirmado) {
          $btnGuardar.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando...');
          $('#despacho-id-wo').val(c.id);

          $.post('php/wo_guardar_documento_op.php', {
            op: opId,
            tipo_doc: $('#tipo_doc').val(),
            cliente: $('#cliente').val(),
            n_doc: c.documento,
            id_wo: c.id,
            valor: c.valor_neto,
            confirmar_excedente: confirmado ? 1 : 0,
          }, null, 'json')
            .done(function (resp) {
              if (!resp.ok) {
                if (window.inkToast) window.inkToast(resp.error || 'No se pudo guardar el documento.', 'error');
                $btnGuardar.prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
                return;
              }
              $lista.find('.wo-btn').prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
              $btnGuardar.prop('disabled', true).html('<i class="bi bi-check-lg"></i> Cargado');
              if (resp.changed && resp.entry) agregarFilaHistorial(resp.entry);
              if (window.inkToast) window.inkToast(c.documento + ' cargado y guardado.', 'ok');
              // El excedente confirmado queda en la trazabilidad de la OP: se recarga para verlo.
              if (confirmado) setTimeout(function () { location.reload(); }, 900);
            })
            .fail(function () {
              if (window.inkToast) window.inkToast('Error al conectar para guardar el documento.', 'error');
              $btnGuardar.prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
            });
          }
        });

        // Queda de solo lectura desde el inicio — no se puede escribir a
        // mano — pero conserva el valor que trajo PHP (el último documento
        // guardado en el historial de esta OP), para no bloquear
        // "Registrar despacho"/"Atender" si el documento ya estaba bien y
        // no hace falta reemplazarlo.
        if (wo) {
          wo.$nDoc.prop('readonly', true);
          wo.$valor.prop('readonly', true);
        }
      })();

      // ── Editar / borrar registros del historial de documentos ──────────
      // El documento actual (arriba) se sigue reemplazando desde el
      // buscador de World Office; esto es solo para corregir o quitar
      // registros ya guardados en el historial (p.ej. se guardó el
      // documento equivocado por error). El modal de editar tiene su propio
      // buscador de World Office — al elegir un candidato ahí solo se llenan
      // los campos; el guardado real ocurre al enviar el formulario del modal.
      (function () {
        var $card = $('#historial-documento-op');
        if (!$card.length) return;

        var woEdit = initWoSearch('#edit-documento', function (c, $btnGuardar, $lista) {
          $lista.find('.wo-btn').prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
          $btnGuardar.prop('disabled', true).html('<i class="bi bi-check-lg"></i> Cargado');
          $('#edit-hist-id-wo').val(c.id);
        });

        // El select de tipo de documento del modal de edición se llena una
        // sola vez clonando las opciones del select principal del formulario.
        var $tipoDocEdit = $('#edit-hist-tipo-doc');
        if ($tipoDocEdit.length && $('#tipo_doc').length) {
          $tipoDocEdit.html($('#tipo_doc').html());
        }

        $card.on('click', '.hist-doc-btn-editar', function () {
          var $fila = $(this).closest('tr');

          // Reinicia el buscador del modal (resultados y texto de búsqueda
          // de la vez anterior) y deja el documento/valor en blanco y de
          // solo lectura — no se pueden escribir a mano ni quedan con el
          // valor que ya tenía el registro; solo se llenan si se busca y
          // se elige otro documento de World Office.
          if (woEdit) {
            woEdit.$doc.find('.wo-resultados').empty();
            woEdit.$doc.find('.wo-texto-manual').val('');
            woEdit.$nDoc.val('').prop('readonly', true);
            woEdit.$valor.val('').prop('readonly', true);
          }

          $('#edit-hist-id').val($fila.data('id'));
          $('#edit-hist-tipo-doc').val($fila.data('tipo-doc'));
          $('#edit-hist-id-wo').val('');
          $('#ModalEditarDocOp').modal('show');
        });

        $card.on('click', '.hist-doc-btn-ver', function () {
          var $fila = $(this).closest('tr');
          var idWo = $fila.data('id-wo');
          if (!idWo) return;

          $('#ver-doc-titulo').text($fila.data('n-doc') || '');
          $('#ver-doc-body').html('<p class="wo-loading" style="padding:20px;"><i class="bi bi-hourglass-split"></i> Consultando World Office...</p>');
          $('#ModalVerDocOp').modal('show');

          $.getJSON('ajax/wo_ver_items_documento.php', { id_wo: idWo })
            .done(function (resp) {
              if (!resp.ok) {
                $('#ver-doc-body').html('<p class="wo-error" style="padding:20px;"><i class="bi bi-exclamation-triangle"></i> ' + (resp.error || 'No se pudo consultar el documento.') + '</p>');
                return;
              }
              if (!resp.items.length) {
                $('#ver-doc-body').html('<p class="wo-empty" style="padding:20px;"><i class="bi bi-info-circle"></i> Este documento no tiene ítems.</p>');
                return;
              }
              var $tabla = $('<table>', { class: 'hist-doc-table' });
              var $thead = $('<thead>').append($('<tr>').append(
                $('<th>').text('Producto'),
                $('<th>').text('Cantidad'),
                $('<th>').text('Precio unitario'),
                $('<th>').text('Descuento'),
                $('<th>').text('Total')
              ));
              var $tbody = $('<tbody>');
              resp.items.forEach(function (it) {
                $tbody.append($('<tr>').append(
                  $('<td>').text(it.descripcion + (it.codigo ? ' (' + it.codigo + ')' : '')),
                  $('<td>').text(it.cantidad),
                  $('<td>').text(fmtValorWo(it.valor_unitario)),
                  $('<td>').text((it.descuento_pct * 100).toLocaleString('es-CO', { maximumFractionDigits: 2 }) + '%'),
                  $('<td>').text(fmtValorWo(it.total))
                ));
              });
              var $tfoot = $('<tfoot>').append($('<tr>').append(
                $('<td>', { colspan: 4, style: 'text-align:right;font-weight:700;' }).text('Total'),
                $('<td>', { style: 'font-weight:700;' }).text(fmtValorWo(resp.total))
              ));
              $('#ver-doc-body').empty().append($tabla.append($thead, $tbody, $tfoot));
            })
            .fail(function () {
              $('#ver-doc-body').html('<p class="wo-error" style="padding:20px;"><i class="bi bi-exclamation-triangle"></i> Error al conectar con World Office. Intenta de nuevo.</p>');
            });
        });

        $card.on('click', '.hist-doc-btn-borrar', function () {
          var $fila = $(this).closest('tr');
          var id = $fila.data('id');
          inkConfirm({
            title: '¿Borrar este registro?',
            text: 'Se quitará del historial de documentos. Esta acción no se puede deshacer.',
            type: 'danger',
            btnOk: 'Sí, borrar',
          }, function () {
            $('#form-hist-doc-borrar input[name="id"]').val(id);
            $('#form-hist-doc-borrar').submit();
          });
        });
      })();

      // ── Corregir un despacho ya guardado ───────────────────────────────
      // Lápiz de cada "Despacho registrado": un solo modal para cambiar el
      // documento de World Office (opcional) y los datos del apartado
      // (php/despacho_op_editar.php). Papelera: borra el apartado
      // (php/despacho_op_borrar.php). Los modales están fuera del formulario
      // principal de despacho.
      (function () {
        var woDespacho = initWoSearch('#edit-despacho-documento', function (c, $btnGuardar, $lista) {
          $lista.find('.wo-btn').prop('disabled', false).html('<i class="bi bi-save"></i> Guardar');
          $btnGuardar.prop('disabled', true).html('<i class="bi bi-check-lg"></i> Elegido');
          $('#edit-despacho-id-wo').val(c.id);
        });
        var $tipoDocDespacho = $('#edit-despacho-tipo-doc');
        if ($tipoDocDespacho.length && $('#tipo_doc').length) {
          $tipoDocDespacho.html($('#tipo_doc').html());
        }

        $(document).on('click', '.despacho-btn-editar', function () {
          var $b = $(this);
          // Documento: arranca con el actual del despacho (solo lectura); solo
          // cambia si se busca y elige otro en World Office.
          if (woDespacho) {
            woDespacho.$doc.find('.wo-resultados').empty();
            woDespacho.$doc.find('.wo-texto-manual').val('');
            woDespacho.$nDoc.val($b.attr('data-n-doc')).prop('readonly', true);
            woDespacho.$valor.val($b.attr('data-valor')).prop('readonly', true);
          }
          $('#edit-despacho-id-wo').val('');
          $tipoDocDespacho.val($('#tipo_doc').val());
          $('#edit-apartado-id').val($b.data('id'));
          $('#edit-apartado-transportista').val($b.attr('data-transportista'));
          $('#edit-apartado-guia').val($b.attr('data-guia'));
          $('#edit-apartado-fecha').val(($b.attr('data-fecha') || '').substring(0, 10));
          $('#edit-apartado-obs').val($b.attr('data-obs'));
          var adj = $b.attr('data-adjunto');
          $('#edit-apartado-adjunto-actual').html(adj
            ? '<a href="adjuntos/envio/' + encodeURIComponent(adj) + '" target="_blank"><i class="bi bi-paperclip"></i> ' + $('<span>').text(adj).html() + '</a>'
            : '<span class="muted">Sin soporte</span>');
          $('#ModalEditarApartado input[type=file]').val('');
          $('#ModalEditarApartado').modal('show');
        });

        $(document).on('click', '.despacho-btn-borrar', function () {
          var id = $(this).data('id');
          inkConfirm({
            title: '¿Borrar este apartado?',
            text: 'Se quitará este envío del despacho. Si era el único, el documento quedará libre.',
            type: 'danger',
            btnOk: 'Sí, borrar',
          }, function () {
            var $f = $('#form-despacho-borrar');
            $f.find('input[name="id"]').val(id);
            $f.find('input[name="todo"]').val('');
            $f.submit();
          });
        });

      })();

      // ── Apartados de envío repetibles ───────────────────────────────
      // Cada "apartado" es un envío (entregado a + guía + fecha + soporte)
      // del MISMO documento de arriba. Se pueden agregar tantos como haga
      // falta; el backend (php/procesar_op.php) guarda un registro en
      // op_atendidas por cada uno, todos con el mismo n_doc/valor.
      (function () {
        var $container = $('#despacho-apartados');
        if (!$container.length) return;

        function initDatepicker($input) {
          $input.datepicker({ language: 'en', autoClose: true, dateFormat: 'dd MM yyyy' });
        }

        function renumerar() {
          var $apartados = $container.find('.despacho-apartado');
          $apartados.each(function (i) {
            $(this).find('> .despacho-apartado-head .apartado-num').text(i + 1);
          });
          $apartados.find('> .despacho-apartado-head .wo-btn-quitar-apartado').toggle($apartados.length > 1);
        }

        // El primer apartado ya viene impreso por PHP, así que su
        // datepicker ya lo inicializa el script global del sitio
        // (vendors/scripts/script.js, que corre $(".date-picker").datepicker()
        // sobre todo lo presente al cargar la página). Inicializarlo de nuevo
        // aquí duplica el plugin sobre el mismo input y hace que la fecha
        // elegida no se refleje en el campo. Solo hace falta inicializar los
        // apartados que se agreguen dinámicamente después (más abajo).
        renumerar();

        $('#btn-add-apartado').on('click', function () {
          var html = document.getElementById('tpl-apartado').innerHTML;
          var $nuevo = $(html).appendTo($container);
          $nuevo.find('.date-picker').each(function () { initDatepicker($(this)); });
          renumerar();
          $nuevo.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        $container.on('click', '.wo-btn-quitar-apartado', function () {
          if ($container.find('.despacho-apartado').length <= 1) return; // siempre debe quedar al menos uno
          $(this).closest('.despacho-apartado').remove();
          renumerar();
        });
      })();
    </script>
<script src="src/ink-alerts.js"></script>
  </body>
</html>
