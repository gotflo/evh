<?php
declare(strict_types=1);

namespace Evh;

/**
 * Journal simple, un fichier par mois dans evh_private/logs.
 * On n'y écrit jamais de données personnelles : seulement des références
 * d'inscription et des messages techniques.
 */
final class Logger
{
    public function __construct(private readonly Config $config)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        try {
            $file = $this->config->privatePath('logs') . '/evh-' . date('Y-m') . '.log';
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            error_log(trim($line));
        }
    }
}
