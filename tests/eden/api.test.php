<?php
declare(strict_types=1);

/*
 * Tests d'intégration du formulaire Eden : vraies requêtes HTTP vers
 * api/eden/inscription.php, vérifications dans MySQL et dans Mailpit.
 *
 * Prérequis (voir server/README.md, section « Tests ») :
 *  - npm run build, puis le site servi par PHP :
 *      EVH_PRIVATE_DIR=<dossier de test> php -S 127.0.0.1:8099 -t dist
 *  - MySQL avec la table eden_inscriptions (server/eden-schema.sql)
 *  - Mailpit (SMTP 1025, interface 8025)
 *
 * Lancer : EVH_PRIVATE_DIR=<dossier de test> php tests/eden/api.test.php
 * ATTENTION : vide la table eden_inscriptions et la boîte Mailpit. Base de test uniquement.
 */

$privateDir = getenv('EVH_PRIVATE_DIR') ?: exit("EVH_PRIVATE_DIR doit pointer vers le dossier privé de TEST.\n");
$baseUrl = getenv('EDEN_TEST_URL') ?: 'http://127.0.0.1:8099';
$mailpit = getenv('EDEN_TEST_MAILPIT') ?: 'http://127.0.0.1:8025';
$endpoint = $baseUrl . '/api/eden/inscription.php';
$envFile = $privateDir . '/.env';
$originalEnv = file_get_contents($envFile);

require __DIR__ . '/../../public/api/_lib/bootstrap.php';
$config = Evh\Config::load($privateDir);
if ($config->isProduction()) {
    exit("Refus : APP_ENV=production dans {$envFile}. Utilisez une configuration de test.\n");
}
$pdo = Evh\Database::connect($config);

/* ------------------------------------------------------------------ outils */

$passed = 0;
$failed = 0;
$tmpDir = sys_get_temp_dir() . '/eden-tests-' . bin2hex(random_bytes(3));
mkdir($tmpDir);

function check(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok    {$label}\n";
    } else {
        $failed++;
        echo "  ÉCHEC {$label}\n";
    }
}

function scenario(string $title): void
{
    echo "\n{$title}\n";
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);

    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
}

function makeJpeg(string $path, int $w = 800, int $h = 1000): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 140, 60));
    imagefilledellipse($img, intdiv($w, 2), intdiv($h, 3), intdiv($w, 2), intdiv($w, 2), imagecolorallocate($img, 13, 95, 87));
    imagejpeg($img, $path, 90);

    return $path;
}

function makePng(string $path): string
{
    $img = imagecreatetruecolor(600, 600);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagefilledrectangle($img, 100, 100, 500, 500, imagecolorallocate($img, 240, 196, 25));
    imagepng($img, $path);

    return $path;
}

function validInput(array $overrides = []): array
{
    return array_merge([
        'submission_id' => uuid4(),
        'repondant_email' => 'marie.kouassi@exemple.com',
        'repondant_nom' => "Kouassi Marie-Ange",
        'repondant_date_naissance' => '1994-05-12',
        'repondant_nationalite' => 'Ivoirienne',
        'repondant_telephone' => '418 555-0123',
        'repondant_profession' => 'Infirmière',
        'membre_evh' => 'oui',
        'baptise_immersion' => 'oui',
        'relation_precedente' => 'non',
        'deja_marie' => 'non',
        'duree_valeur' => '2',
        'duree_unite' => 'annees',
        'cheminant_nom' => "N'Guessan Kouadio Jean",
        'cheminant_date_naissance' => '1990-11-03',
        'cheminant_nationalite' => 'Ivoirienne',
        'cheminant_telephone' => '+225 07 07 12 34 56',
        'cheminant_profession' => 'Comptable',
        'cheminant_pays' => "Côte d'Ivoire",
        'cheminant_ville' => 'Abidjan',
        'cheminant_eglise' => "Vases d'Honneur Abidjan",
        'bloquants' => 'non',
        'pret_classes' => 'oui',
        'confirmation_frais' => '1',
    ], $overrides);
}

