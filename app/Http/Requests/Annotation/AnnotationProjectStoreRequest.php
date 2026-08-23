<?php

namespace App\Http\Requests\Annotation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnotationProjectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'dataset_type' => ['required', 'string', 'max:80'],
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['required', 'string', Rule::in(['planned', 'active', 'paused', 'completed', 'archived'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'dataset_type', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        foreach (['dataset_type', 'status'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = mb_strtolower($normalized[$field]);
            }
        }
        $this->merge($normalized);
    }
}
