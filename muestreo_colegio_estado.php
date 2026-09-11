<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <?php if (isset($_GET["id_pedido"])): ?>
    <title>Inkpulse - Ver muestreo</title>
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
    @media print {
      .mc-actions, .left-side-bar, .header { display:none !important; }
      a[href]:after { content:none !important; }
      body { font-size:9px; }
      #mc-table thead, #mc-table tfoot { display: table-row-group !important; }
      .mc-table-wrap { overflow:visible !important; }
      .main-container, .pd-ltr-20 { overflow:visible !important; }
      table { page-break-inside: auto; }
      tr    { page-break-inside: avoid; }
      textarea { overflow:visible !important; white-space:pre-wrap !important; }
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
    /* Dirección en línea */
    .mc-card.full-width .mc-card-label { display:inline; margin:0 5px 0 0; }
    .mc-card.full-width .mc-card-label::after { content:':'; }
    .mc-card.full-width .mc-card-val   { display:inline; font-weight:500; font-size:.82rem; }

    /* ── OP badge ───────────────────────────────────── */
    .mc-op-badge {
      display: inline-flex; align-items: center; gap: 8px;
      background: #fefce8; border: 1.5px solid #fde047;
      border-radius: 8px; padding: 8px 16px;
      font-size: .88rem; font-weight: 600; color: #92400e;
      margin-bottom: 18px;
    }
    .mc-op-badge a { color: #b45309; font-weight: 700; text-decoration: underline; }

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
      width:100%; border-radius:8px; border:1.5px solid #e2e8f0;
      padding:8px 12px; font-size:.83rem; background:#f8fafc;
      color:#1e293b; resize:vertical; outline:none;
    }

    /* ── Acciones ───────────────────────────────────── */
    .mc-actions { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:4px; }
    .mc-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 22px; border-radius:8px; font-size:14px; font-weight:600;
      border:none; cursor:pointer; text-decoration:none;
      transition:opacity .15s, transform .1s;
    }
    .mc-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; color:#fff; }
    .mc-btn-teal { background:#0d9488; color:#fff; }
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
                  <i class="bi bi-box-seam" style="color:#4361ee;margin-right:6px"></i>
                  <?= isset($_GET["id_pedido"]) ? 'Ver muestreo' : 'Muestras entregadas' ?>
                </h4>
              </div>
              <?php if (isset($_GET["id_pedido"])): ?>
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="ver_muestreo.php">Muestreo</a></li>
                  <li class="breadcrumb-item active">Ver muestreo</li>
                </ol>
              </nav>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php
          include("conexion/bdd.php");

          if (isset($_GET["id_pedido"])) {
            $sql_pedido = "SELECT id FROM muestreos WHERE id='".$_GET["id_pedido"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_pedido = "SELECT pe.fecha, pe.observaciones, pe.estado as id_estado, pe.tipo as ptipo, z.zona, c.colegio, c.direccion, c.sub_zona, c.responsable, u.nombres, u.apellidos, u.tipo, e.estado FROM muestreos pe JOIN colegios c ON pe.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona JOIN usuarios u ON u.cod_zona=z.codigo JOIN estados_pedidos e ON e.id=pe.estado WHERE pe.id='".$pedido["id"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql = "SELECT pe.id, l.id, l.libro, lp.cantidad, lp.cantidad_aprob FROM muestreos pe LEFT JOIN libros_muestreos lp ON lp.cod_muestreo=pe.codigo LEFT JOIN libros l ON l.id=lp.id_libro WHERE pe.id='".$_GET["id_pedido"]."' GROUP BY l.id";
            $req = $bdd->prepare($sql); $req->execute();
          } else {
            $sql_pedido = "SELECT id FROM muestreos WHERE id='".$_GET["id_muestras_e"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql_pedido = "SELECT pe.fecha, pe.observaciones, pe.estado as id_estado, pe.tipo as ptipo, z.zona, c.colegio, c.direccion, c.sub_zona, c.responsable, u.nombres, u.apellidos, u.tipo, e.estado FROM muestreos_e pe JOIN colegios c ON pe.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona JOIN usuarios u ON u.cod_zona=z.codigo JOIN estados_pedidos e ON e.id=pe.estado WHERE pe.id='".$_GET["id_muestras_e"]."'";
            $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
            $pedido = $req_pedido->fetch();

            $sql = "SELECT pe.id, l.id, l.libro, lp.cantidad, lp.cantidad_aprob FROM muestreos_e pe LEFT JOIN libros_muestreos_e lp ON lp.cod_muestreo=pe.codigo LEFT JOIN libros l ON l.id=lp.id_libro WHERE pe.id='".$_GET["id_muestras_e"]."' GROUP BY l.id";
            $req = $bdd->prepare($sql); $req->execute();
          }

          $libros = $req->fetchAll();

          if (!isset($_GET["id_muestras_e"])) {
            $sql = "SELECT id, año FROM ordenes_pedidos WHERE id_muestreo='".$_GET["id_pedido"]."' AND estado!=4";
            $req_op = $bdd->prepare($sql); $req_op->execute();
            $op   = $req_op->rowCount();
            $n_op = $req_op->fetch();
          } else {
            $op = 0;
          }

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

          if ($pedido["ptipo"]==1){
            $pedido["ptipo"]="Docente";
          } elseif ($pedido["ptipo"]==2) {
            $pedido["ptipo"]="Estudiante";
          }else{
            $pedido["ptipo"]="";
          }
        ?>

        <!-- OP badge (si existe) -->
        <?php if ($op != 0): ?>
        <div class="mc-op-badge">
          <i class="bi bi-file-earmark-text"></i>
          OP asociada:
          <a href="op_pendiente.php?op=<?= $n_op["id"] ?>" target="_blank">
            # <?= htmlspecialchars($n_op["año"]) ?> — <?= htmlspecialchars($n_op["id"]) ?>
          </a>
        </div>
        <?php endif; ?>

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
            <div class="mc-card-icon teal"><i class="bi bi-journal"></i></div>
            <div>
              <p class="mc-card-label">Tipo</p>
              <p class="mc-card-val"><?= htmlspecialchars($pedido["ptipo"] ?? '—') ?></p>
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

        <!-- Tabla de libros -->
        <div class="mc-table-wrap">
          <table id="mc-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Título</th>
                <th>Cantidad</th>
                <?php if ($pedido["id_estado"] == 2 || $pedido["id_estado"] == 4): ?>
                  <th>Cantidad aprobada</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php
                $i = 1;
                foreach ($libros as $libro) {
                  $total_cantidad[]       = $libro["cantidad"];
                  $total_cantidad_aprob[] = $libro["cantidad_aprob"];
                  echo '<tr>';
                  echo '<td>'.($i++).'</td>';
                  echo '<td>'.htmlspecialchars($libro["libro"]).'</td>';
                  echo '<td style="text-align:center">'.$libro["cantidad"].'</td>';
                  if ($pedido["id_estado"] == 2 || $pedido["id_estado"] == 4) {
                    echo '<td style="text-align:center">'.$libro["cantidad_aprob"].'</td>';
                  }
                  echo '</tr>';
                }
                $total_c       = array_sum($total_cantidad);
                $total_c_aprob = array_sum($total_cantidad_aprob);
              ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2" style="text-align:right">Total</td>
                <td style="text-align:center"><?= $total_c ?></td>
                <?php if ($pedido["id_estado"] == 2 || $pedido["id_estado"] == 4): ?>
                  <td style="text-align:center"><?= $total_c_aprob ?></td>
                <?php endif; ?>
              </tr>
            </tfoot>
          </table>
        </div>

        <?php if (isset($_GET["id_pedido"]) && !empty($pedido["observaciones"])): ?>
        <div class="mc-obs-wrap">
          <p class="mc-obs-label"><i class="bi bi-chat-text"></i> Observaciones</p>
          <textarea rows="3" disabled><?= htmlspecialchars($pedido["observaciones"]) ?></textarea>
        </div>
        <?php endif; ?>

        <input type="hidden" name="id_colegio" value="<?= htmlspecialchars($_GET["id_colegio"] ?? '') ?>">
        <input type="hidden" name="periodo"    value="<?= htmlspecialchars($_GET["periodo"] ?? '') ?>">

        <div class="mc-actions d-print-none">
          <button type="button" id="imprimir" class="mc-btn mc-btn-teal">
            <i class="bi bi-printer"></i> Imprimir
          </button>
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
</body>
</html>
