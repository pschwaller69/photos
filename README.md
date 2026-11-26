# Mes photos : recherche et publication

Une application pour retrouver ses photos en tapant ce qu'on cherche (« plage coucher de soleil », « chien neige 2019 », « anniversaire Léa »…) et pour préparer ses publications sur les réseaux sociaux depuis son téléphone.

Elle est faite de deux parties :

| Partie | Où | Rôle |
|---|---|---|
| `indexeur/` | sur le PC Windows | Parcourt le NAS, lit les infos de Lightroom (EXIF, mots-clés, notes, lieux), fait décrire chaque photo par l'IA locale (Ollama), puis envoie au site **une miniature + un aperçu + les infos**. Rédige aussi les textes de publication. |
| `site/` | sur o2switch | Galerie protégée par mot de passe, recherche plein texte, filtres (année, appareil, objectif, note…), visionneuse, et la page **Publier** pour le téléphone. |

Les **originaux restent sur le NAS** : rien n'est envoyé en pleine résolution. Le site ne fait que de la recherche et de l'affichage, toute l'IA tourne sur le PC (la RTX 4070 fait le travail, o2switch n'a pas de carte graphique).

```
 NAS (originaux)  ──lecture──▶  PC Windows : photoindex.py + Ollama
                                   │  miniatures, aperçus, EXIF, description IA
                                   ▼
                              o2switch : site PHP + MySQL  ◀──  navigateur / téléphone
                                   ▲
                                   │  « rédige un texte pour cette photo »
 PC : photoindex.py --veille  ─────┘  (le PC récupère la demande, Ollama écrit, le texte revient)
```

---

## 1. Installer le site sur o2switch

