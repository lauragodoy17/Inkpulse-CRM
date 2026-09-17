<?php
/**
 * Botón "Enviar por correo" de lista_pedidos_sa.php?tp=7 (Facturación):
 * junta todos los pedidos sin adopción de lotes_facturacion_sa aún no
 * enviados y manda un solo correo a facturacion3@somoseureka.com.co con el
 * detalle, marcando esos lotes como enviados. Mismo patrón que
 * php/enviar_lote_facturacion.php.
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../lib/PHPMailer/src/Exception.php';
require '../lib/PHPMailer/src/PHPMailer.php';
require '../lib/PHPMailer/src/SMTP.php';

function efs_redirect($status, $msg) {
    header('Location: ../lista_pedidos_sa.php?tp=7&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_sa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_sa_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion_sa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT l.id AS id_lote, p.id AS id_pedido, p.colegio,
               CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) AS responsable
        FROM lotes_facturacion_sa l
        JOIN lotes_facturacion_sa_pedidos lp ON lp.id_lote = l.id
        JOIN pedidos2 p ON p.id = lp.id_pedido
        JOIN usuarios u ON u.id = p.id_usuario
        WHERE l.enviado = 0
        GROUP BY p.id
        ORDER BY p.id";
$rows = $bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) efs_redirect('warn', 'No hay pedidos pendientes de enviar a facturación.');

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
    $mail->addAddress('facturacion3@somoseureka.com.co');
    $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Pedidos sin adopción pasados a Facturación (' . count($rows) . ')';
    $mail->Body =
        '<p style="font-size:15px;">Los siguientes pedidos sin adopción fueron pasados a estado <strong>Facturación</strong>:</p>'
        . '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;"># Pedido</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Colegio</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Responsable</th>'
        . '</tr></thead><tbody>' . $filas_html . '</tbody></table>';
    $mail->AltBody = 'Pedidos sin adopción pasados a Facturación: #' . implode(', #', array_column($rows, 'id_pedido'));

    $mail->send();
} catch (Exception $e) {
    efs_redirect('error', 'No se pudo enviar el correo: ' . $mail->ErrorInfo);
}

$in_lotes = implode(',', array_fill(0, count($lotes_incluidos), '?'));
$upd_lotes = $bdd->prepare("UPDATE lotes_facturacion_sa SET enviado = 1, fecha_envio = NOW() WHERE id IN ($in_lotes)");
$upd_lotes->execute($lotes_incluidos);

efs_redirect('ok', 'Correo enviado a facturacion3@somoseureka.com.co con ' . count($rows) . ' pedido(s).');
