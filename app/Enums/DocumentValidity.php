<?php

namespace App\Enums;

/**
 * Validité d'un document : valeur calculée, sans colonne en base (document sans date d'expiration = valide, B3).
 */
enum DocumentValidity: string
{
    case Valide = 'valide';
    case ExpireBientot = 'expire_bientot';
    case Expire = 'expire';
    case Manquant = 'manquant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
