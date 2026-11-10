<?php

namespace App\Http\Requests\Motorcycles;

use App\Models\Motorcycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moto (RG10) : immatriculation normalisée (majuscules, sans espaces ni tirets)
 * avant le contrôle d'unicité ; châssis unique s'il est renseigné.
 */
class StoreMotorcycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // vérifié par la route
    }

    public function rules(): array
    {
        $ignore = $this->route('motorcycle') instanceof Motorcycle ? $this->route('motorcycle')->id : null;

        return [
            'plate_number' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]{3,20}$/', Rule::unique('motorcycles', 'plate_number')->ignore($ignore)],
            'chassis_number' => ['nullable', 'string', 'max:50', Rule::unique('motorcycles', 'chassis_number')->ignore($ignore)],
            'brand' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:30'],
            'manufacture_year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
        ];
    }

    public function messages(): array
    {
        return [
            'plate_number.unique' => 'Cette immatriculation est déjà enregistrée.',
            'plate_number.regex' => 'L\'immatriculation ne doit contenir que des lettres et des chiffres.',
            'chassis_number.unique' => 'Ce numéro de châssis est déjà enregistré.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('plate_number'))) {
            $data['plate_number'] = Motorcycle::normalizePlate($this->input('plate_number'));
        }
        if (is_string($this->input('chassis_number'))) {
            $chassis = strtoupper((string) preg_replace('/\s+/', '', $this->input('chassis_number')));
            $data['chassis_number'] = $chassis === '' ? null : $chassis;
        }
        foreach (['brand', 'model', 'color', 'manufacture_year'] as $field) {
            if ($this->input($field) === '') {
                $data[$field] = null;
            }
        }

        $this->merge($data);
    }
}
