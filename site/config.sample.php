<?php
// Copiez ce fichier en config.php et renseignez votre base MySQL o2switch
// (cPanel > Bases de données MySQL : créez une base, un utilisateur, et donnez-lui tous les droits).
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'moncompte_photos',
        'user' => 'moncompte_photos',
        'pass' => 'mot-de-passe-mysql',
    ],
    'site_title' => 'Mes photos',
    // Nombre de photos chargées à la fois dans la galerie
    'per_page' => 120,
];
