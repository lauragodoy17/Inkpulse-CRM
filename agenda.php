<!DOCTYPE html>
<html lang="es">
	<head>
		<!-- Basic Page Info -->
		<meta charset="utf-8" />
		<title>Inkpulse - Agenda</title>

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
			href="src/plugins/fullcalendar/fullcalendar.css"
		/>
		<link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
	</head>
	<body>
		<?php
		require_once("php/aut.php");
		include("template/nav_side.php"); ?>
		
		<div class="main-container">
			<div class="pd-ltr-20 xs-pd-20-10">
				<div class="min-height-200px">
					<div class="page-header">
						<div class="row">
							<div class="col-md-12 col-sm-12">
								<div class="title">
									<h4>Plan de trabajo</h4>
								</div>
								<nav aria-label="breadcrumb" role="navigation">
									<ol class="breadcrumb">
										<li class="breadcrumb-item">
											<a href="index.html">Inicio</a>
										</li>
										<li class="breadcrumb-item active" aria-current="page">
											Plan de trabajo
										</li>
									</ol>
								</nav>
							</div>
						</div>
					</div>
					<div class="pd-20 card-box mb-30">
						<div class="calendar-wrap">
							<div id="calendar"></div>
						</div>
						<!-- Modal -->
						<div class="modal fade" id="ModalAdd" role="dialog" aria-labelledby="myModalLabel">
						  <div class="modal-dialog modal-dialog-centered" role="document">
							<div class="modal-content">
							
							
							  <div class="modal-header">
								<h4 class="modal-title"> Agendar visita</h4>
								<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
							  </div>
							  <form class="form-inline" method="POST" action="php/crear_plan_trabajo.php">
							  <div class="modal-body">
							  		<?php if (!isset($_GET['colegio'])) {

							  			$sql = "SELECT id FROM colegios WHERE id = 1";

										$req = $bdd->prepare($sql);
										$req->execute();

										$ofi = $req->fetch();

										$sql = "SELECT id FROM colegios WHERE id = 2";

										$req = $bdd->prepare($sql);
										$req->execute();

										$casa = $req->fetch();
							  		?>
							  			
							  			<div class="form-group">
											<label for="oficina" class="col-sm-4 control-label c_ofi">Oficina:</label>
											<div class="col-sm-8">
											  <input type="checkbox" name="oficina" class="c_ofi" id="oficina" value="<?php echo $ofi['id'] ?>">
											</div>
								  		</div><br>

								  		<div class="form-group">
											<label for="oficina" class="col-sm-4 control-label c_casa">Trabajo en casa:</label>
											<div class="col-sm-8">
											  <input type="checkbox" class="c_casa" name="casa" id="casa" value="<?php echo $casa['id'] ?>">
											</div>
								  		</div><br>

								  		<div class="form-group">
											<label for="otro_chk" class="col-sm-4 control-label c_otro">Otro lugar:</label>
											<div class="col-sm-8">
											  <input type="checkbox" class="c_otro" name="otro_chk" id="otro_chk" value="1">
											</div>
								  		</div><br>
								  		<div class="form-group d-none" id="otro_lugar_wrap">
								  		  <label for="otro_lugar_txt" class="col-sm-4 control-label">¿Cuál? <small style="color:red;">*</small></label>
								  		  <div class="col-sm-8">
								  		    <input type="text" name="otro_lugar_txt" id="otro_lugar_txt" class="form-control" placeholder="Especifique el lugar">
								  		  </div>
								  		</div><br>

								  		<div class="form-group ocultar_oficina">
											<label for="cole" class="col-sm-4 control-label">Colegio<small style="color:red;"> *</small></label>
											<div class="col-sm-8">
											  <select name="cole" id="cole" class="form-control custom-select2" style="width: 300px;" required>
										  		<option value="">Selecciona</option>
										  		<?php 
											 		if ($_SESSION["tipo"]==1 || $_SESSION["tipo"]==4 || $_SESSION["id"] == 10 || $_SESSION["zona"] == '5656') {
														$sql = "SELECT id,colegio, dane, ciudad FROM colegios WHERE id > 2";
													}
													else {

														$sql = "SELECT id,colegio, dane, ciudad FROM colegios WHERE cod_zona='".$_SESSION["zona"]."' AND id > 2";
													}

													$req = $bdd->prepare($sql);
													$req->execute();
													$coles = $req->fetchAll();

													foreach($coles as $cole) {
													   
													    echo '<option value="'.$cole["id"].'">'.$cole["dane"].' - '.$cole["colegio"].' ('.$cole["ciudad"].')</option>';
													}
											 	?>
											 	echo
										  	</select>
											</div>
								  		</div><br>
							  		<?php }else { 

							  			$sql = "SELECT codigo, colegio FROM colegios WHERE id='".$_GET['colegio']."'";

										$req = $bdd->prepare($sql);
										$req->execute();

										$colegio = $req->fetch();

							  		?>
							  			<h4>Colegio: <?php echo $colegio['colegio']; ?></h4><br>
							  			<input type="hidden" name="cole" id="cole" value="<?php echo $_GET['colegio'] ?>">
							  			<input type="hidden" name="cod_cole" id="cod_cole" value="<?php echo $colegio['codigo'] ?>">
							  		<?php } ?>
									

								   
								   <div class="form-group ocultar_oficina">
								  		<label for="parti" class="col-sm-4 control-label">Otros participantes</label>
								  		<div class="col-sm-8">
										  	<select name="participantes[]" id="parti" class="form-control custom-select2"  multiple="multiple" style="width: 300px;">

										  		<?php
											 		$sql = "SELECT id, CONCAT(nombres, ' ', apellidos) as parti FROM usuarios WHERE id !=1 AND act=1 AND (tipo=3 ||tipo=6 || tipo=4 || tipo=1)";

													$req = $bdd->prepare($sql);
													$req->execute();
													$participantes = $req->fetchAll();

													foreach($participantes as $participante) {

													    echo '<option value="'.$participante["id"].'">'.$participante["parti"].'</option>';
													}
											 	?>
											 	<option value="otro">Otro</option>

										  	</select>
										  	<input type="text" name="otro_participante" id="otro_participante_txt"
										  	       class="form-control mt-1 d-none" placeholder="Nombre del participante externo">
									  	</div>
									</div><br>
									
								  <div class="form-group ocultar_oficina">
								  	<?php if ($_SESSION["id"] != 16) { ?>
									<label for="profesor" class="col-sm-4 control-label">Profesor</label>
									<div class="col-sm-8">
									  <input type="text" name="profesor" class="form-control" id="profesor" placeholder="Nombre del profesor" autocomplete="off" onkeyup="bus_h()">
									  <input type="hidden" name="profe" id="profe"><div id="suggestions"></div>
									</div>
									<?php }else { ?>
									<label for="profesor" class="col-sm-4 control-label">Profesor</label>
									<div class="col-sm-8">
									  <input type="text" name="profesor" class="form-control" id="profesor" placeholder="Nombre del profesor" autocomplete="off" onkeyup="bus_h()">
									  <input type="hidden" name="profe" id="profe"><div id="suggestions"></div>
									</div>
									<?php } ?>
								  </div><br>

								  <div class="form-group ocultar_oficina">
									<label for="objetivo" class="col-sm-4 control-label">Objetivo<small style="color:red;"> *</small></label>
									<div class="col-sm-8">
									 <select name="objetivo" id="objetivo" class="form-control custom-select2" style="width:300px;" required>
									 	<option value="">Seleccionar</option>
									 	<?php
									 		if ($_SESSION["tipo"] < 4) {
									 			$sql = "SELECT id, objetivo FROM objetivos WHERE tipo < 3 AND objetivo != 'Otro' ORDER BY objetivo";
									 		}else{
									 			$sql = "SELECT id, objetivo FROM objetivos WHERE tipo > 1 AND objetivo != 'Otro' ORDER BY objetivo";
									 		}
									 		$req = $bdd->prepare($sql);
									 		$req->execute();
									 		$objetivos = $req->fetchAll();
									 		foreach($objetivos as $objetivo) {
									 		    echo '<option value="'.$objetivo['id'].'">'.$objetivo['objetivo'].'</option>';
									 		}
									 	?>
									 	<option value="otro">Otro</option>
									 </select>
									 <input type="text" name="otro_objetivo_txt" id="otro_objetivo_txt"
									        class="form-control mt-1 d-none" placeholder="Especifique el objetivo">
									</div>
								  </div><br>

								  <div class="form-group">
									<label for="start" class="col-sm-4 control-label">Inicio</label>
									<div class="col-sm-8">
									  <input type="text" name="start" class="form-control" id="start" >
									</div>
								  </div><br>
								  <div class="form-group">
									<label for="end" class="col-sm-4 control-label">Fin</label>
									<div class="col-sm-8">
									  <input type="text" name="end" class="form-control" id="end" >
									</div>
								  </div><br>
								
								  <div class="form-group">
									<label for="descripcion" class="col-sm-4 control-label">Descripción:</label>
									<div class="col-sm-8">
									 <textarea class="form-group" id="descripcion" name="descripcion" rows="5" cols="30"></textarea>
									</div>
								  </div><br>
								
							  </div>
							  <div class="modal-footer">
								<button type="submit" class="btn btn-primary" id="guardar">Guardar</button>
							  </div>
							</form>
							</div>
						  </div>
						</div>
		
		
		
						<!-- Modal -->
						<div class="modal fade" id="ModalEdit" role="dialog" aria-labelledby="myModalLabel">
						  <div class="modal-dialog" role="document">
							<div class="modal-content">
							<form class="form-horizontal" method="POST" action="ajax/editEventTitle.php">
							  <div class="modal-header">
								<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
								<h4 class="modal-title" id="myModalLabel">Modificar Evento</h4>
							  </div>
							  <div class="modal-body">
								
								  <div class="form-group">
									<label for="title" class="col-sm-4 control-label">Título</label>
									<div class="col-sm-8">
									  <input type="text" name="title" class="form-control" id="title" placeholder="Título">
									</div>
								  </div>
								  <div class="form-group">
									<label for="color" class="col-sm-4 control-label">Color</label>
									<div class="col-sm-8">
									  <select name="color" class="form-control" id="color">
										  <option value="">Seleccionar</option>
										  <option style="color:#0071c5;" value="#0071c5">&#9724; Azul oscuro</option>
										  <option style="color:#40E0D0;" value="#40E0D0">&#9724; Turquesa</option>
										  <option style="color:#008000;" value="#008000">&#9724; Verde</option>						  
										  <option style="color:#FFD700;" value="#FFD700">&#9724; Amarillo</option>
										  <option style="color:#FF8C00;" value="#FF8C00">&#9724; Naranja</option>
										  <option style="color:#FF0000;" value="#FF0000">&#9724; Rojo</option>
										  <option style="color:#000;" value="#000">&#9724; Negro</option>
										  
										</select>
									</div>
								  </div>
								    <div class="form-group"> 
										<div class="col-sm-offset-2 col-sm-8">
										  <div class="checkbox">
											<label class="text-danger"><input type="checkbox"  name="delete"> Eliminar Evento</label>
										  </div>
										</div>
									</div>
								  
								  <input type="hidden" name="id" class="form-control" id="id">
								
								
							  </div>
							  <div class="modal-footer">
								<button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
								<button type="submit" class="btn btn-primary">Guardar</button>
							  </div>
							</form>
							</div>
						  </div>
						</div>
					</div>
				</div>
				<?php include("template/footer.php"); ?>
			</div>
		</div>
		
		<!-- js -->
		<script src="vendors/scripts/core.js"></script>
		<script src="vendors/scripts/script.min.js"></script>
		<script src="vendors/scripts/process.js"></script>
		<script src="vendors/scripts/layout-settings.js"></script>
		<script src="src/plugins/fullcalendar/fullcalendar.min.js"></script>
		<!--<script src="vendors/scripts/calendar-setting.js"></script>-->
		<script>
			
			$(document).ready(function() {

				var date = new Date();
		       	var yyyy = date.getFullYear().toString();
		       	var mm = (date.getMonth()+1).toString().length == 1 ? "0"+(date.getMonth()+1).toString() : (date.getMonth()+1).toString();
		       	var dd  = (date.getDate()).toString().length == 1 ? "0"+(date.getDate()).toString() : (date.getDate()).toString();
		
				$('#calendar').fullCalendar({
					monthNames: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
		        	monthNamesShort: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
		        	dayNames: ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'],
		    		dayNamesShort: ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'],

		    		defaultView: 'agendaWeek',
		    		
		    		allDaySlot: false,

		    		nowIndicator: true,
		    		slotDuration: '01:00:00',
					header: {
						 language: 'es',
						left: 'prev,next',
						center: 'title',
						right: 'month,agendaWeek,agendaDay',

					},
					hiddenDays: [ 0 ],
					defaultDate: yyyy+"-"+mm+"-"+dd,
					editable: true,
					eventLimit: true, // allow "more" link when too many events
					selectable: true,
					selectHelper: true,
					minTime: "06:00:00",
					maxTime: "20:00:00",
					select: function(start, end) {

				      	// leemos las fechas de inicio de evento y hoy
				      	var check = moment(start).format('YYYY-MM-DD');
				     	var today = moment(new Date()).format('YYYY-MM-DD');

				      	// si el inicio de evento ocurre hoy o en el futuro mostramos el modal
				     	if (check >= today) {

					        // éste es el código que tenías originalmente en el select
					        $('#ModalAdd #start').val(moment(start).format('YYYY-MM-DD HH:mm:ss'));
					        $('#ModalAdd #end').val(moment(end).format('YYYY-MM-DD HH:mm:ss'));
					        $('#ModalAdd').modal('show');
				      	}
				      // si no, mostramos una alerta de error
					    else {
					        alert("No se pueden crear eventos en el pasado!");
					    }
		  			},
					/*eventRender: function(event, element) {
						element.bind('dblclick', function() {
							$('#ModalEdit #id').val(event.id);
							$('#ModalEdit #title').val(event.title);
							$('#ModalEdit #color').val(event.color);
							$('#ModalEdit').modal('show');
						});
					},*/
					eventDrop: function(event, delta, revertFunc) { // si changement de position

						edit(event);

					},
					eventResize: function(event,dayDelta,minuteDelta,revertFunc) { // si changement de longueur

						edit(event);

					},
					events: {
					  url: 'ajax/eventos_plan_trabajo.php', // nuevo script que devuelve eventos
					  method: 'POST',
					  failure: function() {
					    alert('Error al cargar eventos');
					  }
					}
				});
		
				function edit(event){
					start = event.start.format('YYYY-MM-DD HH:mm:ss');
					if(event.end){
						end = event.end.format('YYYY-MM-DD HH:mm:ss');
					}else{
						end = start;
					}
					
					id =  event.id;
					
					Event = [];
					Event[0] = id;
					Event[1] = start;
					Event[2] = end;
					
					$.ajax({
					 url: 'ajax/editar_fecha_plan.php',
					 type: "POST",
					 data: {Event:Event},
					 success: function(rep) {
							if(rep == 'OK'){
								alert('Modificación correcta');
							}else{
								alert('No se pudo guardar. Inténtalo de nuevo.');
								window.location.reload()
							}
						}
					});
				}

				$(".fc-month-button").text("Mes");
				$(".fc-agendaWeek-button").text("Semana");
				$(".fc-agendaDay-button").text("Día");
		
			});


    		$("#oficina").click(function(){

				if( $('#oficina').prop('checked') ) {
			   		$(".ocultar_oficina").addClass("d-none")
			   		$(".c_casa").addClass("d-none");
			   		$(".c_otro").addClass("d-none");
			   		$(".ocultar_oficina").removeClass("d-block")
			   		$("#cole").removeAttr("required");
			   		$("#profesor").removeAttr("required");
			   		$("#objetivo").removeAttr("required");
			   		$("#otro_lugar_wrap").addClass("d-none");
			   		$("#otro_lugar_txt").removeAttr("required").val('');
			   		$("#otro_chk").prop("checked", false);

				}else {
					$(".ocultar_oficina").addClass("d-block")
					$(".ocultar_oficina").removeClass("d-none")
					$(".c_casa").removeClass("d-none");
					$(".c_otro").removeClass("d-none");
					$("#cole").attr("required","required");
					$("#profesor").attr("required","required");
			   		$("#objetivo").attr("required","required");
				}

			})

			$("#casa").click(function(){

				if( $('#casa').prop('checked') ) {
			   		$(".ocultar_oficina").addClass("d-none")
			   		$(".c_ofi").addClass("d-none");
			   		$(".c_otro").addClass("d-none");
			   		$(".ocultar_oficina").removeClass("d-block")
			   		$("#cole").removeAttr("required");
			   		$("#profesor").removeAttr("required");
			   		$("#objetivo").removeAttr("required");
			   		$("#otro_lugar_wrap").addClass("d-none");
			   		$("#otro_lugar_txt").removeAttr("required").val('');
			   		$("#otro_chk").prop("checked", false);

				}else {
					$(".ocultar_oficina").addClass("d-block")
					$(".ocultar_oficina").removeClass("d-none")
					$(".c_ofi").removeClass("d-none");
					$(".c_otro").removeClass("d-none");
					$("#cole").attr("required","required");
					$("#profesor").attr("required","required");
			   		$("#objetivo").attr("required","required");
				}

			})

			$("#otro_chk").click(function(){

				if ($('#otro_chk').prop('checked')) {
					$(".ocultar_oficina").addClass("d-none").removeClass("d-block");
					$(".c_ofi").addClass("d-none");
					$(".c_casa").addClass("d-none");
					$("#cole").removeAttr("required");
					$("#profesor").removeAttr("required");
					$("#objetivo").removeAttr("required");
					$("#otro_lugar_wrap").removeClass("d-none");
					$("#otro_lugar_txt").attr("required", "required");
					$("#oficina").prop("checked", false);
					$("#casa").prop("checked", false);
				} else {
					$(".ocultar_oficina").addClass("d-block").removeClass("d-none");
					$(".c_ofi").removeClass("d-none");
					$(".c_casa").removeClass("d-none");
					$("#cole").attr("required", "required");
					$("#objetivo").attr("required", "required");
					$("#otro_lugar_wrap").addClass("d-none");
					$("#otro_lugar_txt").removeAttr("required").val('');
				}

			})

			$(document).ready(function() {
				$(".custom-select2").select2({
					 dropdownParent: $('#ModalAdd')
				});

				$('#parti').on('change', function() {
					var vals = $(this).val() || [];
					if (vals.indexOf('otro') !== -1) {
						$('#otro_participante_txt').removeClass('d-none').attr('required', 'required');
					} else {
						$('#otro_participante_txt').addClass('d-none').removeAttr('required').val('');
					}
				});

				function manejarObjetivo(valor) {
					if (valor == 2) {
						$("#muestreo").removeClass("d-none");
						$("#materia").attr("required","required");
						$("#grado").attr("required","required");
						$("#libro").attr("required","required");
					} else {
						$("#muestreo").addClass("d-none");
						$("#materia").removeAttr("required");
						$("#grado").removeAttr("required");
						$("#libro").removeAttr("required");
					}
					if (valor === 'otro') {
						$('#otro_objetivo_txt').removeClass('d-none').attr('required', 'required');
					} else {
						$('#otro_objetivo_txt').addClass('d-none').removeAttr('required').val('');
					}
				}
				$('#objetivo').on('change select2:select', function() {
					manejarObjetivo($(this).val());
				});
			});

		</script>
	
	</body>
</html>
