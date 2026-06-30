<?php
/**
 * إعدادات مزودي الإشعارات — نظام وَصْل
 * ضع بياناتك الحقيقية هنا وأضف هذا الملف لـ .gitignore
 */

// ─── Msegat — SMS (مزود سعودي) ──────────────────────────────
// سجّل على https://www.msegat.com
define('MSEGAT_API_URL',  'https://www.msegat.com/gw/sendsms.php');
define('MSEGAT_USERNAME', 'YOUR_MSEGAT_USERNAME');
define('MSEGAT_API_KEY',  'YOUR_MSEGAT_API_KEY');
define('MSEGAT_SENDER',   'WASL');                  // اسم المُرسِل المعتمد

// ─── Unifonic — WhatsApp Business API ────────────────────────
// سجّل على https://unifonic.com
define('UNIFONIC_APP_SID',   'YOUR_UNIFONIC_APP_SID');
define('UNIFONIC_SENDER_ID', 'YOUR_WHATSAPP_NUMBER'); // رقم WhatsApp Business
define('UNIFONIC_API_URL',   'https://el.cloud.unifonic.com/rest/WhatsApp/messages');

// ─── SMTP — البريد الإلكتروني ────────────────────────────────
// استخدم حساب G Suite أو SendGrid أو Mailgun
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_SECURE',     'tls');
define('SMTP_USERNAME',   'YOUR_EMAIL@gmail.com');
define('SMTP_PASSWORD',   'YOUR_APP_PASSWORD');     // App Password من Google
define('SMTP_FROM_EMAIL', 'noreply@yourcompany.com.sa');
define('SMTP_FROM_NAME',  'نظام وَصْل');

// ─── تفعيل/تعطيل القنوات ─────────────────────────────────────
define('NOTIFY_SMS_ENABLED',       false);  // غيّر لـ true بعد الإعداد
define('NOTIFY_WHATSAPP_ENABLED',  false);
define('NOTIFY_EMAIL_ENABLED',     false);
