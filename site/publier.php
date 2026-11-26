<?php
require __DIR__ . '/lib.php';
require_login();
ensure_publications();
$years = db()->query("SELECT DISTINCT year FROM photos WHERE year IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#111214">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Publier · <?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="pub">
<header class="top">
  <a class="brand" href="index.php"><?= h(site_title()) ?></a>
  <nav class="tabs"><a href="index.php">Rechercher</a><a href="publier.php" class="on">Publier</a></nav>
</header>

<section id="pick" class="pick">
  <div class="pick-filters">
    <select id="fRating" aria-label="Note minimale">
      <option value="">Toutes notes</option>
      <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> et +</option><?php endfor; ?>
    </select>
    <select id="fYear" aria-label="Année">
      <option value="">Toutes années</option>
      <?php foreach ($years as $y): ?><option><?= (int)$y ?></option><?php endforeach; ?>
    </select>
    <input id="fQ" type="search" placeholder="Thème (ex. mer, chien)" aria-label="Thème">
    <label class="check"><input id="fAll" type="checkbox"> Inclure déjà publiées</label>
  </div>
  <button id="shuffle" class="btn big" type="button">🎲 Autres photos</button>
  <p id="worker" class="worker"></p>
  <div id="pickGrid" class="pick-grid"></div>
  <p id="pickEmpty" class="empty" hidden>Aucune photo avec ces critères.</p>

  <details id="histBox" class="hist">
    <summary>Historique des textes</summary>
    <div id="hist"></div>
  </details>
</section>

<section id="sheet" class="sheet" hidden aria-label="Préparer la publication">
  <div class="sheet-bar">
    <button id="back" class="btn ghost small" type="button">← Photos</button>
  </div>
  <div class="sheet-body">
    <figure class="sheet-img"><img id="sImg" alt=""></figure>
    <div class="sheet-form">
      <p id="sMeta" class="muted"></p>
      <div class="seg" id="sNet" role="radiogroup" aria-label="Réseau">
        <?php foreach (NETWORKS as $k => $v): ?>
          <label><input type="radio" name="net" value="<?= h($k) ?>"<?= $k === 'instagram' ? ' checked' : '' ?>><span><?= h($v) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label class="field">Ton
        <select id="sTone"><?php foreach (TONES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select>
      </label>
      <label class="check"><input id="sTags" type="checkbox" checked> Ajouter des hashtags</label>
      <label class="field">Consigne (facultatif)
        <textarea id="sInstr" rows="2" placeholder="ex. c'était notre premier week-end en Bretagne, parle du vent"></textarea>
      </label>
      <button id="gen" class="btn big" type="button">✍️ Rédiger le texte</button>

      <div id="result" class="result" hidden>
        <p id="rStatus" class="r-status"></p>
        <textarea id="rText" rows="9" hidden></textarea>
        <div id="rActions" class="r-actions" hidden>
          <button id="share" class="btn" type="button">Partager…</button>
          <button id="copy" class="btn ghost" type="button">Copier le texte</button>
          <a id="dl" class="btn ghost" download>Télécharger la photo</a>
          <button id="again" class="btn ghost" type="button">Autre version</button>
          <button id="done" class="btn ghost" type="button">Marquer comme publiée</button>
        </div>
      </div>
      <div id="prev" class="prev"></div>
    </div>
  </div>
</section>

<div id="toast" class="toast" hidden></div>
<script src="assets/publier.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
