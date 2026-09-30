<?php
/**
 * Tipos de documento permitidos al solicitar una OP, según de dónde viene (pedido de la usuaria
 * 2026-09-30). Se identifican por el código `tipo_doc.tipo` (sin espacios de más: "FVE " está
 * guardado con uno al final).
 *   pedidos (uno o agrupados)  FVE o REM RCEUR — hay que elegir uno, sin valor por defecto
 *   muestreo                   REM22 (fijo)
 *   devolución de muestras     DEV REM 22 (fijo)
 *   devolución de proveedores  DEV PROV (fijo)
 *   devolución de venta        DEV RCEUR o NC — hay que elegir uno
 *   pedido sin adopción        según pedidos2.tipo: Venta (1) igual que pedidos; Muestras (2) REM22 fijo.
 *                              Los antiguos sin tipo (0) siguen con la lista completa.
 * Cualquier otro origen (OP manual) sigue con la lista completa.
 */

function tipos_doc_op_codigos(PDO $bdd, array $origen) {
    if (!empty($origen['pedidos_agp']) || !empty($origen['id_pedido'])) return ['FVE', 'REM RCEUR'];
    if (!empty($origen['id_pedido_dist'])) {
        $stmt = $bdd->prepare("SELECT tipo FROM pedidos2 WHERE id = ?");
        $stmt->execute([intval($origen['id_pedido_dist'])]);
        $tipo = (int)$stmt->fetchColumn();
        if ($tipo === 1) return ['FVE', 'REM RCEUR'];
        if ($tipo === 2) return ['REM22'];
        return null;
    }
    if (!empty($origen['id_muestreo'])) return ['REM22'];
    if (!empty($origen['id_devol_c']))  return ['DEV REM 22'];
    if (!empty($origen['id_devol_p']))  return ['DEV PROV'];
    if (!empty($origen['id_devol_v']))  return ['DEV RCEUR', 'NC'];
    return null; // sin restricción
}

/** Filas de tipo_doc (activas) que se pueden elegir para ese origen. */
function tipos_doc_op_opciones(PDO $bdd, array $origen) {
    $codigos = tipos_doc_op_codigos($bdd, $origen);
    if ($codigos === null) {
        return $bdd->query("SELECT * FROM tipo_doc WHERE act=1")->fetchAll();
    }
    $ph = implode(',', array_fill(0, count($codigos), '?'));
    $stmt = $bdd->prepare("SELECT * FROM tipo_doc WHERE act=1 AND TRIM(tipo) IN ($ph) ORDER BY FIELD(TRIM(tipo), $ph)");
    $stmt->execute(array_merge($codigos, $codigos));
    return $stmt->fetchAll();
}

/** true si $idTipoDoc está permitido para ese origen. */
function tipo_doc_op_permitido(PDO $bdd, array $origen, $idTipoDoc) {
    foreach (tipos_doc_op_opciones($bdd, $origen) as $td) {
        if ((int)$td['id'] === (int)$idTipoDoc) return true;
    }
    return false;
}
