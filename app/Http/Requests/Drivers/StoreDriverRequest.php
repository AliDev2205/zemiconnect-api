<?php

namespace App\Http\Requests\Drivers;

use App\Http\Requests\Documents\StoreDocumentRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape Identité (GE3) : le dossier est créé En attente dès cette étape.
 * Identifiant du conducteur et fiche papier facultatifs (RG01, RG03).
 */
class StoreDriverRequest extends FormRequest
{
    public const PHONE_PATTERN = '/^\+?[0-9][0-9 ().-]{5,19}$/';

    public function authorize(): bool
    {
        return true; // vérifié par la route (can:create,App\Models\Driver)
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'phone' => ['required', 'string', 'max:20', 'regex:'.self::PHONE_PATTERN],
            'address' => ['required', 'string', 'max:255'],
            'identification_number' => ['nullable', 'string', 'max:50'],
            'registration_form' => ['nullable', ...StoreDocumentRequest::fileRules()],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
            'registration_form.mimetypes' => 'Le contenu du fichier ne correspond pas à un PDF, un JPG ou un PNG.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['last_name', 'first_name', 'phone', 'address', 'identification_number'])
            ->filter(fn ($field) => is_string($this->input($field)))
            ->mapWithKeys(fn ($field) => [$field => trim((string) $this->input($field)) ?: null])
            ->all());
    }
}
