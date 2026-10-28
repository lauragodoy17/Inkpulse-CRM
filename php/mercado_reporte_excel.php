<?php
/**
 * /php/mercado_reporte_excel.php
 * Reporte de mercado editorial libro a libro (reporte_mercado.php): una fila por título de otra editorial
 * registrado en la pestaña "Mercado editorial" de cada colegio, con su venta potencial (ver mercado_calcular()).
 * El asesor de un colegio es el dueño de su zona, mismo criterio que php/valoriza_excel.php.
 */
require_once("aut.php");

ini_set('memory_limit', '512M');
set_time_limit(120);
session_write_close();

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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

require_once("../conexion/bdd.php");
require_once("../includes/excel_formato.php");
require_once("../includes/mercado_editorial_datos.php");

$periodo = mercado_periodo($bdd, (int)($_POST['periodo'] ?? 0));
if (!$periodo) {
    header("Location: ../reporte_mercado.php");
    exit;
}
$req = $bdd->prepare("SELECT id_calendario FROM periodos WHERE id=?");
$req->execute([$periodo['id']]);
$calendario = (int)$req->fetchColumn();

// ── Alcance según quien descarga (mismo criterio que php/valoriza_excel.php) ──
$tipo_sesion = (int)($_SESSION['tipo'] ?? 0);
$filtro = '';
$params = [$periodo['id'], $calendario];
$asesor_label = 'Todos';
if (in_array($tipo_sesion, [1, 2, 7], true)) {
    $promotor = (int)($_POST['promotor'] ?? 0);
    if ($promotor) {
        $req = $bdd->prepare("SELECT tipo, CONCAT(nombres, ' ', apellidos) FROM usuarios WHERE id=?");
        $req->execute([$promotor]);
        [$tipo_promotor, $asesor_label] = $req->fetch(PDO::FETCH_NUM) ?: [0, ''];
        // Asesor/distribuidor real (tipo 3/6) o id 69: por zona del colegio; los demás, por quién cargó el título.
        $filtro = (in_array((int)$tipo_promotor, [3, 6], true) || $promotor === 69) ? ' AND owner.id=?' : ' AND m.id_usuario=?';
        $params[] = $promotor;
    }
} elseif ($tipo_sesion === 10) {
    $filtro = ' AND m.id_usuario=?';
    $params[] = $_SESSION['id'];
    $asesor_label = excel_usuario_descarga($bdd);
} else {
    $filtro = ' AND owner.id=?';
    $params[] = $_SESSION['id'];
    $asesor_label = excel_usuario_descarga($bdd);
}

$sql = "SELECT m.id, m.id_colegio, m.id_grado, m.libro, m.vigencia, m.precio, m.tasa_compra, m.descuento,
               c.colegio, c.dane, c.departamento, c.ciudad, c.sub_zona, c.responsable, z.zona,
               COALESCE(UPPER(CONCAT(owner.nombres, ' ', owner.apellidos)), 'Sin asesor asignado') as promotor, owner.tipo as tipouser,
               e.editorial, b.materia, g.grado, t.tipo
        FROM mercado_editorial m
        JOIN colegios c    ON c.id = m.id_colegio
        JOIN editoriales e ON e.id = m.editorial
        JOIN materias b    ON b.id = m.id_materia
        JOIN grados g      ON g.id = m.id_grado
        LEFT JOIN tipos_libro t ON t.id = m.id_tipo_libro
        LEFT JOIN zonas z  ON z.codigo = c.cod_zona
        LEFT JOIN usuarios owner ON owner.cod_zona = c.cod_zona AND owner.cod_zona <> '' AND owner.act = 1 AND (owner.tipo IN (3, 6) OR owner.id = 69)
        WHERE m.id_periodo=? AND c.id_calendario=?" . $filtro . "
        GROUP BY m.id
        ORDER BY promotor, c.colegio, m.id_grado, e.editorial, m.libro";
$req = $bdd->prepare($sql);
$req->execute($params);
$filas = $req->fetchAll(PDO::FETCH_ASSOC);

// ── Pre-fetch: alumnos por colegio/grado, departamentos y sub-zonas ──
$cole_ids = array_values(array_unique(array_column($filas, 'id_colegio')));
$alumnos = [];
if ($cole_ids) {
    $ph = implode(',', array_fill(0, count($cole_ids), '?'));
    $req = $bdd->prepare("SELECT id_colegio, id_grado, SUM(alumnos) as n FROM grados_paralelos
                          WHERE id_periodo=? AND id_colegio IN ($ph) GROUP BY id_colegio, id_grado");
    $req->execute(array_merge([$periodo['id']], $cole_ids));
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $r) $alumnos[$r['id_colegio']][$r['id_grado']] = (int)$r['n'];
}
$dep_map = $bdd->query("SELECT id, departamento FROM departamentos")->fetchAll(PDO::FETCH_KEY_PAIR);
$sz_map  = $bdd->query("SELECT id, sub_zona FROM sub_zonas")->fetchAll(PDO::FETCH_KEY_PAIR);

