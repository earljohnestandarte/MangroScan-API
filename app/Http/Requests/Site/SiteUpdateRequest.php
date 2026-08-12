<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SiteUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'site_name' => ['sometimes', 'required', 'string', 'max:150'],
            'site_code' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'province' => ['sometimes', 'required', 'string', 'max:100'],
            'city_municipality' => ['sometimes', 'required', 'string', 'max:100'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:100'],
            'center_point' => ['sometimes', 'nullable', 'array'],
            'center_point.type' => ['required_with:center_point', 'string', Rule::in(['Point'])],
            'center_point.coordinates' => ['required_with:center_point', 'array', 'size:2'],
            'center_point.coordinates.0' => ['required_with:center_point', 'numeric', 'between:-180,180'],
            'center_point.coordinates.1' => ['required_with:center_point', 'numeric', 'between:-90,90'],
            'area_hectares' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
            'environment_type' => ['sometimes', 'required', 'string', Rule::in(['coastal', 'riverine', 'estuarine'])],
            'access_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one site metadata field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['site_name', 'site_code', 'description', 'province', 'city_municipality', 'barangay', 'environment_type', 'access_notes'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        if (isset($values['site_code'])) {
            $values['site_code'] = Str::upper($values['site_code']);
        }
        if (isset($values['environment_type'])) {
            $values['environment_type'] = Str::lower($values['environment_type']);
        }
        $point = $this->input('center_point');
        if (is_array($point) && is_array($point['coordinates'] ?? null) && count($point['coordinates']) === 2 && is_numeric($point['coordinates'][0]) && is_numeric($point['coordinates'][1])) {
            $point['coordinates'] = [(float) $point['coordinates'][0], (float) $point['coordinates'][1]];
            $values['center_point'] = $point;
        }
        $this->merge($values);
    }
}
