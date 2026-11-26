<?php
require __DIR__ . '/lib.php';

$error = null;
$token = null;

try {
    db();
} catch (Throwable $e) {
    $error = 'Connexion à la base impossible. Vérifiez config.php. Détail : ' . $e->getMessage();
}

if (!$error && is_installed()) {
    header('Location: index.php');
    exit;
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['p1'] ?? '');
    $p2 = (string)($_POST['p2'] ?? '');
    if (mb_strlen($p1) < 8) {
        $error = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($p1 !== $p2) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        foreach (schema_sql() as $sql) {
            db()->exec($sql);
        }
        foreach (['t', 'm'] as $d) {
            @mkdir(__DIR__ . '/media/' . $d, 0755, true);
        }
        $token = bin2hex(random_bytes(24));
        set_setting('api_token', $token);
        set_setting('password_hash', password_hash($p1, PASSWORD_DEFAULT));
        $_SESSION['photos_ok'] = true;
    }
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation · Mes photos</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="page-form">
<main class="card">
  <h1>Installation</h1>
  <?php if ($token): ?>
    <p class="ok">C'est installé. Voici la <strong>clé de synchronisation</strong> à copier dans le fichier <code>config.ini</code> du script sur votre PC (ligne <code>cle_api</code>) :</p>
    <pre class="token"><?= h($token) ?></pre>
    <p>Vous la retrouverez plus tard dans la page Réglages.</p>
    <p class="warn">Pensez à supprimer <code>install.php</code> du serveur.</p>
    <p><a class="btn" href="index.php">Ouvrir la galerie</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
    <?php if (!str_starts_with((string)$error, 'Connexion')): ?>
    <p>Choisissez le mot de passe qui protégera l'accès à vos photos.</p>
    <form method="post">
      <label>Mot de passe<input type="password" name="p1" required minlength="8" autofocus></label>
      <label>Confirmation<input type="password" name="p2" required minlength="8"></label>
      <button class="btn" type="submit">Installer</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
