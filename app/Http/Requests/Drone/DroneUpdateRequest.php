<?php

namespace App\Http\Requests\Drone;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class DroneUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'drone_name' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'firmware_version' => ['sometimes', 'nullable', 'string', 'max:80'],
            'max_flight_minutes' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999.99'],
            'payload_capacity_grams' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'status' => ['sometimes', 'required', 'string', 'in:available,maintenance,retired'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one drone field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach (['drone_name', 'model', 'serial_number', 'firmware_version', 'status'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }   

        if (isset($values['serial_number'])) {
            $values['serial_number'] = Str::upper($values['serial_number']);
        }

        if (isset($values['status'])) {
            $values['status'] = Str::lower($values['status']);
        }

        $this->merge($values);
    }
}