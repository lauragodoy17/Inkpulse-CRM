<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$id_colegio = intval($_GET["id_colegio"] ?? 0);
$periodo_id = intval($_GET["periodo"] ?? 0);

// Qué se puede pedir según el Tipo de adopción (2026-11-10): Libros sueltos → todo suelto; Paquetes → todo en
// paquete; Ambos → paquetes + los títulos marcados "Libro suelto". Ver includes/pedido_paquetes.php.
require_once("includes/pedido_paquetes.php");
$opciones_pedido = pedido_opciones($bdd, $id_colegio, $periodo_id);
$paquetes_pedido = $opciones_pedido['paquetes'];
$libros          = $opciones_pedido['sueltos'];
$tipo_adop_ped   = $opciones_pedido['tipo_adop'];
$ver_plataforma  = ($_SESSION['tipo'] == 3 || $_SESSION['zona'] == '5656');

$cole_info    = $bdd->query("SELECT colegio, direccion FROM colegios WHERE id={$id_colegio}")->fetch();
$periodo_info = $bdd->query("SELECT periodo FROM periodos WHERE id={$periodo_id}")->fetch();
$tipos_doc    = $bdd->query("SELECT id, tipo, descrip FROM tipo_doc WHERE act=1")->fetchAll();

// Cliente definido en la pestaña Adopciones para este colegio/período (editable aquí también).
// clientes tiene ~10.000 filas: se resuelve solo el ya seleccionado y el resto se busca por AJAX.
$stmt_cliente_adop = $bdd->prepare("SELECT cliente FROM recursos WHERE id_colegio=? AND id_periodo=?");
$stmt_cliente_adop->execute([$id_colegio, $periodo_id]);
$rec_cliente = $stmt_cliente_adop->fetch();
$cliente_predet = !empty($rec_cliente['cliente']) ? $rec_cliente['cliente'] : '';

$cliente_sel = null;
if ($cliente_predet !== '') {
    $stmt_cl_sel = $bdd->prepare("SELECT id, cliente FROM clientes WHERE id=?");
    $stmt_cl_sel->execute([$cliente_predet]);
    $cliente_sel = $stmt_cl_sel->fetch();
}

