<?php

namespace App\Enums;

/**
 * Statut d'un QR (qr_codes.status).
 */
enum QrStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
