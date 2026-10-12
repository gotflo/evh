<?php
declare(strict_types=1);

// Dernières vidéos YouTube pour la section « Derniers enseignements » de l'accueil.
// Toute la logique est dans _lib/Youtube (dossier fermé au web).

ini_set('display_errors', '0');

$config = require dirname(__DIR__) . '/_lib/bootstrap.php';

(new Evh\Youtube\LatestVideos($config))->handle();
