<?php
declare(strict_types=1);

namespace Evh\Eden;

use PDO;

/**
 * Accès à la table eden_inscriptions. Toutes les requêtes sont préparées.
 */
final class InscriptionRepository
{
    public const MAIL_PENDING = 'en_attente';
    public const MAIL_SENT = 'envoye';
    public const MAIL_FAILED = 'echec';

    private const COLUMNS = [
        'repondant_email', 'repondant_nom', 'repondant_date_naissance', 'repondant_nationalite',
        'repondant_telephone', 'repondant_profession', 'membre_evh', 'congregation',
        'baptise_immersion', 'relation_precedente', 'deja_marie',
        'duree_valeur', 'duree_unite',
        'cheminant_nom', 'cheminant_date_naissance', 'cheminant_nationalite', 'cheminant_telephone',
        'cheminant_profession', 'cheminant_pays', 'cheminant_ville', 'cheminant_eglise',
        'bloquants', 'pret_classes', 'frais_confirmes',
        'photo_repondant', 'photo_cheminant',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findBySubmissionId(string $submissionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM eden_inscriptions WHERE submission_id = ?');
        $stmt->execute([$submissionId]);

        return $stmt->fetch() ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM eden_inscriptions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Enregistre l'inscription et lui attribue une référence lisible (EDEN-2026-0012).
     *
     * @param array<string, mixed> $data données validées + chemins des photos
     * @return array<string, mixed> la ligne enregistrée
     */
    public function create(string $submissionId, array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $row = ['submission_id' => $submissionId, 'cree_le' => $now, 'frais_confirmes_le' => $now];
        foreach (self::COLUMNS as $column) {
            $value = $data[$column] ?? null;
            $row[$column] = is_bool($value) ? (int) $value : $value;
        }

        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO eden_inscriptions (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare($sql)->execute(array_values($row));
            $id = (int) $this->pdo->lastInsertId();
            $reference = sprintf('EDEN-%s-%04d', date('Y'), $id);
            $this->pdo->prepare('UPDATE eden_inscriptions SET reference = ? WHERE id = ?')->execute([$reference, $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id) ?? throw new \RuntimeException('Inscription introuvable après enregistrement.');
    }

    public function recordMailResult(int $id, string $teamStatus, string $candidateStatus, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE eden_inscriptions
                SET courriel_equipe_statut = ?, courriel_candidat_statut = ?, courriel_erreur = ?,
                    courriel_tentatives = courriel_tentatives + 1, courriel_derniere_tentative = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $teamStatus,
            $candidateStatus,
            $error === null ? null : mb_substr($error, 0, 255),
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /** Les photos ont été transmises à l'équipe et supprimées du serveur. */
    public function clearPhotos(int $id): void
    {
        $this->pdo->prepare('UPDATE eden_inscriptions SET photo_repondant = NULL, photo_cheminant = NULL WHERE id = ?')
            ->execute([$id]);
    }

    /** Inscriptions dont au moins un courriel n'est pas parti. @return list<array<string, mixed>> */
    public function findWithUnsentMail(int $maxAttempts): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM eden_inscriptions
              WHERE (courriel_equipe_statut <> 'envoye' OR courriel_candidat_statut <> 'envoye')
                AND courriel_tentatives < ?
              ORDER BY id"
        );
        $stmt->execute([$maxAttempts]);

        return $stmt->fetchAll();
    }

    public static function isDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
