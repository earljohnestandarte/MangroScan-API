<?php

namespace App\Http\Requests\Drone;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DroneSensorStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sensor_name' => ['required', 'string', 'max:100'],
            'sensor_type' => ['required', 'string', Rule::in(['rgb_camera', 'lidar', 'depth', 'gps', 'imu'])],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:80'],
            'range_meters' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'calibration_required' => ['required', 'boolean'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'maintenance'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['sensor_name', 'sensor_type', 'manufacturer', 'model', 'serial_number', 'resolution', 'status'] as $key) {
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
