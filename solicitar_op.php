<?php require_once("php/aut.php"); ?>
<?php require_once("conexion/bdd.php"); ?>
<?php
/* -- Data fetch ------------------------------------------------ */
if (isset($_GET['id_pedido'])) {
  $sql_pedido = "SELECT pe.fecha,pe.observaciones,pe.fecha_r, pe.cliente, z.zona, c.colegio, u.nombres, u.apellidos
                 FROM pedidos pe JOIN colegios c ON pe.id_colegio=c.id
                 JOIN zonas z ON z.codigo=c.cod_zona
                 JOIN usuarios u ON u.cod_zona=z.codigo
                 WHERE pe.id='".$_GET['id_pedido']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();

  // El pedido de venta ya trae su cliente (elegido al solicitarlo); se usa como
  // valor de partida aquí, editable igual que en los demás orígenes.
  $cliente_predet = !empty($pedido['cliente']) ? $pedido['cliente'] : '';
}
if (isset($_GET['id_pedido_dist'])) {
  $sql_pedido = "SELECT pe.fecha,pe.observaciones,pe.fecha_r, pe.colegio, u.nombres, u.apellidos
                 FROM pedidos2 pe JOIN usuarios u ON u.id=pe.id_usuario
                 WHERE pe.id='".$_GET['id_pedido_dist']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();
}
if (isset($_GET['id_muestreo'])) {
  $sql_pedido = "SELECT pe.fecha,pe.observaciones, pe.id_colegio, pe.id_periodo, z.zona, c.colegio, c.responsable, u.nombres, u.apellidos
                 FROM muestreos pe JOIN colegios c ON pe.id_colegio=c.id
                 JOIN zonas z ON z.codigo=c.cod_zona
                 JOIN usuarios u ON u.cod_zona=z.codigo
                 WHERE pe.id='".$_GET['id_muestreo']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();

  // El cliente de muestras no está ligado al colegio ni a sus ventas: es una
  // cuenta por promotor y por temporada, con el patrón "AA{año}-{PROMOTOR} - MUESTRAS"
  // (ej. "AA27- JAIRO RICO - MUESTRA" para la temporada 2027). Se busca la cuenta
  // de ese promotor para la temporada del muestreo actual.
  $cliente_predet = '';
  if ($pedido && !empty($pedido['id_periodo']) && !empty($pedido['nombres'])) {
    $req_periodo = $bdd->prepare("SELECT periodo FROM periodos WHERE id=:id_periodo");
    $req_periodo->execute([':id_periodo' => $pedido['id_periodo']]);
    $periodo_row = $req_periodo->fetch();
    if ($periodo_row) {
      preg_match('/\d{4}/', $periodo_row['periodo'], $m);
      if ($m) {
        $anio = $m[0];
        $aa = 'AA'.substr($anio, 2, 2);
        // La temporada se identifica como "AA{yy}" (ej. AA27) o con el año completo
        // (ej. 2027), según cómo se haya nombrado la cuenta de ese promotor.
        $sql_cliente = "SELECT id FROM clientes
                        WHERE cliente LIKE '%MUESTRA%'
                          AND (cliente LIKE :aa OR cliente LIKE :anio)
                          AND cliente LIKE :nombres
                          AND cliente LIKE :apellidos
                        ORDER BY id DESC LIMIT 1";
        $req_cliente = $bdd->prepare($sql_cliente);
        $req_cliente->execute([
          ':aa'        => '%'.$aa.'%',
          ':anio'      => '%'.$anio.'%',
          ':nombres'   => '%'.$pedido['nombres'].'%',
          ':apellidos' => '%'.$pedido['apellidos'].'%',
        ]);
        $ult_cliente = $req_cliente->fetch();
        if ($ult_cliente) $cliente_predet = $ult_cliente['id'];
      }
    }
  }

  // Si no hay una cuenta de "MUESTRAS {temporada}" para ese promotor, se busca
  // un cliente cuyo nombre coincida con el del promotor.
  if ($cliente_predet === '' && !empty($pedido['nombres']) && !empty($pedido['apellidos'])) {
    $sql_cliente2 = "SELECT id FROM clientes
                     WHERE cliente LIKE :nombres AND cliente LIKE :apellidos
                     ORDER BY id DESC LIMIT 1";
    $req_cliente2 = $bdd->prepare($sql_cliente2);
    $req_cliente2->execute([
      ':nombres'   => '%'.$pedido['nombres'].'%',
      ':apellidos' => '%'.$pedido['apellidos'].'%',
    ]);
    $c2 = $req_cliente2->fetch();
    if ($c2) $cliente_predet = $c2['id'];
  }

  // Último respaldo: coincidencia con el responsable del colegio.
  if ($cliente_predet === '' && !empty($pedido['responsable'])) {
    $palabras = array_values(array_filter(
      explode(' ', trim($pedido['responsable'])),
      function ($p) { return mb_strlen($p) >= 3; }
    ));
    if ($palabras) {
      $conds = []; $params = [];
      foreach ($palabras as $i => $palabra) {
        $conds[] = "cliente LIKE :p$i";
        $params[":p$i"] = '%'.$palabra.'%';
      }
      $sql_cliente3 = "SELECT id FROM clientes WHERE ".implode(' AND ', $conds)." ORDER BY id DESC LIMIT 1";
      $req_cliente3 = $bdd->prepare($sql_cliente3);
      $req_cliente3->execute($params);
      $c3 = $req_cliente3->fetch();
      if ($c3) $cliente_predet = $c3['id'];
    }
  }
}
if (isset($_GET['id_devol_c'])) {
  $sql_pedido = "SELECT pe.fecha,pe.observaciones, c.cliente
                 FROM devoluciones pe JOIN clientes c ON pe.persona=c.id
                 WHERE pe.id='".$_GET['id_devol_c']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();
}
if (isset($_GET['id_devol_p'])) {
  $sql_pedido = "SELECT pe.fecha, pe.observaciones, p.proveedor AS cliente
                 FROM devoluciones_prov pe JOIN proveedores p ON pe.persona = p.id
                 WHERE pe.id='".$_GET['id_devol_p']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();
}
if (isset($_GET['id_devol_v'])) {
  $sql_pedido = "SELECT pe.fecha,pe.observaciones, c.cliente
                 FROM devoluciones_v pe JOIN clientes c ON pe.cliente=c.id
                 WHERE pe.id='".$_GET['id_devol_v']."'";
  $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
  $pedido = $req_pedido->fetch();
}
if (isset($_POST['pedidos_agp'])) {
  foreach ($_POST['pedidos_agp'] as $pedido_agp) {
    $sql_pedido = "SELECT pe.cliente, u.nombres, u.apellidos, z.zona
                   FROM pedidos pe JOIN colegios c ON pe.id_colegio=c.id
                   JOIN zonas z ON z.codigo=c.cod_zona
                   JOIN usuarios u ON u.cod_zona=z.codigo
                   WHERE pe.id='".$pedido_agp."'";
    $req_pedido = $bdd->prepare($sql_pedido); $req_pedido->execute();
    $pedido = $req_pedido->fetch();
    break;
  }
  $cliente_predet = !empty($pedido['cliente']) ? $pedido['cliente'] : '';
}

