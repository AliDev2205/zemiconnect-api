<?php

namespace App\Enums;

/**
 * Statut du dossier conducteur (drivers.status).
 */
enum DriverStatus: string
{
    case EnAttente = 'en_attente';
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Archive = 'archive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
