<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConfidenceReviewIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mission_id' => ['required', 'uuid'],
            'flight_id' => ['sometimes', 'nullable', 'uuid'],
            'result_type' => ['sometimes', 'nullable', Rule::in(['detection', 'species', 'height', 'age'])],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'in_review', 'resolved', 'dismissed'])],
            'severity' => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['result_type', 'status', 'severity'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::lower(trim($this->input($field)))]);
            }
        }
    }
}
