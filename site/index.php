<?php
require __DIR__ . '/lib.php';
require_login();

$cameras = db()->query("SELECT camera, COUNT(*) n FROM photos WHERE camera IS NOT NULL GROUP BY camera ORDER BY n DESC LIMIT 60")->fetchAll();
$lenses = db()->query("SELECT lens, COUNT(*) n FROM photos WHERE lens IS NOT NULL GROUP BY lens ORDER BY n DESC LIMIT 80")->fetchAll();
$years = db()->query("SELECT DISTINCT year FROM photos WHERE year IS NOT NULL ORDER BY year")->fetchAll(PDO::FETCH_COLUMN);
$moisLabels = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head>
<body>
<header class="top">
  <a class="brand" href="index.php"><?= h(site_title()) ?></a>
  <form id="f" class="search" autocomplete="off" onsubmit="return false">
    <div class="q">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" name="q" id="q" placeholder="Rechercher : plage coucher de soleil, chien neige, mariage 2015…" aria-label="Rechercher">
    </div>
    <button type="button" id="toggleFilters" class="btn ghost" aria-expanded="false">Filtres <span id="nf"></span></button>
    <div class="filters" id="filters" hidden>
      <label>De
        <select name="y1"><option value="">—</option><?php foreach ($years as $y): ?><option><?= (int)$y ?></option><?php endforeach; ?></select>
      </label>
      <label>À
        <select name="y2"><option value="">—</option><?php foreach ($years as $y): ?><option><?= (int)$y ?></option><?php endforeach; ?></select>
      </label>
      <label>Mois
        <select name="month"><option value="">Tous</option><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $moisLabels[$i] ?></option><?php endfor; ?></select>
      </label>
      <label>Appareil
        <select name="camera"><option value="">Tous</option><?php foreach ($cameras as $c): ?><option value="<?= h($c['camera']) ?>"><?= h($c['camera']) ?> (<?= (int)$c['n'] ?>)</option><?php endforeach; ?></select>
      </label>
      <label>Objectif
        <select name="lens"><option value="">Tous</option><?php foreach ($lenses as $c): ?><option value="<?= h($c['lens']) ?>"><?= h($c['lens']) ?> (<?= (int)$c['n'] ?>)</option><?php endforeach; ?></select>
      </label>
      <label>Note
        <select name="rating"><option value="">Toutes</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> et +</option><?php endfor; ?></select>
      </label>
      <label>Format
        <select name="orient"><option value="">Tous</option><option value="paysage">Paysage</option><option value="portrait">Portrait</option><option value="carre">Carré</option><option value="panorama">Panorama</option></select>
      </label>
      <label>Dossier contient<input type="text" name="folder" placeholder="ex. Vacances"></label>
      <label>Tri
        <select name="sort"><option value="">Automatique</option><option value="pertinence">Pertinence</option><option value="recent">Plus récentes</option><option value="ancien">Plus anciennes</option><option value="note">Mieux notées</option><option value="ajout">Ajout récent</option></select>
      </label>
      <label class="check"><input type="checkbox" name="gps" value="1"> Avec position GPS</label>
      <label class="check"><input type="checkbox" name="ia" value="0"> Pas encore analysées</label>
      <input type="hidden" name="day">
      <button type="button" id="reset" class="btn ghost">Tout effacer</button>
    </div>
  </form>
  <nav class="tabs"><a href="index.php" class="on">Rechercher</a><a href="publier.php">Publier</a></nav>
  <a class="icon-link" href="reglages.php" title="Réglages" aria-label="Réglages">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
  </a>
</header>

<nav class="years" id="years" aria-label="Années"></nav>
<div class="status"><span id="count"></span><span id="active"></span></div>
<main id="grid" class="grid" aria-live="polite"></main>
<div id="sentinel"></div>
<p id="empty" class="empty" hidden>Aucune photo ne correspond. Essayez un autre mot, ou retirez un filtre.</p>

<div id="lb" class="lb" hidden role="dialog" aria-modal="true" aria-label="Photo">
  <button class="lb-close" id="lbClose" aria-label="Fermer">×</button>
  <button class="lb-nav prev" id="lbPrev" aria-label="Précédente">‹</button>
  <figure class="lb-img"><img id="lbImg" alt=""></figure>
  <button class="lb-nav next" id="lbNext" aria-label="Suivante">›</button>
  <aside class="lb-info" id="lbInfo"></aside>
</div>

<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
