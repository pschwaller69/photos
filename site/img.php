<?php
// Sert les aperçus uniquement aux personnes connectées.
require __DIR__ . '/lib.php';
session_write_close();
if (!is_logged_in()) {
    http_response_code(403);
    exit;
}
$uid = (string)($_GET['uid'] ?? '');
$size = ($_GET['s'] ?? 't') === 'm' ? 'm' : 't';
if (!preg_match('/^[a-f0-9]{40}$/', $uid)) {
    http_response_code(400);
    exit;
}
$file = media_path($uid, $size);
if ($size === 'm' && !is_file($file)) {
    $file = media_path($uid, 't'); // pas d'aperçu moyen : on retombe sur la miniature
}
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$mtime = filemtime($file);
$etag = '"' . $uid . '-' . $size . '-' . $mtime . '"';
header('Cache-Control: private, max-age=2592000');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
if (isset($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . $uid . '.jpg"');
}
readfile($file);
