<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ValidationSessionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['open', 'completed'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['mission_id', 'site_id', 'status'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        $this->merge($normalized);
    }
}
