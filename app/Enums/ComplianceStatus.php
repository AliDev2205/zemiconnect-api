<?php

namespace App\Enums;

/**
 * Conformité documentaire calculée (drivers.compliance_status), indépendante du statut (RG05).
 */
enum ComplianceStatus: string
{
    case Conforme = 'conforme';
    case ExpireBientot = 'expire_bientot';
    case NonConforme = 'non_conforme';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
