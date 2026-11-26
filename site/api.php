<?php
// Point d'entrée utilisé par le script de synchronisation du PC.
define('PHOTOS_NO_SESSION', true);
require __DIR__ . '/lib.php';

if (!is_installed()) {
    json_out(['error' => 'site non installé (ouvrez install.php)'], 503);
}
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_API_TOKEN'] ?? ($_POST['token'] ?? '');
$expected = (string)setting('api_token');
if ($expected === '' || !hash_equals($expected, (string)$token)) {
    json_out(['error' => 'clé API invalide'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST attendu'], 405);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ping':
        json_out([
            'ok' => true,
            'version' => APP_VERSION,
            'photos' => (int)db()->query('SELECT COUNT(*) FROM photos')->fetchColumn(),
            'upload_max' => ini_get('upload_max_filesize'),
        ]);

    case 'uids':
        $uids = db()->query('SELECT uid FROM photos')->fetchAll(PDO::FETCH_COLUMN);
        json_out(['uids' => $uids]);

    case 'delete':
        $uids = json_decode((string)($_POST['uids'] ?? '[]'), true);
        if (!is_array($uids)) json_out(['error' => 'uids invalides'], 400);
        $n = 0;
        $st = db()->prepare('DELETE FROM photos WHERE uid = ?');
        foreach ($uids as $uid) {
            if (!is_string($uid) || !preg_match('/^[a-f0-9]{40}$/', $uid)) continue;
            $st->execute([$uid]);
            $n += $st->rowCount();
            foreach (['t', 'm'] as $s) @unlink(media_path($uid, $s));
        }
        json_out(['ok' => true, 'deleted' => $n]);

    case 'upsert':
        upsert();

    case 'media': // aperçu d'une photo, pour la rédaction des publications
        $uid = (string)($_POST['uid'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $uid)) json_out(['error' => 'uid invalide'], 400);
        $f = media_path($uid, 'm');
        if (!is_file($f)) $f = media_path($uid, 't');
        if (!is_file($f)) json_out(['error' => 'image absente'], 404);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;

    case 'pub_next': // le PC demande s'il y a un texte à rédiger
        ensure_publications();
        set_setting('worker_seen', (string)time());
        if (!empty($_POST['model'])) set_setting('worker_model', mb_substr((string)$_POST['model'], 0, 80));
        db()->exec("UPDATE publications SET status = 'attente' WHERE status = 'en_cours' AND claimed_at < NOW() - INTERVAL 15 MINUTE");
        $job = db()->query("SELECT * FROM publications WHERE status = 'attente' ORDER BY id LIMIT 1")->fetch();
        if (!$job) json_out(['job' => null]);
        $st = db()->prepare("UPDATE publications SET status = 'en_cours', claimed_at = NOW() WHERE id = ? AND status = 'attente'");
        $st->execute([$job['id']]);
        if ($st->rowCount() === 0) json_out(['job' => null]);
        $ph = db()->prepare('SELECT uid, path, filename, folder, taken_at, camera, lens, focal, aperture, exposure, iso, title, caption,
            keywords, city, region, country, location, ai_description, ai_tags FROM photos WHERE uid = ?');
        $ph->execute([$job['uid']]);
        $photo = $ph->fetch();
        if (!$photo) {
            db()->prepare("UPDATE publications SET status = 'erreur', error = 'photo introuvable' WHERE id = ?")->execute([$job['id']]);
            json_out(['job' => null]);
        }
        $photo['keywords'] = json_list($photo['keywords']);
        $photo['ai_tags'] = json_list($photo['ai_tags']);
        json_out(['job' => [
            'id' => (int)$job['id'], 'network' => $job['network'], 'tone' => $job['tone'],
            'hashtags' => (bool)$job['hashtags'], 'instructions' => $job['instructions'], 'photo' => $photo,
        ]]);

    case 'pub_done': // le PC renvoie le texte rédigé
        ensure_publications();
        $id = (int)($_POST['id'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));
        $err = trim((string)($_POST['error'] ?? ''));
        db()->prepare("UPDATE publications SET status = ?, text = ?, error = ?, model = ? WHERE id = ?")->execute([
            $text !== '' ? 'pret' : 'erreur', $text !== '' ? $text : null, $err !== '' ? mb_substr($err, 0, 500) : null,
            mb_substr((string)($_POST['model'] ?? ''), 0, 80), $id,
        ]);
        json_out(['ok' => true]);

    default:
        json_out(['error' => 'action inconnue'], 400);
}

function str_or_null($v, int $max): ?string
{
    if ($v === null || $v === '') return null;
    return mb_substr(trim((string)$v), 0, $max);
}

function num_or_null($v): ?float
{
    return is_numeric($v) ? (float)$v : null;
}

function save_upload(string $field, string $uid, string $size): ?array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) json_out(['error' => "envoi $field échoué (code {$f['error']})"], 400);
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) json_out(['error' => "$field n'est pas un JPEG"], 400);
    $dest = media_path($uid, $size);
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (!move_uploaded_file($f['tmp_name'], $dest)) json_out(['error' => "écriture $field impossible"], 500);
    return [$info[0], $info[1]];
}

