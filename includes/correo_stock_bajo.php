<?php
/**
 * /includes/correo_stock_bajo.php
 * Correo de aviso de stock bajo (World Office) al crear un pedido de venta (con o sin adopción) o
 * una solicitud de muestras (muestreo o pedido sin adopción de tipo Muestras). Antes cada archivo
 * (php/pedido.php, php/pedido_sa.php) armaba el suyo; se unifica aquí para que todos digan el tipo
 * de pedido (pedido por el usuario 2026-10-05). Destinatarios: los mismos que ya tenía el aviso.
 */
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

/**
 * Envía el aviso. $filas: para venta [['libro','existencia'], ...]; para muestras
 * [['libro','general','muestras'], ...] ($muestras = true: columnas "Bodega General" y "Bodega
 * Muestras General", cada una solo si algún libro está por debajo del umbral en esa bodega; en
 * cada fila solo se escribe la existencia de la bodega que está baja — la que tiene 50 o más queda
 * vacía. Si las dos están bajas, se ven las dos lado a lado. Pedido por el usuario 2026-10-05).
 * Lanza Exception si falla el envío (el llamador decide si lo registra).
 */
function enviar_correo_stock_bajo($asunto, $introHtml, array $filas, $muestras, $urlRevisar, $altBody, $queRevisar = 'el pedido') {
    $celda = 'padding:4px 10px;border-bottom:1px solid #e5e7eb;';
    $th = 'padding:4px 10px;border-bottom:2px solid #d1d5db;';
    $fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',') . ' unid.';

    $bodegas = ['existencia' => 'Existencia'];
    if ($muestras) {
        $bodegas = [];
        foreach (['general' => 'Bodega General', 'muestras' => 'Bodega Muestras General'] as $clave => $titulo) {
            foreach ($filas as $f) {
                if ($f[$clave] < UMBRAL_STOCK_BAJO) { $bodegas[$clave] = $titulo; break; }
            }
        }
    }

    $filasHtml = '';
    foreach ($filas as $f) {
        $filasHtml .= '<tr><td style="' . $celda . '">' . htmlspecialchars($f['libro']) . '</td>';
        foreach ($bodegas as $clave => $_) {
            $valor = (!$muestras || $f[$clave] < UMBRAL_STOCK_BAJO) ? $fmt($f[$clave]) : '';
            $filasHtml .= '<td style="' . $celda . 'text-align:center;">' . $valor . '</td>';
        }
        $filasHtml .= '</tr>';
    }
    $encabezado = '<th style="' . $th . 'text-align:left;">Libro</th>';
    foreach ($bodegas as $titulo) $encabezado .= '<th style="' . $th . '">' . $titulo . '</th>';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'somoseureka.com.co';
    $mail->SMTPAuth   = true;
    $mail->SMTPAutoTLS = false;
    $mail->Username   = 'crm@somoseureka.com.co';
    $mail->Password   = 'cRm14356$';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom('crm@somoseureka.com.co', 'CRM Eureka');
    $mail->addAddress('felipe.vargas@somoseureka.com.co', 'felipe.vargas@somoseureka.com.co');
    $mail->addCC('comercial@somoseureka.com.co');
    $mail->addCC('oltoledo@hotmail.com');
    $mail->addReplyTo('crm@somoseureka.com.co', 'CRM Eureka');

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $asunto;
    $mail->Body =
        '<p style="font-size:15px;">' . $introHtml . '</p>'
        . '<table style="border-collapse:collapse;font-size:14px;"><thead><tr>' . $encabezado . '</tr></thead>'
        . '<tbody>' . $filasHtml . '</tbody></table>'
        . '<p style="font-size:14px;margin-top:14px;">Haz clic <a href="' . htmlspecialchars($urlRevisar) . '">aquí</a> para revisar ' . $queRevisar . '.</p>';
    $mail->AltBody = $altBody;

    $mail->send();
}
