<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
$es_admin = $tipo_sesion === 1;
// Promotores y distribuidores ven el mismo panel pero acotado a su propia
// información (sin selector de rol/persona); el resto de tipos no lo ve.
// Tipo 10 ve el mismo panel que un promotor (Presupuestos, Adopciones y Visitas),
// también acotado únicamente a su propia información.
$dash_rol_fijo = ($tipo_sesion === 3 || $tipo_sesion === 10) ? 'promotor' : ($tipo_sesion === 6 ? 'distribuidor' : null);
// Tipo 4 solo ve la sección de Visitas planificadas, acotada a sus propias visitas
// (igual que promotores/distribuidores, el filtro por usuario lo fuerza el backend).
$solo_visitas_dash = $tipo_sesion === 4;
$dash_visible = $es_admin || $dash_rol_fijo !== null || $solo_visitas_dash;

if ($dash_visible) {
    $periodos_dash = $bdd->query("SELECT id, periodo FROM periodos ORDER BY id DESC")->fetchAll();
    $periodo_actual_dash = !empty($periodos_dash) ? $periodos_dash[0]['id'] : 0;
}
if ($es_admin) {
    // El filtro "Eureka" agrupa a los promotores (tipo=3) y, a pedido del negocio,
    // también a Hector Morales (id=69, tipo=10) aunque su tipo de cuenta no sea promotor;
    // por eso se excluye de "otros" para no duplicarlo en ambos grupos.
    $promotores_dash = $bdd->query("SELECT id, nombres, apellidos FROM usuarios WHERE (tipo=3 OR id=69) AND act=1 ORDER BY nombres, apellidos")->fetchAll();
    $distribuidores_dash = $bdd->query("SELECT id, nombres, apellidos FROM usuarios WHERE tipo=6 AND act=1 ORDER BY nombres, apellidos")->fetchAll();
    $otros_dash = $bdd->query("SELECT id, nombres, apellidos FROM usuarios WHERE tipo IN (1,4,10) AND id<>69 AND act=1 ORDER BY nombres, apellidos")->fetchAll();
}
?>
<!DOCTYPE html>
<html>
	<head>
		<!-- Basic Page Info -->
		<meta charset="utf-8" />
		<title>Inkpulse - Inicio</title>

		<!-- Site favicon -->
		<link
			rel="apple-touch-icon"
			sizes="180x180"
			href="vendors/images/apple-touch-icon.png"
		/>
		<link
			rel="icon"
			type="image/png"
			sizes="32x32"
			href="vendors/images/favicon-32x32.png"
		/>
		<link
			rel="icon"
			type="image/png"
			sizes="16x16"
			href="vendors/images/favicon-16x16.png"
		/>

		<!-- Mobile Specific Metas -->
		<meta
			name="viewport"
			content="width=device-width, initial-scale=1, maximum-scale=1"
		/>

		<!-- Google Font -->
		<link
			href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
			rel="stylesheet"
		/>
		<!-- CSS -->
		<link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
		<link
			rel="stylesheet"
			type="text/css"
			href="vendors/styles/icon-font.min.css"
		/>
		<link
			rel="stylesheet"
			type="text/css"
			href="src/plugins/datatables/css/dataTables.bootstrap4.min.css"
		/>
		<link
			rel="stylesheet"
			type="text/css"
			href="src/plugins/datatables/css/responsive.bootstrap4.min.css"
		/>
		<link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />

		<style>
			.dash-toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:8px}
			.dash-toolbar h4{margin:0}
			.dash-toolbar .dash-filters{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
			.dash-toolbar .dash-select{height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 32px 0 12px;font-size:13px;font-weight:600;color:#374151;background:#f8fafc;cursor:pointer;min-width:170px}
			.dash-select:disabled{opacity:.6;cursor:wait}
			#dash-loading{display:none;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#4361ee}
			#dash-loading.show{display:flex}
			#dash-loading .spinner-border{width:16px;height:16px;border-width:2px}
			#dash-visitas.dash-loading-overlay{position:relative}
			#dash-visitas.dash-loading-overlay::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.4);z-index:1;pointer-events:none}
			.dash-section-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:700;color:#1e293b;margin:28px 0 14px;padding-top:6px;border-top:1px solid #e2e8f0}
			.dash-section-title:first-of-type{border-top:none;margin-top:16px}
			.dash-section-title i{color:#4361ee}
			.stat-card-hero{background:linear-gradient(135deg,#4361ee,#3730a3);border-radius:14px;padding:26px 28px;display:flex;align-items:center;gap:22px;color:#fff;box-shadow:0 6px 22px rgba(67,97,238,.28);margin-bottom:24px}
			.stat-card-hero .stat-icon-hero{width:60px;height:60px;border-radius:14px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
			.stat-card-hero > div:last-child{min-width:0}
			.stat-card-hero .stat-label-hero{font-size:13px;font-weight:600;opacity:.85;margin:0 0 4px;color:#fff}
			.stat-card-hero h2{font-size:34px;font-weight:800;margin:0;line-height:1.1;color:#fff;overflow-wrap:anywhere}
			.stat-card-hero .stat-sub-hero{font-size:12.5px;opacity:.8;margin-top:6px;color:#fff;overflow-wrap:anywhere}
			.stat-card-hero.green{background:linear-gradient(135deg,#2ecc71,#1b9e5a);box-shadow:0 6px 22px rgba(46,204,113,.28)}
			.stat-card-hero.compact{padding:20px 22px;gap:16px}
			.stat-card-hero.compact .stat-icon-hero{width:46px;height:46px;font-size:20px;border-radius:12px}
			.stat-card-hero.compact .stat-label-hero{font-size:12px}
			.stat-card-hero.compact h2{font-size:20px}
			.stat-card-hero.compact .stat-sub-hero{font-size:11px}
			@media(max-width:575px){.stat-card-hero{padding:20px}.stat-card-hero h2{font-size:24px}.stat-card-hero .stat-icon-hero{width:48px;height:48px;font-size:22px}.stat-card-hero.compact h2{font-size:19px}}
			.stat-sub-row{display:flex;align-items:center;gap:6px;margin-top:2px}
			.stat-trend{font-size:11.5px;font-weight:700;padding:1px 7px;border-radius:20px}
			.stat-trend.up{background:#e9f9f0;color:#1b9e5a}
			.stat-trend.down{background:#fff1f0;color:#e5484d}
			.chart-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.07),0 4px 16px rgba(0,0,0,.04);padding:20px 20px 8px;margin-bottom:24px}
			.chart-card .chart-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
			.chart-card .chart-card-head h5{font-size:15px;font-weight:600;color:#2d3748;margin:0}
			.chart-card .chart-card-head span{font-size:12px;color:#94a3b8}
			.chart-card-head-actions{display:flex;align-items:center;gap:10px}
			.btn-exportar-mini{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:#e9f9f0;color:#1b9e5a;font-size:14px;transition:background .15s,color .15s;flex-shrink:0}
			.btn-exportar-mini:hover{background:#1b9e5a;color:#fff;text-decoration:none}
			.dash-cole-link{cursor:pointer}
			.dash-cole-link:hover{fill:#2a78d6;text-decoration:underline}
			.badge-efectiva{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:20px}
			.badge-efectiva-si{background:#e9f9f0;color:#1b9e5a}
			.badge-efectiva-no{background:#fff1f0;color:#e5484d}
			.badge-efectiva-na{background:#f1f5f9;color:#94a3b8}
			.dash-empty{text-align:center;padding:30px;color:#94a3b8;font-size:13px}
		</style>
	</head>
	<body>
		
		<?php include("template/nav_side.php"); ?>
		<div class="main-container">
			<div class="pd-ltr-20">
				<div class="card-box pd-20 height-100-p mb-30">
					<div class="row align-items-center">
						<div class="col-md-4">
							<img src="vendors/images/banner-img.png" alt="" />
						</div>
						<div class="col-md-8">
							<h4 class="font-20 weight-500 mb-10 text-capitalize">
								Bienvenido
								<?php
									
									echo '<div class="weight-600 font-30 text-blue">'.$usuario["nombre_completo"].'</div>';
								?>
								
							</h4>
							<!--<p class="font-18 max-width-600">
								Lorem ipsum dolor sit amet, consectetur adipisicing elit. Unde
								
							</p>-->
						</div>
					</div>
				</div>

				<?php if ($dash_visible): ?>
				<div id="dash-visitas">

					<div class="dash-toolbar">
						<h4 class="font-18 weight-600"><?= $es_admin ? 'Panel general' : 'Mi panel' ?></h4>
						<div class="dash-filters">
							<?php if ($es_admin): ?>
							<select id="dash-rol" class="dash-select">
								<option value="">Todos</option>
								<option value="promotor">Eureka</option>
								<option value="distribuidor">Distribuidores</option>
								<option value="otros">Otros</option>
							</select>
							<select id="dash-persona" class="dash-select" style="display:none;">
								<option value="">Todos</option>
							</select>
							<?php else: ?>
							<select id="dash-rol" style="display:none;">
								<option value="<?= htmlspecialchars($dash_rol_fijo ?? '') ?>" selected><?= htmlspecialchars($dash_rol_fijo ?? '') ?></option>
							</select>
							<select id="dash-persona" style="display:none;">
								<option value="">Todos</option>
							</select>
							<?php endif; ?>
							<select id="dash-periodo" class="dash-select">
								<?php foreach ($periodos_dash as $p): ?>
									<option value="<?= $p['id'] ?>" <?= $p['id'] == $periodo_actual_dash ? 'selected' : '' ?>><?= htmlspecialchars($p['periodo']) ?></option>
								<?php endforeach; ?>
							</select>
							<span id="dash-loading"><span class="spinner-border" role="status"></span> Cargando...</span>
						</div>
					</div>

					<?php if ($es_admin): ?>
					<!-- ── Presupuesto, adopciones y venta real por asesor ── -->
					<div class="row">
						<div class="col-12">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Presupuesto, adopciones y venta real por asesor</h5>
									<div class="chart-card-head-actions">
										<span id="da-asesores-sub">Período seleccionado</span>
										<a href="#" id="btn-valorizacion-global" class="btn-exportar-mini" title="Descargar valorización global (según usuario y período seleccionados)">
											<i class="bi bi-file-earmark-excel"></i>
										</a>
									</div>
								</div>
								<div id="chart-asesores"></div>
							</div>
						</div>
					</div>

					<!-- Descarga silenciosa (sin navegar ni abrir pestaña): el form apunta a un iframe
					     oculto, así el navegador solo procesa la descarga del Excel. -->
					<iframe name="valoriza-global-frame" style="display:none;"></iframe>
					<form id="form-valoriza-global" action="php/valoriza_global_excel.php" method="POST" target="valoriza-global-frame" style="display:none;">
						<input type="hidden" name="periodo" id="vg-periodo">
						<input type="hidden" name="promotor" id="vg-promotor">
						<input type="hidden" name="rol" id="vg-rol">
					</form>
					<?php endif; ?>

					<?php if (!$solo_visitas_dash): ?>
					<!-- ── Presupuestos ── -->
					<h5 class="dash-section-title"><i class="bi bi-cash-coin"></i> Presupuestos</h5>

					<div class="row">
						<div class="col-12 col-lg-6">
							<div class="stat-card-hero">
								<div class="stat-icon-hero"><i class="bi bi-cash-stack"></i></div>
								<div>
									<p class="stat-label-hero">Venta potencial estimada</p>
									<h2 id="dp-venta-potencial">—</h2>
									<p class="stat-sub-hero" id="dp-venta-potencial-sub">Ítems en definición</p>
								</div>
							</div>
						</div>
						<div class="col-6 col-lg-3">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sblue"><i class="bi bi-collection"></i></div>
								<div class="stat-info-modern">
									<h3 id="dp-total">—</h3>
									<p class="stat-label">Ítems de presupuesto</p>
									<span class="stat-sub">En el período seleccionado</span>
								</div>
							</div>
						</div>
						<div class="col-6 col-lg-3">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sgreen"><i class="bi bi-check2-square"></i></div>
								<div class="stat-info-modern">
									<h3 id="dp-definidos">—</h3>
									<p class="stat-label">% Definidos</p>
									<span class="stat-sub" id="dp-definidos-sub">Ítems definidos</span>
								</div>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-xl-4 col-lg-5">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-pie-chart mr-2"></i>Venta potencial por probabilidad</h5>
								</div>
								<div id="chart-probabilidad-presup"></div>
							</div>
						</div>
						<div class="col-xl-8 col-lg-7">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Top colegios por venta potencial (presupuesto)</h5>
									<span id="dp-ranking-sub">Período seleccionado</span>
								</div>
								<div id="chart-ranking-presup"></div>
							</div>
						</div>
					</div>

					<?php if ($es_admin): ?>
					<div class="row">
						<div class="col-12">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Venta potencial por editorial (presupuesto)</h5>
									<span id="dp-editorial-sub">Período seleccionado</span>
								</div>
								<div id="chart-editorial-presup"></div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<!-- ── Adopciones ── -->
					<h5 class="dash-section-title"><i class="bi bi-bookmark-check-fill"></i> Adopciones</h5>

					<div class="row">
						<div class="col-12 col-sm-6 col-xl-3">
							<div class="stat-card-hero green compact">
								<div class="stat-icon-hero"><i class="bi bi-graph-up-arrow"></i></div>
								<div>
									<p class="stat-label-hero">Venta potencial (títulos adoptados)</p>
									<h2 id="da-venta">—</h2>
									<p class="stat-sub-hero" id="da-venta-sub">Promedio por título adoptado</p>
								</div>
							</div>
						</div>
						<div class="col-12 col-sm-6 col-xl-3">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sblue"><i class="bi bi-book"></i></div>
								<div class="stat-info-modern">
									<h3 id="da-adoptados">—</h3>
									<p class="stat-label">Títulos adoptados</p>
									<span class="stat-sub">En el período seleccionado</span>
								</div>
							</div>
						</div>
						<div class="col-12 col-sm-6 col-xl-3">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sgreen"><i class="bi bi-building-check"></i></div>
								<div class="stat-info-modern">
									<h3 id="da-colegios">—</h3>
									<p class="stat-label">Colegios con adopción</p>
									<span class="stat-sub" id="da-colegios-sub">Promedio por colegio</span>
								</div>
							</div>
						</div>
						<div class="col-12 col-sm-6 col-xl-3">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sorange"><i class="bi bi-mortarboard"></i></div>
								<div class="stat-info-modern">
									<h3 id="da-materia-top">—</h3>
									<p class="stat-label">Materia líder por venta</p>
									<span class="stat-sub" id="da-materia-top-sub">Mayor venta potencial</span>
								</div>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-xl-6">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Colegios con más descuento</h5>
									<div class="chart-card-head-actions">
										<span id="da-descuento-sub">Período seleccionado</span>
										<a href="#" id="btn-exportar-descuento" class="btn-exportar-mini" title="Exportar a Excel" target="_blank">
											<i class="bi bi-file-earmark-excel"></i>
										</a>
									</div>
								</div>
								<div id="chart-descuento-adop"></div>
							</div>
						</div>
						<div class="col-xl-6">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Top colegios por venta potencial (adopciones)</h5>
									<span id="da-ranking-sub">Período seleccionado</span>
								</div>
								<div id="chart-ranking-adop"></div>
							</div>
						</div>
					</div>

					<?php if ($es_admin): ?>
					<div class="row">
						<div class="col-12">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Venta potencial por editorial (adopciones)</h5>
									<span id="da-editorial-sub">Período seleccionado</span>
								</div>
								<div id="chart-editorial-adop"></div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<!-- ── Venta real ── -->
					<h5 class="dash-section-title"><i class="bi bi-wallet2"></i> Venta real</h5>

					<div class="row">
						<div class="col-12 col-lg-6">
							<div class="stat-card-hero">
								<div class="stat-icon-hero"><i class="bi bi-cash-coin"></i></div>
								<div>
									<p class="stat-label-hero">Venta real</p>
									<h2 id="dvr-total">—</h2>
									<p class="stat-sub-hero">En el período seleccionado</p>
								</div>
							</div>
						</div>
						<div class="col-12 col-lg-6">
							<div class="stat-card-modern">
								<div class="stat-icon-modern sgreen"><i class="bi bi-building-check"></i></div>
								<div class="stat-info-modern">
									<h3 id="dvr-colegios">—</h3>
									<p class="stat-label">Colegios con venta real</p>
									<span class="stat-sub">En el período seleccionado</span>
								</div>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-12">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Top colegios por venta real</h5>
									<span id="dvr-ranking-sub">Período seleccionado</span>
								</div>
								<div id="chart-ranking-ventareal"></div>
							</div>
						</div>
					</div>

					<?php if ($es_admin): ?>
					<div class="row">
						<div class="col-12">
							<div class="chart-card">
								<div class="chart-card-head">
									<h5><i class="bi bi-bar-chart mr-2"></i>Venta real por editorial</h5>
									<span id="dvr-editorial-sub">Período seleccionado</span>
								</div>
								<div id="chart-editorial-ventareal"></div>
							</div>
						</div>
					</div>
					<?php endif; ?>
					<?php endif; // !$solo_visitas_dash ?>

					<!-- ── Visitas planificadas ── -->
					<div id="dash-visitas-section">
						<h5 class="dash-section-title"><i class="bi bi-check2-circle"></i> Visitas planificadas</h5>

						<div class="row">
							<div class="col-xl-6 col-lg-6 col-md-6">
								<div class="stat-card-modern">
									<div class="stat-icon-modern sblue"><i class="bi bi-check2-circle"></i></div>
									<div class="stat-info-modern">
										<h3 id="dv-planificadas">—</h3>
										<p class="stat-label">Visitas planificadas</p>
										<span class="stat-sub">En el período seleccionado</span>
									</div>
								</div>
							</div>
							<div class="col-xl-6 col-lg-6 col-md-6">
								<div class="stat-card-modern">
									<div class="stat-icon-modern spurple"><i class="bi bi-bullseye"></i></div>
									<div class="stat-info-modern">
										<h3 id="dv-efectividad">—</h3>
										<p class="stat-label">Efectividad</p>
										<span class="stat-sub" id="dv-efectividad-sub">Visitas efectivas</span>
									</div>
								</div>
							</div>
						</div>

						<div class="row">
							<div class="col-xl-6 col-lg-6 col-md-6">
								<div class="chart-card">
									<div class="chart-card-head">
										<h5><i class="bi bi-pie-chart mr-2"></i>Efectividad</h5>
									</div>
									<div id="chart-efectividad-visitas"></div>
								</div>
							</div>
							<div class="col-xl-6 col-lg-6 col-md-6">
								<div class="chart-card">
									<div class="chart-card-head">
										<h5><i class="bi bi-pie-chart mr-2"></i>Objetivos más frecuentes</h5>
										<span>Visitas planificadas</span>
									</div>
									<div id="chart-objetivos-visitas"></div>
								</div>
							</div>
						</div>

						<div class="row">
							<div class="col-12">
								<div class="chart-card">
									<div class="chart-card-head">
										<h5><i class="bi bi-bar-chart mr-2"></i><?= ($dash_rol_fijo === 'promotor' || $solo_visitas_dash) ? 'Visitas planificadas' : 'Top promotores por visitas planificadas' ?></h5>
										<span id="dv-ranking-sub">Período seleccionado</span>
									</div>
									<div id="chart-ranking-promotores"></div>
								</div>
							</div>
						</div>
					</div>

				</div>
				<?php endif; ?>

				<!--<div class="row">
					<div class="col-xl-3 mb-30">
						<div class="card-box height-100-p widget-style1">
							<div class="d-flex flex-wrap align-items-center">
								<div class="progress-data">
									<div id="chart"></div>
								</div>
								<div class="widget-data">
									<div class="h4 mb-0">2020</div>
									<div class="weight-600 font-14">Contact</div>
								</div>
							</div>
						</div>
					</div>
					<div class="col-xl-3 mb-30">
						<div class="card-box height-100-p widget-style1">
							<div class="d-flex flex-wrap align-items-center">
								<div class="progress-data">
									<div id="chart2"></div>
								</div>
								<div class="widget-data">
									<div class="h4 mb-0">400</div>
									<div class="weight-600 font-14">Deals</div>
								</div>
							</div>
						</div>
					</div>
					<div class="col-xl-3 mb-30">
						<div class="card-box height-100-p widget-style1">
							<div class="d-flex flex-wrap align-items-center">
								<div class="progress-data">
									<div id="chart3"></div>
								</div>
								<div class="widget-data">
									<div class="h4 mb-0">350</div>
									<div class="weight-600 font-14">Campaign</div>
								</div>
							</div>
						</div>
					</div>
					<div class="col-xl-3 mb-30">
						<div class="card-box height-100-p widget-style1">
							<div class="d-flex flex-wrap align-items-center">
								<div class="progress-data">
									<div id="chart4"></div>
								</div>
								<div class="widget-data">
									<div class="h4 mb-0">$6060</div>
									<div class="weight-600 font-14">Worth</div>
								</div>
							</div>
						</div>
					</div>
				</div>-->
				<!--<div class="row">
					<div class="col-xl-8 mb-30">
						<div class="card-box height-100-p pd-20">
							<h2 class="h4 mb-20">Activity</h2>
							<div id="chart5"></div>
						</div>
					</div>
					<div class="col-xl-4 mb-30">
						<div class="card-box height-100-p pd-20">
							<h2 class="h4 mb-20">Lead Target</h2>
							<div id="chart6"></div>
						</div>
					</div>
				</div>
				<div class="card-box mb-30">
					<h2 class="h4 pd-20">Best Selling Products</h2>
					<table class="data-table table nowrap">
						<thead>
							<tr>
								<th class="table-plus datatable-nosort">Product</th>
								<th>Name</th>
								<th>Color</th>
								<th>Size</th>
								<th>Price</th>
								<th>Oty</th>
								<th class="datatable-nosort">Action</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="table-plus">
									<img
										src="vendors/images/product-1.jpg"
										width="70"
										height="70"
										alt=""
									/>
								</td>
								<td>
									<h5 class="font-16">Shirt</h5>
									by John Doe
								</td>
								<td>Black</td>
								<td>M</td>
								<td>$1000</td>
								<td>1</td>
								<td>
									<div class="dropdown">
										<a
											class="btn btn-link font-24 p-0 line-height-1 no-arrow dropdown-toggle"
											href="#"
											role="button"
											data-toggle="dropdown"
										>
											<i class="dw dw-more"></i>
										</a>
										<div
											class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
										>
											<a class="dropdown-item" href="#"
												><i class="dw dw-eye"></i> View</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-edit2"></i> Edit</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-delete-3"></i> Delete</a
											>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<td class="table-plus">
									<img
										src="vendors/images/product-2.jpg"
										width="70"
										height="70"
										alt=""
									/>
								</td>
								<td>
									<h5 class="font-16">Boots</h5>
									by Lea R. Frith
								</td>
								<td>brown</td>
								<td>9UK</td>
								<td>$900</td>
								<td>1</td>
								<td>
									<div class="dropdown">
										<a
											class="btn btn-link font-24 p-0 line-height-1 no-arrow dropdown-toggle"
											href="#"
											role="button"
											data-toggle="dropdown"
										>
											<i class="dw dw-more"></i>
										</a>
										<div
											class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
										>
											<a class="dropdown-item" href="#"
												><i class="dw dw-eye"></i> View</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-edit2"></i> Edit</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-delete-3"></i> Delete</a
											>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<td class="table-plus">
									<img
										src="vendors/images/product-3.jpg"
										width="70"
										height="70"
										alt=""
									/>
								</td>
								<td>
									<h5 class="font-16">Hat</h5>
									by Erik L. Richards
								</td>
								<td>Orange</td>
								<td>M</td>
								<td>$100</td>
								<td>4</td>
								<td>
									<div class="dropdown">
										<a
											class="btn btn-link font-24 p-0 line-height-1 no-arrow dropdown-toggle"
											href="#"
											role="button"
											data-toggle="dropdown"
										>
											<i class="dw dw-more"></i>
										</a>
										<div
											class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
										>
											<a class="dropdown-item" href="#"
												><i class="dw dw-eye"></i> View</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-edit2"></i> Edit</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-delete-3"></i> Delete</a
											>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<td class="table-plus">
									<img
										src="vendors/images/product-4.jpg"
										width="70"
										height="70"
										alt=""
									/>
								</td>
								<td>
									<h5 class="font-16">Long Dress</h5>
									by Renee I. Hansen
								</td>
								<td>Gray</td>
								<td>L</td>
								<td>$1000</td>
								<td>1</td>
								<td>
									<div class="dropdown">
										<a
											class="btn btn-link font-24 p-0 line-height-1 no-arrow dropdown-toggle"
											href="#"
											role="button"
											data-toggle="dropdown"
										>
											<i class="dw dw-more"></i>
										</a>
										<div
											class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
										>
											<a class="dropdown-item" href="#"
												><i class="dw dw-eye"></i> View</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-edit2"></i> Edit</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-delete-3"></i> Delete</a
											>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<td class="table-plus">
									<img
										src="vendors/images/product-5.jpg"
										width="70"
										height="70"
										alt=""
									/>
								</td>
								<td>
									<h5 class="font-16">Blazer</h5>
									by Vicki M. Coleman
								</td>
								<td>Blue</td>
								<td>M</td>
								<td>$1000</td>
								<td>1</td>
								<td>
									<div class="dropdown">
										<a
											class="btn btn-link font-24 p-0 line-height-1 no-arrow dropdown-toggle"
											href="#"
											role="button"
											data-toggle="dropdown"
										>
											<i class="dw dw-more"></i>
										</a>
										<div
											class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
										>
											<a class="dropdown-item" href="#"
												><i class="dw dw-eye"></i> View</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-edit2"></i> Edit</a
											>
											<a class="dropdown-item" href="#"
												><i class="dw dw-delete-3"></i> Delete</a
											>
										</div>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>-->
				<?php include("template/footer.php"); ?>
			</div>
		</div>
		
		</button>
		<!-- welcome modal end -->
		<!-- js -->
		<script src="vendors/scripts/core.js"></script>
		<script src="vendors/scripts/script.min.js"></script>
		<script src="vendors/scripts/process.js"></script>
		<script src="vendors/scripts/layout-settings.js"></script>
		<script src="src/plugins/apexcharts/apexcharts.min.js"></script>
		<script src="vendors/scripts/dashboard.js"></script>

		<?php if ($dash_visible): ?>
		<script>
		$(document).ready(function () {
			var dashPersonas = {
				promotor: <?= json_encode($es_admin ? $promotores_dash : []) ?>,
				distribuidor: <?= json_encode($es_admin ? $distribuidores_dash : []) ?>,
				otros: <?= json_encode($es_admin ? $otros_dash : []) ?>,
			};
			var dashRolLabels = { promotor: 'de Eureka', distribuidor: 'los distribuidores', otros: 'los otros roles' };

			function actualizarSelectPersona() {
				var rol = $('#dash-rol').val();
				var $persona = $('#dash-persona');
				if (!rol) {
					$persona.hide().val('');
					return;
				}
				var lista = dashPersonas[rol] || [];
				var opciones = '<option value="">Todos ' + dashRolLabels[rol] + '</option>';
				lista.forEach(function (u) {
					opciones += '<option value="' + u.id + '">' + $('<div>').text(u.nombres + ' ' + u.apellidos).html() + '</option>';
				});
				$persona.html(opciones).show();
			}

			$('#dash-rol').on('change', function () {
				actualizarSelectPersona();
				cargarPanel();
			});

			function filtros() {
				return {
					periodo: $('#dash-periodo').val(),
					rol: $('#dash-rol').val(),
					usuario: $('#dash-persona').val(),
				};
			}

			function fmtCOP(v) {
				return '$' + Math.round(v).toLocaleString('es-CO') + ' COP';
			}

			function fmtCOPShort(v) {
				return '$' + (v / 1000000).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
			}

			var dashBarColors = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'];
			var rankingPresupCodigos = [];
			var rankingAdopCodigos = [];
			var rankingDescuentoCodigos = [];
			var rankingVentaRealCodigos = [];

			function irAColegio(codigo, tab) {
				if (!codigo) return;
				window.location.href = 'colegio.php?codigo=' + encodeURIComponent(codigo) + '&periodo=' + $('#dash-periodo').val() + '&tab=' + tab;
			}

			function bindColegioLabelClicks(containerSelector, getCodigos, tab, axisGroupClass) {
				axisGroupClass = axisGroupClass || 'apexcharts-yaxis-texts-g';
				document.querySelector(containerSelector).addEventListener('click', function (e) {
					var el = e.target.closest('.dash-cole-link');
					if (!el) return;
					// El texto visible se trunca con "..." en nombres largos, así que no se puede
					// usar para identificar el colegio. En cambio, las etiquetas del eje de
					// categorías se renderizan en el mismo orden que los datos dentro de este
					// grupo SVG, así que usamos su posición para ubicar el código correcto.
					var grupo = el.closest('.' + axisGroupClass);
					if (!grupo) return;
					var idx = Array.prototype.indexOf.call(grupo.querySelectorAll('.dash-cole-link'), el);
					if (idx === -1) return;
					irAColegio(getCodigos()[idx], tab);
				});
			}

			// ── Visitas planificadas ──
			var chartEfectividad = new ApexCharts(document.querySelector("#chart-efectividad-visitas"), {
				series: [0, 0],
				chart: { type: 'donut', height: 300 },
				labels: ['Efectivas', 'No efectivas'],
				colors: ['#2ecc71', '#e5484d'],
				legend: { position: 'bottom', fontSize: '12px' },
				dataLabels: { enabled: true, formatter: function (val) { return val.toFixed(1) + '%'; } },
				plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Registradas' } } } } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartEfectividad.render();

			var chartRankingVisitas = new ApexCharts(document.querySelector("#chart-ranking-promotores"), {
				series: [{ name: 'Visitas planificadas', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true },
				xaxis: { categories: [] },
				grid: { strokeDashArray: 4 },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartRankingVisitas.render();

			var chartObjetivosVisitas = new ApexCharts(document.querySelector("#chart-objetivos-visitas"), {
				series: [],
				chart: { type: 'pie', height: 320 },
				labels: [],
				colors: ['#9b59b6', '#4361ee', '#2ecc71', '#f77f00', '#e5484d', '#94a3b8', '#00b8d9', '#ff6b6b'],
				legend: { position: 'bottom', fontSize: '12px' },
				dataLabels: { enabled: true, formatter: function (val) { return val.toFixed(1) + '%'; } },
				tooltip: { y: { formatter: function (v) { return v + ' visitas'; } } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartObjetivosVisitas.render();

			<?php if (!$solo_visitas_dash): ?>
			// ── Presupuestos ──
			var chartProbabilidadPresup = new ApexCharts(document.querySelector("#chart-probabilidad-presup"), {
				series: [],
				chart: { type: 'donut', height: 300 },
				labels: [],
				colors: ['#2ecc71', '#4361ee', '#f77f00', '#9b59b6', '#e5484d', '#94a3b8'],
				legend: { position: 'bottom', fontSize: '12px' },
				dataLabels: { enabled: true, formatter: function (val) { return val.toFixed(1) + '%'; } },
				plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Venta potencial', formatter: function (w) { return fmtCOPShort(w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0)); } } } } } },
				tooltip: { y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartProbabilidadPresup.render();

			var chartRankingPresup = new ApexCharts(document.querySelector("#chart-ranking-presup"), {
				series: [{ name: 'Venta potencial', data: [] }],
				chart: { type: 'bar', height: 300, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [], labels: { formatter: fmtCOPShort } },
				yaxis: { labels: { style: { cssClass: 'dash-cole-link' } } },
				grid: { strokeDashArray: 4 },
				tooltip: { y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartRankingPresup.render();
			bindColegioLabelClicks('#chart-ranking-presup', function () { return rankingPresupCodigos; }, 'presupuesto');

			<?php if ($es_admin): ?>
			var chartEditorialPresup = new ApexCharts(document.querySelector("#chart-editorial-presup"), {
				series: [{ name: 'Venta potencial', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: false, columnWidth: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [] },
				yaxis: { labels: { formatter: fmtCOPShort } },
				grid: { strokeDashArray: 4 },
				tooltip: { shared: false, intersect: false, y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartEditorialPresup.render();
			<?php endif; ?>

			// ── Adopciones ──
			function fmtPct(val) { return val.toFixed(1) + '%'; }

			var chartDescuentoAdop = new ApexCharts(document.querySelector("#chart-descuento-adop"), {
				series: [{ name: 'Descuento promedio', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: false, columnWidth: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtPct },
				xaxis: { categories: [], labels: { style: { cssClass: 'dash-cole-link', fontSize: '10px' }, rotate: -45, trim: true, hideOverlappingLabels: false } },
				yaxis: { labels: { formatter: fmtPct } },
				grid: { strokeDashArray: 4 },
				tooltip: { y: { formatter: fmtPct } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartDescuentoAdop.render();
			bindColegioLabelClicks('#chart-descuento-adop', function () { return rankingDescuentoCodigos; }, 'adopciones', 'apexcharts-xaxis-texts-g');

			var chartRankingAdop = new ApexCharts(document.querySelector("#chart-ranking-adop"), {
				series: [{ name: 'Venta potencial', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [], labels: { formatter: fmtCOPShort } },
				yaxis: { labels: { style: { cssClass: 'dash-cole-link' } } },
				grid: { strokeDashArray: 4 },
				tooltip: { y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartRankingAdop.render();
			bindColegioLabelClicks('#chart-ranking-adop', function () { return rankingAdopCodigos; }, 'adopciones');

			<?php if ($es_admin): ?>
			var chartEditorialAdop = new ApexCharts(document.querySelector("#chart-editorial-adop"), {
				series: [{ name: 'Venta potencial', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: false, columnWidth: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [] },
				yaxis: { labels: { formatter: fmtCOPShort } },
				grid: { strokeDashArray: 4 },
				tooltip: { shared: false, intersect: false, y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartEditorialAdop.render();
			<?php endif; ?>

			var chartRankingVentaReal = new ApexCharts(document.querySelector("#chart-ranking-ventareal"), {
				series: [{ name: 'Venta real', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [], labels: { formatter: fmtCOPShort } },
				yaxis: { labels: { style: { cssClass: 'dash-cole-link' } } },
				grid: { strokeDashArray: 4 },
				tooltip: { y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartRankingVentaReal.render();
			bindColegioLabelClicks('#chart-ranking-ventareal', function () { return rankingVentaRealCodigos; }, 'adopciones');

			<?php if ($es_admin): ?>
			var chartEditorialVentaReal = new ApexCharts(document.querySelector("#chart-editorial-ventareal"), {
				series: [{ name: 'Venta real', data: [] }],
				chart: { type: 'bar', height: 320, toolbar: { show: false } },
				colors: dashBarColors,
				plotOptions: { bar: { borderRadius: 6, horizontal: false, columnWidth: '55%', distributed: true } },
				legend: { show: false },
				dataLabels: { enabled: true, formatter: fmtCOPShort },
				xaxis: { categories: [] },
				yaxis: { labels: { formatter: fmtCOPShort } },
				grid: { strokeDashArray: 4 },
				tooltip: { shared: false, intersect: false, y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartEditorialVentaReal.render();
			<?php endif; ?>
			<?php endif; // !$solo_visitas_dash ?>

			<?php if ($es_admin): ?>
			var chartAsesores = new ApexCharts(document.querySelector("#chart-asesores"), {
				series: [{ name: 'Presupuesto', data: [] }, { name: 'Adopciones', data: [] }, { name: 'Venta real', data: [] }],
				chart: { type: 'bar', height: 340, toolbar: { show: false } },
				colors: [dashBarColors[0], dashBarColors[1], dashBarColors[3]],
				plotOptions: { bar: { borderRadius: 6, horizontal: false, columnWidth: '60%' } },
				legend: { show: true, position: 'top', fontSize: '12px' },
				dataLabels: { enabled: false },
				xaxis: { categories: [], labels: { style: { fontSize: '10px' }, rotate: -45, trim: true, hideOverlappingLabels: false } },
				yaxis: { labels: { formatter: fmtCOPShort } },
				grid: { strokeDashArray: 4 },
				tooltip: { shared: true, y: { formatter: fmtCOP } },
				noData: { text: 'Sin datos para mostrar' },
			});
			chartAsesores.render();
			<?php endif; ?>

			function mostrarCargando() {
				$('#dash-loading').addClass('show');
				$('#dash-visitas').addClass('dash-loading-overlay');
				$('#dash-rol, #dash-persona, #dash-periodo').prop('disabled', true);
			}

			function ocultarCargando() {
				$('#dash-loading').removeClass('show');
				$('#dash-visitas').removeClass('dash-loading-overlay');
				$('#dash-rol, #dash-persona, #dash-periodo').prop('disabled', false);
			}

			function cargarVisitas() {
				if ($('#dash-rol').val() === 'distribuidor') {
					$('#dash-visitas-section').hide();
					return $.Deferred().resolve().promise();
				}
				$('#dash-visitas-section').show();
				return $.getJSON('php/dashboard_visitas_stats.php', filtros(), function (resp) {
					if (!resp.success) return;
					var s = resp.stats;

					$('#dv-planificadas').text(s.planificadas.toLocaleString('es-CO'));
					$('#dv-efectividad').text(s.efectividad_pct + '%');
					$('#dv-efectividad-sub').text(s.efectivas.toLocaleString('es-CO') + ' de ' + s.planificadas.toLocaleString('es-CO') + ' planificadas');
					$('#dv-ranking-sub').text('Período ' + resp.periodo);

					chartEfectividad.updateSeries([s.efectivas, s.no_efectivas]);

					chartRankingVisitas.updateOptions({
						chart: { height: Math.max(320, resp.ranking.labels.length * 32) },
						xaxis: { categories: resp.ranking.labels },
					});
					chartRankingVisitas.updateSeries([{ name: 'Visitas planificadas', data: resp.ranking.data }]);

					chartObjetivosVisitas.updateOptions({ labels: resp.objetivos.labels });
					chartObjetivosVisitas.updateSeries(resp.objetivos.data);
				});
			}

			function cargarPresupuestos() {
				return $.getJSON('php/dashboard_presupuestos_stats.php', filtros(), function (resp) {
					if (!resp.success) return;
					var s = resp.stats;

					$('#dp-venta-potencial').text(fmtCOP(s.venta_potencial));
					$('#dp-venta-potencial-sub').text('Período ' + resp.periodo + ' · ítems en definición');
					$('#dp-total').text(s.total_items.toLocaleString('es-CO'));
					$('#dp-definidos').text(s.pct_definidos + '%');
					$('#dp-definidos-sub').text(s.definidos.toLocaleString('es-CO') + ' de ' + s.total_items.toLocaleString('es-CO') + ' ítems');
					$('#dp-ranking-sub').text('Período ' + resp.periodo);

					chartProbabilidadPresup.updateOptions({ labels: resp.probabilidad.labels });
					chartProbabilidadPresup.updateSeries(resp.probabilidad.data);

					rankingPresupCodigos = resp.ranking.codigos || [];
					chartRankingPresup.updateOptions({ xaxis: { categories: resp.ranking.labels } });
					chartRankingPresup.updateSeries([{ name: 'Venta potencial', data: resp.ranking.data }]);

					<?php if ($es_admin): ?>
					$('#dp-editorial-sub').text('Período ' + resp.periodo);
					chartEditorialPresup.updateOptions({ xaxis: { categories: resp.editoriales.labels } });
					chartEditorialPresup.updateSeries([{ name: 'Venta potencial', data: resp.editoriales.data }]);
					<?php endif; ?>
				});
			}

			function cargarAdopciones() {
				return $.getJSON('php/dashboard_adopciones_stats.php', filtros(), function (resp) {
					if (!resp.success) return;
					var s = resp.stats;

					$('#da-venta').text(fmtCOP(s.venta_potencial));
					$('#da-venta-sub').text(fmtCOP(s.venta_promedio_titulo) + ' promedio por título adoptado');
					$('#da-adoptados').text(s.total_adoptados.toLocaleString('es-CO'));
					$('#da-colegios').text(s.colegios_con_adopcion.toLocaleString('es-CO'));
					$('#da-colegios-sub').text(s.promedio_por_colegio.toLocaleString('es-CO') + ' títulos por colegio (prom.)');
					$('#da-ranking-sub').text('Período ' + resp.periodo);
					$('#da-descuento-sub').text('Período ' + resp.periodo);

					if (resp.materias.labels.length) {
						$('#da-materia-top').text(fmtCOPShort(resp.materias.data[0]));
						$('#da-materia-top-sub').text(resp.materias.labels[0]);
					} else {
						$('#da-materia-top').text('—');
						$('#da-materia-top-sub').text('Mayor venta potencial');
					}

					rankingDescuentoCodigos = resp.descuentos.codigos || [];
					chartDescuentoAdop.updateOptions({ xaxis: { categories: resp.descuentos.labels } });
					chartDescuentoAdop.updateSeries([{ name: 'Descuento promedio', data: resp.descuentos.data }]);
					$('#btn-exportar-descuento').attr('href', 'php/descuento_adopciones_excel.php?' + $.param(filtros()));

					rankingAdopCodigos = resp.ranking.codigos || [];
					chartRankingAdop.updateOptions({ xaxis: { categories: resp.ranking.labels } });
					chartRankingAdop.updateSeries([{ name: 'Venta potencial', data: resp.ranking.data }]);

					<?php if ($es_admin): ?>
					$('#da-editorial-sub').text('Período ' + resp.periodo);
					chartEditorialAdop.updateOptions({ xaxis: { categories: resp.editoriales.labels } });
					chartEditorialAdop.updateSeries([{ name: 'Venta potencial', data: resp.editoriales.data }]);
					<?php endif; ?>
				});
			}

			function cargarVentaReal() {
				return $.getJSON('php/dashboard_ventareal_stats.php', filtros(), function (resp) {
					if (!resp.success) return;
					var s = resp.stats;

					$('#dvr-total').text(fmtCOP(s.venta_real_total));
					$('#dvr-colegios').text(s.colegios_con_venta.toLocaleString('es-CO'));
					$('#dvr-ranking-sub').text('Período ' + resp.periodo);

					rankingVentaRealCodigos = resp.ranking.codigos || [];
					chartRankingVentaReal.updateOptions({ xaxis: { categories: resp.ranking.labels } });
					chartRankingVentaReal.updateSeries([{ name: 'Venta real', data: resp.ranking.data }]);

					<?php if ($es_admin): ?>
					$('#dvr-editorial-sub').text('Período ' + resp.periodo);
					chartEditorialVentaReal.updateOptions({ xaxis: { categories: resp.editoriales.labels } });
					chartEditorialVentaReal.updateSeries([{ name: 'Venta real', data: resp.editoriales.data }]);
					<?php endif; ?>
				});
			}

			<?php if ($es_admin): ?>
			function cargarAsesores() {
				return $.getJSON('php/dashboard_asesores_stats.php', filtros(), function (resp) {
					if (!resp.success) return;

					$('#da-asesores-sub').text('Período ' + resp.periodo);
					$('#btn-valorizacion-global').attr('href', 'reporte_valoriza_global.php?periodo=' + $('#dash-periodo').val());

					chartAsesores.updateOptions({ xaxis: { categories: resp.asesores.labels } });
					chartAsesores.updateSeries([
						{ name: 'Presupuesto', data: resp.asesores.presupuesto },
						{ name: 'Adopciones', data: resp.asesores.adopciones },
						{ name: 'Venta real', data: resp.asesores.venta_real },
					]);
				});
			}
			<?php endif; ?>

			function cargarPanel() {
				mostrarCargando();
				<?php if ($solo_visitas_dash): ?>
				$.when(cargarVisitas()).always(ocultarCargando);
				<?php else: ?>
				$.when(cargarVisitas(), cargarPresupuestos(), cargarAdopciones(), cargarVentaReal()<?php if ($es_admin): ?>, cargarAsesores()<?php endif; ?>).always(ocultarCargando);
				<?php endif; ?>
			}

			cargarPanel();

			$('#dash-periodo, #dash-persona').on('change', cargarPanel);

			<?php if ($es_admin): ?>
			$('#btn-valorizacion-global').on('click', function (e) {
				e.preventDefault();
				// promotor: el usuario específico seleccionado en el filtro del dashboard, o
				// "0" (Todos) cuando no hay uno puntual elegido. rol: el grupo seleccionado
				// (Eureka/Distribuidores/Otros) cuando no se eligió una persona puntual — sin
				// esto, el reporte ignoraba el filtro de rol y traía todos los grupos.
				$('#vg-periodo').val($('#dash-periodo').val());
				$('#vg-promotor').val($('#dash-persona').val() || '0');
				$('#vg-rol').val($('#dash-rol').val() || '');
				$('#form-valoriza-global').trigger('submit');
			});
			<?php endif; ?>
		});
		</script>
		<?php endif; ?>

	</body>
</html>
