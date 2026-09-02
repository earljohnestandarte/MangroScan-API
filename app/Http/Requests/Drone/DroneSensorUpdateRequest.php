<?php

namespace App\Http\Requests\Drone;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DroneSensorUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sensor_name' => ['sometimes', 'required', 'string', 'max:100'],
            'sensor_type' => ['sometimes', 'required', 'string', Rule::in([
                'rgb_camera', 'lidar', 'depth', 'gps', 'imu',
            ])],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:80'],
            'range_meters' => [
                'sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99',
            ],
            'calibration_required' => ['sometimes', 'required', 'boolean'],
            'status' => ['sometimes', 'required', 'string', Rule::in([
                'active', 'inactive', 'maintenance',
            ])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add(
                    'request',
                    'At least one sensor field is required.'
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach ([
            'sensor_name',
            'sensor_type',
            'manufacturer',
            'model',
            'serial_number',
            'resolution',
            'status',
        ] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }

        if (isset($values['sensor_type'])) {
            $values['sensor_type'] = Str::lower($values['sensor_type']);
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
