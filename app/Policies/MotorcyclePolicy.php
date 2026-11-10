<?php

namespace App\Policies;

use App\Models\Motorcycle;
use App\Models\User;

/**
 * Motos et historique des affectations (§11) : gestionnaire en écriture, administrateur en lecture.
 */
class MotorcyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('motorcycles.view');
    }

    public function view(User $user, Motorcycle $motorcycle): bool
    {
        return $user->can('motorcycles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('motorcycles.manage');
    }

    /** Modifier, archiver. */
    public function update(User $user, Motorcycle $motorcycle): bool
    {
        return $user->can('motorcycles.manage');
    }
}
