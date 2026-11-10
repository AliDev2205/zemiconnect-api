<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation datée conducteur <-> moto. Ne se supprime pas : elle se clôt (ended_at).
 */
class Assignment extends Model
{
    protected $table = 'driver_motorcycle_assignments';

    protected $fillable = [
        'driver_id',
        'motorcycle_id',
        'started_at',
        'ended_at',
        'start_reason',
        'end_reason',
        'created_by',
        'ended_by',
    ];

    protected $hidden = ['open_driver_id', 'open_motorcycle_id', 'created_by', 'ended_by', 'updated_at', 'driver', 'motorcycle'];

    /** Libellés dérivés pour l'historique (relations driver et motorcycle). */
    protected $appends = ['driver_name', 'plate_number'];

    public function getDriverNameAttribute(): ?string
    {
        return $this->driver ? trim("{$this->driver->first_name} {$this->driver->last_name}") : null;
    }

    public function getPlateNumberAttribute(): ?string
    {
        return $this->motorcycle?->plate_number;
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'date:Y-m-d',
            'ended_at' => 'date:Y-m-d',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function motorcycle(): BelongsTo
    {
        return $this->belongsTo(Motorcycle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
