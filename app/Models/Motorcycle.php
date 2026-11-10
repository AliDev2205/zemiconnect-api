<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\MotorcycleStatus;
use Database\Factories\MotorcycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Moto. Porte l'assurance (RG07). Jamais supprimée : archivée.
 */
class Motorcycle extends Model
{
    /** @use HasFactory<MotorcycleFactory> */
    use HasFactory;

    protected $fillable = [
        'plate_number',
        'chassis_number',
        'brand',
        'model',
        'color',
        'manufacture_year',
        'status',
        'archived_at',
        'archive_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'manufacture_year' => 'integer',
            'status' => MotorcycleStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Forme stockée d'une immatriculation : majuscules, sans espaces ni tirets.
     */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $plate));
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function currentInsurance(): HasOne
    {
        return $this->hasOne(Document::class)
            ->where('type', DocumentType::Insurance)
            ->where('is_current', true);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(Assignment::class)->whereNull('ended_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
