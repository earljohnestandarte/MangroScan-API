<?php

namespace App\Http\Requests\Annotation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnotationExportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['format' => ['required', 'string', Rule::in(['coco', 'yolo', 'csv', 'geojson'])]];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('format'))) {
            $this->merge(['format' => mb_strtolower(trim($this->input('format')))]);
        }
    }
}
