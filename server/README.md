# Backend PHP du site (formulaire Eden)

Le site reste un site statique Astro. Seul le formulaire d'inscription aux cours
de mariage Eden a besoin d'un serveur : un petit backend PHP, livré avec le site
dans `public/api/` (donc copié dans `dist/api/` à chaque `npm run build`).

```
public/api/
  .htaccess                 pas de listing des dossiers, en-têtes de sécurité
  eden/inscription.php      seule adresse publique : reçoit le formulaire
  _lib/                     code interne, interdit au web (.htaccess)
    bootstrap.php           chargement + emplacement du dossier privé
    Config, Database, Mailer, Logger, RateLimiter, Http, Phone
    Eden/                   validation, photos, enregistrement, courriels
    cli/eden-relancer-courriels.php   renvoi des courriels en échec (Cron)
    vendor/PHPMailer/       envoi SMTP (PHPMailer 6.10, licence LGPL)
server/
  eden-schema.sql           table MySQL à importer une fois
  evh_private.env.example   modèle de configuration privée
```

Prérequis serveur : PHP 8.1 ou plus, extensions `pdo_mysql`, `gd` (avec WebP),
`fileinfo`, `mbstring`, `openssl`. Elles sont actives par défaut chez Hostinger.

## Le dossier privé `evh_private`

Mots de passe, photos en attente et journaux ne doivent jamais être accessibles
depuis le web. Ils vivent dans un dossier `evh_private` placé **à côté** de
`public_html`, jamais dedans :

```
domains/vasesdhonneurchicoutimi.org/
  public_html/          le contenu de dist/
  evh_private/
    .env                la configuration (copiée depuis server/evh_private.env.example)
    eden/photos/        photos en attente d'envoi (vidé automatiquement)
    logs/               journal mensuel, sans données personnelles
    cache/ratelimit/    compteurs anti-abus
```

Les sous-dossiers sont créés tout seuls au premier envoi. Seul `.env` est à créer.

## Mise en production sur Hostinger

1. **Version de PHP** : hPanel > Avancé > Configuration PHP, choisir 8.1 ou plus.
   Dans l'onglet « Options PHP », vérifier `upload_max_filesize` au moins `10M`
   et `post_max_size` au moins `25M`.
2. **Base de données** : hPanel > Bases de données > Gestion. Créer une base et
   son utilisateur, puis phpMyAdmin > la base > Importer > `server/eden-schema.sql`.
3. **Boîte d'envoi** : hPanel > Emails. Créer (ou choisir) une adresse du domaine,
   par exemple `eden@vasesdhonneurchicoutimi.org`. Elle servira d'expéditeur.
4. **Configuration** : avec le Gestionnaire de fichiers, créer le dossier
   `evh_private` à côté de `public_html`, puis y créer le fichier `.env` à partir
   de `server/evh_private.env.example` (base, mot de passe de la boîte d'envoi...).
5. **Site** : `npm run build`, puis envoyer le **contenu** de `dist/` dans
   `public_html/`, comme d'habitude. Le dossier `api/` en fait partie.
6. **Relance des courriels** (conseillé) : hPanel > Avancé > Tâches Cron, une fois
   par heure :
   `/usr/bin/php /home/<compte>/domains/vasesdhonneurchicoutimi.org/public_html/api/_lib/cli/eden-relancer-courriels.php`

## Phase de test, puis vraies adresses

Le modèle `.env` est livré en **phase de test** : toutes les inscriptions arrivent
à une seule adresse, sans copie. Une fois tout validé, remplacer dans
`evh_private/.env` les lignes `EDEN_EMAIL_TO` et `EDEN_EMAIL_CC` par les vraies
adresses (déjà écrites en commentaire juste en dessous). Aucune autre modification
ni aucun nouveau build n'est nécessaire : le `.env` est relu à chaque envoi.

## Vérifier après la mise en ligne

- `https://<domaine>/api/_lib/Config.php` doit afficher une erreur **403**.
- `https://<domaine>/api/eden/inscription.php` ouvert dans le navigateur doit
  répondre « Méthode non autorisée » (405) : PHP fonctionne.
- Faire une inscription d'essai depuis `/eden-inscription.html` :
  - la page de confirmation affiche un numéro `EDEN-AAAA-NNNN` ;
  - deux courriels arrivent : celui de l'équipe (avec les 2 photos en pièces
    jointes) et la confirmation du candidat. Regarder aussi les indésirables ;
  - phpMyAdmin : une ligne dans `eden_inscriptions`, colonnes
    `courriel_equipe_statut` et `courriel_candidat_statut` à `envoye` ;
  - `evh_private/eden/photos/` ne contient plus rien (photos supprimées après envoi).
- En cas de souci, lire `evh_private/logs/evh-AAAA-MM.log`.

Pour éviter que les courriels finissent en indésirables, vérifier dans hPanel >
Emails que les enregistrements DNS SPF et DKIM du domaine sont actifs.

## Consulter les inscriptions

Il n'y a pas d'écran d'administration : les inscriptions arrivent par courriel et
restent consultables dans phpMyAdmin (table `eden_inscriptions`, colonnes en
français). La colonne `paiement_statut` vaut `en_attente` : l'équipe peut la
passer à `paye` à la main après réception du virement.

## Tests

Tests des règles de validation (navigateur) :

```bash
npm test
```

Tests d'intégration du backend (requêtes HTTP réelles, MySQL, courriels) : ils
demandent un MySQL local avec une base de test, Mailpit (faux serveur SMTP, fourni
avec Laragon) et un dossier privé de test dont le `.env` contient `APP_ENV=local`,
`MAIL_DRIVER=smtp`, `SMTP_HOST=127.0.0.1`, `SMTP_PORT=1025`, `SMTP_SECURE=none`.
Ils vident la table et la boîte Mailpit : base de test uniquement.

```bash
npm run build
EVH_PRIVATE_DIR=/chemin/evh_private_test php -S 127.0.0.1:8099 -t dist
EVH_PRIVATE_DIR=/chemin/evh_private_test php tests/eden/api.test.php
```
