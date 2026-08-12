<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiModelIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', 'string', Rule::in([
                'species_classifier', 'tree_detector', 'height_estimator', 'age_estimator',
            ])],
            'deployed' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => Str::lower(trim($this->input('type')))]);
        }

        if (is_string($this->input('deployed'))) {
            $deployed = match (Str::lower(trim($this->input('deployed')))) {
                'true' => true,
                'false' => false,
                default => $this->input('deployed'),
            };
            $this->merge(['deployed' => $deployed]);
        }
    }
}
