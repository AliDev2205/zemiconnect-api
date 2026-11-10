<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route (can:update,driver)
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Le motif est obligatoire pour archiver un dossier.'];
    }
}
