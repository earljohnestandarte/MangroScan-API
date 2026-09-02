<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;

class EnvironmentLogStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'recorded_at' => ['required', 'date'],
            'weather_condition' => ['required', 'string', 'max:100'],
            'wind_speed_mps' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'temperature_celsius' => ['sometimes', 'nullable', 'numeric'],
            'humidity_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'visibility_status' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