$show_archivo = !isset($_GET['id_pedido']) && !isset($_GET['id_pedido_dist'])
             && !isset($_GET['id_devol_c'])  && !isset($_GET['id_devol_p'])
             && !isset($_GET['id_devol_v'])  && !isset($_GET['id_muestreo'])
             && !isset($_POST['pedidos_agp']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Solicitar OP</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32"  href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16"  href="vendors/images/favicon-16x16.png" />
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

    /* -- Info cards ------------------------------------------- */
    .mc-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-bottom: 22px;
    }
    .mc-card {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 10px; padding: 14px 16px;
      display: flex; align-items: flex-start; gap: 12px;
      min-width: 0;
    }
    .mc-card-icon { margin-top: 2px; }
    .mc-card-text { min-width: 0; flex: 1; }
    .mc-card-icon {
      width: 40px; height: 40px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.05rem; flex-shrink: 0;
    }
    .mc-card-icon.blue   { background:#dbeafe; color:#1d4ed8; }
    .mc-card-icon.green  { background:#dcfce7; color:#15803d; }
    .mc-card-icon.orange { background:#ffedd5; color:#c2410c; }
    .mc-card-icon.purple { background:#ede9fe; color:#6d28d9; }
    .mc-card-icon.teal   { background:#ccfbf1; color:#0d9488; }
    .mc-card-icon.amber  { background:#fef3c7; color:#b45309; }
    .mc-card-label { font-size:.68rem; color:#64748b; margin:0 0 3px 0; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .mc-card-val   { font-size:.88rem; font-weight:700; color:#0f172a; margin:0; word-break:break-word; overflow-wrap:break-word; line-height:1.35; }

    /* -- Card header ------------------------------------------ */
    .sop-card-head {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 24px 16px;
      border-bottom: 1px solid #e2e8f0;
    }
    .sop-card-icon {
      width: 46px; height: 46px; border-radius: 12px;
      background: linear-gradient(135deg, #4361ee 0%, #6d28d9 100%);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; color: #fff; flex-shrink: 0;
    }
    .sop-card-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; }

    /* -- Cuerpo con padding ----------------------------------- */
    .sop-body { padding: 24px; }

    /* -- Texto largo: wrap ------------------------------------ */
    .mc-card-val { word-break: break-word; overflow-wrap: break-word; }

    /* -- Divider ---------------------------------------------- */
    .sop-divider { border: none; border-top: 1px solid #e2e8f0; margin: 0 0 24px; }

    /* -- Form grid -------------------------------------------- */
    .sop-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px 22px;
      margin-bottom: 22px;
    }
    .sop-span2 { grid-column: span 2; }
    .sop-full  { grid-column: 1 / -1; }
    @media (max-width: 768px) {
      .sop-grid { grid-template-columns: 1fr; }
      .sop-span2, .sop-full { grid-column: 1; }
    }

    .sop-field { display: flex; flex-direction: column; gap: 6px; }
    .sop-label {
      font-size: .73rem; font-weight: 600; color: #374151;
      text-transform: uppercase; letter-spacing: .04em; margin: 0;
    }
    .sop-label .req { color: #ef4444; margin-left: 2px; }
    .sop-input, .sop-select, .sop-textarea {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: .875rem; color: #1e293b; background: #f9fafb;
      outline: none; transition: border-color .15s, box-shadow .15s;
      font-family: inherit; box-sizing: border-box;
    }
    .sop-input:focus, .sop-select:focus, .sop-textarea:focus {
      border-color: #4361ee; background: #fff;
      box-shadow: 0 0 0 3px rgba(67,97,238,.12);
    }
    .sop-select {
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center;
      padding-right: 36px; cursor: pointer;
    }
    .sop-textarea { resize: vertical; min-height: 96px; }
    .sop-input[type="file"] { padding: 8px 12px; cursor: pointer; }

    /* -- Select2 para Cliente y Tipo de documento -------------- */
    #cliente + .select2-container,
    #tipo_doc + .select2-container { width: 100% !important; }
    #cliente + .select2-container .select2-selection--single,
    #tipo_doc + .select2-container .select2-selection--single {
      height: 42px; border: 1.5px solid #d1d5db; border-radius: 8px;
      background: #f9fafb; display: flex; align-items: center;
      padding: 0 14px; font-size: .875rem; color: #1e293b;
      transition: border-color .15s, box-shadow .15s;
    }
    #cliente + .select2-container .select2-selection--single .select2-selection__rendered,
    #tipo_doc + .select2-container .select2-selection--single .select2-selection__rendered {
      padding: 0; line-height: normal; color: #1e293b; font-size: .875rem;
    }
    #cliente + .select2-container .select2-selection--single .select2-selection__placeholder,
    #tipo_doc + .select2-container .select2-selection--single .select2-selection__placeholder {
      color: #9ca3af;
    }
    #cliente + .select2-container .select2-selection--single .select2-selection__arrow,
    #tipo_doc + .select2-container .select2-selection--single .select2-selection__arrow {
      height: 40px; right: 10px;
    }
    #cliente + .select2-container--open .select2-selection--single,
    #cliente + .select2-container--focus .select2-selection--single,
    #tipo_doc + .select2-container--open .select2-selection--single,
    #tipo_doc + .select2-container--focus .select2-selection--single {
      border-color: #4361ee; background: #fff;
      box-shadow: 0 0 0 3px rgba(67,97,238,.12);
    }
    .select2-dropdown { border: 1.5px solid #d1d5db; border-radius: 8px; box-shadow: 0 4px 20px rgba(15,23,42,.10); }
    .select2-search--dropdown { padding: 8px; }
    .select2-search--dropdown .select2-search__field {
      border: 1.5px solid #d1d5db; border-radius: 7px;
      padding: 7px 12px; font-size: .85rem; outline: none;
    }
    .select2-search--dropdown .select2-search__field:focus { border-color: #4361ee; }
    .select2-results__option { font-size: .875rem; padding: 8px 14px; }
    .select2-results__option--highlighted { background: #4361ee !important; }

    /* -- Guardar button --------------------------------------- */
    .sop-actions { display: flex; justify-content: center; padding-top: 8px; }
    .sop-btn-save {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 12px 40px; border-radius: 9px;
      background: linear-gradient(135deg, #4361ee 0%, #6d28d9 100%);
      color: #fff; font-size: .92rem; font-weight: 600;
      border: none; cursor: pointer;
      box-shadow: 0 4px 14px rgba(67,97,238,.3);
      transition: opacity .15s, transform .1s;
    }
    .sop-btn-save:hover { opacity: .9; transform: translateY(-1px); }
    .sop-btn-save:active { transform: translateY(0); }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class=”page-header”>
        <div class=”row”>
          <div class=”col-md-6 col-sm-12”>
            <div class=”title”><h4>Solicitar OP</h4></div>
            <nav aria-label=”breadcrumb”>
              <ol class=”breadcrumb”>
                <li class=”breadcrumb-item”><a href=”lista_op.php”>OP</a></li>
                <li class=”breadcrumb-item active” aria-current=”page”>Solicitar</li>
              </ol>
            </nav>
          </div>
        </div>
      </div>

      <div class="modern-card">

        <!-- Card header -->
        <div class="sop-card-head">
          <div class="sop-card-icon"><i class="bi bi-file-earmark-check"></i></div>
          <p class="sop-card-title">Información de la solicitud</p>
        </div>

        <div class="sop-body">

        <!-- Info cards por tipo de origen -->
        <?php if (isset($_GET['id_pedido'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Pedido de venta</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_pedido']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Colegio</p><p class="mc-card-val"><?= htmlspecialchars($pedido['colegio']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon purple"><i class="bi bi-geo-alt"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Zona</p><p class="mc-card-val"><?= htmlspecialchars($pedido['zona']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-person"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Promotor</p><p class="mc-card-val"><?= htmlspecialchars($pedido['nombres'].' '.$pedido['apellidos']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon amber"><i class="bi bi-calendar-check"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha de recogida</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_r']) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['id_pedido_dist'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Pedido sin adopción</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_pedido_dist']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Colegio</p><p class="mc-card-val"><?= htmlspecialchars($pedido['colegio']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-person"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Usuario</p><p class="mc-card-val"><?= htmlspecialchars($pedido['nombres'].' '.$pedido['apellidos']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon amber"><i class="bi bi-calendar-check"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha de recogida</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha_r']) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['id_muestreo'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Muestreo</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_muestreo']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-building"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Colegio</p><p class="mc-card-val"><?= htmlspecialchars($pedido['colegio']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon purple"><i class="bi bi-geo-alt"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Zona</p><p class="mc-card-val"><?= htmlspecialchars($pedido['zona']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-person"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Promotor</p><p class="mc-card-val"><?= htmlspecialchars($pedido['nombres'].' '.$pedido['apellidos']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon amber"><i class="bi bi-person-badge"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Responsable</p><p class="mc-card-val"><?= htmlspecialchars($pedido['responsable'] ?: '—') ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['id_devol_c'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Devolución de cliente</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_devol_c']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-person-vcard"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Cliente</p><p class="mc-card-val"><?= htmlspecialchars($pedido['cliente']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['id_devol_p'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Devolución de proveedor</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_devol_p']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-person-vcard"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Proveedor</p><p class="mc-card-val"><?= htmlspecialchars($pedido['cliente']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['id_devol_v'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-hash"></i></div>
            <div class="mc-card-text"><p class="mc-card-label"># Devolución de venta</p><p class="mc-card-val"><?= htmlspecialchars($_GET['id_devol_v']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon green"><i class="bi bi-person-vcard"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Cliente</p><p class="mc-card-val"><?= htmlspecialchars($pedido['cliente']) ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon orange"><i class="bi bi-calendar3"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Fecha</p><p class="mc-card-val"><?= htmlspecialchars($pedido['fecha']) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_POST['pedidos_agp'])): ?>
        <div class="mc-cards">
          <div class="mc-card">
            <div class="mc-card-icon blue"><i class="bi bi-collection"></i></div>
            <div>
              <p class="mc-card-label"># Pedidos agrupados</p>
              <p class="mc-card-val"><?= implode(', ', array_map('htmlspecialchars', $_POST['pedidos_agp'])) ?></p>
            </div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon purple"><i class="bi bi-geo-alt"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Zona</p><p class="mc-card-val"><?= htmlspecialchars($pedido['zona'] ?? '—') ?></p></div>
          </div>
          <div class="mc-card">
            <div class="mc-card-icon teal"><i class="bi bi-person"></i></div>
            <div class="mc-card-text"><p class="mc-card-label">Promotor</p><p class="mc-card-val"><?= htmlspecialchars(($pedido['nombres'] ?? '').' '.($pedido['apellidos'] ?? '')) ?></p></div>
          </div>
        </div>
        <?php endif; ?>

        <hr class="sop-divider" />

        <!-- Form -->
        <form name="crear_colegio" role="form" action="php/crear_op.php" method="POST" enctype="multipart/form-data">

          <div class="sop-grid">

            <!-- Tipo de documento -->
            <div class="sop-field">
              <label class="sop-label">Tipo de documento<span class="req">*</span></label>
              <select class="sop-select" name="tipo_doc" id="tipo_doc" required>
                <option value="">Seleccionar</option>
                <?php
                  $sql = "SELECT * FROM tipo_doc WHERE act=1";
                  $req = $bdd->prepare($sql); $req->execute();
                  foreach ($req->fetchAll() as $td):
                ?>
                <option value="<?= $td['id'] ?>"><?= htmlspecialchars($td['tipo'].' ('.$td['descrip'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Cliente -->
            <div class="sop-field">
              <label class="sop-label">Cliente<span class="req">*</span></label>
              <select name="cliente" id="cliente" required>
                <option value="">Seleccionar</option>
                <?php
                  $sql = "SELECT * FROM clientes";
                  $req = $bdd->prepare($sql); $req->execute();
                  foreach ($req->fetchAll() as $cl):
                ?>
                <option value="<?= $cl['id'] ?>" <?= (isset($cliente_predet) && $cliente_predet !== '' && $cliente_predet == $cl['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cl['cliente']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Contacto -->
            <div class="sop-field">
              <label class="sop-label">Contacto</label>
              <input type="text" class="sop-input" name="solicitante" id="solicitante" placeholder="Contacto" value="<?= isset($_GET['id_muestreo']) ? htmlspecialchars($pedido['responsable'] ?? '') : '' ?>" />
            </div>

            <?php if ($show_archivo): ?>
            <!-- Archivo adjunto -->
            <div class="sop-field">
              <label class="sop-label">Archivo adjunto</label>
              <input type="file" class="sop-input" name="archivo" id="archivo" />
            </div>
            <!-- Ciudad destino (con archivo: 1 col) -->
            <div class="sop-field">
              <label class="sop-label">Ciudad destino<span class="req">*</span></label>
              <input type="text" class="sop-input" name="ciudad_d" id="ciudad_d" placeholder="Ciudad destino" required />
            </div>
            <!-- Observaciones (con archivo: 1 col) -->
            <div class="sop-field">
              <label class="sop-label">Observaciones</label>
              <textarea class="sop-textarea" name="observaciones" id="observaciones" rows="4" placeholder="Escribe las observaciones..."></textarea>
            </div>
            <?php else: ?>
            <!-- Ciudad destino (sin archivo: 1 col) -->
            <div class="sop-field">
              <label class="sop-label">Ciudad destino<span class="req">*</span></label>
              <input type="text" class="sop-input" name="ciudad_d" id="ciudad_d" placeholder="Ciudad destino" required />
            </div>
            <!-- Observaciones (sin archivo: 2 cols) -->
            <div class="sop-field sop-span2">
              <label class="sop-label">Observaciones</label>
              <textarea class="sop-textarea" name="observaciones" id="observaciones" rows="4" placeholder="Escribe las observaciones..."></textarea>
            </div>
            <?php endif; ?>

          </div>

          <!-- Hidden inputs -->
          <input type="hidden" name="cod_zona" value="<?= htmlspecialchars($zona['codigo'] ?? '') ?>">

          <?php if (isset($_GET['id_pedido'])): ?>
          <input type="hidden" name="id_pedido" value="<?= htmlspecialchars($_GET['id_pedido']) ?>">
          <?php endif; ?>

          <?php if (isset($_GET['id_pedido_dist'])): ?>
          <input type="hidden" name="id_pedido_dist" value="<?= htmlspecialchars($_GET['id_pedido_dist']) ?>">
          <?php endif; ?>

          <?php if (isset($_GET['id_muestreo'])): ?>
          <input type="hidden" name="id_muestreo" value="<?= htmlspecialchars($_GET['id_muestreo']) ?>">
          <?php endif; ?>

          <?php if (isset($_GET['id_devol_c'])): ?>
          <input type="hidden" name="id_devol_c" value="<?= htmlspecialchars($_GET['id_devol_c']) ?>">
          <?php endif; ?>

          <?php if (isset($_GET['id_devol_p'])): ?>
          <input type="hidden" name="id_devol_p" value="<?= htmlspecialchars($_GET['id_devol_p']) ?>">
          <?php endif; ?>

          <?php if (isset($_GET['id_devol_v'])): ?>
          <input type="hidden" name="id_devol_v" value="<?= htmlspecialchars($_GET['id_devol_v']) ?>">
          <?php endif; ?>

          <?php if (isset($_POST['pedidos_agp'])): ?>
            <?php foreach ($_POST['pedidos_agp'] as $pedidoa): ?>
            <input type="hidden" name="pedidos_agp[]" value="<?= htmlspecialchars($pedidoa) ?>">
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- Guardar -->
          <div class="sop-actions">
            <button type="submit" class="sop-btn-save">
              <i class="bi bi-floppy2-fill"></i> Guardar
            </button>
          </div>

        </form>

        </div><!-- /.sop-body -->
      </div><!-- /.modern-card -->

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<!-- js -->
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<script>
  $(document).ready(function () {
    $('#cliente').select2({
      placeholder: 'Seleccionar cliente',
      allowClear: true,
      minimumResultsForSearch: 0,
      width: '100%',
      language: {
        noResults: function () { return 'Sin resultados'; },
        searching:  function () { return 'Buscando...'; }
      }
    });

    $('#tipo_doc').select2({
      placeholder: 'Seleccionar',
      allowClear: true,
      minimumResultsForSearch: 0,
      width: '100%',
      language: {
        noResults: function () { return 'Sin resultados'; },
        searching:  function () { return 'Buscando...'; }
      }
    });
  });

  $('#materia').on('change',function(){
    var valor = $(this).val();
    var dataString = 'mat_gra='+valor;
    $.ajax({
      url: "ajax/buscar_l_eureka_sp.php",
      type: "POST", data: dataString, dataType: "html",
      success: function(resp){ $("#libro").html(resp); },
      error: function(jqXHR,estado,error){ alert("error"); console.log(estado); console.log(error); },
      complete: function(jqXHR,estado){ console.log(estado); }
    });
  });

  $('#libro').on('change',function(){
    var cant  = $('#cantidad').val();
    var libro = $('#libro').val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado==15 || grado==16) {
      $('#l_cantidad').addClass("d-none");
      $('#cantidad').addClass("d-none");
      var dataString = 'pri_sec='+libro;
      $.ajax({
        url: "ajax/buscar_pri_sec.php",
        type: "POST", data: dataString, dataType: "html",
        success: function(resp){ $("#ls_pri_sec").html(''); $("#ls_pri_sec").append(resp); console.log(resp); },
        error: function(jqXHR,estado,error){ alert("error"); console.log(estado); console.log(error); },
        complete: function(jqXHR,estado){ console.log(estado); }
      });
    } else {
      $('#libro_e').val(libro+'/'+cant);
    }
  });

  $('#cantidad').keyup(function(){
    var cant  = $('#cantidad').val();
    var libro = $('#libro').val();
    var grado = $('#libro option:selected').attr('data-grado');
    if (grado!=15 || grado!=16) { $('#libro_e').val(libro+'/'+cant); }
  });

  var m = 1;
  $("#agregar_libro").click(function(){
    if (m>98) { $("#agregar_libro").addClass("d-none"); }
    $("#agg_l"+m).removeClass("d-none");
    m++;
    <?php for ($i=1; $i < 100; $i++) { ?>
      $('#materia<?php echo $i; ?>').on('change',function(){
        var valor = $(this).val();
        var dataString = 'mat_gra='+valor;
        $.ajax({
          url: "ajax/buscar_l_eureka_sp.php",
          type: "POST", data: dataString, dataType: "html",
          success: function(resp){ $("#libro<?php echo $i; ?>").html(resp); },
          error: function(jqXHR,estado,error){ alert("error"); console.log(estado); console.log(error); },
          complete: function(jqXHR,estado){ console.log(estado); }
        });
      });
      $('#libro<?php echo $i; ?>').on('change',function(){
        var cant  = $('#cantidad<?php echo $i; ?>').val();
        var libro = $('#libro<?php echo $i; ?>').val();
        var grado = $('#libro<?php echo $i; ?> option:selected').attr('data-grado');
        if (grado==15 || grado==16) {
          $('#l_cantidad<?php echo $i; ?>').addClass("d-none");
          $('#cantidad<?php echo $i; ?>').addClass("d-none");
          var dataString = 'pri_sec='+libro;
          $.ajax({
            url: "ajax/buscar_pri_sec.php",
            type: "POST", data: dataString, dataType: "html",
            success: function(resp){ $("#ls_pri_sec<?php echo $i; ?>").html(''); $("#ls_pri_sec<?php echo $i; ?>").append(resp); console.log(resp); },
            error: function(jqXHR,estado,error){ alert("error"); console.log(estado); console.log(error); },
            complete: function(jqXHR,estado){ console.log(estado); }
          });
        } else {
          $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant);
        }
      });
      $('#cantidad<?php echo $i; ?>').keyup(function(){
        var cant  = $('#cantidad<?php echo $i; ?>').val();
        var libro = $('#libro<?php echo $i; ?>').val();
        var grado = $('#libro option:selected').attr('data-grado');
        if (grado!=15 || grado!=16) { $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant); }
      });
    <?php } ?>
  });

  // Tamaño máximo del archivo
  const maxSize  = 10000000;
  const $miInput = document.querySelector("#archivo");
  if ($miInput) {
    $miInput.addEventListener("change", function() {
      if (this.files.length <= 0) return;
      const archivo = this.files[0];
      if (archivo.size > maxSize) {
        const tamanioEnMb = maxSize / 1000000;
        alert(`El tamaño máximo es ${tamanioEnMb} MB`);
        $miInput.value = "";
      }
    });
  }
</script>

</body>
</html>

