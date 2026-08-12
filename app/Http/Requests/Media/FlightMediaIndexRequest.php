<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlightMediaIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', 'string', Rule::in(['image', 'video'])],
            'quality_status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['pending', 'acceptable', 'rejected', 'needs_recapture']),
            ],
            'processing_status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['pending', 'queued', 'processing', 'completed', 'failed']),
            ],
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

        foreach (['type', 'quality_status', 'processing_status'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        $this->merge($normalized);
    }
}
