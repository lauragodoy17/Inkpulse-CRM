<?php
/**
 * /php/pedido_precio_padre.php
 * Pedidos de venta directa (2026-11-10): editar el "Precio venta padre" de un título desde pedido_colegio.php mientras
 * el pedido está Pendiente o Aprobado. El precio se guarda en la adopción del colegio (presupuestos.precio_venta_final
 * del colegio, período y título), así que aplica a todos los pedidos de ese colegio, a los que ya existen y a los
 * que se monten después, y no toca otros colegios. Queda en el historial del colegio (módulo "Venta directa").
 *
 * 2026-11-11: solo una modificación por título desde el pedido (presupuestos.pp_editado_pedido); después queda fijo aquí
 * (se puede corregir desde Adopciones).
 * POST lpid (libros_pedidos.id), precio (entero > 0). Responde JSON {ok, precio?, id_presup?, error?}.
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("registrar_historial.php");
require_once("../includes/sobrecosto_canal.php");

header('Content-Type: application/json; charset=utf-8');
sobrecosto_asegurar_columna($bdd);

function ppp_salir(array $r) { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ppp_salir(['ok' => false, 'error' => 'Solicitud no válida.']);

$tipo = $_SESSION['tipo'] ?? null;
$id_usuario = (int)($_SESSION['id'] ?? 0);
// Los mismos usuarios que pueden aprobar o modificar el pedido (pedido_colegio.php, $can_act).
if (!($tipo == 1 || $tipo == 2 || $id_usuario == 21 || $tipo == 10)) ppp_salir(['ok' => false, 'error' => 'No tienes permiso para cambiar el precio.']);

$lpid = (int)($_POST['lpid'] ?? 0);
$precio_txt = preg_replace('/[^\d]/', '', (string)($_POST['precio'] ?? ''));
$precio = $precio_txt === '' ? 0 : (int)$precio_txt;
if ($precio <= 0) ppp_salir(['ok' => false, 'error' => 'Escribe un precio venta padre mayor a cero.']);

$st = $bdd->prepare(
  "SELECT pe.id AS id_pedido, pe.estado, pe.para_andrea, pe.id_colegio, pe.id_periodo,
          p.id AS id_presup, p.precio_venta_final, p.pp_editado_pedido, l.libro
   FROM libros_pedidos lp
   JOIN pedidos pe      ON pe.codigo = lp.cod_pedido
   JOIN libros l        ON l.id = lp.id_libro
   JOIN presupuestos p  ON p.id_colegio = pe.id_colegio AND p.id_libro = lp.id_libro
                       AND COALESCE(lp.cod_area,'') = COALESCE(p.cod_area,'') AND p.id_periodo = pe.id_periodo
   WHERE lp.id = ? AND p.definido = 1
   LIMIT 1");
$st->execute([$lpid]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) ppp_salir(['ok' => false, 'error' => 'No se encontró el título en la adopción del colegio.']);
if (empty($f['para_andrea']) && !adopcion_es_venta_directa($bdd, $f['id_colegio'], $f['id_periodo'])) ppp_salir(['ok' => false, 'error' => 'Este pedido no es de venta directa.']);
if (!in_array((int)$f['estado'], [1, 2], true)) ppp_salir(['ok' => false, 'error' => 'Solo se puede cambiar el precio con el pedido Pendiente o Aprobado.']);

$bloqueado = 'El precio venta padre de ' . $f['libro'] . ' ya se modificó una vez desde un pedido y no se puede volver a cambiar aquí.';
if (!empty($f['pp_editado_pedido'])) ppp_salir(['ok' => false, 'error' => $bloqueado]);

$antes = (float)$f['precio_venta_final'];
if (abs($antes - $precio) > 0.0001) {
  $bdd->beginTransaction();
  $up = $bdd->prepare("UPDATE presupuestos SET precio_venta_final = ?, pp_editado_pedido = NOW() WHERE id = ? AND pp_editado_pedido IS NULL");
  $up->execute([$precio, (int)$f['id_presup']]);
  if ($up->rowCount() === 0) { $bdd->rollBack(); ppp_salir(['ok' => false, 'error' => $bloqueado]); } // otra pestaña se adelantó
  registrar_historial($bdd, (int)$f['id_colegio'], $id_usuario, 'Venta directa',
    'Precio venta padre - ' . $f['libro'] . ' (desde el pedido #' . (int)$f['id_pedido'] . ')',
    $antes > 0 ? (string)(int)$antes : '', (string)$precio);
  $bdd->commit();
}
ppp_salir(['ok' => true, 'precio' => $precio, 'id_presup' => (int)$f['id_presup']]);
