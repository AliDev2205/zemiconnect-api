<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Affecter ou changer la moto (P6). Date et motif obligatoires pour un
 * changement : vérifiés par AssignmentService, qui connaît l'affectation en cours.
 */
class AssignMotorcycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route (can:assignMotorcycle,driver)
    }

    public function rules(): array
    {
        return [
            'motorcycle_id' => ['required', 'integer', 'exists:motorcycles,id'],
            'started_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'start_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
