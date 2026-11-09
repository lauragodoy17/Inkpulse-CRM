<?php
/**
 * Guarda de inmediato el documento (factura/remisión) elegido en el
 * buscador de World Office de op_pendiente.php, sin esperar a que se envíe
 * todo el formulario de despacho — así el botón "Guardar" de cada
 * candidato deja constancia en el historial apenas se hace clic, y el
 * documento se puede seguir reemplazando por otro las veces que haga falta
 * (ver includes/historial_documento_op.php).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/historial_documento_op.php");
require_once("../includes/documentos_wo_usados.php");
require_once("../includes/excedentes_despacho.php");

header('Content-Type: application/json; charset=utf-8');

$op_id    = intval($_POST['op'] ?? 0);
$tipo_doc = trim($_POST['tipo_doc'] ?? '');
$cliente  = trim($_POST['cliente'] ?? '');
$n_doc    = trim($_POST['n_doc'] ?? '');
$valor    = trim($_POST['valor'] ?? '');
$id_wo    = trim($_POST['id_wo'] ?? '');

if ($op_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'OP inválida.']);
    exit;
}
if ($n_doc === '' || $valor === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta el número de documento o el valor.']);
    exit;
}

$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

crear_tabla_historial_documento_op($bdd);
crear_tabla_documentos_wo_usados($bdd);

// Un documento de World Office solo puede usarse para UN despacho en total
// (ver includes/documentos_wo_usados.php). Este endpoint solo asocia el
// documento (no registra apartados todavía), así que aquí se avisa
// temprano si ya está consumido, pero quien realmente lo marca como usado
// es php/procesar_op.php cuando se registra el primer apartado real.
$otro_despacho = documento_wo_ya_usado($bdd, $id_wo);
if ($otro_despacho) {
    echo json_encode(['ok' => false, 'error' => 'El documento ' . $n_doc . ' ya se usó en el despacho de la OP ' . $otro_despacho['año'] . '-' . $otro_despacho['opid'] . '. Elige otro documento.']);
    exit;
}

// Excedente: si el documento envía más de lo solicitado en los pedidos de la OP, se pide
// confirmación (op_pendiente.php muestra la alerta y reenvía con confirmar_excedente=1).
$excedente = excedentes_calcular($bdd, $op_id, intval($id_wo));
if (excedentes_requiere_confirmacion($excedente, !empty($_POST['confirmar_excedente']))) {
    echo json_encode(['ok' => false, 'requiere_confirmacion' => true, 'excedente' => $excedente,
                      'error' => excedentes_mensaje($excedente)], JSON_UNESCAPED_UNICODE);
    exit;
}
excedentes_asegurar_tabla($bdd); // DDL: antes de abrir la transacción

try {
    $bdd->beginTransaction();

    $r_prev = $bdd->prepare("SELECT tipo_doc, n_doc, valor FROM ordenes_pedidos WHERE id = ?");
    $r_prev->execute([$op_id]);
    $prev = $r_prev->fetch();

    if (!$prev) {
        $bdd->rollBack();
        echo json_encode(['ok' => false, 'error' => 'No se encontró la OP.']);
        exit;
    }

    $upd = $bdd->prepare("UPDATE ordenes_pedidos SET tipo_doc = ?, n_doc = ?, cliente = ?, valor = ? WHERE id = ?");
    $upd->execute([
        $tipo_doc !== '' ? $tipo_doc : null,
        $n_doc,
        $cliente !== '' ? $cliente : null,
        $valor,
        $op_id,
    ]);

    $cambio = documento_op_cambio($prev, $tipo_doc, $n_doc, $valor);

    $entry = null;
    if ($cambio) {
        registrar_historial_documento_op($bdd, $op_id, $tipo_doc, $n_doc, $id_wo, $valor, $_SESSION['id']);

        $r_u = $bdd->prepare("SELECT CONCAT(TRIM(nombres),' ',TRIM(apellidos)) AS nombre FROM usuarios WHERE id = ?");
        $r_u->execute([$_SESSION['id']]);
        $usuario = $r_u->fetchColumn() ?: '—';

        $tipo_label = null;
        if ($tipo_doc !== '') {
            $r_t = $bdd->prepare("SELECT tipo FROM tipo_doc WHERE id = ?");
            $r_t->execute([$tipo_doc]);
            $tipo_label = $r_t->fetchColumn() ?: null;
        }

        $entry = [
            'id'         => $bdd->lastInsertId(),
            'fecha'      => date('d/m/Y H:i'),
            'usuario'    => $usuario,
            'tipo'       => $tipo_label,
            'tipo_doc'   => $tipo_doc !== '' ? intval($tipo_doc) : 0,
            'n_doc'      => $n_doc,
            'id_wo'      => $id_wo !== '' ? intval($id_wo) : 0,
            'valor'      => $valor,
            'valor_fmt'  => '$' . number_format((float)$valor, 0, ',', '.'),
        ];
    }

    excedentes_registrar($bdd, $op_id, $id_wo, $n_doc, $excedente, 'guardar_documento', $_SESSION['id']);

    $bdd->commit();
} catch (Exception $e) {
    if ($bdd->inTransaction()) $bdd->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Error al guardar el documento: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'changed' => $cambio, 'entry' => $entry]);
