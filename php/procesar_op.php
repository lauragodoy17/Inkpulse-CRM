<?php
	ini_set('display_startup_errors', 1);
	ini_set('display_errors', 1);
	error_reporting(-1);
	require_once("../php/aut.php");
	include("../conexion/bdd.php");
	require_once("../includes/historial_documento_op.php");
	require_once("../includes/documentos_wo_usados.php");
	require_once("../includes/excedentes_despacho.php");

	header("Content-Type:text/html;charset=utf-8");

	// El documento (n_doc) y el valor despachado son ÚNICOS por OP: se
	// buscan/asignan una sola vez arriba del formulario. Los "apartados"
	// (entregado a + guía + fecha + observaciones + soporte) sí se repiten,
	// uno por posición del mismo índice en esos arrays — así que se guarda
	// un registro en op_atendidas por apartado, todos con el mismo n_doc y
	// valor.
	$n_doc          = trim($_POST['n_doc'] ?? '');
	$valor          = trim($_POST['valor'] ?? '');
	$id_wo          = trim($_POST['id_wo'] ?? '');
	$transportistas = $_POST['transportista'] ?? [];
	$guias          = $_POST['guia'] ?? [];
	$fechas_entrega = $_POST['fecha_entrega'] ?? [];
	$obs_envios     = $_POST['obs_envio'] ?? [];
	$archivos       = $_FILES['adjunto_envio'] ?? null;

	$op_id      = intval($_POST['op'] ?? 0);
	$accion     = ($_POST['accion'] ?? 'atender') === 'guardar' ? 'guardar' : 'atender';
	// Relativo a este script (php/) en vez de $_SERVER['DOCUMENT_ROOT']: en
	// XAMPP local, htdocs sirve varios proyectos por symlink y DOCUMENT_ROOT
	// no coincide con la raíz real de este proyecto, así que el adjunto
	// terminaba subiéndose a una ruta inexistente y no quedaba guardado.
	$dir_subida = dirname(__DIR__) . '/adjuntos/envio/';

	if ($n_doc === '' || $valor === '') {
		echo "Falta buscar y asignar el documento y el valor despachado antes de guardar.";
		exit;
	}

	$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	crear_tabla_historial_documento_op($bdd);
	crear_tabla_documentos_wo_usados($bdd);
	excedentes_asegurar_tabla($bdd);

	// Excedente frente a lo solicitado en los pedidos de la OP (ver
	// includes/excedentes_despacho.php). Si el documento ya se había confirmado
	// al guardarlo, no se vuelve a pedir confirmación; solo se actualizan las cifras.
	$excedente = excedentes_calcular($bdd, $op_id, intval($id_wo));
	if (excedentes_requiere_confirmacion($excedente, !empty($_POST['confirmar_excedente']))) {
		header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode(excedentes_mensaje($excedente) . ' Confirma el excedente para continuar.'));
		exit;
	}

	try {
		$bdd->beginTransaction();

		// Documento actual de la OP (antes de sobrescribirlo) para saber si el
		// documento/valor que se está guardando es distinto al que ya tenía y
		// así solo dejar un registro en el historial cuando realmente cambia.
		$r_prev = $bdd->prepare("SELECT tipo_doc, n_doc, valor FROM ordenes_pedidos WHERE id = ?");
		$r_prev->execute([$op_id]);
		$prev = $r_prev->fetch();

		$ins = $bdd->prepare(
			"INSERT INTO op_atendidas (opid, n_doc, transportista, guia, fecha_entrega, valor, obs_envio, fecha_at, usuario_at, adjunto_envio)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
		);

		$apartados_guardados = 0;

		foreach ($transportistas as $i => $transportista) {
			$transportista = trim($transportista);
			$fecha_entrega = trim($fechas_entrega[$i] ?? '');

			// Un apartado vacío (por ejemplo uno agregado y no usado) se
			// salta en vez de fallar toda la solicitud.
			if ($transportista === '' && $fecha_entrega === '') continue;

			$nombre_archivo = '';
			if ($archivos && isset($archivos['tmp_name'][$i]) && $archivos['error'][$i] === UPLOAD_ERR_OK) {
				$nombre_archivo = uniqid() . '_' . basename($archivos['name'][$i]);
				$fichero_subido = $dir_subida . $nombre_archivo;
				if (!move_uploaded_file($archivos['tmp_name'][$i], $fichero_subido)) {
					$nombre_archivo = '';
				}
			}

			$ins->execute([
				$op_id,
				$n_doc,
				$transportista,
				trim($guias[$i] ?? ''),
				$fecha_entrega,
				$valor,
				trim($obs_envios[$i] ?? ''),
				date('Y-m-d H:i:s'),
				$_SESSION['id'],
				$nombre_archivo,
			]);

			$apartados_guardados++;
		}

		// "Atender" cierra la OP, así que sí necesita al menos un apartado de
		// envío completo. "Guardar" no: el documento y su valor ya quedan
		// guardados en la OP como historial aunque todavía no se haya
		// completado ningún apartado (para eso es "Guardar").
		if ($apartados_guardados === 0 && $accion === 'atender') {
			$bdd->rollBack();
			echo "No se guardó ningún apartado: completa al menos uno con entregado a y fecha de despacho para poder atender.";
			exit;
		}

		// Un documento de World Office solo puede usarse para UN despacho en
		// total — ni siquiera para un segundo despacho de la MISMA OP en una
		// solicitud posterior (varios apartados agregados en ESTA misma
		// solicitud sí cuentan como un solo despacho, eso está bien). Se
		// valida justo aquí, ya con $apartados_guardados calculado, porque
		// "Guardar" sin apartados (solo asociar el documento) no cuenta
		// todavía como un despacho real y no debe bloquear nada.
		if ($apartados_guardados > 0) {
			$otro_despacho = documento_wo_ya_usado($bdd, $id_wo);
			if ($otro_despacho) {
				$bdd->rollBack();
				$msg = 'El documento ' . $n_doc . ' ya se usó en el despacho de la OP ' . $otro_despacho['año'] . '-' . $otro_despacho['opid'] . '. Asocia otro documento distinto para este despacho.';
				header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=error&ink_msg=' . urlencode($msg));
				exit;
			}
		}

		// El documento y el valor son únicos por OP, así que se guardan tal
		// cual en ordenes_pedidos (no hay que recalcular nada desde
		// op_atendidas): esto es lo que hace que "Guardar" deje un historial
		// del documento elegido incluso sin apartados todavía.
		if ($accion === 'guardar') {
			$upd = $bdd->prepare("UPDATE ordenes_pedidos SET tipo_doc = ?, n_doc = ?, cliente = ?, valor = ? WHERE id = ?");
		} else {
			$upd = $bdd->prepare("UPDATE ordenes_pedidos SET tipo_doc = ?, n_doc = ?, cliente = ?, valor = ?, estado = '2' WHERE id = ?");
		}
		$upd->execute([
			$_POST['tipo_doc'] ?? null,
			$n_doc,
			$_POST['cliente'] ?? null,
			$valor,
			$op_id,
		]);

		// Deja constancia en el historial solo cuando el documento/valor
		// cambió respecto al que tenía la OP — así "Guardar" no llena el
		// historial de registros repetidos, pero sí queda rastro cada vez que
		// se reemplaza el documento por otro.
		$tipo_doc_nuevo = $_POST['tipo_doc'] ?? null;
		$cambio = documento_op_cambio($prev, $tipo_doc_nuevo, $n_doc, $valor);
		if ($cambio) {
			// $id_wo viaja en un campo oculto del formulario, actualizado por
			// JS cada vez que se elige un candidato del buscador de World
			// Office; queda null solo si el n_doc se escribió/cambió a mano
			// sin pasar por ahí (no debería pasar con los campos de solo
			// lectura, pero por si acaso).
			registrar_historial_documento_op($bdd, $op_id, $tipo_doc_nuevo, $n_doc, $id_wo, $valor, $_SESSION['id']);
		}

		// Recién ahora, con al menos un apartado realmente registrado, el
		// documento queda consumido para cualquier despacho futuro (de esta
		// OP o de otra).
		if ($apartados_guardados > 0) {
			marcar_documento_wo_usado($bdd, $id_wo, $op_id, $n_doc, $_SESSION['id']);
		}

		excedentes_registrar($bdd, $op_id, $id_wo, $n_doc, $excedente, $accion === 'atender' ? 'atender' : 'guardar_despacho', $_SESSION['id']);

		$bdd->commit();
	} catch (Exception $e) {
		if ($bdd->inTransaction()) $bdd->rollBack();
		echo "Error al registrar el despacho: " . $e->getMessage();
		exit;
	}

	if ($accion === 'guardar') {
		$msg = $apartados_guardados > 0
			? 'Despacho guardado. Puedes añadir otro apartado.'
			: 'Documento guardado. Puedes completar el despacho más tarde.';
		header('Location: ../op_pendiente.php?op=' . $op_id . '&ink_status=ok&ink_msg=' . urlencode($msg));
	} else {
		header('Location: ../formato_op.php?op=' . $op_id . '&ink_status=ok&ink_msg=' . urlencode('OP atendida correctamente.'));
	}
?>
