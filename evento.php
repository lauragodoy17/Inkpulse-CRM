<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html>
  <head>
    <!-- Basic Page Info -->
    <meta charset="utf-8" />
    <title>DeskApp - Bootstrap Admin Dashboard HTML Template</title>

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
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />

    
  </head>
  <body>
    
    <?php include("template/nav_side.php"); ?>
    <div class="main-container">
      <div class="pd-ltr-20 xs-pd-20-10">
        <div class="min-height-200px">
          <div class="page-header">
            <div class="row">
              <div class="col-md-6 col-sm-12">
                <div class="title">
                  <h4>Plan de trabajo</h4>
                </div>
                <nav aria-label="breadcrumb" role="navigation">
                  <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                      Inicio
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">
                      Plan de trabajo
                    </li>
                  </ol>
                </nav>
              </div>
              
            </div>
          </div>
          <div class="pd-20 bg-white border-radius-4 box-shadow mb-30">

            <?php
 
              $sql = "SELECT * FROM plan_trabajo WHERE id='".$_GET["evento"]."'";

              $req = $bdd->prepare($sql);
              $req->execute();
              $visita = $req->fetch();

              list($fecha, $hora)=explode(" ", $visita['start']);

              list($a,$m,$d)=explode("-", $fecha);
              $fecha= $d."/".$m."/".$a;
                
              if ($visita['id_colegio'] == 0) {
                $colegio = [
                  'id' => 0, 'codigo' => '', 'barrio' => '', 'direccion' => '', 'telefono' => '',
                  'colegio' => $visita['otro_lugar'] ?: 'Otro lugar',
                ];
              } else {
                $sql_colegio = "SELECT id,codigo, colegio, barrio, direccion,telefono FROM colegios WHERE id='".$visita["id_colegio"]."'";
                $req_colegio = $bdd->prepare($sql_colegio);
                $req_colegio->execute();
                $colegio = $req_colegio->fetch();
              }


              if ($visita['id_objetivo'] == 0) {
                $objetivo = ['objetivo' => $visita['otro_objetivo'] ?: 'Otro'];
              } else {
                $sql_objetivo = "SELECT objetivo FROM objetivos WHERE id='".$visita["id_objetivo"]."'";
                $req_objetivo = $bdd->prepare($sql_objetivo);
                $req_objetivo->execute();
                $objetivo = $req_objetivo->fetch();
              }

              $sql_grado = "SELECT grado FROM grados a JOIN grados_materias b ON a.id=b.id_grado WHERE cod_profesor='".$visita["cod_profesor"]."'";

              $req_grado = $bdd->prepare($sql_grado);
              $req_grado->execute();
              $grado = $req_grado->fetch();

              $sql_materia = "SELECT materia FROM materias a JOIN grados_materias b ON a.id=b.id_materia WHERE cod_profesor='".$visita["cod_profesor"]."'";

              $req_materia = $bdd->prepare($sql_materia);
              $req_materia->execute();
              $materia = $req_materia->fetch();

              $sql_parti = "SELECT CONCAT (nombres, ' ', apellidos) as parti, t.tipo FROM usuarios u JOIN plan_trabajo p ON u.id=p.id_promotor JOIN tipos_notifi t ON t.id=p.agendamiento WHERE p.codigo='".$visita['codigo']."' GROUP BY p.codigo, u.id";
              $req_parti = $bdd->prepare($sql_parti);
              $req_parti->execute();

              $participantes = $req_parti->fetchAll();

              foreach($participantes as $participante) {

                $tipo_noti = explode(" ", $participante["tipo"]);
                $partics.=$participante["parti"]." (".ucfirst($tipo_noti[1])."), ";

              }

              if (!empty($visita['otro_participante'])) {
                $partics .= $visita['otro_participante'] . " (Externo), ";
              }
   
                
            ?>


            <div class="row">
              <h4>Datos del colegio:</h4>

              <table class="table table-bordered table-hover">
                            
                <tr>
                  <td>Colegio: <?php echo $colegio['colegio']; ?></td>
                  <td>Teléfonos: <?php echo $colegio['telefono']; ?></td>
                </tr>
                <tr>
                  <td>Barrio: <?php echo $colegio['barrio']; ?></td>
                  <td>direccion: <?php echo $colegio['direccion']; ?></td>
                </tr>
                <tr>
                  <td>Fecha: <?php echo $fecha; ?></td>
                  <td>Hora: <?php echo $hora; ?></td>
                </tr>
                <tr>
                  <td>
                    Participantes: <?php echo $partics; ?>
                  </td>
                </tr>
                              
                </table>

            </div>

            <h6>Descripción: <?php echo $visita["descripcion"] ?></h6><br>
            <?php 
              $sql_profesor = "SELECT t.nombre,t.cargo,t.area, c.cargo as nombrecargo FROM trabajadores_colegios t JOIN cargos c ON t.cargo=c.id WHERE t.codigo='".$visita["cod_profesor"]."' AND t.codigo!=''";

                $req_profesor = $bdd->prepare($sql_profesor);
                $req_profesor->execute();
                $num_profesor=$req_profesor->rowCount();
                $profesor = $req_profesor->fetch();
             ?>

            <div class="row">
              <?php if ($visita["id_colegio"] != 1) { ?>
              <h4>Datos del profesor:</h4>
              <?php
                if ($num_profesor > 0) {
                
                if ($profesor["cargo"]== 1 || $profesor["cargo"]== 2 || $profesor["cargo"]== 7 || $profesor["cargo"]== 8 || $profesor["cargo"]== 9 || $profesor["cargo"]== 10 ){ 
              ?>
                
              <table class="table table-bordered table-hover">
                <tr>
                  <td>Nombre: <?php echo $profesor['nombre']; ?></td>
                  <td>Cargo: <?php echo $profesor['nombrecargo']; ?></td>
                </tr>
              </table>

              <?php }

                if ($profesor["cargo"]== 3){ 
              ?>
                
              <table class="table table-bordered table-hover">
                <tr>
                  <td>Nombre: <?php echo $profesor['nombre']; ?></td>
                  <td>Cargo: Coordinador académico</td>
                </tr>
              </table>

              <?php } if($profesor["cargo"]== 5) { 

                $sql_materia = "SELECT materia FROM materias WHERE id='".$profesor["area"]."'";

                $req_materia = $bdd->prepare($sql_materia);
                $req_materia->execute();
                $materia = $req_materia->fetch();
              ?>

                <table class="table table-bordered table-hover">
                  <tr>
                    <td>Nombre: <?php echo $profesor['nombre']; ?></td>
                    <td>Jefe de area: <?php echo $materia["materia"]; ?></td>
                  </tr>
                </table>
              <?php } if($profesor["cargo"]== 6){?>
              
                <table class="table table-bordered table-hover">
                  <tr>
                    <td>Nombre: <?php echo $profesor['nombre']; ?></td>
                  </tr>
                  <tr>
                    <td>Grado: <?php echo $grado['grado']; ?></td>
                    <td>Materia: <?php echo $materia['materia']; ?></td>
                  </tr>
                </table>
              <?php }
              }else{?>
                          <!--<form action="php/crear_profesor.php" method="POST">
                            <div class="col-sm-4">
                  <div class="form-group">
                    <label for="profesor" class="control-label no-padding-right">Nombre Profesor<small style="color:red;"> *</small></label>
                     <input type="text" required name="profesor" id="profesor" class="form-control" placeholder="">
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="form-group">
                    <label for="telefono_p" class="control-label no-padding-right">Teléfono<small style="color:red;"> *</small></label>
                    <input type="tel" name="telefono_p" id="telefono_p" class="form-control" placeholder="" required>
                  </div>

                </div>
                
              <div class="col-sm-4">
                <label for="email_p" class="control-label no-padding-right">Email</label>
                  <input type="text" name="email_p" id="email_p" class="form-control" placeholder="" >
              </div>
                <br>
                <input type="hidden" name="id_colegio" value="<?php echo $colegio['id'] ?>">
                <input type="hidden" name="cod_colegio" value="<?php echo $colegio['codigo'] ?>">
                <div class="otro_p">
              <div class="row profesor">
                <div class="col-sm-6">
                  <div class="form-group">
                    <label class="control-label no-padding-right" for="materia_p"> Materia:<small style="color:red;"> *</small></label>
              
                    <select name="materia[]" id="materia_p" class="form-control materia" required>
                      <option value="">Seleccionar</option>
                        <?php 
                          $sql = "SELECT id, materia FROM materias";
              
                          $req = $bdd->prepare($sql);
                          $req->execute();
                          $materias = $req->fetchAll();
              
                          foreach($materias as $materia) {
                              $id = $materia['id'];
                              $nom = $materia['materia'];
                              echo '<option value="'.$id.'">'.$nom.'</option>';
                          }
                        ?>
                    </select>
                      
                  </div>
              
                </div>
                <div class="col-sm-6">
                  <div class="form-group">
                    <label class="control-label no-padding-right" for="grado_p"> Grado:<small style="color:red;"> *</small></label>
              
                    <select name="grado[]" id="grado_p" class="form-control materia" required>
                                  <option value="">Seleccionar</option>
                        <?php 
                          $sql = "SELECT id, grado FROM grados";
              
                          $req = $bdd->prepare($sql);
                          $req->execute();
                          $grados = $req->fetchAll();
              
                          foreach($grados as $grado) {
                              $id = $grado['id'];
                              $nom = $grado['grado'];
                              echo '<option value="'.$id.'">'.$nom.'</option>';
                          }
                        ?>
                    </select>
                      
                  </div>
                  
                </div>
              </div>
            </div>
            <a id="agregar_materia" style="cursor: pointer;">Agregar materia +</a>
            <input type="hidden" name="evento" value="<?php echo $_GET["evento"];?>">
            <center><button class="btn btn-primary">Guardar profesor</button></center>
                          </form>
                         <?php } ?>
            </div><br>
            <?php } ?>-->
          </div><br>
            <div class="row">
              <center><h5>Objetivo de la Visita: <?php echo $objetivo["objetivo"] ?></h5></center>


            </div><br>
              <?php 
                if ($visita["resultado"]==1) {
                  
                
                $sql_v = "SELECT observaciones FROM visitas WHERE id_plan_trabajo='".$_GET["evento"]."'";

                $req_v = $bdd->prepare($sql_v);
                $req_v->execute();
                $v = $req_v->fetch();
               ?>
              <div class="row">
                <div class="form-group">
                  <div class="col-sm-6 col-sm-offset-3">

                    <br><br><br><center><b>Comentarios:</b></center>
                    <?php if ($visita['start'] >= date("Y-m-d 00:00:00")) {?>
                    <form action="php/editar_comentario.php" method="POST">
                      <textarea class="form-control" cols="70" rows="3" name="comen_edit" id=""><?php echo $v["observaciones"] ?></textarea><br>
                      <input type="hidden" name="id_plan_trabajo" value="<?php echo $_GET["evento"]; ?>">
                      <center><button class="btn btn-warning">Editar Comentario</button></center>

                    </form>
                    <?php } else {?>
                      <textarea class="form-control" cols="70" rows="3" name="comen_edit" disabled><?php echo $v["observaciones"] ?></textarea>
                    <?php } ?>
                  </div>
                </div>

              </div><br>
            <?php if ($visita["id_objetivo"]==3 ) {

                echo'<table class="table table-bordered">
                  <thead>
                    <th>Libro</th>
                    <th>Materia</th>
                    <th>Grado</th>
                  </thead>
                  <tbody>';
                $sql_mp = "SELECT  id_libro FROM mu_pre WHERE id_plan_trabajo='".$_GET["evento"]."'";

                $req_mp = $bdd->prepare($sql_mp);
                $req_mp->execute();
                $mps = $req_mp->fetchAll();

                foreach ($mps as $mp) {
                  
                  $sql_libro = "SELECT id_materia, id_grado, libro FROM libros WHERE id='".$mp["id_libro"]."'";

                  $req_libro = $bdd->prepare($sql_libro);
                  $req_libro->execute();
                  $libro = $req_libro->fetch();

                  $sql_materia = "SELECT materia FROM materias WHERE id='".$libro["id_materia"]."'";

                  $req_materia = $bdd->prepare($sql_materia);
                  $req_materia->execute();
                  $materia = $req_materia->fetch();

                  $sql_grado = "SELECT grado FROM grados WHERE id='".$libro["id_materia"]."'";

                  $req_grado = $bdd->prepare($sql_grado);
                  $req_grado->execute();
                  $grado = $req_grado->fetch();

                  echo "<tr>
                      <td>".$libro["libro"]."</td>
                      <td>".$materia["materia"]."</td>
                      <td>".$grado["grado"]."</td>
                    </tr>";

                }

                echo "</tbody></table>";
              
              
              }
            }?>
            <?php if ($visita["resultado"] ==0 && $visita['start'] >= date("Y-m-d 00:00:00")) {
            ?>
            <form action="php/llegada.php" method="POST">
            <INPUT TYPE='hidden' readonly='readonly' ID='latitud1' NAME='latitud1'>
            <INPUT TYPE='hidden' readonly='readonly' ID='longitud1' NAME='longitud1'>
            <input type="hidden" name="id_visita" value="<?php echo $_GET["evento"] ?>">
            <br><br><br><center><a href="php/borrar_plan_trabajo.php?evento=<?php echo $visita["id"] ?>" class="btn btn-danger">Eliminar</a> 

            <?php

            $sql_v = "SELECT id FROM visitas WHERE id_plan_trabajo='".$_GET["evento"]."'";

              $req_v = $bdd->prepare($sql_v);
              $req_v->execute();
              $v2 = $req_v->fetch();

              if (empty($v2)) {
                
                echo "<button  class='btn btn-info'>Llegada</button>";

              }else{

                echo "<button type='button' class='btn btn-success' data-toggle='modal' data-target='#ModalEjecutar'>Ejecutar</button></center>";

              }

            ?>

              
              
            </form><br><br><br><br><br><br><br><br>
            <?php }else if($visita["resultado"]==1){ ?>
              <center><p class="text-success bg-success"  style="font-size: 20px; color:#FFF !important;">Ejecutada</p></center>
            <?php } ?>
            <div class="modal fade" id="ModalEjecutar" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <form class="form-horizontal" method="POST" action="php/ejecutar_visita.php">
      
                  <div class="modal-header">
                    <h4 class="modal-title" id="myModalLabel">Ejecutar Visita</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                  </div>
                  <div class="modal-body">
        
                    <div class="form-group">
                      <label for="comentarios" class="col-sm-2 control-label">Comentarios:</label>
                      <div class="col-sm-10">
                      <textarea class="form-control" rows="3" name="comentarios" id="comentarios"></textarea>
                      </div>
                    </div>
                    <center>
                    <div class="checkbox">
                        <label><b>Efectiva:</b><br>
                          <input type="checkbox" name="efectiva" value="1" id="ef_si"> Si
                        </label>
                        <label>
                          <input type="checkbox" name="efectiva" value="0" id="ef_no"> No
                        </label>
                      </div>
                    </center>
        
                    <INPUT TYPE='hidden' readonly='readonly' ID='latitud' NAME='latitud'>
                    <INPUT TYPE='hidden' readonly='readonly' ID='longitud' NAME='longitud'>

                    <input type="hidden" name="id_visita" value="<?php echo $_GET["evento"] ?>">
                  </div>
                  <div class="modal-footer">
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
    <script>
      <?php if ($visita["resultado"] ==0) { ?>
        
        function success(position) {
          var lat = document.getElementById("latitud");
          var lon = document.getElementById("longitud");
          var lat1 = document.getElementById("latitud1");
          var lon1 = document.getElementById("longitud1");
          lat.value  = position.coords.latitude;
          lon.value = position.coords.longitude;
          lat1.value  = position.coords.latitude;
          lon1.value = position.coords.longitude;
        };
        function error() {
          alert ("verifique la configuración de Ubicación y vuelva a intentarlo ...");
        };
        navigator.geolocation.getCurrentPosition(success, error);
      <?php } ?>

      $("#ef_si").click(function(){
        $("#ef_no").prop("checked", false);

      })
      $("#ef_no").click(function(){
        $("#ef_si").prop("checked", false);

      })


      $("#plan_trabajo").addClass("active");

  </script>
    
  </body>
</html>
