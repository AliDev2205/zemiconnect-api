<?php

namespace App\Http\Requests\Drivers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Photo du conducteur : JPEG ou PNG (contenu réel), 5 Mo au plus.
 * Le frontend envoie un JPEG carré déjà recadré ; l'API le réencode.
 */
class UpdateDriverPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route (can:update,driver)
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required', 'file', 'max:'.config('zemiconnect.files.max_kilobytes'),
                'extensions:jpg,jpeg,png', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png',
                'dimensions:min_width=200,min_height=200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'La photo doit être une image JPEG ou PNG.',
            'photo.mimes' => 'La photo doit être une image JPEG ou PNG.',
            'photo.extensions' => 'La photo doit être une image JPEG ou PNG.',
        ];
    }
}
