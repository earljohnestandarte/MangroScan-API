<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlightStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'drone_id' => ['required', 'uuid'],
            'pilot_user_id' => ['required', 'uuid'],
            'flight_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('flight_sessions', 'flight_code'),
            ],
            'planned_altitude_meters' => [
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
        $normalized = [];

        if (is_string($this->input('flight_code'))) {
            $normalized['flight_code'] = Str::upper(trim($this->input('flight_code')));
        }

        if (is_string($this->input('notes'))) {
            $normalized['notes'] = trim($this->input('notes'));
        }

        $this->merge($normalized);
    }
}
