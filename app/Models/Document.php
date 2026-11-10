<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document du conducteur ou assurance de la moto. Un renouvellement crée un
 * nouveau document ; l'ancien passe à is_current = false (RG11).
 */
class Document extends Model
{
    protected $fillable = [
        'driver_id',
        'motorcycle_id',
        'type',
        'number',
        'category',
        'issuer',
        'issued_at',
        'expires_at',
        'file_path',
        'file_mime',
        'file_size',
        'is_current',
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['file_path', 'current_driver_id', 'current_motorcycle_id'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'issued_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
            'file_size' => 'integer',
            'is_current' => 'boolean',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
