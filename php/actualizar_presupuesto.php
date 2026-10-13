<?php
    require_once("../php/aut.php");
    include("../conexion/bdd.php");
    require_once("registrar_historial.php");
    require_once(__DIR__ . "/../includes/presupuesto_cerrado.php");

    if (presupuesto_cerrado($bdd, $_POST["id_colegio"] ?? 0, $_POST["periodo"] ?? 0)) {
        header('Location: ../colegio.php?codigo='.urlencode($_POST["codigo"] ?? '').'&periodo='.intval($_POST["periodo"] ?? 0).'&tab=presupuesto');
        exit;
    }

    $id_usuario_h = intval($_SESSION["id"] ?? 0);

    if (isset($_POST["presupuesto_p"])) {



        $sql_fcole = "SELECT MAX(fila_zona) as fila_zona FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

        $req_fcole = $bdd->prepare($sql_fcole);
        $req_fcole->execute();
        $fcole = $req_fcole->fetch();

        if ($fcole["fila_zona"] > 0) {

            $fila_zona= $fcole["fila_zona"];

        }else {


            $sql_zona = "SELECT cod_zona FROM colegios WHERE id='".$_POST["id_colegio"]."'";

            $req_zona = $bdd->prepare($sql_zona);
            $req_zona->execute();
            $zona = $req_zona->fetch();


            $sql = "SELECT MAX(fila_zona) as fila_zona FROM presupuestos p JOIN colegios c ON p.id_colegio=c.id JOIN zonas z ON z.codigo=c.cod_zona WHERE p.id_periodo='".$_POST["periodo"]."' AND z.codigo='".$zona["cod_zona"]."'";

            $req = $bdd->prepare($sql);
            $req->execute();
            $con_fila_zona = $req->fetch();

            if ($con_fila_zona["fila_zona"] > 0) {

                $fila_zona=$con_fila_zona["fila_zona"] + 1;
            }
            else {

                $fila_zona=2;
            }

        }

        $sql_fcole = "SELECT MAX(fila) as fila FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."'";

        $req_fcole = $bdd->prepare($sql_fcole);
        $req_fcole->execute();
        $fcole = $req_fcole->fetch();

        if ($fcole["fila"] > 0) {

            $fila= $fcole["fila"];

        }else {

            $sql = "SELECT MAX(fila) as fila FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."'";

            $req = $bdd->prepare($sql);
            $req->execute();
            $con_fila = $req->fetch();

            if ($con_fila["fila"] > 0) {

                $fila=$con_fila["fila"] + 1;
            }
            else {

                $fila=2;
            }

        }

        foreach ($_POST["presupuesto_p"] as $presups => $presup) {



            list($libro,$tasa_c,$descuento,$precio, $probab) = explode("/", $presup);



            if ($libro !="" && $tasa_c !="") {

                $sql_cod = "SELECT p.id_libro, g.id_grado_otro FROM presupuestos p JOIN areas_objetivas g ON g.id_libro_eureka=p.id_libro WHERE g.codigo='".$libro."'";
                $req_cod = $bdd->prepare($sql_cod);
                $req_cod->execute();
                $row_cod = $req_cod->fetch();

                if ($row_cod["id_grado_otro"] == 0) {

                    // Fetch old values and book name
                    $req_old_p = $bdd->prepare("SELECT precio, tasa_compra, descuento, probabilidad FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'");
                    $req_old_p->execute();
                    $old_p = $req_old_p->fetch();

                    $req_lib = $bdd->prepare("SELECT libro FROM libros WHERE id=:id");
                    $req_lib->execute([':id' => $libro]);
                    $lib_row = $req_lib->fetch();
                    $lib_nombre = $lib_row ? $lib_row['libro'] : "Libro #$libro";

                    $sql = "SELECT columna FROM libros WHERE id='".$libro."'";

                    $req = $bdd->prepare($sql);
                    $req->execute();
                    $con_colum = $req->fetch();

                    $sql_e = "UPDATE presupuestos SET precio='".$precio."', tasa_compra='".$tasa_c."', descuento='".$descuento."', probabilidad='".$probab."', fecha='".date("Y-m-d")."', fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND id_libro='".$libro."'";


                    $query_e = $bdd->prepare( $sql_e );
                    if ($query_e == false) {
                        print_r($bdd->errorInfo());
                            die ('Erreur prepare');
                        }
                    $sth_e = $query_e->execute();
                    if ($sth_e == false) {
                        print_r($query_e->errorInfo());
                        die ('Erreur execute');
                    }

                }else{

                    // Fetch old values and book name
                    $req_old_p = $bdd->prepare("SELECT precio, tasa_compra, descuento, probabilidad FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$libro."'");
                    $req_old_p->execute();
                    $old_p = $req_old_p->fetch();

                    $req_lib = $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id=a.id_libro_eureka WHERE a.codigo='".$libro."'");
                    $req_lib->execute();
                    $lib_row = $req_lib->fetch();
                    $lib_nombre = $lib_row ? $lib_row['libro'] : "Libro cod $libro";

                    $sql = "SELECT l.columna FROM libros l JOIN areas_objetivas a ON l.id=a.id_libro_eureka WHERE a.codigo='".$libro."'";

                    $req = $bdd->prepare($sql);
                    $req->execute();
                    $con_colum = $req->fetch();

                    echo $con_colum["columna"]."<br>";

                    $sql_e = "UPDATE presupuestos SET precio='".$precio."', tasa_compra='".$tasa_c."', descuento='".$descuento."', probabilidad='".$probab."', fecha='".date("Y-m-d")."', fila='".$fila."', fila_zona='".$fila_zona."', columna='".$con_colum["columna"]."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$libro."'";

                    $query_e = $bdd->prepare( $sql_e );
                    if ($query_e == false) {
                        print_r($bdd->errorInfo());
                            die ('Erreur prepare');
                        }
                    $sth_e = $query_e->execute();
                    if ($sth_e == false) {
                        print_r($query_e->errorInfo());
                        die ('Erreur execute');
                    }


                }

                // Historial: log changed presupuesto fields
                if ($old_p) {
                    $campos_p_num = [
                        'Tasa de compra' => [(float)($old_p['tasa_compra'] ?? 0), (float)$tasa_c],
                        'Descuento'      => [(float)($old_p['descuento'] ?? 0),   (float)$descuento],
                    ];
                    foreach ($campos_p_num as $cn => $vn) {
                        if (abs($vn[0] - $vn[1]) > 0.0001) {
                            registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Presupuesto',
                                "$cn - $lib_nombre", (string)$vn[0], (string)$vn[1]);
                        }
                    }
                    if ((int)($old_p['probabilidad'] ?? 0) !== (int)$probab) {
                        $stmt_prob = $bdd->prepare("SELECT probabilidad FROM probabilidades WHERE id=:id");
                        $stmt_prob->execute([':id' => $old_p['probabilidad']]);
                        $r = $stmt_prob->fetch();
                        $prob_old_name = $r ? $r['probabilidad'] : (string)$old_p['probabilidad'];

                        $stmt_prob->execute([':id' => $probab]);
                        $r = $stmt_prob->fetch();
                        $prob_new_name = $r ? $r['probabilidad'] : (string)$probab;

                        registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Presupuesto',
                            "Probabilidad - $lib_nombre", $prob_old_name, $prob_new_name);
                    }
                }
            }


        }

    }else{

        foreach ($_POST["presupuesto_p"] as $presups => $presup) {

            list($libro,$tasa_c,$descuento,$precio, $probab) = explode("/", $presup);


            if ($libro !="" && $tasa_c !="") {
                $sql_cod = "SELECT p.id_libro, g.id_grado_otro FROM presupuestos p JOIN areas_objetivas g ON g.id_libro_eureka=p.id_libro WHERE g.codigo='".$libro."'";
                $req_cod = $bdd->prepare($sql_cod);
                $req_cod->execute();
                $row_cod = $req_cod->fetch();

                if ($row_cod["id_grado_otro"] == 0) {

                    // Fetch old values and book name
                    $req_old_p = $bdd->prepare("SELECT precio, tasa_compra, descuento, probabilidad FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND (id_libro='".$libro."' OR cod_area='".$libro."')");
                    $req_old_p->execute();
                    $old_p = $req_old_p->fetch();

                    $req_lib = $bdd->prepare("SELECT libro FROM libros WHERE id=:id");
                    $req_lib->execute([':id' => $libro]);
                    $lib_row = $req_lib->fetch();
                    $lib_nombre = $lib_row ? $lib_row['libro'] : "Libro #$libro";

                    $sql_e = "UPDATE presupuestos SET precio='".$precio."', tasa_compra='".$tasa_c."', descuento='".$descuento."', probabilidad='".$probab."', fecha='".date("Y-m-d")."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND (id_libro='".$libro."' OR cod_area='".$libro."')";


                    $query_e = $bdd->prepare( $sql_e );
                    if ($query_e == false) {
                        print_r($bdd->errorInfo());
                            die ('Erreur prepare');
                        }
                    $sth_e = $query_e->execute();
                    if ($sth_e == false) {
                        print_r($query_e->errorInfo());
                        die ('Erreur execute');
                    }

                }else{

                    // Fetch old values and book name
                    $req_old_p = $bdd->prepare("SELECT precio, tasa_compra, descuento, probabilidad FROM presupuestos WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$libro."'");
                    $req_old_p->execute();
                    $old_p = $req_old_p->fetch();

                    $req_lib = $bdd->prepare("SELECT l.libro FROM libros l JOIN areas_objetivas a ON l.id=a.id_libro_eureka WHERE a.codigo='".$libro."'");
                    $req_lib->execute();
                    $lib_row = $req_lib->fetch();
                    $lib_nombre = $lib_row ? $lib_row['libro'] : "Libro cod $libro";

                    $sql_e = "UPDATE presupuestos SET precio='".$precio."', tasa_compra='".$tasa_c."', descuento='".$descuento."', probabilidad='".$probab."', fecha='".date("Y-m-d")."' WHERE id_periodo='".$_POST["periodo"]."' AND id_colegio='".$_POST["id_colegio"]."' AND cod_area='".$libro."'";

                    $query_e = $bdd->prepare( $sql_e );
                    if ($query_e == false) {
                        print_r($bdd->errorInfo());
                            die ('Erreur prepare');
                        }
                    $sth_e = $query_e->execute();
                    if ($sth_e == false) {
                        print_r($query_e->errorInfo());
                        die ('Erreur execute');
                    }


                }

                // Historial: log changed presupuesto fields
                if ($old_p) {
                    $campos_p_num = [
                        'Tasa de compra' => [(float)($old_p['tasa_compra'] ?? 0), (float)$tasa_c],
                        'Descuento'      => [(float)($old_p['descuento'] ?? 0),   (float)$descuento],
                    ];
                    foreach ($campos_p_num as $cn => $vn) {
                        if (abs($vn[0] - $vn[1]) > 0.0001) {
                            registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Presupuesto',
                                "$cn - $lib_nombre", (string)$vn[0], (string)$vn[1]);
                        }
                    }
                    if ((int)($old_p['probabilidad'] ?? 0) !== (int)$probab) {
                        $stmt_prob = $bdd->prepare("SELECT probabilidad FROM probabilidades WHERE id=:id");
                        $stmt_prob->execute([':id' => $old_p['probabilidad']]);
                        $r = $stmt_prob->fetch();
                        $prob_old_name = $r ? $r['probabilidad'] : (string)$old_p['probabilidad'];

                        $stmt_prob->execute([':id' => $probab]);
                        $r = $stmt_prob->fetch();
                        $prob_new_name = $r ? $r['probabilidad'] : (string)$probab;

                        registrar_historial($bdd, $_POST["id_colegio"], $id_usuario_h, 'Presupuesto',
                            "Probabilidad - $lib_nombre", $prob_old_name, $prob_new_name);
                    }
                }
            }


        }

    }


    header('Location: ../colegio.php?codigo='.$_POST["codigo"].'&periodo='.$_POST["periodo"].'&tab=presupuesto');




?>
