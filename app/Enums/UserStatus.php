<?php

namespace App\Enums;

/**
 * Statut d'un compte (users.status).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
