<?php

namespace App\Http\Requests\Tree;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MissionLayerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => ['sometimes', 'nullable', 'string', Rule::in(['tree_points', 'species_map', 'canopy_height', 'orthomosaic'])]];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => Str::lower(trim($this->input('type')))]);
        }
    }
}
