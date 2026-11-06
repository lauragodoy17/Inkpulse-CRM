<?php
/**
 * Registra backorders sin adopción de UN pedido sin adopción (pedidos2) y uno o VARIOS libros: cada
 * libro queda como su propio backorder (con su consecutivo), para poder atenderlo, anularlo y
 * consultarlo por separado. El cliente (tercero) sale del pedido — desde 2026-10-20 ya no se escoge
 * suelto en World Office. Todo se guarda en una sola transacción: si algún libro no es válido no
 * se registra ninguno. La fecha de registro la pone el servidor. Ver includes/backorders_sa_datos.php.
 *
 * POST: id_pedido2, id_libro[] + cantidad[] (misma posición = misma línea), observaciones.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/backorders_sa_datos.php");

function bsa_volver_guardar($ok, $msg) {
    header('Location: ../backorders_sa.php?ink_status=' . ($ok ? 'ok' : 'error') . '&ink_msg=' . urlencode($msg));
    exit;
}

if (!bsa_tiene_acceso()) { header('Location: ../index.php'); exit; }

$idPedido2 = intval($_POST['id_pedido2'] ?? 0);
$idsLibro = (array)($_POST['id_libro'] ?? []);
$cantidades = (array)($_POST['cantidad'] ?? []);
$observaciones = trim((string)($_POST['observaciones'] ?? ''));

if ($idPedido2 <= 0) bsa_volver_guardar(false, 'Selecciona el pedido.');
if (mb_strlen($observaciones) > 1000) bsa_volver_guardar(false, 'Las observaciones no pueden superar 1000 caracteres.');

// Líneas: se ignoran las totalmente vacías (una fila agregada y no usada); una a medias es error.
$lineas = []; // id_libro => cantidad
foreach (array_keys($idsLibro + $cantidades) as $i) {
    $idLibro = intval($idsLibro[$i] ?? 0);
    $cantidadTxt = trim((string)($cantidades[$i] ?? ''));
    if ($idLibro <= 0 && $cantidadTxt === '') continue;
    if ($idLibro <= 0) bsa_volver_guardar(false, 'Hay una línea con cantidad pero sin libro seleccionado.');
    if (!ctype_digit($cantidadTxt) || (int)$cantidadTxt <= 0) bsa_volver_guardar(false, 'Cada libro debe tener una cantidad entera mayor que cero.');
    if (isset($lineas[$idLibro])) bsa_volver_guardar(false, 'Un mismo libro está repetido: deja una sola línea con la cantidad total.');
    $lineas[$idLibro] = (int)$cantidadTxt;
}
if (!$lineas) bsa_volver_guardar(false, 'Agrega al menos un libro con su cantidad.');
// Hay pedidos sin adopción con más de 100 libros (se precargan todos al elegir el pedido).
if (count($lineas) > 300) bsa_volver_guardar(false, 'Máximo 300 libros por registro.');

$ph = implode(',', array_fill(0, count($lineas), '?'));
$stmt = $bdd->prepare("SELECT id, libro FROM libros WHERE id IN ($ph)");
$stmt->execute(array_keys($lineas));
$titulos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($titulos) !== count($lineas)) bsa_volver_guardar(false, 'Uno de los libros seleccionados no existe.');

bsa_asegurar_tablas($bdd);
// Pedido y cliente confirmados en el CRM (no se confía en lo que mande el navegador).
$pedido = bsa_pedido2_confirmado($bdd, $idPedido2);
if (isset($pedido['error'])) bsa_volver_guardar(false, $pedido['error']);
// Un pedido ya registrado (consignado) en backorders no se registra dos veces.
$registrado = bsa_pedidos2_registrados($bdd, [$pedido['id_pedido2']])[$pedido['id_pedido2']] ?? [];
if ($registrado) {
    bsa_volver_guardar(false, 'El pedido #' . $pedido['id_pedido2'] . ' ya está registrado en backorders (#' . implode(', #', $registrado) . ').');
}
// Un libro que está en el pedido no puede pedir más de lo que se solicitó ahí (los agregados a mano
// que no están en el pedido no tienen tope).
foreach (bsa_libros_pedido2($bdd, $pedido['id_pedido2']) as $idLibro => $l) {
    if (isset($lineas[$idLibro]) && $lineas[$idLibro] > $l['cantidad']) {
        bsa_volver_guardar(false, 'La cantidad de "' . $l['libro'] . '" (' . $lineas[$idLibro] . ') supera lo solicitado en el pedido #'
            . $pedido['id_pedido2'] . ' (' . $l['cantidad'] . ').');
    }
}
$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ids = [];
try {
    $bdd->beginTransaction();
    // El tercero es el cliente del pedido (nombre/identificación como quedaron en `clientes`, su id
    // de World Office si lo tiene).
    $ins = $bdd->prepare("INSERT INTO backorders_sa (id_libro, id_pedido2, id_cliente, tercero_id_wo, tercero_nombre, tercero_identificacion,
                                                     cantidad_solicitada, observaciones, fecha_registro, id_usuario_registro)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    foreach ($lineas as $idLibro => $cantidad) {
        $ins->execute([$idLibro, $pedido['id_pedido2'], $pedido['id_cliente'], $pedido['id_wo'], $pedido['nombre'], $pedido['identificacion'],
                       $cantidad, $observaciones !== '' ? $observaciones : null, intval($_SESSION['id'])]);
        $id = (int)$bdd->lastInsertId();
        $ids[] = $id;
        bsa_registrar_historial($bdd, $id, 'creado',
            'Backorder registrado: ' . $cantidad . ' de "' . $titulos[$idLibro] . '" del pedido #' . $pedido['id_pedido2']
            . ' (' . $pedido['nombre'] . ').');
    }
    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    bsa_volver_guardar(false, 'Error al guardar el backorder: ' . $e->getMessage());
}

bsa_volver_guardar(true, count($ids) === 1
    ? 'Backorder #' . $ids[0] . ' registrado.'
    : count($ids) . ' backorders registrados (#' . implode(', #', $ids) . ').');
