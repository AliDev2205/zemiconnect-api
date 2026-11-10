<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentType;
use App\Models\Motorcycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout ou renouvellement d'un document. Le rattachement est vérifié ici
 * pour un 422 lisible (le CHECK documents_single_owner le garantit en base) :
 * assurance -> moto ; pièce d'identité, permis, droit de taxi, fiche papier -> conducteur.
 */
class StoreDocumentRequest extends FormRequest
{
    public const DRIVER_TYPES = ['id_card', 'driving_license', 'taxi_license', 'registration_form'];

    public const MOTORCYCLE_TYPES = ['insurance'];

    /**
     * Fichier : PDF, JPG ou PNG, 5 Mo au plus. « mimes » et « mimetypes » lisent
     * le contenu réel (finfo), « extensions » le nom envoyé : les trois doivent concorder.
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return [
            'file',
            'max:'.config('zemiconnect.files.max_kilobytes'),
            'extensions:pdf,jpg,jpeg,png',
            'mimes:pdf,jpg,jpeg,png',
            'mimetypes:application/pdf,image/jpeg,image/png',
        ];
    }

    public function authorize(): bool
    {
        return true; // vérifié par la route (can:create,App\Models\Document)
    }

    public function rules(): array
    {
        $forMotorcycle = $this->route('motorcycle') instanceof Motorcycle;
        $allowed = $forMotorcycle ? self::MOTORCYCLE_TYPES : self::DRIVER_TYPES;

        $expires = ['nullable', 'date_format:Y-m-d', 'required_if:type,'.DocumentType::Insurance->value];
        if ($this->filled('issued_at')) {
            $expires[] = 'after:issued_at';
        }

        return [
            'type' => ['required', 'string', Rule::in($allowed)],
            'number' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'issuer' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_at' => $expires,
            'file' => ['required_if:type,'.DocumentType::RegistrationForm->value, 'nullable', ...self::fileRules()],
        ];
    }

    public function messages(): array
    {
        $forMotorcycle = $this->route('motorcycle') instanceof Motorcycle;

        return [
            'type.in' => $forMotorcycle
                ? 'Seule l\'assurance se rattache à la moto.'
                : 'L\'assurance se rattache à la moto, pas au conducteur.',
            'expires_at.required_if' => 'La date d\'expiration est obligatoire pour l\'assurance.',
            'expires_at.after' => 'La date d\'expiration doit être postérieure à la date de délivrance.',
            'file.required_if' => 'Le fichier est obligatoire pour la fiche papier.',
            'file.extensions' => 'Le fichier doit être un PDF, un JPG ou un PNG.',
            'file.mimes' => 'Le fichier doit être un PDF, un JPG ou un PNG.',
            'file.mimetypes' => 'Le contenu du fichier ne correspond pas à un PDF, un JPG ou un PNG.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Champ vide envoyé par un formulaire multipart = pas de date.
        foreach (['issued_at', 'expires_at', 'number', 'category', 'issuer'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
