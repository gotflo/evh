<?php
declare(strict_types=1);

// Renvoie les courriels Eden qui n'ont pas pu partir (panne SMTP, mot de passe changé...).
// Ligne de commande uniquement, par exemple dans une tâche Cron Hostinger :
//   php /home/<compte>/domains/<domaine>/public_html/api/_lib/cli/eden-relancer-courriels.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap.php';

$service = Evh\Eden\InscriptionService::fromConfig($config);
$repository = new Evh\Eden\InscriptionRepository(Evh\Database::connect($config));

$rows = $repository->findWithUnsentMail(5);
foreach ($rows as $row) {
    $service->deliver($row);
    $after = $repository->find((int) $row['id']);
    printf(
        "%s : équipe %s, candidat %s\n",
        $row['reference'],
        $after['courriel_equipe_statut'] ?? '?',
        $after['courriel_candidat_statut'] ?? '?'
    );
}

printf("%d inscription(s) traitée(s).\n", count($rows));
