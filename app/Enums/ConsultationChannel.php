<?php

namespace App\Enums;

/**
 * Canal d'une consultation agent (professional_consultations.channel).
 */
enum ConsultationChannel: string
{
    case Qr = 'qr';
    case Recherche = 'recherche';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
