<?php
declare(strict_types=1);

// Réception du formulaire d'inscription aux cours de mariage Eden.
// Toute la logique est dans _lib/Eden (dossier fermé au web).

ini_set('display_errors', '0');
ini_set('memory_limit', '384M');
set_time_limit(90);

$config = require dirname(__DIR__) . '/_lib/bootstrap.php';

(new Evh\Eden\InscriptionController($config))->handle();
