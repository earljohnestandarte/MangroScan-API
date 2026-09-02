<?php

namespace App\Http\Requests\Battery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BatteryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'battery_code' => ['required', 'string', 'max:100'],
            'battery_type' => ['required', 'string', Rule::in(['lipo', 'li-ion', 'nimh'])],
            'capacity_mah' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'voltage' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'status' => [
                'required',
                'string',
                Rule::in(['available', 'in_use', 'charging', 'maintenance', 'retired']),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('battery_code'))) {
            $normalized['battery_code'] = Str::upper(trim($this->input('battery_code')));
        }

        foreach (['battery_type', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::lower(trim($this->input($field)));
            }
        }

        $this->merge($normalized);
    }
}
