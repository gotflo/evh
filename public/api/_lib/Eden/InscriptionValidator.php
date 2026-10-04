<?php
declare(strict_types=1);

namespace Evh\Eden;

use Evh\Phone;

/**
 * Validation serveur du formulaire d'inscription.
 * Seuls les champs listés ici sont lus : tout champ inattendu est ignoré.
 * Mêmes règles que src/scripts/eden-validation.js côté navigateur.
 */
final class InscriptionValidator
{
    public const MIN_AGE = 18;
    public const MAX_AGE = 100;
    public const DURATION_MAX = 99;

    /** Champs texte : nom => [longueur max, est un nom de personne]. */
    private const TEXT_FIELDS = [
        'repondant_nom' => [120, true],
        'repondant_nationalite' => [80, false],
        'repondant_profession' => [100, false],
        'cheminant_nom' => [120, true],
        'cheminant_nationalite' => [80, false],
        'cheminant_profession' => [100, false],
        'cheminant_pays' => [80, false],
        'cheminant_ville' => [80, false],
        'cheminant_eglise' => [150, false],
    ];

    private const YES_NO_FIELDS = [
        'membre_evh',
        'baptise_immersion',
        'relation_precedente',
        'deja_marie',
        'bloquants',
        'pret_classes',
    ];

    public const DURATION_UNITS = ['mois', 'annees'];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed> $input  $_POST
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $this->errors = [];
        $this->data = [];

        $submissionId = $this->string($input, 'submission_id');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $submissionId)) {
            $this->errors['submission_id'] = 'Le formulaire a expiré. Rechargez la page puis réessayez.';
        }
        $this->data['submission_id'] = $submissionId;

        $this->email($input, 'repondant_email');

        foreach (self::TEXT_FIELDS as $field => [$max, $isName]) {
            $this->text($input, $field, $max, $isName);
        }

        $this->birthDate($input, 'repondant_date_naissance');
        $this->birthDate($input, 'cheminant_date_naissance');
        $this->phone($input, 'repondant_telephone');
        $this->phone($input, 'cheminant_telephone');

        foreach (self::YES_NO_FIELDS as $field) {
            $this->yesNo($input, $field);
        }

        // La congrégation n'est demandée qu'aux personnes qui ne sont pas de Vases d'Honneur.
        if (($this->data['membre_evh'] ?? null) === false) {
            $this->text($input, 'congregation', 150, false);
        } else {
            $this->data['congregation'] = null;
        }

        $this->duration($input);

        if ($this->string($input, 'confirmation_frais') !== '1') {
            $this->errors['confirmation_frais'] = "Vous devez confirmer avoir pris connaissance des frais d'inscription.";
        }
        $this->data['frais_confirmes'] = !isset($this->errors['confirmation_frais']);

        return ['data' => $this->data, 'errors' => $this->errors];
    }

    /** Valeur scalaire nettoyée ; un tableau envoyé à la place d'un texte devient vide. */
    private function string(array $input, string $field): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        // Retire les caractères de contrôle et regroupe les espaces.
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function email(array $input, string $field): void
    {
        $value = $this->string($input, $field);
        $this->data[$field] = $value;

        if ($value === '') {
            $this->errors[$field] = 'Indiquez votre adresse courriel.';
        } elseif (
            mb_strlen($value) > 254
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
            || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $value)
        ) {
            $this->errors[$field] = 'Cette adresse courriel ne semble pas valide.';
        }
    }

    private function text(array $input, string $field, int $max, bool $isName): void
    {
        $value = $this->string($input, $field);
        $this->data[$field] = $value;

        if ($value === '') {
            $this->errors[$field] = 'Ce champ est obligatoire.';
        } elseif (mb_strlen($value) > $max) {
            $this->errors[$field] = "Ce texte est trop long ({$max} caractères au maximum).";
        } elseif ($isName && !preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M}' .\\-’]+$/u", $value)) {
            $this->errors[$field] = 'Utilisez uniquement des lettres, espaces, traits d\'union ou apostrophes.';
        } elseif (!$isName && preg_match('/[<>{}\\\\]/', $value)) {
            $this->errors[$field] = 'Certains caractères spéciaux ne sont pas acceptés.';
        }
    }

    private function birthDate(array $input, string $field): void
    {
        $value = $this->string($input, $field);
        $this->data[$field] = $value;

        if ($value === '') {
            $this->errors[$field] = 'Indiquez la date de naissance.';
            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = "Cette date n'est pas valide.";
            return;
        }

        $today = new \DateTimeImmutable('today');
        if ($date > $today) {
            $this->errors[$field] = 'La date ne peut pas être dans le futur.';
        } elseif ($date->diff($today)->y < self::MIN_AGE) {
            $this->errors[$field] = 'Les cours Eden sont réservés aux personnes de ' . self::MIN_AGE . ' ans et plus.';
        } elseif ($date->diff($today)->y > self::MAX_AGE) {
            $this->errors[$field] = "Vérifiez l'année de naissance.";
        }
    }

    private function phone(array $input, string $field): void
    {
        $value = $this->string($input, $field);
        $normalized = $value === '' ? null : Phone::normalize($value);
        $this->data[$field] = $normalized ?? $value;

        if ($value === '') {
            $this->errors[$field] = 'Indiquez un numéro de téléphone.';
        } elseif ($normalized === null) {
            $this->errors[$field] = 'Ce numéro ne semble pas valide. Hors Canada et États-Unis, commencez par l\'indicatif du pays (ex. +225).';
        }
    }

    private function yesNo(array $input, string $field): void
    {
        $value = $this->string($input, $field);
        if ($value === 'oui' || $value === 'non') {
            $this->data[$field] = $value === 'oui';
        } else {
            $this->data[$field] = null;
            $this->errors[$field] = 'Choisissez une réponse.';
        }
    }

    private function duration(array $input): void
    {
        $value = $this->string($input, 'duree_valeur');
        $unit = $this->string($input, 'duree_unite');

        if (!preg_match('/^\d{1,2}$/', $value) || (int) $value < 1 || (int) $value > self::DURATION_MAX) {
            $this->errors['duree_valeur'] = 'Indiquez un nombre entier entre 1 et ' . self::DURATION_MAX . '.';
        }
        if (!in_array($unit, self::DURATION_UNITS, true)) {
            $this->errors['duree_unite'] = 'Choisissez mois ou années.';
        }

        $this->data['duree_valeur'] = (int) $value;
        $this->data['duree_unite'] = $unit;
    }
}
