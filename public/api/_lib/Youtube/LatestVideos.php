<?php
declare(strict_types=1);

namespace Evh\Youtube;

use Evh\Config;
use Evh\Http;
use Evh\Logger;

/**
 * Dernières vidéos de la chaîne YouTube, lues dans le flux RSS public de la
 * chaîne : aucune clé API, aucun quota. Le résultat est gardé en cache dans
 * evh_private/cache pour ne pas interroger YouTube à chaque visite. Si YouTube
 * ne répond pas, on renvoie la dernière liste connue.
 */
final class LatestVideos
{
    private const CHANNEL_ID = 'UCy99K69O9BklRKZ67WbVKZg'; // @vasesdhonneurchicoutimi
    private const LIMIT = 5;
    private const FRESH_SECONDS = 600;   // on relit le flux au plus toutes les 10 minutes
    private const RETRY_SECONDS = 120;   // après un échec, nouvel essai 2 minutes plus tard
    private const BROWSER_MAX_AGE = 300;

    public function __construct(private readonly Config $config)
    {
    }

    public function handle(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            Http::json(405, ['error' => 'Méthode non permise.']);
        }

        $cacheFile = $this->cacheFile();
        $cached = $cacheFile !== null ? $this->readCache($cacheFile) : null;

        if ($cached !== null && time() - $cached['checkedAt'] < self::FRESH_SECONDS) {
            Http::json(200, $this->publicPayload($cached), self::BROWSER_MAX_AGE);
        }

        try {
            $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . self::CHANNEL_ID;
            $videos = self::parseFeed($this->download($url), self::LIMIT);
            if ($videos === []) {
                throw new \RuntimeException('aucune vidéo dans le flux');
            }

            $entry = ['checkedAt' => time(), 'fetchedAt' => time(), 'videos' => $videos];
            $this->writeCache($cacheFile, $entry);
            Http::json(200, $this->publicPayload($entry), self::BROWSER_MAX_AGE);
        } catch (\Throwable $e) {
            (new Logger($this->config))->error('YouTube : flux indisponible', ['message' => $e->getMessage()]);

            if ($cached === null) {
                Http::json(503, ['error' => 'Vidéos indisponibles pour le moment.']);
            }

            $cached['checkedAt'] = time() - self::FRESH_SECONDS + self::RETRY_SECONDS;
            $this->writeCache($cacheFile, $cached);
            Http::json(200, $this->publicPayload($cached), 60);
        }
    }

    /**
     * Extrait les vidéos du flux Atom de YouTube, la plus récente en premier.
     * Les Shorts sont écartés, ainsi que les doublons de titre (même culte
     * publié deux fois).
     *
     * @return list<array{id: string, title: string, published: string, views: int}>
     */
    public static function parseFeed(string $xml, int $limit): array
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('flux illisible');
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('a', 'http://www.w3.org/2005/Atom');
        $xp->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
        $xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');

        $videos = [];
        $seenTitles = [];
        foreach ($xp->query('/a:feed/a:entry') ?: [] as $entry) {
            $id = trim((string) $xp->evaluate('string(yt:videoId)', $entry));
            $link = (string) $xp->evaluate('string(a:link[@rel="alternate"]/@href)', $entry);
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id) || str_contains($link, '/shorts/')) {
                continue;
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(a:title)', $entry)));
            $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
            if ($title === '' || isset($seenTitles[$key])) {
                continue;
            }
            $seenTitles[$key] = true;

            $published = strtotime((string) $xp->evaluate('string(a:published)', $entry));
            $videos[] = [
                'id' => $id,
                'title' => $title,
                'published' => $published !== false ? date(DATE_ATOM, $published) : '',
                'views' => (int) $xp->evaluate('string(media:group/media:community/media:statistics/@views)', $entry),
            ];

            if (count($videos) >= $limit) {
                break;
            }
        }

        return $videos;
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; EVH-site/1.0)',
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            unset($ch);

            if (!is_string($body) || $status !== 200) {
                throw new \RuntimeException(sprintf('HTTP %d %s', $status, $error));
            }

            return $body;
        }

        $context = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Mozilla/5.0 (compatible; EVH-site/1.0)']]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('téléchargement impossible');
        }

        return $body;
    }

    private function cacheFile(): ?string
    {
        try {
            return $this->config->privatePath('cache') . '/youtube-latest.json';
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{checkedAt: int, fetchedAt: int, videos: list<array<string, mixed>>}|null */
    private function readCache(string $file): ?array
    {
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data) || !is_int($data['checkedAt'] ?? null) || !is_int($data['fetchedAt'] ?? null)
            || !is_array($data['videos'] ?? null) || $data['videos'] === []) {
            return null;
        }

        return $data;
    }

    private function writeCache(?string $file, array $entry): void
    {
        if ($file === null) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $file);
        }
        @unlink($tmp);
    }

    private function publicPayload(array $entry): array
    {
        return ['updatedAt' => date(DATE_ATOM, $entry['fetchedAt']), 'videos' => $entry['videos']];
    }
}
