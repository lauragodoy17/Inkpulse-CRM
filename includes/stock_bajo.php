<?php
/**
 * /includes/stock_bajo.php
 * Detecta, para uno o varios pedidos (con o sin adopción), qué libros tienen
 * existencia real por debajo del umbral en la bodega General de World Office.
 * Usado tanto por ajax/stock_bajo_pedidos.php (alertas en las listas/detalle)
 * como por php/pedido.php y php/pedido_sa.php (aviso por correo al crear un
 * pedido con stock bajo).
 */
require_once("api_wo_inventario.php");

if (!defined('UMBRAL_STOCK_BAJO')) define('UMBRAL_STOCK_BAJO', 50);

/**
 * @param PDO   $bdd
 * @param array $idsPedido ids de pedidos.id (origen='pedidos') o pedidos2.id (origen='pedidos2')
 * @param string $origen 'pedidos' | 'pedidos2'
 * @return array [id_pedido => [['id_libro'=>, 'libro'=>, 'existencia'=>], ...]] — solo pedidos con al menos un libro bajo
 */
function libros_bajo_stock_pedidos(PDO $bdd, array $idsPedido, $origen = 'pedidos') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsPedido))));
    $resultado = [];
    if (!$ids) return $resultado;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($origen === 'pedidos2') {
        $sql = "SELECT DISTINCT pe.id AS id_pedido, l.id AS id_libro, l.id_wo, l.libro
                FROM pedidos2 pe
                JOIN libros_pedidos2 lp ON lp.cod_pedido = pe.codigo
                JOIN libros l           ON l.id = lp.id_libro
                WHERE pe.id IN ($placeholders) AND lp.cantidad != 0 AND l.id_wo IS NOT NULL";
    } else {
        $sql = "SELECT DISTINCT pe.id AS id_pedido, l.id AS id_libro, l.id_wo, l.libro
                FROM pedidos pe
                JOIN libros_pedidos lp ON lp.cod_pedido = pe.codigo
                JOIN libros l           ON l.id = lp.id_libro
                JOIN presupuestos p     ON p.id_colegio = pe.id_colegio
                  AND p.id_libro = lp.id_libro
                  AND COALESCE(lp.cod_area,'') = COALESCE(p.cod_area,'')
                  AND pe.id_periodo = p.id_periodo
                WHERE pe.id IN ($placeholders) AND p.definido = 1 AND lp.cantidad != 0 AND l.id_wo IS NOT NULL";
    }

    $req = $bdd->prepare($sql);
    $req->execute($ids);
    $filas = $req->fetchAll();

    $idsWo = array_column($filas, 'id_wo');
    $existencias = existencias_bodega_general_bulk($idsWo);

    foreach ($filas as $f) {
        $existencia = $existencias[$f['id_wo']] ?? null;
        if ($existencia !== null && $existencia < UMBRAL_STOCK_BAJO) {
            $resultado[$f['id_pedido']][] = [
                'id_libro'   => (int)$f['id_libro'],
                'libro'      => $f['libro'],
                'existencia' => $existencia,
            ];
        }
    }

    return $resultado;
}
