<?php

namespace App\Services;

use App\Models\Driver;
use RuntimeException;

/**
 * Seule classe qui fabrique et vérifie les numéros ZemiConnect (R1/B4).
 *
 * Format : préfixe (config zemiconnect.zemi_number.prefix, « ZC- » par défaut)
 * + 6 chiffres aléatoires + 1 chiffre de contrôle Luhn. Exemple : ZC-4829172.
 *
 * generate() doit être appelé dans la transaction de validation du dossier.
 * Il tire un numéro libre et réessaie en cas de collision ; l'index unique
 * de drivers.zemi_number reste le dernier rempart en cas de course entre
 * deux validations simultanées (l'appelant rejoue alors la transaction).
 */
class ZemiNumberGenerator
{
    public function generate(): string
    {
        $attempts = (int) config('zemiconnect.zemi_number.max_attempts', 10);

        for ($i = 0; $i < $attempts; $i++) {
            $candidate = $this->candidate();

            // Les numéros des dossiers archivés restent en base : jamais réutilisés.
            if (! Driver::query()->where('zemi_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("Impossible de générer un numéro ZemiConnect libre après {$attempts} tentatives.");
    }

    /**
     * Tire un numéro bien formé, sans vérifier sa disponibilité.
     */
    public function candidate(): string
    {
        $length = $this->randomDigits();
        $payload = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        return $this->prefix().$payload.self::luhnCheckDigit($payload);
    }

    /**
     * Vrai si le numéro a le bon format ET une clé de contrôle correcte.
     * Sert à la recherche pour signaler une faute de frappe plutôt que « introuvable ».
     */
    public function isValid(string $number): bool
    {
        $digits = $this->digitsOf($number);

        if ($digits === null) {
            return false;
        }

        $payload = substr($digits, 0, -1);

        return self::luhnCheckDigit($payload) === (int) substr($digits, -1);
    }

    /**
     * Forme canonique d'une saisie : « zc 482 9172 » ou « ZC4829172 » → « ZC-4829172 ».
     * Renvoie la saisie nettoyée (majuscules, sans espaces) si elle ne ressemble pas à un numéro.
     */
    public function normalize(string $input): string
    {
        $digits = $this->digitsOf($input);

        if ($digits === null) {
            return strtoupper((string) preg_replace('/\s+/', '', $input));
        }

        return $this->prefix().$digits;
    }

    /**
     * Chiffre de contrôle Luhn d'une suite de chiffres.
     */
    public static function luhnCheckDigit(string $payload): int
    {
        $sum = 0;
        $double = true; // le chiffre le plus à droite de la charge utile est doublé

        for ($i = strlen($payload) - 1; $i >= 0; $i--) {
            $digit = (int) $payload[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Extrait les 7 chiffres (6 + clé) d'une saisie, préfixe facultatif,
     * espaces et tirets ignorés. null si la saisie n'a pas cette forme.
     */
    private function digitsOf(string $input): ?string
    {
        $prefix = strtoupper((string) preg_replace('/[\s\-]+/', '', $this->prefix()));
        $clean = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));

        if ($prefix !== '' && str_starts_with($clean, $prefix)) {
            $clean = substr($clean, strlen($prefix));
        }

        $expected = $this->randomDigits() + 1;

        return preg_match('/^\d{'.$expected.'}$/', $clean) === 1 ? $clean : null;
    }

    private function prefix(): string
    {
        return (string) config('zemiconnect.zemi_number.prefix', 'ZC-');
    }

    private function randomDigits(): int
    {
        return (int) config('zemiconnect.zemi_number.random_digits', 6);
    }
}
