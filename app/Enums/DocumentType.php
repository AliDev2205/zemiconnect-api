<?php

namespace App\Enums;

/**
 * Type de document (documents.type). L'assurance appartient à la moto, les autres types au conducteur (B2).
 */
enum DocumentType: string
{
    case IdCard = 'id_card';
    case DrivingLicense = 'driving_license';
    case TaxiLicense = 'taxi_license';
    case RegistrationForm = 'registration_form';
    case Insurance = 'insurance';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
