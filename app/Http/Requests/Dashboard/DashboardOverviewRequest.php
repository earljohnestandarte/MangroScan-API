<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['site_id', 'mission_id', 'from', 'to'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $normalized[$key] = $value === '' ? null : strtolower($value);
            }
        }
        $this->merge($normalized);
    }
}
