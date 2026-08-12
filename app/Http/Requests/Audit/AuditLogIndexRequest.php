<?php

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'action' => ['sometimes', 'nullable', 'string', 'max:150'],
            'table_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'record_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP', 'after_or_equal:from'],
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

        foreach (['user_id', 'record_id'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        foreach (['action', 'table_name'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        $this->merge($normalized);
    }
}
