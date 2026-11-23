<?php
/**
 * Alertas de venta directa (2026-11-10) — ver includes/alertas_vd.php.
 *
 * WhatsApp con CallMeBot (gratis): para activar, desde el celular de destino se envía UNA vez por WhatsApp el mensaje
 *   I allow callmebot to send me messages
 * al número de CallMeBot que aparece en https://www.callmebot.com/blog/free-api-whatsapp-messages/ . CallMeBot
 * responde con una clave (apikey): pegarla abajo en ALERTAS_VD_CALLMEBOT_APIKEY. Mientras esté vacía, solo se envía
 * el correo.
 */

// Correo que recibe las alertas. Si quien programa la venta es el usuario con este mismo correo, no se le avisa.
const ALERTAS_VD_CORREO = 'facturacion3@somoseureka.com.co';

// Celular (WhatsApp) que recibe las alertas, con indicativo de país y sin espacios ni +.
const ALERTAS_VD_CELULAR = '573160489184';

// Clave que entrega CallMeBot para ese celular (vacía = WhatsApp desactivado).
const ALERTAS_VD_CALLMEBOT_APIKEY = '';

// Recordatorio: se avisa cuando faltan estos días o menos para la venta.
const ALERTAS_VD_DIAS_RECORDATORIO = 2;

// Dirección del CRM para los enlaces de los mensajes.
const ALERTAS_VD_URL_CRM = 'https://crm.somoseureka.com.co/';
