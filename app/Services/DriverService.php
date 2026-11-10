<?php

namespace App\Services;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Driver;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dossier conducteur : création En attente dès l'étape Identité (GE3, P1),
 * modification, photo. La fiche papier scannée est un document registration_form.
 */
class DriverService
{
    /** Champs de l'étape Identité. */
    public const IDENTITY_FIELDS = ['last_name', 'first_name', 'birth_date', 'phone', 'address', 'identification_number'];

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * @param  array<string, mixed>  $identity
     */
    public function create(array $identity, ?UploadedFile $registrationForm, User $by): Driver
    {
        $driver = DB::transaction(function () use ($identity, $by) {
            $driver = Driver::query()->create([
                ...array_intersect_key($identity, array_flip(self::IDENTITY_FIELDS)),
                'status' => DriverStatus::EnAttente,
                'compliance_status' => ComplianceStatus::NonConforme,
                'created_by' => $by->id,
            ]);

            Audit::record('driver.created', $driver, new: $this->identity($driver), driverId: $driver->id, label: $driver->fullName());

            return $driver;
        });

        if ($registrationForm !== null) {
            $this->documents->add($driver, ['type' => DocumentType::RegistrationForm->value], $registrationForm, $by);
        }

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function update(Driver $driver, array $identity, ?UploadedFile $registrationForm, User $by): Driver
    {
        $driver = DB::transaction(function () use ($driver, $identity, $by) {
            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);

            if ($driver->isArchived()) {
                throw BusinessRuleException::driverArchived();
            }

            $driver->fill(array_intersect_key($identity, array_flip(self::IDENTITY_FIELDS)));

            if ($driver->isDirty()) {
                [$old, $new] = Audit::diff($driver);
                $driver->updated_by = $by->id;
                $driver->save();
                Audit::record('driver.updated', $driver, $old, $new, driverId: $driver->id, label: $driver->fullName());
            }

            return $driver;
        });

        if ($registrationForm !== null) {
            $this->documents->add($driver, ['type' => DocumentType::RegistrationForm->value], $registrationForm, $by);
        }

        return $driver;
    }

    /**
     * Photo (upload ou remplacement). Réencodée en JPEG quand GD est disponible :
     * supprime les métadonnées EXIF et tout contenu piégé (§17). L'ancien fichier est conservé.
     */
    public function updatePhoto(Driver $driver, UploadedFile $photo, User $by): Driver
    {
        $disk = Storage::disk(config('zemiconnect.files.disk'));
        $path = 'drivers/photos/'.now()->format('Y/m').'/'.Str::random(40).'.jpg';
        $disk->put($path, $this->reencode($photo));

        try {
            return DB::transaction(function () use ($driver, $path, $by) {
                $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);

                if ($driver->isArchived()) {
                    throw BusinessRuleException::driverArchived();
                }

                $hadPhoto = $driver->photo_path !== null;
                $driver->forceFill(['photo_path' => $path, 'updated_by' => $by->id])->save();

                Audit::record('driver.photo_updated', $driver, ['has_photo' => $hadPhoto], ['has_photo' => true], driverId: $driver->id, label: $driver->fullName());

                return $driver;
            });
        } catch (BusinessRuleException $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    private function reencode(UploadedFile $photo): string
    {
        $raw = (string) file_get_contents($photo->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            return $raw;
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return $raw;
        }

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Driver $driver): array
    {
        return array_intersect_key($driver->getAttributes(), array_flip(self::IDENTITY_FIELDS));
    }
}
