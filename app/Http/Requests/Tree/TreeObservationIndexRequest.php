<?php

namespace App\Http\Requests\Tree;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TreeObservationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'flight_id' => ['sometimes', 'nullable', 'uuid'],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'validation_status' => ['sometimes', 'nullable', 'string', Rule::in([
                'unvalidated', 'validated', 'corrected', 'rejected',
            ])],
            'min_confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes', 'integer', 'min:1',
                'max:'.max(1, (int) config('mangroscan.limits.pagination_per_page_max')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('validation_status'))) {
            $this->merge(['validation_status' => Str::lower(trim($this->input('validation_status')))]);
        }
    }
}
