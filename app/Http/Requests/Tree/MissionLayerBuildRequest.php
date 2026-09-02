<?php

namespace App\Http\Requests\Tree;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MissionLayerBuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'layer_types' => ['required', 'array', 'min:1', 'max:4'],
            'layer_types.*' => ['required', 'string', 'distinct:strict', Rule::in(['tree_points', 'species_map', 'canopy_height', 'orthomosaic'])],
            'parameters' => ['sometimes', 'nullable', 'array', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('layer_types'))) {
            $this->merge(['layer_types' => array_map(
                fn (mixed $value): mixed => is_string($value) ? Str::lower(trim($value)) : $value,
                $this->input('layer_types'),
            )]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('parameters')
                && strlen(json_encode($this->input('parameters'), JSON_THROW_ON_ERROR)) > 65_536) {
                $validator->errors()->add('parameters', 'The parameters field must not exceed 65536 encoded bytes.');
            }
        }];
    }
}
