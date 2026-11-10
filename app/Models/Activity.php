<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Entrée du journal d'audit. Étend le modèle du paquet pour une seule raison :
 * renseigner ip_address (§21, C11) sur chaque entrée, sans l'écrire à chaque appel.
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->ip_address === null && app()->bound('request')) {
                $activity->ip_address = request()->ip();
            }
        });
    }
}
