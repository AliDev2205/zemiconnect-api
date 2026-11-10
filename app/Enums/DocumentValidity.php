<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Validité d'un document : valeur calculée, sans colonne en base.
 * - manquant : aucun document actuel ;
 * - expire : date d'expiration antérieure à aujourd'hui (un document expire à la fin de son dernier jour) ;
 * - expire_bientot : expiration dans les 30 jours (bornes incluses : aujourd'hui et J+30) ;
 * - valide : au-delà, ou sans date d'expiration (B3).
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

    /**
     * « Aujourd'hui » dans le fuseau métier (Bénin), à minuit.
     */
    public static function today(): Carbon
    {
        return Carbon::today((string) config('zemiconnect.timezone', 'Africa/Porto-Novo'));
    }

    public static function fromExpiry(?CarbonInterface $expiresAt, ?CarbonInterface $today = null): self
    {
        if ($expiresAt === null) {
            return self::Valide;
        }

        $today ??= self::today();
        $expiry = $expiresAt->toDateString();

        if ($expiry < $today->toDateString()) {
            return self::Expire;
        }

        $warningDays = (int) config('zemiconnect.compliance.expiry_warning_days', 30);
        $limit = $today->copy()->addDays($warningDays)->toDateString();

        return $expiry <= $limit ? self::ExpireBientot : self::Valide;
    }
}
