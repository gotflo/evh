<?php
declare(strict_types=1);

namespace Evh;

final class Http
{
    public static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Refuse les requêtes envoyées depuis un autre site par un navigateur.
     * Les navigateurs ajoutent toujours l'en-tête Origin à un POST : s'il est
     * présent, il doit correspondre au site lui-même ou à une origine autorisée.
     */
    public static function isAllowedOrigin(Config $config): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return true;
        }

        $allowed = $config->list('ALLOWED_ORIGINS');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $allowed[] = 'https://' . $host;
            $allowed[] = 'http://' . $host;
        }

        return in_array(rtrim($origin, '/'), $allowed, true);
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Taille maximale d'un POST acceptée par PHP, en octets. */
    public static function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
