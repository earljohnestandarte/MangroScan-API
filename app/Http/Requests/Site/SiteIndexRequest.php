<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SiteIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'archived'])],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
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

        foreach (['search', 'status', 'province'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['status'])) {
            $normalized['status'] = strtolower($normalized['status']);
        }

        $this->merge($normalized);
    }
}
