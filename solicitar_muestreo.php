<?php require_once("php/aut.php"); ?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <!-- Basic Page Info -->
    <meta charset="utf-8" />
    <?php if ($_GET['tp']!=2) { ?>
      <title>Inkpulse - Solicitar muestreo</title>
    <?php }else{ ?>
      <title>Inkpulse - Entregar muestras</title>
    <?php } ?>

    <!-- Site favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />

    <!-- Mobile Specific Metas -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

    <!-- CSS -->
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />

    <style>
      input[type=number] { -moz-appearance: textfield; }
      input[type=number]::-webkit-inner-spin-button,
      input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
      .custom-select2 { width: auto !important; }

      /* ── Solicitar muestreo ─────────────────────────────────── */
      .sm-page-desc {
        font-size: 13px;
        color: #6b7280;
        margin: 2px 0 0;
        font-weight: 400;
      }

      .sm-section {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 18px;
        box-shadow: 0 1px 3px rgba(0,0,0,.05);
      }
      .sm-section-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 13px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
      }
      .sm-step {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #4361ee;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }
      .sm-sec-icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: #eff6ff;
        color: #4361ee;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }
      .sm-section-title {
        font-size: 13.5px;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
      }
      .sm-section-body {
        padding: 22px 24px;
      }

      .sm-cole-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #eff6ff;
        color: #2563eb;
        font-size: 13px;
        font-weight: 600;
        border-radius: 8px;
        padding: 6px 14px;
      }

      .sm-book-block {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px 16px 4px;
        margin-bottom: 14px;
        background: #fafafa;
      }
      .sm-book-block:first-of-type {
        background: #fff;
      }

      .sm-add-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #4361ee;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        background: none;
        padding: 0;
        margin-bottom: 20px;
        text-decoration: none;
      }
      .sm-add-btn:hover { color: #2d4cda; }

      .sm-divider {
        border: none;
        border-top: 1px solid #e5e7eb;
        margin: 4px 0 20px;
      }

      .sm-footer {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        padding-top: 4px;
      }
      .sm-footer .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 22px;
        font-size: 14px;
        font-weight: 600;
        border-radius: 8px;
      }

      /* ── Campo de archivo ───────────────────────────────────── */
      .sm-file-label {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 2px dashed #d1d5db;
        border-radius: 8px;
        padding: 13px 16px;
        cursor: pointer;
        background: #f9fafb;
        transition: border-color .15s, background .15s;
        color: #64748b;
        font-size: 13.5px;
        font-weight: 500;
      }
      .sm-file-label:hover  { border-color: #4361ee; background: #eff6ff; color: #4361ee; }
      .sm-file-label.has-file { border-color: #16a34a; background: #f0fdf4; color: #15803d; }
      .sm-file-label i { font-size: 1.15rem; flex-shrink: 0; }
      .sm-file-name {
        font-size: 12px;
        color: #4361ee;
        margin: 5px 0 0;
        font-weight: 600;
        word-break: break-all;
      }
      input[type="file"].sm-file-input { display: none; }

      /* ── Toast ───────────────────────────────────────────────── */
      .sm-toast {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 9999;
        min-width: 260px;
        max-width: 380px;
        display: none;
        align-items: center;
        gap: 11px;
        border-radius: 10px;
        padding: 14px 18px;
        font-size: 13.5px;
        font-weight: 500;
        box-shadow: 0 6px 24px rgba(0,0,0,.12);
        animation: sm-toast-in .3s ease;
      }
      .sm-toast.ok    { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
      .sm-toast.error { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
      .sm-toast i     { font-size: 20px; flex-shrink: 0; }
      @keyframes sm-toast-in {
        from { opacity:0; transform:translateY(14px); }
        to   { opacity:1; transform:translateY(0); }
      }
    </style>
  </head>
  <body>

    <?php include("template/nav_side.php"); ?>
    <div class="main-container">
      <div class="pd-ltr-20 xs-pd-20-10">
        <div class="min-height-200px">

          <!-- Page Header -->
          <div class="page-header">
            <div class="row align-items-center">
              <div class="col-md-8 col-sm-12">
                <div class="title">
                  <?php if ($_GET['tp']!=2): ?>
                    <h4>Solicitar muestreo</h4>
                    <p class="sm-page-desc">Registra la información del libro que deseas muestrear.</p>
                  <?php else: ?>
                    <h4>Legalizar muestras</h4>
                    <p class="sm-page-desc">Registra la información de las muestras que deseas legalizar.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <!-- /Page Header -->

          <form action="php/crear_muestreo_new.php" method="POST" enctype="multipart/form-data" id="miFormulario">

            <!-- ── Sección 1: Colegio ──────────────────────────── -->
            <?php if (!isset($_GET['colegio'])): ?>
            <div class="sm-section">
              <div class="sm-section-head">
                <span class="sm-step">1</span>
                <span class="sm-sec-icon"><i class="bi bi-building"></i></span>
                <span class="sm-section-title">Tipo de muestras y Colegio</span>
              </div>
              <div class="sm-section-body">
                <div class="row">

                  <?php if ($_SESSION['tipo']!=6): ?>
                  <div class="form-group col-md-5 col-sm-6 mb-0">
                    <label for="cole" class="control-label">Tipo de muestras<small style="color:red;">*</small></label>
                    <select name="tipo" id="tipo" class="form-control custom-select2" required>
                      <option value="">Seleccione</option>
                      <option value="1">Docente</option>
                      <option value="2">Estudiante</option>
                      
                    </select>
                  </div>

                  <?php else: ?>

                    <input type="hidden" name="tipo" id="tipo" value="1">

                  <?php endif; ?>

                  <div class="form-group col-md-5 col-sm-6 ocultar_oficina mb-0">
                    <label for="cole" class="control-label">Colegio <small style="color:red;">*</small></label>
                    <select name="cole" id="cole" class="form-control custom-select2" required>
                      <option value="">Selecciona un colegio</option>
                      <?php
                        if ($_SESSION["tipo"]==1 || $_SESSION["tipo"]==2 || $_SESSION["tipo"]==2) {
                          $sql = "SELECT id,colegio FROM colegios WHERE colegio like'%".$colegio."%' AND id > 2";
                        } elseif ($_SESSION["tipo"]==3) {
                          $sql = "SELECT id,colegio FROM colegios WHERE colegio like'%".$colegio."%' AND cod_zona='".$_SESSION["zona"]."'";
                        } else {
                          $sql = "SELECT id,colegio FROM colegios WHERE colegio like'%".$colegio."%' AND cod_zona='".$_SESSION["zona"]."' OR zona_madre='".$_SESSION["zona"]."'";
                        }
                        $req = $bdd->prepare($sql);
                        $req->execute();
                        $colegios = $req->fetchAll();
                        foreach ($colegios as $colegio) {
                          echo "<option value='".$colegio["id"]."'>".$colegio["colegio"]."</option>";
                        }
                      ?>
                    </select>
                  </div>

                </div>
              </div>
            </div>

            <?php else:
              $sql = "SELECT codigo, colegio FROM colegios WHERE id='".$_GET['colegio']."'";
              $req = $bdd->prepare($sql);
              $req->execute();
              $colegio = $req->fetch();
            ?>
            <div class="sm-section">
              <div class="sm-section-head">
                <span class="sm-step">1</span>
                <span class="sm-sec-icon"><i class="bi bi-building"></i></span>
                <span class="sm-section-title">Colegio seleccionado</span>
              </div>
              <div class="sm-section-body" style="padding-top:16px; padding-bottom:16px;">
                <span class="sm-cole-tag">
                  <i class="bi bi-geo-alt-fill"></i>
                  <?php echo htmlspecialchars($colegio['colegio']); ?>
                </span>
              </div>
            </div>
            <input type="hidden" name="cole" id="cole" value="<?php echo $_GET['colegio']; ?>">
            <input type="hidden" name="cod_cole" id="cod_cole" value="<?php echo $colegio['codigo']; ?>">
            <?php endif; ?>

            <!-- ── Sección 2: Libros ───────────────────────────── -->
            <div class="sm-section">
              <div class="sm-section-head">
                <span class="sm-step"><?php echo !isset($_GET['colegio']) ? '2' : '1'; ?></span>
                <span class="sm-sec-icon"><i class="bi bi-book"></i></span>
                <span class="sm-section-title">Información del libro</span>
              </div>
              <div class="sm-section-body">

                <div class="otro_l">

                  <!-- Libro #1 -->
                  <div class="sm-book-block">
                    <div class="row">
                      <div class="form-group col-sm-4">
                        <label id="l_materia" for="materia" class="control-label">Materia <small style="color:red;">*</small></label>
                        <select name="materia[]" id="materia" class="form-control">
                          <option value="">Selecciona una materia</option>
                          <?php
                            $sql = "SELECT id, materia FROM materias";
                            $req = $bdd->prepare($sql);
                            $req->execute();
                            $colegios = $req->fetchAll();
                            foreach ($colegios as $colegio) {
                              echo '<option value="'.$colegio['id'].'">'.$colegio['materia'].'</option>';
                            }
                          ?>
                        </select>
                      </div>
                      <div class="form-group col-sm-4">
                        <label id="l_libro" for="libro" class="control-label">Libro <small style="color:red;">*</small></label>
                        <select name="libro" id="libro" class="form-control custom-select2">
                          <option value="">Selecciona un libro</option>
                        </select>
                      </div>
                      <div class="form-group col-sm-4">
                        <label id="l_cantidad" for="cantidad" class="control-label">Cantidad <small style="color:red;">*</small></label>
                        <input type="number" class="form-control cantidad" name="cantidad" id="cantidad" placeholder="Ingresa la cantidad">
                      </div>
                    </div>
                    <div id="ls_pri_sec"></div>
                    <input type="hidden" name="libro_e[]" id="libro_e">
                  </div>

                  <!-- Libros adicionales #2 – #100 -->
                  <?php for ($i=1; $i < 100; $i++): ?>
                  <div id="agg_l<?php echo $i; ?>" class="d-none sm-book-block">
                    <div class="row">
                      <div class="form-group col-sm-4">
                        <label id="l_materia<?php echo $i; ?>" for="materia<?php echo $i; ?>" class="control-label">Materia <small style="color:red;">*</small></label>
                        <select name="materia[]" id="materia<?php echo $i; ?>" class="form-control">
                          <option value="">Selecciona una materia</option>
                          <?php
                            $sql = "SELECT id, materia FROM materias";
                            $req = $bdd->prepare($sql);
                            $req->execute();
                            $colegios = $req->fetchAll();
                            foreach ($colegios as $colegio) {
                              echo '<option value="'.$colegio['id'].'">'.$colegio['materia'].'</option>';
                            }
                          ?>
                        </select>
                      </div>
                      <div class="form-group col-sm-4">
                        <label id="l_libro<?php echo $i; ?>" for="libro<?php echo $i; ?>" class="control-label">Libro <small style="color:red;">*</small></label>
                        <select name="libro" id="libro<?php echo $i; ?>" class="form-control custom-select2" width="200"></select>
                      </div>
                      <div class="form-group col-sm-4">
                        <label id="l_cantidad<?php echo $i; ?>" for="cantidad<?php echo $i; ?>" class="control-label">Cantidad <small style="color:red;">*</small></label>
                        <input type="number" class="form-control cantidad" name="cantidad" id="cantidad<?php echo $i; ?>" placeholder="Ingresa la cantidad">
                      </div>
                    </div>
                    <div id="ls_pri_sec<?php echo $i; ?>"></div>
                    <input type="hidden" name="libro_e[]" id="libro_e<?php echo $i; ?>">
                  </div>
                  <?php endfor; ?>

                </div><!-- /.otro_l -->

                <a id="agregar_libro" class="sm-add-btn">
                  <i class="bi bi-plus-circle"></i> Agregar libro
                </a>

                <hr class="sm-divider">

                <div class="row">
                  <div class="<?= $_GET['tp'] == 2 ? 'col-md-8' : 'col-md-12' ?> col-sm-12">
                    <div class="form-group mb-3">
                      <label for="observaciones" class="control-label">Observaciones</label>
                      <textarea name="observaciones" id="observaciones" class="form-control" rows="3" placeholder="Escribe observaciones opcionales..."></textarea>
                    </div>
                  </div>
                  <?php if ($_GET['tp'] == 2): ?>
                  <div class="col-md-4 col-sm-12">
                    <div class="form-group mb-3">
                      <label class="control-label">Archivo adjunto</label>
                      <label class="sm-file-label" id="sm-file-label" for="archivo_muestreo">
                        <i class="bi bi-cloud-upload"></i>
                        <span id="sm-file-text">Haz clic para adjuntar</span>
                      </label>
                      <input type="file" name="archivo_muestreo" id="archivo_muestreo" class="sm-file-input"
                             accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                      <p class="sm-file-name" id="sm-file-name"></p>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>

                <div class="sm-footer">
                  <input type="hidden" name="tp" value="<?php echo $_GET['tp']; ?>">
                  <?php if ($_GET['tp']!=2): ?>
                    <button class="btn btn-primary" id="solicitar">
                      <i class="bi bi-send"></i> Solicitar muestreo
                    </button>
                  <?php else: ?>
                    <button class="btn btn-primary" id="solicitar">
                      <i class="bi bi-check-circle"></i> Legalizar muestras
                    </button>
                  <?php endif; ?>
                </div>

              </div>
            </div>

          </form>

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

         // 1. Al cargar la página, verificamos si hay un valor en la sesión
        var valorGuardado = sessionStorage.getItem('select_tipo_valor');
        
        if (valorGuardado) {
            $('#tipo').val(valorGuardado);
            var cambios = 1; 
        } else {
            var cambios = 0;
        }

        // 2. Evento change
        $('#tipo').on('change', function() {
            var valor = $(this).val();
            cambios++;
            
            if (valor != '' && cambios > 1) {
                sessionStorage.setItem('select_tipo_valor', valor);
                location.reload();
            }
        });

        // 3. SOLUCIÓN AL ENVIAR EL FORMULARIO: Limpiar al hacer submit
        // Cambia 'form' por el ID o clase de tu formulario si es necesario (ej: '#miFormulario')
        $('form').on('submit', function() {
            sessionStorage.removeItem('select_tipo_valor');
        });

        // 4. SOLUCIÓN AL CAMBIAR DE URL: Limpiar si el usuario hace clic en un enlace a otra página
        $('a').on('click', function() {
            // Opcional: Podrías verificar si el enlace va a otra página antes de borrar,
            // pero borrarlo al hacer clic asegura que la siguiente URL empiece limpia.
            sessionStorage.removeItem('select_tipo_valor');
        });



      $('#materia').on('change',function(){
      var valor = $(this).val();
      var tipo = $("#tipo").val();
      //alert(valor);
      var dataString = 'mat_gra='+valor+"/"+tipo;

      $.ajax({

        url: "ajax/buscar_l_eureka_sp_new.php",
        type: "POST",
        data: dataString,
        dataType: "html",
        success: function (resp) {

            $("#libro").html(resp);
            //console.log(resp);
        },
        error: function (jqXHR,estado,error){
            alert("Debes seleccionar tipo de muestras");
            console.log(estado);
            console.log(error);
            $("#materia").val("");
        },
        complete: function (jqXHR,estado){
            console.log(estado);
        }


      })

    });

      $('#libro').on('change',function(){
        var cant =$('#cantidad').val();
        var libro=$('#libro').val();
        var grado = $('#libro option:selected').attr('data-grado');


        if (grado==15 || grado==16) {
          $('#l_cantidad').addClass("d-none");
          $('#cantidad').addClass("d-none");

          var dataString = 'pri_sec='+libro;

          $.ajax({

              url: "ajax/buscar_pri_sec.php",
              type: "POST",
              data: dataString,
              dataType: "html",
              success: function (resp) {
                  $("#ls_pri_sec").html('');
                  $("#ls_pri_sec").append(resp);
                  console.log(resp);
              },
              error: function (jqXHR,estado,error){
                  alert("error");
                  console.log(estado);
                  console.log(error);
              },
              complete: function (jqXHR,estado){
                  console.log(estado);
              }


          })

        }else{
          $('#libro_e').val(libro+'/'+cant);
        }



      })

    $('#cantidad').keyup(function(){
      var cant =$('#cantidad').val();
      var libro=$('#libro').val();
      var grado = $('#libro option:selected').attr('data-grado');

      if (grado!=15 || grado!=16) {
        $('#libro_e').val(libro+'/'+cant);
      }

  })



  var m = 1;

  $("#agregar_libro").click(function(){
    if (m>98) {
      $("#agregar_libro").addClass("d-none");
    }

    $("#agg_l"+m).removeClass("d-none")

    m++;
    <?php for ($i=1; $i < 100; $i++) { ?>

      $('#materia<?php echo $i; ?>').on('change',function(){
          var valor = $(this).val();
          var tipo = $("#tipo").val();
          //alert(valor);
          var dataString = 'mat_gra='+valor+"/"+tipo;

          $.ajax({

              url: "ajax/buscar_l_eureka_sp_new.php",
              type: "POST",
              data: dataString,
              dataType: "html",
              success: function (resp) {

                  $("#libro<?php echo $i; ?>").html(resp);
                  //console.log(resp);
              },
              error: function (jqXHR,estado,error){
                  alert("error");
                  console.log(estado);
                  console.log(error);
              },
              complete: function (jqXHR,estado){
                  console.log(estado);
              }


        })

        });



      $('#libro<?php echo $i; ?>').on('change',function(){
        var cant =$('#cantidad<?php echo $i; ?>').val();
        var libro=$('#libro<?php echo $i; ?>').val();
        var grado = $('#libro<?php echo $i; ?> option:selected').attr('data-grado');


        if (grado==15 || grado==16) {
          $('#l_cantidad<?php echo $i; ?>').addClass("d-none");
          $('#cantidad<?php echo $i; ?>').addClass("d-none");

          var dataString = 'pri_sec='+libro;

          $.ajax({

              url: "ajax/buscar_pri_sec.php",
              type: "POST",
              data: dataString,
              dataType: "html",
              success: function (resp) {
                  $("#ls_pri_sec<?php echo $i; ?>").html('');
                  $("#ls_pri_sec<?php echo $i; ?>").append(resp);
                  console.log(resp);
              },
              error: function (jqXHR,estado,error){
                  alert("error");
                  console.log(estado);
                  console.log(error);
              },
              complete: function (jqXHR,estado){
                  console.log(estado);
              }


          })

        }else{
          $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant);
        }



      })

      $('#cantidad<?php echo $i; ?>').keyup(function(){
        var cant =$('#cantidad<?php echo $i; ?>').val();
        var libro=$('#libro<?php echo $i; ?>').val();
        var grado = $('#libro option:selected').attr('data-grado');

        if (grado!=15 || grado!=16) {
          $('#libro_e<?php echo $i; ?>').val(libro+'/'+cant);
        }




      })

    <?php } ?>



  })
    </script>

    <script>
      $('#archivo_muestreo').on('change', function () {
        var $label = $('#sm-file-label');
        var $name  = $('#sm-file-name');
        if (this.files && this.files.length > 0) {
          $('#sm-file-text').text('Archivo seleccionado');
          $name.text(this.files[0].name);
          $label.addClass('has-file');
        } else {
          $('#sm-file-text').text('Haz clic para adjuntar');
          $name.text('');
          $label.removeClass('has-file');
        }
      });
    </script>

    <!-- Toast de estado -->
    <div id="sm-toast" class="sm-toast" role="alert">
      <i id="sm-toast-icon" class="bi"></i>
      <span id="sm-toast-msg"></span>
    </div>

    <script>
      (function () {
        var params = new URLSearchParams(window.location.search);
        var status = params.get('status');
        if (!status) return;

        var toast = document.getElementById('sm-toast');
        var icon  = document.getElementById('sm-toast-icon');
        var msg   = document.getElementById('sm-toast-msg');

        if (status === 'ok') {
          toast.classList.add('ok');
          icon.className = 'bi bi-check-circle-fill';
          msg.textContent = '<?php echo $_GET['tp'] != 2 ? 'Muestreo solicitado correctamente.' : 'Muestras legalizadas correctamente.'; ?>';
        } else {
          toast.classList.add('error');
          icon.className = 'bi bi-x-circle-fill';
          msg.textContent = 'Ocurrió un error al guardar. Intenta de nuevo.';
        }

        toast.style.display = 'flex';

        setTimeout(function () {
          toast.style.transition = 'opacity .4s';
          toast.style.opacity = '0';
          setTimeout(function () { toast.style.display = 'none'; }, 420);
        }, 4500);

        // Limpiar el parámetro de la URL sin recargar
        var clean = window.location.pathname + '?tp=' + (params.get('tp') || '1');
        history.replaceState(null, '', clean);
      })();
    </script>

  </body>
</html>
