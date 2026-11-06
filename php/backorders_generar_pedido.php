<?php
/**
 * /php/backorders_generar_pedido.php
 * "Atender backorder": genera un pedido NUEVO con solo lo que sigue pendiente de un pedido
 * original (uno o varios libros, hasta el máximo que de verdad falta por surtir), y lo asocia
 * a la MISMA OP del pedido original (vía op_pedidos_agrupados) — no se crea una OP nueva.
 *
 * El pedido original NO se modifica (conserva la cantidad que se pidió originalmente). Cada
 * libro generado queda registrado en backorder_pedidos_generados (ver
 * includes/backorders_datos.php) y esa cantidad se descuenta del saldo pendiente del original al
 * calcularlo; varios pedidos nuevos para el mismo backorder se van descontando del saldo que
 * quede, nunca por encima de lo que de verdad falta. Si un pedido nuevo se anula, su cantidad
 * vuelve a quedar pendiente en el original.
 *
 * Mismo patrón de creación que php/pedido.php (código aleatorio de 10 dígitos, mismas columnas
 * de `pedidos`/`libros_pedidos`), pero con sentencias preparadas (ese archivo usa interpolación
 * directa) y con el tope de cantidad SÍ validado en el servidor (ese archivo no lo hace).
 */
require_once("aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header('Location: ../index.php');
    exit;
}

require_once("../conexion/bdd.php");
require_once("../includes/backorders_datos.php");

// Del formulario solo se toman el pedido original y las cantidades: todo lo demás (fecha de
// recogida, dirección, observaciones, descuentos...) se copia del pedido original y no se puede
// cambiar — pedido por la usuaria 2026-10-20.
$idPedidoOrigen = intval($_POST['id_pedido_origen'] ?? 0);
$cantidades = $_POST['cantidad'] ?? []; // [id_libro => cantidad]

class BackorderValidacionException extends Exception {}

function volver_con_error($idPedidoOrigen, $msg) {
    header('Location: ../reporte_backorders_pedido.php?id_pedido=' . $idPedidoOrigen . '&ink_status=error&ink_msg=' . urlencode($msg));
    exit;
}

if ($idPedidoOrigen <= 0) {
    volver_con_error($idPedidoOrigen, 'No se indicó el pedido original.');
}

$detalle = obtener_backorder_pedido($bdd, $idPedidoOrigen);
if ($detalle === null) {
    volver_con_error($idPedidoOrigen, 'No se encontró el pedido original.');
}
if ($detalle['generado_desde']) {
    volver_con_error($idPedidoOrigen, 'Este pedido fue generado para atender el backorder del pedido #' . $detalle['generado_desde'] . ' — los pedidos nuevos se generan siempre desde el pedido original.');
}
if (!$detalle['periodo_permitido']) {
    volver_con_error($idPedidoOrigen, 'Solo se pueden atender backorders de pedidos de los periodos ' . implode(', ', BACKORDERS_PERIODOS_PEDIDO) . '.');
}
if ($detalle['esPreMigracion']) {
    volver_con_error($idPedidoOrigen, 'Este pedido es de antes de la migración a World Office — no hay traza confiable de lo pendiente, no se puede generar un pedido nuevo desde aquí.');
}

// La OP del pedido nuevo debe ser la MISMA del original — se busca directa o agrupada, cualquier
// estado salvo Anulada (mismo criterio que "# OP" del resto del reporte de backorders).
$stmtOpDirecta = $bdd->prepare("SELECT id FROM ordenes_pedidos WHERE id_pedido = ? AND estado != 4 ORDER BY id DESC LIMIT 1");
$stmtOpDirecta->execute([$idPedidoOrigen]);
$idOp = $stmtOpDirecta->fetchColumn();
if (!$idOp) {
    $stmtOpAgrupada = $bdd->prepare(
        "SELECT o.id FROM op_pedidos_agrupados a JOIN ordenes_pedidos o ON o.id = a.op
         WHERE a.id_pedido = ? AND o.estado != 4 ORDER BY o.id DESC LIMIT 1"
    );
    $stmtOpAgrupada->execute([$idPedidoOrigen]);
    $idOp = $stmtOpAgrupada->fetchColumn();
}
if (!$idOp) {
    volver_con_error($idPedidoOrigen, 'Este pedido no tiene una OP activa asociada — no se puede generar un pedido de backorder ligado a ella.');
}
$idOp = (int)$idOp;

// Cantidades pedidas (se validan contra lo pendiente dentro de la transacción, más abajo).
$aMover = []; // id_libro => cantidad
foreach ($cantidades as $idLibro => $cant) {
    $idLibro = (int)$idLibro;
    $cant = (float)$cant;
    if ($cant <= 0) continue;
    $aMover[$idLibro] = $cant;
}
if (empty($aMover)) {
    volver_con_error($idPedidoOrigen, 'Indica al menos una cantidad para generar el pedido.');
}