1. **Créer la base de données** : cPanel ▸ *Bases de données MySQL*. Créez une base (ex. `moncompte_photos`), un utilisateur, puis ajoutez l'utilisateur à la base avec *Tous les privilèges*.
2. **Créer le sous-domaine** (conseillé) : cPanel ▸ *Domaines*, par exemple `photos.mondomaine.fr`. Activez le certificat SSL (cPanel ▸ *Let's Encrypt SSL* ou *SSL/TLS Status*) : le site doit être en **https**.
3. **Envoyer les fichiers** : copiez tout le contenu du dossier `site/` dans le dossier du sous-domaine (gestionnaire de fichiers cPanel, ou FTP avec FileZilla).
4. **Configurer** : dans ce dossier, copiez `config.sample.php` en `config.php` et remplissez le nom de la base, l'utilisateur et le mot de passe MySQL.
5. **Installer** : ouvrez `https://photos.mondomaine.fr/install.php`, choisissez votre mot de passe. La page affiche une **clé de synchronisation** : gardez-la pour l'étape 2 (elle reste visible dans *Réglages*).
6. **Supprimez `install.php`** du serveur.

PHP 8.1 ou plus récent est nécessaire (cPanel ▸ *Sélecteur de version PHP*, 8.2 ou 8.3 conseillé, avec l'extension `intl` cochée si elle ne l'est pas).

## 2. Préparer le PC Windows

1. **Python** : installez Python 3.11 ou plus récent depuis python.org (cochez *Add python.exe to PATH* pendant l'installation).
2. **exiftool** : téléchargez la version Windows sur <https://exiftool.org>, dézippez, renommez `exiftool(-k).exe` en `exiftool.exe` et placez-le **dans le dossier `indexeur`** (avec son dossier `exiftool_files` s'il y en a un).
3. **Le modèle IA** : dans une invite de commandes :
   ```
   ollama pull gemma3:12b
   ```
   Avec la RTX 4070 (12 Go), `gemma3:12b` donne de bonnes descriptions en français, en quelques secondes par photo. Pour aller plus vite au détriment de la finesse : `qwen2.5vl:7b`.
4. **Les modules Python** : ouvrez une invite de commandes dans le dossier `indexeur` et lancez
   ```
   pip install -r requirements.txt
   ```
5. **La configuration** : copiez `config.example.ini` en `config.ini` puis renseignez :
   - `dossiers` : le chemin de vos photos sur le NAS, par exemple `\\NAS\photo` ou `Z:\Photos` ;
   - `url` : l'adresse du site (`https://photos.mondomaine.fr`) ;
   - `cle_api` : la clé affichée à l'installation du site ;
   - `catalogue` *(facultatif mais conseillé)* : le chemin de votre catalogue Lightroom `.lrcat`, voir plus bas.
6. **Vérifier** :
   ```
   python photoindex.py --test
   ```
   Chaque ligne doit afficher `OK`.

## 3. Première indexation

Faites d'abord un essai sur quelques photos :

```
python photoindex.py --limite 20
```

Ouvrez le site : les 20 photos sont là, avec leur description. Si tout vous convient, lancez l'indexation complète (double-clic sur `lancer.bat`). Comptez **3 à 8 secondes par photo** avec la RTX 4070 : 20 000 photos prennent donc une à deux nuits. Vous pouvez interrompre à tout moment (Ctrl+C ou fermer la fenêtre) : au lancement suivant, le script reprend là où il s'était arrêté.

Pour aller plus vite la première fois, vous pouvez tout envoyer sans IA (`python photoindex.py --sans-ia`, quelques minutes), chercher déjà par date / appareil / mots-clés Lightroom, puis relancer normalement pour que l'IA complète les descriptions.

### Ensuite, automatiquement

Le script ne traite que les nouveautés : photos ajoutées, mots-clés ou notes modifiés dans Lightroom. Pour le lancer chaque nuit : *Planificateur de tâches* Windows ▸ *Créer une tâche de base* ▸ tous les jours à 3 h ▸ *Démarrer un programme* ▸ `lancer-planifie.bat` (le journal est écrit dans `planifie.log`).

Si vous supprimez des photos du NAS, `python photoindex.py --nettoyer` les retire aussi du site (par sécurité, il refuse si plus de 20 % des photos auraient disparu, par exemple si le NAS est mal monté).

## 4. Lightroom

Le script récupère les infos de Lightroom de deux façons, et combine les deux :

- **Dans les fichiers** : mots-clés, notes, étiquettes, titres, légendes et lieux écrits dans les JPEG. Lightroom ne les écrit dans le fichier que si l'option *Catalogue ▸ Paramètres ▸ Métadonnées ▸ Enregistrer automatiquement les modifications dans XMP* est cochée (ou après *Métadonnées ▸ Enregistrer les métadonnées dans le fichier*, Ctrl+S).
- **Dans le catalogue** (`catalogue = ...` dans `config.ini`) : le script en lit une copie, donc Lightroom peut rester ouvert et rien n'est modifié. C'est la solution la plus complète si vous n'écrivez pas les métadonnées dans les fichiers. Si Lightroom voit le NAS sous un autre chemin (par exemple `Z:\Photos` au lieu de `\\NAS\photo`), le script s'en sort tout seul la plupart du temps ; sinon, ajoutez une ligne dans `correspondances`.

La lecture du catalogue est prévue pour Lightroom Classic. Si votre version a une structure différente, le script l'indique dans le journal et continue avec les métadonnées des fichiers.

## 5. Rechercher

- Tapez des mots, en français : `plage`, `chien neige`, `mariage 2015`, `bretagne été`. Tous les mots doivent correspondre. Les accents et le pluriel n'ont pas d'importance.
- `"coucher de soleil"` entre guillemets cherche l'expression exacte ; `-chien` exclut un mot.
- On peut chercher par mois ou saison (`août`, `hiver`), par appareil (`6D`), par nom de dossier, par lieu, par mot-clé Lightroom.
- La frise des années permet de filtrer d'un clic ; *Filtres* propose année, mois, appareil, objectif, note, format, dossier, GPS.
- Dans la visionneuse : flèches du clavier ou glissement sur mobile, clic sur un mot-clé pour chercher les photos semblables, *voir la journée* pour afficher toute la série prise ce jour-là, et le chemin de l'original sur le NAS à copier.

## 6. Publier depuis le téléphone

La page **Publier** (onglet en haut) remplace l'ancienne application WinDev :

1. Elle propose des photos **au hasard** (filtrables par note, année ou thème, et sans celles déjà publiées). *Autres photos* en tire de nouvelles.
2. Touchez une photo, choisissez le réseau (Instagram, Facebook, X/Threads), le ton (naturel, poétique, humour, photographe avec réglages, inspirant, sobre), les hashtags, et ajoutez une consigne si vous voulez (« c'était notre premier voyage en famille »).
3. *Rédiger le texte* : la demande part vers le PC, Ollama regarde la photo et écrit le texte avec la date, le lieu et les mots-clés. Le texte apparaît sur le téléphone en quelques secondes, modifiable.
4. *Partager…* ouvre le menu de partage du téléphone avec la photo (Instagram, Facebook, WhatsApp…) ; le texte est copié, il suffit de le coller. *Marquer comme publiée* évite qu'elle soit reproposée.

Pour que le PC réponde, il faut que **`lancer-veille.bat` tourne**. Le plus simple : appuyez sur Win+R, tapez `shell:startup`, et déposez-y un raccourci vers `lancer-veille.bat` ; il démarrera avec Windows. Si le PC est éteint, la demande attend : le texte sera rédigé à son retour et apparaîtra dans l'historique. La page indique si le PC est en ligne.

Astuce : sur le téléphone, *Ajouter à l'écran d'accueil* depuis le navigateur donne une icône comme une application.

## Place utilisée sur o2switch

Par photo : environ 50 Ko de miniature et 300 à 400 Ko d'aperçu (1 600 px). Pour 30 000 photos, comptez une dizaine de Go. Pour réduire, baissez `apercu_taille` (ex. 1200) dans `config.ini`, puis relancez `python photoindex.py --forcer` pour renvoyer les aperçus. La page *Réglages* du site indique l'espace utilisé.

## Sécurité et vie privée

- Tout le site est protégé par mot de passe, y compris les images (elles ne sont pas accessibles par une adresse directe), et il n'est pas référencé par les moteurs de recherche.
- Les miniatures et aperçus ne contiennent plus aucune donnée EXIF (ni GPS). La position GPS reste visible uniquement dans la fiche de la photo, une fois connecté.
- Les photos ne passent jamais par un service d'IA externe : tout est analysé sur le PC.

## Options du script

```
python photoindex.py --test          vérifie la configuration
python photoindex.py                 indexe les nouveautés
python photoindex.py --limite 50     n'en traite que 50
python photoindex.py --dossier "\\NAS\photo\2019"   seulement ce dossier
python photoindex.py --sans-ia       sans analyse IA (rapide)
python photoindex.py --reanalyser    refait l'analyse IA (après un changement de modèle)
python photoindex.py --forcer        renvoie tout (après un changement de taille d'aperçu)
python photoindex.py --nettoyer      retire du site les photos supprimées du NAS
python photoindex.py --veille        rédige les textes de publication demandés depuis le téléphone
```

Le fichier `journal.log` garde la trace de chaque passage et des erreurs. `etat.sqlite` mémorise ce qui a déjà été traité : si vous le supprimez, tout sera renvoyé, mais les analyses IA déjà faites seront perdues (il faudra les refaire).
