<?php
session_start();
$_SESSION['hits'] = ($_SESSION['hits'] ?? 0) + 1;

header('Content-Type: text/plain; charset=utf-8');
echo "session_id: " . session_id() . "\n";
echo "session_save_path (ini): " . ini_get('session.save_path') . "\n";
echo "session_save_path (live): " . session_save_path() . "\n";
echo "hits this session: " . $_SESSION['hits'] . "\n";
echo "cookie received: " . (isset($_COOKIE[session_name()]) ? $_COOKIE[session_name()] : '(none)') . "\n";
echo "session dir exists: " . (is_dir(__DIR__ . '/storage/sessions') ? 'yes' : 'no') . "\n";
echo "session dir writable: " . (is_writable(__DIR__ . '/storage/sessions') ? 'yes' : 'no') . "\n";
echo "session dir contents:\n";
$d = __DIR__ . '/storage/sessions';
if (is_dir($d)) {
    foreach (scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') echo "  - $f\n";
    }
}
echo "server software: " . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . "\n";
echo "request time: " . date('H:i:s') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? '?') . "\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "getcwd(): " . getcwd() . "\n";
