<?php
require __DIR__ . '/lib.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hash = setting('password_hash');
    if ($hash && password_verify((string)($_POST['p'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['photos_ok'] = true;
        header('Location: index.php');
        exit;
    }
    sleep(2); // ralentit les essais de mot de passe
    $error = 'Mot de passe incorrect.';
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Connexion · <?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="page-form">
<main class="card">
  <h1><?= h(site_title()) ?></h1>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <label>Mot de passe<input type="password" name="p" required autofocus autocomplete="current-password"></label>
    <button class="btn" type="submit">Entrer</button>
  </form>
</main>
</body>
</html>
