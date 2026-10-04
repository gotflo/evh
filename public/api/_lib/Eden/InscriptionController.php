<?php
declare(strict_types=1);

namespace Evh\Eden;

use Evh\Config;
use Evh\Http;
use Evh\Logger;
use Evh\RateLimiter;

/**
 * Couche HTTP de POST /api/eden/inscription.php.
 * Les messages renvoyés sont destinés au public : jamais de détail technique.
 */
final class InscriptionController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(): never
    {
        $logger = new Logger($this->config);

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            Http::json(405, ['ok' => false, 'message' => 'Méthode non autorisée.']);
        }

        if (!Http::isAllowedOrigin($this->config)) {
            $logger->info('Inscription Eden refusée : origine non autorisée', ['origin' => $_SERVER['HTTP_ORIGIN'] ?? '']);
            Http::json(403, ['ok' => false, 'message' => "Cette demande n'est pas autorisée."]);
        }

        // Au-delà de post_max_size, PHP vide $_POST et $_FILES sans prévenir.
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > Http::postMaxBytes()) {
            Http::json(413, ['ok' => false, 'message' => 'Les photos envoyées sont trop lourdes. Choisissez des images plus légères (10 Mo au maximum chacune).']);
        }

        $limiter = new RateLimiter($this->config);
        $max = $this->config->int('EDEN_LIMITE_PAR_HEURE', 10);
        if (!$limiter->attempt('eden-inscription|' . Http::clientIp(), $max, 3600)) {
            Http::json(429, ['ok' => false, 'message' => 'Trop de tentatives depuis votre connexion. Patientez une heure avant de réessayer, ou appelez l\'infoline.']);
        }

        // Champ piège invisible pour les humains : seuls les robots le remplissent.
        if (isset($_POST['site_web']) && $_POST['site_web'] !== '') {
            $logger->info('Inscription Eden ignorée : champ piège rempli');
            Http::json(200, ['ok' => true, 'reference' => null, 'courriel_candidat_echec' => false]);
        }

        try {
            $result = InscriptionService::fromConfig($this->config)->submit($_POST, $_FILES);
        } catch (\Throwable $e) {
            $logger->error('Inscription Eden en échec', ['type' => $e::class, 'erreur' => $e->getMessage()]);
            Http::json(500, [
                'ok' => false,
                'message' => "Votre inscription n'a pas pu être enregistrée à cause d'un problème technique de notre côté. Vos réponses sont toujours dans le formulaire : réessayez dans quelques minutes.",
            ]);
        }

        Http::json($result['status'], $result['body']);
    }
}