$origen = $detalle['origen'];

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $bdd->beginTransaction();

    // Se bloquea el pedido original mientras dura la transacción y se recalcula lo pendiente YA
    // con el bloqueo tomado: dos envíos simultáneos (doble clic, dos pestañas) quedan en fila y el
    // segundo ve el saldo ya descontado por el primero — así no se generan pedidos por encima de
    // lo que falta.
    $bdd->prepare("SELECT id FROM pedidos WHERE id = ? FOR UPDATE")->execute([$idPedidoOrigen]);
    $detalle = obtener_backorder_pedido($bdd, $idPedidoOrigen);
    $itemPorLibro = [];
    foreach ($detalle['items'] as $it) $itemPorLibro[$it['id_libro']] = $it;
    // Tope = pendiente de GESTIÓN: lo que falta por despachar y todavía no está en ningún pedido
    // nuevo (desde 2026-10-20 lo generado ya no rebaja el pendiente de despacho, así que sin este
    // tope se podría volver a pedir lo mismo).
    // Desde 2026-10-20 el detalle trae los libros de TODO el colegio (y su pendiente es el del
    // colegio): solo se pueden pedir los que están en este pedido, porque el nuevo copia sus líneas.
    foreach ($aMover as $idLibro => $cant) {
        if (empty($itemPorLibro[$idLibro]['en_pedido'])) {
            throw new BackorderValidacionException('Uno de los libros indicados no está en este pedido. Recarga la página e intenta de nuevo.');
        }
        $pendiente = $itemPorLibro[$idLibro]['pendiente_gestion'] ?? 0;
        if ($cant > $pendiente + 0.001) {
            throw new BackorderValidacionException('La cantidad indicada para un libro supera lo que falta por gestionar (' . $pendiente . '). Recarga la página e intenta de nuevo.');
        }
    }

    // Código aleatorio de 10 dígitos, único — mismo esquema que usa php/pedido.php (el flujo
    // normal de "Solicitar pedido"), para que este pedido no se distinga de uno normal.
    do {
        $codigoNuevo = (string)random_int(1000000000, 9999999999);
        $stmtDup = $bdd->prepare("SELECT COUNT(*) FROM pedidos WHERE codigo = ?");
        $stmtDup->execute([$codigoNuevo]);
    } while ($stmtDup->fetchColumn() > 0);

    // Mismas columnas que php/pedido.php al crear un pedido — TODO se hereda tal cual del pedido
    // original (colegio, período, usuario, cliente, tipo, factura/remisión, fecha de recogida,
    // dirección y observaciones): el pedido nuevo es el mismo pedido con otra cantidad.
    $stmtPedido = $bdd->prepare(
        "INSERT INTO pedidos (codigo, id_periodo, id_colegio, id_usuario, fecha_r, dir_ent, observaciones, cliente, fac_rem, tipo, estado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmtPedido->execute([
        $codigoNuevo,
        $origen['id_periodo'],
        $origen['id_colegio'],
        $origen['id_usuario'],
        $origen['fecha_r'],
        $origen['dir_ent'],
        $origen['observaciones'],
        $origen['id_cliente'],
        $origen['fac_rem'],
        $origen['tipo'],
    ]);
    $idPedidoNuevo = (int)$bdd->lastInsertId();

    foreach ($aMover as $idLibro => $cant) {
        // Todo lo de la línea se copia de la original (área, presupuesto, plataforma y descuento
        // aprobado); solo cambia la cantidad. El descuento del presupuesto sale igual porque el
        // pedido nuevo tiene el mismo colegio, período, libro y área.
        $stmtLineaOrig = $bdd->prepare(
            "SELECT id, cod_area, id_presup, plataforma, descuento_aprob, cantidad FROM libros_pedidos WHERE cod_pedido = ? AND id_libro = ? LIMIT 1"
        );
        $stmtLineaOrig->execute([$origen['codigo'], $idLibro]);
        $lineaOrig = $stmtLineaOrig->fetch();
        if (!$lineaOrig) continue; // no debería pasar (ya validamos contra items del propio pedido)

        $stmtInsLinea = $bdd->prepare(
            "INSERT INTO libros_pedidos (cod_pedido, id_libro, cod_area, id_presup, cantidad, plataforma, descuento_aprob) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtInsLinea->execute([$codigoNuevo, $idLibro, $lineaOrig['cod_area'], $lineaOrig['id_presup'], $cant, $lineaOrig['plataforma'], $lineaOrig['descuento_aprob']]);

        // El pedido original NO se toca: la relación queda registrada (con lo solicitado,
        // despachado y pendiente de ese momento) y se descuenta del saldo al calcularlo.
        $it = $itemPorLibro[$idLibro];
        $stmtTraza = $bdd->prepare(
            "INSERT INTO backorder_pedidos_generados
                (id_pedido_origen, id_pedido_nuevo, id_op, id_libro, cantidad, solicitada_origen,
                 despachada_al_generar, pendiente_antes, id_usuario, fecha, origen_registro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'app')"
        );
        $stmtTraza->execute([
            $idPedidoOrigen, $idPedidoNuevo, $idOp, $idLibro, $cant,
            $it['solicitada'], $it['despachada'], $it['pendiente'], intval($_SESSION['id'] ?? 0) ?: null,
        ]);
    }

    // Mismo patrón que php/crear_op.php para agrupar varios pedidos bajo una OP — tipo=1.
    $stmtAgrupar = $bdd->prepare("INSERT INTO op_pedidos_agrupados (tipo, op, id_pedido) VALUES (1, ?, ?)");
    $stmtAgrupar->execute([$idOp, $idPedidoNuevo]);

    $bdd->commit();
} catch (BackorderValidacionException $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    volver_con_error($idPedidoOrigen, $e->getMessage());
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    volver_con_error($idPedidoOrigen, 'Error al generar el pedido: ' . $e->getMessage());
}

$msg = 'Pedido #' . $idPedidoNuevo . ' generado con lo pendiente, ligado a la OP #' . $idOp . '.';
header('Location: ../reporte_backorders_pedido.php?id_pedido=' . $idPedidoOrigen . '&ink_status=ok&ink_msg=' . urlencode($msg));
