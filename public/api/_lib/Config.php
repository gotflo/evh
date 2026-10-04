<?php
declare(strict_types=1);

namespace Evh;

/**
 * Configuration lue dans evh_private/.env.
 * Une vraie variable d'environnement du serveur a priorité sur le fichier.
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(
        private readonly string $privateDir,
        private readonly array $values,
    ) {
    }

    public static function load(string $privateDir): self
    {
        $privateDir = rtrim($privateDir, '/\\');
        $file = $privateDir . '/.env';
        $values = is_file($file) ? self::parse((string) file_get_contents($file)) : [];

        return new self($privateDir, $values);
    }

    /** @return array<string, string> */
    public static function parse(string $content): array
    {
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $quoted = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0];
            $values[$key] = $quoted ? substr($value, 1, -1) : $value;
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return ($this->values[$key] ?? '') !== '' ? $this->values[$key] : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return ctype_digit($value) ? (int) $value : $default;
    }

    /** Liste séparée par des virgules. @return list<string> */
    public function list(string $key, string $default = ''): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key, $default)))));
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }

    /** Chemin absolu dans le dossier privé, créé au besoin. */
    public function privatePath(string $relative): string
    {
        $path = $this->privateDir . '/' . trim($relative, '/');
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new \RuntimeException('Dossier privé impossible à créer : ' . $relative);
        }

        // Garde-fou si le dossier privé se retrouvait par erreur dans public_html.
        $guard = $this->privateDir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }

        return $path;
    }
}
