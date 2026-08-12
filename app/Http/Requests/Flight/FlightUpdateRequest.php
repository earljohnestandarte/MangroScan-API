<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class FlightUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'drone_id' => ['sometimes', 'required', 'uuid'],
            'pilot_user_id' => ['sometimes', 'required', 'uuid'],
            'flight_code' => ['sometimes', 'required', 'string', 'max:50'],
            'planned_altitude_meters' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one planning field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('flight_code'))) {
            $values['flight_code'] = Str::upper(trim($this->input('flight_code')));
        }
        if (is_string($this->input('notes'))) {
            $values['notes'] = trim($this->input('notes'));
        }
        $this->merge($values);
    }
}
