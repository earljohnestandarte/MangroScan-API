<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainingDatasetItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'label_file_path' => ['required', 'string', 'max:1024'],
            'label_format' => ['required', 'string', Rule::in(['coco', 'yolo', 'csv', 'geojson', 'json'])],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'annotation_status' => ['required', 'string', Rule::in(['planned', 'in_progress', 'completed', 'reviewed', 'rejected'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['label_file_path', 'label_format', 'annotation_status'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        foreach (['label_format', 'annotation_status'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = mb_strtolower($normalized[$field]);
            }
        }
        $this->merge($normalized);
    }
}