$objSpreadsheet = new Spreadsheet();
$objSpreadsheet->getProperties()->setCreator("Ing. Alejandro Rangel");
$objSpreadsheet->getProperties()->setTitle("Mercado editorial libro a libro");
$hoja = $objSpreadsheet->getActiveSheet();
$hoja->setTitle("MERCADO EDITORIAL");
$hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$hoja->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
$hoja->getPageSetup()->setFitToPage(true);
$hoja->getPageSetup()->setFitToWidth(1);
$hoja->getPageSetup()->setFitToHeight(0);

excel_encabezado($hoja, $bdd, 'REPORTE DE MERCADO EDITORIAL', 'Periodo: ' . $periodo['periodo'] . ' — Usuario: ' . $asesor_label);

$fila = EXCEL_FILA_ENCABEZADOS;
$hoja->fromArray(['Empresa', 'Asesor', 'Colegio', 'Dane', 'Zona', 'Departamento', 'Ciudad', 'Editorial', 'Tipo', 'Materia',
    'Grado', 'Título', 'Vigencia', 'Alumnos', 'Tasa de compra', 'PVP', 'Descuento', 'Precio neto', 'Ejemplares potenciales',
    'Venta potencial'], null, 'A' . $fila);
excel_estilo_encabezados($hoja, 'A' . $fila . ':T' . $fila);
$fila++;

$inicioDatos = $fila;
foreach ($filas as $f) {
    mercado_calcular($f, $periodo, $alumnos[$f['id_colegio']][$f['id_grado']] ?? 0);

    // Igual que php/valoriza_excel.php: zonas.zona trae "Empresa/Zona"; si el dueño es distribuidor (tipo 6)
    // la empresa es el distribuidor, el asesor es el responsable del colegio y la zona es su sub-zona.
    if ((int)$f['tipouser'] !== 6) {
        [$empresa, $zona] = array_pad(explode('/', $f['zona'] ?? ''), 2, '');
        if ($zona === '') $zona = $sz_map[$f['sub_zona']] ?? '';
        $asesor = $f['promotor'];
    } else {
        $empresa = $f['promotor'];
        $asesor  = $f['responsable'];
        $zona    = $sz_map[$f['sub_zona']] ?? '';
    }

    $hoja->fromArray([$empresa, $asesor, $f['colegio']], null, 'A' . $fila);
    $hoja->setCellValueExplicit('D' . $fila, (string)$f['dane'], DataType::TYPE_STRING);
    $hoja->fromArray([$zona, $dep_map[$f['departamento']] ?? '', $f['ciudad'], $f['editorial'], $f['tipo'], $f['materia'],
        $f['grado'], $f['libro'], $f['vigencia'], $f['alumnos'], $f['tasa'] / 100, $f['precio'], $f['desc'] / 100,
        $f['neto'], $f['ejemplares'], $f['venta']], null, 'E' . $fila, true);
    $fila++;
}

if ($filas) {
    $finDatos = $fila - 1;
    $hoja->setCellValue('A' . $fila, 'TOTAL');
    $hoja->setCellValue('S' . $fila, '=SUM(S' . $inicioDatos . ':S' . $finDatos . ')');
    $hoja->setCellValue('T' . $fila, '=SUM(T' . $inicioDatos . ':T' . $finDatos . ')');
    $hoja->getStyle('A' . $fila . ':T' . $fila)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    ]);
} else {
    $hoja->setCellValue('A' . $fila, 'No hay títulos registrados en el mercado editorial para los filtros seleccionados.');
}

$hoja->getStyle('N' . $inicioDatos . ':N' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('O' . $inicioDatos . ':O' . $fila)->getNumberFormat()->setFormatCode('0%');
$hoja->getStyle('P' . $inicioDatos . ':P' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('Q' . $inicioDatos . ':Q' . $fila)->getNumberFormat()->setFormatCode('0%');
$hoja->getStyle('R' . $inicioDatos . ':R' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('S' . $inicioDatos . ':S' . $fila)->getNumberFormat()->setFormatCode('#,##0');
$hoja->getStyle('T' . $inicioDatos . ':T' . $fila)->getNumberFormat()->setFormatCode('"$"#,##0');
$hoja->getStyle('N' . EXCEL_FILA_ENCABEZADOS . ':S' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
foreach (range('A', 'T') as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}
$hoja->setAutoFilter('A' . EXCEL_FILA_ENCABEZADOS . ':T' . max($inicioDatos, $fila - 1));
$hoja->freezePane('D' . $inicioDatos);

$objWriter = new Xlsx($objSpreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="mercado_editorial_' . preg_replace('/[^A-Za-z0-9]+/', '_', $periodo['periodo']) . '_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
$objWriter->save('php://output');
