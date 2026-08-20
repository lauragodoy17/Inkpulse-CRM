<?php

	/*ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);*/

	require_once("aut.php");
	require_once('../conexion/bdd.php');
	require_once("registrar_historial.php");
	require_once("../includes/materializar_atenciones_pendientes.php");

	use PHPMailer\PHPMailer\PHPMailer;
	use PHPMailer\PHPMailer\SMTP;
	use PHPMailer\PHPMailer\Exception;

	require '../lib/PHPMailer/src/Exception.php';
	require '../lib/PHPMailer/src/PHPMailer.php';
	require '../lib/PHPMailer/src/SMTP.php';

	// Columnas para enlazar la solicitud "raíz" (año 1) con las que se materialicen más adelante
	// para los años siguientes (ver includes/materializar_atenciones_pendientes.php).
	// OJO: este archivo tiene "use PHPMailer\PHPMailer\Exception;" más arriba, así que un
	// catch(Exception) a secas atraparía la Exception de PHPMailer, no la PDOException real —
	// por eso el \Exception con barra invertida (namespace global) es obligatorio acá.
	try { $bdd->exec("ALTER TABLE solicitudes_recursos ADD COLUMN distribucion_grupo_id INT NULL AFTER conse"); } catch (\Exception $e) {}
	try { $bdd->exec("ALTER TABLE solicitudes_recursos ADD COLUMN distribucion_anio_num INT NULL AFTER distribucion_grupo_id"); } catch (\Exception $e) {}
	try { $bdd->exec("ALTER TABLE solicitudes_recursos ADD COLUMN distribucion_total_anios INT NULL AFTER distribucion_anio_num"); } catch (\Exception $e) {}
	crear_tabla_pendientes_distribucion($bdd);

	$periodo_actual      = (int)$_POST["periodo"];
	$distribucion_anios  = isset($_POST["distribucion_anios"]) ? max(1, min(4, (int)$_POST["distribucion_anios"])) : 1;

	// ── Año calendario y calendario del período actual (para calcular a qué año calendario
	// apunta cada cuota futura, sin necesidad de que esos períodos existan todavía). ──
	$req_periodo_actual = $bdd->prepare("SELECT periodo, id_calendario FROM periodos WHERE id = ?");
	$req_periodo_actual->execute([$periodo_actual]);
	$periodo_actual_row   = $req_periodo_actual->fetch(PDO::FETCH_ASSOC) ?: [];
	$id_calendario_actual = (int)($periodo_actual_row['id_calendario'] ?? 0);
	$anio_actual          = anio_de_periodo($periodo_actual_row['periodo'] ?? '');

	// ── Repartir el presupuesto de cada recurso entre los años: partes iguales, con el resto
	// (si el reparto no es exacto) sumado al último año, para que la suma total sea idéntica al
	// valor original solicitado. ──
	$recursos_parsed = [];
	foreach ($_POST["recursos"] as $recurso_at) {
		list($recurso, $tipo, $categoria, $presupuesto) = explode("/", $recurso_at);
		if ($recurso === "") continue;

		$presupuesto = (int)round((float)$presupuesto);
		$base  = intdiv($presupuesto, $distribucion_anios);
		$resto = $presupuesto - ($base * $distribucion_anios);
		$recursos_parsed[] = ['recurso' => $recurso, 'tipo' => $tipo, 'categoria' => $categoria, 'base' => $base, 'resto' => $resto];
	}

	// ── Áreas comprometidas: solo se guardan en la solicitud del año 1 (las de los años
	// siguientes se copian de ahí en el momento en que se materialicen). ──
	$areas_parsed = [];
	foreach ($_POST["areas_r"] as $libro) {
		if (empty($libro) || substr_count($libro, '/') < 3) continue;
		list($materia, $preescolar, $primaria, $bachillerato) = explode("/", $libro, 4);
		if ($materia != 0) {
			$areas_parsed[] = ['materia' => $materia, 'preescolar' => $preescolar, 'primaria' => $primaria, 'bachillerato' => $bachillerato];
		}
	}

	// ── Fecha de entrega de cada año: se corre +N años (mismo mes/día) respecto a la fecha que
	// el usuario escribió para el año 1. ──
	$fechas_por_anio = [];
	for ($idx = 0; $idx < $distribucion_anios; $idx++) {
		$dt = date_create($_POST["fecha_entrega"]);
		if ($dt && $idx > 0) $dt->modify('+' . $idx . ' year');
		$fechas_por_anio[$idx] = $dt ? $dt->format('Y-m-d') : $_POST["fecha_entrega"];
	}

	// ── Solicitud del año 1 (período actual): igual que siempre, nunca requiere crear nada. ──
	$req_conse = $bdd->prepare("SELECT MAX(conse) as conse FROM solicitudes_recursos WHERE id_periodo = ?");
	$req_conse->execute([$periodo_actual]);
	$conse = ((int)$req_conse->fetchColumn()) + 1;

	$stmt_solicitud = $bdd->prepare(
		"INSERT INTO solicitudes_recursos
			(id_periodo, usuario, id_colegio, estado, solicitante, fecha_entrega, reintegro, conse, distribucion_grupo_id, distribucion_anio_num, distribucion_total_anios)
		 VALUES (?, ?, ?, 1, ?, ?, ?, ?, NULL, 1, ?)"
	);
	$stmt_solicitud->execute([
		$periodo_actual, $_SESSION["id"], $_POST["id_colegio"], $_POST["solicitante"],
		$fechas_por_anio[0], $_POST["reintegro"], $conse, $distribucion_anios,
	]);
	$id_solicitud = (int)$bdd->lastInsertId();

	foreach ($areas_parsed as $a) {
		$bdd->prepare("INSERT INTO areas_recursos (id_solicitud, materia, preescolar, primaria, bachillerato) VALUES (?, ?, ?, ?, ?)")
			->execute([$id_solicitud, $a['materia'], $a['preescolar'], $a['primaria'], $a['bachillerato']]);
	}

	// ── Recursos del año 1 + cuotas pendientes de los años siguientes (si aplica). Las
	// pendientes NO tocan la tabla periodos ni crean ninguna solicitud todavía: quedan a la
	// espera de que ese período exista de verdad (ver includes/materializar_atenciones_pendientes.php). ──
	$stmt_recurso = $bdd->prepare("INSERT INTO recursos_solicitados (id_solicitud, tipo, categoria, recurso, presupuesto) VALUES (?, ?, ?, ?, ?)");
	$stmt_pendiente = $bdd->prepare(
		"INSERT INTO atenciones_pendientes_distribucion
			(id_solicitud_origen, id_recurso_origen, id_colegio, id_calendario, anio_objetivo, id_usuario, solicitante, fecha_entrega, reintegro, recurso, tipo, categoria, monto, distribucion_anio_num, distribucion_total_anios)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
	);

	$anios_pendientes = [];
	foreach ($recursos_parsed as $r) {
		$monto_anio1 = $r['base'] + ($distribucion_anios === 1 ? $r['resto'] : 0);
		$stmt_recurso->execute([$id_solicitud, $r['tipo'], $r['categoria'], $r['recurso'], $monto_anio1]);
		$id_recurso_origen = (int)$bdd->lastInsertId();

		for ($idx = 1; $idx < $distribucion_anios; $idx++) {
			$anio_objetivo = $anio_actual + $idx;
			$monto = $r['base'] + ($idx === $distribucion_anios - 1 ? $r['resto'] : 0);
			$stmt_pendiente->execute([
				$id_solicitud, $id_recurso_origen, $_POST["id_colegio"], $id_calendario_actual, $anio_objetivo,
				$_SESSION["id"], $_POST["solicitante"], $fechas_por_anio[$idx], $_POST["reintegro"],
				$r['recurso'], $r['tipo'], $r['categoria'], $monto, $idx + 1, $distribucion_anios,
			]);
			$anios_pendientes[$anio_objetivo] = true;
		}
	}
	$anios_pendientes = array_keys($anios_pendientes);
	sort($anios_pendientes);

	// ── Notificación: solo sobre la solicitud del año 1 (las cuotas futuras generan su propia
	// notificación automáticamente cuando se materialicen). ──
	$sql_z = "INSERT INTO notificaciones (id_periodo,id_colegio,id_usuario,id_solicitud,id_tipo_notifi,usuario_respuesta,visible) VALUES (?,?,0,?,7,0,1)";
	$stmt_z = $bdd->prepare($sql_z);
	$stmt_z->execute([$periodo_actual, $_POST["id_colegio"], $id_solicitud]);

	$sq_l2 = "SELECT CONCAT(nombres, ' ', apellidos) AS promotor FROM usuarios WHERE id='".$_SESSION["id"]."'";
	$req_l2 = $bdd->prepare($sq_l2);
	$req_l2->execute();
	$promo = $req_l2->fetch();

	$sq_l3 = "SELECT colegio FROM colegios WHERE id='".$_POST["id_colegio"]."'";
	$req_l3 = $bdd->prepare($sq_l3);
	$req_l3->execute();
	$cole = $req_l3->fetch();

	$mail = new PHPMailer(true);

	try {

		//Server settings
		//$mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;                      // OFF verbose debug output
		$mail->isSMTP();                                            // Send using SMTP
	    $mail->Host       = 'somoseureka.com.co';                    // Set the SMTP server to send through
	    $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
	    $mail->SMTPAutoTLS = false;
	    $mail->Username   = 'crm@somoseureka.com.co';                     // SMTP username
	    $mail->Password   = 'cRm14356$';                              // SMTP password
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
		$mail->Port       = 587;
		$mail->SMTPOptions = [
		      'ssl' => [
		        'verify_peer' => false,
		        'verify_peer_name' => false,
		        'allow_self_signed' => true
		    ]
    	];                                     // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_S	above                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

		//Recipients
		$mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
		$mail->addAddress("felipe.vargas@somoseureka.com.co", 'felipe.vargas@somoseureka.com.co');     // Add a recipient

		//$mail->addCC("oltoledo@hotmail.com");
		//$mail->addBCC('comercial@eurekalibros.com.co');


		// Content
		$mail->isHTML(true);


		                                  // Set email format to HTML
		$mail->Subject = 'Solicitud de recursos #'.$id_solicitud.($distribucion_anios > 1 ? ' (distribuida en '.$distribucion_anios.' años)' : '');

		$mail->Body = '<p style="font-size: 17px;">'.$promo["promotor"].' hizo la solicitud de recursos #'.$id_solicitud.' para: '.$cole["colegio"].'</p>';
		if (!empty($anios_pendientes)) {
			$mail->Body .= '<p>Distribuida en '.$distribucion_anios.' años. Las cuotas de '.implode(', ', $anios_pendientes).
				' quedan pendientes y se activarán automáticamente cuando esos períodos existan.</p>';
		}

		$mail->AltBody = 'probandosss';

		$mail->CharSet = 'UTF-8';

		$mail->send();
			//echo "<script>alert('We have sent a message to your registered email. Check your Inbox or check your Spam Mail folder.');window.location='../index.php';</script>";
	} catch (Exception $e) {

		echo "An error has occurred please try again: {$mail->ErrorInfo}";
	}

	$valor_nuevo_hist = 'Solicitud #'.$conse;
	if (!empty($anios_pendientes)) {
		$valor_nuevo_hist .= ' distribuida en '.$distribucion_anios.' años (pendientes: '.implode(', ', $anios_pendientes).')';
	}
	registrar_historial($bdd, $_POST["id_colegio"], intval($_SESSION["id"] ?? 0), 'Atenciones al cliente',
		'Nueva solicitud de recursos', '', $valor_nuevo_hist);

	header('Location: ../colegio.php?codigo='.$_POST["cod_colegio"].'&periodo='.$_POST["periodo"].'&tab=atenciones');

?>
