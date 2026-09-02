<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class TrainingDatasetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'dataset_name' => ['required', 'string', 'max:150'],
            'dataset_type' => ['required', 'string', 'max:80'],
            'source' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'version_label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['dataset_name', 'dataset_type', 'source', 'description', 'version_label'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        foreach (['dataset_type', 'source'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = mb_strtolower($normalized[$field]);
            }
        }
        $this->merge($normalized);
    }
}
