<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;

class FlightCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ended_at' => ['required', 'date'],
            'landing_location' => ['sometimes', 'nullable', 'array'],
            'landing_location.type' => ['required_with:landing_location', 'string', 'in:Point'],
            'landing_location.coordinates' => ['required_with:landing_location', 'array', 'size:2'],
            'landing_location.coordinates.0' => ['required_with:landing_location', 'numeric', 'between:-180,180'],
            'landing_location.coordinates.1' => ['required_with:landing_location', 'numeric', 'between:-90,90'],
            'actual_avg_altitude_meters' => [
                'sometimes',
                'nullable',
                'numeric',
                'decimal:0,2',
                'between:0,999999.99',
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes'))]);
        }
    }
}