function upsert(): never
{
    $m = json_decode((string)($_POST['meta'] ?? ''), true);
    if (!is_array($m)) json_out(['error' => 'meta manquant'], 400);
    $uid = (string)($m['uid'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $uid)) json_out(['error' => 'uid invalide'], 400);

    $existing = db()->prepare('SELECT thumb_w, thumb_h, has_medium FROM photos WHERE uid = ?');
    $existing->execute([$uid]);
    $old = $existing->fetch();

    $thumb = save_upload('thumb', $uid, 't');
    $medium = save_upload('medium', $uid, 'm');
    if (!$thumb && !$old) json_out(['error' => 'miniature manquante pour une nouvelle photo'], 400);

    $list = function ($v): ?string {
        if (!is_array($v)) return null;
        $v = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $v), fn($x) => $x !== '')));
        return $v ? json_encode($v, JSON_UNESCAPED_UNICODE) : null;
    };

    $taken = null;
    if (!empty($m['taken_at']) && ($ts = strtotime((string)$m['taken_at']))) {
        $taken = date('Y-m-d H:i:s', $ts);
    }
    $w = isset($m['width']) ? (int)$m['width'] : null;
    $h = isset($m['height']) ? (int)$m['height'] : null;
    $orientation = null;
    if ($w && $h) {
        $r = $w / $h;
        $orientation = $r > 1.9 ? 'panorama' : ($r > 1.05 ? 'paysage' : ($r < 0.95 ? 'portrait' : 'carre'));
    }

    $row = [
        'uid' => $uid,
        'path' => str_or_null($m['path'] ?? '', 1024) ?? '',
        'filename' => str_or_null($m['filename'] ?? '', 255) ?? '',
        'folder' => str_or_null($m['folder'] ?? '', 1024) ?? '',
        'taken_at' => $taken,
        'year' => $taken ? (int)substr($taken, 0, 4) : null,
        'camera' => str_or_null($m['camera'] ?? null, 128),
        'lens' => str_or_null($m['lens'] ?? null, 160),
        'focal' => num_or_null($m['focal'] ?? null),
        'aperture' => num_or_null($m['aperture'] ?? null),
        'exposure' => str_or_null($m['exposure'] ?? null, 24),
        'iso' => is_numeric($m['iso'] ?? null) ? (int)$m['iso'] : null,
        'width' => $w,
        'height' => $h,
        'orientation' => $orientation,
        'rating' => is_numeric($m['rating'] ?? null) ? max(-1, min(5, (int)$m['rating'])) : null,
        'label' => str_or_null($m['label'] ?? null, 32),
        'keywords' => $list($m['keywords'] ?? null),
        'title' => str_or_null($m['title'] ?? null, 255),
        'caption' => str_or_null($m['caption'] ?? null, 5000),
        'city' => str_or_null($m['city'] ?? null, 128),
        'region' => str_or_null($m['region'] ?? null, 128),
        'country' => str_or_null($m['country'] ?? null, 128),
        'location' => str_or_null($m['location'] ?? null, 255),
        'lat' => num_or_null($m['lat'] ?? null),
        'lon' => num_or_null($m['lon'] ?? null),
        'ai_description' => str_or_null($m['ai_description'] ?? null, 5000),
        'ai_tags' => $list($m['ai_tags'] ?? null),
        'ai_text' => str_or_null($m['ai_text'] ?? null, 2000),
        'ai_model' => str_or_null($m['ai_model'] ?? null, 80),
        'thumb_w' => $thumb[0] ?? $old['thumb_w'] ?? null,
        'thumb_h' => $thumb[1] ?? $old['thumb_h'] ?? null,
        'has_medium' => $medium ? 1 : (int)($old['has_medium'] ?? 0),
        'file_size' => is_numeric($m['file_size'] ?? null) ? (int)$m['file_size'] : null,
    ];
    $row['search_text'] = build_search_text($row);

    $cols = array_keys($row);
    $sql = 'INSERT INTO photos (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", array_filter($cols, fn($c) => $c !== 'uid')))
        . ', updated_at = CURRENT_TIMESTAMP';
    db()->prepare($sql)->execute(array_values($row));

    json_out(['ok' => true, 'uid' => $uid, 'created' => !$old]);
}
