<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfidenceFlagUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'in_review', 'resolved', 'dismissed'])],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => Str::lower(trim($this->input('status')))]);
        }
        foreach (['review_note', 'reason', 'resolution_notes'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (in_array($this->input('status'), ['resolved', 'dismissed'], true)
                && ! is_string($this->input('resolution_notes'))) {
                $validator->errors()->add('resolution_notes', 'Resolution notes are required for a final review status.');
            }
        }];
    }
}
