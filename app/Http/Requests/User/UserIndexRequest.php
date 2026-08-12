<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'org_id' => ['sometimes', 'nullable', 'uuid'],
            'role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
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

        foreach (['role', 'search'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['role'])) {
            $normalized['role'] = Str::lower($normalized['role']);
        }

        if (is_string($this->input('active'))) {
            $active = filter_var(
                $this->input('active'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );

            if ($active !== null) {
                $normalized['active'] = $active;
            }
        }

        $this->merge($normalized);
    }
}
