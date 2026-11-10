<?php

namespace App\Enums;

/**
 * Statut d'une moto (motorcycles.status). Ajouté aux sept enums demandés : la colonne existe au MLD.
 */
enum MotorcycleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
