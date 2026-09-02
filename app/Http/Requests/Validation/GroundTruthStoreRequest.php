<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GroundTruthStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'field_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'location' => ['required', 'array'],
            'location.type' => ['required', 'string', Rule::in(['Point'])],
            'location.coordinates' => ['required', 'array', 'size:2'],
            'location.coordinates.0' => ['required', 'numeric', 'between:-180,180'],
            'location.coordinates.1' => ['required', 'numeric', 'between:-90,90'],
            'height_m' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'age_years' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'diameter_cm' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'crown_diameter_m' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'health_status' => ['required', 'string', Rule::in(['healthy', 'stressed', 'dead', 'unknown'])],
            'is_tree' => ['required', 'boolean'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['field_code', 'photo_path', 'notes'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $normalized[$key] = $value === '' ? null : $value;
            }
        }

        foreach (['species_id', 'health_status'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $normalized[$key] = $value === '' ? null : Str::lower($value);
            }
        }

        if (is_string($this->input('location.type'))) {
            $normalized['location'] = array_replace(
                is_array($this->input('location')) ? $this->input('location') : [],
                ['type' => trim($this->input('location.type'))],
            );
        }

        $this->merge($normalized);
    }
}
