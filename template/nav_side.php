<?php
require_once('conexion/bdd.php');

// Detección de página activa para el menú
$current_page = basename($_SERVER['PHP_SELF']);

$zonificacion_active = in_array($current_page, ['ver_colegios.php','agregar_colegio.php','colegio.php','colegio2.php']);
$agenda_active       = $current_page === 'agenda.php';
$muestreo_active     = in_array($current_page, ['solicitar_muestreo.php','lista_muestreo.php','ver_muestreo.php','muestras_entregadas.php']);
$pedidos_active      = in_array($current_page, ['ver_pedidos.php','lista_pedidos.php','agrupar_pedidos.php','pedido_colegio.php']);
$devoluciones_active = in_array($current_page, ['devol_muestras_sa.php','ver_devol_muestras.php','ver_devol_ventas.php','ver_devol_proveedores.php','proveedores.php']);
$pedidos_sa_active   = in_array($current_page, ['solicitar_pedido_sa.php','ver_pedidos_sa.php','lista_pedidos_sa.php','pedido_colegio_sa.php']);
$presupuesto_active  = $current_page === 'colegios_presup.php';
$atenciones_active   = $current_page === 'lista_atenciones.php';
$ops_active          = in_array($current_page, ['solicitar_op.php','lista_op.php','clientes_op.php']);
$opds_active         = in_array($current_page, ['solicitar_orden_pd.php','ver_opds.php','reporte_opd.php']);
$reportes_active     = in_array($current_page, ['reporte_zonificacion.php','reporte_cubrimiento.php','reporte_visitas.php','reporte_atenciones.php','calendar_ti.php','reporte_valoriza.php','reporte_valoriza_global.php','reporte_trabajadores.php','reporte_cant_adop.php','reporte_muestreo_f.php','reporte_pedidos.php','reporte_devoluciones.php']);
$libros_active       = $current_page === 'libros.php';
$usuarios_active     = $current_page === 'usuarios.php';
?>
<!--<div class="pre-loader">
			<div class="pre-loader-box">
				<div class="loader-logo">
					<img src="vendors/images/deskapp-logo.svg" alt="" />
				</div>
				<div class="loader-progress" id="progress_div">
					<div class="bar" id="bar1"></div>
				</div>
				<div class="percent" id="percent1">0%</div>
				<div class="loading-text">Loading...</div>
			</div>
		</div>-->

		<div class="header d-print-none">
			<div class="header-left">
				<div class="menu-icon bi bi-list"></div>
				<div
					class="search-toggle-icon bi bi-search"
					data-toggle="header_search"
				></div>
				
			</div>
			<div class="header-right">
				
				<div class="user-notification">
					<div class="dropdown">
						<a
							class="dropdown-toggle no-arrow"
							href="#"
							role="button"
							data-toggle="dropdown"
						>
							<i class="icon-copy dw dw-notification"></i>
							<span class="badge notification-active"></span>
						</a>
						<div class="dropdown-menu dropdown-menu-right">
							<div class="notification-list mx-h-350 customscroll">
								<ul>
									<li>
										<a href="#">
											<h3>Eureka</h3>
											<p>
												Bienvenido(a) al nuevo CRM
											</p>
										</a>
									</li>
								</ul>
							</div>
						</div>
					</div>
				</div>
				<div class="user-info-dropdown">
					<div class="dropdown">
						<a
							class="dropdown-toggle"
							href="#"
							role="button"
							data-toggle="dropdown"
						>
							<!--<span class="user-icon">
								<img src="vendors/images/photo1.jpg" alt="" />
							</span>-->
							<?php 
														
								$sql = "SELECT CONCAT(nombres, ' ',apellidos)  as nombre_completo FROM usuarios WHERE id='".$_SESSION['id']."'";

								$req = $bdd->prepare($sql);
								$req->execute();
								$usuario = $req->fetch();

								echo '<span class="user-name">'.$usuario["nombre_completo"].'</span>';
							?>
							
						</a>
						<div
							class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list"
						>
							<!--<a class="dropdown-item" href="profile.html"
								><i class="dw dw-user1"></i> Profile</a
							>
							<a class="dropdown-item" href="profile.html"
								><i class="dw dw-settings2"></i> Setting</a
							>
							<a class="dropdown-item" href="faq.html"
								><i class="dw dw-help"></i> Help</a
							>-->
							<a class="dropdown-item" href="php/cerrar_sesion.php"
								><i class="dw dw-logout"></i> Salir</a
							>
						</div>
					</div>
				</div>
				<!--<div class="github-link">
					<a href="https://github.com/dropways/deskapp" target="_blank"
						><img src="vendors/images/github.svg" alt=""
					/></a>
				</div>-->
			</div>
		</div>

		<div class="right-sidebar d-print-none">
			<div class="sidebar-title">
				<h3 class="weight-600 font-16 text-blue">
					Layout Settings
					<span class="btn-block font-weight-400 font-12"
						>User Interface Settings</span
					>
				</h3>
				<div class="close-sidebar" data-toggle="right-sidebar-close">
					<i class="icon-copy ion-close-round"></i>
				</div>
			</div>
			<div class="right-sidebar-body customscroll">
				<div class="right-sidebar-body-content">
					<h4 class="weight-600 font-18 pb-10">Header Background</h4>
					<div class="sidebar-btn-group pb-30 mb-10">
						<a
							href="javascript:void(0);"
							class="btn btn-outline-primary header-white active"
							>White</a
						>
						<a
							href="javascript:void(0);"
							class="btn btn-outline-primary header-dark"
							>Dark</a
						>
					</div>

					<h4 class="weight-600 font-18 pb-10">Sidebar Background</h4>
					<div class="sidebar-btn-group pb-30 mb-10">
						<a
							href="javascript:void(0);"
							class="btn btn-outline-primary sidebar-light"
							>White</a
						>
						<a
							href="javascript:void(0);"
							class="btn btn-outline-primary sidebar-dark active"
							>Dark</a
						>
					</div>

					<h4 class="weight-600 font-18 pb-10">Menu Dropdown Icon</h4>
					<div class="sidebar-radio-group pb-10 mb-10">
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebaricon-1"
								name="menu-dropdown-icon"
								class="custom-control-input"
								value="icon-style-1"
								checked=""
							/>
							<label class="custom-control-label" for="sidebaricon-1"
								><i class="fa fa-angle-down"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebaricon-2"
								name="menu-dropdown-icon"
								class="custom-control-input"
								value="icon-style-2"
							/>
							<label class="custom-control-label" for="sidebaricon-2"
								><i class="ion-plus-round"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebaricon-3"
								name="menu-dropdown-icon"
								class="custom-control-input"
								value="icon-style-3"
							/>
							<label class="custom-control-label" for="sidebaricon-3"
								><i class="fa fa-angle-double-right"></i
							></label>
						</div>
					</div>

					<h4 class="weight-600 font-18 pb-10">Menu List Icon</h4>
					<div class="sidebar-radio-group pb-30 mb-10">
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-1"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-1"
								checked=""
							/>
							<label class="custom-control-label" for="sidebariconlist-1"
								><i class="ion-minus-round"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-2"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-2"
							/>
							<label class="custom-control-label" for="sidebariconlist-2"
								><i class="fa fa-circle-o" aria-hidden="true"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-3"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-3"
							/>
							<label class="custom-control-label" for="sidebariconlist-3"
								><i class="dw dw-check"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-4"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-4"
								checked=""
							/>
							<label class="custom-control-label" for="sidebariconlist-4"
								><i class="icon-copy dw dw-next-2"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-5"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-5"
							/>
							<label class="custom-control-label" for="sidebariconlist-5"
								><i class="dw dw-fast-forward-1"></i
							></label>
						</div>
						<div class="custom-control custom-radio custom-control-inline">
							<input
								type="radio"
								id="sidebariconlist-6"
								name="menu-list-icon"
								class="custom-control-input"
								value="icon-list-style-6"
							/>
							<label class="custom-control-label" for="sidebariconlist-6"
								><i class="dw dw-next"></i
							></label>
						</div>
					</div>

					<div class="reset-options pt-30 text-center">
						<button class="btn btn-danger" id="reset-settings">
							Reset Settings
						</button>
					</div>
				</div>
			</div>
		</div>

		<div class="left-side-bar sidebar-modern">
			<div class="brand-logo">
				<a href="index.php">
					<img src="vendors/images/logo_ink-pulse.png" alt="" class="dark-logo" />
					<img
						src="vendors/images/logo_ink-pulse.png"
						alt=""
						class="light-logo"
					/>
				</a>
				<div class="close-sidebar" data-toggle="left-sidebar-close">
					<i class="ion-close-round"></i>
				</div>
			</div>
			<div class="menu-block customscroll">

				<div class="sidebar-menu">
					<ul id="accordion-menu">
						<li><div class="sidebar-small-cap nav-sec">Navegación</div></li>
						<li>
							<a href="index.php" class="dropdown-toggle no-arrow <?= $current_page==='index.php' ? 'active' : '' ?>">
								<span class="micon bi bi-house"></span
								><span class="mtext">Inicio</span>
							</a>
						</li>
						<?php if ($_SESSION["tipo"] !=8) {?>
							<li class="dropdown <?= $zonificacion_active ? 'show' : '' ?>" id="zonificacion">
								<a href="javascript:;" class="dropdown-toggle <?= $zonificacion_active ? 'active' : '' ?>">
									<span class="micon bi bi-building"></span
									><span class="mtext">Zonificación</span>
								</a>
								<ul class="submenu" <?= $zonificacion_active ? 'style="display:block"' : '' ?>>
									<?php if ($_SESSION["tipo"] ==1) {?>
										<li>
											<a href="agregar_colegio.php" class="<?= $current_page==='agregar_colegio.php' ? 'active' : '' ?>">Crear colegio</a>
										</li>
									<?php } ?>
									<li>
										<a href="ver_colegios.php" class="<?= $current_page==='ver_colegios.php' ? 'active' : '' ?>" id="ver_zonificacion">Ver colegios</a>
									</li>
								</ul>
							</li>
							<li>
								<a href="agenda.php" class="dropdown-toggle no-arrow <?= $agenda_active ? 'active' : '' ?>" id="plan_trabajo">
									<span class="micon bi bi-calendar4-week"></span
									><span class="mtext">Plan de trabajo</span>
								</a>
							</li>
						<?php }?>
						
						<?php if ($_SESSION["tipo"] !=5  && $_SESSION["tipo"] !=4 && $_SESSION["tipo"] !=8) {?>
							<li><div class="sidebar-small-cap proc-sec">Procesos</div></li>
							<li class="dropdown <?= $muestreo_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $muestreo_active ? 'active' : '' ?>">
									<span class="micon bi bi-book"></span
									><span class="mtext">Muestreo</span>
								</a>
								<ul class="submenu" <?= $muestreo_active ? 'style="display:block"' : '' ?>>
									<?php if ($_SESSION["tipo"]!=3 && $_SESSION["tipo"]!=6 ) {?>
										<li>
											<a href="solicitar_muestreo.php?tp=1" id="">Solicitar muestreo</a>
										</li>
										<li>
											<a href="lista_muestreo.php?tp=2" id="">Pendientes</a>
										</li>
										<li>
											<a href="lista_muestreo.php?tp=3" id="">Aprobados</a>
										</li>
										<li>
											<a href="lista_muestreo.php?tp=4" id="">Despachados</a>
										</li>
										<li>
											<a href="lista_muestreo.php?tp=5" id="">Anulados</a>
										</li>
										<li>
											<a href="solicitar_muestreo.php?tp=2" id="">Legalizar muestras</a>
										</li>
										<li>
											<a href="muestras_entregadas.php" id="">Muestras legalizadas</a>
										</li>
									<?php }else{ ?>
										<li>
											<a href="solicitar_muestreo.php?tp=1" id="">Solicitar muestreo</a>
										</li>
										<li>
											<a href="ver_muestreo.php" id="">Muestras solicitadas</a>
										</li>
										<li>
											<a href="solicitar_muestreo.php?tp=2" id="">Legalizar muestras</a>
										</li>
										<li>
											<a href="muestras_entregadas.php" id="">Muestras legalizadas</a>
										</li>
									<?php } ?>
									
								</ul>
							</li>

						<?php } ?>

						<?php if ($_SESSION["tipo"] !=4 && $_SESSION["tipo"] !=8 && $_SESSION["tipo"] !=5) {?>

							<li class="dropdown <?= $pedidos_active ? 'show' : '' ?>" id="pedidos">
								<a href="javascript:;" class="dropdown-toggle <?= $pedidos_active ? 'active' : '' ?>">
									<span class="micon bi bi-truck"></span
									><span class="mtext">Pedidos</span>
								</a>
								<ul class="submenu">
									<?php if ($_SESSION["tipo"]==3 || $_SESSION["tipo"]==6) {?>
										<li>
											<a href="#"  data-toggle="modal" data-target="#modal_pedidos">Solicitar pedido</a>
										</li>
										<li>
											<a href="ver_pedidos.php" >Ver pedidos</a>
										</li>
									<?php }else { ?>
										<li>
											<a href="#"  data-toggle="modal" data-target="#modal_pedidos">Solicitar</a>
										</li>
										<li>
											<a href="lista_pedidos.php?tp=2" >Pendientes</a>
											<a href="lista_pedidos.php?tp=3" >Aprobados</a>
											<a href="lista_pedidos.php?tp=4" >Entregados</a>
											<a href="agrupar_pedidos.php" >Agrupar pedidos</a>
											<a href="lista_pedidos.php?tp=5" >Anulados</a>
										</li>
									<?php } ?>
									
									
						
								</ul>
							</li>

							<li class="dropdown <?= $devoluciones_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $devoluciones_active ? 'active' : '' ?>">
									<span class="micon bi bi-arrow-bar-left"></span
									><span class="mtext">Devoluciones</span>
								</a>
								<ul class="submenu">

									<?php if ($_SESSION["tipo"]==1 || $_SESSION["tipo"] ==2) { ?>
										<li class="menu-subgroup-label">Proveedores</li>
										<li><a href="proveedores.php">Cargar Proveedores</a></li>
										<li><a href="devol_muestras_sa.php?tp=2">Devoluciones de proveedores</a></li>
										<li><a href="ver_devol_proveedores.php">Ver devoluciones de proveedores</a></li>
									<?php } ?>

									<li class="menu-subgroup-label">Muestras</li>
									<li><a href="devol_muestras_sa.php?tp=1">Devolución de muestras</a></li>
									<li><a href="ver_devol_muestras.php">Ver devoluciones de muestras</a></li>

									<li class="menu-subgroup-label">Ventas</li>
									<li><a href="#" data-toggle="modal" data-target="#modal_devols">Devolución de ventas</a></li>
									<?php if ($_SESSION["tipo"]==1 || $_SESSION["tipo"] ==2 || $_SESSION["id"] ==2) { ?>
										<li><a href="devol_muestras_sa.php?tp=3">Devoluciones de ventas sin adopción</a></li>
									<?php } ?>
									<li><a href="ver_devol_ventas.php">Ver devoluciones de ventas</a></li>

								</ul>
							</li>

						<?php } ?>

						<?php if ($_SESSION["tipo"] !=8 && $_SESSION["tipo"] !=3 && $_SESSION["tipo"] !=10 && $_SESSION["tipo"] !=5) {?>

							<li class="dropdown <?= $pedidos_sa_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $pedidos_sa_active ? 'active' : '' ?>">
									<span class="micon bi bi-truck-flatbed"></span
									><span class="mtext">Pedidos sin adopción</span>
								</a>
								<ul class="submenu">
									<?php if ($_SESSION["tipo"] ==6 || $_SESSION["tipo"] ==4 || $_SESSION["tipo"] ==3) { ?>
										<li>
											<a href="solicitar_pedido_sa.php">Solicitar pedido</a>
										</li>
										<li>
											<a href="ver_pedidos_sa.php" >Ver pedidos</a>
										</li>
									<?php }else { ?>
										<li>
											<a href="solicitar_pedido_sa.php">Solicitar</a>
										</li>
										<li>
											<a href="lista_pedidos_sa.php?tp=2" >Pendientes</a>
											<a href="lista_pedidos_sa.php?tp=3" >Aprobados</a>
											<a href="lista_pedidos_sa.php?tp=4" >Entregados</a>
											<a href="lista_pedidos_sa.php?tp=5" >Anulados</a>
										</li>
									<?php } ?>
									
									
						
								</ul>
							</li>

						<?php } ?>

						<?php if ($_SESSION["tipo"] ==1 || $_SESSION["tipo"] ==3 || $_SESSION["tipo"] ==10) {?>
						<li><div class="sidebar-small-cap gest-sec">Gestión</div></li>
						<li>
							<a href="colegios_presup.php" class="dropdown-toggle no-arrow <?= $presupuesto_active ? 'active' : '' ?>">
								<span class="micon bi bi-currency-dollar"></span
								><span class="mtext">Presupuesto</span>
							</a>
						</li>
						<?php }?>

						<?php if ($_SESSION["tipo"] == 1) {?>
						<li>
							<a href="libros.php" class="dropdown-toggle no-arrow <?= $libros_active ? 'active' : '' ?>">
								<span class="micon bi bi-book"></span
								><span class="mtext">Libros</span>
							</a>
						</li>
						<li>
							<a href="usuarios.php" class="dropdown-toggle no-arrow <?= $usuarios_active ? 'active' : '' ?>">
								<span class="micon bi bi-people"></span
								><span class="mtext">Usuarios</span>
							</a>
						</li>
						<?php }?>

						<?php if ($_SESSION["tipo"] ==1 || $_SESSION["tipo"] ==2 || $_SESSION["tipo"] ==7 || $_SESSION["tipo"] ==9 ) {?>
							<li class="dropdown <?= $atenciones_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $atenciones_active ? 'active' : '' ?>">
									<span class="micon bi bi-gift"></span
									><span class="mtext">Atenciones</span>
								</a>
								<ul class="submenu">
									<li><a href="lista_atenciones.php?tp=1">Todas</a></li>
									<li><a href="lista_atenciones.php?tp=2">Pendientes</a></li>
									<li><a href="lista_atenciones.php?tp=3">Aprobadas</a></li>
									<li><a href="lista_atenciones.php?tp=4">Entregadas</a></li>
									<li><a href="lista_atenciones.php?tp=5">Anuladas</a></li>
								</ul>
							</li>
						<?php } ?>

						<?php if ($_SESSION["tipo"] !=3 && $_SESSION["tipo"] !=4 && $_SESSION["tipo"] !=6 && $_SESSION["tipo"] !=8 && $_SESSION["tipo"] !=10 && $_SESSION["tipo"] !=5) {?>
							<li class="dropdown <?= $ops_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $ops_active ? 'active' : '' ?>">
									<span class="micon bi bi-receipt"></span
									><span class="mtext">Órdenes de Pedido</span>
								</a>
								<ul class="submenu">
									<?php if ($_SESSION["tipo"] ==1 || $_SESSION["tipo"] ==2) {?>
										<li><a href="clientes_op.php">Cargar clientes</a></li>
									<?php } ?>
									<li><a href="solicitar_op.php">Solicitar</a></li>
									<li><a href="lista_op.php?tp=1">Todas</a></li>
									<li><a href="lista_op.php?tp=2">Pendientes</a></li>
									<li><a href="lista_op.php?tp=3">Atendidas</a></li>
									<li><a href="lista_op.php?tp=4">Anuladas</a></li>
								</ul>
							</li>
						<?php } ?>

						<?php if ($_SESSION["tipo"] ==1 || $_SESSION["tipo"] ==2 || $_SESSION["tipo"] ==8) {?>
							<li class="dropdown <?= $opds_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $opds_active ? 'active' : '' ?>">
									<span class="micon bi bi-printer"></span
									><span class="mtext">Órdenes de Producción</span>
								</a>
								<ul class="submenu">

									<?php if ($_SESSION["tipo"] !=8) {?>
										<li><a href="solicitar_orden_pd.php">Solicitar</a></li>
									<?php }?>
									<li><a href="ver_opds.php">Ver</a></li>
									
									<li><a href="reporte_opd.php">Reporte</a></li>
									
									
								</ul>
							</li>
						<?php } ?>

						<?php if ($_SESSION["tipo"] !=8) {?>
							<li><div class="sidebar-small-cap anal-sec">Análisis</div></li>
							<li class="dropdown <?= $reportes_active ? 'show' : '' ?>">
								<a href="javascript:;" class="dropdown-toggle <?= $reportes_active ? 'active' : '' ?>">
									<span class="micon bi bi-clipboard2-data"></span
									><span class="mtext">Reportes</span>
								</a>
								<ul class="submenu">

									<?php if ($_SESSION["tipo"] != 7 && $_SESSION["tipo"] != 9) { ?>
										<li class="menu-subgroup-label">Territorial</li>
										<?php if ($_SESSION["tipo"] != 4) { ?>
											<li><a href="reporte_zonificacion.php">Zonificación</a></li>
											<li><a href="reporte_cubrimiento.php">Cubrimiento</a></li>
										<?php } ?>
										<li><a href="reporte_visitas.php">Visitas</a></li>
									<?php } ?>

									<?php if (in_array($_SESSION["tipo"], [1,2,3,4,5,7,9])) { ?>
										<li class="menu-subgroup-label">Clientes</li>
										<?php if ($_SESSION["tipo"] == 1 || $_SESSION["tipo"] == 2 || $_SESSION["tipo"] == 7 || $_SESSION["tipo"] == 9 || $_SESSION["tipo"] == 5) { ?>
											<li><a href="reporte_atenciones.php">Atenciones a clientes</a></li>
										<?php } ?>
										<?php if ($_SESSION["tipo"]==1 || $_SESSION["tipo"]==3 || $_SESSION["tipo"]==4 || $_SESSION["tipo"]==7 || $_SESSION["tipo"]==5) { ?>
											<li><a href="calendar_ti.php">Calendario consultorías</a></li>
										<?php } ?>
									<?php } ?>

									<?php if ($_SESSION["tipo"] != 10 && $_SESSION["tipo"] != 4) { ?>
										<li class="menu-subgroup-label">Muestreo</li>
										<li><a href="reporte_muestreo_f.php?tp=1">Muestreos solicitados</a></li>
										<li><a href="reporte_muestreo_f.php?tp=2">Muestreos entregados</a></li>
									<?php } ?>

									<?php if ($_SESSION["tipo"] != 4) { ?>
										<li class="menu-subgroup-label">Ventas</li>
										<li><a href="reporte_valoriza.php">Valorización libro a libro</a></li>
										<li><a href="reporte_valoriza_global.php">Valorización global</a></li>
										<?php if ($_SESSION["tipo"] != 10) { ?>
											<li><a href="reporte_cant_adop.php">Cantidad adopciones</a></li>
											<li><a href="reporte_trabajadores.php">Contactos</a></li>
										<?php } ?>
										<?php if ($_SESSION["tipo"]==1 || $_SESSION["tipo"]==2) { ?>
											<li><a href="reporte_pedidos.php">Pedidos</a></li>
											<li><a href="reporte_devoluciones.php">Devoluciones</a></li>
										<?php } ?>
									<?php } ?>

									<?php if ($_SESSION["tipo"]==1 || $_SESSION["tipo"]==2) { ?>
										<li class="menu-subgroup-label">Órdenes de Pedido</li>
										<li><a href="php/oppend_excel.php">Pendientes</a></li>
										<li><a href="php/opaten_excel.php">Atendidas</a></li>
										<li><a href="php/opanu_excel.php">Anuladas</a></li>
									<?php } ?>

								</ul>
							</li>
						<?php } ?>
						
						<!--<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-archive"></span
								><span class="mtext"> UI Elements </span>
							</a>
							<ul class="submenu">
								<li><a href="ui-buttons.html">Buttons</a></li>
								<li><a href="ui-cards.html">Cards</a></li>
								<li><a href="ui-cards-hover.html">Cards Hover</a></li>
								<li><a href="ui-modals.html">Modals</a></li>
								<li><a href="ui-tabs.html">Tabs</a></li>
								<li>
									<a href="ui-tooltip-popover.html">Tooltip &amp; Popover</a>
								</li>
								<li><a href="ui-sweet-alert.html">Sweet Alert</a></li>
								<li><a href="ui-notification.html">Notification</a></li>
								<li><a href="ui-timeline.html">Timeline</a></li>
								<li><a href="ui-progressbar.html">Progressbar</a></li>
								<li><a href="ui-typography.html">Typography</a></li>
								<li><a href="ui-list-group.html">List group</a></li>
								<li><a href="ui-range-slider.html">Range slider</a></li>
								<li><a href="ui-carousel.html">Carousel</a></li>
							</ul>
						</li>
						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-command"></span
								><span class="mtext">Icons</span>
							</a>
							<ul class="submenu">
								<li><a href="bootstrap-icon.html">Bootstrap Icons</a></li>
								<li><a href="font-awesome.html">FontAwesome Icons</a></li>
								<li><a href="foundation.html">Foundation Icons</a></li>
								<li><a href="ionicons.html">Ionicons Icons</a></li>
								<li><a href="themify.html">Themify Icons</a></li>
								<li><a href="custom-icon.html">Custom Icons</a></li>
							</ul>
						</li>
						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-pie-chart"></span
								><span class="mtext">Charts</span>
							</a>
							<ul class="submenu">
								<li><a href="highchart.html">Highchart</a></li>
								<li><a href="knob-chart.html">jQuery Knob</a></li>
								<li><a href="jvectormap.html">jvectormap</a></li>
								<li><a href="apexcharts.html">Apexcharts</a></li>
							</ul>
						</li>
						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-file-earmark-text"></span
								><span class="mtext">Additional Pages</span>
							</a>
							<ul class="submenu">
								<li><a href="video-player.html">Video Player</a></li>
								<li><a href="login.html">Login</a></li>
								<li><a href="forgot-password.html">Forgot Password</a></li>
								<li><a href="reset-password.html">Reset Password</a></li>
							</ul>
						</li>
						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-bug"></span
								><span class="mtext">Error Pages</span>
							</a>
							<ul class="submenu">
								<li><a href="400.html">400</a></li>
								<li><a href="403.html">403</a></li>
								<li><a href="404.html">404</a></li>
								<li><a href="500.html">500</a></li>
								<li><a href="503.html">503</a></li>
							</ul>
						</li>

						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-back"></span
								><span class="mtext">Extra Pages</span>
							</a>
							<ul class="submenu">
								<li><a href="blank.html">Blank</a></li>
								<li><a href="contact-directory.html">Contact Directory</a></li>
								<li><a href="blog.html">Blog</a></li>
								<li><a href="blog-detail.html">Blog Detail</a></li>
								<li><a href="product.html">Product</a></li>
								<li><a href="product-detail.html">Product Detail</a></li>
								<li><a href="faq.html">FAQ</a></li>
								<li><a href="profile.html">Profile</a></li>
								<li><a href="gallery.html">Gallery</a></li>
								<li><a href="pricing-table.html">Pricing Tables</a></li>
							</ul>
						</li>
						<li class="dropdown">
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-hdd-stack"></span
								><span class="mtext">Multi Level Menu</span>
							</a>
							<ul class="submenu">
								<li><a href="javascript:;">Level 1</a></li>
								<li><a href="javascript:;">Level 1</a></li>
								<li><a href="javascript:;">Level 1</a></li>
								<li class="dropdown">
									<a href="javascript:;" class="dropdown-toggle">
										<span class="micon fa fa-plug"></span
										><span class="mtext">Level 2</span>
									</a>
									<ul class="submenu child">
										<li><a href="javascript:;">Level 2</a></li>
										<li><a href="javascript:;">Level 2</a></li>
									</ul>
								</li>
								<li><a href="javascript:;">Level 1</a></li>
								<li><a href="javascript:;">Level 1</a></li>
								<li><a href="javascript:;">Level 1</a></li>
							</ul>
						</li>
						<li>
							<a href="sitemap.html" class="dropdown-toggle no-arrow">
								<span class="micon bi bi-diagram-3"></span
								><span class="mtext">Sitemap</span>
							</a>
						</li>
						<li>
							<a href="chat.html" class="dropdown-toggle no-arrow">
								<span class="micon bi bi-chat-right-dots"></span
								><span class="mtext">Chat</span>
							</a>
						</li>
						<li>
							<a href="invoice.html" class="dropdown-toggle no-arrow">
								<span class="micon bi bi-receipt-cutoff"></span
								><span class="mtext">Invoice</span>
							</a>
						</li>
						<li>
							<div class="dropdown-divider"></div>
						</li>
						<li>
							<div class="sidebar-small-cap">Extra</div>
						</li>
						<li>
							<a href="javascript:;" class="dropdown-toggle">
								<span class="micon bi bi-file-pdf"></span
								><span class="mtext">Documentation</span>
							</a>
							<ul class="submenu">
								<li><a href="introduction.html">Introduction</a></li>
								<li><a href="getting-started.html">Getting Started</a></li>
								<li><a href="color-settings.html">Color Settings</a></li>
								<li>
									<a href="third-party-plugins.html">Third Party Plugins</a>
								</li>
							</ul>
						</li>
						<li>
							<a
								href="https://dropways.github.io/deskapp-free-single-page-website-template/"
								target="_blank"
								class="dropdown-toggle no-arrow"
							>
								<span class="micon bi bi-layout-text-window-reverse"></span>
								<span class="mtext"
									>Landing Page
									<img src="vendors/images/coming-soon.png" alt="" width="25"
								/></span>
							</a>
						</li>-->
					</ul>
				</div>
			</div>
		</div>
		<div class="mobile-menu-overlay"></div>