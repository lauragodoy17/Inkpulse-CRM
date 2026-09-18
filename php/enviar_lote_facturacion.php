<?php
/**
 * Botón "Enviar por correo" de lista_pedidos.php?tp=7 (Facturación): junta
 * todos los pedidos de lotes_facturacion aún no enviados (creados por
 * php/generar_lote_facturacion.php al pasar pedidos de Procesando a
 * Facturación) y manda un solo correo a comercial@somoseureka.com.co con
 * el detalle, marcando esos lotes como enviados.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../lib/PHPMailer/src/Exception.php';
require '../lib/PHPMailer/src/PHPMailer.php';
require '../lib/PHPMailer/src/SMTP.php';

function ef_redirect($status, $msg) {
    header('Location: ../lista_pedidos.php?tp=7&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Todos los pedidos de lotes aún no enviados, sin importar la zona de quien
// pulsa el botón: el correo es para todo el equipo de facturación, no solo
// lo de la zona del usuario que envía.
$sql = "SELECT l.id AS id_lote, p.id AS id_pedido, c.colegio,
               CASE WHEN u.tipo=3 THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable
        FROM lotes_facturacion l
        JOIN lotes_facturacion_pedidos lp ON lp.id_lote = l.id
        JOIN pedidos p  ON p.id = lp.id_pedido
        JOIN colegios c ON c.id = p.id_colegio
        JOIN zonas z    ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.cod_zona = z.codigo
        WHERE l.enviado = 0
        GROUP BY p.id
        ORDER BY p.id";
$rows = $bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) ef_redirect('warn', 'No hay pedidos pendientes de enviar a facturación.');

// Se marcan como enviados solo los lotes realmente incluidos en este correo
// (por id, no "WHERE enviado=0" otra vez), para no marcar como enviado un
// lote nuevo que se haya creado justo entre el SELECT de arriba y este UPDATE.
$lotes_incluidos = array_values(array_unique(array_column($rows, 'id_lote')));

$filas_html = '';
foreach ($rows as $r) {
    $filas_html .= '<tr>'
        . '<td style="padding:5px 10px;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($r['id_pedido']) . '</td>'
        . '<td style="padding:5px 10px;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($r['colegio']) . '</td>'
        . '<td style="padding:5px 10px;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($r['responsable'] ?? '') . '</td>'
        . '</tr>';
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host        = 'somoseureka.com.co';
    $mail->SMTPAuth    = true;
    $mail->SMTPAutoTLS = false;
    $mail->Username    = 'crm@somoseureka.com.co';
    $mail->Password    = 'cRm14356$';
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = 587;
    $mail->SMTPOptions = [
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ];

    $mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
    $mail->addAddress('comercial@somoseureka.com.co');
    $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Pedidos pasados a Facturación (' . count($rows) . ')';
    $mail->Body =
        '<p style="font-size:15px;">Los siguientes pedidos fueron pasados a estado <strong>Facturación</strong>:</p>'
        . '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;"># Pedido</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Colegio</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Responsable</th>'
        . '</tr></thead><tbody>' . $filas_html . '</tbody></table>';
    $mail->AltBody = 'Pedidos pasados a Facturación: #' . implode(', #', array_column($rows, 'id_pedido'));

    $mail->send();
} catch (Exception $e) {
    ef_redirect('error', 'No se pudo enviar el correo: ' . $mail->ErrorInfo);
}

$in_lotes = implode(',', array_fill(0, count($lotes_incluidos), '?'));
$upd_lotes = $bdd->prepare("UPDATE lotes_facturacion SET enviado = 1, fecha_envio = NOW() WHERE id IN ($in_lotes)");
$upd_lotes->execute($lotes_incluidos);

ef_redirect('ok', 'Correo enviado a comercial@somoseureka.com.co con ' . count($rows) . ' pedido(s).');
