<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle de vie des comptes. Désactiver ou archiver un compte coupe
 * immédiatement toutes ses sessions (H4, CA20) : lignes de la table
 * sessions supprimées et cookie « Rester connecté » invalidé.
 */
class AccountService
{
    public function deactivate(User $user, ?User $by = null, ?string $reason = null): User
    {
        return $this->changeStatus($user, UserStatus::Inactive, $by, $reason);
    }

    public function archive(User $user, ?User $by = null, ?string $reason = null): User
    {
        return $this->changeStatus($user, UserStatus::Archived, $by, $reason);
    }

    /**
     * Supprime les sessions en base d'un compte, sauf éventuellement la session courante.
     */
    public function revokeSessions(User $user, ?string $exceptSessionId = null): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    private function changeStatus(User $user, UserStatus $status, ?User $by, ?string $reason): User
    {
        return DB::transaction(function () use ($user, $status, $by, $reason) {
            $old = $user->status;

            $user->forceFill([
                'status' => $status,
                'archived_at' => $status === UserStatus::Archived ? now() : $user->archived_at,
                // Invalide le cookie de reconnexion (session longue).
                'remember_token' => Str::random(60),
            ])->save();

            $this->revokeSessions($user);

            activity()
                ->useLog('audit')
                ->event($status === UserStatus::Archived ? 'archived' : 'deactivated')
                ->performedOn($user)
                ->causedBy($by)
                ->withProperties([
                    'old' => ['status' => $old?->value],
                    'attributes' => ['status' => $status->value],
                    'reason' => $reason,
                ])
                ->log($status === UserStatus::Archived ? 'user.archived' : 'user.deactivated');

            return $user;
        });
    }
}
