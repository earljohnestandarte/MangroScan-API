<?php

namespace App\Http\Requests\Battery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BatteryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['available', 'in_use', 'charging', 'maintenance', 'retired']),
            ],
            'type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['lipo', 'li-ion', 'nimh']),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.max(1, (int) config('mangroscan.limits.pagination_per_page_max')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['status', 'type'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['status'])) {
            $normalized['status'] = Str::lower($normalized['status']);
        }

        if (isset($normalized['type'])) {
            $normalized['type'] = Str::lower($normalized['type']);
        }

        $this->merge($normalized);
    }
}
