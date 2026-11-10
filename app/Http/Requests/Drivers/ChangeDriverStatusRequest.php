<?php

namespace App\Http\Requests\Drivers;

use App\Enums\DriverStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suspendre (motif obligatoire) ou réactiver. L'archivage a sa propre route.
 */
class ChangeDriverStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route (can:update,driver)
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([DriverStatus::Suspendu->value, DriverStatus::Actif->value])],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,'.DriverStatus::Suspendu->value],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Statut demandé invalide : utilisez « suspendu » ou « actif » (archivage : route dédiée).',
            'reason.required_if' => 'Le motif est obligatoire pour suspendre un dossier.',
        ];
    }
}