// Verificar si existe documento de adopción cargado (solo aplica para tipos 1, 3, 10)
$tiene_archivo = true;
if (in_array($_SESSION['tipo'], [1, 3, 10])) {
    try {
        $stmt_arch = $bdd->prepare("SELECT archivo FROM recursos WHERE id_colegio=? AND id_periodo=?");
        $stmt_arch->execute([$id_colegio, $periodo_id]);
        $rec_arch   = $stmt_arch->fetch();
        $tiene_archivo = !empty($rec_arch['archivo']);
    } catch (Exception $e) {
        $tiene_archivo = false;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Solicitar pedido</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32"  href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16"  href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }

    /* Info cards */
    .mc-cards {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px; margin-bottom: 22px;
    }
    .mc-card {
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
      padding: 14px 16px; display: flex; align-items: flex-start; gap: 12px; min-width: 0;
    }
    .mc-card-icon {
      width: 40px; height: 40px; border-radius: 9px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    .mc-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .mc-card-icon.green  { background:#dcfce7; color:#15803d; }
    .mc-card-icon.orange { background:#ffedd5; color:#c2410c; }
    .mc-card-label { font-size:.68rem; color:#64748b; margin:0 0 3px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .mc-card-val   { font-size:.88rem; font-weight:700; color:#0f172a; margin:0; word-break:break-word; line-height:1.35; }

    /* Card header */
    .sop-card-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px 16px; border-bottom: 1px solid #e2e8f0;
    }
    .sop-card-icon {
      width: 46px; height: 46px; border-radius: 12px;
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; color: #fff; flex-shrink: 0;
    }
    .sop-card-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; }
    .sop-card-sub   { font-size: .78rem; color: #64748b; margin: 2px 0 0; }
    .sop-body       { padding: 24px; }
    .sop-divider    { border: none; border-top: 1px solid #e2e8f0; margin: 0 0 24px; }

    /* Section title */
    .sop-section-title {
      font-size: .78rem; font-weight: 700; color: #374151; text-transform: uppercase;
      letter-spacing: .06em; margin: 0 0 14px; display: flex; align-items: center; gap: 6px;
    }

    /* Form grid */
    .sop-grid {
      display: grid; grid-template-columns: repeat(3, 1fr);
      gap: 20px 22px; margin-bottom: 22px;
    }
    .sop-span2 { grid-column: span 2; }
    .sop-full  { grid-column: 1 / -1; }
    @media (max-width: 768px) {
      .sop-grid { grid-template-columns: 1fr; }
      .sop-span2, .sop-full { grid-column: 1; }
    }
    .sop-field  { display: flex; flex-direction: column; gap: 6px; }
    .sop-label  { font-size:.73rem; font-weight:600; color:#374151; text-transform:uppercase; letter-spacing:.04em; margin:0; }
    .sop-label .req { color:#ef4444; margin-left:2px; }
    .sop-input, .sop-select, .sop-textarea {
      width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb; outline: none;
      transition: border-color .15s, box-shadow .15s; font-family: inherit; box-sizing: border-box;
    }
    .sop-input:focus, .sop-select:focus, .sop-textarea:focus {
      border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
    }
    .sop-select {
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px; cursor: pointer;
    }
    .sop-textarea { resize: vertical; min-height: 90px; }

    /* Select2 para Cliente */
    #cliente + .select2-container { width: 100% !important; }
    #cliente + .select2-container .select2-selection--single {
      height: 42px; border: 1.5px solid #d1d5db; border-radius: 8px;
      background: #f9fafb; display: flex; align-items: center;
      padding: 0 14px; font-size: .875rem; color: #1e293b;
      transition: border-color .15s, box-shadow .15s;
    }
    #cliente + .select2-container .select2-selection--single .select2-selection__rendered { padding:0; line-height:normal; color:#1e293b; font-size:.875rem; }
    #cliente + .select2-container .select2-selection--single .select2-selection__placeholder { color:#9ca3af; }
    #cliente + .select2-container .select2-selection--single .select2-selection__arrow { height:40px; right:10px; }
    #cliente + .select2-container--open .select2-selection--single,
    #cliente + .select2-container--focus .select2-selection--single {
      border-color:#2563eb; background:#fff; box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .select2-dropdown { border:1.5px solid #d1d5db; border-radius:8px; box-shadow:0 4px 20px rgba(15,23,42,.10); }
    .select2-search--dropdown { padding:8px; }
    .select2-search--dropdown .select2-search__field { border:1.5px solid #d1d5db; border-radius:7px; padding:7px 12px; font-size:.85rem; outline:none; }
    .select2-search--dropdown .select2-search__field:focus { border-color:#2563eb; }
    .select2-results__option { font-size:.875rem; padding:8px 14px; }
    .select2-results__option--highlighted { background:#2563eb !important; }

    /* Books table */
    .sp-books-wrap { overflow-x: auto; margin-bottom: 24px; border-radius: 10px; border: 1px solid #e2e8f0; }
    .sp-books-table { width: 100%; border-collapse: collapse; min-width: 600px; }
    .sp-books-table thead th {
      background: #1e40af; color: #fff; font-weight: 600; font-size: .80rem;
      padding: 11px 14px; white-space: nowrap; border: none;
    }
    .sp-books-table tbody td {
      padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: .875rem; color: #1e293b; vertical-align: middle;
    }
    .sp-books-table tbody tr:last-child td { border-bottom: none; }
    .sp-books-table tbody tr:nth-child(even) td { background: #eff6ff; }
    .sp-books-table tbody tr:hover td { background: #dbeafe !important; }
    .sp-qty {
      display: inline-flex; align-items: stretch; border: 1.5px solid #d1d5db; border-radius: 8px;
      background: #f9fafb; overflow: hidden; transition: border-color .15s;
    }
    .sp-qty:focus-within { border-color: #2563eb; background: #fff; }
    .sp-qty-btn {
      width: 32px; border: 0; background: #eef2f7; color: #1e293b; font-size: 1rem; font-weight: 700;
      cursor: pointer; line-height: 1; padding: 0; transition: background .15s;
    }
    .sp-qty-btn:hover { background: #dbeafe; color: #1d4ed8; }
    .sp-qty-btn:disabled { opacity: .4; cursor: not-allowed; background: #eef2f7; color: #1e293b; }
    .sp-qty-input {
      width: 58px; padding: 7px 4px; border: 0; font-size: .875rem; color: #1e293b;
      background: transparent; text-align: center; outline: none; -moz-appearance: textfield;
    }
    .sp-qty-input::-webkit-outer-spin-button,
    .sp-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .sop-hint { font-size: .74rem; color: #6b7280; margin: 0; }
    .sop-select:disabled { background: #eef2f7; color: #475569; cursor: not-allowed; }
    .sp-plat-check { width: 18px; height: 18px; cursor: pointer; accent-color: #2563eb; }
    /* Títulos de cada paquete (desplegable) */
    .sp-paq-libros summary { cursor: pointer; color: #1d4ed8; font-weight: 600; font-size: .82rem; list-style: none; }
    .sp-paq-libros summary::-webkit-details-marker { display: none; }
    .sp-paq-libros summary::before { content: '\25B8'; display: inline-block; margin-right: 5px; transition: transform .15s; }
    .sp-paq-libros[open] summary::before { transform: rotate(90deg); }
    .sp-paq-libros ul { margin: 6px 0 0; padding-left: 18px; font-size: .8rem; color: #475569; }

    /* Agregar libro button */
    .sp-btn-add {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 18px; border-radius: 8px; font-size: .85rem; font-weight: 600;
      border: 1.5px dashed #2563eb; color: #2563eb; background: transparent;
      cursor: pointer; transition: background .15s; margin-bottom: 24px;
    }
    .sp-btn-add:hover { background: #eff6ff; }

    /* Alerta sin archivo */
    .sop-no-arch {
      display: flex; align-items: flex-start; gap: 12px;
      background: #fff7ed; border: 1.5px solid #fed7aa; border-radius: 10px;
      padding: 14px 18px; margin-bottom: 16px; font-size: .875rem; color: #92400e;
    }
    .sop-no-arch i { font-size: 1.2rem; color: #f97316; flex-shrink: 0; margin-top: 1px; }
    .sop-no-arch strong { color: #7c2d12; }

    /* Submit */
    .sop-actions { display: flex; justify-content: center; padding-top: 8px; }
    .sop-btn-save {
      display: inline-flex; align-items: center; gap: 8px; padding: 12px 40px;
      border-radius: 9px; background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
      color: #fff; font-size: .92rem; font-weight: 600; border: none; cursor: pointer;
      box-shadow: 0 4px 14px rgba(29,78,216,.3); transition: opacity .15s, transform .1s;
    }
    .sop-btn-save:hover { opacity:.9; transform:translateY(-1px); }
    .sop-btn-save:active { transform:translateY(0); }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Solicitar pedido</h4></div>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="colegios_pedidos.php?periodo=<?= $periodo_id ?>">Pedidos</a></li>
                <li class="breadcrumb-item active">Solicitar</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="modern-card">

        <div class="sop-card-head">
          <div class="sop-card-icon"><i class="bi bi-cart-plus"></i></div>
          <div>
            <p class="sop-card-title">Nueva solicitud de pedido</p>
            <p class="sop-card-sub"><?= htmlspecialchars($cole_info['colegio'] ?? '—') ?> — Período <?= htmlspecialchars($periodo_info['periodo'] ?? '—') ?></p>
          </div>
        </div>

        <div class="sop-body">

          <!-- Info cards -->
          <div class="mc-cards">
            <div class="mc-card">
              <div class="mc-card-icon blue"><i class="bi bi-building"></i></div>
              <div><p class="mc-card-label">Colegio</p><p class="mc-card-val"><?= htmlspecialchars($cole_info['colegio'] ?? '—') ?></p></div>
            </div>
            <div class="mc-card">
              <div class="mc-card-icon green"><i class="bi bi-calendar3"></i></div>
              <div><p class="mc-card-label">Período</p><p class="mc-card-val"><?= htmlspecialchars($periodo_info['periodo'] ?? '—') ?></p></div>
            </div>
            <div class="mc-card">
              <div class="mc-card-icon orange"><i class="bi bi-journal-text"></i></div>
              <div><p class="mc-card-label">Disponibles para pedir</p><p class="mc-card-val"><?php
                $partes_disp = [];
                if ($paquetes_pedido) $partes_disp[] = count($paquetes_pedido) . ' paquete' . (count($paquetes_pedido) === 1 ? '' : 's');
                if ($libros || !$paquetes_pedido) $partes_disp[] = count($libros) . ' libro' . (count($libros) === 1 ? '' : 's') . ' suelto' . (count($libros) === 1 ? '' : 's');
                echo implode(' · ', $partes_disp);
              ?></p></div>
            </div>
            <div class="mc-card">
              <div class="mc-card-icon blue"><i class="bi bi-boxes"></i></div>
              <div><p class="mc-card-label">Tipo de pedido</p><p class="mc-card-val"><?= htmlspecialchars(pedido_tipo_texto($opciones_pedido['tipo_pedido'])) ?></p></div>
            </div>
          </div>

<?php if ($periodo_info['periodo'] >= 2027 && !$tiene_archivo): ?>
  <div class="sop-no-arch">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span>Para solicitar el pedido debes adjuntar primero el acuerdo de adopción en la pestaña <strong>Adopciones</strong> del colegio.</span>
</div>
<?php endif; ?>

          <hr class="sop-divider" />

          <form action="php/pedido.php" method="POST">

            <!-- Datos del pedido -->
            <p class="sop-section-title"><i class="bi bi-file-text"></i> Datos del pedido</p>
            <div class="sop-grid">

              <div class="sop-field">
                <label class="sop-label">Cliente<span class="req">*</span></label>
                <select name="cliente" id="cliente" required>
                  <option value="">Seleccionar cliente</option>
                  <?php if ($cliente_sel): ?>
                  <option value="<?= $cliente_sel['id'] ?>" selected><?= htmlspecialchars($cliente_sel['cliente']) ?></option>
                  <?php endif; ?>
                </select>
              </div>

              <div class="sop-field">
                <label class="sop-label">Factura o Remisión<span class="req">*</span></label>
                <select name="fac_rem" id="fac_rem" class="sop-select" required>
                  <option value="">Seleccionar</option>
                  <option value="1">Factura</option>
                  <option value="2">Remisión</option>
                </select>
              </div>

            </div>

            <hr class="sop-divider" />

            <!-- Fecha, dirección y observaciones -->
            <p class="sop-section-title"><i class="bi bi-geo-alt"></i> Entrega y observaciones</p>
            <div class="sop-grid">
              <div class="sop-field">
                <label class="sop-label">Fecha de recogida<span class="req">*</span></label>
                <input type="date" class="sop-input" name="fecha_r" id="fecha_r" required />
                <p class="sop-hint" id="fecha_r_nota" style="display:none"></p>
              </div>
              <div class="sop-field sop-span2">
                <label class="sop-label">Dirección de entrega<span class="req">*</span></label>
                <input type="text" class="sop-input" name="dir_ent" id="dir_ent" placeholder="Dirección de entrega" required />
              </div>
              <div class="sop-field sop-full" id="obs_pedido_campo">
                <label class="sop-label">Observaciones</label>
                <textarea class="sop-textarea" name="observaciones" id="observaciones" placeholder="Escribe las observaciones..." maxlength="300" data-contador></textarea>
              </div>
            </div>

            <hr class="sop-divider" />

            <?php if (in_array($tipo_adop_ped, [2, 3], true)): ?>
            <!-- Paquetes (Tipo de adopción "Paquetes" o "Ambos"): cada paquete pedido lleva sus libros con la misma cantidad. -->
            <p class="sop-section-title"><i class="bi bi-box-seam"></i> Paquetes del pedido</p>
            <?php if (!$paquetes_pedido): ?>
              <div class="sop-no-arch"><i class="bi bi-exclamation-triangle-fill"></i>
                <span>La adopción es por <strong>paquetes</strong> pero aún no tiene paquetes guardados. Guárdalos en la pestaña <strong>Adopciones</strong> del colegio.</span></div>
            <?php else: ?>
            <div class="sp-books-wrap">
              <table class="sp-books-table">
                <thead>
                  <tr>
                    <th>Paquete</th>
                    <th>Grado</th>
                    <th>Títulos incluidos</th>
                    <th>Compradores activos</th>
                    <th>Cantidad de paquetes</th>
                    <?php if ($ver_plataforma): ?><th>Plataforma</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paquetes_pedido as $paq): $pk = 'paq' . $paq['id']; ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($paq['paquete']) ?></strong></td>
                    <td><?= htmlspecialchars($paq['grado'] ?: '—') ?></td>
                    <td>
                      <details class="sp-paq-libros">
                        <summary><?= count($paq['libros']) ?> título<?= count($paq['libros']) === 1 ? '' : 's' ?></summary>
                        <ul><?php foreach ($paq['libros'] as $pl): ?><li><?= htmlspecialchars($pl['libro']) ?></li><?php endforeach; ?></ul>
                      </details>
                    </td>
                    <td><?= $paq['comp_act'] ?: '—' ?></td>
                    <td>
                      <div class="sp-qty">
                        <button type="button" class="sp-qty-btn" data-paso="-1" aria-label="Restar" disabled>−</button>
                        <input type="number" id="cantidad<?= $pk ?>" class="sp-qty-input" value="0" min="0"
                               data-key="<?= $pk ?>" data-paquete="<?= $paq['id'] ?>" data-max="<?= $paq['comp_act'] ?>">
                        <button type="button" class="sp-qty-btn" data-paso="1" aria-label="Sumar">+</button>
                      </div>
                    </td>
                    <?php if ($ver_plataforma): ?>
                    <td><input type="checkbox" id="plat<?= $pk ?>" class="sp-plat-check" data-key="<?= $pk ?>"></td>
                    <?php endif; ?>
                    <td class="d-none"><input type="hidden" name="paquete[]" id="libro<?= $pk ?>"></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($tipo_adop_ped !== 2): ?>
            <!-- Libros sueltos (Tipo de adopción "Libros sueltos": todos los adoptados; "Ambos": los marcados "Libro suelto"). -->
            <p class="sop-section-title"><i class="bi bi-book"></i> <?= $tipo_adop_ped === 3 ? 'Libros sueltos del pedido' : 'Libros del pedido' ?></p>
            <?php if (!$libros): ?>
              <p class="sop-hint" style="margin-bottom:24px"><?= $tipo_adop_ped === 3 ? 'No hay títulos marcados como "Libro suelto" en la adopción.' : 'No hay títulos adoptados.' ?></p>
            <?php else: ?>
            <div class="sp-books-wrap">
              <table class="sp-books-table">
                <thead>
                  <tr>
                    <th>Título</th>
                    <th>Materia</th>
                    <th>Grado</th>
                    <th>Compradores activos</th>
                    <th>Cantidad</th>
                    <?php if ($ver_plataforma): ?><th>Plataforma</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($libros as $libro):
                    $key      = $libro["cod_area"] !== "" ? $libro["cod_area"] : $libro["id_libro"];
                    $has_area = $libro["cod_area"] !== "" ? "1" : "0";
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($libro["libro"]) ?></td>
                    <td><?= htmlspecialchars($libro["materia"]) ?></td>
                    <td><?= htmlspecialchars($libro["grado"]) ?></td>
                    <td><?= $libro["comp_act"] ?></td>
                    <td>
                      <div class="sp-qty">
                        <button type="button" class="sp-qty-btn" data-paso="-1" aria-label="Restar" disabled>−</button>
                        <input type="number" id="cantidad<?= $key ?>" class="sp-qty-input" value="0" min="0"
                               data-key="<?= $key ?>"
                               data-lib-id="<?= $libro['id_libro'] ?>"
                               data-has-area="<?= $has_area ?>"
                               data-max="<?= $libro["comp_act"] ?>">
                        <button type="button" class="sp-qty-btn" data-paso="1" aria-label="Sumar">+</button>
                      </div>
                    </td>
                    <?php if ($ver_plataforma): ?>
                    <td>
                      <input type="checkbox" id="plat<?= $key ?>" class="sp-plat-check" data-key="<?= $key ?>"<?= $libro['plataforma'] ? ' checked' : '' ?>>
                    </td>
                    <?php endif; ?>
                    <td class="d-none">
                      <input type="hidden" name="libro[]" id="libro<?= $key ?>">
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Hidden inputs -->
            <input type="hidden" name="id_colegio" value="<?= $id_colegio ?>">
            <input type="hidden" name="periodo"    value="<?= $periodo_id ?>">
<button type="submit" class="sop-btn-save" id="solicitar"
<?= ($periodo_info["periodo"] >= 2027 && !$tiene_archivo)
    ? 'disabled style="opacity:.45;cursor:not-allowed;pointer-events:none"'
    : '' ?>>
    <i class="bi bi-floppy2-fill"></i> Solicitar pedido
</button>

          </form>

        </div><!-- /.sop-body -->
      </div><!-- /.modern-card -->

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script>
$(document).ready(function () {
  $('#cliente').select2({
    placeholder: 'Seleccionar cliente',
    allowClear: true,
    width: '100%',
    minimumInputLength: 2,
    ajax: {
      url: 'ajax/buscar_clientes.php',
      dataType: 'json',
      delay: 300,
      data: function (params) { return { q: params.term }; },
      processResults: function (data) { return { results: data }; }
    },
    language: {
      inputTooShort: function () { return 'Escribe al menos 2 letras para buscar...'; },
      noResults:  function () { return 'Sin resultados'; },
      searching:  function () { return 'Buscando...'; }
    }
  });
});

/* ── Libros: poblado de hidden inputs ─────────────────────── */
function buildLibroVal(key) {
  var $qty   = $('#cantidad' + key);
  var libId  = $qty.data('lib-id');
  var hasArea = $qty.data('has-area') == '1';
  var cant   = parseInt($qty.val()) || 0;
  var plat   = $('#plat' + key).prop('checked') ? 1 : 0;
  // Paquete: "id_paquete/cantidad/plataforma" (php/pedido.php lo convierte en un renglón por libro del paquete).
  if ($qty.data('paquete')) return $qty.data('paquete') + '/' + cant + '/' + plat;
  return hasArea
    ? libId + '/' + cant + '/' + plat + '/' + key
    : libId + '/' + cant + '/' + plat;
}

// Actualiza en tiempo real (input cubre teclado + spinner + pegar)
$(document).on('input', '.sp-qty-input', function () {
  var key = $(this).data('key');
  var max = parseInt($(this).data('max')) || 0;
  var val = parseInt($(this).val()) || 0;
  if (max > 0 && val > max) {
    alert('La cantidad solicitada no puede ser mayor a los compradores activos');
    $(this).val(0);
  }
  $('#libro' + key).val(buildLibroVal(key));
  actualizarBotonesQty($(this));
});

/* ── Sube y baja de cantidades ── */
function actualizarBotonesQty($qty) {
  var max = parseInt($qty.data('max')) || 0;
  var val = parseInt($qty.val()) || 0;
  var $caja = $qty.closest('.sp-qty');
  $caja.find('[data-paso="-1"]').prop('disabled', val <= 0);
  $caja.find('[data-paso="1"]').prop('disabled', max > 0 && val >= max);
}
$(document).on('click', '.sp-qty-btn', function () {
  var $qty = $(this).siblings('.sp-qty-input');
  var max = parseInt($qty.data('max')) || 0;
  var val = (parseInt($qty.val()) || 0) + parseInt($(this).data('paso'));
  if (val < 0) val = 0;
  if (max > 0 && val > max) val = max;
  $qty.val(val).trigger('input');
});
// Al entrar a la casilla se quita el 0 para escribir directo; si se sale vacía vuelve a 0.
$(document).on('focus', '.sp-qty-input', function () {
  if ($(this).val() === '0') $(this).val('');
});
$(document).on('blur', '.sp-qty-input', function () {
  if ($(this).val() === '') $(this).val(0).trigger('input');
});
// La rueda del mouse no cambia la cantidad: suelta el campo y la página sigue bajando.
$(document).on('wheel', '.sp-qty-input', function () { $(this).blur(); });

$(document).on('change', '.sp-plat-check', function () {
  var key = $(this).data('key');
  $('#libro' + key).val(buildLibroVal(key));
});

// Garantiza que todos los hidden inputs estén poblados al enviar; debe ir al menos un paquete o libro.
$('form[action="php/pedido.php"]').on('submit', function (e) {
  var total = 0;
  $('.sp-qty-input').each(function () {
    var key = $(this).data('key');
    $('#libro' + key).val(buildLibroVal(key));
    total += parseInt($(this).val()) || 0;
  });
  if (total <= 0) {
    e.preventDefault();
    var msg = 'Indica la cantidad de al menos un paquete o libro.';
    if (typeof window.inkToast === 'function') window.inkToast(msg, 'warn'); else alert(msg);
    return false;
  }
});
</script>
<script src="src/contador-caracteres.js"></script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
