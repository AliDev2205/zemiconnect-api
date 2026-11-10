<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Documents (§11) : ajout et renouvellement par le gestionnaire ; fichiers
 * ouverts par le gestionnaire et l'administrateur (documents.view_file),
 * jamais par l'agent (RG17).
 */
class DocumentPolicy
{
    public function create(User $user): bool
    {
        return $user->can('documents.manage');
    }

    public function viewFile(User $user, Document $document): bool
    {
        return $user->can('documents.view_file');
    }
}
