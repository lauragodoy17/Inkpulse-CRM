<?php
/**
 * /php/solicitudes_vd_excel.php?periodo=N&colegio=M&orden=proxima|antiguo|reciente
 * Excel de Solicitudes de venta directa (2026-11-26, la usuaria): el mismo comparativo en unidades de la página
 * (solicitado, remisionado en World Office, devuelto y vendido) por pedido, con los mismos filtros (colegio y orden,
 * svd_filtrar_ordenar). Subtotal por colegio y total general. Respeta el alcance por zona del usuario (svd_listar).
 */
require_once("aut.php");
require_once("../conexion/bdd.php");
require_once("../includes/solicitudes_vd_datos.php");

dm_validar_acceso(false, "../");
// Un aviso impreso en la salida corrompe el .xlsx; World Office puede tardar con muchos colegios.
ini_set('display_errors', 0);
set_time_limit(0);
session_write_close();

$periodos = svd_periodos($bdd);
$periodo = (int)($_GET['periodo'] ?? 0);
$nombre_periodo = null;
foreach ($periodos as $p) if ((int)$p['id'] === $periodo) $nombre_periodo = $p['periodo'];
if ($nombre_periodo === null) {
    header('Location: ../solicitudes_venta_directa.php?ink_status=error&ink_msg=' . urlencode('El período no existe o no está en tu alcance.'));
    exit;
}
$orden = isset(SVD_ORDENES[$_GET['orden'] ?? '']) ? $_GET['orden'] : 'proxima';
$colegios = svd_filtrar_ordenar(svd_listar($bdd, $periodo), (int)($_GET['colegio'] ?? 0), $orden);

include("../lib/autoload-phpspreadsheet.php");
require_once("../lib/ZipStream/src/Option/Archive.php");
require_once("../lib/MyCLabs/Enum/Enum.php");
require_once("../lib/ZipStream/src/Option/Method.php");
require_once("../lib/ZipStream/src/ZipStream.php");
require_once("../lib/ZipStream/src/Bigint.php");
require_once("../lib/ZipStream/src/Option/File.php");
require_once("../lib/ZipStream/src/File.php");
require_once("../lib/ZipStream/src/Option/Version.php");
require_once("../includes/excel_formato.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$filtros = ['Período ' . $nombre_periodo, 'Orden: ' . SVD_ORDENES[$orden]];
if ((int)($_GET['colegio'] ?? 0) && $colegios) $filtros[] = 'Colegio: ' . $colegios[0]['colegio'];

$libro = new Spreadsheet();
$libro->getProperties()->setCreator('Inkpulse CRM')->setTitle('Solicitudes de venta directa');
$h = $libro->getActiveSheet();
$h->setTitle('Solicitudes');
excel_encabezado($h, $bdd, 'Solicitudes de venta directa', implode('   ·   ', $filtros));
$cols = ['Colegio', 'Ciudad', 'Pedido', 'Fecha del pedido', 'Estado', 'Solicitante', 'Formato', 'Solicitado', 'Remisionado', 'Devuelto', 'Vendido', 'Observación'];
foreach ($cols as $i => $c) $h->setCellValueByColumnAndRow($i + 1, EXCEL_FILA_ENCABEZADOS, $c);
$rangoEnc = 'A' . EXCEL_FILA_ENCABEZADOS . ':L' . EXCEL_FILA_ENCABEZADOS;
excel_estilo_encabezados($h, $rangoEnc);
$h->getStyle($rangoEnc)->getFont()->setBold(true);

$K = ['solicitado' => 'H', 'remisionado' => 'I', 'devuelto' => 'J', 'vendido' => 'K'];
$total = array_fill_keys(array_keys($K), 0);
$r = EXCEL_FILA_ENCABEZADOS + 1;
foreach ($colegios as $col) {
    $u = svd_resumen_unidades($bdd, $col);
    foreach ($col['pedidos'] as $p) {
        $x = $u['pedidos'][$p['id']] ?? null;
        $h->setCellValue("A$r", $col['colegio']);
        $h->setCellValue("B$r", (string)$col['ciudad']);
        $h->setCellValue("C$r", '#' . $p['id']);
        $h->setCellValue("D$r", $p['fecha'] ? date('d/m/Y', strtotime($p['fecha'])) : '');
        $h->setCellValue("E$r", $p['estado_txt'] ?: 'Estado ' . $p['estado']);
        $h->setCellValue("F$r", $p['solicitante']);
        $h->setCellValue("G$r", $p['formato'] ? fvd_numero($p['formato']['consecutivo']) : '');
        $obs = [];
        if (!$u['ok']) {
            $obs[] = 'No se pudo consultar: ' . $u['error'];
        } elseif ($x) {
            foreach ($K as $k => $c) if ($x[$k] !== null) $h->setCellValue("$c$r", (int)$x[$k]);
            if ($x['remisionado'] === null) $obs[] = 'El pedido aún no está entregado';
            elseif ($x['sin_remision']) $obs[] = 'Sin remisión en WO';
        }
        $h->setCellValue("L$r", implode('; ', $obs));
        $r++;
    }
    if ($u['ok']) foreach ($K as $k => $_) $total[$k] += (int)$u['total'][$k];
    if (count($col['pedidos']) > 1 && $u['ok']) {
        $h->setCellValue("A$r", 'Subtotal ' . $col['colegio']);
        foreach ($K as $k => $c) $h->setCellValue("$c$r", (int)$u['total'][$k]);
        $h->getStyle("A$r:L$r")->getFont()->setBold(true);
        $h->getStyle("A$r:L$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $r++;
    }
}
$h->setCellValue("A$r", 'Total general');
foreach ($K as $k => $c) $h->setCellValue("$c$r", $total[$k]);
$h->getStyle("A$r:L$r")->getFont()->setBold(true);
$h->getStyle("A$r:L$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
$r++;
$h->setCellValue("A" . ($r + 1), 'Unidades de libros (un paquete cuenta cada libro). Remisionado: lo despachado en World Office (remisiones CEUR y facturas FVE); solo en pedidos entregados. Vendido = remisionado − devuelto.');
$h->getStyle("A" . ($r + 1))->getFont()->setItalic(true)->getColor()->setRGB('64748B');

$h->getColumnDimension('A')->setWidth(40);
foreach (range('B', 'K') as $c) $h->getColumnDimension($c)->setAutoSize(true);
$h->getColumnDimension('L')->setWidth(45);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="solicitudes_venta_directa_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($libro))->save('php://output');
