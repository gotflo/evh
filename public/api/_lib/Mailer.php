<?php
declare(strict_types=1);

namespace Evh;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi des courriels via PHPMailer.
 *
 * MAIL_DRIVER :
 *  - smtp : serveur SMTP (Hostinger : smtp.hostinger.com, port 465, ssl)
 *  - mail : fonction mail() de PHP (dépannage seulement, souvent classé en spam)
 *  - log  : aucun envoi, le message est écrit dans evh_private/logs/mail (tests)
 */
final class Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @throws \RuntimeException si l'envoi échoue */
    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $from = $this->config->get('MAIL_FROM');
            if ($from === '') {
                throw new \RuntimeException('MAIL_FROM manquant dans evh_private/.env');
            }
            $mail->setFrom($from, $this->config->get('MAIL_FROM_NAME', "Vases d'Honneur Chicoutimi"));

            foreach ($message->to as $address) {
                $mail->addAddress($address);
            }
            foreach ($message->cc as $address) {
                $mail->addCC($address);
            }
            if ($message->replyTo !== null) {
                $mail->addReplyTo($message->replyTo);
            }
            foreach ($message->attachments as $file) {
                $mail->addAttachment($file['path'], $file['name']);
            }

            $mail->isHTML(true);
            $mail->Subject = $message->subject;
            $mail->Body = $message->html;
            $mail->AltBody = $message->text;

            $driver = $this->config->get('MAIL_DRIVER', 'smtp');
            if ($driver === 'log') {
                $mail->preSend();
                $file = $this->config->privatePath('logs/mail') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
                file_put_contents($file, $mail->getSentMIMEMessage());
                return;
            }

            if ($driver === 'mail') {
                $mail->isMail();
            } else {
                $this->configureSmtp($mail);
            }

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException('Envoi du courriel impossible : ' . $e->getMessage(), 0, $e);
        }
    }

    private function configureSmtp(PHPMailer $mail): void
    {
        $host = $this->config->get('SMTP_HOST');
        if ($host === '') {
            throw new \RuntimeException('SMTP_HOST manquant dans evh_private/.env');
        }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $this->config->int('SMTP_PORT', 465);
        $mail->Timeout = 20;

        // ssl (port 465) ou tls (port 587). « none » ne sert qu'aux tests locaux.
        $secure = $this->config->get('SMTP_SECURE', 'ssl');
        if ($secure === 'none') {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        }

        $user = $this->config->get('SMTP_USER');
        $mail->SMTPAuth = $user !== '';
        $mail->Username = $user;
        $mail->Password = $this->config->get('SMTP_PASSWORD');
    }
}
