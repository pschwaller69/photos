<?php
// Publications (JSON) : photos au hasard, demandes de texte, historique.
require __DIR__ . '/lib.php';
require_login(true);
session_write_close();
ensure_publications();

$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'photos') {
    json_out(['error' => 'requête refusée'], 400);
}
$in = $isPost ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;

function worker_info(): array
{
    $seen = (int)(setting('worker_seen') ?? 0);
    return ['seen' => $seen ?: null, 'ago' => $seen ? time() - $seen : null, 'model' => setting('worker_model')];
}

function pub_row(int $id): ?array
{
    $st = db()->prepare('SELECT id, uid, status, network, tone, hashtags, instructions, text, error, model, created_at, published_at
                         FROM publications WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if ($r['status'] === 'attente') {
        $r['position'] = (int)db()->query("SELECT COUNT(*) FROM publications WHERE status = 'attente' AND id < " . (int)$id)->fetchColumn();
    }
    return $r;
}

switch ($action) {
    case 'random':
        $where = ['1=1'];
        $args = [];
        if (($v = (int)($in['rating'] ?? 0)) > 0) { $where[] = 'p.rating >= ?'; $args[] = $v; }
        if (($v = (int)($in['year'] ?? 0)) > 0) { $where[] = 'p.year = ?'; $args[] = $v; }
        if (($v = norm((string)($in['q'] ?? ''))) !== '') {
            foreach (explode(' ', $v) as $w) {
                if (mb_strlen($w) < 2) continue;
                $where[] = "CONCAT(' ', p.search_text) LIKE ?";
                $args[] = '% ' . $w . '%';
            }
        }
        if (empty($in['all'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM publications x WHERE x.uid = p.uid AND x.published_at IS NOT NULL)';
        }
        $n = max(4, min(30, (int)($in['n'] ?? 12)));
        $st = db()->prepare('SELECT p.uid, p.thumb_w w, p.thumb_h h, p.taken_at d, p.title, p.ai_description, p.city, p.country, p.rating
                             FROM photos p WHERE ' . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $n");
        $st->execute($args);
        json_out(['items' => $st->fetchAll(), 'worker' => worker_info()]);

    case 'photo':
        $st = db()->prepare('SELECT uid, taken_at, title, caption, ai_description, city, region, country, location, rating, thumb_w w, thumb_h h
                             FROM photos WHERE uid = ?');
        $st->execute([(string)($in['uid'] ?? '')]);
        $p = $st->fetch();
        if (!$p) json_out(['error' => 'introuvable'], 404);
        $h = db()->prepare('SELECT id, status, network, text, published_at, created_at FROM publications WHERE uid = ? ORDER BY id DESC LIMIT 10');
        $h->execute([$p['uid']]);
        $p['publications'] = $h->fetchAll();
        json_out($p);

    case 'create':
        $uid = (string)($in['uid'] ?? '');
        $chk = db()->prepare('SELECT 1 FROM photos WHERE uid = ?');
        $chk->execute([$uid]);
        if (!$chk->fetchColumn()) json_out(['error' => 'photo introuvable'], 404);
        $network = array_key_exists($in['network'] ?? '', NETWORKS) ? $in['network'] : 'instagram';
        $tone = array_key_exists($in['tone'] ?? '', TONES) ? $in['tone'] : 'naturel';
        db()->prepare('INSERT INTO publications (uid, network, tone, hashtags, instructions) VALUES (?, ?, ?, ?, ?)')->execute([
            $uid, $network, $tone, empty($in['hashtags']) ? 0 : 1, mb_substr(trim((string)($in['instructions'] ?? '')), 0, 1000) ?: null,
        ]);
        $id = (int)db()->lastInsertId();
        json_out(['pub' => pub_row($id), 'worker' => worker_info()]);

    case 'get':
        $r = pub_row((int)($in['id'] ?? 0));
        if (!$r) json_out(['error' => 'introuvable'], 404);
        json_out(['pub' => $r, 'worker' => worker_info()]);

    case 'save':
    case 'published':
        $id = (int)($in['id'] ?? 0);
        $text = isset($in['text']) ? trim((string)$in['text']) : null;
        if ($text !== null) {
            db()->prepare('UPDATE publications SET text = ? WHERE id = ?')->execute([$text, $id]);
        }
        if ($action === 'published') {
            $on = !isset($in['undo']);
            db()->prepare('UPDATE publications SET published_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id = ?')->execute([$id]);
        }
        json_out(['pub' => pub_row($id)]);

    case 'cancel':
        db()->prepare("DELETE FROM publications WHERE id = ? AND status IN ('attente', 'erreur')")->execute([(int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);

    case 'history':
        $rows = db()->query("SELECT x.id, x.uid, x.status, x.network, x.text, x.created_at, x.published_at
                             FROM publications x JOIN photos p ON p.uid = x.uid
                             WHERE x.status = 'pret' ORDER BY COALESCE(x.published_at, x.created_at) DESC LIMIT 40")->fetchAll();
        json_out(['items' => $rows, 'worker' => worker_info()]);

    default:
        json_out(['error' => 'action inconnue'], 400);
}
