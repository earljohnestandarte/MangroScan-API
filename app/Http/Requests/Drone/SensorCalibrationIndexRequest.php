<?php

namespace App\Http\Requests\Drone;

use Illuminate\Foundation\Http\FormRequest;

class SensorCalibrationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sensor_id' => ['sometimes', 'nullable', 'uuid'],
            'is_valid' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.max(1, (int) config('mangroscan.limits.pagination_per_page_max')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sensor_id'))) {
            $this->merge(['sensor_id' => trim($this->input('sensor_id'))]);
        }
    }
}
