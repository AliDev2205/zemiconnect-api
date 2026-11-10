<?php

namespace App\Http\Requests\Motorcycles;

use Illuminate\Foundation\Http\FormRequest;

/**
 * L'archivage exige un motif (§15, C7).
 */
class ArchiveMotorcycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route
    }

    public function rules(): array
    {
        return [
            'archive_reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['archive_reason.required' => 'Le motif est obligatoire pour archiver une moto.'];
    }
}
