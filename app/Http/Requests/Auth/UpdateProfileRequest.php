<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Profil (A8) : nom, prénom, téléphone. L'email n'est pas modifiable par l'utilisateur.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
