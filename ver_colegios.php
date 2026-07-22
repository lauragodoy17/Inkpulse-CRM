<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

// WHERE base según rol del usuario
if ($_SESSION['zona']=='5656' || ($_SESSION["tipo"]!=3 && $_SESSION["tipo"]!=6 && $_SESSION["tipo"]!=10)) {
    $where_stats = "cod_zona != 0 AND id > 2";
} elseif ($_SESSION["tipo"]==10) {
    $zona_id = intval($_SESSION['zona']);
    $where_stats = "(cod_zona=$zona_id OR zona_madre=$zona_id)";
} else {
    $zona_id = intval($_SESSION['zona']);
    $where_stats = "cod_zona=$zona_id";
}

// Estadísticas
$total_colegios   = $bdd->query("SELECT COUNT(*) FROM colegios WHERE $where_stats")->fetchColumn();
$total_zonas      = $bdd->query("SELECT COUNT(DISTINCT cod_zona) FROM colegios WHERE $where_stats AND cod_zona != 0")->fetchColumn();
$total_deptos     = $bdd->query("SELECT COUNT(DISTINCT departamento) FROM colegios WHERE $where_stats")->fetchColumn();

// Datos para filtros
if ($_SESSION['tipo'] == 3) {
    $zona_id_dep = intval($_SESSION['zona']);
    $stmt_dep = $bdd->prepare(
        "SELECT DISTINCT d.id, d.departamento
         FROM departamentos d
         INNER JOIN colegios c ON c.departamento = d.id
         WHERE c.cod_zona = :zona_id
         ORDER BY d.departamento"
    );
    $stmt_dep->execute([':zona_id' => $zona_id_dep]);
    $depto_list = $stmt_dep->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filtro_prueba_depto = $_SESSION['tipo'] != 1 ? "WHERE LOWER(departamento) NOT LIKE '%prueba%'" : "";
    $depto_list = $bdd->query("SELECT id, departamento FROM departamentos $filtro_prueba_depto ORDER BY departamento")->fetchAll(PDO::FETCH_ASSOC);
}
if ($_SESSION['tipo'] != 3) {
    // Usar municipios si ya fue importada para mostrar nombres oficiales sin duplicados
    $usa_municipios = false;
    try { $bdd->query("SELECT 1 FROM municipios LIMIT 1"); $usa_municipios = true; } catch (Exception $e) {}

    if ($usa_municipios) {
        $ciudades_list = $bdd->query("
            SELECT DISTINCT m.nombre AS ciudad
            FROM municipios m
            WHERE EXISTS (
                SELECT 1 FROM colegios
                WHERE departamento = m.id_departamento
                  AND ciudad = m.nombre
                  AND $where_stats
            )
            ORDER BY m.nombre
            LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $filtro_prueba_ciudad = $_SESSION['tipo'] != 1 ? "AND LOWER(ciudad) NOT LIKE '%prueba%'" : "";
        $ciudades_list = $bdd->query("SELECT DISTINCT ciudad FROM colegios WHERE $where_stats AND ciudad != '' AND ciudad IS NOT NULL $filtro_prueba_ciudad ORDER BY ciudad LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    }
}

$calendarios_filter_list = $bdd->query("SELECT id, calendario FROM calendarios WHERE act=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$show_zona_filter = ($_SESSION['tipo'] != 3 && ($_SESSION['tipo']==1 || $_SESSION["tipo"]==7 || $_SESSION["tipo"]==10 || $_SESSION["tipo"]==5 || $_SESSION['zona']=='5656'));
if ($show_zona_filter) {
    $filtro_prueba_zona = $_SESSION['tipo'] != 1 ? "WHERE LOWER(zona) NOT LIKE '%prueba%'" : "";
    $zonas_filter_list = $bdd->query("SELECT codigo, zona FROM zonas $filtro_prueba_zona ORDER BY zona")->fetchAll(PDO::FETCH_ASSOC);
}

$show_resp_filter = ($_SESSION['tipo']==1 || $_SESSION['tipo']==2);
if ($show_resp_filter) {
    $filtro_prueba_resp = $_SESSION['tipo'] != 1 ? "AND LOWER(u.nombres) NOT LIKE '%prueba%' AND LOWER(u.apellidos) NOT LIKE '%prueba%'" : "";
    $resp_list = $bdd->query(
        "SELECT DISTINCT CONCAT(u.nombres,' ',u.apellidos) AS responsable
         FROM zonas z JOIN usuarios u ON z.codigo = u.cod_zona
         WHERE 1=1 $filtro_prueba_resp
         ORDER BY responsable"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <title>Inkpulse - Zonificación</title>
    <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css" />
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css?v=6" />
  </head>
  <body>

    <?php include("template/nav_side.php"); ?>

    <div class="main-container">
      <div class="pd-ltr-20 xs-pd-20-10">
        <div class="min-height-200px">

          <!-- Encabezado de página -->
          <div class="page-header">
            <div class="row align-items-center">
              <div class="col-md-6 col-sm-12">
                <div class="title"><h4>Zonificación</h4></div>
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Ver colegios</li>
                  </ol>
                </nav>
              </div>
              <?php if ($_SESSION['tipo'] == 1): ?>
              <div class="col-md-6 col-sm-12 text-right">
                <a href="agregar_colegio.php" class="btn btn-primary">
                  <i class="bi bi-plus-circle"></i> Crear colegio
                </a>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Tarjetas de estadísticas -->
          <?php $col_stat = ($_SESSION['tipo'] == 3 || $_SESSION['tipo'] == 6) ? 'col-xl-6 col-lg-6 col-md-6' : 'col-xl-4 col-lg-4 col-md-6'; ?>
          <div class="row">
            <div class="<?= $col_stat ?>">
              <div class="stat-card-modern">
                <div class="stat-icon-modern sblue"><i class="bi bi-building"></i></div>
                <div class="stat-info-modern">
                  <h3><?= number_format($total_colegios) ?></h3>
                  <p class="stat-label">Total colegios</p>
                  <span class="stat-sub">En tu zonificación</span>
                </div>
              </div>
            </div>
            <?php if ($_SESSION['tipo'] != 3 && $_SESSION['tipo'] != 6): ?>
            <div class="<?= $col_stat ?>">
              <div class="stat-card-modern">
                <div class="stat-icon-modern sorange"><i class="bi bi-map"></i></div>
                <div class="stat-info-modern">
                  <h3><?= number_format($total_zonas) ?></h3>
                  <p class="stat-label">Zonas activas</p>
                  <span class="stat-sub">Con colegios asignados</span>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <div class="<?= $col_stat ?>">
              <div class="stat-card-modern">
                <div class="stat-icon-modern sgreen"><i class="bi bi-geo-alt"></i></div>
                <div class="stat-info-modern">
                  <h3><?= number_format($total_deptos) ?></h3>
                  <p class="stat-label">Departamentos</p>
                  <span class="stat-sub">Con presencia activa</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Barra de filtros -->
          <div class="filter-toolbar">
            <div class="ft-search">
              <i class="bi bi-search ft-search-icon"></i>
              <input type="text" id="ft-input-search" placeholder="Buscar por nombre o DANE...">
            </div>

            <select class="ft-select" id="ft-depto">
              <option value="">Todos los departamentos</option>
              <?php foreach ($depto_list as $dep): ?>
              <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['departamento']) ?></option>
              <?php endforeach; ?>
            </select>

            <?php if ($_SESSION['tipo'] != 3): ?>
            <select class="ft-select" id="ft-ciudad">
              <option value="">Todas las ciudades</option>
              <?php foreach ($ciudades_list as $ciu): ?>
              <option value="<?= htmlspecialchars($ciu['ciudad']) ?>"><?= htmlspecialchars($ciu['ciudad']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <select class="ft-select" id="ft-calendario">
              <option value="">Todos los calendarios</option>
              <?php foreach ($calendarios_filter_list as $cal): ?>
              <option value="<?= $cal['id'] ?>">Calendario <?= htmlspecialchars($cal['calendario']) ?></option>
              <?php endforeach; ?>
            </select>

            <?php if ($show_zona_filter): ?>
            <select class="ft-select" id="ft-zona">
              <option value="">Todas las zonas</option>
              <option value="sin_asignar">Sin asignar</option>
              <?php foreach ($zonas_filter_list as $z):
                $parts = explode("/", $z['zona']);
                $label = trim(count($parts) > 1 ? $parts[1] : $parts[0]);
              ?>
              <option value="<?= $z['codigo'] ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ($show_resp_filter): ?>
            <select class="ft-select" id="ft-resp">
              <option value="">Todos los responsables</option>
              <?php foreach ($resp_list as $r): ?>
              <option value="<?= htmlspecialchars($r['responsable']) ?>"><?= htmlspecialchars($r['responsable']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <button class="ft-btn ft-apply" id="ft-btn-apply">
              <i class="bi bi-funnel"></i> Filtrar
            </button>
            <button class="ft-btn ft-clear" id="ft-btn-clear">
              <i class="bi bi-x-circle"></i> Limpiar
            </button>
          </div>

          <!-- Tabla de colegios -->
          <div class="modern-card">
            <div class="card-head">
              <h5><i class="bi bi-list-ul mr-2"></i> Lista de colegios</h5>
            </div>
            <div class="table-responsive px-2 pb-2">
              <table class="table table-sm table-hover" id="dataTables-example">
                <thead>
                  <tr>
                    <th>Colegio</th>
                    <?php if ($_SESSION['tipo'] == 1 || $_SESSION["tipo"]==7 || $_SESSION["tipo"]==10 || $_SESSION["tipo"]==5 || $_SESSION['zona']=='5656'): ?>
                      <th>Empresa</th>
                      <th>Zona</th>
                      <th>Responsable</th>
                    <?php elseif ($_SESSION['tipo']==6): ?>
                      <th>Zona</th>
                      <th>Responsable</th>
                    <?php endif; ?>
                    <th>Departamento</th>
                    <th>Ciudad</th>
                    <th>Dirección</th>
                    <th>Periodo</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
              </table>
            </div>
          </div>

        </div>
        <?php include("template/footer.php"); ?>
      </div><!-- /.pd-ltr-20 -->
    </div><!-- /.main-container -->

    <!-- Panel lateral de detalle de colegio -->
    <div id="panel-overlay" class="dp-overlay"></div>
    <div id="panel-detalle" class="dp-panel">
      <div class="dp-header">
        <div class="dp-header-info">
          <div class="dp-avatar" id="dp-avatar">CO</div>
          <div>
            <h5 id="dp-nombre">—</h5>
            <span id="dp-dane" class="dp-dane">—</span>
          </div>
        </div>
        <button class="dp-close" id="dp-close"><i class="bi bi-x-lg"></i></button>
      </div>

      <div class="dp-body">
        <div class="dp-row">
          <span class="dp-icon" style="background:#eef0ff;color:#4361ee"><i class="bi bi-calendar3"></i></span>
          <div class="dp-field">
            <span class="dp-label">Calendario</span>
            <span class="dp-val" id="dp-calendario">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#e9f9f0;color:#2ecc71"><i class="bi bi-geo-alt"></i></span>
          <div class="dp-field">
            <span class="dp-label">Dirección</span>
            <span class="dp-val" id="dp-direccion">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#fff4ec;color:#f77f00"><i class="bi bi-telephone"></i></span>
          <div class="dp-field">
            <span class="dp-label">Teléfono</span>
            <span class="dp-val" id="dp-telefono">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#f4ecff;color:#9b59b6"><i class="bi bi-tag"></i></span>
          <div class="dp-field">
            <span class="dp-label">Segmento</span>
            <span class="dp-val" id="dp-segmento">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#fef3c7;color:#d97706"><i class="bi bi-circle-half"></i></span>
          <div class="dp-field">
            <span class="dp-label">Status</span>
            <span class="dp-val" id="dp-status">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#e0f2fe;color:#0284c7"><i class="bi bi-calendar-range"></i></span>
          <div class="dp-field">
            <span class="dp-label">Periodo</span>
            <span class="dp-val" id="dp-periodo">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-currency-dollar"></i></span>
          <div class="dp-field">
            <span class="dp-label">Venta potencial (presupuesto)</span>
            <span class="dp-val dp-money" id="dp-valor">—</span>
          </div>
        </div>
        <div class="dp-row">
          <span class="dp-icon" style="background:#dbeafe;color:#2563eb"><i class="bi bi-book"></i></span>
          <div class="dp-field">
            <span class="dp-label">Venta potencial (adopciones)</span>
            <span class="dp-val dp-money" id="dp-valor-adopciones">—</span>
          </div>
        </div>
      </div>
    </div><!-- /#panel-detalle -->

    <!-- Scripts -->
    <script src="vendors/scripts/core.js"></script>
    <script src="vendors/scripts/script.min.js"></script>
    <script src="vendors/scripts/process.js"></script>
    <script src="vendors/scripts/layout-settings.js"></script>
    <script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
    <script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
    <script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
    <script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

    <script>
    $(document).ready(function () {
        $.fn.dataTable.ext.errMode = 'none';

        function avatarRender(data, type, row) {
            if (type !== 'display') return data;
            var txt = $('<div>').html(data).text();
            var words = txt.trim().split(/\s+/).filter(function(w){ return w.length > 2; });
            var initials = words.slice(0,2).map(function(w){ return w[0].toUpperCase(); }).join('');
            if (!initials) initials = txt.substring(0,2).toUpperCase();
            var dane = row.dane ? '<div class="dt-dane-sub">' + row.dane + '</div>' : '';
            return '<div class="dt-cole-wrap">'
                 + '<span class="school-avatar">' + initials + '</span>'
                 + '<div class="dt-cole-info">' + data + dane + '</div>'
                 + '</div>';
        }

        var table = $('#dataTables-example').dataTable({
            processing: true,
            serverSide: true,
            responsive: { details: false },
            autoWidth: false,
            ajax: {
                url: "php/colegios_tabla.php",
                data: function(d) {
                    d.depto_filter  = $('#ft-depto').val();
                    d.ciudad_filter = $('#ft-ciudad').val();
                    d.calendario_filter = $('#ft-calendario').val();
                    <?php if ($show_zona_filter): ?>
                    d.zona_filter   = $('#ft-zona').val();
                    <?php endif; ?>
                    <?php if ($show_resp_filter): ?>
                    d.resp_filter   = $('#ft-resp').val();
                    <?php endif; ?>
                }
            },

            <?php if ($_SESSION['tipo'] == 1 || $_SESSION["tipo"]==7 || $_SESSION["tipo"]==10 || $_SESSION["tipo"]==5 || $_SESSION['zona']=='5656'): ?>
            columns: [
                { data: "colegio",      responsivePriority: 1, render: avatarRender, className: "dt-colegio all" },
                { data: "empresa",      responsivePriority: 6, className: "dt-empresa" },
                { data: "zona",         responsivePriority: 3, className: "dt-zona" },
                { data: "responsable",  responsivePriority: 1, className: "dt-resp all" },
                { data: "departamento", responsivePriority: 5, className: "dt-depto" },
                { data: "ciudad",       responsivePriority: 4, className: "dt-ciudad" },
                { data: "direccion",    responsivePriority: 7, className: "dt-dir" },
                { data: "periodo",      responsivePriority: 1, orderable: false, className: "dt-periodo all" },
                { data: "acciones",     responsivePriority: 1, orderable: false, className: "dt-acc" },
            ],
            <?php elseif ($_SESSION['tipo']==6): ?>
            columns: [
                { data: "colegio",      responsivePriority: 1, render: avatarRender, className: "dt-colegio all" },
                { data: "zona",         responsivePriority: 3, className: "dt-zona" },
                { data: "responsable",  responsivePriority: 1, className: "dt-resp all" },
                { data: "departamento", responsivePriority: 5, className: "dt-depto" },
                { data: "ciudad",       responsivePriority: 4, className: "dt-ciudad" },
                { data: "direccion",    responsivePriority: 6, className: "dt-dir" },
                { data: "periodo",      responsivePriority: 1, orderable: false, className: "dt-periodo all" },
                { data: "acciones",     responsivePriority: 1, orderable: false, className: "dt-acc" },
            ],
            <?php else: ?>
            columns: [
                { data: "colegio",      responsivePriority: 1, render: avatarRender, className: "dt-colegio all" },
                { data: "departamento", responsivePriority: 3, className: "dt-depto" },
                { data: "ciudad",       responsivePriority: 3, className: "dt-ciudad" },
                { data: "direccion",    responsivePriority: 5, className: "dt-dir" },
                { data: "periodo",      responsivePriority: 1, orderable: false, className: "dt-periodo all" },
                { data: "acciones",     responsivePriority: 1, orderable: false, className: "dt-acc" },
            ],
            <?php endif; ?>

            language: {
                lengthMenu:   "Mostrar _MENU_ registros",
                zeroRecords:  "No se encontraron resultados",
                emptyTable:   "No hay información para mostrar",
                info:         "Mostrando _START_ a _END_ de _TOTAL_ registros",
                infoEmpty:    "Sin registros disponibles",
                infoFiltered: "(filtrado de _MAX_ registros)",
                search:       "Buscar:",
                processing:   '<div class="dt-loading"><i class="bi bi-arrow-repeat"></i> Cargando...</div>',
                paginate: {
                    first:    "«",
                    previous: "‹",
                    next:     "›",
                    last:     "»"
                }
            },

            initComplete: function () {
                var api = this.api();
                $('#ft-input-search').on('keyup', function () {
                    var val = this.value;
                    if (val.length >= 4 || val.length === 0) {
                        api.search(val).draw();
                    }
                });
            }
        });

        // Aplicar filtros
        $('#ft-btn-apply').on('click', function () {
            table.api().draw();
        });

        // Limpiar filtros
        $('#ft-btn-clear').on('click', function () {
            $('#ft-depto, #ft-zona, #ft-resp, #ft-calendario').val('');
            $('#ft-input-search').val('');
            $('#ft-ciudad').html('<option value="">Todas las ciudades</option>');
            table.api().search('').draw();
        });

        // Cargar ciudades al seleccionar departamento
        $('#ft-depto').on('change', function () {
            var depto = $(this).val();
            $('#ft-ciudad').html('<option value="">Todas las ciudades</option>');
            if (!depto) return;

            $.ajax({
                url: 'ajax/ciudades_por_depto.php',
                type: 'POST',
                data: { departamento: depto },
                success: function (resp) {
                    $('#ft-ciudad').append(resp);
                }
            });
        });

        // Navegación al detalle del colegio
        $('#dataTables-example').on('click', '.btn-info', function (e) {
            e.preventDefault();
            var id      = $(this).data('id');
            var codigo  = $(this).data('codigo');
            var periodo = $('#periodo' + id).val();
            window.location.href = 'colegio.php?codigo=' + codigo + '&periodo=' + periodo;
        });

        $('#dataTables-example').on('click', '.linkcole', function (e) {
            e.preventDefault();
            var id      = $(this).data('id');
            var codigo  = $(this).data('codigo');
            var periodo = $('#periodo' + id).val();
            window.location.href = 'colegio.php?codigo=' + codigo + '&periodo=' + periodo;
        });

        $('#dataTables-example').on('click', '.eliminar', function () {
            var cod = $(this).attr('data-codigo');
            if (confirm("¿Seguro que desea eliminar este colegio?")) {
                window.location = "php/eliminar_colegio.php?codigo=" + cod;
            }
        });

        // ── Panel de detalle ──────────────────────────────
        function abrirPanel(codigo, periodo, periodoTexto) {
            $.getJSON('ajax/detalle_colegio.php', { codigo: codigo, periodo: periodo }, function(d) {
                if (d.error) return;

                var txt   = (d.colegio || '').trim();
                var words = txt.split(/\s+/).filter(function(w){ return w.length > 2; });
                var ini   = words.slice(0,2).map(function(w){ return w[0].toUpperCase(); }).join('') || txt.substring(0,2).toUpperCase();

                $('#dp-avatar').text(ini);
                $('#dp-nombre').text(d.colegio || '—');
                $('#dp-dane').text(d.dane || '—');
                $('#dp-calendario').text(d.calendario || '—');
                $('#dp-direccion').text(d.direccion ? d.direccion + (d.ciudad ? ', ' + d.ciudad : '') : '—');
                $('#dp-telefono').text(d.telefono || '—');
                $('#dp-segmento').text(d.segmento || '—');
                $('#dp-status').text(d.status || '—');
                $('#dp-periodo').text(periodoTexto || '—');

                var val = parseFloat(d.valor_potencial) || 0;
                $('#dp-valor').text(val > 0 ? '$ ' + val.toLocaleString('es-CO') : '—');

                var valAdop = parseFloat(d.valor_potencial_adopciones) || 0;
                $('#dp-valor-adopciones').text(valAdop > 0 ? '$ ' + valAdop.toLocaleString('es-CO') : '—');

                $('#panel-detalle, #panel-overlay').addClass('dp-open');
                $('body').addClass('dp-body-lock');
            });
        }

        $('#dataTables-example').on('click', '.btn-ver-detalle', function() {
            var id           = $(this).data('id');
            var $sel         = $('#periodo' + id);
            var periodo      = $sel.val();
            var periodoTexto = $sel.find('option:selected').text();
            abrirPanel($(this).data('codigo'), periodo, periodoTexto);
        });

        $('#dp-close, #panel-overlay').on('click', function() {
            $('#panel-detalle, #panel-overlay').removeClass('dp-open');
            $('body').removeClass('dp-body-lock');
        });
    });
    </script>

  </body>
</html>