/** Envoie une inscription. $photos : champ => [chemin, type annoncé, nom] ou null pour l'omettre. */
function post(array $fields, ?array $photos = null, array $headers = []): array
{
    global $endpoint, $tmpDir;
    $photos ??= [
        'photo_repondant' => [makeJpeg("{$tmpDir}/a.jpg"), 'image/jpeg', 'moi.jpg'],
        'photo_cheminant' => [makePng("{$tmpDir}/b.png"), 'image/png', 'lui.png'],
    ];
    foreach ($photos as $name => $photo) {
        if ($photo !== null) {
            $fields[$name] = new CURLFile($photo[0], $photo[1], $photo[2]);
        }
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function rows(): array
{
    global $pdo;

    return $pdo->query('SELECT * FROM eden_inscriptions ORDER BY id')->fetchAll();
}

function rowBySubmission(string $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM eden_inscriptions WHERE submission_id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

function mailpit(string $method, string $path): mixed
{
    global $mailpit;
    $ch = curl_init($mailpit . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    return json_decode($raw, true) ?? $raw;
}

function photoFiles(): array
{
    global $privateDir;
    $dir = $privateDir . '/eden/photos';
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = $file->getPathname();
    }

    return $files;
}

function setEnv(array $values): void
{
    global $envFile, $originalEnv;
    $content = $originalEnv;
    foreach ($values as $key => $value) {
        $content = preg_match("/^{$key}=.*$/m", $content)
            ? preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $content)
            : $content . "\n{$key}={$value}\n";
    }
    file_put_contents($envFile, $content);
}

function restoreEnv(): void
{
    global $envFile, $originalEnv;
    file_put_contents($envFile, $originalEnv);
}

function errorsOf(array $response): array
{
    return $response['body']['errors'] ?? [];
}

register_shutdown_function(static function () use ($tmpDir): void {
    restoreEnv();
    array_map('unlink', glob("{$tmpDir}/*") ?: []);
    @rmdir($tmpDir);
});

/* ------------------------------------------------------------------ remise à zéro */

$pdo->exec('DELETE FROM eden_inscriptions');
mailpit('DELETE', '/api/v1/messages');
array_map('unlink', photoFiles());
array_map('unlink', glob($privateDir . '/cache/ratelimit/*') ?: []);

/* ------------------------------------------------------------------ scénarios */

scenario('TEST 8 · Soumission normale : base de données + courriels');
$input = validInput();
$r = post($input);
check($r['status'] === 200 && ($r['body']['ok'] ?? false) === true, 'réponse 200 ok');
check((bool) preg_match('/^EDEN-\d{4}-\d{4}$/', (string) ($r['body']['reference'] ?? '')), 'référence lisible : ' . ($r['body']['reference'] ?? '?'));
$row = rowBySubmission($input['submission_id']);
check($row !== null, 'inscription enregistrée en base');
check(($row['repondant_telephone'] ?? '') === '+14185550123', 'téléphone du répondant normalisé (+14185550123)');
check(($row['cheminant_telephone'] ?? '') === '+2250707123456', 'téléphone WhatsApp international normalisé');
check($row !== null && $row['congregation'] === null, 'congrégation vide pour un membre de Vases d\'Honneur');
check(($row['frais_confirmes'] ?? 0) == 1 && ($row['paiement_statut'] ?? '') === 'en_attente', 'frais confirmés, paiement en attente (non considéré payé)');
check(($row['courriel_equipe_statut'] ?? '') === 'envoye' && ($row['courriel_candidat_statut'] ?? '') === 'envoye', 'deux courriels marqués envoyés');
check($row !== null && $row['photo_repondant'] === null && photoFiles() === [], 'photos supprimées du serveur après envoi à l\'équipe');

$messages = mailpit('GET', '/api/v1/messages')['messages'] ?? [];
check(count($messages) === 2, '2 courriels reçus dans Mailpit');
$team = $candidate = null;
foreach ($messages as $m) {
    $detail = mailpit('GET', '/api/v1/message/' . $m['ID']);
    if (str_starts_with($detail['Subject'], 'Nouvelle inscription')) {
        $team = $detail;
    } else {
        $candidate = $detail;
    }
}
$addresses = static fn (array $list) => array_map(static fn ($a) => $a['Address'], $list);
check($team !== null && $addresses($team['To']) === ['eden1coach@evhc.com'], 'équipe : À eden1coach@evhc.com');
check($team !== null && $addresses($team['Cc']) === ['wlogan.wilfried@yahoo.fr'], 'équipe : Cc wlogan.wilfried@yahoo.fr');
check($team !== null && $addresses($team['Bcc'] ?? []) === ['vasesdhonneurchicoutimi@gmail.com'], 'équipe : Cci vasesdhonneurchicoutimi@gmail.com');
// Mailpit ajoute lui-même une ligne « Bcc: » avant son « Received: » : seuls les
// en-têtes qui suivent ont été transmis par PHPMailer.
$teamRaw = $team ? (string) mailpit('GET', '/api/v1/message/' . $team['ID'] . '/raw') : '';
$sentHeaders = strstr(substr($teamRaw, (int) strpos($teamRaw, "\nReceived:")), "\r\n\r\n", true) ?: '';
check($sentHeaders !== '' && !preg_match('/^Bcc:/mi', $sentHeaders) && !str_contains($sentHeaders, 'vasesdhonneurchicoutimi@gmail.com'), 'équipe : la copie cachée est absente des en-têtes transmis');
check($candidate !== null && ($candidate['Bcc'] ?? []) === [], 'candidat : aucune copie cachée');
check($team !== null && ($addresses($team['ReplyTo'] ?? [])[0] ?? '') === 'marie.kouassi@exemple.com', 'équipe : réponse dirigée vers le candidat');
check($team !== null && count($team['Attachments']) === 2, 'équipe : 2 photos en pièces jointes');
$teamHtml = $team['HTML'] ?? '';
check(str_contains($teamHtml, 'Informations du répondant') && str_contains($teamHtml, 'Informations du/de la cheminant(e)') && str_contains($teamHtml, 'Photos'), 'équipe : sections présentes');
check(str_contains($teamHtml, 'Côte d&apos;Ivoire'), 'équipe : valeurs échappées dans le HTML');
check($candidate !== null && $addresses($candidate['To']) === ['marie.kouassi@exemple.com'] && $candidate['Cc'] === [], 'candidat : courriel séparé à son adresse, sans copie');
check($candidate !== null && str_contains($candidate['Text'], 'eden1@evhca.com') && str_contains($candidate['Text'], '+1 (581) 574-4660') && str_contains($candidate['Text'], '250 $'), 'candidat : rappel 250 $, Interac et WhatsApp');
check($candidate !== null && !str_contains($candidate['Text'], 'Baptisé') && count($candidate['Attachments']) === 0, 'candidat : aucune donnée interne ni pièce jointe');

scenario('TEST 1 · Membre de Vases d\'Honneur = Oui : congrégation non obligatoire');
$input = validInput(['membre_evh' => 'oui', 'congregation' => '']);
$r = post($input);
check($r['status'] === 200, 'accepté sans congrégation');
$input = validInput(['membre_evh' => 'oui', 'congregation' => 'Une autre église']);
post($input);
check(rowBySubmission($input['submission_id'])['congregation'] === null, 'congrégation ignorée si Oui');

scenario('TEST 2 · Membre de Vases d\'Honneur = Non : congrégation obligatoire');
$r = post(validInput(['membre_evh' => 'non']));
check($r['status'] === 422 && isset(errorsOf($r)['congregation']), 'refusé sans congrégation');
$input = validInput(['membre_evh' => 'non', 'congregation' => 'Église Baptiste de Québec']);
$r = post($input);
check($r['status'] === 200 && rowBySubmission($input['submission_id'])['congregation'] === 'Église Baptiste de Québec', 'accepté et enregistré avec congrégation');

scenario('TEST 3 · Case des frais non cochée');
$input = validInput();
unset($input['confirmation_frais']);
$r = post($input);
check($r['status'] === 422 && isset(errorsOf($r)['confirmation_frais']), 'refusé sans la case (requête HTTP directe)');
$r = post(validInput(['confirmation_frais' => '0']));
check($r['status'] === 422 && isset(errorsOf($r)['confirmation_frais']), 'refusé avec confirmation_frais=0');

scenario('TEST 4 · Courriel invalide');
foreach (['marie', 'marie@gmail', "marie@exemple.com\r\nBcc: pirate@exemple.com"] as $email) {
    $r = post(validInput(['repondant_email' => $email]));
    check($r['status'] === 422 && isset(errorsOf($r)['repondant_email']), 'refusé : ' . json_encode($email));
}

scenario('TEST 5 · Date de naissance future, invalide ou mineur');
foreach (['2030-01-01' => 'futur', date('Y-m-d', strtotime('+1 day')) => 'futur', '1990-02-30' => 'pas valide', date('Y-m-d', strtotime('-17 years')) => '18 ans'] as $date => $expect) {
    $r = post(validInput(['cheminant_date_naissance' => $date]));
    check($r['status'] === 422 && str_contains(errorsOf($r)['cheminant_date_naissance'] ?? '', $expect), "refusé : {$date}");
}

scenario('TEST 6 · Photo invalide');
file_put_contents("{$tmpDir}/faux.jpg", "Ceci n'est pas une image");
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/faux.jpg", 'image/jpeg', 'faux.jpg'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && str_contains(errorsOf($r)['photo_repondant'] ?? '', 'Format'), 'texte déguisé en JPEG refusé');
$gif = imagecreatetruecolor(400, 400);
imagegif($gif, "{$tmpDir}/anim.gif");
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/anim.gif", 'image/gif', 'anim.gif'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && isset(errorsOf($r)['photo_repondant']), 'GIF refusé');
$r = post(validInput(), ['photo_repondant' => [makeJpeg("{$tmpDir}/petit.jpg", 80, 80), 'image/jpeg', 'petit.jpg'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && str_contains(errorsOf($r)['photo_repondant'] ?? '', 'petite'), 'image minuscule refusée');
$r = post(validInput(), ['photo_repondant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg'], 'photo_cheminant' => null]);
check($r['status'] === 422 && isset(errorsOf($r)['photo_cheminant']), 'photo manquante refusée');

scenario('TEST 7 · Photo trop volumineuse');
makeJpeg("{$tmpDir}/lourde.jpg");
file_put_contents("{$tmpDir}/lourde.jpg", str_repeat("\0", 11 * 1024 * 1024), FILE_APPEND);
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/lourde.jpg", 'image/jpeg', 'lourde.jpg'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && str_contains(errorsOf($r)['photo_repondant'] ?? '', '10 Mo'), 'photo de 11 Mo refusée');

scenario('TEST 9 · Double clic : même formulaire envoyé deux fois en même temps');
$before = count(rows());
$input = validInput();
$multi = curl_multi_init();
$handles = [];
foreach ([1, 2] as $i) {
    $fields = $input + [
        'photo_repondant' => new CURLFile(makeJpeg("{$tmpDir}/d{$i}.jpg"), 'image/jpeg', 'd.jpg'),
        'photo_cheminant' => new CURLFile(makeJpeg("{$tmpDir}/e{$i}.jpg"), 'image/jpeg', 'e.jpg'),
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running);
$refs = array_map(static fn ($ch) => json_decode((string) curl_multi_getcontent($ch), true)['reference'] ?? null, $handles);
check(count(rows()) === $before + 1, 'une seule inscription créée');
check($refs[0] !== null && $refs[0] === $refs[1], 'les deux réponses donnent la même référence');
$r = post($input);
check($r['status'] === 200 && $r['body']['reference'] === $refs[0] && count(rows()) === $before + 1, 'renvoi ultérieur : même référence, rien de dupliqué');

scenario('TEST 12 · Téléphone invalide');
foreach (['12345', '07 07 12 34 56', 'pas de numéro'] as $phone) {
    $r = post(validInput(['repondant_telephone' => $phone]));
    check($r['status'] === 422 && isset(errorsOf($r)['repondant_telephone']), 'refusé : ' . $phone);
}

scenario('TEST 13 · Durée négative ou nulle');
foreach (['-3', '0', '100'] as $value) {
    $r = post(validInput(['duree_valeur' => $value]));
    check($r['status'] === 422 && isset(errorsOf($r)['duree_valeur']), "refusé : {$value}");
}
$r = post(validInput(['duree_unite' => 'siecles']));
check($r['status'] === 422 && isset(errorsOf($r)['duree_unite']), 'unité inconnue refusée');

scenario('TEST 14 · Durée avec lettres');
foreach (['deux', '2a', '1e2', '2.5'] as $value) {
    $r = post(validInput(['duree_valeur' => $value]));
    check($r['status'] === 422 && isset(errorsOf($r)['duree_valeur']), "refusé : {$value}");
}

scenario('TEST 17 · Requêtes malveillantes et champs inattendus');
$input = validInput([
    'paiement_statut' => 'paye',
    'reference' => 'EDEN-PIRATE',
    'id' => '1',
    'courriel_equipe_statut' => 'envoye',
    'cheminant_eglise' => "Église'; DROP TABLE eden_inscriptions; --",
]);
$r = post($input);
$row = rowBySubmission($input['submission_id']);
check($r['status'] === 200 && $row !== null, 'champs inattendus ignorés, inscription acceptée');
check($row['paiement_statut'] === 'en_attente' && $row['reference'] !== 'EDEN-PIRATE', 'impossible de forcer le statut de paiement ou la référence');
check($row['cheminant_eglise'] === "Église'; DROP TABLE eden_inscriptions; --" && count(rows()) > 0, 'tentative d\'injection SQL stockée comme simple texte, table intacte');
$r = post(validInput(['repondant_profession' => '<script>alert(1)</script>']));
check($r['status'] === 422 && isset(errorsOf($r)['repondant_profession']), 'balise <script> refusée');
$input = validInput();
unset($input['repondant_nom']);
$r = post($input + ['repondant_nom[]' => 'tableau']);
check($r['status'] === 422 && isset(errorsOf($r)['repondant_nom']), 'tableau à la place d\'un texte : refusé proprement');
$r = post(validInput(['submission_id' => "1' OR '1'='1"]));
check($r['status'] === 422 && isset(errorsOf($r)['submission_id']), 'identifiant de soumission falsifié refusé');
$r = post(validInput(), null, ['Origin: https://site-pirate.example']);
check($r['status'] === 403, 'envoi depuis un autre site (Origin étranger) refusé');
$get = curl_init($endpoint);
curl_setopt_array($get, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($get);
check(curl_getinfo($get, CURLINFO_RESPONSE_CODE) === 405, 'GET refusé (405)');
$before = count(rows());
$r = post(validInput(['site_web' => 'http://spam.example']));
check($r['status'] === 200 && count(rows()) === $before, 'robot (champ piège rempli) : réponse neutre, rien enregistré');

scenario('TEST 18 · Fichiers dangereux');
file_put_contents("{$tmpDir}/shell.php", '<?php system($_GET["c"]); ?>');
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/shell.php", 'image/jpeg', 'shell.php'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && isset(errorsOf($r)['photo_repondant']), 'script PHP annoncé comme image : refusé');
file_put_contents("{$tmpDir}/image.svg", '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/image.svg", 'image/jpeg', 'image.jpg'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 422 && isset(errorsOf($r)['photo_repondant']), 'SVG avec script : refusé');
// Image valide contenant du code caché : acceptée mais redessinée, le code disparaît.
makeJpeg("{$tmpDir}/polyglotte.jpg");
file_put_contents("{$tmpDir}/polyglotte.jpg", '<?php echo "pirate"; ?>', FILE_APPEND);
mailpit('DELETE', '/api/v1/messages');
$r = post(validInput(), ['photo_repondant' => ["{$tmpDir}/polyglotte.jpg", 'image/jpeg', '../../polyglotte.php.jpg'], 'photo_cheminant' => [makeJpeg("{$tmpDir}/c.jpg"), 'image/jpeg', 'c.jpg']]);
check($r['status'] === 200, 'image avec code caché acceptée (contenu image valide)');
$teamMail = null;
foreach (mailpit('GET', '/api/v1/messages')['messages'] ?? [] as $m) {
    if (str_starts_with($m['Subject'], 'Nouvelle inscription')) {
        $teamMail = mailpit('GET', '/api/v1/message/' . $m['ID']);
    }
}
$attachment = $teamMail ? mailpit('GET', '/api/v1/message/' . $teamMail['ID'] . '/part/' . $teamMail['Attachments'][0]['PartID']) : '';
check(is_string($attachment) && str_starts_with($attachment, "\xFF\xD8") && !str_contains($attachment, '<?php'), 'pièce jointe redessinée en JPEG propre, sans le code caché');
check($teamMail !== null && !str_contains($teamMail['Attachments'][0]['FileName'], '..'), 'nom de fichier choisi par le serveur (pas celui envoyé)');

scenario('TEST 10 · Panne d\'envoi des courriels');
setEnv(['SMTP_PORT' => '1']);
$input = validInput();
$r = post($input);
$row = rowBySubmission($input['submission_id']);
check($r['status'] === 200 && ($r['body']['courriel_candidat_echec'] ?? false) === true, 'inscription confirmée à la personne, avec mention du courriel non parti');
check($row !== null && $row['courriel_equipe_statut'] === 'echec' && $row['courriel_candidat_statut'] === 'echec', 'échec noté en base, données conservées');
check($row !== null && $row['photo_repondant'] !== null && count(photoFiles()) === 2, 'photos gardées en attente de la relance');
restoreEnv();
mailpit('DELETE', '/api/v1/messages');
$php = PHP_BINARY;
$out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../../public/api/_lib/cli/eden-relancer-courriels.php') . ' 2>&1');
$row = rowBySubmission($input['submission_id']);
check($row['courriel_equipe_statut'] === 'envoye' && $row['courriel_candidat_statut'] === 'envoye', 'relance : les deux courriels repartent (' . trim((string) $out) . ')');
check($row['photo_repondant'] === null && photoFiles() === [], 'relance : photos supprimées après l\'envoi');
check(count(mailpit('GET', '/api/v1/messages')['messages'] ?? []) === 2, 'relance : 2 courriels reçus');

scenario('TEST 11 · Base de données indisponible');
setEnv(['DB_DSN' => '"mysql:host=127.0.0.1;dbname=base_inexistante;charset=utf8mb4"']);
$r = post(validInput());
restoreEnv();
check($r['status'] === 500, 'réponse 500');
check(str_contains($r['body']['message'] ?? '', 'problème technique') && !preg_match('/PDO|SQLSTATE|mysql|\.php|Stack/i', $r['raw']), 'message humain, aucun détail technique');
check(photoFiles() === [], 'aucune photo laissée sur le serveur');

scenario('Limitation du nombre d\'envois');
array_map('unlink', glob($privateDir . '/cache/ratelimit/*') ?: []);
setEnv(['EDEN_LIMITE_PAR_HEURE' => '2']);
post(validInput(['repondant_email' => 'x']));
post(validInput(['repondant_email' => 'x']));
$r = post(validInput());
restoreEnv();
array_map('unlink', glob($privateDir . '/cache/ratelimit/*') ?: []);
check($r['status'] === 429 && str_contains($r['body']['message'] ?? '', 'Patientez'), '3e envoi dans l\'heure refusé (429)');

/* ------------------------------------------------------------------ bilan */

echo "\n{$passed} vérification(s) réussie(s), {$failed} échec(s).\n";
exit($failed === 0 ? 0 : 1);
