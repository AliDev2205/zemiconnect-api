<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

/**
 * Dossiers conducteurs (§11) : le gestionnaire écrit ; l'administrateur lit
 * (toute écriture lui répond 403) ; l'agent n'a accès à aucune route de gestion
 * (sa consultation professionnelle arrive au lot 3).
 */
class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('drivers.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->can('drivers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('drivers.manage');
    }

    /** Modifier, photo, valider, suspendre, réactiver, archiver, rechercher les doublons. */
    public function update(User $user, Driver $driver): bool
    {
        return $user->can('drivers.manage');
    }

    public function assignMotorcycle(User $user, Driver $driver): bool
    {
        return $user->can('assignments.manage');
    }

    public function viewAssignments(User $user, Driver $driver): bool
    {
        return $user->can('motorcycles.view');
    }
}
