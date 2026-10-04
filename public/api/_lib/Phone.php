<?php
declare(strict_types=1);

namespace Evh;

/**
 * Numéros de téléphone : normalisation au format international E.164.
 * Les numéros à 10 chiffres sans indicatif sont considérés comme canadiens
 * ou américains (+1). Pour les autres pays, l'indicatif est obligatoire.
 * Même logique que src/scripts/eden-validation.js côté navigateur.
 */
final class Phone
{
    private const NANP = '/^1([2-9]\d{2})([2-9]\d{2})(\d{4})$/';

    public static function normalize(string $input): ?string
    {
        $value = preg_replace('/[\s().\-\x{00A0}]/u', '', trim($input)) ?? '';
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        if (str_starts_with($value, '+')) {
            $digits = substr($value, 1);
            if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
                return null;
            }
            if ($digits[0] === '1' && !preg_match(self::NANP, $digits)) {
                return null;
            }

            return '+' . $digits;
        }

        if (!ctype_digit($value)) {
            return null;
        }
        if (strlen($value) === 10) {
            $value = '1' . $value;
        }

        return preg_match(self::NANP, $value) ? '+' . $value : null;
    }

    /** Affichage lisible : +1 (418) 490-0186 pour l'Amérique du Nord. */
    public static function format(string $e164): string
    {
        if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)) {
            return sprintf('+1 (%s) %s-%s', $m[1], $m[2], $m[3]);
        }

        return $e164;
    }
}
