<?php
declare(strict_types=1);

namespace Evh;

/**
 * Limitation du nombre de requêtes par clé (adresse IP hachée) sur une fenêtre
 * glissante. Stockage fichier : suffisant pour un hébergement mutualisé.
 */
final class RateLimiter
{
    public function __construct(private readonly Config $config)
    {
    }

    /** Enregistre une tentative. Renvoie false si la limite est dépassée. */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        $file = $this->config->privatePath('cache/ratelimit') . '/' . hash('sha256', $key) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            flock($handle, LOCK_EX);
            $now = time();
            $hits = json_decode((string) stream_get_contents($handle), true);
            $hits = array_values(array_filter(
                is_array($hits) ? $hits : [],
                static fn ($t) => is_int($t) && $t > $now - $windowSeconds
            ));

            if (count($hits) >= $max) {
                return false;
            }

            $hits[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($hits));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
