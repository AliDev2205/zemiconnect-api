<?php

namespace App\Models;

use App\Enums\ComplianceStatus;
use App\Enums\DriverStatus;
use App\Enums\QrStatus;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Dossier du conducteur. Jamais supprimé : archivé (RG20).
 */
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    protected $fillable = [
        'zemi_number',
        'last_name',
        'first_name',
        'birth_date',
        'identification_number',
        'phone',
        'address',
        'photo_path',
        'status',
        'status_reason',
        'compliance_status',
        'compliance_checked_at',
        'validated_at',
        'validated_by',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'status' => DriverStatus::class,
            'compliance_status' => ComplianceStatus::class,
            'compliance_checked_at' => 'datetime',
            'validated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function currentDocuments(): HasMany
    {
        return $this->documents()->where('is_current', true);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(Assignment::class)->whereNull('ended_at');
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function activeQrCode(): HasOne
    {
        return $this->hasOne(QrCode::class)->where('status', QrStatus::Active);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ProfessionalConsultation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
