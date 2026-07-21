<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("../lib/autoload-phpspreadsheet.php");
require_once("../lib/ZipStream/src/Option/Archive.php");
require_once("../lib/MyCLabs/Enum/Enum.php");
require_once("../lib/ZipStream/src/Option/Method.php");
require_once("../lib/ZipStream/src/ZipStream.php");
require_once("../lib/ZipStream/src/Bigint.php");
require_once("../lib/ZipStream/src/Option/File.php");
require_once("../lib/ZipStream/src/File.php");
require_once("../lib/ZipStream/src/Option/Version.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

require_once("aut.php");
include("../conexion/bdd.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 3, 6, 10], true)) {
    header("Location: ../index.php");
    exit;
}

$periodo = intval($_GET['periodo'] ?? 0);
if ($periodo <= 0) {
    $periodo = intval($bdd->query("SELECT id FROM periodos ORDER BY id DESC LIMIT 1")->fetchColumn());
}

// Mismo criterio de "dueño de zona" que php/dashboard_adopciones_stats.php: el
// encargado de un colegio es quien tiene su cod_zona (no quién cargó la línea).
$ownerJoin = "LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)";

$rolCondiciones = ['promotor' => '(owner.tipo = 3 OR owner.id = 69)', 'distribuidor' => 'owner.tipo = 6', 'otros' => 'p.id_usuario IN (SELECT id FROM usuarios WHERE tipo IN (1,4,10) AND id <> 69)'];
if ($tipo_sesion === 1) {
    $rol = $_GET['rol'] ?? '';
    $usuario = intval($_GET['usuario'] ?? 0);
} else {
    // Promotores y distribuidores solo ven su propia información, sin importar el filtro enviado.
    $rol = '';
    $usuario = intval($_SESSION['id'] ?? 0);
}
if ($usuario > 0) {
    $tipo_usuario_filtro = intval($bdd->query("SELECT tipo FROM usuarios WHERE id = $usuario")->fetchColumn());
    if (in_array($tipo_usuario_filtro, [3, 6], true) || $usuario === 69) {
        $userFilter = " AND owner.id = $usuario";
    } else {
        $userFilter = " AND p.id_usuario = $usuario";
    }
} elseif ($rol === 'otros') {
    $userFilter = " AND " . $rolCondiciones['otros'];
} elseif (isset($rolCondiciones[$rol])) {
    $userFilter = " AND " . $rolCondiciones[$rol];
} else {
    $userFilter = "";
}

$sql_periodo = $bdd->prepare("SELECT periodo, id_calendario FROM periodos WHERE id = ?");
$sql_periodo->execute([$periodo]);
$row_periodo = $sql_periodo->fetch(PDO::FETCH_ASSOC);
$nombre_periodo = $row_periodo['periodo'] ?? '';
$calendario_periodo = intval($row_periodo['id_calendario'] ?? 0);

// Mismo descuento promedio que aplica la gráfica del dashboard (AVG por colegio): el del
// distribuidor (_d) cuando tiene tasa_compra_d asignada, si no el descuento base del
// presupuesto. p.conse se asigna una sola vez por colegio+período (todas sus líneas de
// libros comparten el mismo consecutivo), así que MAX(p.conse) es seguro al agrupar.
$descuentoExpr = "(CASE WHEN p.tasa_compra_d = 0 THEN p.descuento ELSE p.descuento_d END * 100)";

// Cuando el colegio no tiene un dueño de zona activo (encargado = "Sin asesor asignado"),
// se usan directamente las columnas cod_zona y responsable que trae cada línea de
// presupuestos para ese colegio+período (cargadas en areas_cumplimiento.php/areas_objetivas.php
// al definir el presupuesto), en vez de dejar la zona y el responsable en blanco.
$sql = "SELECT COALESCE(z.zona, z_pf.zona) AS zona,
        COALESCE(CONCAT(owner.nombres, ' ', owner.apellidos), pf.responsable) AS encargado,
        UPPER(c.colegio) AS colegio, AVG($descuentoExpr) AS descuento, MAX(p.conse) AS consecutivo
    FROM presupuestos p
    JOIN colegios c ON p.id_colegio = c.id
    LEFT JOIN zonas z ON z.codigo = c.cod_zona
    $ownerJoin
    LEFT JOIN (
        SELECT id_colegio, MAX(cod_zona) AS cod_zona, MAX(responsable) AS responsable
        FROM presupuestos
        WHERE id_periodo = ? AND definido = 1
        GROUP BY id_colegio
    ) pf ON pf.id_colegio = p.id_colegio
    LEFT JOIN zonas z_pf ON z_pf.codigo = pf.cod_zona
    WHERE p.id_periodo = ? AND p.definido = 1 AND c.id_calendario = $calendario_periodo" . $userFilter . "
    GROUP BY p.id_colegio, c.colegio, zona, encargado
    ORDER BY descuento DESC, colegio";
$req = $bdd->prepare($sql);
$req->execute([$periodo, $periodo]);
$filas = $req->fetchAll(PDO::FETCH_ASSOC);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Colegios con más descuento");
$objSpreadsheet->setActiveSheetIndex(0);
$objSpreadsheet->getActiveSheet()->setTitle("Descuento por adopción");
$objSpreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToPage(true);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
$objSpreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);

