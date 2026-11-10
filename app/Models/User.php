<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte Administrateur, Gestionnaire ou Agent. Un seul rôle par compte (règle applicative).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'status',
        'must_change_password',
        'last_login_at',
        'archived_at',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ProfessionalConsultation::class, 'agent_id');
    }
}
