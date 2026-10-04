<?php
declare(strict_types=1);

namespace Evh\Eden;

use Evh\Config;
use Evh\Database;
use Evh\Logger;
use Evh\Mailer;

/**
 * Traitement d'une inscription : validation, enregistrement, courriels.
 *
 * L'inscription est enregistrée AVANT l'envoi des courriels : si l'envoi échoue,
 * rien n'est perdu. L'échec est noté dans la table et la tâche
 * cli/eden-relancer-courriels.php peut renvoyer les messages plus tard.
 */
final class InscriptionService
{
    private const PHOTO_FIELDS = ['photo_repondant', 'photo_cheminant'];

    public function __construct(
        private readonly InscriptionRepository $repository,
        private readonly PhotoStore $photos,
        private readonly InscriptionMails $mails,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $photos = new PhotoStore($config);

        return new self(
            new InscriptionRepository(Database::connect($config)),
            $photos,
            new InscriptionMails($config, $photos),
            new Mailer($config),
            new Logger($config),
        );
    }

    /**
     * @param array<string, mixed> $input $_POST
     * @param array<string, mixed> $files $_FILES
     * @return array{status: int, body: array<string, mixed>}
     */
    public function submit(array $input, array $files): array
    {
        ['data' => $data, 'errors' => $errors] = (new InscriptionValidator())->validate($input);

        $photos = [];
        foreach (self::PHOTO_FIELDS as $field) {
            try {
                $photos[$field] = $this->photos->inspect($files[$field] ?? null);
            } catch (PhotoException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        if ($errors) {
            return $this->invalid($errors);
        }

        // Même formulaire envoyé deux fois (double clic, nouvel essai après coupure réseau).
        $existing = $this->repository->findBySubmissionId($data['submission_id']);
        if ($existing !== null) {
            return $this->success($existing);
        }

        $saved = [];
        try {
            foreach ($photos as $field => $photo) {
                try {
                    $saved[$field] = $this->photos->save($photo);
                } catch (PhotoException $e) {
                    $this->deletePhotos($saved);
                    return $this->invalid([$field => $e->getMessage()]);
                }
            }
            $row = $this->repository->create($data['submission_id'], $data + $saved);
        } catch (\PDOException $e) {
            $this->deletePhotos($saved);
            if (InscriptionRepository::isDuplicateKey($e)) {
                $existing = $this->repository->findBySubmissionId($data['submission_id']);
                if ($existing !== null) {
                    return $this->success($existing);
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->deletePhotos($saved);
            throw $e;
        }

        $this->logger->info('Inscription Eden enregistrée', ['reference' => $row['reference']]);
        $this->deliver($row);

        return $this->success($this->repository->find((int) $row['id']) ?? $row);
    }

    /**
     * Envoie les courriels qui ne sont pas encore partis pour cette inscription.
     * Les photos sont supprimées dès que le courriel de l'équipe est envoyé.
     */
    public function deliver(array $row): void
    {
        $id = (int) $row['id'];
        $team = $row['courriel_equipe_statut'];
        $candidate = $row['courriel_candidat_statut'];
        $errors = [];

        if ($team !== InscriptionRepository::MAIL_SENT) {
            try {
                $this->mailer->send($this->mails->teamMessage($row));
                $team = InscriptionRepository::MAIL_SENT;
                $this->deletePhotos([$row['photo_repondant'], $row['photo_cheminant']]);
                $this->repository->clearPhotos($id);
            } catch (\Throwable $e) {
                $team = InscriptionRepository::MAIL_FAILED;
                $errors[] = 'équipe : ' . $e->getMessage();
                $this->logger->error('Courriel équipe Eden non envoyé', ['reference' => $row['reference'], 'erreur' => $e->getMessage()]);
            }
        }

        if ($candidate !== InscriptionRepository::MAIL_SENT) {
            try {
                $this->mailer->send($this->mails->candidateMessage($row));
                $candidate = InscriptionRepository::MAIL_SENT;
            } catch (\Throwable $e) {
                $candidate = InscriptionRepository::MAIL_FAILED;
                $errors[] = 'candidat : ' . $e->getMessage();
                $this->logger->error('Courriel candidat Eden non envoyé', ['reference' => $row['reference'], 'erreur' => $e->getMessage()]);
            }
        }

        $this->repository->recordMailResult($id, $team, $candidate, $errors ? implode(' | ', $errors) : null);
    }

    /** @param array<array-key, ?string> $paths */
    private function deletePhotos(array $paths): void
    {
        foreach ($paths as $path) {
            $this->photos->delete($path);
        }
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function invalid(array $errors): array
    {
        return ['status' => 422, 'body' => [
            'ok' => false,
            'message' => 'Certaines informations sont à corriger.',
            'errors' => $errors,
        ]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function success(array $row): array
    {
        return ['status' => 200, 'body' => [
            'ok' => true,
            'reference' => $row['reference'],
            'courriel_candidat_echec' => $row['courriel_candidat_statut'] === InscriptionRepository::MAIL_FAILED,
        ]];
    }
}
