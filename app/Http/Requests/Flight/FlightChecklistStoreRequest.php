<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlightChecklistStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'checklist_type' => [
                'required',
                'string',
                Rule::in(['pre_flight', 'post_flight']),
            ],
            'battery_ok' => ['required', 'boolean'],
            'weather_ok' => ['required', 'boolean'],
            'gps_ok' => ['required', 'boolean'],
            'camera_ok' => ['required', 'boolean'],
            'lidar_depth_ok' => ['required', 'boolean'],
            'storage_ok' => ['required', 'boolean'],
            'overall_status' => [
                'required',
                'string',
                Rule::in(['passed', 'failed', 'conditional']),
            ],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['checklist_type', 'overall_status'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        if (is_string($this->input('remarks'))) {
            $normalized['remarks'] = trim($this->input('remarks'));
        }

        $this->merge($normalized);
    }
}
