<?php

namespace App\Http\Requests\Annotation;

use Illuminate\Foundation\Http\FormRequest;

class AnnotationObjectReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'objects' => ['required', 'array', 'max:5000'],
            'objects.*.class_id' => ['required', 'uuid', 'exists:mangrove_species,species_id'],
            'objects.*.bbox' => ['sometimes', 'nullable', 'array', 'size:4'],
            'objects.*.bbox.*' => ['numeric'],
            'objects.*.polygon' => ['sometimes', 'nullable', 'array'],
            'objects.*.attributes' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
