<?php
// Recherche (JSON) : texte libre + filtres.
require __DIR__ . '/lib.php';
require_login(true);
session_write_close();

const STOP = ['le', 'la', 'les', 'de', 'des', 'du', 'un', 'une', 'et', 'en', 'au', 'aux', 'a', 'l', 'd', 'sur', 'dans',
    'avec', 'pour', 'par', 'the', 'and', 'with', 'for', 'from', 'that', 'this', 'what', 'when', 'where', 'who', 'will',
    'was', 'are', 'how', 'about', 'com', 'www', 'und', 'photo', 'photos', 'image', 'images'];

/** Racine approximative : « chiens » → « chien », « chevaux » → « cheva » (recherche par préfixe ensuite). */
function stem(string $w): string
{
    $n = mb_strlen($w);
    if ($n > 5 && str_ends_with($w, 'aux')) return mb_substr($w, 0, $n - 2);
    if ($n > 4 && (str_ends_with($w, 's') || str_ends_with($w, 'x'))) return mb_substr($w, 0, $n - 1);
    return $w;
}

/** Transforme la saisie en conditions SQL. Gère "expression exacte" et -exclusion. */
function parse_query(string $q, array &$where, array &$args): ?string
{
    $ft = [];
    preg_match_all('/(-?)"([^"]+)"|(-?)(\S+)/u', $q, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $neg = ($m[1] ?? '') === '-' || ($m[3] ?? '') === '-';
        if (!empty($m[2])) { // expression entre guillemets
            $p = norm($m[2]);
            if ($p === '') continue;
            $where[] = "CONCAT(' ', search_text, ' ') " . ($neg ? 'NOT ' : '') . 'LIKE ?';
            $args[] = '% ' . $p . ' %';
            continue;
        }
        foreach (explode(' ', norm($m[4] ?? '')) as $w) {
            if ($w === '' || in_array($w, STOP, true)) continue;
            if (mb_strlen($w) < 3) {
                $where[] = "CONCAT(' ', search_text, ' ') " . ($neg ? 'NOT ' : '') . 'LIKE ?';
                $args[] = '% ' . $w . ($neg ? ' %' : '%');
            } else {
                $ft[] = ($neg ? '-' : '+') . stem($w) . '*';
            }
        }
    }
    $hasPositive = (bool)array_filter($ft, fn($t) => $t[0] === '+');
    if (!$ft) return null;
    if (!$hasPositive) { // uniquement des exclusions : on passe par LIKE
        foreach ($ft as $t) {
            $where[] = "CONCAT(' ', search_text) NOT LIKE ?";
            $args[] = '% ' . substr($t, 1, -1) . '%';
        }
        return null;
    }
    $expr = implode(' ', $ft);
    $where[] = 'MATCH(search_text) AGAINST (? IN BOOLEAN MODE)';
    $args[] = $expr;
    return $expr;
}

$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$args = [];
$ftExpr = $q !== '' ? parse_query($q, $where, $args) : null;

// Filtres (hors années, pour calculer la frise des années)
if (($v = trim((string)($_GET['camera'] ?? ''))) !== '') { $where[] = 'camera = ?'; $args[] = $v; }
if (($v = trim((string)($_GET['lens'] ?? ''))) !== '') { $where[] = 'lens = ?'; $args[] = $v; }
if (($v = (int)($_GET['rating'] ?? 0)) > 0) { $where[] = 'rating >= ?'; $args[] = $v; }
if (in_array($v = (string)($_GET['orient'] ?? ''), ['paysage', 'portrait', 'carre', 'panorama'], true)) { $where[] = 'orientation = ?'; $args[] = $v; }
if (!empty($_GET['gps'])) { $where[] = 'lat IS NOT NULL'; }
if (($_GET['ia'] ?? '') === '0') { $where[] = "(ai_description IS NULL OR ai_description = '')"; }
if (($v = trim((string)($_GET['folder'] ?? ''))) !== '') { $where[] = 'folder LIKE ?'; $args[] = '%' . addcslashes($v, '%_\\') . '%'; }

$baseWhere = $where;
$baseArgs = $args;
$y1 = (int)($_GET['y1'] ?? 0);
$y2 = (int)($_GET['y2'] ?? 0);
if ($y1) { $where[] = 'year >= ?'; $args[] = $y1; }
if ($y2) { $where[] = 'year <= ?'; $args[] = $y2; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v = (string)($_GET['day'] ?? ''))) { $where[] = 'taken_at >= ? AND taken_at < ? + INTERVAL 1 DAY'; $args[] = $v; $args[] = $v; }
if (($v = (int)($_GET['month'] ?? 0)) >= 1 && $v <= 12) { $where[] = 'MONTH(taken_at) = ?'; $args[] = $v; }

$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$per = max(20, min(300, (int)(cfg()['per_page'] ?? 120)));
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = (string)($_GET['sort'] ?? '');
if ($sort === '') $sort = $ftExpr ? 'pertinence' : 'recent';

$score = $ftExpr ? 'MATCH(search_text) AGAINST (? IN BOOLEAN MODE)' : '0';
$order = match ($sort) {
    'ancien' => 'taken_at IS NULL, taken_at ASC, id ASC',
    'note' => 'rating DESC, taken_at DESC',
    'ajout' => 'id DESC',
    'pertinence' => $ftExpr ? 'score DESC, taken_at DESC' : 'taken_at DESC',
    default => 'taken_at IS NULL, taken_at DESC, id DESC',
};

try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM photos $w");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $sql = "SELECT uid, thumb_w tw, thumb_h th, taken_at, rating, title, ai_description, filename, $score AS score
            FROM photos $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per);
    $st = db()->prepare($sql);
    $st->execute($ftExpr ? array_merge([$ftExpr], $args) : $args);
    $items = [];
    foreach ($st as $r) {
        $label = $r['title'] ?: ($r['ai_description'] ? mb_strimwidth($r['ai_description'], 0, 120, '…') : $r['filename']);
        $items[] = [
            'uid' => $r['uid'],
            'w' => (int)($r['tw'] ?: 4),
            'h' => (int)($r['th'] ?: 3),
            'd' => $r['taken_at'],
            'r' => $r['rating'] !== null ? (int)$r['rating'] : null,
            't' => $label,
        ];
    }

    $out = ['total' => $total, 'page' => $page, 'per' => $per, 'sort' => $sort, 'items' => $items];

    if ($page === 1) {
        $bw = $baseWhere ? 'WHERE ' . implode(' AND ', $baseWhere) . ' AND year IS NOT NULL' : 'WHERE year IS NOT NULL';
        $ys = db()->prepare("SELECT year, COUNT(*) n FROM photos $bw GROUP BY year ORDER BY year");
        $ys->execute($baseArgs);
        $out['years'] = array_map(fn($r) => [(int)$r['year'], (int)$r['n']], $ys->fetchAll());
    }
    json_out($out);
} catch (PDOException $e) {
    json_out(['error' => 'Recherche impossible : ' . $e->getMessage()], 500);
}
