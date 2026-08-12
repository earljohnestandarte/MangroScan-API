<?php

namespace App\Http\Requests\Processing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProcessingJobIndexRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['queued', 'running', 'succeeded', 'failed', 'cancelled'])],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(['tree_detection', 'species_classification', 'full_pipeline'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.max(1, (int) config('mangroscan.limits.pagination_per_page_max')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['mission_id', 'flight_id', 'status', 'type'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        $this->merge($normalized);
    }
}
