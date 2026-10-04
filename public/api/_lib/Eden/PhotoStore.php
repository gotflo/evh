<?php
declare(strict_types=1);

namespace Evh\Eden;

use Evh\Config;

/**
 * Photos des candidats.
 *
 * Chaque photo est contrôlée (taille, type réel lu dans le fichier, dimensions),
 * puis entièrement redessinée en JPEG avec GD. Le fichier enregistré ne contient
 * donc plus rien de l'original : ni métadonnées (position GPS), ni contenu caché.
 *
 * Les photos ne sont pas conservées : elles attendent hors de public_html, sous
 * un nom aléatoire, le temps d'être jointes au courriel de l'équipe, puis sont
 * supprimées. Elles ne restent que si cet envoi échoue, jusqu'à la relance.
 */
final class PhotoStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_PIXELS = 50_000_000;
    private const MIN_SIDE = 200;
    private const OUTPUT_SIDE = 1600;

    private const TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Vérifie un fichier reçu (entrée de $_FILES) sans l'enregistrer.
     *
     * @return array{tmp: string, type: int, width: int, height: int}
     * @throws PhotoException
     */
    public function inspect(mixed $file): array
    {
        if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
            throw new PhotoException('Ajoutez une photo.');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new PhotoException('Ajoutez une photo.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new PhotoException('Cette photo dépasse 10 Mo. Choisissez une image plus légère.');
            default:
                throw new PhotoException("La photo n'a pas pu être reçue. Réessayez.");
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new PhotoException("La photo n'a pas pu être reçue. Réessayez.");
        }

        $size = filesize($tmp);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new PhotoException('Cette photo dépasse 10 Mo. Choisissez une image plus légère.');
        }

        // Type réel, lu dans le contenu du fichier (jamais celui annoncé par le navigateur).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset(self::TYPES[$mime]) || $info === false || $info[2] !== self::TYPES[$mime]) {
            throw new PhotoException('Format non accepté. Choisissez une photo JPG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            throw new PhotoException('Cette photo est trop petite. Choisissez une image plus nette.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new PhotoException('Cette photo est trop grande. Choisissez une image plus légère.');
        }

        return ['tmp' => $tmp, 'type' => $info[2], 'width' => $width, 'height' => $height];
    }

    /**
     * Redessine la photo en JPEG et l'enregistre. Renvoie le chemin relatif.
     *
     * @param array{tmp: string, type: int, width: int, height: int} $photo
     * @throws PhotoException
     */
    public function save(array $photo): string
    {
        $source = match ($photo['type']) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($photo['tmp']),
            IMAGETYPE_PNG => @imagecreatefrompng($photo['tmp']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($photo['tmp']),
            default => false,
        };
        if ($source === false) {
            throw new PhotoException('Cette photo semble endommagée. Choisissez une autre image.');
        }

        if ($photo['type'] === IMAGETYPE_JPEG) {
            $source = $this->applyOrientation($source, $photo['tmp']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, self::OUTPUT_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        unset($source);

        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
        $absolute = $this->config->privatePath('eden/photos/' . date('Y/m')) . '/' . basename($relative);

        $written = imagejpeg($canvas, $absolute, 85);
        unset($canvas);
        if (!$written) {
            throw new \RuntimeException('Écriture de la photo impossible.');
        }
        @chmod($absolute, 0640);

        return $relative;
    }

    public function absolutePath(string $relative): string
    {
        if (!preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#', $relative)) {
            throw new \InvalidArgumentException('Chemin de photo invalide.');
        }

        return $this->config->privatePath('eden/photos') . '/' . $relative;
    }

    public function delete(?string $relative): void
    {
        if ($relative !== null && $relative !== '') {
            @unlink($this->absolutePath($relative));
        }
    }

    /** Remet droite une photo de téléphone selon son orientation EXIF. */
    private function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        return $rotated;
    }
}
