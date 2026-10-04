<?php
declare(strict_types=1);

namespace Evh\Eden;

use Evh\Config;
use Evh\MailMessage;
use Evh\Phone;

/**
 * Les deux courriels envoyés après une inscription :
 *  - à l'équipe Eden (toutes les réponses + les deux photos en pièces jointes) ;
 *  - au candidat (accusé de réception et rappel du paiement, sans données internes).
 * Toutes les valeurs saisies sont échappées avant d'être insérées dans le HTML.
 */
final class InscriptionMails
{
    private const MONTHS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly PhotoStore $photos,
    ) {
    }

    public function teamMessage(array $row): MailMessage
    {
        $reference = (string) $row['reference'];
        $sections = [
            'Informations du répondant' => [
                'Courriel' => $row['repondant_email'],
                'Nom et prénoms' => $row['repondant_nom'],
                'Date de naissance' => $this->birthDate($row['repondant_date_naissance']),
                'Nationalité' => $row['repondant_nationalite'],
                'Téléphone' => Phone::format($row['repondant_telephone']),
                'Profession' => $row['repondant_profession'],
                "Membre de Vases d'Honneur" => $this->yesNo($row['membre_evh']),
                'Congrégation' => $row['membre_evh'] ? 'Sans objet' : $row['congregation'],
                'Baptisé(e) par immersion' => $this->yesNo($row['baptise_immersion']),
                'Cheminement précédent non abouti' => $this->yesNo($row['relation_precedente']),
                'Déjà marié(e) par le passé' => $this->yesNo($row['deja_marie']),
            ],
            'Informations du cheminement' => [
                'Durée' => (string) $row['duree_valeur'],
                'Unité' => $row['duree_unite'] === 'annees' ? 'années' : 'mois',
            ],
            'Informations du/de la cheminant(e)' => [
                'Nom et prénoms' => $row['cheminant_nom'],
                'Date de naissance' => $this->birthDate($row['cheminant_date_naissance']),
                'Nationalité' => $row['cheminant_nationalite'],
                'Téléphone WhatsApp' => Phone::format($row['cheminant_telephone']),
                'Profession' => $row['cheminant_profession'],
                'Pays' => $row['cheminant_pays'],
                'Ville' => $row['cheminant_ville'],
                'Église fréquentée' => $row['cheminant_eglise'],
            ],
            'Questions' => [
                'Bloquants dans le cheminement' => $this->yesNo($row['bloquants']),
                'Prêt(e) pour les classes' => $this->yesNo($row['pret_classes']),
            ],
            'Confirmation' => [
                'Frais de ' . $this->fee() . ' $ confirmés' => $row['frais_confirmes'] ? 'Oui, le ' . $this->dateTime($row['frais_confirmes_le']) : 'Non',
                'Paiement' => 'À vérifier (capture Interac attendue par WhatsApp)',
            ],
            'Photos' => [
                'Photo du répondant' => 'photo-repondant-' . $reference . '.jpg (pièce jointe)',
                'Photo du/de la cheminant(e)' => 'photo-cheminant-' . $reference . '.jpg (pièce jointe)',
            ],
        ];

        $html = $this->layout(
            'Nouvelle inscription : Cours de mariage Eden',
            '<p style="margin:0 0 6px;font-size:15px;color:#33413e;">Une nouvelle inscription vient d\'être reçue sur le site.</p>'
            . '<p style="margin:0 0 24px;font-size:15px;color:#33413e;">Référence : <strong style="color:#0a4a44;">' . $this->e($reference) . '</strong>, reçue le ' . $this->e($this->dateTime($row['cree_le'])) . '.</p>'
            . $this->sectionsHtml($sections)
            . '<p style="margin:24px 0 0;font-size:13px;color:#6b7774;">Pour répondre au candidat, utilisez simplement « Répondre » : la réponse partira vers ' . $this->e($row['repondant_email']) . '.</p>'
        );

        $text = "Nouvelle inscription : Cours de mariage Eden\nRéférence : {$reference}\n\n" . $this->sectionsText($sections);

        return new MailMessage(
            to: $this->teamRecipients(),
            subject: 'Nouvelle inscription : Cours de mariage Eden (' . $reference . ')',
            html: $html,
            text: $text,
            cc: $this->config->list('EDEN_EMAIL_CC'),
            replyTo: $row['repondant_email'],
            attachments: [
                ['path' => $this->photos->absolutePath($row['photo_repondant']), 'name' => 'photo-repondant-' . $reference . '.jpg'],
                ['path' => $this->photos->absolutePath($row['photo_cheminant']), 'name' => 'photo-cheminant-' . $reference . '.jpg'],
            ],
        );
    }

    public function candidateMessage(array $row): MailMessage
    {
        $reference = (string) $row['reference'];
        $fee = $this->fee();
        $interac = $this->config->get('EDEN_INTERAC_EMAIL', 'eden1@evhca.com');
        $whatsapp = $this->config->get('EDEN_WHATSAPP', '+1 (581) 574-4660');
        $start = $this->config->get('EDEN_DEBUT_COURS', 'à partir du mardi 20 octobre 2026, en ligne sur Zoom');
        $infoline = $this->config->get('EDEN_INFOLINE', '+1 (418) 490-0186');
        $p = 'margin:0 0 16px;font-size:15px;line-height:1.6;color:#33413e;';

        $body = '<p style="' . $p . '">Bonjour ' . $this->e($row['repondant_nom']) . ',</p>'
            . '<p style="' . $p . '">Nous avons bien reçu votre inscription aux cours de mariage Eden. Merci de votre confiance : nous sommes heureux de vous accompagner, vous et votre cheminant(e), sur ce chemin vers le mariage.</p>'
            . '<p style="' . $p . '">Votre numéro d\'inscription : <strong style="color:#0a4a44;">' . $this->e($reference) . '</strong></p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;background:#fbf6ec;border:1px solid #ecd9b0;border-radius:12px;">'
            . '<tr><td style="padding:20px 22px;">'
            . '<p style="margin:0 0 10px;font-size:13px;letter-spacing:1px;text-transform:uppercase;color:#a5561b;font-weight:bold;">Frais d\'inscription</p>'
            . '<p style="' . $p . '">Les frais sont de <strong>' . $fee . ' $ pour les deux cheminants</strong>. Ils doivent être réglés avant le début des classes.</p>'
            . '<p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#33413e;">1. Faites un virement Interac du montant exact de ' . $fee . ' $ à <strong>' . $this->e($interac) . '</strong>.</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;color:#33413e;">2. Envoyez une capture d\'écran du virement par WhatsApp au <strong style="white-space:nowrap;">' . $this->e($whatsapp) . '</strong>, en indiquant votre numéro d\'inscription.</p>'
            . '</td></tr></table>'
            . '<p style="' . $p . '">L\'envoi du formulaire ne vaut pas paiement : votre inscription sera complète lorsque l\'équipe Eden aura reçu votre virement.</p>'
            . '<p style="' . $p . '"><strong>La suite :</strong> les cours reprennent ' . $this->e($start) . '. L\'équipe Eden vous contactera pour vous transmettre les détails pratiques.</p>'
            . '<p style="' . $p . '">Une question ? Appelez l\'infoline au ' . $this->e($infoline) . ' ou répondez simplement à ce courriel.</p>'
            . '<p style="margin:24px 0 0;font-size:15px;line-height:1.6;color:#33413e;">Que Dieu bénisse votre cheminement,<br><strong>L\'équipe Eden</strong></p>';

        $text = "Bonjour {$row['repondant_nom']},\n\n"
            . "Nous avons bien reçu votre inscription aux cours de mariage Eden. Merci de votre confiance.\n\n"
            . "Votre numéro d'inscription : {$reference}\n\n"
            . "FRAIS D'INSCRIPTION\n"
            . "Les frais sont de {$fee} \$ pour les deux cheminants, à régler avant le début des classes.\n"
            . "1. Faites un virement Interac du montant exact de {$fee} \$ à {$interac}.\n"
            . "2. Envoyez une capture d'écran du virement par WhatsApp au {$whatsapp}, en indiquant votre numéro d'inscription.\n\n"
            . "L'envoi du formulaire ne vaut pas paiement : votre inscription sera complète lorsque l'équipe Eden aura reçu votre virement.\n\n"
            . "La suite : les cours reprennent {$start}. L'équipe Eden vous contactera pour vous transmettre les détails pratiques.\n\n"
            . "Une question ? Infoline : {$infoline}\n\n"
            . "Que Dieu bénisse votre cheminement,\nL'équipe Eden\nÉglise Vases d'Honneur Chicoutimi, Assemblée Plénitude d'Amour";

        return new MailMessage(
            to: [$row['repondant_email']],
            subject: 'Votre inscription aux cours Eden est bien reçue (' . $reference . ')',
            html: $this->layout('Votre inscription est bien reçue', $body),
            text: $text,
            replyTo: $this->teamRecipients()[0],
        );
    }

    /**
     * Destinataires des inscriptions, lus uniquement dans evh_private/.env :
     * aucune adresse n'est écrite dans le code, pour qu'un test ne puisse
     * jamais partir par erreur vers l'équipe.
     *
     * @return non-empty-list<string>
     */
    private function teamRecipients(): array
    {
        $to = $this->config->list('EDEN_EMAIL_TO');
        if ($to === []) {
            throw new \RuntimeException('EDEN_EMAIL_TO manquant dans evh_private/.env');
        }

        return $to;
    }

    private function fee(): string
    {
        return (string) $this->config->int('EDEN_FRAIS', 250);
    }

    /** @param array<string, array<string, mixed>> $sections */
    private function sectionsHtml(array $sections): string
    {
        $html = '';
        foreach ($sections as $title => $rows) {
            $html .= '<p style="margin:22px 0 8px;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#a5561b;font-weight:bold;">' . $this->e($title) . '</p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">';
            foreach ($rows as $label => $value) {
                $html .= '<tr>'
                    . '<td style="padding:8px 12px 8px 0;border-bottom:1px solid #eef1f0;color:#6b7774;width:42%;vertical-align:top;">' . $this->e($label) . '</td>'
                    . '<td style="padding:8px 0;border-bottom:1px solid #eef1f0;color:#1c2b29;font-weight:600;vertical-align:top;">' . $this->e((string) $value) . '</td>'
                    . '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    /** @param array<string, array<string, mixed>> $sections */
    private function sectionsText(array $sections): string
    {
        $text = '';
        foreach ($sections as $title => $rows) {
            $text .= mb_strtoupper($title) . "\n";
            foreach ($rows as $label => $value) {
                $text .= "- {$label} : {$value}\n";
            }
            $text .= "\n";
        }

        return $text;
    }

    private function layout(string $title, string $content): string
    {
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $this->e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f2f0ea;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f0ea;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;">'
            . '<tr><td style="background:#0a4a44;background-image:linear-gradient(135deg,#0d5f57,#06302c);padding:26px 28px;">'
            . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#f0c419;">Eden · Cheminement au mariage</p>'
            . '<h1 style="margin:0;font-family:Georgia,serif;font-size:24px;font-weight:normal;color:#ffffff;">' . $this->e($title) . '</h1>'
            . '</td></tr>'
            . '<tr><td style="height:4px;background:#d98a2b;background-image:linear-gradient(90deg,#f0c419,#d98a2b,#b5481f);"></td></tr>'
            . '<tr><td style="padding:28px;">' . $content . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#f7f5f0;font-size:12px;line-height:1.5;color:#7a857f;">'
            . 'Église Vases d\'Honneur Chicoutimi, Assemblée Plénitude d\'Amour<br>70 Rue Racine Est, Chicoutimi (Québec) G7H 1P6'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function yesNo(mixed $value): string
    {
        return (int) $value === 1 ? 'Oui' : 'Non';
    }

    private function birthDate(string $date): string
    {
        $d = new \DateTimeImmutable($date);
        $age = $d->diff(new \DateTimeImmutable('today'))->y;

        return $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y') . " ({$age} ans)";
    }

    private function dateTime(string $dateTime): string
    {
        $d = new \DateTimeImmutable($dateTime);

        return $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y') . ' à ' . $d->format('H\hi');
    }
}
