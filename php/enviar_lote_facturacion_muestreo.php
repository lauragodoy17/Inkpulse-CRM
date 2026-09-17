<?php
/**
 * Botón "Enviar por correo" de lista_muestreo.php?tp=7 (Facturación): junta
 * todos los muestreos de lotes_facturacion_muestreo aún no enviados y manda
 * un solo correo a facturacion3@somoseureka.com.co con el detalle, marcando
 * esos lotes como enviados. Mismo patrón que
 * php/enviar_lote_facturacion.php (pedidos con adopción).
 */
require_once("../php/aut.php");
require_once("../conexion/bdd.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../lib/PHPMailer/src/Exception.php';
require '../lib/PHPMailer/src/PHPMailer.php';
require '../lib/PHPMailer/src/SMTP.php';

function efm_redirect($status, $msg) {
    header('Location: ../lista_muestreo.php?tp=7&ink_status=' . $status . '&ink_msg=' . urlencode($msg));
    exit;
}

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    cantidad_pedidos INT NOT NULL DEFAULT 0,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$bdd->exec("CREATE TABLE IF NOT EXISTS lotes_facturacion_muestreo_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_pedido INT NOT NULL,
    KEY idx_lote (id_lote),
    FOREIGN KEY (id_lote) REFERENCES lotes_facturacion_muestreo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT l.id AS id_lote, m.id AS id_pedido, c.colegio,
               CASE WHEN u.tipo IN (1,3) THEN CONCAT(TRIM(u.nombres),' ',TRIM(u.apellidos)) ELSE TRIM(c.responsable) END AS responsable
        FROM lotes_facturacion_muestreo l
        JOIN lotes_facturacion_muestreo_pedidos lp ON lp.id_lote = l.id
        JOIN muestreos m ON m.id = lp.id_pedido
        JOIN colegios c ON c.id = m.id_colegio
        JOIN zonas z    ON z.codigo = c.cod_zona
        JOIN usuarios u ON u.id = m.id_usuario
        WHERE l.enviado = 0
        GROUP BY m.id
        ORDER BY m.id";
$rows = $bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) efm_redirect('warn', 'No hay muestreos pendientes de enviar a facturación.');

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
    $mail->Subject = 'Muestreos pasados a Facturación (' . count($rows) . ')';
    $mail->Body =
        '<p style="font-size:15px;">Los siguientes muestreos fueron pasados a estado <strong>Facturación</strong>:</p>'
        . '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;"># Muestreo</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Colegio</th>'
        . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #d1d5db;">Responsable</th>'
        . '</tr></thead><tbody>' . $filas_html . '</tbody></table>';
    $mail->AltBody = 'Muestreos pasados a Facturación: #' . implode(', #', array_column($rows, 'id_pedido'));

    $mail->send();
} catch (Exception $e) {
    efm_redirect('error', 'No se pudo enviar el correo: ' . $mail->ErrorInfo);
}

$in_lotes = implode(',', array_fill(0, count($lotes_incluidos), '?'));
$upd_lotes = $bdd->prepare("UPDATE lotes_facturacion_muestreo SET enviado = 1, fecha_envio = NOW() WHERE id IN ($in_lotes)");
$upd_lotes->execute($lotes_incluidos);

efm_redirect('ok', 'Correo enviado a facturacion3@somoseureka.com.co con ' . count($rows) . ' muestreo(s).');
