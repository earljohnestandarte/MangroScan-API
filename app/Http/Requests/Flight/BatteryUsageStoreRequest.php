<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;

class BatteryUsageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'battery_id' => ['required', 'uuid'],
            'start_percentage' => ['required', 'numeric', 'between:0,100'],
            'end_percentage' => ['required', 'numeric', 'between:0,100'],
            'usage_minutes' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
