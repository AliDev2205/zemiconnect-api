<?php

namespace App\Models;

use App\Enums\QrStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * QR d'un conducteur. Ne se supprime pas : il se révoque (RG12).
 */
class QrCode extends Model
{
    protected $fillable = [
        'driver_id',
        'token',
        'status',
        'generation_reason',
        'generated_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $hidden = ['active_driver_id'];

    protected function casts(): array
    {
        return [
            'status' => QrStatus::class,
            'revoked_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ProfessionalConsultation::class);
    }
}
