<?php

namespace App\Models;

use App\Enums\ConsultationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consultation des informations professionnelles par un agent (G6). Ajout seul.
 */
class ProfessionalConsultation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_id',
        'driver_id',
        'qr_code_id',
        'channel',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConsultationChannel::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }
}
