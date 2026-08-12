<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SiteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:150'],
            'site_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('survey_sites', 'site_code'),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'province' => ['required', 'string', 'max:100'],
            'city_municipality' => ['required', 'string', 'max:100'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:100'],
            'center_point' => ['sometimes', 'nullable', 'array'],
            'center_point.type' => ['required_with:center_point', 'string', Rule::in(['Point'])],
            'center_point.coordinates' => ['required_with:center_point', 'array', 'size:2'],
            'center_point.coordinates.0' => [
                'required_with:center_point',
                'numeric',
                'between:-180,180',
            ],
            'center_point.coordinates.1' => [
                'required_with:center_point',
                'numeric',
                'between:-90,90',
            ],
            'area_hectares' => [
                'sometimes',
                'nullable',
                'numeric',
                'decimal:0,4',
                'between:0,99999999.9999',
            ],
            'environment_type' => [
                'required',
                'string',
                Rule::in(['coastal', 'riverine', 'estuarine']),
            ],
            'access_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'site_name',
            'site_code',
            'description',
            'province',
            'city_municipality',
            'barangay',
            'environment_type',
            'access_notes',
        ] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['site_code'])) {
            $normalized['site_code'] = Str::upper($normalized['site_code']);
        }

        if (isset($normalized['environment_type'])) {
            $normalized['environment_type'] = Str::lower($normalized['environment_type']);
        }

        $centerPoint = $this->input('center_point');

        if (is_array($centerPoint)
            && isset($centerPoint['coordinates'])
            && is_array($centerPoint['coordinates'])
            && count($centerPoint['coordinates']) === 2
            && is_numeric($centerPoint['coordinates'][0])
            && is_numeric($centerPoint['coordinates'][1])) {
            $centerPoint['coordinates'] = [
                (float) $centerPoint['coordinates'][0],
                (float) $centerPoint['coordinates'][1],
            ];
            $normalized['center_point'] = $centerPoint;
        }

        $this->merge($normalized);
    }
}
