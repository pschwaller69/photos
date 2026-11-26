<?php
// Détails d'une photo (JSON) pour la visionneuse.
require __DIR__ . '/lib.php';
require_login(true);
session_write_close();
$uid = (string)($_GET['uid'] ?? '');
$st = db()->prepare('SELECT * FROM photos WHERE uid = ?');
$st->execute([$uid]);
$p = $st->fetch();
if (!$p) json_out(['error' => 'introuvable'], 404);
unset($p['search_text'], $p['id']);
$p['keywords'] = json_list($p['keywords']);
$p['ai_tags'] = json_list($p['ai_tags']);
json_out($p);
