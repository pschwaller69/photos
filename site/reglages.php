<?php
require __DIR__ . '/lib.php';
require_login();

$msg = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    if (($_POST['action'] ?? '') === 'password') {
        $p1 = (string)($_POST['p1'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), (string)setting('password_hash'))) {
            $err = 'Ancien mot de passe incorrect.';
        } elseif (mb_strlen($p1) < 8 || $p1 !== ($_POST['p2'] ?? '')) {
            $err = 'Nouveau mot de passe trop court ou confirmation différente.';
        } else {
            set_setting('password_hash', password_hash($p1, PASSWORD_DEFAULT));
            $msg = 'Mot de passe changé.';
        }
    } elseif (($_POST['action'] ?? '') === 'token') {
        set_setting('api_token', bin2hex(random_bytes(24)));
        $msg = 'Nouvelle clé générée. Mettez à jour config.ini sur votre PC.';
    }
}
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));

$stats = db()->query("SELECT COUNT(*) n, SUM(ai_description IS NOT NULL AND ai_description <> '') ia,
    MIN(taken_at) mini, MAX(taken_at) maxi, MAX(updated_at) maj, COALESCE(SUM(file_size),0) taille FROM photos")->fetch();
$disk = 0;
foreach (['t', 'm'] as $d) {
    $dir = __DIR__ . '/media/' . $d;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $disk += $f->getSize();
        }
    }
}
$mo = fn($b) => number_format($b / 1048576, 0, ',', ' ') . ' Mo';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Réglages · <?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="page-form">
<main class="card wide">
  <p><a href="index.php">← Retour à la galerie</a></p>
  <h1>Réglages</h1>
  <?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>

  <h2>État</h2>
  <ul class="stats">
    <li><strong><?= number_format((int)$stats['n'], 0, ',', ' ') ?></strong> photos indexées, dont <?= number_format((int)$stats['ia'], 0, ',', ' ') ?> analysées par l'IA</li>
    <li>Prises entre <?= h($stats['mini'] ? date('d/m/Y', strtotime($stats['mini'])) : '–') ?> et <?= h($stats['maxi'] ? date('d/m/Y', strtotime($stats['maxi'])) : '–') ?></li>
    <li>Dernière synchronisation : <?= h($stats['maj'] ? date('d/m/Y H:i', strtotime($stats['maj'])) : 'jamais') ?></li>
    <li>Espace utilisé par les aperçus sur le serveur : <?= $mo($disk) ?> (originaux sur le NAS : <?= $mo((float)$stats['taille']) ?>)</li>
  </ul>

  <h2>Clé de synchronisation</h2>
  <p>À copier dans <code>config.ini</code> (ligne <code>cle_api</code>) sur votre PC.</p>
  <pre class="token"><?= h(setting('api_token')) ?></pre>
  <form method="post" onsubmit="return confirm('Générer une nouvelle clé ? L\'ancienne ne fonctionnera plus.')">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="token">
    <button class="btn ghost" type="submit">Générer une nouvelle clé</button>
  </form>

  <h2>Mot de passe</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="password">
    <label>Mot de passe actuel<input type="password" name="old" required></label>
    <label>Nouveau mot de passe<input type="password" name="p1" required minlength="8"></label>
    <label>Confirmation<input type="password" name="p2" required minlength="8"></label>
    <button class="btn" type="submit">Changer</button>
  </form>
  <p><a href="logout.php">Se déconnecter</a></p>
</main>
</body>
</html>