$estilo_negrita = ['font' => ['bold' => true]];

$objSpreadsheet->getActiveSheet()->getStyle('A1')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->SetCellValue("A1", "Colegios con más descuento");
$objSpreadsheet->getActiveSheet()->getStyle('A2')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->SetCellValue("A2", "Período");
$objSpreadsheet->getActiveSheet()->SetCellValue("B2", $nombre_periodo);
$objSpreadsheet->getActiveSheet()->getStyle('A3')->applyFromArray($estilo_negrita);
$objSpreadsheet->getActiveSheet()->SetCellValue("A3", "Fecha");
$objSpreadsheet->getActiveSheet()->SetCellValue("B3", date("Y-m-d"));

$objSpreadsheet->getActiveSheet()->SetCellValue("A5", "Zona");
$objSpreadsheet->getActiveSheet()->SetCellValue("B5", "Encargado");
$objSpreadsheet->getActiveSheet()->SetCellValue("C5", "Colegio");
$objSpreadsheet->getActiveSheet()->SetCellValue("D5", "Descuento");
$objSpreadsheet->getActiveSheet()->SetCellValue("E5", "Consecutivo de adopción");

$objSpreadsheet->getActiveSheet()->getStyle('A5:E5')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00FF84']],
]);

$conta = 6;
foreach ($filas as $fila) {
    $objSpreadsheet->getActiveSheet()->SetCellValue("A$conta", $fila['zona'] ?: 'Sin asesor asignado');
    $objSpreadsheet->getActiveSheet()->SetCellValue("B$conta", $fila['encargado'] ?: 'Sin asesor asignado');
    $objSpreadsheet->getActiveSheet()->SetCellValue("C$conta", $fila['colegio']);
    $objSpreadsheet->getActiveSheet()->SetCellValue("D$conta", round(floatval($fila['descuento']), 2));
    $objSpreadsheet->getActiveSheet()->SetCellValue("E$conta", $fila['consecutivo']);
    $conta++;
}

$objSpreadsheet->getActiveSheet()->getStyle("D6:D" . ($conta - 1))
    ->getNumberFormat()->setFormatCode('0.00"%"');

$objSpreadsheet->getActiveSheet()->getStyle("A5:E" . ($conta - 1))->applyFromArray([
    'borders' => [
        'top' => ['style' => Border::BORDER_THIN],
        'right' => ['style' => Border::BORDER_THIN],
        'bottom' => ['style' => Border::BORDER_THIN],
        'left' => ['style' => Border::BORDER_THIN],
    ],
]);

foreach (range('A', 'E') as $columnID) {
    $objSpreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
}

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Colegios_mas_descuento.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
exit;
