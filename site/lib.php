<?php
// Fonctions communes du site « Mes photos ».
declare(strict_types=1);

const APP_VERSION = '1.0';

if (!defined('PHOTOS_NO_SESSION')) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_name('photos_sess');
    session_start();
}

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) {
            http_response_code(500);
            exit('config.php manquant : copiez config.sample.php en config.php et renseignez la base de données.');
        }
        $c = require $f;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg()['db'];
        $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function schema_sql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid CHAR(40) NOT NULL,
            path VARCHAR(1024) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            folder VARCHAR(1024) NOT NULL DEFAULT '',
            taken_at DATETIME NULL,
            year SMALLINT NULL,
            camera VARCHAR(128) NULL,
            lens VARCHAR(160) NULL,
            focal FLOAT NULL,
            aperture FLOAT NULL,
            exposure VARCHAR(24) NULL,
            iso INT NULL,
            width INT NULL,
            height INT NULL,
            orientation VARCHAR(10) NULL,
            rating TINYINT NULL,
            label VARCHAR(32) NULL,
            keywords TEXT NULL,
            title VARCHAR(255) NULL,
            caption TEXT NULL,
            city VARCHAR(128) NULL,
            region VARCHAR(128) NULL,
            country VARCHAR(128) NULL,
            location VARCHAR(255) NULL,
            lat DOUBLE NULL,
            lon DOUBLE NULL,
            ai_description TEXT NULL,
            ai_tags TEXT NULL,
            ai_text TEXT NULL,
            ai_model VARCHAR(80) NULL,
            thumb_w SMALLINT NULL,
            thumb_h SMALLINT NULL,
            has_medium TINYINT(1) NOT NULL DEFAULT 0,
            file_size BIGINT NULL,
            search_text MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_uid (uid),
            KEY k_taken (taken_at),
            KEY k_year (year),
            KEY k_camera (camera),
            KEY k_rating (rating),
            FULLTEXT KEY ft_search (search_text)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        PUBLICATIONS_SQL,
    ];
}

const PUBLICATIONS_SQL = "CREATE TABLE IF NOT EXISTS publications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid CHAR(40) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'attente',
            network VARCHAR(30) NOT NULL DEFAULT 'instagram',
            tone VARCHAR(30) NOT NULL DEFAULT 'naturel',
            hashtags TINYINT(1) NOT NULL DEFAULT 1,
            instructions TEXT NULL,
            text TEXT NULL,
            error VARCHAR(500) NULL,
            model VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            claimed_at DATETIME NULL,
            published_at DATETIME NULL,
            KEY k_uid (uid),
            KEY k_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/** Crée la table des publications si le site a été installé avant son apparition. */
function ensure_publications(): void
{
    static $done = false;
    if (!$done) {
        db()->exec(PUBLICATIONS_SQL);
        $done = true;
    }
}

const NETWORKS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X / Threads', 'autre' => 'Autre'];
const TONES = ['naturel' => 'Naturel', 'poetique' => 'Poétique', 'humour' => 'Avec humour', 'technique' => 'Photographe (réglages)', 'inspirant' => 'Inspirant', 'sobre' => 'Sobre et court'];

function setting(string $k): ?string
{
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (PDOException $e) {
        return null;
    }
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

function is_installed(): bool
{
    return setting('password_hash') !== null;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Minuscules, sans accents, uniquement lettres/chiffres séparés par des espaces. */
function norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    static $tr = null;
    if ($tr === null && class_exists('Transliterator')) {
        $tr = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    }
    if ($tr) {
        $s = $tr->transliterate($s);
    } else {
        $s = strtr($s, [
            'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','æ'=>'ae','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o','œ'=>'oe',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','ß'=>'ss',
        ]);
    }
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

const MOIS = ['', 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

function saison(int $m): string
{
    return match (true) {
        $m === 12 || $m <= 2 => 'hiver',
        $m <= 5 => 'printemps',
        $m <= 8 => 'ete',
        default => 'automne',
    };
}

function json_list(?string $json): array
{
    if (!$json) return [];
    $v = json_decode($json, true);
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

/** Construit le texte indexé pour la recherche plein texte. */
function build_search_text(array $p): string
{
    $parts = [
        $p['filename'] ?? '', $p['folder'] ?? '', $p['title'] ?? '', $p['caption'] ?? '',
        implode(' ', json_list($p['keywords'] ?? null)),
        $p['ai_description'] ?? '', implode(' ', json_list($p['ai_tags'] ?? null)), $p['ai_text'] ?? '',
        $p['camera'] ?? '', $p['lens'] ?? '', $p['city'] ?? '', $p['region'] ?? '', $p['country'] ?? '',
        $p['location'] ?? '', $p['label'] ?? '', $p['orientation'] ?? '',
    ];
    if (!empty($p['taken_at'])) {
        $ts = strtotime($p['taken_at']);
        if ($ts) {
            $m = (int)date('n', $ts);
            $parts[] = date('Y', $ts) . ' ' . MOIS[$m] . ' ' . saison($m);
        }
    }
    return norm(implode(' ', $parts));
}

function media_path(string $uid, string $size): string
{
    return __DIR__ . '/media/' . $size . '/' . substr($uid, 0, 2) . '/' . $uid . '.jpg';
}

function is_logged_in(): bool
{
    return !empty($_SESSION['photos_ok']);
}

function require_login(bool $json = false): void
{
    if (!is_installed()) {
        header('Location: install.php');
        exit;
    }
    if (!is_logged_in()) {
        if ($json) {
            json_out(['error' => 'non connecté'], 401);
        }
        header('Location: login.php');
        exit;
    }
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function site_title(): string
{
    return cfg()['site_title'] ?? 'Mes photos';
}
