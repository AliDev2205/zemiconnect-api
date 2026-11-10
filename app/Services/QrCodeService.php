<?php

namespace App\Services;

use App\Enums\QrStatus;
use App\Models\Driver;
use App\Models\QrCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * QR du conducteur (RG12, RG13). Lot 2 : création à la validation et
 * révocation à l'archivage ; régénération, images et badge au lot suivant.
 * À appeler dans la transaction de l'opération appelante.
 */
class QrCodeService
{
    public function createActive(Driver $driver, User $by, ?string $reason = null): QrCode
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $qr = QrCode::query()->create([
                    'driver_id' => $driver->id,
                    // Str::random : base62 tiré de random_bytes (aléa cryptographique).
                    'token' => Str::random((int) config('zemiconnect.qr.token_length', 16)),
                    'status' => QrStatus::Active,
                    'generation_reason' => $reason,
                    'generated_by' => $by->id,
                ]);

                Audit::record('qr.generated', $qr, new: ['status' => QrStatus::Active], reason: $reason, driverId: $driver->id, label: $driver->fullName());

                return $qr;
            } catch (UniqueConstraintViolationException $e) {
                // Collision de jeton (improbable) : nouveau tirage. Deuxième QR actif : erreur réelle.
                if (! str_contains($e->getMessage(), 'qr_codes_token_unique')) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Impossible de générer un jeton QR unique.');
    }

    public function revokeActive(Driver $driver, User $by, string $reason): ?QrCode
    {
        $qr = $driver->activeQrCode()->lockForUpdate()->first();

        if ($qr === null) {
            return null;
        }

        $qr->forceFill([
            'status' => QrStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            'revoke_reason' => $reason,
        ])->save();

        Audit::record('qr.revoked', $qr, ['status' => QrStatus::Active], ['status' => QrStatus::Revoked], $reason, $driver->id, $driver->fullName());

        return $qr;
    }
}
