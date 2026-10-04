<?php
declare(strict_types=1);

// Point de départ commun des scripts PHP du site (API et tâches en ligne de commande).
//
// Le code vit dans public_html/api/_lib (dossier interdit au web par .htaccess).
// Les secrets, les photos et les journaux vivent dans un dossier privé situé
// HORS de public_html : par défaut « evh_private », à côté de public_html.
// La variable d'environnement EVH_PRIVATE_DIR permet de le placer ailleurs.

spl_autoload_register(static function (string $class): void {
    $namespaces = [
        'Evh\\' => __DIR__ . '/',
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/vendor/PHPMailer/',
    ];
    foreach ($namespaces as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

date_default_timezone_set('America/Toronto');
mb_internal_encoding('UTF-8');

$privateDir = getenv('EVH_PRIVATE_DIR') ?: dirname(__DIR__, 3) . '/evh_private';

return Evh\Config::load($privateDir);
