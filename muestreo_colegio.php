<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <?php if (isset($_GET["id_pedido"])): ?>
    <title>Inkpulse - Muestreo pendiente</title>
  <?php else: ?>
    <title>Inkpulse - Muestras entregadas</title>
  <?php endif; ?>
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
    input[type=number] { -moz-appearance:textfield; }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    @media print {
      .mc-actions, .left-side-bar, .header { display:none !important; }
      a[href]:after { content:none !important; }
      body { font-size:9px; }
      .mc-obs-wrap textarea { height:auto !important; min-height:0 !important; overflow:visible !important; white-space:pre-wrap !important; page-break-inside:avoid; }
      #mc-table thead, #mc-table tfoot { display: table-row-group !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
    }

    /* ── Info table ─────────────────────────────────── */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 1px;
      background: #e2e8f0;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(15,23,42,.06);
    }
    @media (max-width: 767px) { .mc-cards { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 480px) { .mc-cards { grid-template-columns: repeat(2, 1fr); } }
    .mc-card {
      background: #fff;
      display: flex; align-items: center; gap: 9px;
      padding: 9px 13px;
    }
    .mc-card.full-width {
      grid-column: 1 / -1;
      background: #f8fafc;
    }
    .mc-card-icon {
      width: 30px; height: 30px; border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; flex-shrink: 0;
    }
    .mc-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .mc-card-icon.green  { background:#dcfce7; color:#15803d; }
    .mc-card-icon.orange { background:#ffedd5; color:#c2410c; }
    .mc-card-icon.purple { background:#ede9fe; color:#6d28d9; }
    .mc-card-icon.teal   { background:#ccfbf1; color:#0d9488; }
    .mc-card-label { font-size:.63rem; color:#94a3b8; margin:0 0 1px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .mc-card-val   { font-size:.82rem; font-weight:600; color:#0f172a; margin:0; }
    .mc-card.full-width .mc-card-label { display:inline; margin:0 5px 0 0; }
    .mc-card.full-width .mc-card-label::after { content:':'; }
    .mc-card.full-width .mc-card-val   { display:inline; font-weight:500; font-size:.82rem; }

    /* ── Tabla ──────────────────────────────────────── */
    .mc-table-wrap { border-radius:10px; overflow-x:auto; box-shadow:0 2px 10px rgba(15,23,42,.09); margin-bottom:24px; }
    #mc-table { width:100%; font-size:.83rem; border-collapse:collapse; }
    #mc-table thead th {
      background:#f8fafc; color:#374151; font-weight:600;
      padding:11px 12px; text-align:left; border:none;
      border-bottom:2px solid #e2e8f0; white-space:nowrap; font-size:.79rem;
    }
    #mc-table tbody tr              { background:#fff; }
    #mc-table tbody tr:nth-child(even) { background:#f8fafc; }
    #mc-table tbody tr:hover        { background:#eff6ff; }
    #mc-table tbody td {
      padding:9px 12px; border-bottom:1px solid #e2e8f0;
      color:#1e293b; vertical-align:middle;
    }
    #mc-table tbody td input[type="number"] {
      width:70px; padding:4px 8px; border:1.5px solid #d1d5db;
      border-radius:6px; font-size:.82rem; text-align:center;
      background:#f9fafb; outline:none; transition:border-color .15s;
    }
    #mc-table tbody td input[type="number"]:focus { border-color:#4361ee; background:#fff; }
    #mc-table tfoot td {
      padding:10px 12px; background:#f8fafc; color:#374151;
      font-weight:700; font-size:.83rem; border:none;
      border-top:2px solid #e2e8f0;
    }

    /* ── Observaciones ──────────────────────────────── */
    .mc-obs-wrap {
      background:#fff; border-radius:10px; padding:16px 20px;
      box-shadow:0 1px 6px rgba(15,23,42,.08); margin-bottom:20px;
    }
    .mc-obs-label {
      font-size:.78rem; font-weight:700; color:#374151;
      text-transform:uppercase; letter-spacing:.04em;
      display:flex; align-items:center; gap:6px; margin:0 0 10px 0;
    }
    .mc-obs-label i { color:#6366f1; }
    .mc-obs-wrap textarea {
      width:100%; border-radius:8px; border:1.5px solid #d1d5db;
      padding:10px 14px; font-size:.85rem; background:#f9fafb;
      color:#1e293b; resize:vertical; outline:none; transition:border-color .15s;
      min-height:150px;
    }
    .mc-obs-wrap textarea:focus { border-color:#6366f1; background:#fff; }

    /* ── Archivo adjunto ───────────────────────────── */
    .mc-file-wrap {
      background: #fff;
      border-radius: 10px;
      padding: 14px 20px;
      box-shadow: 0 1px 6px rgba(15,23,42,.08);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .mc-file-icon {
      width: 40px; height: 40px; border-radius: 10px;
      background: #eff6ff; color: #2563eb;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }
    .mc-file-label {
      font-size: .72rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .05em; margin: 0 0 3px;
    }
    .mc-file-link {
      font-size: .875rem; font-weight: 600; color: #2563eb;
      text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
    }
    .mc-file-link:hover { text-decoration: underline; color: #1d4ed8; }

    /* ── Acciones ───────────────────────────────────── */
    .mc-actions { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:4px; }
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 22px; border-radius:8px; font-size:14px; font-weight:600;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; color:#fff; }
    .mc-btn-teal  { background:#0d9488; color:#fff; }
    .mc-btn-green { background:#16a34a; color:#fff; }
    .mc-btn-red   { background:#dc2626; color:#fff; }
  </style>
</head>
<body>
  <?php include("template/nav_side.php"); ?>
  <div class="main-container">
    <div class="pd-ltr-20 xs-pd-20-10">
      <div class="min-height-200px">

        <div class="page-header">
          <div class="row align-items-center">
            <div class="col-md-8 col-sm-12">
              <div class="title">
                <h4>
                  <?php if (isset($_GET["id_pedido"])): ?>
                    <i class="bi bi-hourglass-split" style="color:#b45309;margin-right:6px"></i>Muestreo pendiente
                  <?php else: ?>
                    <i class="bi bi-box-seam" style="color:#4361ee;margin-right:6px"></i>Muestras entregadas
                  <?php endif; ?>
                </h4>
              </div>
              <?php if (isset($_GET["id_pedido"])): ?>
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="lista_muestreo.php?tp=<?= intval($_GET['tp'] ?? 2) ?>">Muestreo</a></li>
                  <li class="breadcrumb-item active">Pendiente</li>
                </ol>
              </nav>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php
          if (isset($_GET["id_muestreo"])) {
            $_GET["id_pedido"] = $_GET["id_muestreo"];
          }

          if (isset($_GET["id_pedido"])) {
            $sql_pedido = "SELECT id FROM muestreos WHERE id='".$_GET["id_pedido"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_pedido = "SELECT pe.id, pe.id_periodo, pe.id_colegio, pe.fecha, pe.observaciones, pe.archivo, z.zona, c.colegio, c.direccion, c.sub_zona, c.responsable, cal.calendario, u.nombres, u.apellidos, u.tipo, e.estado FROM muestreos pe JOIN colegios c ON pe.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona JOIN usuarios u ON u.cod_zona=z.codigo JOIN estados_pedidos e ON e.id=pe.estado LEFT JOIN calendarios cal ON c.id_calendario=cal.id WHERE pe.id='".$pedido["id"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_repetido = "SELECT id FROM muestreos WHERE id_periodo='".$pedido["id_periodo"]."' AND id_colegio='".$pedido["id_colegio"]."' AND estado='4'";
            $req_repetido = $bdd->prepare($sql_repetido); $req_repetido->execute();
            $num_repetido = $req_repetido->rowCount();
            $n_repetido   = $req_repetido->fetchAll();

            $sql = "SELECT pe.id, l.id, l.libro, lp.cantidad, lp.id as id_lm, l.isbn, m.materia, g.id as id_grado, g.grado FROM muestreos pe LEFT JOIN libros_muestreos lp ON lp.cod_muestreo=pe.codigo LEFT JOIN libros l ON l.id=lp.id_libro LEFT JOIN materias m ON m.id=l.id_materia LEFT JOIN grados g ON g.id=l.id_grado WHERE pe.id='".$_GET["id_pedido"]."' GROUP BY l.id";
            $req = $bdd->prepare($sql); $req->execute();
          } else {
            $sql_pedido = "SELECT id FROM muestreos_e WHERE id='".$_GET["id_muestras_e"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_pedido = "SELECT pe.id, pe.id_periodo, pe.id_colegio, pe.fecha, pe.observaciones, pe.archivo, z.zona, c.colegio, c.direccion, c.sub_zona, c.responsable, cal.calendario, u.nombres, u.apellidos, u.tipo, e.estado FROM muestreos_e pe JOIN colegios c ON pe.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona JOIN usuarios u ON u.cod_zona=z.codigo JOIN estados_pedidos e ON e.id=pe.estado LEFT JOIN calendarios cal ON c.id_calendario=cal.id WHERE pe.id='".$_GET["id_muestras_e"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_repetido = "SELECT id FROM muestreos_e WHERE id_periodo='".$pedido["id_periodo"]."' AND id_colegio='".$_GET["id_muestras_e"]."'";
            $req_repetido = $bdd->prepare($sql_repetido); $req_repetido->execute();
            $num_repetido = $req_repetido->rowCount();
            $n_repetido   = $req_repetido->fetchAll();

            $sql = "SELECT pe.id, l.id, l.libro, lp.cantidad, lp.id as id_lm, l.isbn, m.materia, g.id as id_grado, g.grado FROM muestreos_e pe LEFT JOIN libros_muestreos_e lp ON lp.cod_muestreo=pe.codigo LEFT JOIN libros l ON l.id=lp.id_libro LEFT JOIN materias m ON m.id=l.id_materia LEFT JOIN grados g ON g.id=l.id_grado WHERE pe.id='".$_GET["id_muestras_e"]."' GROUP BY l.id";
            $req = $bdd->prepare($sql); $req->execute();
          }

          $libros = $req->fetchAll();

          if ($pedido["tipo"] == 3) {
            list($empresa, $n_zona) = explode("/", $pedido["zona"]);
            $responsable = $pedido["nombres"]." ".$pedido["apellidos"];
          } else {
            $sql_sz = "SELECT sub_zona FROM sub_zonas WHERE id='".$pedido["sub_zona"]."'";
            $req_sz = $bdd->prepare($sql_sz); $req_sz->execute();
            $sub_zona    = $req_sz->fetch();
            $empresa     = $pedido["nombres"]." ".$pedido["apellidos"];
            $n_zona      = $sub_zona["sub_zona"] ?? '—';
            $responsable = $pedido["responsable"];
          }

          $id_disp = isset($_GET["id_pedido"]) ? $_GET["id_pedido"] : ($_GET["id_muestras_e"] ?? '—');
        ?>

        <!-- Tarjetas informativas -->
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-box-seam"></i></div>
            <div>
              <p class="mc-card-label"># Muestreo</p>
              <p class="mc-card-val"><?= htmlspecialchars($id_disp) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
            <div>
              <p class="mc-card-label">Colegio</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["colegio"]) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-calendar2-week"></i></div>
            <div>
              <p class="mc-card-label">Calendario</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["calendario"] ?? '—') ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div>
              <p class="mc-card-label">Fecha</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["fecha"]) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon purple"><i class="bi bi-flag-fill"></i></div>
            <div>
              <p class="mc-card-label">Estado</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["estado"]) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon purple"><i class="bi bi-person-fill"></i></div>
            <div>
              <p class="mc-card-label">Promotor / Empresa</p>
              <p class="mc-card-val"><?= htmlspecialchars($empresa) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-geo-alt-fill"></i></div>
            <div>
              <p class="mc-card-label">Zona</p>
              <p class="mc-card-val"><?= htmlspecialchars($n_zona) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-person-badge"></i></div>
            <div>
              <p class="mc-card-label">Responsable</p>
              <p class="mc-card-val"><?= htmlspecialchars($responsable) ?></p>
            </div>
          </div>
          <?php if (!empty($pedido["direccion"])): ?>
          <div class="mc-card full-width">
            <div class="mc-card-icon orange"><i class="bi bi-map"></i></div>
            <div>
              <p class="mc-card-label">Dirección</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["direccion"]) ?></p>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($pedido['archivo'])): ?>
        <div class="mc-file-wrap">
          <div class="mc-file-icon"><i class="bi bi-paperclip"></i></div>
          <div>
            <p class="mc-file-label">Archivo adjunto</p>
            <a class="mc-file-link" href="<?= htmlspecialchars($pedido['archivo']) ?>" target="_blank">
              <i class="bi bi-file-earmark-arrow-down"></i>
              <?= htmlspecialchars(basename($pedido['archivo'])) ?>
            </a>
          </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de libros -->
        <form action="php/aprobar_muestreo.php" method="POST" id="form_aprobar">
          <div class="mc-table-wrap">
            <table id="mc-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ISBN</th>
                  <th>Título</th>
                  <th>Ubicación</th>
                  <th>Materia</th>
                  <th>Grado</th>
                  <?php if (isset($_GET["id_pedido"])): ?>
                    <th>Cantidad solicitada</th>
                    <th>Cantidad aprobada</th>
                  <?php else: ?>
                    <th>Cantidad entregada</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                  $i = 1;
                  foreach ($libros as $libro) {
                    $total_cantidad[] = $libro["cantidad"];

                    $su = $bdd->prepare(
                      "SELECT l.id_tipo, l.lugar, u.piso, u.ubicacion, lu.posicion
                       FROM lugares l
                       JOIN ubicaciones u      ON l.id = u.id_lugar
                       JOIN libros_ubicaciones lu ON u.id = lu.ubicacion
                       WHERE lu.id_libro = ?"
                    );
                    $su->execute([$libro["id"]]);
                    $ubs = $su->fetchAll();
                    $ubi = '';
                    foreach ($ubs as $ub)
                      $ubi .= ($ub['id_tipo'] == 1)
                        ? $ub['lugar'].$ub['piso'].' Pallet '.$ub['ubicacion'].' '.$ub['posicion'].', '
                        : $ub['lugar'].' Bandeja '.$ub['ubicacion'].', ';
                    $ubi = rtrim($ubi, ', ');

                    echo '<tr>';
                    echo '<td>'.($i++).'</td>';
                    echo '<td>'.htmlspecialchars($libro["isbn"]).'</td>';
                    echo '<td>'.htmlspecialchars($libro["libro"]).'</td>';
                    echo '<td>'.htmlspecialchars($ubi).'</td>';
                    echo '<td>'.htmlspecialchars($libro["materia"]).'</td>';
                    echo '<td>'.htmlspecialchars($libro["grado"]).'</td>';
                    echo '<td style="text-align:center">'.$libro["cantidad"].'</td>';
                    if (isset($_GET["id_pedido"])) {
                      echo '<td style="text-align:center"><input type="number" id="cantidad_aprob'.$libro["id_lm"].'" value="0" required></td>';
                      echo '<input type="hidden" name="libro_m[]" id="libro_m'.$libro["id_lm"].'">';
                      echo "<script>
                        $('#cantidad_aprob".$libro["id_lm"]."').keyup(function(){
                          var cant = $('#cantidad_aprob".$libro["id_lm"]."').val();
                          $('#libro_m".$libro["id_lm"]."').val(".$libro["id_lm"]."+'/' + cant);
                        });
                      </script>";
                    }
                    echo '</tr>';
                  }
                  echo '<input type="hidden" name="id_muestreo" value="'.$pedido["id"].'">';
                  $total_c = array_sum($total_cantidad);
                ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="6" style="text-align:right">Total</td>
                  <td style="text-align:center"><?= $total_c ?></td>
                  <?php if (isset($_GET["id_pedido"])): ?><td></td><?php endif; ?>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if (isset($_GET["id_pedido"])): ?>
          <div class="mc-obs-wrap">
            <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
            <textarea name="observaciones" id="observaciones" rows="6"><?= htmlspecialchars($pedido["observaciones"]) ?></textarea>
          </div>
          <?php endif; ?>

          <input type="hidden" name="id_colegio" value="<?= htmlspecialchars($_GET["id_colegio"] ?? '') ?>">
          <input type="hidden" name="periodo"    value="<?= htmlspecialchars($_GET["periodo"] ?? '') ?>">

          <div class="mc-actions d-print-none">
            <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
              <i class="bi bi-printer"></i> Imprimir
            </button>
            <?php if (isset($_GET["id_pedido"])): ?>
              <button type="submit" class="mc-btn mc-btn-green">
                <i class="bi bi-check-circle"></i> Aprobar
              </button>
              <a class="mc-btn mc-btn-red" id="rechazar" style="cursor:pointer">
                <i class="bi bi-x-circle"></i> Rechazar
              </a>
            <?php endif; ?>
          </div>
        </form>

      </div>
      <?php include("template/footer.php"); ?>
    </div>
  </div>

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>
  <script>
    $("#rechazar").click(function(){
      inkConfirm({
        title: '¿Rechazar este muestreo?',
        text:  'El muestreo pasará al estado Rechazado.',
        type:  'danger',
        btnOk: 'Sí, rechazar'
      }, function(){
        window.location = "php/accion_muestreo.php?rechazar=<?= $_GET['id_pedido'] ?? '' ?>";
      });
    });

    $("#form_aprobar").on('submit', function(e){
      e.preventDefault();
      var form = this;
      inkConfirm({
        title: '¿Aprobar este muestreo?',
        text:  'Se registrarán las cantidades aprobadas y el muestreo quedará en estado Aprobado.',
        type:  'info',
        btnOk: 'Sí, aprobar'
      }, function(){ form.submit(); });
    });

    window.addEventListener('beforeprint', function () {
      document.querySelectorAll('textarea').forEach(function (ta) {
        ta._ph = ta.style.height;
        ta.style.setProperty('height', ta.scrollHeight + 'px', 'important');
      });
    });
    window.addEventListener('afterprint', function () {
      document.querySelectorAll('textarea').forEach(function (ta) {
        ta.style.height = ta._ph || '';
      });
    });
    $("#imprimir").click(function(){
      window.print();
    });
  </script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
