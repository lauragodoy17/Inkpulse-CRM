<?php
/**
 * /includes/wo_clientes_sync.php
 * Lógica compartida entre php/comparar_terceros.php y php/cruzar_terceros.php
 * para saber, en cualquier momento, hasta qué id de World Office ya no hay
 * pendientes por cruzar contra la tabla local `clientes`.
 */

require_once("api_wo_terceros.php");

// Punto de corte histórico absoluto: antes de este id, el negocio ya dio todo
// por revisado/migrado y no interesa volver a mirar, sin importar huecos.
const ID_MINIMO_WO = 11767;

/**
 * Calcula el mayor id de WO tal que todo tercero con ID_MINIMO_WO < id <= piso
 * ya existe en `clientes` (comparando por documento). Pagina en orden ASC
 * desde ID_MINIMO_WO usando el filtro "mayor que" de WO (barato: solo trae lo
 * que está por encima del corte) y se detiene en el primer id pendiente
 * (hueco), sin necesidad de pedir el detalle completo de cada uno (no llama
 * consultar_tercero, solo usa los campos que ya trae el listado).
 *
 * El piso NO es MAX(id_wo): si cruzas un id "de en medio" (ej. 11770) dejando
 * uno menor sin cruzar (ej. 11768), esta función no avanza más allá de 11767
 * hasta que 11768 también se cruce — así nunca se pierde un pendiente.
 */
function calcular_piso_efectivo($bdd, $base = ID_MINIMO_WO) {
    $porPagina = 100;
    $pagina = 0;
    $piso = $base;

    while (true) {
        $filtro = crear_filtro_api('id', $base, 2, 3); // id > base (fijo), siempre el mismo corte absoluto
        $resultado = listar_terceros([$filtro], $pagina, $porPagina, 'ASC');
        $status = $resultado['status'] ?? 'error';
        if (!in_array($status, ['OK', 'ACCEPTED'], true)) {
            break; // ante error de la API, no se avanza el piso (se queda en el último confirmado)
        }

        $pag = $resultado['data'] ?? [];
        $filas = $pag['content'] ?? [];
        if (!$filas) break;

        $identificaciones = array_values(array_filter(array_map(function ($f) {
            return trim((string)($f['identificacion'] ?? ''));
        }, $filas), function ($v) { return $v !== ''; }));

        $existentesLocal = [];
        if ($identificaciones) {
            $marcadores = implode(',', array_fill(0, count($identificaciones), '?'));
            $stmt = $bdd->prepare("SELECT TRIM(documento) as documento FROM clientes WHERE TRIM(documento) IN ($marcadores)");
            $stmt->execute($identificaciones);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $doc) {
                $existentesLocal[$doc] = true;
            }
        }

        foreach ($filas as $f) {
            $identificacion = trim((string)($f['identificacion'] ?? ''));
            $existe = $identificacion !== '' && isset($existentesLocal[$identificacion]);
            if (!$existe) {
                return $piso; // primer pendiente encontrado: el piso no avanza más allá de acá
            }
            $piso = (int)($f['id'] ?? $piso);
        }

        $totalPaginas = $pag['totalPages'] ?? null;
        $pagina++;
        if ($totalPaginas === null || $pagina >= $totalPaginas) break;
    }

    return $piso;
}
