<?php

namespace App\Http\Requests\Drone;

use Illuminate\Foundation\Http\FormRequest;

class SensorCalibrationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'calibration_date' => ['required', 'date'],
            'calibration_method' => ['required', 'string', 'max:100'],
            'calibration_file_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'calibration_notes' => ['sometimes', 'nullable', 'string'],
            'is_valid' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->input('calibration_method'))) {
            $values['calibration_method'] = trim($this->input('calibration_method'));
        }

        if (is_string($this->input('calibration_file_path'))) {
            $values['calibration_file_path'] = trim($this->input('calibration_file_path'));
        }

        if (is_string($this->input('calibration_notes'))) {
            $values['calibration_notes'] = trim($this->input('calibration_notes'));
        }

        $this->merge($values);
    }
}